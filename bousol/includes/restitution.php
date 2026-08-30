<?php
declare(strict_types=1);

/**
 * Module Restitution - cloture de periode, rapports et figement (CDC 6).
 *
 * La cloture n'est pas datee, elle est conditionnee : elle intervient quand les
 * dossiers de la periode sont complets, et son declenchement reste manuel. Une
 * seule sequence sert les trois echeances, seule la liste de sorties variant.
 *
 * Le figement est ce qui donne sa valeur au reste. Les lignes du rapport financier
 * sont stockees et non recalculees, de sorte qu'une correction ulterieure dans un
 * dossier ancien ne modifie jamais un rapport deja envoye - et le cumul du rapport
 * suivant repart de ce qui a ete transmis, non de ce que la base dit aujourd'hui.
 */

require_once __DIR__ . '/depenses.php';
require_once __DIR__ . '/remuneration.php';
require_once __DIR__ . '/activites.php';
require_once __DIR__ . '/documents.php';

const TYPES_RAPPORT = [
    'mensuel'       => 'Rapport mensuel',
    'intermediaire' => 'Rapport intermédiaire',
    'final'         => 'Rapport final',
    'rectificatif'  => 'Version rectificative',
];

const STATUTS_RAPPORT_RESTITUTION = [
    'brouillon' => 'Brouillon', 'valide' => 'Validé', 'transmis' => 'Transmis',
];

// ---------------------------------------------------------------------
// Cloture de periode : les trois etapes bloquantes
// ---------------------------------------------------------------------

/**
 * « Trois etapes sont bloquantes, le controle de completude, le versement a la DGI
 * et le rapprochement » (CDC 6.6).
 *
 * Le controle de completude n'exige pas que tous les dossiers soient clos, ce qui
 * rendrait la cloture impossible, mais que tous soient regles et que toutes les
 * pieces exigibles avant paiement soient presentes. Les pieces posterieures peuvent
 * rejoindre leur dossier apres le figement.
 *
 * @return array{ok: bool, etapes: array<int, array{nom: string, ok: bool, motif: string}>}
 */
function cloture_controles(int $periodeId): array
{
    $st = db()->prepare('SELECT * FROM periodes WHERE id = ? AND projet_id = ?');
    $st->execute([$periodeId, projet_id()]);
    $periode = $st->fetch();
    if ($periode === false) {
        return ['ok' => false, 'etapes' => [['nom' => 'Période', 'ok' => false, 'motif' => 'Période inconnue dans ce projet.']]];
    }
    $etapes = [];

    // 1. Completude des dossiers de la periode.
    $sd = db()->prepare(
        "SELECT d.id, d.numero, d.statut,
                (SELECT COUNT(*) FROM pieces p WHERE p.dossier_id = d.id
                  AND p.obligatoire = 1 AND p.moment = 'avant' AND p.statut = 'attendue') AS pieces_avant
           FROM dossiers d
          WHERE d.projet_id = ? AND d.periode_id = ? AND d.statut <> 'abandonne'"
    );
    $sd->execute([projet_id(), $periodeId]);
    $incomplets = [];
    foreach ($sd->fetchAll() as $d) {
        if (!in_array($d['statut'], ['regle', 'clos'], true)) {
            $incomplets[] = $d['numero'] . ' (' . $d['statut'] . ')';
        } elseif ((int)$d['pieces_avant'] > 0) {
            $incomplets[] = $d['numero'] . ' (' . (int)$d['pieces_avant'] . ' pièce(s) préalable(s) manquante(s))';
        }
    }
    $etapes[] = [
        'nom'   => 'Complétude des dossiers',
        'ok'    => $incomplets === [],
        'motif' => $incomplets === []
            ? 'Tous les dossiers de la période sont réglés et leurs pièces préalables au paiement sont présentes.'
            : 'À régler ou à compléter : ' . implode(', ', $incomplets)
              . '. Un dossier n\'a pas besoin d\'être clos, mais il doit être réglé.',
    ];

    // 2. Versement a la DGI de la dette nee dans la periode.
    $dette = dette_dgi_soldee((int)$periode['numero']);
    $etapes[] = [
        'nom'   => 'Versement à la DGI',
        'ok'    => $dette['soldee'],
        'motif' => $dette['soldee']
            ? 'La dette fiscale née dans cette période est soldée.'
            : $dette['motif'],
    ];

    // 3. Rapprochement bancaire de la periode, valide.
    $sr = db()->prepare(
        "SELECT COUNT(*) FROM rapprochements WHERE projet_id = ? AND periode_id = ? AND statut = 'valide'"
    );
    $sr->execute([projet_id(), $periodeId]);
    $rapproche = (int)$sr->fetchColumn() > 0;
    $etapes[] = [
        'nom'   => 'Rapprochement bancaire',
        'ok'    => $rapproche,
        'motif' => $rapproche
            ? 'Le rapprochement de la période est validé.'
            : 'Aucun rapprochement validé pour cette période : il est produit à chaque période et accompagne les demandes de tranche.',
    ];

    return ['ok' => !in_array(false, array_column($etapes, 'ok'), true), 'etapes' => $etapes];
}

