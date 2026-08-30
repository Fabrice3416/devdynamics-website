<?php
declare(strict_types=1);

/**
 * Les deux essais de cycle complet de l'annexe G.
 *
 *   Cycle complet  Mener un dossier de chaque type de sa creation a sa cloture
 *                  → chaque type produit exactement les pieces du guide, dans l'ordre
 *   Cycle complet  Rejouer un mois reel avec une douzaine de dossiers de types differents
 *                  → journal des depenses, rapport financier, rapprochement et balance concordent
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_cycle.php
 *
 * Ce sont les deux seuls cas de l'annexe qui font travailler les modules ensemble.
 * Deux des sept types ne s'ouvrent pas a la main et n'ont pas a l'etre : le dossier
 * d'honoraires nait d'une prestation acceptee, le dossier de versement a la DGI nait
 * de l'acompte que cette prestation a retenu. La recette les mene par leur vraie
 * porte, celle de Remuneration, sans quoi elle prouverait un chemin qui n'existe pas.
 *
 * Le televersement des scans ne se rejoue pas en ligne de commande - enregistrer_upload()
 * exige is_uploaded_file(). Les cases de checklist sont donc posees directement, comme
 * le fait deja la recette de la phase 4 : ce qui est eprouve ici est l'enchainement des
 * etats et la concordance des chiffres, non le televersement, couvert ailleurs.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restitution.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette du cycle complet - annexe G, les deux essais de bout en bout');
$pdo = db();
$ok = 0; $ko = 0;

function cas(string $lib, bool $reussi, string $detail = ''): void
{
    global $ok, $ko;
    $reussi ? $ok++ : $ko++;
    echo ($reussi ? '  OK  ' : ' ECHEC') . ' ' . $lib . ($detail !== '' ? '  [' . mb_substr($detail, 0, 95) . ']' : '') . PHP_EOL;
}

function refuse_avec(string $lib, array $resultat, string $attendu = ''): void
{
    $refuse = empty($resultat['success']);
    cas($lib, $refuse && ($attendu === '' || str_contains(mb_strtolower($resultat['error'] ?? ''), mb_strtolower($attendu))),
        $resultat['error'] ?? 'accepte');
}

function endosser(string $role): void
{
    $_SESSION['role_projet'] = $role;
    $_SESSION['est_mandataire'] = false;
}

/**
 * Annexe D - listes de pieces par type de dossier, transcrites telles qu'elles sont
 * imprimees : le code de la case dans l'outil, et le libelle du guide en regard.
 * L'ordre du tableau est l'ordre attendu.
 */
const ANNEXE_D = [
    'achat_bien' => ['nb' => 8, 'pieces' => [
        'fiche_imputation'  => 'Fiche d\'imputation',
        'proforma'          => 'proforma',
        'bon_commande'      => 'bon de commande',
        'facture'           => 'facture',
        'bon_decaissement'  => 'bon de décaissement',
        'recu_beneficiaire' => 'reçu signé du fournisseur',
        'bon_reception'     => 'bon de réception',
        'preuve_paiement'   => 'preuve de paiement',
    ]],
    'service_compagnie' => ['nb' => 8, 'pieces' => [
        'fiche_imputation'  => 'Fiche d\'imputation',
        'proforma'          => 'deux ou trois proformas',
        'bon_commande'      => 'bon de commande',
        'contrat_services'  => 'contrat de services avec patente à jour',
        'facture'           => 'facture',
        'bon_decaissement'  => 'bon de décaissement',
        'recu_beneficiaire' => 'reçu signé',
        'preuve_paiement'   => 'preuve de paiement',
    ]],
    'service_particulier' => ['nb' => 10, 'pieces' => [
        'fiche_imputation'       => 'Fiche d\'imputation',
        'contrat_service'        => 'contrat de service',
        'piece_identite'         => 'pièce d\'identité',
        'facture_prestataire'    => 'facture du prestataire',
        'rapport_execution'      => 'rapport d\'exécution',
        'certificat_acceptation' => 'certificat d\'acceptation',
        'bon_decaissement'       => 'bon de décaissement',
        'recu_beneficiaire'      => 'reçu signé',
        'preuve_paiement'        => 'preuve de paiement',
        'recu_dgi'               => 'reçu de la DGI',
    ]],
    'frais_voyage' => ['nb' => 7, 'pieces' => [
        'fiche_imputation'  => 'Fiche d\'imputation',
        'ordre_mission'     => 'ordre de mission',
        'fiche_calcul'      => 'fiche de calcul des frais',
        'piece_identite'    => 'pièce d\'identité',
        'bon_decaissement'  => 'bon de décaissement',
        'recu_beneficiaire' => 'reçu',
        'preuve_paiement'   => 'preuve de paiement',
    ]],
    'versement_dgi' => ['nb' => 5, 'pieces' => [
        'fiche_imputation_memoire' => 'Fiche d\'imputation pour mémoire',
        'bordereau_decaissement'   => 'bordereau de décaissement',
        'ordre_paiement_dgi'       => 'ordre de paiement de la DGI',
        'etat_recap_acomptes'      => 'état récapitulatif des acomptes retenus',
        'recu_scelle_dgi'          => 'reçu scellé de la DGI',
    ]],
    'remboursement_frais' => ['nb' => 8, 'pieces' => [
        'fiche_imputation'         => 'Fiche d\'imputation',
        'justificatif_fournisseur' => 'justificatif du fournisseur',
        'preuve_debit'             => 'preuve du débit',
        'releve_taux'              => 'relevé portant le taux',
        'note_remboursement'       => 'note de remboursement',
        'bon_decaissement'         => 'bon de décaissement',
        'recu_beneficiaire'        => 'reçu signé',
        'preuve_paiement'          => 'preuve de virement',
    ]],
    'petite_caisse' => ['nb' => 4, 'pieces' => [
        'fiche_imputation'   => 'Fiche d\'imputation',
        'justificatif_achat' => 'justificatif d\'achat',
        'recu_beneficiaire'  => 'reçu',
        'mention_journal'    => 'mention au journal de caisse',
    ]],
];

