<?php
declare(strict_types=1);

/**
 * Module Remuneration - honoraires, acompte fiscal et versement a la DGI (CDC 4.4, 4.5).
 *
 * La sequence est contrainte : rapport d'execution, certificat d'acceptation,
 * prestation, reglement, versement a la DGI, puis seulement cloture. Aucune etape
 * ne se saute, et c'est ce qui fait que la cloture d'une periode n'intervient
 * jamais le dernier jour de cette periode, mais quand les dossiers sont complets.
 *
 * Le montant verse n'est jamais le montant budgete : la ligne budgetaire est
 * consommee pour le brut, l'intervenant recoit le net, et la difference part a la
 * DGI. C'est la seule depense de l'outil ou ces trois montants different.
 */

require_once __DIR__ . '/depenses.php';
require_once __DIR__ . '/comptes.php';
require_once __DIR__ . '/documents.php';   // certificat d'acceptation, etat recapitulatif

const AUTORITES_ACCEPTATION = [
    'coordinateur'       => 'Coordinateur du projet',
    'assemblee_generale' => 'Assemblée Générale, par résolution écrite',
];

const STATUTS_RAPPORT = ['recu' => 'Reçu', 'accepte' => 'Accepté', 'refuse' => 'Refusé'];

const RATIFICATIONS = [
    'sans_objet' => 'Sans objet',
    'provisoire' => 'Provisoire, en attente de résolution',
    'ratifiee'   => 'Ratifiée',
];

// ---------------------------------------------------------------------
// Rapports d'execution
// ---------------------------------------------------------------------

/**
 * « Le certificat d'acceptation est delivre par le Coordinateur pour six
 * intervenants. Pour le Coordinateur lui-meme, l'acceptation releve de l'Assemblee
 * Generale qui l'a nomme. L'autorite d'acceptation est un attribut du rapport
 * d'execution et non une constante » (CDC 4.4).
 *
 * On ne peut pas s'accepter son propre travail : c'est le titulaire du contrat qui
 * determine l'autorite, pas le role de celui qui saisit.
 */
function rapport_autorite(int $contratId): string
{
    $st = db()->prepare(
        'SELECT c.tiers_id, a.role
           FROM contrats c
           LEFT JOIN affectations a ON a.projet_id = c.projet_id AND a.utilisateur_id IN (
                SELECT u.id FROM utilisateurs u WHERE u.tiers_id = c.tiers_id)
            AND a.date_debut <= CURDATE() AND (a.date_fin IS NULL OR a.date_fin >= CURDATE())
          WHERE c.id = ?'
    );
    $st->execute([$contratId]);
    foreach ($st->fetchAll() as $r) {
        if ($r['role'] === 'coordinateur') {
            return 'assemblee_generale';
        }
    }
    return 'coordinateur';
}

/**
 * Le rapport est remis hors outil au Responsable Administratif et Financier, qui
 * le verse au dossier : la table conserve la date de remise, distincte de la date
 * de versement (CDC 4.4).
 *
 * @return array{success: bool, id?: int, error?: string}
 */
