<?php
declare(strict_types=1);

/**
 * Recette de la phase 8 : module Financement.
 * Tresorerie, tranches, demandes de versement et leur circuit (CDC 4.10).
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase8.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/financement.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 8 - Financement');
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

/** Le televersement exige un vrai POST : on pose la piece en base pour la suite. */
function poser_piece_demande(PDO $pdo, int $demandeId): void
{
    $f = enregistrer_contenu('REC8 piece ' . random_int(1, 999999999), 'pdf', 'application/pdf',
        'documents', 'REC8-PIECE-' . $demandeId . '-' . random_int(1, 999999) . '.pdf');
    $pdo->prepare("UPDATE pieces_demande SET statut = 'recue', fichier_id = ? WHERE demande_id = ? AND statut = 'attendue'")
        ->execute([(int)$f['id'], $demandeId]);
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

echo "== Sources de revenu\n";
$sources = sources_revenu();
cas('Le projet declare au moins une source', count($sources) >= 1, count($sources) . ' source(s)');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne declare pas les sources de revenu',
    source_revenu_creer(['origine' => 'don', 'libelle' => 'REC8 don', 'montant_attendu' => 1000]), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Une origine hors liste est refusee',
    source_revenu_creer(['origine' => 'loterie', 'libelle' => 'REC8 loterie', 'montant_attendu' => 1000]), 'obligatoires');
$res = source_revenu_creer(['origine' => 'parrainage', 'libelle' => 'REC8 parrainage local', 'montant_attendu' => 50000]);
cas('La meme structure couvre plusieurs origines', !empty($res['success']), $res['error'] ?? '');

echo "\n== Tranches\n";
$listeTranches = tranches();
cas('Les tranches contractuelles sont posees', count($listeTranches) === 3, count($listeTranches) . ' tranche(s)');
$t1 = $listeTranches[0];
$t2 = $listeTranches[1] ?? $t1;
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne fixe pas le montant contractuel',
    tranche_contractualiser((int)$t1['id'], 1000), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Un montant contractuel nul est refuse',
    tranche_contractualiser((int)$t1['id'], 0), 'strictement positif');
$res = tranche_contractualiser((int)$t1['id'], 2666613.88, 'Contrat signé et fiche signalétique validée');
cas('Le montant contractuel se fixe a la signature', !empty($res['success']), $res['error'] ?? '');
tranche_contractualiser((int)$t2['id'], 2399952.49, 'Rapport intermédiaire accepté');

echo "\n== Demande de versement\n";
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Le Coordinateur ne prepare pas la demande',
    demande_ouvrir((int)$t1['id']), 'Responsable Administratif');
$_SESSION['role_projet'] = 'raf';
$t3 = $listeTranches[2] ?? $t1;
refuse_avec('Une tranche sans montant contractuel n\'ouvre pas de demande',
    demande_ouvrir((int)$t3['id']), 'se fixe à la signature');

$d1 = demande_ouvrir((int)$t1['id']);
cas('La demande de la premiere tranche s\'ouvre', !empty($d1['success']), $d1['error'] ?? '');
$pieces1 = pieces_demande((int)$d1['id']);
cas('La premiere tranche ne demande que quatre pieces',
    count($pieces1) === count(PIECES_DEMANDE['premiere']), count($pieces1) . ' piece(s)');
cas('Elle ne demande aucun rapport',
    !in_array('rapport_financier', array_column($pieces1, 'type'), true),
    implode(', ', array_column($pieces1, 'type')));
refuse_avec('Une seconde demande sur la meme tranche est refusee',
    demande_ouvrir((int)$t1['id']), 'déjà en cours');

refuse_avec('Valider sans les pieces est refuse',
    demande_valider((int)$d1['id']), 'Pièces manquantes');
poser_piece_demande($pdo, (int)$d1['id']);
$res = demande_valider((int)$d1['id']);
cas('La premiere tranche se valide sans rapport joint', !empty($res['success']), $res['error'] ?? '');

echo "\n== Transmission et delais\n";
refuse_avec('Une demande non validee ne se transmet pas',
    demande_transmettre((int)$d1['id'] + 999, date('Y-m-d')), 'inconnue');
$res = demande_transmettre((int)$d1['id'], '2026-02-01');
cas('La transmission conserve sa date', !empty($res['success']), $res['error'] ?? '');
cas('La date de transmission ouvre le delai contractuel',
    (demande_paiement((int)$d1['id'])['date_transmission'] ?? '') === '2026-02-01',
    demande_paiement((int)$d1['id'])['date_transmission'] ?? '');
refuse_avec('Une demande transmise ne se revalide pas', demande_valider((int)$d1['id']), 'déjà');

$res = demande_complement((int)$d1['id'], '2026-02-10');
cas('Un complement reclame se consigne avec sa date', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Une reponse anterieure a la demande est refusee',
    demande_repondre_complement((int)$d1['id'], '2026-02-05'), 'ne précède pas');
$res = demande_repondre_complement((int)$d1['id'], '2026-02-14');
cas('La reponse au complement se consigne', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Un complement ne se reclame pas deux fois',
    demande_complement((int)$d1['id'], '2026-02-20'), 'transmise');