/**
 * Les cheques emis et non encore presentes a la banque. « Un cheque remis est regle
 * meme s'il n'est pas encore presente. La cloture arrete donc la liste des cheques
 * en circulation, dont l'ecriture existe depuis l'emission » (CDC 6.6).
 */
function cheques_en_circulation(int $periodeId): array
{
    $st = db()->prepare(
        "SELECT r.numero, r.numero_cheque, r.montant, r.date_reglement, t.nom AS beneficiaire
           FROM reglements r
           JOIN tiers t ON t.id = r.beneficiaire_id
           JOIN ecritures e ON e.reglement_id = r.id
          WHERE r.projet_id = ? AND r.mode = 'cheque' AND r.statut = 'execute'
            AND e.periode_id = ?
            AND NOT EXISTS (SELECT 1 FROM lignes_rapprochement lr
                             WHERE lr.reglement_id = r.id AND lr.nature = 'cheque_non_encaisse')
          ORDER BY r.date_reglement"
    );
    $st->execute([projet_id(), $periodeId]);
    return $st->fetchAll();
}

function periodes_ouvertes(?int $projetId = null): array
{
    $st = db()->prepare("SELECT * FROM periodes WHERE projet_id = ? AND statut <> 'figee' ORDER BY numero");
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------
// Rapports
// ---------------------------------------------------------------------

function rapports_restitution(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT r.*, p.numero AS periode_numero, t.nom AS auteur
           FROM rapports r
           LEFT JOIN periodes p ON p.id = r.periode_id
           JOIN utilisateurs u ON u.id = r.created_by JOIN tiers t ON t.id = u.tiers_id
          WHERE r.projet_id = ? ORDER BY r.periode_fin DESC, r.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

function rapport_restitution(int $id): ?array
{
    $st = db()->prepare(
        'SELECT r.*, p.numero AS periode_numero, p.statut AS periode_statut
           FROM rapports r LEFT JOIN periodes p ON p.id = r.periode_id
          WHERE r.id = ? AND r.projet_id = ?'
    );
    $st->execute([$id, projet_id()]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * Ouvre un rapport sur une periode. Une seule sequence sert les trois echeances :
 * seule la liste de sorties varie, un rapport mensuel produisant le seul rapport
 * mensuel, un intermediaire y ajoutant les rapports contractuels et la liasse, un
 * final le decompte du solde (CDC 6.6).
 *
 * @return array{success: bool, id?: int, error?: string}
 */
function rapport_ouvrir(string $type, int $periodeId, string $commentaire = ''): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Valider et signer un rapport revient au Coordinateur (annexe B).'];
    }
    if (!array_key_exists($type, TYPES_RAPPORT) || $type === 'rectificatif') {
        return ['success' => false, 'error' => 'Type de rapport hors liste : une version rectificative se produit par rectification.'];
    }
    $st = db()->prepare('SELECT * FROM periodes WHERE id = ? AND projet_id = ?');
    $st->execute([$periodeId, projet_id()]);
    $periode = $st->fetch();
    if ($periode === false) {
        return ['success' => false, 'error' => 'Période inconnue dans ce projet.'];
    }
    if ($periode['statut'] === 'figee') {
        return ['success' => false, 'error' => 'Cette période est déjà figée : une correction passe par la réouverture exceptionnelle.'];
    }

    $version = cadre_version_courante();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO rapports (projet_id, type, periode_debut, periode_fin, periode_id, version_cadre_ref,
                                   contenu_json, created_by)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), $type, $periode['date_debut'], $periode['date_fin'], $periodeId,
                    $version === null ? null : (int)$version['numero'],
                    json_encode(['commentaire' => trim($commentaire)], JSON_UNESCAPED_UNICODE), user_id()]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE periodes SET statut = 'en_cloture' WHERE id = ? AND statut = 'ouverte'")->execute([$periodeId]);
        audit_strict('restitution', 'rapport_ouvert', 'rapport', $id,
            TYPES_RAPPORT[$type] . ' · période ' . (int)$periode['numero']
            . ' · cadre logique version ' . ($version['numero'] ?? 'aucune'));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('rapport_ouvrir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Ouverture impossible.'];
    }
    rapport_calculer_lignes($id);
    return ['success' => true, 'id' => $id];
}