/**
 * Les deux ecarts assumes entre la checklist de l'outil et le tableau du guide.
 * Ils sont affiches a chaque passage, avec leur raison : un ecart assume ne doit
 * jamais pouvoir se confondre avec un ecart oublie.
 */
const ANNEXE_D_LECTURES = [
    'mention_ttc' => 'Une case supplémentaire, « aucune mention hors taxe », sur les quatre types qui '
        . 'appellent une facture commerciale. Le 4.2 la demande explicitement : « la fiche de contrôle '
        . 'documentaire comporte une case attestant que la pièce ne porte aucune mention hors taxe, et '
        . 'cette attestation conditionne la clôture du dossier ». Elle s\'ajoute au guide, elle n\'en '
        . 'retire rien.',
    'ordre_achat_bien' => 'Sur l\'achat de bien, la preuve de paiement et le bon de réception sont '
        . 'permutés par rapport au guide. La checklist est ordonnée par moment - ce qui est exigible '
        . 'avant le paiement, puis ce qui le suit - et le bon de réception se reçoit avant de payer. '
        . 'L\'énumération du guide n\'est pas chronologique ; la liste des pièces, elle, est la même.',
];

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

// ---------------------------------------------------------------------
// Mise en place
// ---------------------------------------------------------------------

echo "== Mise en place\n";
if (date_debut() === null) {
    param_set('date_debut_execution', '2026-01-01', 'RECC ancrage posé par la recette du cycle');
    param_oublier();
    generer_periodes();
}
cas('Le calendrier relatif est ancre', date_debut() !== null, (string)date_debut());

$periode = periode_pour_date(date('Y-m-d'));
cas('Le jour du passage tombe dans une periode du projet', $periode !== null,
    $periode === null ? 'aucune période ne couvre le ' . date('Y-m-d')
        : 'mois ' . $periode['numero'] . ' · ' . $periode['date_debut'] . ' → ' . $periode['date_fin']);
if ($periode === null) {
    echo "\nLa recette s'arrête : sans période courante, il n'y a pas de mois à rejouer.\n";
    echo "\n$ok OK, " . ($ko + 1) . " ECHEC\n";
    exit(1);
}
$moisProjet = (int)$periode['numero'];

$tiers = [];
foreach ([
    'fournisseur' => ['fournisseur', 'RECC Fournisseur de matériel'],
    'compagnie'   => ['fournisseur', 'RECC Compagnie de services'],
    'prestataire' => ['personne',    'RECC Prestataire indépendant'],
    'missionnaire'=> ['personne',    'RECC Missionnaire'],
    'avanceur'    => ['personne',    'RECC Avanceur de frais'],
    'caissier'    => ['personne',    'RECC Détenteur de la caisse'],
    'mandataireA' => ['personne',    'RECC Mandataire A'],
    'mandataireB' => ['personne',    'RECC Mandataire B'],
] as $cle => [$type, $nom]) {
    $st = $pdo->prepare('SELECT id FROM tiers WHERE nom = ?');
    $st->execute([$nom]);
    $id = $st->fetchColumn();
    if ($id === false) {
        $pdo->prepare('INSERT INTO tiers (type, nom, est_mandataire) VALUES (?,?,?)')
            ->execute([$type, $nom, str_starts_with($cle, 'mandataire') ? 1 : 0]);
        $id = $pdo->lastInsertId();
    }
    $tiers[$cle] = (int)$id;
}
cas('Les tiers du scenario sont au referentiel', count($tiers) === 8, count($tiers) . ' tiers');

$banque = compte_par_code('BQ');
$caisse = compte_caisse();
cas('Le plan de comptes porte la banque et la petite caisse',
    $banque !== null && $caisse !== null,
    ($banque['code'] ?? '—') . ' · ' . ($caisse['code'] ?? '—'));
if ($banque === null || $caisse === null) {
    echo "\n$ok OK, " . ($ko + 1) . " ECHEC\n";
    exit(1);
}