function rapport_verser(int $contratId, int $mois, string $dateRemise, ?array $fichier): array
{
    if (user_role() !== 'raf') {
        return ['success' => false, 'error' => 'Le rapport d\'exécution est versé au dossier par le Responsable Administratif et Financier.'];
    }
    $st = db()->prepare("SELECT * FROM contrats WHERE id = ? AND projet_id = ? AND statut = 'actif'");
    $st->execute([$contratId, projet_id()]);
    $contrat = $st->fetch();
    if ($contrat === false) {
        return ['success' => false, 'error' => 'Contrat inconnu ou clos dans ce projet.'];
    }
    if ($contrat['type'] === 'convention_partenariat') {
        return ['success' => false, 'error' => 'Une convention de partenariat n\'est pas rémunérée : elle ne donne aucun rapport d\'exécution.'];
    }
    if ($mois < 1) {
        return ['success' => false, 'error' => 'Le mois de projet est obligatoire.'];
    }
    if (empty($fichier['name'])) {
        return ['success' => false, 'error' => 'Le rapport d\'exécution numérisé est la pièce qui ouvre le circuit : il est obligatoire.'];
    }
    $up = enregistrer_upload($fichier, 'scans',
        projet_code() . '-RAPPORT-' . $contratId . '-M' . str_pad((string)$mois, 2, '0', STR_PAD_LEFT) . '.pdf');
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Rapport d\'exécution : ' . $up['error']];
    }
    $autorite = rapport_autorite($contratId);
    try {
        db()->prepare(
            'INSERT INTO rapports_execution (projet_id, contrat_id, mois, date_remise, date_versement,
                                             fichier_id, autorite)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([projet_id(), $contratId, $mois, $dateRemise, date('Y-m-d'), (int)$up['id'], $autorite]);
        $id = (int)db()->lastInsertId();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_rapport_contrat_mois')
            ? 'Ce contrat a déjà un rapport pour le mois ' . $mois . '.'
            : 'Enregistrement impossible.'];
    }
    audit('remuneration', 'rapport_verse', 'rapport_execution', $id,
        'Contrat ' . $contratId . ' · mois ' . $mois . ' · remis le ' . date_fr($dateRemise)
        . ' · acceptation ' . AUTORITES_ACCEPTATION[$autorite]);
    return ['success' => true, 'id' => $id];
}