/**
 * Calcule et STOCKE les onze lignes du rapport financier au modele de l'annexe G.
 *
 * Stockees, et non recalculees : c'est ce qui fait qu'une correction ulterieure
 * dans un dossier ancien ne modifie jamais un rapport deja envoye (CDC 6.7).
 *
 * La colonne budget affiche le budget contractuel fige, jamais le budget de
 * gestion. La valeur unitaire des depenses est une moyenne - le cout total divise
 * par la quantite - ce qui reste exact lorsqu'une meme ligne recoit des depenses a
 * des valeurs unitaires differentes. Sur une ligne non consommee, la quantite etant
 * nulle, la colonne reste vide.
 */
function rapport_calculer_lignes(int $rapportId, bool $forceFige = false): void
{
    $r = rapport_restitution($rapportId);
    if ($r === null) {
        return;
    }
    // « Les lignes du rapport financier sont stockees et non recalculees, de sorte
    // qu'une correction ulterieure dans un dossier ancien ne modifie jamais un
    // rapport deja envoye » (CDC 6.7). Une correction passe par la rectification,
    // qui est un autre rapport - d'ou le seul appel autorise sur un rapport fige,
    // celui de la validation qui arrete les chiffres une derniere fois.
    if ($r['statut'] !== 'brouillon' && !$forceFige) {
        audit('restitution', 'recalcul_refuse', 'rapport', $rapportId,
            'Rapport ' . $r['statut'] . ' : ses lignes sont figées, une correction passe par la rectification');
        return;
    }
    $pdo = db();
    $pdo->prepare('DELETE FROM lignes_financieres WHERE rapport_id = ?')->execute([$rapportId]);
    $ins = $pdo->prepare(
        'INSERT INTO lignes_financieres (projet_id, rapport_id, ligne_id, budget_unite, budget_quantite,
                                         budget_valeur, budget_total, periode_quantite, periode_valeur,
                                         periode_total, cumul_anterieur, cumul_total, difference)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $provision = budget_ligne_provision();
    $indirect  = budget_ligne_couts_indirects();

    foreach (budget_lignes() as $code => $l) {
        // (b) depenses de la periode, sur les imputations de consommation seules.
        $sp = $pdo->prepare(
            "SELECT COALESCE(SUM(i.montant), 0) AS total, COALESCE(SUM(i.quantite), 0) AS quantite
               FROM imputations i JOIN dossiers d ON d.id = i.dossier_id
              WHERE i.projet_id = ? AND i.ligne_id = ? AND i.nature = 'consommation'
                AND i.date_imputation BETWEEN ? AND ?"
        );
        $sp->execute([projet_id(), (int)$l['id'], $r['periode_debut'], $r['periode_fin']]);
        $p = $sp->fetch();
        $periodeTotal = round((float)$p['total'], 2);
        $periodeQuantite = round((float)$p['quantite'], 2);

        // « La ligne 10 reste a zero en depenses, la provision n'etant jamais
        // imputee directement » (CDC 6.4).
        if ($provision !== null && (int)$provision['id'] === (int)$l['id'] && $l['nature'] !== 'imputable') {
            $periodeTotal = 0.0;
            $periodeQuantite = 0.0;
        }

        $cumulAnterieur = cumul_anterieur((int)$l['id'], (string)$r['periode_debut'], $rapportId);
        $cumulTotal = round($cumulAnterieur + $periodeTotal, 2);
        $budgetTotal = $l['montant'] === null ? null : (float)$l['montant'];

        $ins->execute([
            projet_id(), $rapportId, (int)$l['id'],
            $l['unite'], $l['quantite'], $l['valeur_unitaire'], $budgetTotal,
            $periodeQuantite > 0 ? $periodeQuantite : null,
            $periodeQuantite > 0 ? round($periodeTotal / $periodeQuantite, 2) : null,
            $periodeTotal,
            $cumulAnterieur,
            $cumulTotal,
            $budgetTotal === null ? null : round($cumulTotal - $budgetTotal, 2),
        ]);
    }
    audit('restitution', 'lignes_financieres_calculees', 'rapport', $rapportId,
        count(budget_lignes()) . ' ligne(s) figées au modèle de l\'annexe G');
}

