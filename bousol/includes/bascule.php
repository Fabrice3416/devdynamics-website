<?php
declare(strict_types=1);

/**
 * Bascule vers la phase de suivi post-cloture (CDC 1.7 et 9).
 *
 * Un projet peut vivre en deux regimes successifs, et cette double temporalite
 * s'active projet par projet : elle est retenue pour KesKle, dont l'accompagnement
 * se poursuit jusqu'en avril 2028, et desactivee pour Koule Ki Pale, qui se clot a
 * la transmission de son rapport final.
 *
 * Entre les deux s'intercalent une periode de regularisation, de duree
 * parametrable, puis une checklist de cloture bloquante declenchee manuellement
 * par le Coordinateur. La bascule n'est jamais automatique : elle se decide.
 */

require_once __DIR__ . '/restitution.php';
require_once __DIR__ . '/financement.php';

/**
 * La checklist de bascule. Chaque point est bloquant : « la cloture de fin
 * d'execution est le dernier passage dans la sequence de cloture avant la
 * checklist de bascule » (CDC 6.6).
 *
 * @return array{ok: bool, points: array<int, array{nom: string, ok: bool, motif: string}>}
 */
function bascule_checklist(?int $projetId = null): array
{
    $pid = $projetId ?? projet_id();
    $points = [];

    // 1. Toutes les periodes figees.
    $st = db()->prepare("SELECT numero FROM periodes WHERE projet_id = ? AND statut <> 'figee' ORDER BY numero");
    $st->execute([$pid]);
    $ouvertes = $st->fetchAll(PDO::FETCH_COLUMN);
    $points[] = [
        'nom'   => 'Périodes figées',
        'ok'    => $ouvertes === [],
        'motif' => $ouvertes === []
            ? 'Toutes les périodes du projet sont figées par leur rapport.'
            : 'Périodes encore ouvertes : ' . implode(', ', $ouvertes) . '.',
    ];

    // 2. Aucun dossier en cours.
    $st = db()->prepare(
        "SELECT numero, statut FROM dossiers
          WHERE projet_id = ? AND statut NOT IN ('clos', 'abandonne') ORDER BY numero"
    );
    $st->execute([$pid]);
    $encours = $st->fetchAll();
    $points[] = [
        'nom'   => 'Dossiers de dépense',
        'ok'    => $encours === [],
        'motif' => $encours === []
            ? 'Tous les dossiers sont clos ou abandonnés.'
            : count($encours) . ' dossier(s) en cours : '
              . implode(', ', array_map(fn($d) => $d['numero'] . ' (' . $d['statut'] . ')', array_slice($encours, 0, 6)))
              . '. Un dossier ouvert après la bascule ne trouvera plus où s\'imputer.',
    ];

    // 3. Dette fiscale soldee sur tous les mois.
    $st = db()->prepare("SELECT numero FROM periodes WHERE projet_id = ? ORDER BY numero");
    $st->execute([$pid]);
    $nonSoldes = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mois) {
        $d = dette_dgi_soldee((int)$mois, $pid);
        if (!$d['soldee']) {
            $nonSoldes[] = (string)$mois;
        }
    }
    $points[] = [
        'nom'   => 'Dette envers la DGI',
        'ok'    => $nonSoldes === [],
        'motif' => $nonSoldes === []
            ? 'Aucun acompte retenu n\'attend son versement.'
            : 'Mois dont la dette n\'est pas soldée : ' . implode(', ', $nonSoldes) . '.',
    ];

    // 4. Rapport final transmis.
    $st = db()->prepare("SELECT COUNT(*) FROM rapports WHERE projet_id = ? AND type = 'final' AND statut = 'transmis'");
    $st->execute([$pid]);
    $final = (int)$st->fetchColumn() > 0;
    $points[] = [
        'nom'   => 'Rapport final',
        'ok'    => $final,
        'motif' => $final
            ? 'Le rapport final est transmis au bailleur.'
            : 'Le rapport final n\'est pas encore transmis : il récapitule l\'ensemble de l\'exécution.',
    ];

    // 5. Prestations ratifiees.
    $nonRatifiees = prestations_non_ratifiees($pid);
    $points[] = [
        'nom'   => 'Prestations ratifiées',
        'ok'    => $nonRatifiees === [],
        'motif' => $nonRatifiees === []
            ? 'Aucune prestation provisoire en attente de résolution.'
            : count($nonRatifiees) . ' prestation(s) provisoire(s) : la clôture finale ne peut aboutir '
              . 'tant qu\'une résolution ne couvre pas l\'ensemble de la période.',
    ];

    // 6. Demandes de versement soldees.
    $st = db()->prepare(
        "SELECT COUNT(*) FROM demandes_paiement WHERE projet_id = ? AND statut NOT IN ('payee')"
    );
    $st->execute([$pid]);
    $demandes = (int)$st->fetchColumn();
    $points[] = [
        'nom'   => 'Demandes de versement',
        'ok'    => $demandes === 0,
        'motif' => $demandes === 0
            ? 'Toutes les demandes de versement sont payées.'
            : $demandes . ' demande(s) non soldée(s) : aucune pièce ne se transmet plus après la bascule.',
    ];

    return ['ok' => !in_array(false, array_column($points, 'ok'), true), 'points' => $points];
}