function rapport(int $id): ?array
{
    $st = db()->prepare(
        'SELECT r.*, c.tiers_id, c.ligne_id, c.unite, c.montant_unitaire, c.taux_acompte_defaut,
                c.avance_autorisee, c.part_avance, c.fonction, t.nom AS intervenant,
                l.code AS ligne_code, l.libelle AS ligne_libelle
           FROM rapports_execution r
           JOIN contrats c ON c.id = r.contrat_id
           JOIN tiers t ON t.id = c.tiers_id
           LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
          WHERE r.id = ? AND r.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

function rapports(?int $mois = null, ?int $projetId = null): array
{
    $sql = 'SELECT r.*, t.nom AS intervenant, c.fonction, p.id AS prestation_id, p.net, p.ratification
              FROM rapports_execution r
              JOIN contrats c ON c.id = r.contrat_id
              JOIN tiers t ON t.id = c.tiers_id
              LEFT JOIN prestations p ON p.rapport_id = r.id
             WHERE r.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($mois !== null) {
        $sql .= ' AND r.mois = ?';
        $args[] = $mois;
    }
    $sql .= ' ORDER BY r.mois DESC, t.nom';
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * Delivre le certificat d'acceptation. « Delivrer un certificat d'acceptation :
 * E Coordinateur » (annexe B), et jamais sur son propre travail : quand le
 * rapport est celui du Coordinateur, l'autorite est l'Assemblee Generale, qui
 * statue par resolution ecrite a intervalle fixe.
 */
function rapport_accepter(int $rapportId): array
{
    if (($refus = droit_ecriture('certificat')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $r = rapport($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if ($r['statut'] !== 'recu') {
        return ['success' => false, 'error' => 'Ce rapport est déjà ' . (STATUTS_RAPPORT[$r['statut']] ?? $r['statut']) . '.'];
    }
    if ((int)user_tiers_id() === (int)$r['tiers_id']) {
        return ['success' => false, 'error' => 'On n\'accepte pas son propre rapport d\'exécution : '
            . 'celui du Coordinateur relève de l\'Assemblée Générale qui l\'a nommé.'];
    }
    db()->prepare("UPDATE rapports_execution SET statut = 'accepte', accepte_par = ?, accepte_le = NOW() WHERE id = ?")
        ->execute([user_id(), $rapportId]);
    audit('remuneration', 'certificat_acceptation', 'rapport_execution', $rapportId,
        $r['intervenant'] . ' · mois ' . $r['mois'] . ' · autorité ' . AUTORITES_ACCEPTATION[$r['autorite']]);

    $r['statut'] = 'accepte';
    $doc = document_generer('certificat_acceptation', ['rapport' => $r, 'prestation' => null],
        'rapport_execution', $rapportId, 'remuneration');
    if (!empty($doc['success'])) {
        db()->prepare('UPDATE rapports_execution SET certificat_document_id = ? WHERE id = ?')
            ->execute([(int)$doc['document_id'], $rapportId]);
    }
    return ['success' => true, 'document_id' => $doc['document_id'] ?? null];
}

function rapport_refuser(int $rapportId, string $motif): array
{
    $r = rapport($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Le refus d\'un rapport revient au Coordinateur.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif du refus est obligatoire.'];
    }
    db()->prepare("UPDATE rapports_execution SET statut = 'refuse' WHERE id = ?")->execute([$rapportId]);
    audit('remuneration', 'rapport_refuse', 'rapport_execution', $rapportId, $r['intervenant'] . ' · ' . trim($motif));
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Prestations
// ---------------------------------------------------------------------

/**
 * Calcule la prestation d'un rapport accepte : brut selon le contrat, acompte
 * retenu, net a verser (CDC 4.4).
 *
 * Le taux est fige sur la prestation au moment du calcul, le contrat ne portant
 * que le taux par defaut : un changement ulterieur ne modifie jamais
 * retroactivement une prestation deja reglee.
 *
 * L'ecriture est posee dans la foulee - la charge au debit pour le brut, le
 * prestataire au credit pour le net, la DGI au credit pour l'acompte - de sorte
 * que la dette envers la DGI naisse avec la prestation et non avec son reglement.
 *
 * @param float|null $quotePart part du brut versee maintenant, pour une avance
 * @return array{success: bool, id?: int, error?: string}
 */
function prestation_calculer(int $rapportId, ?float $quantite = null, ?float $quotePart = null): array
{
    $r = rapport($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if ($r['statut'] !== 'accepte') {
        return ['success' => false, 'error' => 'Aucune prestation sans certificat d\'acceptation préalable : '
            . 'ce rapport est ' . (STATUTS_RAPPORT[$r['statut']] ?? $r['statut']) . '.'];
    }
    if ($r['ligne_id'] === null) {
        return ['success' => false, 'error' => 'Ce contrat n\'est adossé à aucune ligne budgétaire : la rémunération n\'a rien à consommer.'];
    }
    $quantite = $quantite ?? 1.0;
    if ($quantite <= 0) {
        return ['success' => false, 'error' => 'La quantité rémunérée doit être strictement positive.'];
    }
    $brut = round($quantite * (float)$r['montant_unitaire'], 2);
    if ($quotePart !== null) {
        if ($quotePart <= 0 || $quotePart > $brut + 0.005) {
            return ['success' => false, 'error' => 'La quote-part versée ne peut ni être nulle ni dépasser le brut.'];
        }
        $brut = round($quotePart, 2);
    }

    // « Chaque versement porte sa quote-part de montant brut et son acompte de deux
    // pour cent calcule sur cette quote-part, de sorte que la retenue suive le
    // decaissement et non l'achevement de la prestation » (CDC 4.5).
    $taux = (float)$r['taux_acompte_defaut'];
    $acompte = round($brut * $taux / 100, 2);
    $net = round($brut - $acompte, 2);

    // « Le second versement suit le circuit ordinaire et solde l'avance » (CDC 4.5).
    // Solder, c'est completer : la somme des quote-parts d'un contrat ne depasse
    // jamais son brut total, sans quoi l'avance ne serait plus une avance.
    $sc = db()->prepare('SELECT montant_total FROM contrats WHERE id = ?');
    $sc->execute([(int)$r['contrat_id']]);
    $plafondContrat = (float)$sc->fetchColumn();
    $sd = db()->prepare('SELECT COALESCE(SUM(brut), 0) FROM prestations WHERE contrat_id = ?');
    $sd->execute([(int)$r['contrat_id']]);
    $dejaVerse = (float)$sd->fetchColumn();
    if ($plafondContrat > 0 && round($dejaVerse + $brut, 2) > round($plafondContrat, 2) + 0.005) {
        return ['success' => false, 'error' => sprintf(
            'Ce contrat porte %s ; %s ont déjà été rémunérés, il en reste %s à verser.',
            htg($plafondContrat), htg($dejaVerse), htg(round($plafondContrat - $dejaVerse, 2)))];
    }

    $ratification = $r['autorite'] === 'assemblee_generale' ? 'provisoire' : 'sans_objet';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO prestations (projet_id, contrat_id, rapport_id, mois, quantite, brut,
                                      taux_acompte, acompte, net, ratification)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), (int)$r['contrat_id'], $rapportId, (int)$r['mois'], $quantite,
                    $brut, $taux, $acompte, $net, $ratification]);
        $id = (int)$pdo->lastInsertId();

        // La ligne est consommee pour le brut, l'intervenant recevra le net, et la
        // difference part a la DGI.
        ecriture_honoraires((int)$r['ligne_id'], (int)$r['tiers_id'], $brut, $acompte,
            date('Y-m-d'), 'Honoraires ' . $r['intervenant'] . ' — mois ' . $r['mois'], 'prestation:' . $id);

        audit_strict('remuneration', 'prestation_calculee', 'prestation', $id,
            $r['intervenant'] . ' · mois ' . $r['mois'] . ' · brut ' . htg($brut)
            . ' · acompte ' . rtrim(rtrim(number_format($taux, 2, ',', ' '), '0'), ',') . ' % = ' . htg($acompte)
            . ' · net ' . htg($net) . ($ratification === 'provisoire' ? ' · provisoire, en attente de résolution' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (str_contains($e->getMessage(), 'uk_prestation_contrat_mois')) {
            return ['success' => false, 'error' => 'Ce contrat a déjà une prestation pour le mois ' . $r['mois'] . '.'];
        }
        error_log('prestation_calculer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Calcul impossible : ' . $e->getMessage()];
    }
    return ['success' => true, 'id' => $id, 'brut' => $brut, 'acompte' => $acompte, 'net' => $net];
}

function prestation(int $id): ?array
{
    $st = db()->prepare(
        'SELECT p.*, t.nom AS intervenant, c.ligne_id, c.fonction, r.autorite
           FROM prestations p
           JOIN contrats c ON c.id = p.contrat_id
           JOIN tiers t ON t.id = c.tiers_id
           JOIN rapports_execution r ON r.id = p.rapport_id
          WHERE p.id = ? AND p.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $p = $st->fetch();
    return $p === false ? null : $p;
}

function prestations(?int $mois = null, ?int $projetId = null): array
{
    $sql = 'SELECT p.*, t.nom AS intervenant, c.fonction
              FROM prestations p JOIN contrats c ON c.id = p.contrat_id JOIN tiers t ON t.id = c.tiers_id
             WHERE p.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($mois !== null) {
        $sql .= ' AND p.mois = ?';
        $args[] = $mois;
    }
    $st = db()->prepare($sql . ' ORDER BY p.mois DESC, t.nom');
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * « Bousol tient la liste des prestations non ratifiees, et la cloture finale ne
 * peut aboutir tant qu'une resolution ne couvre pas l'ensemble de la periode »
 * (CDC 4.4). Restitution lira cette liste avant de figer.
 */
function prestations_non_ratifiees(?int $projetId = null, ?int $mois = null): array
{
    $sql = "SELECT p.*, t.nom AS intervenant FROM prestations p
              JOIN contrats c ON c.id = p.contrat_id JOIN tiers t ON t.id = c.tiers_id
             WHERE p.projet_id = ? AND p.ratification = 'provisoire'";
    $args = [$projetId ?? projet_id()];
    if ($mois !== null) {
        $sql .= ' AND p.mois = ?';
        $args[] = $mois;
    }
    $st = db()->prepare($sql . ' ORDER BY p.mois, t.nom');
    $st->execute($args);
    return $st->fetchAll();
}

/** La resolution ecrite de l'Assemblee Generale couvre une ou plusieurs prestations. */
function prestation_ratifier(array $prestationIds, array $fichierResolution): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Le versement de la résolution de l\'Assemblée Générale revient au Coordinateur.'];
    }
    if (!$prestationIds) {
        return ['success' => false, 'error' => 'Aucune prestation sélectionnée.'];
    }
    if (empty($fichierResolution['name'])) {
        return ['success' => false, 'error' => 'La résolution écrite de l\'Assemblée Générale est la pièce qui ratifie : elle est obligatoire.'];
    }
    $up = enregistrer_upload($fichierResolution, 'coffre',
        projet_code() . '-RESOLUTION-AG-' . date('Ymd-His') . '.pdf', ALLOWED_DOCUMENT, true);
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Résolution : ' . $up['error']];
    }
    $in = implode(',', array_fill(0, count($prestationIds), '?'));
    db()->prepare("UPDATE prestations SET ratification = 'ratifiee', resolution_fichier_id = ?
                    WHERE id IN ($in) AND projet_id = ? AND ratification = 'provisoire'")
        ->execute([(int)$up['id'], ...array_map('intval', $prestationIds), projet_id()]);
    audit('remuneration', 'prestations_ratifiees', 'prestation', implode(',', $prestationIds),
        count($prestationIds) . ' prestation(s) · résolution fichier #' . (int)$up['id']);
    return ['success' => true];
}

// ---------------------------------------------------------------------
// Le dossier de depense d'une prestation
// ---------------------------------------------------------------------

/**
 * Ouvre le dossier de depense d'une prestation et l'impute pour le brut.
 *
 * L'imputation porte le brut - c'est lui qui consomme la ligne budgetaire - et le
 * reglement portera le net : « le montant verse n'est jamais le montant budgete »
 * (CDC 4.4). La difference est deja au credit de la DGI depuis le calcul.
 *
 * @return array{success: bool, dossier_id?: int, numero?: string, error?: string}
 */
function prestation_ouvrir_dossier(int $prestationId): array
{
    $p = prestation($prestationId);
    if ($p === null) {
        return ['success' => false, 'error' => 'Prestation inconnue dans ce projet.'];
    }
    if ($p['dossier_ref'] !== null) {
        return ['success' => false, 'error' => 'Cette prestation a déjà son dossier ' . $p['dossier_ref'] . '.'];
    }
    $st = db()->prepare('SELECT tiers_id, unite FROM contrats WHERE id = ?');
    $st->execute([(int)$p['contrat_id']]);
    $contrat = $st->fetch();

    $res = dossier_ouvrir([
        'type'          => 'service_particulier',
        'tiers_id'      => (int)$contrat['tiers_id'],
        'objet'         => 'Honoraires ' . $p['intervenant'] . ' — mois ' . $p['mois'],
        'montant_prevu' => 0.0,   // un service a un particulier ne se met pas en concurrence
    ]);
    if (empty($res['success'])) {
        return $res;
    }
    $imputation = dossier_imputer((int)$res['id'], (int)$p['ligne_id'], (float)$p['quantite'],
        round((float)$p['brut'] / max((float)$p['quantite'], 0.01), 2), (string)$contrat['unite']);
    if (empty($imputation['success'])) {
        return $imputation;
    }
    db()->prepare('UPDATE prestations SET dossier_ref = ? WHERE id = ?')->execute([$res['numero'], $prestationId]);
    audit('remuneration', 'prestation_dossier', 'prestation', $prestationId,
        $p['intervenant'] . ' · dossier ' . $res['numero'] . ' · brut imputé ' . htg((float)$p['brut'])
        . ' · net à régler ' . htg((float)$p['net']));
    return ['success' => true, 'dossier_id' => (int)$res['id'], 'numero' => $res['numero']];
}

// ---------------------------------------------------------------------
// Avance sur honoraires (CDC 4.5)
// ---------------------------------------------------------------------

/**
 * « Sur un projet qui l'autorise, les remunerations non recurrentes peuvent donner
 * lieu a une avance, versee a la signature de l'entente, le solde intervenant
 * apres validation du rapport. »
 *
 * Le premier versement s'appuie sur l'entente signee et non sur un certificat.
 * Le modele exigeant qu'une prestation soit rattachee a un rapport, l'entente est
 * enregistree comme le rapport de ce premier versement, acceptee d'emblee : c'est
 * elle qui tient lieu de piece justificative, et le journal d'audit dit laquelle.
 *
 * @return array{success: bool, prestation_id?: int, error?: string}
 */
function avance_verser(int $contratId, int $mois, ?array $ententeFichier): array
{
    if (user_role() !== 'raf') {
        return ['success' => false, 'error' => 'L\'avance est préparée par le Responsable Administratif et Financier.'];
    }
    $st = db()->prepare("SELECT * FROM contrats WHERE id = ? AND projet_id = ? AND statut = 'actif'");
    $st->execute([$contratId, projet_id()]);
    $contrat = $st->fetch();
    if ($contrat === false) {
        return ['success' => false, 'error' => 'Contrat inconnu ou clos dans ce projet.'];
    }
    // « Elle n'est ouverte a aucun contrat mensuel recurrent, ou la regle du
    // service fait demeure entiere » (CDC 4.5). Cette regle-la tient au contrat et
    // non au projet : elle est donc verifiee la premiere, pour que le refus dise
    // ce qui l'empeche vraiment plutot que ce qui l'empeche aussi.
    if ($contrat['unite'] === 'mois') {
        return ['success' => false, 'error' => 'Une avance est interdite sur un contrat mensuel récurrent : '
            . 'la règle du service fait y demeure entière.'];
    }
    if (!$contrat['avance_autorisee']) {
        return ['success' => false, 'error' => 'Ce contrat ne prévoit pas d\'avance.'];
    }
    if (param('avances_honoraires', '0') !== '1') {
        return ['success' => false, 'error' => 'Les avances sur honoraires ne sont pas autorisées sur ce projet (annexe F).'];
    }
    if ($contrat['part_avance'] === null || (float)$contrat['part_avance'] <= 0 || (float)$contrat['part_avance'] >= 100) {
        return ['success' => false, 'error' => 'La part avancée du contrat doit être une fraction du brut, entre 0 et 100 %.'];
    }
    if (empty($ententeFichier['name'])) {
        return ['success' => false, 'error' => 'L\'entente signée est la pièce qui justifie l\'avance : elle est obligatoire.'];
    }
    $up = enregistrer_upload($ententeFichier, 'coffre',
        projet_code() . '-ENTENTE-' . $contratId . '.pdf', ALLOWED_DOCUMENT, true);
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Entente : ' . $up['error']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO rapports_execution (projet_id, contrat_id, mois, date_remise, date_versement,
                                             fichier_id, autorite, statut, accepte_par, accepte_le)
             VALUES (?,?,?,CURDATE(),CURDATE(),?,?,?,?,NOW())'
        )->execute([projet_id(), $contratId, $mois, (int)$up['id'],
                    rapport_autorite($contratId), 'accepte', user_id()]);
        $rapportId = (int)$pdo->lastInsertId();
        audit_strict('remuneration', 'avance_entente', 'rapport_execution', $rapportId,
            'Contrat ' . $contratId . ' · mois ' . $mois . ' · entente fichier #' . (int)$up['id']
            . ' · tient lieu de certificat pour le premier versement');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_rapport_contrat_mois')
            ? 'Ce contrat a déjà un rapport pour le mois ' . $mois . '.'
            : 'Enregistrement impossible.'];
    }

    $brutTotal = round((float)$contrat['quantite'] * (float)$contrat['montant_unitaire'], 2);
    $quotePart = round($brutTotal * (float)$contrat['part_avance'] / 100, 2);
    return prestation_calculer($rapportId, (float)$contrat['quantite'], $quotePart);
}