/**
 * « Les colonnes cumulatives reprennent le cumul fige des rapports anterieurs.
 * Lorsqu'un rapport a fait l'objet d'une version rectificative, c'est celle-ci qui
 * alimente le cumul du rapport suivant, faute de quoi la correction serait perdue
 * et l'ecart se propagerait jusqu'au rapport final » (CDC 6.4).
 */
function cumul_anterieur(int $ligneId, string $avant, int $rapportCourant): float
{
    $st = db()->prepare(
        "SELECT r.id, r.rectifie_id, lf.periode_total
           FROM rapports r JOIN lignes_financieres lf ON lf.rapport_id = r.id
          WHERE r.projet_id = ? AND lf.ligne_id = ? AND r.id <> ?
            AND r.periode_fin < ? AND r.statut IN ('valide', 'transmis')
          ORDER BY r.periode_fin, r.id"
    );
    $st->execute([projet_id(), $ligneId, $rapportCourant, $avant]);
    $lignes = $st->fetchAll();

    // Un rapport rectifie est remplace par sa rectification : on retire celui qui
    // a ete rectifie et on garde celle qui le corrige.
    $rectifies = array_filter(array_column($lignes, 'rectifie_id'));
    $cumul = 0.0;
    foreach ($lignes as $l) {
        if (in_array((int)$l['id'], array_map('intval', $rectifies), true)) {
            continue;
        }
        $cumul += (float)$l['periode_total'];
    }
    return round($cumul, 2);
}

function lignes_financieres(int $rapportId): array
{
    $st = db()->prepare(
        'SELECT lf.*, l.code, l.libelle, l.nature, l.niveau, l.rubrique
           FROM lignes_financieres lf JOIN lignes_budgetaires l ON l.id = lf.ligne_id
          WHERE lf.rapport_id = ? ORDER BY l.ordre'
    );
    $st->execute([$rapportId]);
    return $st->fetchAll();
}

/**
 * Valide le rapport et fige sa periode. « Un rapport valide fige sa periode. Les
 * depenses de la periode ne sont plus modifiables, le cumul est verrouille et sert
 * de base a la colonne des couts cumulatifs anterieurs du rapport suivant »
 * (CDC 6.7).
 *
 * Les trois etapes bloquantes de la cloture sont verifiees ici, et non a
 * l'ouverture : un rapport se prepare pendant que les dossiers se completent.
 */