/** Solde d'un compte, pour mesurer des ecarts plutot que des totaux. */
$solde = fn(int $compteId): float => solde_compte($compteId);
$soldeBanqueAvant = $solde((int)$banque['id']);
$soldeCaisseAvant = $solde((int)$caisse['id']);
$chargesAvant = 0.0;
foreach (balance() as $c) {
    if ($c['type'] === 'charge') {
        $chargesAvant += (float)$c['solde'];
    }
}

// ---------------------------------------------------------------------
// Les outils du scenario
// ---------------------------------------------------------------------

/** Pose les cases d'un moment, le televersement ne se rejouant pas en CLI. */
function poser_pieces(PDO $pdo, int $dossierId, string $moment): void
{
    $pdo->prepare("UPDATE pieces SET statut = 'recue', date_piece = CURDATE()
                    WHERE dossier_id = ? AND moment = ? AND statut = 'attendue'")
        ->execute([$dossierId, $moment]);
}

/** La suite des etats qu'un dossier a traverses, lue au journal d'audit. */
function etats_traverses(PDO $pdo, int $dossierId): array
{
    $st = $pdo->prepare("SELECT action FROM journal_audit
                          WHERE objet_type = 'dossier' AND objet_id = ? ORDER BY id");
    $st->execute([$dossierId]);
    return array_column($st->fetchAll(), 'action');
}

$reglementsExecutes = ['banque' => 0.0, 'caisse' => 0.0];

/**
 * Mene un dossier deja ouvert et impute jusqu'a sa cloture : pieces prealables,
 * concurrence si le seuil l'exige, approbation, reglement a deux mandataires,
 * execution, pieces posterieures, cloture.
 *
 * @return array{ok: bool, motif: string, reglement: float, compte: string}
 */
function mener_a_cloture(PDO $pdo, int $dossierId, array $tiers, array $compte, float $montantRegle,
                         array &$cumul): array
{
    $d = dossier($dossierId);
    if ($d === null) {
        return ['ok' => false, 'motif' => 'dossier introuvable', 'reglement' => 0.0, 'compte' => ''];
    }
    $imputation = imputation_dossier($dossierId);

    endosser('raf');
    poser_pieces($pdo, $dossierId, 'avant');
    // Les deux cases qui font avancer l'etat se constatent, elles ne se declarent pas.
    dossier_avancer_sur_piece($dossierId, 'bon_commande');
    dossier_avancer_sur_piece($dossierId, 'bon_reception');

    // « Au-dessus du seuil, trois proformas sont exiges » (CDC 4.3). Le mois rejoue
    // en porte, comme un vrai mois.
    if ($imputation !== null
        && concurrence_requise((float)$imputation['montant'], (string)$d['type'])
        && proformas_dossier($dossierId) === []) {
        foreach ([$tiers['fournisseur'], $tiers['compagnie'], $tiers['prestataire']] as $i => $t) {
            proforma_ajouter($dossierId, $t, round((float)$imputation['montant'] * (1 + $i * 0.05), 2));
        }
        $offres = proformas_dossier($dossierId);
        proforma_retenir((int)$offres[0]['id'], 'Offre la moins-disante');
    }

    endosser('coordinateur');
    $res = dossier_approuver($dossierId);
    if (empty($res['success'])) {
        return ['ok' => false, 'motif' => 'approbation : ' . $res['error'], 'reglement' => 0.0, 'compte' => ''];
    }

    $res = dossier_demander_reglement($dossierId, [
        'compte_id' => (int)$compte['id'],
        'mode'      => $compte['type'] === 'caisse' ? 'especes_caisse' : 'virement',
        'montant'   => $montantRegle,
    ]);
    if (empty($res['success'])) {
        return ['ok' => false, 'motif' => 'demande de règlement : ' . $res['error'], 'reglement' => 0.0, 'compte' => ''];
    }
    $regId = (int)$res['id'];
    reglement_valider($regId, $tiers['mandataireA'], 'signature_bancaire');
    reglement_valider($regId, $tiers['mandataireB'], 'signature_bancaire');
    $res = reglement_executer($regId, date('Y-m-d'));
    if (empty($res['success'])) {
        return ['ok' => false, 'motif' => 'exécution du règlement : ' . $res['error'], 'reglement' => 0.0, 'compte' => ''];
    }
    $cumul[$compte['type'] === 'caisse' ? 'caisse' : 'banque'] += $montantRegle;

    dossier_constater_reglement($dossierId);
    poser_pieces($pdo, $dossierId, 'apres');
    // La mention hors taxe est une attestation, non un scan : elle se leve.
    foreach (pieces_dossier($dossierId) as $p) {
        if (in_array($p['type'], PIECES_ATTESTEES, true) && $p['statut'] !== 'recue') {
            piece_attester((int)$p['id'], 'Vérifié sur pièces par la recette du cycle');
        }
    }
    $res = dossier_clore($dossierId);
    if (empty($res['success'])) {
        return ['ok' => false, 'motif' => 'clôture : ' . $res['error'], 'reglement' => $montantRegle,
                'compte' => $compte['type']];
    }
    return ['ok' => true, 'motif' => '', 'reglement' => $montantRegle, 'compte' => $compte['type']];
}

// ---------------------------------------------------------------------
// 1. Chaque type produit exactement les pieces du guide, dans l'ordre
// ---------------------------------------------------------------------

echo "\n== Les pieces de l'annexe D, type par type\n";
foreach (ANNEXE_D_LECTURES as $cle => $raison) {
    cas('Écart assumé sur « ' . $cle . ' »', true);
    echo '       ' . wordwrap($raison, 96, "\n       ", false) . PHP_EOL;
}

/** Les dossiers ouverts par la recette, dans l'ordre du guide. */
$dossiers = [];

endosser('raf');

// Cinq types s'ouvrent directement. Les deux autres naissent de Remuneration et
// sont menes plus bas, par leur vraie porte.
$ouvertures = [
    'achat_bien'          => ['tiers' => 'fournisseur',  'objet' => 'RECC achat de matériel de bureau',
                              'ligne' => '3.1', 'qte' => 1.0, 'pu' => 4000.0,  'unite' => 'mois',    'compte' => 'BQ'],
    'service_compagnie'   => ['tiers' => 'compagnie',    'objet' => 'RECC entretien du local',
                              'ligne' => '3.2', 'qte' => 1.0, 'pu' => 6000.0,  'unite' => 'mois',    'compte' => 'BQ'],
    'frais_voyage'        => ['tiers' => 'missionnaire', 'objet' => 'RECC mission de coordination au Cap',
                              'ligne' => '3.3', 'qte' => 1.0, 'pu' => 5000.0,  'unite' => 'forfait', 'compte' => 'BQ'],
    'remboursement_frais' => ['tiers' => 'avanceur',     'objet' => 'RECC frais avancés sur achat en ligne',
                              'ligne' => '4.4', 'qte' => 1.0, 'pu' => 3500.0,  'unite' => 'forfait', 'compte' => 'BQ'],
    'petite_caisse'       => ['tiers' => 'fournisseur',  'objet' => 'RECC achat de consommables au comptant',
                              'ligne' => '5.2', 'qte' => 2.0, 'pu' => 600.0,   'unite' => 'personne','compte' => 'CA'],
];

foreach ($ouvertures as $type => $def) {
    $ligne = budget_ligne($def['ligne']);
    $montant = round($def['qte'] * $def['pu'], 2);
    $res = dossier_ouvrir(['type' => $type, 'tiers_id' => $tiers[$def['tiers']],
                           'objet' => $def['objet'], 'montant_prevu' => $montant]);
    if (empty($res['success']) || $ligne === null) {
        cas('Un dossier « ' . TYPES_DOSSIER[$type]['libelle'] . ' » s\'ouvre', false,
            $res['error'] ?? ('ligne ' . $def['ligne'] . ' absente du budget'));
        continue;
    }
    $imp = dossier_imputer((int)$res['id'], (int)$ligne['id'], $def['qte'], $def['pu'], $def['unite']);
    cas('Un dossier « ' . TYPES_DOSSIER[$type]['libelle'] . ' » s\'ouvre et s\'impute',
        !empty($imp['success']), $imp['error'] ?? ($res['numero'] . ' · ' . htg($montant) . ' sur ' . $def['ligne']));
    $dossiers[$type] = ['id' => (int)$res['id'], 'montant' => $montant,
                        'compte' => $def['compte'] === 'CA' ? $caisse : $banque];
}

// Le type desactive n'ouvre rien : l'annexe D ne lui donne aucune piece.
refuse_avec('Le type désactivé n\'ouvre aucun dossier',
    dossier_ouvrir(['type' => 'contrat_travail', 'tiers_id' => $tiers['fournisseur'],
                    'objet' => 'RECC contrat de travail']), 'désactivé');

// ---------------------------------------------------------------------
// Honoraires et versement a la DGI : les deux types qui naissent ailleurs
// ---------------------------------------------------------------------

echo "\n== Les deux types qui naissent de Remuneration\n";
$ligneRh = budget_ligne('AE1.3');
$contrat = null;
if ($ligneRh !== null) {
    $pdo->prepare("INSERT INTO contrats (projet_id, tiers_id, ligne_id, type, fonction, date_debut, date_fin,
                                         unite, quantite, montant_unitaire, montant_total, taux_acompte_defaut, created_by)
                   VALUES (1,?,?,'service','RECC développeur mobile',?,?,'mois',1,110000,110000,2,1)")
         ->execute([$tiers['prestataire'], (int)$ligneRh['id'], $periode['date_debut'], $periode['date_fin']]);
    $contrat = (int)$pdo->lastInsertId();
}
cas('Un contrat de service porte la ligne de rémunération', $contrat !== null,
    $contrat === null ? 'ligne AE1.3 absente du budget' : 'contrat ' . $contrat);

if ($contrat !== null) {
    endosser('raf');
    $f = enregistrer_contenu('RECC rapport ' . $contrat . '-' . $moisProjet . '-' . random_int(1, 999999),
        'pdf', 'application/pdf', 'scans', 'RECC-RAPPORT-' . $contrat . '.pdf');
    $pdo->prepare('INSERT INTO rapports_execution (projet_id, contrat_id, mois, date_remise, date_versement, fichier_id, autorite)
                   VALUES (?,?,?,CURDATE(),CURDATE(),?,?)')
        ->execute([projet_id(), $contrat, $moisProjet, (int)$f['id'], 'coordinateur']);
    $rapportExec = (int)$pdo->lastInsertId();

    endosser('coordinateur');
    $res = rapport_accepter($rapportExec);
    cas('Le certificat d\'acceptation est délivré', !empty($res['success']), $res['error'] ?? '');

    endosser('raf');
    $res = prestation_calculer($rapportExec, 1.0);
    cas('La prestation retient son acompte fiscal',
        !empty($res['success']) && abs(($res['acompte'] ?? 0) - 2200.0) < 0.01,
        $res['error'] ?? ('brut ' . htg($res['brut'] ?? 0) . ' · acompte ' . htg($res['acompte'] ?? 0)
                          . ' · net ' . htg($res['net'] ?? 0)));
    $prestationId = (int)($res['id'] ?? 0);
    $netHonoraires = round((float)($res['net'] ?? 0), 2);

    if ($prestationId > 0) {
        $res = prestation_ouvrir_dossier($prestationId);
        cas('La prestation ouvre son dossier de service à particulier',
            !empty($res['success']), $res['error'] ?? ($res['numero'] ?? ''));
        if (!empty($res['success'])) {
            $dossiers['service_particulier'] = ['id' => (int)$res['dossier_id'],
                                                'montant' => $netHonoraires, 'compte' => $banque];
        }

        $res = versement_dgi_preparer($moisProjet);
        cas('Le versement à la DGI se prépare sur l\'acompte du mois',
            !empty($res['success']) && abs(($res['montant'] ?? 0) - 2200.0) < 0.01,
            $res['error'] ?? htg($res['montant'] ?? 0));
        if (!empty($res['success'])) {
            $versementId = (int)$res['id'];
            $dossiers['versement_dgi'] = ['id' => (int)$res['dossier_id'],
                                          'montant' => round((float)$res['montant'], 2), 'compte' => $banque];
        }
    }
}

// ---------------------------------------------------------------------
// La comparaison au guide, une fois les sept dossiers ouverts
// ---------------------------------------------------------------------

echo "\n== Chaque type produit exactement les pieces du guide\n";
foreach (ANNEXE_D as $type => $guide) {
    if (!isset($dossiers[$type])) {
        cas('« ' . TYPES_DOSSIER[$type]['libelle'] .' » : liste comparée au guide', false, 'dossier non ouvert');
        continue;
    }
    $posees = array_column(pieces_dossier($dossiers[$type]['id']), 'type');
    // La case d'attestation hors taxe est l'ecart assume : on la met de cote pour
    // comparer ce qui doit l'etre, et on verifie a part qu'elle est bien la.
    $sansAttestation = array_values(array_filter($posees, fn($t) => !in_array($t, PIECES_ATTESTEES, true)));
    $attendues = array_keys($guide['pieces']);

    $memeListe = $sansAttestation === $attendues;
    cas('« ' . TYPES_DOSSIER[$type]['libelle'] . ' » : ' . $guide['nb'] . ' pièces du guide, dans l\'ordre',
        $memeListe && count($attendues) === $guide['nb'],
        $memeListe ? implode(' → ', $attendues)
            : 'attendu ' . implode(', ', $attendues) . ' / posé ' . implode(', ', $sansAttestation));

    $porteAttestation = array_intersect(PIECES_ATTESTEES, $posees) !== [];
    $doitPorter = in_array($type, ['achat_bien', 'service_compagnie', 'frais_voyage', 'remboursement_frais'], true);
    cas('  et la case « aucune mention hors taxe » ' . ($doitPorter ? 'y figure' : 'n\'y figure pas'),
        $porteAttestation === $doitPorter, $porteAttestation ? 'présente' : 'absente');
}

// ---------------------------------------------------------------------
// 2. Chaque type mene de sa creation a sa cloture
// ---------------------------------------------------------------------

echo "\n== De la creation a la cloture, type par type\n";

// La petite caisse doit d'abord etre approvisionnee : elle ne regle qu'en especes,
// et on ne sort pas d'especes d'une caisse vide. Mais on ne renfloue pas non plus
// sans avoir arrete la caisse : « le renflouement suppose la justification des
// depenses anterieures » (CDC 4.7). L'arrete precede donc le cheque.
endosser('raf');
$soldeTheorique = solde_compte((int)$caisse['id']);
$arr = arrete_caisse_creer((int)$caisse['id'], date('Y-m-d'), $soldeTheorique, $tiers['caissier'],
    'RECC arrêté préalable au renflouement');
cas('La caisse est arrêtée avant d\'être renflouée', !empty($arr['success']),
    $arr['error'] ?? ('solde constaté ' . htg($soldeTheorique)));
// Le fonds fixe est plafonne quand le projet l'a parametre : on ne renfloue pas
// au-dela, la recette n'ayant pas a forcer une regle qu'elle est censee respecter.
$plafondCaisse = param('plafond_petite_caisse');
$aRenflouer = $plafondCaisse === null ? 20000.0
    : max(0.0, min(20000.0, round((float)$plafondCaisse - $soldeTheorique, 2)));
$renf = $aRenflouer <= 0 ? ['success' => false, 'error' => 'fonds fixe déjà au plafond']
                         : caisse_renflouer((int)$caisse['id'], $aRenflouer, $tiers['caissier'], '000RECC');
if (!empty($renf['success'])) {
    reglement_valider((int)$renf['id'], $tiers['mandataireA'], 'signature_bancaire');
    reglement_valider((int)$renf['id'], $tiers['mandataireB'], 'signature_bancaire');
    $exec = reglement_executer((int)$renf['id'], date('Y-m-d'));
    cas('La petite caisse est approvisionnée par chèque nominatif',
        !empty($exec['success']), $exec['error'] ?? htg($aRenflouer));
    if (!empty($exec['success'])) {
        $reglementsExecutes['banque'] += $aRenflouer;
    }
} else {
    cas('La petite caisse est approvisionnée par chèque nominatif', false, $renf['error'] ?? '');
}

foreach (ANNEXE_D as $type => $guide) {
    if (!isset($dossiers[$type]) || $type === 'versement_dgi') {
        continue;   // le versement a la DGI se regle autrement : voir plus bas
    }
    $d = $dossiers[$type];
    $res = mener_a_cloture($pdo, $d['id'], $tiers, $d['compte'], $d['montant'], $reglementsExecutes);
    cas('« ' . TYPES_DOSSIER[$type]['libelle'] . ' » mené de sa création à sa clôture',
        $res['ok'], $res['ok'] ? dossier($d['id'])['numero'] . ' · ' . htg($d['montant']) : $res['motif']);
    if ($res['ok']) {
        $etats = etats_traverses($pdo, $d['id']);
        cas('  il a traversé ouverture, approbation, règlement et clôture',
            in_array('dossier_ouvert', $etats, true) && in_array('dossier_approuve', $etats, true)
            && in_array('reglement_demande', $etats, true) && in_array('dossier_clos', $etats, true),
            implode(' → ', array_values(array_unique(array_filter($etats,
                fn($a) => in_array($a, ['dossier_ouvert', 'dossier_approuve', 'reglement_demande', 'dossier_clos'], true))))));
        $l31 = budget_ligne('3.1');
        cas('  et il ne se réimpute plus une fois clos',
            $l31 !== null && empty(dossier_imputer($d['id'], (int)$l31['id'], 1, 100, 'mois')['success']));
    }
}

// ---------------------------------------------------------------------
// Le versement a la DGI : le seul dossier qui ne se regle pas depuis lui-meme
// ---------------------------------------------------------------------

echo "\n== Le versement a la DGI, de sa preparation a sa cloture\n";
if (!isset($dossiers['versement_dgi']) || !isset($versementId)) {
    cas('« Versement de taxes à la DGI » mené de sa création à sa clôture', false,
        'le versement n\'a pas été préparé');
} else {
    $dgi = $dossiers['versement_dgi'];
    $dgiTiers = (int)$pdo->query("SELECT id FROM tiers WHERE type = 'administration' AND sigle = 'DGI' LIMIT 1")
                         ->fetchColumn();
    endosser('raf');
    poser_pieces($pdo, $dgi['id'], 'avant');

    endosser('coordinateur');
    $res = dossier_approuver($dgi['id']);
    cas('Le dossier de versement s\'approuve', !empty($res['success']), $res['error'] ?? '');

    endosser('raf');
    // Son imputation est pour memoire, a zero : rien ne peut se payer depuis le
    // dossier, et c'est juste - un versement a la DGI ne consomme aucune ligne.
    refuse_avec('Le règlement ne se demande pas depuis le dossier, dont l\'imputation est à zéro',
        dossier_demander_reglement($dgi['id'], ['compte_id' => (int)$banque['id']]), 'strictement positif');
    $r = reglement_creer([
        'mode'            => 'virement',
        'beneficiaire_id' => $dgiTiers,
        'compte_id'       => (int)$banque['id'],
        'montant'         => $dgi['montant'],
        'objet'           => 'Acomptes retenus du mois ' . $moisProjet,
        'origine_module'  => 'remuneration',
        'origine_ref'     => 'versement_dgi:' . $versementId,
    ]);
    cas('Le règlement du versement se saisit sur l\'origine « versement_dgi »',
        !empty($r['success']), $r['error'] ?? ($r['numero'] ?? ''));

    if (!empty($r['success'])) {
        $detteAvant = dette_dgi_soldee($moisProjet);
        cas('Tant que le versement n\'est pas réglé, la dette du mois bloque la clôture',
            $detteAvant['soldee'] === false, $detteAvant['motif'] ?? '');

        reglement_valider((int)$r['id'], $tiers['mandataireA'], 'signature_bancaire');
        reglement_valider((int)$r['id'], $tiers['mandataireB'], 'signature_bancaire');
        $exec = reglement_executer((int)$r['id'], date('Y-m-d'));
        cas('Le règlement du versement s\'exécute', !empty($exec['success']), $exec['error'] ?? '');
        if (!empty($exec['success'])) {
            $reglementsExecutes['banque'] += $dgi['montant'];
        }

        versement_dgi_constater($versementId);
        cas('Le dossier de versement passe à l\'état réglé',
            (dossier($dgi['id'])['statut'] ?? '') === 'regle', dossier($dgi['id'])['statut'] ?? '');
        $detteApres = dette_dgi_soldee($moisProjet);
        cas('La dette fiscale du mois est soldée', $detteApres['soldee'] === true,
            $detteApres['motif'] ?? 'soldée');

        $res = dossier_clore($dgi['id']);
        cas('Sans le reçu scellé, le dossier ne se clôt pas', empty($res['success']),
            $res['error'] ?? 'accepté');
        poser_pieces($pdo, $dgi['id'], 'apres');
        $res = dossier_clore($dgi['id']);
        cas('« Versement de taxes à la DGI » mené de sa création à sa clôture',
            !empty($res['success']), $res['error'] ?? dossier($dgi['id'])['numero']);
    }
}

// ---------------------------------------------------------------------
// 3. Rejouer un mois reel : la douzaine de dossiers
// ---------------------------------------------------------------------

echo "\n== Le reste du mois : jusqu'a la douzaine\n";
endosser('raf');
$complement = [
    ['achat_bien',        'fournisseur',  'RECC achat de téléphones de test', '2.1',   1.0, 15000.0, 'unite',    'BQ'],
    ['service_compagnie', 'compagnie',    'RECC atelier de lancement',        '4.2',   1.0, 20000.0, 'forfait',  'BQ'],
    ['frais_voyage',      'missionnaire', 'RECC déplacement de suivi',        '5.1',   1.0, 12000.0, 'jour',     'BQ'],
    ['petite_caisse',     'fournisseur',  'RECC petites fournitures',         '5.2',   1.0, 800.0,   'personne', 'CA'],
    ['achat_bien',        'fournisseur',  'RECC matériels de formation',      'AE2.2', 1.0, 9000.0,  'forfait',  'BQ'],
];
foreach ($complement as $i => [$type, $cleTiers, $objet, $code, $qte, $pu, $unite, $compte]) {
    endosser('raf');   // mener_a_cloture() a rendu la main en Coordinateur
    $ligne = budget_ligne($code);
    $montant = round($qte * $pu, 2);
    $res = $ligne === null ? ['success' => false, 'error' => 'ligne ' . $code . ' absente']
                           : dossier_ouvrir(['type' => $type, 'tiers_id' => $tiers[$cleTiers],
                                             'objet' => $objet, 'montant_prevu' => $montant]);
    if (empty($res['success'])) {
        cas('Dossier complémentaire ' . ($i + 1) . ' · ' . $objet, false, $res['error']);
        continue;
    }
    $imp = dossier_imputer((int)$res['id'], (int)$ligne['id'], $qte, $pu, $unite);
    if (empty($imp['success'])) {
        cas('Dossier complémentaire ' . ($i + 1) . ' · ' . $objet, false, $imp['error']);
        continue;
    }
    $mene = mener_a_cloture($pdo, (int)$res['id'], $tiers, $compte === 'CA' ? $caisse : $banque,
                            $montant, $reglementsExecutes);
    cas('Dossier complémentaire ' . ($i + 1) . ' · ' . $objet, $mene['ok'],
        $mene['ok'] ? $res['numero'] . ' · ' . htg($montant) : $mene['motif']);
}

$st = $pdo->prepare("SELECT COUNT(*) FROM dossiers WHERE projet_id = ? AND periode_id = ?");
$st->execute([projet_id(), (int)$periode['id']]);
$nbDossiers = (int)$st->fetchColumn();
cas('Le mois porte une douzaine de dossiers de types différents', $nbDossiers >= 12,
    $nbDossiers . ' dossier(s) · ' . count(array_unique(array_column(
        $pdo->query("SELECT type FROM dossiers WHERE projet_id = " . (int)projet_id()
                  . " AND periode_id = " . (int)$periode['id'])->fetchAll(), 'type'))) . ' type(s)');

// ---------------------------------------------------------------------
// 4. Concordance : journal, rapport financier, balance, rapprochement
// ---------------------------------------------------------------------

echo "\n== Concordance du mois\n";
$debut = (string)$periode['date_debut'];
$fin   = (string)$periode['date_fin'];

// (a) Le journal des depenses, au formulaire de suivi du PAIESC.
$vent = ventilation($debut, $fin);
$totalJournal = 0.0;
foreach (array_merge($vent['banque'], $vent['caisse']) as $l) {
    $totalJournal += (float)$l['montant'];
}
$totalJournal = round($totalJournal, 2);
cas('Le journal des dépenses sépare la banque de la petite caisse',
    $vent['caisse'] !== [], count($vent['banque']) . ' en banque · ' . count($vent['caisse']) . ' en caisse');

// (b) La meme somme, lue sur les imputations : le journal n'oublie rien.
$st = $pdo->prepare("SELECT COALESCE(SUM(i.montant), 0) FROM imputations i
                      WHERE i.projet_id = ? AND i.nature = 'consommation'
                        AND i.date_imputation BETWEEN ? AND ?");
$st->execute([projet_id(), $debut, $fin]);
$totalImpute = round((float)$st->fetchColumn(), 2);
cas('Le journal porte exactement les imputations de consommation du mois',
    abs($totalJournal - $totalImpute) < 0.01,
    htg($totalJournal) . ' au journal · ' . htg($totalImpute) . ' aux imputations');

// (c) Le rapport financier au modele de l'annexe G.
endosser('coordinateur');
$res = rapport_ouvrir('mensuel', (int)$periode['id'], 'RECC mois rejoué');
cas('Le rapport financier du mois s\'ouvre', !empty($res['success']), $res['error'] ?? '');
$totalRapport = 0.0;
if (!empty($res['success'])) {
    foreach (lignes_financieres((int)$res['id']) as $lf) {
        $totalRapport += (float)$lf['periode_total'];
    }
    $totalRapport = round($totalRapport, 2);
}
cas('Le rapport financier porte le même total que le journal',
    abs($totalRapport - $totalJournal) < 0.01,
    htg($totalRapport) . ' au rapport · ' . htg($totalJournal) . ' au journal');

// (d) La balance : partie double, et les charges au montant du journal.
$bal = balance();
$debits = 0.0; $credits = 0.0; $chargesApres = 0.0;
foreach ($bal as $c) {
    $debits  += (float)$c['debit'];
    $credits += (float)$c['credit'];
    if ($c['type'] === 'charge') {
        $chargesApres += (float)$c['solde'];
    }
}
cas('La balance est équilibrée, débits contre crédits',
    abs(round($debits - $credits, 2)) < 0.01, htg($debits) . ' / ' . htg($credits));
cas('Les comptes de charge ont augmenté du montant du journal',
    abs(round($chargesApres - $chargesAvant, 2) - $totalJournal) < 0.01,
    htg(round($chargesApres - $chargesAvant, 2)) . ' de charges · ' . htg($totalJournal) . ' au journal');

// (e) La tresorerie : chaque compte a bouge de ce qu'il a regle.
$deltaBanque = round($solde((int)$banque['id']) - $soldeBanqueAvant, 2);
$deltaCaisse = round($solde((int)$caisse['id']) - $soldeCaisseAvant, 2);
// Le renflouement sort de la banque et entre en caisse : il compte deux fois.
cas('La banque a diminué de ce qu\'elle a réglé',
    abs($deltaBanque + round($reglementsExecutes['banque'], 2)) < 0.01,
    htg($deltaBanque) . ' de variation · ' . htg(-$reglementsExecutes['banque']) . ' attendu');
cas('La caisse porte son approvisionnement moins ses dépenses',
    abs($deltaCaisse - round($aRenflouer - $reglementsExecutes['caisse'], 2)) < 0.01,
    htg($deltaCaisse) . ' de variation · ' . htg($aRenflouer - $reglementsExecutes['caisse']) . ' attendu');

// (f) Le rapprochement bancaire, oppose au releve.
$dateReleve = fin_de_mois($fin);
$compteBancaireId = (int)$banque['compte_bancaire_id'];
$etat = rapprochement_consolide($compteBancaireId, $dateReleve, 0.0);
$soldeReconstitue = (float)$etat['reconstitue'];
$etatJuste = rapprochement_consolide($compteBancaireId, $dateReleve, $soldeReconstitue);
cas('Un relevé conforme au reconstitué ne laisse aucun écart',
    abs((float)$etatJuste['ecart']) < 0.01,
    'reconstitué ' . htg($soldeReconstitue) . ' · écart ' . htg((float)$etatJuste['ecart']));
$etatFaux = rapprochement_consolide($compteBancaireId, $dateReleve, $soldeReconstitue + 1500.0);
cas('Un relevé qui s\'écarte le fait voir',
    abs((float)$etatFaux['ecart'] - 1500.0) < 0.01, htg((float)$etatFaux['ecart']));
cas('La part de chaque projet est ventilée, celle du projet courant comprise',
    isset($etat['par_projet'][projet_id()]),
    implode(' · ', array_map(fn($p) => $p['code'] . ' ' . htg($p['solde']), $etat['par_projet'])));

// La banque du projet est un compte de tresorerie du plan : son solde reconstitue
// pour ce projet est celui de la balance.
cas('Le reconstitué du projet est le solde de son compte en banque',
    isset($etat['par_projet'][projet_id()])
    && abs((float)$etat['par_projet'][projet_id()]['solde'] - $solde((int)$banque['id'])) < 0.01,
    htg((float)($etat['par_projet'][projet_id()]['solde'] ?? 0)) . ' / ' . htg($solde((int)$banque['id'])));

endosser('coordinateur');
echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