/**
 * Ouvre la periode de regularisation. Sa duree est parametrable, et pendant elle
 * aucune imputation nouvelle ne se cree tandis que les dossiers ouverts se menent
 * a leur terme (CDC 1.7).
 */
function regularisation_ouvrir(): array
{
    if (($refus = droit_ecriture('bascule')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    if (phase_code() !== 'projet_actif') {
        return ['success' => false, 'error' => 'Le projet n\'est plus en exécution : la régularisation est déjà passée.'];
    }
    $duree = (int)(param('duree_regularisation_jours', '30') ?? 30);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE phases SET statut = 'close' WHERE projet_id = ? AND code = 'projet_actif'")
            ->execute([projet_id()]);
        $pdo->prepare("UPDATE phases SET statut = 'en_cours' WHERE projet_id = ? AND code = 'regularisation'")
            ->execute([projet_id()]);
        audit_strict('noyau', 'regularisation_ouverte', 'phase', projet_id(),
            'Période de régularisation de ' . $duree . ' jours · aucune imputation nouvelle, '
            . 'les dossiers ouverts se mènent à leur terme');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('regularisation_ouvrir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Ouverture impossible.'];
    }
    return ['success' => true, 'duree' => $duree];
}

/**
 * Bascule vers la phase de suivi post-cloture.
 *
 * « L'enveloppe indirecte est figee a la bascule, a sept pour cent des couts
 * directs finalement constates, conformement a l'article 3.3. Elle n'est pas
 * necessairement egale au montant budgete, puisqu'elle se recalcule sur la
 * consommation reelle » (CDC 9.5). C'est elle qui finance la phase 2, et elle
 * n'est connue qu'a ce moment.
 */
function basculer(string $motif): array
{
    if (($refus = droit_ecriture('bascule')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    if (param('suivi_post_cloture', '0') !== '1') {
        return ['success' => false, 'error' => 'Ce projet n\'a pas de phase de suivi post-clôture : '
            . 'il se clôt à la transmission de son rapport final.'];
    }
    if (phase_code() !== 'regularisation') {
        return ['success' => false, 'error' => 'La bascule suit la période de régularisation : '
            . 'la phase courante est « ' . (phase_code() ?? 'non initialisée') . ' ».'];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif de la bascule est obligatoire : il reste au journal d\'audit.'];
    }
    $checklist = bascule_checklist();
    if (!$checklist['ok']) {
        $manquants = [];
        foreach ($checklist['points'] as $p) {
            if (!$p['ok']) {
                $manquants[] = $p['nom'] . ' : ' . $p['motif'];
            }
        }
        return ['success' => false, 'error' => 'La checklist de bascule est bloquante. ' . implode(' ', $manquants)];
    }

    $solde = solde_cloture();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE phases SET statut = 'close' WHERE projet_id = ? AND code = 'regularisation'")
            ->execute([projet_id()]);
        $pdo->prepare("UPDATE phases SET statut = 'en_cours' WHERE projet_id = ? AND code = 'post_cloture'")
            ->execute([projet_id()]);
        audit_strict('noyau', 'bascule', 'phase', projet_id(),
            'Passage en suivi post-clôture · ' . trim($motif)
            . ' · enveloppe indirecte figée à ' . htg($solde['indirects'])
            . ' sur ' . htg($solde['directs']) . ' de coûts directs constatés');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('basculer: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Bascule impossible.'];
    }
    // Le parametre est historise : l'enveloppe figee reste lisible avec sa date.
    param_set('enveloppe_indirecte_figee', number_format($solde['indirects'], 2, '.', ''),
        'Figée à la bascule, ' . number_format($solde['taux_indirect'] * 100, 2, ',', ' ')
        . ' % de ' . htg($solde['directs']) . ' de coûts directs constatés');
    return ['success' => true, 'enveloppe' => $solde['indirects'], 'directs' => $solde['directs']];
}

/**
 * « Une reouverture exceptionnelle reste possible apres bascule. Elle est motivee,
 * tracee au journal d'audit, bornee dans le temps, et ne rouvre que l'etat de
 * regularisation, jamais la creation de depense » (CDC 1.7).
 */
function reouverture_ouvrir(string $motif, string $dateLimite, ?int $periodeId = null): array
{
    if (($refus = droit_ecriture('reouverture')) !== null) {
        return ['success' => false, 'error' => $refus];
    }
    if (trim($motif) === '') {
        return ['success' => false, 'error' => 'Le motif de la réouverture est obligatoire.'];
    }
    if ($dateLimite <= date('Y-m-d')) {
        return ['success' => false, 'error' => 'Une réouverture est bornée dans le temps : sa date limite est future.'];
    }
    $st = db()->prepare("SELECT COUNT(*) FROM reouvertures WHERE projet_id = ? AND statut = 'ouverte'");
    $st->execute([projet_id()]);
    if ((int)$st->fetchColumn() > 0) {
        return ['success' => false, 'error' => 'Une réouverture est déjà en cours sur ce projet.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO reouvertures (projet_id, periode_id, motif, date_debut, date_limite, auteur_id)
             VALUES (?,?,?,CURDATE(),?,?)'
        )->execute([projet_id(), $periodeId, trim($motif), $dateLimite, user_id()]);
        $id = (int)$pdo->lastInsertId();
        // Elle ne rouvre que l'etat de regularisation : la creation de depense
        // reste fermee, require_creation_depense() y veille.
        $pdo->prepare("UPDATE phases SET statut = 'close' WHERE projet_id = ? AND code = 'post_cloture'")
            ->execute([projet_id()]);
        $pdo->prepare("UPDATE phases SET statut = 'en_cours' WHERE projet_id = ? AND code = 'regularisation'")
            ->execute([projet_id()]);
        audit_strict('noyau', 'reouverture_ouverte', 'reouverture', $id,
            trim($motif) . ' · bornée au ' . date_fr($dateLimite)
            . ' · rouvre la régularisation, jamais la création de dépense');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('reouverture_ouvrir: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Réouverture impossible.'];
    }
    return ['success' => true, 'id' => $id];
}

function reouverture_clore(int $reouvertureId): array
{
    $st = db()->prepare('SELECT * FROM reouvertures WHERE id = ? AND projet_id = ?');
    $st->execute([$reouvertureId, projet_id()]);
    $r = $st->fetch();
    if ($r === false) {
        return ['success' => false, 'error' => 'Réouverture inconnue dans ce projet.'];
    }
    if ($r['statut'] === 'close') {
        return ['success' => false, 'error' => 'Cette réouverture est déjà close.'];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE reouvertures SET statut = 'close' WHERE id = ?")->execute([$reouvertureId]);
        $pdo->prepare("UPDATE phases SET statut = 'close' WHERE projet_id = ? AND code = 'regularisation'")
            ->execute([projet_id()]);
        $pdo->prepare("UPDATE phases SET statut = 'en_cours' WHERE projet_id = ? AND code = 'post_cloture'")
            ->execute([projet_id()]);
        audit_strict('noyau', 'reouverture_close', 'reouverture', $reouvertureId,
            'Retour en suivi post-clôture · ' . $r['motif']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => 'Clôture impossible.'];
    }
    return ['success' => true];
}

function reouvertures(?int $projetId = null): array
{
    $st = db()->prepare(
        'SELECT r.*, t.nom AS auteur FROM reouvertures r
           JOIN utilisateurs u ON u.id = r.auteur_id JOIN tiers t ON t.id = u.tiers_id
          WHERE r.projet_id = ? ORDER BY r.id DESC'
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/** Les reouvertures dont la borne est depassee : elles doivent etre closes. */
function reouvertures_expirees(?int $projetId = null): array
{
    $st = db()->prepare(
        "SELECT * FROM reouvertures WHERE projet_id = ? AND statut = 'ouverte' AND date_limite < CURDATE()"
    );
    $st->execute([$projetId ?? projet_id()]);
    return $st->fetchAll();
}

/**
 * « A la seconde borne, l'outil produit le paquet autoportant, remis a la direction
 * et conserve jusqu'en 2032 au titre de l'article 5.6. La base peut alors etre
 * arretee » (CDC 9.6).
 *
 * Ce que Bousol produit ici est l'index du classement general : la liste complete
 * des pieces, des documents et des liasses du projet, dans l'ordre qui rend
 * l'arborescence extraite lisible sans l'outil. L'extraction des fichiers eux-memes
 * se fait par l'export de sauvegarde, qui existe deja au module Noyau.
 *
 * @return array{success: bool, id?: int, nombre?: int, error?: string}
 */
function archive_definitive(): array
{
    if (user_role() !== 'coordinateur') {
        return ['success' => false, 'error' => 'L\'archive définitive est produite par le Coordinateur.'];
    }
    $index = "ARCHIVE DÉFINITIVE — " . projet_code() . " — " . projet_intitule() . "\n"
           . "Contrat " . (param('numero_contrat') ?? '—') . " · " . (param('nom_organisation', 'DÉVELOPPEMENT ET DYNAMISME')) . "\n"
           . "Produite le " . date_fr(date('Y-m-d')) . " · conservation jusqu'en 2032, article 5.6\n\n";

    $st = db()->prepare(
        "SELECT i.numero_piece, d.numero AS dossier, d.objet, p.libelle, f.nom_genere, f.empreinte
           FROM pieces p
           JOIN dossiers d ON d.id = p.dossier_id
           LEFT JOIN imputations i ON i.dossier_id = d.id
           LEFT JOIN fichiers f ON f.id = p.fichier_id
          WHERE p.projet_id = ? AND p.statut = 'recue'
          ORDER BY i.numero_piece, d.numero, p.ordre"
    );
    $st->execute([projet_id()]);
    $pieces = $st->fetchAll();
    $index .= "PIÈCES JUSTIFICATIVES (" . count($pieces) . ")\n";
    foreach ($pieces as $p) {
        $index .= sprintf("  %-10s %-12s %-44s %s\n", $p['numero_piece'] ?? '—', $p['dossier'],
            mb_substr((string)$p['libelle'], 0, 44), mb_substr((string)($p['empreinte'] ?? ''), 0, 16));
    }

    $sd = db()->prepare(
        "SELECT d.type, d.objet_type, d.objet_id, f.nom_genere, f.empreinte
           FROM documents d LEFT JOIN fichiers f ON f.id = d.fichier_id
          WHERE d.projet_code = ? ORDER BY d.type, d.objet_id"
    );
    $sd->execute([projet_code()]);
    $documents = $sd->fetchAll();
    $index .= "\nDOCUMENTS PRODUITS (" . count($documents) . ")\n";
    foreach ($documents as $d) {
        $index .= sprintf("  %-24s %-18s %s\n", $d['type'], $d['objet_type'] . ':' . $d['objet_id'],
            mb_substr((string)($d['empreinte'] ?? ''), 0, 16));
    }

    $sl = db()->prepare('SELECT type, objet_ref, nombre_pieces FROM liasses WHERE projet_id = ? ORDER BY id');
    $sl->execute([projet_id()]);
    $mesLiasses = $sl->fetchAll();
    $index .= "\nLIASSES (" . count($mesLiasses) . ")\n";
    foreach ($mesLiasses as $l) {
        $index .= sprintf("  %-12s %-24s %d pièce(s)\n", $l['type'], (string)($l['objet_ref'] ?? '—'), (int)$l['nombre_pieces']);
    }

    $solde = solde_cloture();
    $index .= "\nDÉCOMPTE FINAL\n"
            . sprintf("  Coûts directs constatés   %s\n", htg($solde['directs']))
            . sprintf("  Enveloppe indirecte figée %s\n", htg($solde['indirects']))
            . sprintf("  Préfinancements reçus     %s\n", htg($solde['prefinancements']))
            . sprintf("  Solde %-19s %s\n", $solde['sens'] === 'a_recevoir' ? 'à recevoir' : 'à rembourser', htg($solde['solde']));

    $f = enregistrer_contenu($index, 'txt', 'text/plain', 'exports',
        projet_code() . '-ARCHIVE-' . date('Ymd') . '.txt');
    if (empty($f['success'])) {
        return ['success' => false, 'error' => 'Enregistrement de l\'archive impossible.'];
    }
    db()->prepare(
        'INSERT INTO liasses (projet_id, type, objet_ref, fichier_id, nombre_pieces, created_by)
         VALUES (?,?,?,?,?,?)'
    )->execute([projet_id(), 'classement', 'projet:' . projet_id(), (int)$f['id'],
                count($pieces) + count($documents), user_id()]);
    $id = (int)db()->lastInsertId();
    audit('noyau', 'archive_definitive', 'liasse', $id,
        count($pieces) . ' pièce(s), ' . count($documents) . ' document(s), ' . count($mesLiasses) . ' liasse(s)');
    return ['success' => true, 'id' => $id, 'nombre' => count($pieces) + count($documents)];
}