// ---------------------------------------------------------------------
// Versement a la DGI
// ---------------------------------------------------------------------

/**
 * Les acomptes retenus d'un mois, non encore rattaches a un versement. Ils se lisent
 * sur les prestations et non sur le compte de dette : le compte porte le cumul de
 * tous les mois, le versement est mensuel.
 */
function acomptes_du_mois(int $mois, ?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT p.id, p.acompte, p.brut, t.nom AS intervenant, l.code AS ligne_code, l.libelle AS ligne_libelle
           FROM prestations p
           JOIN contrats c ON c.id = p.contrat_id
           JOIN tiers t ON t.id = c.tiers_id
           LEFT JOIN lignes_budgetaires l ON l.id = c.ligne_id
          WHERE p.projet_id = ? AND p.mois = ? AND p.versement_dgi_id IS NULL AND p.acompte > 0
          ORDER BY t.nom'
    );
    $st->execute([$projetId ?? projet_id(), $mois]);
    return $st->fetchAll();
}

/**
 * Prepare le versement mensuel a la DGI et son dossier.
 *
 * « Il ne consomme aucune ligne budgetaire puisque l'acompte est deja compris dans
 * le brut impute. Sa fiche d'imputation existe a titre de memoire, listant les
 * lignes d'origine des acomptes retenus, avec une consommation nulle » (CDC 4.4).
 *
 * Un dossier ne porte qu'une imputation : celle-ci est de nature memoire et de
 * montant nul, et les lignes d'origine sont nommees dans l'objet du dossier et au
 * journal d'audit, ou elles restent lisibles.
 *
 * @return array{success: bool, id?: int, dossier_id?: int, montant?: float, error?: string}
 */
