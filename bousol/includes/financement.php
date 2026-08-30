<?php
declare(strict_types=1);

/**
 * Module Financement - tresorerie, sources de revenu et demandes de versement
 * (CDC 4.10).
 *
 * Le suivi de tresorerie est distinct du suivi budgetaire. Il enregistre les
 * versements attendus, chacun avec son montant contractuel saisi a la signature et
 * son montant effectivement recu constate sur avis de credit : la disponibilite se
 * calcule sur le second, jamais sur le premier.
 *
 * Les deux bailleurs ne raisonnent pas de la meme facon. Le PAIESC decoupe sa
 * subvention en trois tranches contractuelles, la FOKAL presente un tableau de
 * revenus admettant plusieurs origines. La meme structure couvre les deux, un
 * projet a source unique n'en declarant qu'une.
 */

require_once __DIR__ . '/restitution.php';
require_once __DIR__ . '/comptes.php';
require_once __DIR__ . '/documents.php';

const ORIGINES_REVENU = [
    'subvention'   => 'Subvention', 'fondation' => 'Fondation', 'parrainage' => 'Parrainage',
    'vente'        => 'Vente',      'inscription' => 'Inscription', 'don' => 'Don',
    'apport_propre' => 'Apport propre', 'autre' => 'Autre',
];

const STATUTS_SOURCE = ['en_cours' => 'En cours', 'acquis' => 'Acquis', 'abandonne' => 'Abandonné'];

const STATUTS_DEMANDE = [
    'preparation'        => 'En préparation',
    'validee'            => 'Validée',
    'transmise'          => 'Transmise',
    'complement_demande' => 'Complément demandé',
    'complement_repondu' => 'Complément répondu',
    'payee'              => 'Payée',
];

// ---------------------------------------------------------------------
// Sources de revenu et tranches
// ---------------------------------------------------------------------

function sources_revenu(?int $projetId = null): array
{
    $st = db()->prepare('SELECT * FROM sources_revenu WHERE projet_id = ? ORDER BY id');
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function source_revenu_creer(array $d): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Les sources de revenu relèvent du Coordinateur.'];
    }
    $origine = (string)($d['origine'] ?? '');
    if (!array_key_exists($origine, ORIGINES_REVENU) || trim((string)($d['libelle'] ?? '')) === '') {
        return ['success' => false, 'error' => 'Origine et libellé sont obligatoires.'];
    }
    $montant = round((float)($d['montant_attendu'] ?? 0), 2);
    if ($montant <= 0) {
        return ['success' => false, 'error' => 'Le montant attendu doit être strictement positif.'];
    }
    db()->prepare('INSERT INTO sources_revenu (projet_id, origine, libelle, montant_attendu) VALUES (?,?,?,?)')
        ->execute([projet_id(), $origine, trim((string)$d['libelle']), $montant]);
    $id = (int)db()->lastInsertId();
    audit('financement', 'source_revenu_creee', 'source_revenu', $id,
        ORIGINES_REVENU[$origine] . ' · ' . trim((string)$d['libelle']) . ' · ' . htg($montant));
    return ['success' => true, 'id' => $id];
}

function tranches(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT t.*, s.libelle AS source, s.origine, f.nom_genere AS avis_nom
           FROM tranches t
           JOIN sources_revenu s ON s.id = t.source_revenu_id
           LEFT JOIN fichiers f ON f.id = t.avis_credit_fichier_id
          WHERE t.projet_id = ? ORDER BY t.numero'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function tranche(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tranches WHERE id = ? AND projet_id = ?');
    $st->execute([$id, projet_id()]);
    $t = $st->fetch();
    return $t === false ? null : $t;
}

/**
 * Le montant contractuel d'une tranche est saisi a la signature. Sur KesKle il
 * derive d'un taux applique au total hors reserve pour imprevus ; sur Koule Ki
 * Pale, ou le bailleur ne fixe pas de taux, il se saisit avec son declencheur.
 */