function rapport_valider(int $rapportId): array
{
    $r = rapport_restitution($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'Valider et signer un rapport revient au Coordinateur (annexe B).'];
    }
    if ($r['statut'] !== 'brouillon') {
        return ['success' => false, 'error' => 'Ce rapport est déjà ' . (STATUTS_RAPPORT_RESTITUTION[$r['statut']] ?? $r['statut']) . '.'];
    }
    if ($r['periode_id'] === null) {
        return ['success' => false, 'error' => 'Ce rapport ne couvre aucune période du projet.'];
    }

    $controles = cloture_controles((int)$r['periode_id']);
    if (!$controles['ok']) {
        $motifs = [];
        foreach ($controles['etapes'] as $e) {
            if (!$e['ok']) {
                $motifs[] = $e['nom'] . ' : ' . $e['motif'];
            }
        }
        return ['success' => false, 'error' => 'La clôture n\'est pas datée, elle est conditionnée. ' . implode(' ', $motifs)];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Les lignes sont recalculees une derniere fois, puis plus jamais.
        rapport_calculer_lignes($rapportId, true);
        $pdo->prepare("UPDATE rapports SET statut = 'valide', valide_par = ? WHERE id = ?")
            ->execute([user_id(), $rapportId]);
        $pdo->prepare("UPDATE periodes SET statut = 'figee', figee_le = NOW(), figee_par = ? WHERE id = ?")
            ->execute([user_id(), (int)$r['periode_id']]);
        // La version du cadre transmise avec le rapport est figee avec lui.
        if ($r['version_cadre_ref'] !== null) {
            $sv = $pdo->prepare('SELECT id FROM versions_cadre WHERE projet_id = ? AND numero = ?');
            $sv->execute([projet_id(), (int)$r['version_cadre_ref']]);
            if ($vid = $sv->fetchColumn()) {
                cadre_version_figer((int)$vid);
            }
        }
        // « Un rapport valide fige sa periode » (CDC 6.7), et les documents qui
        // l'accompagnent avec elle : ce qui a ete envoye ne se regenere plus.
        $pdo->prepare("UPDATE documents SET statut = 'fige'
                        WHERE objet_type = 'rapport' AND objet_id = ? AND projet_code = ?")
            ->execute([$rapportId, projet_code()]);
        $cheques = count(cheques_en_circulation((int)$r['periode_id']));
        audit_strict('restitution', 'rapport_valide', 'rapport', $rapportId,
            TYPES_RAPPORT[$r['type']] . ' · période ' . (int)$r['periode_numero'] . ' figée'
            . ' · ' . $cheques . ' chèque(s) en circulation arrêté(s)');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('rapport_valider: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Validation impossible.'];
    }
    return ['success' => true];
}