function versement_dgi_preparer(int $mois): array
{
    if (user_role() !== 'raf') {
        return ['success' => false, 'error' => 'Le versement à la DGI est préparé par le Responsable Administratif et Financier.'];
    }
    $acomptes = acomptes_du_mois($mois);
    if (!$acomptes) {
        return ['success' => false, 'error' => 'Aucun acompte retenu au mois ' . $mois . ' n\'attend son versement.'];
    }
    $total = 0.0;
    $lignes = [];
    foreach ($acomptes as $a) {
        $total += (float)$a['acompte'];
        if ($a['ligne_code'] !== null) {
            $lignes[$a['ligne_code']] = true;
        }
    }
    $total = round($total, 2);

    $dgiTiers = db()->query("SELECT id FROM tiers WHERE type = 'administration' AND sigle = 'DGI' LIMIT 1")->fetchColumn();
    if ($dgiTiers === false) {
        return ['success' => false, 'error' => 'La Direction Générale des Impôts n\'est pas au référentiel des tiers.'];
    }
    $premiereLigne = null;
    foreach ($acomptes as $a) {
        $sl = db()->prepare('SELECT ligne_id FROM contrats WHERE id = (SELECT contrat_id FROM prestations WHERE id = ?)');
        $sl->execute([(int)$a['id']]);
        $premiereLigne = $sl->fetchColumn() ?: $premiereLigne;
        if ($premiereLigne) {
            break;
        }
    }
    if (!$premiereLigne) {
        return ['success' => false, 'error' => 'Aucune ligne d\'origine : les contrats concernés ne sont adossés à aucune ligne budgétaire.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO versements_dgi (projet_id, mois, montant_total) VALUES (?,?,?)')
            ->execute([projet_id(), $mois, $total]);
        $id = (int)$pdo->lastInsertId();
        audit_strict('remuneration', 'versement_dgi_prepare', 'versement_dgi', $id,
            'Mois ' . $mois . ' · ' . htg($total) . ' · lignes d\'origine ' . implode(', ', array_keys($lignes))
            . ' · ' . count($acomptes) . ' prestation(s)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => str_contains($e->getMessage(), 'uk_versement_mois')
            ? 'Le mois ' . $mois . ' a déjà son versement à la DGI.'
            : 'Enregistrement impossible.'];
    }

    document_generer('etat_recap_acomptes',
        ['mois' => $mois, 'acomptes' => $acomptes, 'total' => $total],
        'versement_dgi', $id, 'remuneration');

    $res = dossier_ouvrir([
        'type'          => 'versement_dgi',
        'tiers_id'      => (int)$dgiTiers,
        'objet'         => 'Acomptes retenus du mois ' . $mois . ' — lignes ' . implode(', ', array_keys($lignes)),
        'montant_prevu' => 0.0,
    ]);
    if (empty($res['success'])) {
        return $res;
    }
    // Consommation nulle : l'acompte est deja compris dans le brut impute.
    $imp = dossier_imputer((int)$res['id'], (int)$premiereLigne, 1.0, 0.0, 'forfait', 'memoire');
    if (empty($imp['success'])) {
        return $imp;
    }
    $in = implode(',', array_fill(0, count($acomptes), '?'));
    db()->prepare("UPDATE prestations SET versement_dgi_id = ? WHERE id IN ($in)")
        ->execute([$id, ...array_map(fn($a) => (int)$a['id'], $acomptes)]);
    db()->prepare('UPDATE versements_dgi SET dossier_ref = ? WHERE id = ?')->execute([$res['numero'], $id]);

    return ['success' => true, 'id' => $id, 'dossier_id' => (int)$res['id'],
            'numero' => $res['numero'], 'montant' => $total];
}