function tranche_contractualiser(int $trancheId, ?float $montant, string $declencheur = ''): array
{
    $t = tranche($trancheId);
    if ($t === null) {
        return ['success' => false, 'error' => 'Tranche inconnue dans ce projet.'];
    }
    // L'etat de l'objet prime le role de celui qui demande : une tranche encaissee
    // ne se recontractualise pour personne, et le refus doit le dire plutot que
    // d'opposer un droit qui ne serait pas le vrai obstacle.
    if ($t['montant_recu'] !== null) {
        return ['success' => false, 'error' => 'Cette tranche est déjà reçue : son montant contractuel ne se réécrit plus.'];
    }
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Le montant contractuel d\'une tranche se saisit à la signature, par le Coordinateur.'];
    }
    if ($montant !== null && $montant <= 0) {
        return ['success' => false, 'error' => 'Le montant contractuel doit être strictement positif.'];
    }
    db()->prepare('UPDATE tranches SET montant_contractuel = ?, declencheur = ? WHERE id = ?')
        ->execute([$montant, trim($declencheur) ?: null, $trancheId]);
    audit('financement', 'tranche_contractualisee', 'tranche', $trancheId,
        'Tranche ' . (int)$t['numero'] . ' · ' . ($montant === null ? 'montant à saisir' : htg($montant))
        . ($declencheur !== '' ? ' · ' . trim($declencheur) : ''));
    return ['success' => true];
}

/**
 * Constate la reception d'une tranche sur avis de credit, et pose l'ecriture :
 * « l'encaissement d'une tranche debite la banque et credite le financement »
 * (CDC 4.8). C'est ici que ecriture_encaissement_tranche trouve son appelant.
 */
function tranche_encaisser(int $trancheId, float $montant, string $date, ?array $avis): array
{
    if (user_role() !== 'raf') {
        return ['success' => false, 'error' => 'La constatation d\'un encaissement revient au Responsable Administratif et Financier.'];
    }
    $t = tranche($trancheId);
    if ($t === null) {
        return ['success' => false, 'error' => 'Tranche inconnue dans ce projet.'];
    }
    if ($t['montant_recu'] !== null) {
        return ['success' => false, 'error' => 'Cette tranche est déjà encaissée.'];
    }
    if ($montant <= 0) {
        return ['success' => false, 'error' => 'Le montant reçu doit être strictement positif.'];
    }
    if (empty($avis['name'])) {
        return ['success' => false, 'error' => 'L\'avis de crédit est la pièce qui constate la réception : il est obligatoire.'];
    }
    $banque = compte_par_code('BQ');
    $financement = compte_par_code('FIN');
    if ($banque === null || $financement === null) {
        return ['success' => false, 'error' => 'Plan de comptes incomplet : compte bancaire ou compte de financement absent.'];
    }
    $up = enregistrer_upload($avis, 'scans', projet_code() . '-AVIS-CREDIT-T' . (int)$t['numero'] . '.pdf');
    if (!$up['success']) {
        return ['success' => false, 'error' => 'Avis de crédit : ' . $up['error']];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ecriture = ecriture_encaissement_tranche((int)$banque['id'], (int)$financement['id'], $montant, $date,
            'Encaissement de la tranche ' . (int)$t['numero'], 'tranche:' . $trancheId);
        $pdo->prepare('UPDATE tranches SET montant_recu = ?, date_reception = ?, avis_credit_fichier_id = ?, ecriture_ref = ? WHERE id = ?')
            ->execute([$montant, $date, (int)$up['id'], 'ecriture:' . $ecriture, $trancheId]);
        // La source de revenu suit ce qui est acquis, non ce qui est attendu.
        $pdo->prepare(
            'UPDATE sources_revenu s
                SET s.montant_acquis = (SELECT COALESCE(SUM(t.montant_recu), 0) FROM tranches t
                                         WHERE t.source_revenu_id = s.id),
                    s.statut = CASE WHEN (SELECT COALESCE(SUM(t.montant_recu), 0) FROM tranches t
                                           WHERE t.source_revenu_id = s.id) >= s.montant_attendu
                                    THEN \'acquis\' ELSE s.statut END
              WHERE s.id = ?'
        )->execute([(int)$t['source_revenu_id']]);
        audit_strict('financement', 'tranche_encaissee', 'tranche', $trancheId,
            'Tranche ' . (int)$t['numero'] . ' · ' . htg($montant) . ' · reçue le ' . date_fr($date)
            . ' · écriture ' . $ecriture);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('tranche_encaisser: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Encaissement impossible : ' . $e->getMessage()];
    }
    return ['success' => true, 'ecriture_id' => $ecriture];
}

