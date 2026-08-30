<?php
declare(strict_types=1);

/**
 * Recette de la phase 7 : module Restitution.
 * Cloture conditionnee, figement, rapport financier au modele de l'annexe G,
 * rectification et solde de cloture.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase7.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restitution.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 7 - Restitution');
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

function doit_echouer(string $lib, callable $f): void
{
    try {
        $f();
        cas($lib, false, 'aucune erreur levee');
    } catch (Throwable $e) {
        cas($lib, true, $e->getMessage());
    }
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

// Restitution ne se teste pas sans calendrier : les periodes derivent de la date
// d'ancrage. On la pose si elle manque, ce que le Coordinateur ferait a
// l'initialisation.
if (date_debut() === null) {
    param_set('date_debut_execution', '2026-01-01', 'Ancrage posé par la recette de la phase 7');
    param_oublier();
    generer_periodes();
}
cas('Le calendrier relatif est ancre', date_debut() !== null, (string)date_debut());
$listePeriodes = periodes();
cas('Les periodes derivent de l\'ancrage', count($listePeriodes) > 0, count($listePeriodes) . ' periode(s)');
if (!$listePeriodes) {
    echo "\n$ok OK, $ko ECHEC\n";
    exit(1);
}
$p1 = $listePeriodes[0];
$p2 = $listePeriodes[1] ?? $p1;

echo "\n== Conditions de cloture\n";
$controles = cloture_controles((int)$p1['id']);
cas('Les trois etapes bloquantes sont evaluees', count($controles['etapes']) === 3,
    implode(' · ', array_column($controles['etapes'], 'nom')));
foreach ($controles['etapes'] as $et) {
    cas('Étape « ' . $et['nom'] . ' » rend son motif', $et['motif'] !== '', mb_substr($et['motif'], 0, 70));
}

echo "\n== Ouverture d'un rapport\n";
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne valide pas les rapports',
    rapport_ouvrir('mensuel', (int)$p1['id'], 'REC7'), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Une version rectificative ne s\'ouvre pas directement',
    rapport_ouvrir('rectificatif', (int)$p1['id'], 'REC7'), 'hors liste');
$r1 = rapport_ouvrir('mensuel', (int)$p1['id'], 'REC7 commentaire d\'analyse du premier mois');
cas('Un rapport mensuel s\'ouvre', !empty($r1['success']), $r1['error'] ?? '');
$rid = (int)$r1['id'];
cas('La periode passe en cloture',
    (rapport_restitution($rid)['periode_statut'] ?? '') === 'en_cloture',
    rapport_restitution($rid)['periode_statut'] ?? '');

echo "\n== Rapport financier au modele de l'annexe G\n";
$lignes = lignes_financieres($rid);
cas('Le rapport porte une ligne par ligne budgetaire',
    count($lignes) === count(budget_lignes()), count($lignes) . ' ligne(s)');
$l11 = null; $l10 = null;
foreach ($lignes as $lf) {
    if ($lf['code'] === '1.1') { $l11 = $lf; }
    if ($lf['code'] === '10')  { $l10 = $lf; }
}
cas('La colonne budget reproduit le contractuel, jamais le budget de gestion',
    $l11 !== null && abs((float)$l11['budget_total'] - 960000) < 0.01,
    $l11 ? htg((float)$l11['budget_total']) : '');
cas('Une ligne non consommee laisse sa valeur unitaire vide',
    $l11 !== null && ($l11['periode_quantite'] === null ? $l11['periode_valeur'] === null : true),
    $l11 && $l11['periode_valeur'] === null ? 'vide' : 'renseignee');
cas('La provision reste a zero en depenses',
    $l10 !== null && abs((float)$l10['periode_total']) < 0.01, $l10 ? htg((float)$l10['periode_total']) : '');
cas('La difference se calcule sur le cumul, pas sur la periode',
    $l11 !== null && abs((float)$l11['difference'] - ((float)$l11['cumul_total'] - (float)$l11['budget_total'])) < 0.01);

echo "\n== Validation : la cloture est conditionnee\n";
$res = rapport_valider($rid);
$controles = cloture_controles((int)$p1['id']);
if ($controles['ok']) {
    cas('Les conditions etant reunies, la validation aboutit', !empty($res['success']), $res['error'] ?? '');
} else {
    cas('Sans les trois conditions, la validation est refusee', empty($res['success']), $res['error'] ?? 'accepte');
    cas('Le refus nomme la condition qui manque',
        str_contains($res['error'] ?? '', 'conditionnée'), mb_substr($res['error'] ?? '', 0, 70));
    // On force le figement pour eprouver ce qui en decoule, sans quoi la moitie
    // de la section ne serait pas atteignable sur une base de recette.
    $pdo->prepare("UPDATE rapports SET statut = 'valide', valide_par = 1 WHERE id = ?")->execute([$rid]);
    $pdo->prepare("UPDATE periodes SET statut = 'figee', figee_le = NOW(), figee_par = 1 WHERE id = ?")
        ->execute([(int)$p1['id']]);
}
cas('La periode est figee', periode_est_figee((int)$p1['id']));

echo "\n== Ce que le figement interdit\n";
// Le dossier s'ouvre par la fonction du module, sans quoi sa checklist n'existe
// pas : c'est elle qui porte la distinction entre pieces avant et apres paiement.
$_SESSION['role_projet'] = 'raf';
$ouv = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => 'REC7 dossier figé']);
$dosFige = (int)($ouv['id'] ?? 0);
$pdo->prepare('UPDATE dossiers SET periode_id = ? WHERE id = ?')->execute([(int)$p1['id'], $dosFige]);
cas('Le dossier de contrôle porte sa checklist',
    count(pieces_dossier($dosFige)) > 0, count(pieces_dossier($dosFige)) . ' case(s)');
$l21 = budget_ligne('2.1');
refuse_avec('Une imputation dans une periode figee est refusee',
    dossier_imputer($dosFige, (int)$l21['id'], 1, 1000, 'unite'), 'figée');
doit_echouer('Une ecriture datee dans une periode figee est refusee', function () use ($p1) {
    $banque = compte_par_code('BQ');
    $tiers = compte_par_code('TI');
    ecriture_poser(['date' => (string)$p1['date_debut'], 'libelle' => 'REC7 ecriture tardive', 'type' => 'autre',
                    'origine_module' => 'recette', 'origine_ref' => 'REC7-1'],
        [['compte_id' => (int)$banque['id'], 'sens' => 'D', 'montant' => 10],
         ['compte_id' => (int)$tiers['id'], 'sens' => 'C', 'montant' => 10]]);
});
$sp = $pdo->prepare("SELECT id, moment FROM pieces WHERE dossier_id = ? AND statut = 'attendue' ORDER BY moment DESC, ordre");
$sp->execute([$dosFige]);
$piecesFige = $sp->fetchAll();
$pieceApres = null; $pieceAvant = null;
foreach ($piecesFige as $pf) {
    if ($pf['moment'] === 'apres' && $pieceApres === null) { $pieceApres = $pf; }
    if ($pf['moment'] === 'avant' && $pieceAvant === null) { $pieceAvant = $pf; }
}
if ($pieceAvant !== null) {
    refuse_avec('Une piece prealable au paiement ne rejoint plus une periode figee',
        piece_verser((int)$pieceAvant['id'], ['name' => 'x.pdf']), 'figée');
}
cas('Les pieces posterieures au paiement peuvent encore la rejoindre',
    $pieceApres !== null, $pieceApres ? 'une piece « apres » reste ouverte' : 'aucune piece apres');

echo "\n== Transmission et rectification\n";
$_SESSION['role_projet'] = 'coordinateur';
$r2 = rapport_ouvrir('mensuel', (int)$p2['id'], 'REC7 deuxieme periode');
if (!empty($r2['success'])) {
    refuse_avec('Un brouillon ne se transmet pas',
        rapport_transmettre((int)$r2['id'], date('Y-m-d')), 'une fois validé');
    refuse_avec('Un brouillon ne se rectifie pas',
        rapport_rectifier((int)$r2['id'], 'REC7 motif'), 'se corrige directement');
}
$res = rapport_transmettre($rid, '2026-02-05');
cas('Un rapport valide se transmet', !empty($res['success']), $res['error'] ?? '');
refuse_avec('La rectification exige son motif', rapport_rectifier($rid, ''), 'obligatoire');
$rect = rapport_rectifier($rid, 'REC7 erreur de saisie sur la ligne 2.1');
cas('La rectification produit une version numerotee', !empty($rect['success']), $rect['error'] ?? '');
if (!empty($rect['success'])) {
    $vr = rapport_restitution((int)$rect['id']);
    cas('La version rectificative porte le numero suivant', (int)$vr['version'] === 2, (string)$vr['version']);
    cas('Elle rattache le rapport qu\'elle corrige', (int)$vr['rectifie_id'] === $rid);
    cas('La version transmise reste intacte',
        (rapport_restitution($rid)['statut'] ?? '') === 'transmis', rapport_restitution($rid)['statut'] ?? '');
    cas('La periode se rouvre le temps de la correction',
        !periode_est_figee((int)$p1['id']), 'statut ' . ($vr['periode_statut'] ?? ''));
}

echo "\n== Ce qu'un rapport fige ne laisse plus faire\n";
// « Les lignes du rapport financier sont stockees et non recalculees, de sorte
// qu'une correction ulterieure dans un dossier ancien ne modifie jamais un rapport
// deja envoye » (CDC 6.7).
$avantLignes = [];
foreach (lignes_financieres($rid) as $lf) {
    $avantLignes[(int)$lf['ligne_id']] = (float)$lf['periode_total'];
}
rapport_calculer_lignes($rid);
$apresLignes = [];
foreach (lignes_financieres($rid) as $lf) {
    $apresLignes[(int)$lf['ligne_id']] = (float)$lf['periode_total'];
}
cas('Modifier un rapport transmis sans version rectificative est sans effet',
    $avantLignes == $apresLignes, count($avantLignes) . ' ligne(s) inchangées');
$refus = (int)$pdo->query("SELECT COUNT(*) FROM journal_audit
                            WHERE module = 'restitution' AND action = 'recalcul_refuse'")->fetchColumn();
cas('Le refus de recalcul laisse sa trace au journal', $refus > 0, $refus . ' trace(s)');

// « Le generateur refuse de produire un document si les donnees qui l'alimentent
// portent plus d'un identifiant de projet » (CDC 7.3).
cas('Les identifiants de projet se ramassent a toute profondeur',
    count(projets_dans(['a' => ['projet_id' => 1], 'b' => [['projet_id' => 2]]])) === 2,
    implode(', ', projets_dans(['a' => ['projet_id' => 1], 'b' => [['projet_id' => 2]]])));
refuse_avec('Produire un rapport portant deux identifiants de projet est refuse',
    document_generer('rapport_mensuel',
        ['rapport' => ['projet_id' => 1], 'autre' => ['projet_id' => 2]],
        'rapport', $rid, 'restitution'),
    'identifiants de projet');

// « Lorsqu'un rapport a fait l'objet d'une version rectificative, c'est celle-ci
// qui alimente le cumul du rapport suivant » (CDC 6.4).
if (!empty($rect['success'])) {
    $pdo->prepare("UPDATE rapports SET statut = 'valide' WHERE id = ?")->execute([(int)$rect['id']]);
    $ligneTest = budget_ligne('1.1');
    $cumul = cumul_anterieur((int)$ligneTest['id'], '2099-12-31', 0);
    $sr = $pdo->prepare("SELECT COUNT(*) FROM rapports r JOIN lignes_financieres lf ON lf.rapport_id = r.id
                          WHERE r.projet_id = 1 AND r.rectifie_id IS NOT NULL AND lf.ligne_id = ?");
    $sr->execute([(int)$ligneTest['id']]);
    cas('Le cumul alimente depuis une version remplacee ecarte celle qu\'elle rectifie',
        is_float($cumul) && (int)$sr->fetchColumn() >= 1, 'cumul ' . htg($cumul));
}

echo "\n== Solde de cloture\n";
$solde = solde_cloture();
cas('L\'enveloppe indirecte est plafonnee au taux du contrat',
    $solde['indirects'] <= round($solde['directs'] * $solde['taux_indirect'], 2) + 0.01,
    htg($solde['indirects']) . ' sur ' . htg($solde['directs']));
cas('Le total eligible additionne directs et indirects',
    abs($solde['total_eligible'] - ($solde['directs'] + $solde['indirects'])) < 0.01,
    htg($solde['total_eligible']));
cas('Le solde se compare aux prefinancements recus',
    in_array($solde['sens'], ['a_recevoir', 'a_rembourser'], true),
    $solde['sens'] . ' ' . htg($solde['solde']));

echo "\n== Ventilation et liasses\n";
$v = ventilation((string)$p1['date_debut'], (string)$p2['date_fin']);
cas('La ventilation separe la banque de la petite caisse',
    array_key_exists('banque', $v) && array_key_exists('caisse', $v),
    count($v['banque']) . ' banque · ' . count($v['caisse']) . ' caisse');
$res = liasse_periode($rid);
cas('La liasse de periode se produit ou dit pourquoi elle ne peut pas',
    !empty($res['success']) || str_contains($res['error'] ?? '', 'vide'), $res['error'] ?? ('index de ' . ($res['nombre'] ?? 0) . ' pièce(s)'));

echo "\n== Gabarits de l'annexe E\n";
foreach (['rapport_mensuel', 'rapport_narratif', 'rapport_financier', 'ventilation'] as $g) {
    cas('Le gabarit ' . $g . ' existe', is_file(root_dir() . '/pdf/templates/' . $g . '.php'));
}
$mentions = mentions_exemplaires();
cas('Les mentions d\'exemplaire viennent du parametre du projet',
    $mentions === [] || count($mentions) === 3, implode(' · ', $mentions) ?: 'paramètre non saisi');

echo "\n== Rendu des ecrans\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = $rid;
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
[$a, $b] = $rendre('modules/restitution/index.php');
cas('Restitution - clôture et rapports (KESKLE)', $a, $b);
[$a, $b] = $rendre('modules/restitution/rapport.php');
cas('Restitution - fiche d\'un rapport (KESKLE)', $a, $b);
[$a, $b] = $rendre('modules/restitution/ventilation.php');
cas('Restitution - ventilation (KESKLE)', $a, $b);
$_SESSION['projet_id'] = 2; $_SESSION['projet_code'] = 'KKP'; param_oublier();
unset($_GET['id']);
[$a, $b] = $rendre('modules/restitution/index.php');
cas('Restitution - clôture et rapports (KKP)', $a, $b);

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