function versements_dgi(?int $projetId = null): array
{
    $st = db()->prepare('SELECT * FROM versements_dgi WHERE projet_id = ? ORDER BY mois DESC');
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/**
 * « Une periode ne peut etre figee tant que la dette nee dans cette periode n'est
 * pas soldee » (CDC 4.4). Restitution appellera cette fonction avant de figer.
 *
 * @return array{soldee: bool, motif?: string}
 */
function dette_dgi_soldee(int $mois, ?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $restants = acomptes_du_mois($mois, $pid);
    if ($restants) {
        return ['soldee' => false, 'motif' => sprintf(
            'Le mois %d porte %s d\'acomptes retenus qui n\'ont pas encore de versement à la DGI.',
            $mois, htg(array_sum(array_map(fn($a) => (float)$a['acompte'], $restants))))];
    }
    $st = db()->prepare("SELECT mois, montant_total FROM versements_dgi WHERE projet_id = ? AND mois = ? AND statut = 'a_verser'");
    $st->execute([$pid, $mois]);
    $enAttente = $st->fetch();
    if ($enAttente !== false) {
        return ['soldee' => false, 'motif' => sprintf(
            'Le versement à la DGI du mois %d, %s, est préparé mais pas encore réglé.',
            $mois, htg((float)$enAttente['montant_total']))];
    }
    return ['soldee' => true];
}

/**
 * Constate le reglement du versement et attend le recu scelle de la DGI.
 *
 * Le reglement d'un versement a la DGI ne se demande pas depuis son dossier :
 * l'imputation y est pour memoire, a zero, et dossier_demander_reglement() refuse
 * a juste titre de payer plus que ce qui est impute. Il se saisit dans Comptes sur
 * l'origine « versement_dgi », la seule que l'ecriture type sache traduire en un
 * debit de la dette et un credit de la banque (CDC 4.8). C'est donc cette origine
 * qu'il faut chercher ici : la chercher sous « dossier: » revenait a attendre un
 * reglement qui ne pouvait pas exister, et le versement restait « a verser » pour
 * toujours - avec lui la dette du mois, et donc la cloture de la periode.
 */
function versement_dgi_constater(int $versementId): void
{
    $st = db()->prepare('SELECT dossier_ref FROM versements_dgi WHERE id = ? AND projet_id = ?');
    $st->execute([$versementId, projet_id()]);
    $ref = $st->fetchColumn();
    if ($ref === false) {
        return;
    }
    $sd = db()->prepare("SELECT COUNT(*) FROM reglements
                          WHERE projet_id = ? AND origine_ref = ? AND statut = 'execute'");
    $sd->execute([projet_id(), 'versement_dgi:' . $versementId]);
    if ((int)$sd->fetchColumn() === 0) {
        return;
    }
    db()->prepare("UPDATE versements_dgi SET statut = 'verse' WHERE id = ? AND statut = 'a_verser'")
        ->execute([$versementId]);
    // Le dossier suit : regle, il attend son recu scelle avant de se clore.
    db()->prepare("UPDATE dossiers SET statut = 'regle'
                    WHERE projet_id = ? AND numero = ? AND statut = 'approuve'")
        ->execute([projet_id(), $ref]);
}

/** Le recu scelle de la DGI clot le circuit. */
function versement_dgi_justifier(int $versementId, ?array $recu): array
{
    if (empty($recu['name'])) {
        return ['success' => false, 'error' => 'Le reçu scellé de la DGI est la pièce qui justifie le versement.'];
    }
    $up = enregistrer_upload($recu, 'scans', projet_code() . '-RECU-DGI-' . $versementId . '.pdf');
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Reçu de la DGI : ' . $up['error']];
    }
    db()->prepare("UPDATE versements_dgi SET recu_scelle_fichier_id = ?, statut = 'justifie'
                    WHERE id = ? AND projet_id = ? AND statut = 'verse'")
        ->execute([(int)$up['id'], $versementId, projet_id()]);
    audit('remuneration', 'versement_dgi_justifie', 'versement_dgi', $versementId, 'Reçu scellé fichier #' . (int)$up['id']);
    return ['success' => true];
}