/** La transmission conserve sa date, qui ouvre le delai contractuel, et son accuse. */
function rapport_transmettre(int $rapportId, string $date, ?array $accuse = null): array
{
    $r = rapport_restitution($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if ($r['statut'] !== 'valide') {
        return ['success' => false, 'error' => 'Un rapport se transmet une fois validé : le figement précède la transmission, '
            . 'de sorte que la copie envoyée et la version conservée soient rigoureusement identiques.'];
    }
    $accuseId = null;
    if (!empty($accuse['name'])) {
        $up = enregistrer_upload($accuse, 'documents',
            projet_code() . '-ACCUSE-' . $rapportId . '.pdf', ALLOWED_DOCUMENT);
        if (!$up['success']) {
            return ['success' => false, 'error' => 'Accusé de réception : ' . $up['error']];
        }
        $accuseId = (int)$up['id'];
    }
    db()->prepare("UPDATE rapports SET statut = 'transmis', date_transmission = ?, accuse_fichier_id = ? WHERE id = ?")
        ->execute([$date, $accuseId, $rapportId]);
    audit('restitution', 'rapport_transmis', 'rapport', $rapportId,
        TYPES_RAPPORT[$r['type']] . ' · transmis le ' . date_fr($date));
    return ['success' => true];
}

/**
 * « Une correction ulterieure passe par la reouverture exceptionnelle, produit une
 * version rectificative numerotee et laisse intacte la version transmise. Le
 * journal d'audit conserve le motif, l'auteur et l'ecart entre les deux versions »
 * (CDC 6.7).
 */
function rapport_rectifier(int $rapportId, string $motif): array
{
    $r = rapport_restitution($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'La réouverture exceptionnelle est autorisée par le Coordinateur (annexe B).'];
    }
    if ($r['statut'] === 'brouillon') {
        return ['success' => false, 'error' => 'Un brouillon se corrige directement : la rectification ne vaut que pour un rapport figé.'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif de la réouverture est obligatoire : il reste au journal d\'audit.'];
    }

    $ancien = [];
    foreach (lignes_financieres($rapportId) as $lf) {
        $ancien[(int)$lf['ligne_id']] = (float)$lf['periode_total'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO rapports (projet_id, type, periode_debut, periode_fin, periode_id, version,
                                   rectifie_id, version_cadre_ref, contenu_json, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([projet_id(), 'rectificatif', $r['periode_debut'], $r['periode_fin'], $r['periode_id'],
                    (int)$r['version'] + 1, $rapportId, $r['version_cadre_ref'], $r['contenu_json'], user_id()]);
        $id = (int)$pdo->lastInsertId();
        // La periode se rouvre le temps de la correction ; la version transmise
        // reste intacte, c'est la rectification qui portera le nouveau montant.
        $pdo->prepare("UPDATE periodes SET statut = 'en_cloture' WHERE id = ?")->execute([(int)$r['periode_id']]);
        // Les documents de la version corrigee sont remplaces, non detruits : la
        // version transmise reste intacte et reste consultable.
        $pdo->prepare("UPDATE documents SET statut = 'remplace'
                        WHERE objet_type = 'rapport' AND objet_id = ? AND projet_code = ? AND statut = 'fige'")
            ->execute([$rapportId, projet_code()]);
        audit_strict('restitution', 'rapport_rectifie', 'rapport', $id,
            'Rectifie le rapport ' . $rapportId . ' · version ' . ((int)$r['version'] + 1) . ' · ' . trim($motif));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('rapport_rectifier: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Rectification impossible.'];
    }

    rapport_calculer_lignes($id, true);
    $ecarts = [];
    foreach (lignes_financieres($id) as $lf) {
        $avant = $ancien[(int)$lf['ligne_id']] ?? 0.0;
        if (abs((float)$lf['periode_total'] - $avant) >= 0.01) {
            $ecarts[] = $lf['code'] . ' ' . htg($avant) . ' → ' . htg((float)$lf['periode_total']);
        }
    }
    audit('restitution', 'rectification_ecarts', 'rapport', $id,
        $ecarts ? implode(' ; ', $ecarts) : 'aucun écart de montant à ce stade');
    return ['success' => true, 'id' => $id, 'ecarts' => $ecarts];
}

// ---------------------------------------------------------------------
// Solde de cloture (CDC 6.8)
// ---------------------------------------------------------------------

/**
 * « A la cloture, Bousol etablit le decompte final. Les couts directs constates
 * determinent l'enveloppe indirecte definitive, plafonnee a sept pour cent. Le
 * total des couts eligibles finaux est compare aux prefinancements recus, ce qui
 * produit soit un solde a recevoir, soit un solde a rembourser. Ces deux montants
 * figurent explicitement dans le modele de rapport narratif et doivent donc etre
 * calcules et non estimes. »
 *
 * @return array{directs: float, indirects: float, provision_mobilisee: float,
 *               total_eligible: float, prefinancements: float, solde: float, sens: string}
 */
function solde_cloture(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $indirects = budget_couts_indirects_constates($pid);
    $directs = $indirects['directs_constates'];

    // L'enveloppe indirecte definitive est plafonnee au taux du contrat, appliquee
    // aux couts directs reellement constates.
    $enveloppeContractuelle = $indirects['enveloppe_contractuelle'];
    $enveloppe = $indirects['enveloppe'];
    if ($enveloppeContractuelle !== null && $enveloppe > $enveloppeContractuelle) {
        $enveloppe = $enveloppeContractuelle;
    }

    // La provision mobilisee se lit sur l'ecart entre gestion et contractuel de la
    // ligne qui la porte : elle a servi, elle entre dans les couts eligibles.
    $provision = budget_ligne_provision($pid);
    $mobilisee = 0.0;
    if ($provision !== null && $provision['montant'] !== null) {
        $mobilisee = round((float)$provision['montant'] - (float)($provision['montant_gestion'] ?? 0), 2);
        $mobilisee = max(0.0, $mobilisee);
    }

    $st = db()->prepare(
        "SELECT COALESCE(SUM(t.montant_recu), 0) FROM tranches t WHERE t.projet_id = ?"
    );
    $st->execute([$pid]);
    $prefinancements = round((float)$st->fetchColumn(), 2);

    $totalEligible = round($directs + $enveloppe, 2);
    $solde = round($totalEligible - $prefinancements, 2);

    return [
        'directs'             => $directs,
        'indirects'           => round($enveloppe, 2),
        'taux_indirect'       => $indirects['taux'],
        'provision_mobilisee' => $mobilisee,
        'total_eligible'      => $totalEligible,
        'prefinancements'     => $prefinancements,
        'solde'               => abs($solde),
        'sens'                => $solde >= 0 ? 'a_recevoir' : 'a_rembourser',
    ];
}

// ---------------------------------------------------------------------
// Ventilation detaillee (CDC 6.5) et liasses (CDC 5.4)
// ---------------------------------------------------------------------

/**
 * « La ventilation detaillee exigee par l'article 4.2 suit le formulaire de suivi
 * des depenses transmis par le PAIESC, avec son en-tete de financement et son
 * journal chronologique - numero de piece, date, description, mode de paiement,
 * ligne budgetaire et montant - ainsi que la feuille distincte de la petite caisse. »
 *
 * @return array{banque: array, caisse: array}
 */
function ventilation(string $debut, string $fin, ?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $st = db()->prepare(
        "SELECT i.numero_piece, i.date_imputation, i.montant, d.numero AS dossier, d.objet,
                l.code AS ligne_code, l.libelle AS ligne_libelle, t.nom AS beneficiaire,
                r.mode, c.type AS compte_type
           FROM imputations i
           JOIN dossiers d ON d.id = i.dossier_id
           JOIN lignes_budgetaires l ON l.id = i.ligne_id
           JOIN tiers t ON t.id = d.tiers_id
           LEFT JOIN reglements r ON r.origine_ref IN (CONCAT('dossier:', d.id), CONCAT('dossier_avance:', d.id))
                                 AND r.statut = 'execute'
           LEFT JOIN comptes c ON c.id = r.compte_id
          WHERE i.projet_id = ? AND i.nature = 'consommation'
            AND i.date_imputation BETWEEN ? AND ?
          ORDER BY i.date_imputation, i.numero_piece"
    );
    $st->execute([$pid, $debut, $fin]);
    $banque = [];
    $caisse = [];
    foreach ($st->fetchAll() as $r) {
        // « Ainsi que la feuille distincte de la petite caisse » : les depenses en
        // especes se presentent a part, comme le veut le formulaire.
        if ($r['compte_type'] === 'caisse') {
            $caisse[] = $r;
        } else {
            $banque[] = $r;
        }
    }
    return ['banque' => $banque, 'caisse' => $caisse];
}

function liasses(?int $rapportId = null, ?int $projetId = null): array
{
    $sql = 'SELECT l.*, f.nom_genere FROM liasses l LEFT JOIN fichiers f ON f.id = l.fichier_id
             WHERE l.projet_id = ?';
    $args = [$projetId ?? projet_id()];
    if ($rapportId !== null) {
        $sql .= ' AND l.rapport_id = ?';
        $args[] = $rapportId;
    }
    $st = db()->prepare($sql . ' ORDER BY l.id DESC');
    $st->execute($args);
    return $st->fetchAll();
}

/**
 * La liasse d'un dossier : ses pieces dans l'ordre de la checklist, ce qui est
 * l'ordre du classement physique (CDC 5.4).
 */
function liasse_dossier(int $dossierId): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['success' => false, 'error' => 'Dossier inconnu dans ce projet.'];
    }
    $pieces = array_filter(pieces_dossier($dossierId), fn($p) => $p['statut'] === 'recue' && $p['fichier_id'] !== null);
    if (!$pieces) {
        return ['success' => false, 'error' => 'Aucune pièce reçue sur ce dossier : la liasse serait vide.'];
    }
    $imputation = imputation_dossier($dossierId);
    $index = "LIASSE DE DOSSIER — " . projet_code() . " — " . $d['numero']
           . ($imputation && $imputation['numero_piece'] ? " — pièce " . $imputation['numero_piece'] : '')
           . "\n" . $d['objet'] . "\n\n";
    foreach ($pieces as $p) {
        $index .= sprintf("%-3d %-46s %s\n", (int)$p['ordre'], $p['libelle'], $p['nom_genere'] ?? '');
    }
    $index .= "\n" . count($pieces) . " pièce(s).\n";

    $f = enregistrer_contenu($index, 'txt', 'text/plain', 'liasses',
        projet_code() . '-LIASSE-' . $d['numero'] . '.txt');
    if (empty($f['success'])) {
        return ['success' => false, 'error' => 'Enregistrement de la liasse impossible.'];
    }
    db()->prepare(
        'INSERT INTO liasses (projet_id, type, objet_ref, fichier_id, nombre_pieces, created_by)
         VALUES (?,?,?,?,?,?)'
    )->execute([projet_id(), 'dossier', 'dossier:' . $dossierId, (int)$f['id'], count($pieces), user_id()]);
    $id = (int)db()->lastInsertId();
    audit('restitution', 'liasse_dossier', 'liasse', $id, $d['numero'] . ' · ' . count($pieces) . ' pièce(s)');
    return ['success' => true, 'id' => $id, 'nombre' => count($pieces)];
}

/**
 * Produit la liasse d'une periode : le classement electronique des pieces, dont
 * l'ordre reproduit le classement physique (CDC 5.4).
 *
 * @return array{success: bool, id?: int, error?: string}
 */
function liasse_periode(int $rapportId): array
{
    $r = rapport_restitution($rapportId);
    if ($r === null) {
        return ['success' => false, 'error' => 'Rapport inconnu dans ce projet.'];
    }
    $st = db()->prepare(
        "SELECT p.id, p.libelle, p.fichier_id, d.numero AS dossier, i.numero_piece
           FROM pieces p
           JOIN dossiers d ON d.id = p.dossier_id
           LEFT JOIN imputations i ON i.dossier_id = d.id
          WHERE p.projet_id = ? AND p.statut = 'recue' AND p.fichier_id IS NOT NULL
            AND d.periode_id = ?
          ORDER BY i.numero_piece, p.ordre"
    );
    $st->execute([projet_id(), (int)$r['periode_id']]);
    $pieces = $st->fetchAll();
    if (!$pieces) {
        return ['success' => false, 'error' => 'Aucune pièce reçue sur cette période : la liasse serait vide.'];
    }

    // La liasse est un index : le paquet autoportant des fichiers viendra avec
    // l'archive de la phase 8. L'index dit quelle piece porte quel numero, ce qui
    // rend le classement electronique identique au classement physique.
    $index = "LIASSE DE PÉRIODE — " . projet_code() . " — " . date_fr((string)$r['periode_debut'])
           . " au " . date_fr((string)$r['periode_fin']) . "\n\n";
    foreach ($pieces as $p) {
        $index .= sprintf("%-10s %-12s %s\n", $p['numero_piece'] ?? '—', $p['dossier'], $p['libelle']);
    }
    $index .= "\n" . count($pieces) . " pièce(s).\n";

    $f = enregistrer_contenu($index, 'txt', 'text/plain', 'liasses',
        projet_code() . '-LIASSE-P' . (int)$r['periode_numero'] . '.txt');
    if (empty($f['success'])) {
        return ['success' => false, 'error' => 'Enregistrement de la liasse impossible.'];
    }
    db()->prepare(
        'INSERT INTO liasses (projet_id, rapport_id, type, fichier_id, nombre_pieces, created_by)
         VALUES (?,?,?,?,?,?)'
    )->execute([projet_id(), $rapportId, 'periode', (int)$f['id'], count($pieces), user_id()]);
    $id = (int)db()->lastInsertId();
    audit('restitution', 'liasse_produite', 'liasse', $id,
        'Période ' . (int)$r['periode_numero'] . ' · ' . count($pieces) . ' pièce(s)');
    return ['success' => true, 'id' => $id, 'nombre' => count($pieces)];
}