/**
 * La tresorerie disponible se calcule sur ce qui a ete recu, jamais sur ce qui est
 * attendu : c'est toute la difference entre le suivi de tresorerie et le suivi
 * budgetaire (CDC 4.10).
 */
function tresorerie(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $recu = 0.0;
    $attendu = 0.0;
    foreach (tranches($pid) as $t) {
        $recu += (float)($t['montant_recu'] ?? 0);
        $attendu += (float)($t['montant_contractuel'] ?? 0);
    }
    $depense = 0.0;
    foreach (balance($pid) as $c) {
        if (in_array($c['type'], COMPTES_TRESORERIE, true)) {
            $depense += $c['solde'];
        }
    }
    return [
        'recu'       => round($recu, 2),
        'attendu'    => round($attendu, 2),
        'a_recevoir' => round($attendu - $recu, 2),
        'tresorerie' => round($depense, 2),
    ];
}

// ---------------------------------------------------------------------
// Demandes de versement
// ---------------------------------------------------------------------

function demandes_paiement(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT d.*, t.numero AS tranche_numero,
                (SELECT COUNT(*) FROM pieces_demande p WHERE p.demande_id = d.id
                  AND p.obligatoire = 1 AND p.statut = \'attendue\') AS pieces_manquantes
           FROM demandes_paiement d JOIN tranches t ON t.id = d.tranche_id
          WHERE d.projet_id = ? ORDER BY d.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function demande_paiement(int $id): ?array
{
    $st = db()->prepare(
        'SELECT d.*, t.numero AS tranche_numero, t.montant_contractuel, t.declencheur
           FROM demandes_paiement d JOIN tranches t ON t.id = d.tranche_id
          WHERE d.id = ? AND d.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $d = $st->fetch();
    return $d === false ? null : $d;
}

function pieces_demande(int $demandeId): array
{
    $st = db()->prepare(
        'SELECT p.*, f.nom_genere FROM pieces_demande p LEFT JOIN fichiers f ON f.id = p.fichier_id
          WHERE p.demande_id = ? ORDER BY p.ordre'
    );
    $st->execute([$demandeId]);
    return $st->fetchAll();
}

/**
 * Ouvre une demande de versement et sa checklist. « Le dossier de demande porte la
 * checklist correspondante » (CDC 4.10), et cette liste differe selon la tranche :
 * la premiere ne demande que le contrat signe, la demande de paiement, la fiche
 * signaletique validee par la banque et les pieces d'identite des signataires.
 *
 * @return array{success: bool, id?: int, error?: string}
 */
function demande_ouvrir(int $trancheId, ?float $montant = null): array
{
    if (($refus = droit_ecriture('demande_tranche')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    $t = tranche($trancheId);
    if ($t === null) {
        return ['success' => false, 'error' => 'Tranche inconnue dans ce projet.'];
    }
    if ($t['montant_recu'] !== null) {
        return ['success' => false, 'error' => 'Cette tranche est déjà encaissée.'];
    }
    $montant = $montant ?? ($t['montant_contractuel'] === null ? null : (float)$t['montant_contractuel']);
    if ($montant === null || $montant <= 0) {
        return ['success' => false, 'error' => 'Le montant contractuel de la tranche n\'est pas saisi : '
            . 'il se fixe à la signature, avant toute demande.'];
    }
    $sd = db()->prepare("SELECT COUNT(*) FROM demandes_paiement WHERE tranche_id = ? AND statut <> 'payee'");
    $sd->execute([$trancheId]);
    if ((int)$sd->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'Une demande est déjà en cours pour cette tranche.'];
    }

    $premiere = (int)$t['numero'] === 1;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO demandes_paiement (projet_id, tranche_id, date, montant, created_by) VALUES (?,?,CURDATE(),?,?)')
            ->execute([projet_id(), $trancheId, $montant, user_id()]);
        $id = (int)$pdo->lastInsertId();
        $ordre = 0;
        foreach (PIECES_DEMANDE[$premiere ? 'premiere' : 'suivante'] as [$code, $libelle]) {
            $pdo->prepare('INSERT INTO pieces_demande (projet_id, demande_id, type, libelle, statut, ordre)
                           VALUES (?,?,?,?,\'attendue\',?)')
                ->execute([projet_id(), $id, $code, $libelle, ++$ordre]);
        }
        audit_strict('financement', 'demande_ouverte', 'demande_paiement', $id,
            'Tranche ' . (int)$t['numero'] . ' · ' . htg($montant)
            . ' · ' . $ordre . ' pièce(s) attendue(s)'
            . ($premiere ? ' · première tranche, sans rapport joint' : ''));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('demande_ouvrir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Ouverture impossible : ' . $e->getMessage()];
    }
    return ['success' => true, 'id' => $id];
}

function piece_demande_verser(int $pieceId, ?array $fichier): array
{
    $st = db()->prepare(
        'SELECT p.*, d.statut AS demande_statut, d.tranche_id FROM pieces_demande p
           JOIN demandes_paiement d ON d.id = p.demande_id
          WHERE p.id = ? AND p.projet_id = ?'
    );
    $st->execute([$pieceId, projet_id()]);
    $piece = $st->fetch();
    if ($piece === false) {
        return ['success' => false, 'error' => 'Pièce inconnue dans ce projet.'];
    }
    if (in_array($piece['demande_statut'], ['transmise', 'payee'], true)) {
        return ['success' => false, 'error' => 'Cette demande est ' . $piece['demande_statut'] . ' : sa liasse est arrêtée.'];
    }
    if (empty($fichier['name'])) {
        return ['success' => false, 'error' => 'Le fichier numérisé est obligatoire.'];
    }
    $up = enregistrer_upload($fichier, 'documents',
        projet_code() . '-DEMANDE-' . (int)$piece['demande_id'] . '-' . strtoupper($piece['type']) . '.pdf',
        ALLOWED_DOCUMENT, false, $piece['fichier_id'] ? (int)$piece['fichier_id'] : null);
    if (!$up['success']) {
        return ['success' => false, 'error' => $up['error']];
    }
    db()->prepare("UPDATE pieces_demande SET fichier_id = ?, statut = 'recue' WHERE id = ?")
        ->execute([(int)$up['id'], $pieceId]);
    audit('financement', 'piece_demande_versee', 'demande_paiement', (int)$piece['demande_id'], $piece['libelle']);
    return ['success' => true];
}

/**
 * « Le figement des rapports precede la transmission, de sorte que la copie envoyee
 * a l'UGP et la version conservee soient rigoureusement identiques » (CDC 4.10).
 *
 * Une demande autre que la premiere joint donc un rapport fige, et ne se valide pas
 * sans lui.
 */
function demande_valider(int $demandeId, ?int $rapportId = null): array
{
    $d = demande_paiement($demandeId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Demande inconnue dans ce projet.'];
    }
    if ($d['statut'] !== 'preparation') {
        return ['success' => false, 'error' => 'Cette demande est déjà ' . (STATUTS_DEMANDE[$d['statut']] ?? $d['statut']) . '.'];
    }
    $manquantes = [];
    foreach (pieces_demande($demandeId) as $p) {
        if ((int)$p['obligatoire'] === 1 && $p['statut'] === 'attendue') {
            $manquantes[] = $p['libelle'];
        }
    }
    if ($manquantes) {
        return ['success' => false, 'error' => 'Pièces manquantes : ' . implode(', ', $manquantes) . '.'];
    }

    $ref = null;
    if ((int)$d['tranche_numero'] !== 1) {
        if ($rapportId === null) {
            return ['success' => false, 'error' => 'Une demande autre que la première joint le rapport figé de la période : '
                . 'le figement précède la transmission, de sorte que la copie envoyée et la version conservée soient identiques.'];
        }
        $r = rapport_restitution($rapportId);
        if ($r === null) {
            return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
        }
        if (!in_array($r['statut'], ['valide', 'transmis'], true)) {
            return ['success' => false, 'error' => 'Le rapport joint doit être figé : celui-ci est encore '
                . (STATUTS_RAPPORT_RESTITUTION[$r['statut']] ?? $r['statut']) . '.'];
        }
        $ref = 'rapport:' . $rapportId;
    }

    db()->prepare("UPDATE demandes_paiement SET statut = 'validee', rapport_ref = ? WHERE id = ?")
        ->execute([$ref, $demandeId]);
    audit('financement', 'demande_validee', 'demande_paiement', $demandeId,
        'Tranche ' . (int)$d['tranche_numero'] . ' · ' . htg((float)$d['montant'])
        . ($ref ? ' · ' . $ref : ' · première tranche, sans rapport joint'));
    return ['success' => true];
}

/**
 * « La date de transmission ouvre le delai contractuel de cinq jours et est
 * conservee avec l'accuse » (CDC 4.10).
 */
function demande_transmettre(int $demandeId, string $date, ?array $accuse = null): array
{
    $d = demande_paiement($demandeId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Demande inconnue dans ce projet.'];
    }
    if ($d['statut'] !== 'validee') {
        return ['success' => false, 'error' => 'Une demande se transmet une fois validée.'];
    }
    $accuseId = null;
    if (!empty($accuse['name'])) {
        $up = enregistrer_upload($accuse, 'documents',
            projet_code() . '-ACCUSE-DEMANDE-' . $demandeId . '.pdf', ALLOWED_DOCUMENT);
        if (!$up['success']) {
            return ['success' => false, 'error' => 'Accusé de réception : ' . $up['error']];
        }
        $accuseId = (int)$up['id'];
    }
    db()->prepare("UPDATE demandes_paiement SET statut = 'transmise', date_transmission = ?, accuse_fichier_id = ? WHERE id = ?")
        ->execute([$date, $accuseId, $demandeId]);
    audit('financement', 'demande_transmise', 'demande_paiement', $demandeId,
        'Transmise le ' . date_fr($date) . ' · délai contractuel de cinq jours ouvert');
    return ['success' => true];
}

/**
 * « L'article 4.3 autorise l'UGP a reclamer des informations complementaires sous
 * trente jours. La demande de paiement porte donc un etat d'attente de complement,
 * avec la date de la demande recue et celle de la reponse produite » (CDC 4.10).
 */
function demande_complement(int $demandeId, string $date): array
{
    $d = demande_paiement($demandeId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Demande inconnue dans ce projet.'];
    }
    if ($d['statut'] !== 'transmise') {
        return ['success' => false, 'error' => 'Un complément ne se demande que sur une demande transmise.'];
    }
    db()->prepare("UPDATE demandes_paiement SET statut = 'complement_demande', date_demande_complement = ? WHERE id = ?")
        ->execute([$date, $demandeId]);
    audit('financement', 'complement_demande', 'demande_paiement', $demandeId,
        'Complément réclamé le ' . date_fr($date) . ' · article 4.3, trente jours');
    return ['success' => true];
}

function demande_repondre_complement(int $demandeId, string $date): array
{
    $d = demande_paiement($demandeId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Demande inconnue dans ce projet.'];
    }
    if ($d['statut'] !== 'complement_demande') {
        return ['success' => false, 'error' => 'Aucun complément n\'est en attente sur cette demande.'];
    }
    if ($date < (string)$d['date_demande_complement']) {
        return ['success' => false, 'error' => 'La réponse ne précède pas la demande de complément.'];
    }
    db()->prepare("UPDATE demandes_paiement SET statut = 'complement_repondu', date_reponse_complement = ? WHERE id = ?")
        ->execute([$date, $demandeId]);
    $jours = (int)(new DateTimeImmutable((string)$d['date_demande_complement']))->diff(new DateTimeImmutable($date))->format('%a');
    audit('financement', 'complement_repondu', 'demande_paiement', $demandeId,
        'Répondu le ' . date_fr($date) . ' · ' . $jours . ' jour(s) après la demande');
    return ['success' => true];
}

/** Le paiement d'une demande se constate a l'encaissement de sa tranche. */
function demande_constater_paiement(int $demandeId): void
{
    $d = demande_paiement($demandeId);
    if ($d === null) {
        return;
    }
    $t = tranche((int)$d['tranche_id']);
    if ($t !== null && $t['montant_recu'] !== null) {
        db()->prepare("UPDATE demandes_paiement SET statut = 'payee' WHERE id = ? AND statut <> 'payee'")
            ->execute([$demandeId]);
    }
}