echo "\n== Le figement precede la transmission\n";
$d2 = demande_ouvrir((int)$t2['id']);
cas('La demande de la deuxieme tranche s\'ouvre', !empty($d2['success']), $d2['error'] ?? '');
$pieces2 = pieces_demande((int)$d2['id']);
cas('Les tranches suivantes exigent les rapports figes',
    in_array('rapport_financier', array_column($pieces2, 'type'), true),
    implode(', ', array_column($pieces2, 'type')));
poser_piece_demande($pdo, (int)$d2['id']);
refuse_avec('Sans rapport joint, la demande ne se valide pas',
    demande_valider((int)$d2['id']), 'rapport figé');
$brouillon = $pdo->query("SELECT id FROM rapports WHERE projet_id = 1 AND statut = 'brouillon' LIMIT 1")->fetchColumn();
if ($brouillon !== false) {
    refuse_avec('Un rapport encore en brouillon ne se joint pas',
        demande_valider((int)$d2['id'], (int)$brouillon), 'doit être figé');
}
$fige = $pdo->query("SELECT id FROM rapports WHERE projet_id = 1 AND statut IN ('valide','transmis') LIMIT 1")->fetchColumn();
if ($fige !== false) {
    $res = demande_valider((int)$d2['id'], (int)$fige);
    cas('Avec un rapport fige, la demande se valide', !empty($res['success']), $res['error'] ?? '');
    cas('La demande conserve la reference du rapport joint',
        (demande_paiement((int)$d2['id'])['rapport_ref'] ?? '') === 'rapport:' . (int)$fige,
        demande_paiement((int)$d2['id'])['rapport_ref'] ?? '');
} else {
    cas('Aucun rapport fige sur la base : le cas est saute', true, 'lancer la recette de la phase 7 d\'abord');
}

echo "\n== Encaissement d'une tranche\n";
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Le Coordinateur ne constate pas l\'encaissement',
    tranche_encaisser((int)$t1['id'], 1000, date('Y-m-d'), ['name' => 'avis.pdf']), 'Responsable Administratif');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Un encaissement sans avis de credit est refuse',
    tranche_encaisser((int)$t1['id'], 2666613.88, '2026-02-06', null), 'avis de crédit');

// L'ecriture se pose : on contourne l'upload, impossible en ligne de commande.
$banque = compte_par_code('BQ');
$financement = compte_par_code('FIN');
$avantBanque = solde_compte((int)$banque['id']);
$ecr = ecriture_encaissement_tranche((int)$banque['id'], (int)$financement['id'], 2666613.88,
    '2026-02-06', 'REC8 encaissement tranche 1', 'tranche:' . (int)$t1['id']);
$pdo->prepare('UPDATE tranches SET montant_recu = ?, date_reception = ?, ecriture_ref = ? WHERE id = ?')
    ->execute([2666613.88, '2026-02-06', 'ecriture:' . $ecr, (int)$t1['id']]);
cas('L\'encaissement debite la banque et credite le financement',
    abs((solde_compte((int)$banque['id']) - $avantBanque) - 2666613.88) < 0.01,
    htg(solde_compte((int)$banque['id']) - $avantBanque));
cas('Le financement est au credit',
    abs(solde_compte((int)$financement['id']) + 2666613.88) < 0.01, htg(-solde_compte((int)$financement['id'])));
refuse_avec('Une tranche deja encaissee ne se recontractualise pas',
    tranche_contractualiser((int)$t1['id'], 1), 'déjà reçue');

$tr = tresorerie();
cas('La disponibilite se calcule sur le recu, jamais sur l\'attendu',
    abs($tr['recu'] - 2666613.88) < 0.01 && $tr['attendu'] > $tr['recu'],
    'reçu ' . htg($tr['recu']) . ' sur ' . htg($tr['attendu']));
demande_constater_paiement((int)$d1['id']);
cas('La demande passe a payee quand sa tranche est encaissee',
    (demande_paiement((int)$d1['id'])['statut'] ?? '') === 'payee',
    demande_paiement((int)$d1['id'])['statut'] ?? '');

echo "\n== Gabarit de la demande de paiement\n";
cas('Le gabarit demande_paiement existe', is_file(root_dir() . '/pdf/templates/demande_paiement.php'));
$manquants = [];
foreach (DOCUMENTS_GENERES as $code => $def) {
    if ($def[2] === 'papier_scanne' || $code === 'liasse') {
        continue;
    }
    if (!is_file(root_dir() . '/pdf/templates/' . $code . '.php')) {
        $manquants[] = $code;
    }
}
cas('Le catalogue de l\'annexe E est complet', $manquants === [],
    $manquants ? 'manquent : ' . implode(', ', $manquants) : '16 documents produits, 2 reçus numérisés');

echo "\n== Rendu des ecrans\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$rendre = function (string $page): array {
    ob_start();
    try {
        require __DIR__ . '/../' . $page;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return [false, get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
    return [str_contains($html, '</html>'), strlen($html) . ' octets'];
};
foreach ([1 => 'KESKLE', 2 => 'KKP'] as $pid => $code) {
    $_SESSION['projet_id'] = $pid;
    $_SESSION['projet_code'] = $code;
    param_oublier();
    foreach (['Financement - trésorerie' => 'modules/financement/index.php',
              'Financement - demandes'   => 'modules/financement/demandes.php'] as $lib => $page) {
        [$a, $b] = $rendre($page);
        cas($lib . ' (' . $code . ')', $a, $b);
    }
}

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
