<?php
declare(strict_types=1);

/**
 * Recette de la phase 2 : modules Tiers et Budget.
 * Reprend les cas de l'annexe G qui relevent de ces deux modules, plus les cas
 * "qui doivent reussir" correspondants - un outil qui refuserait tout passerait
 * autrement la recette integralement.
 *
 * Usage (CLI, sur une base de TEST chargee avec schema.sql, schema_triggers.sql
 * et seed.sql) :
 *   php bousol/tests/recette_phase2.php
 *
 * Rejouable : la recette retire d'abord ses propres traces, sans quoi son second
 * passage echouerait sur les dossiers et les tiers laisses par le premier.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/budget.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 2 - Tiers et Budget');
$pdo = db();
$ok = 0; $ko = 0;

function cas(string $lib, bool $reussi, string $detail = ''): void
{
    global $ok, $ko;
    $reussi ? $ok++ : $ko++;
    echo ($reussi ? '  OK  ' : ' ECHEC') . ' ' . $lib . ($detail !== '' ? '  [' . mb_substr($detail, 0, 95) . ']' : '') . PHP_EOL;
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

/**
 * La recette part d'un etat connu. Plutot que d'exiger un rechargement du seed
 * entre deux passages, elle retire ses propres traces : les dossiers REC2-*, leurs
 * imputations, les tiers qu'elle cree, et les mouvements qu'elle a appliques au
 * budget de gestion. Le journal d'audit, lui, est en ajout seul : ses entrees
 * restent, et c'est exactement ce qu'on attend de lui.
 */
function nettoyer_traces(PDO $pdo): void
{
    $etapes = [
        "DELETE FROM imputations WHERE dossier_id IN (SELECT id FROM dossiers WHERE numero LIKE 'REC2-%')",
        "DELETE FROM dossiers WHERE numero LIKE 'REC2-%'",
        "DELETE FROM tiers WHERE nom IN ('Fournisseur Recette', 'Doublon Recette', 'Sans NIF 1', 'Sans NIF 2')",
        // Les fichiers ne se suppriment pas (trg_fichiers_no_delete) : on les
        // renomme pour que le passage suivant reprenne les siens sans ambiguite.
        "UPDATE fichiers SET nom_genere = CONCAT('ANCIEN-', id, '-', nom_genere) WHERE nom_genere LIKE 'REC2-AUTORISATION-%'",
        // Si une cle etrangere retient le tiers, liberer au moins son NIF suffit.
        "UPDATE tiers SET nif = NULL WHERE nif = '001-234-567-8'",
        // Le budget de gestion repart du contractuel, comme au chargement du seed.
        'UPDATE lignes_budgetaires SET montant_gestion = montant, quantite_gestion = quantite WHERE projet_id = 1',
    ];
    foreach ($etapes as $q) {
        try {
            $pdo->exec($q);
        } catch (Throwable $e) {
            // Une trace absente n'est pas une anomalie : c'est le cas nominal.
        }
    }
}

/** Un refus attendu, exprime par sa regle : budget_controle_* rend une liste. */
function refuse(array $refus, string $regle): bool
{
    foreach ($refus as $r) {
        if (($r['regle'] ?? '') === $regle) {
            return true;
        }
    }
    return false;
}

function messages(array $refus): string
{
    return implode(' | ', array_column($refus, 'message'));
}

// Session simulee : coordinateur de KesKle.
$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
param_oublier();
nettoyer_traces($pdo);

echo "== Tiers : unicite du NIF\n";
$tiersA = (int)etape('Creer un tiers avec un NIF neuf', function () use ($pdo) {
    $pdo->exec("INSERT INTO tiers (type, nom, nif) VALUES ('fournisseur', 'Fournisseur Recette', '001-234-567-8')");
    return (int)$pdo->lastInsertId();
});
doit_echouer('Creer un second tiers portant un NIF deja enregistre', function () use ($pdo) {
    $pdo->exec("INSERT INTO tiers (type, nom, nif) VALUES ('fournisseur', 'Doublon Recette', '001-234-567-8')");
});
$sansNif = (int)etape('Creer un tiers sans NIF', function () use ($pdo) {
    $pdo->exec("INSERT INTO tiers (type, nom) VALUES ('fournisseur', 'Sans NIF 1')");
    return (int)$pdo->lastInsertId();
});
doit_echouer('Donner a un tiers existant un NIF deja pris', function () use ($pdo, $sansNif) {
    $pdo->exec("UPDATE tiers SET nif = '001-234-567-8' WHERE id = $sansNif");
});
$avant = (int)$pdo->query("SELECT COUNT(*) FROM tiers WHERE nif IS NULL")->fetchColumn();
etape('Un second tiers sans NIF passe', fn() => $pdo->exec("INSERT INTO tiers (type, nom) VALUES ('fournisseur', 'Sans NIF 2')"));
$apres = (int)$pdo->query("SELECT COUNT(*) FROM tiers WHERE nif IS NULL")->fetchColumn();
cas('Plusieurs tiers sans NIF cohabitent', $apres === $avant + 1, $avant . ' -> ' . $apres);

echo "\n== Budget : arithmetique de la nomenclature\n";
cas('Couts directs contractuels de KesKle', abs(budget_couts_directs_contractuels(1) - 4984325.00) < 0.01,
    (string)budget_couts_directs_contractuels(1));
cas('Total contractuel de KesKle (lignes + indirects + provision)',
    abs(budget_total_contractuel(1) - 5599889.14) < 0.01, (string)budget_total_contractuel(1));
cas('Total sous le plafond contractuel', budget_total_contractuel(1) <= (float)plafond_contractuel(),
    'plafond ' . plafond_contractuel());
cas('Taux des couts indirects deduit du contrat', abs(budget_taux_indirect(1) - 0.07) < 0.0001,
    (string)budget_taux_indirect(1));
cas('Part des ressources humaines a 24,29 %', abs((float)budget_part_rh(1) - 24.29) < 0.01,
    (string)budget_part_rh(1));
// Une rubrique non ventilee vaut son sous-total : sans cela le projet tomberait a
// 103 756 gourdes pour un plafond de 974 556, et la part RH afficherait 231 %.
cas('Total contractuel de Koule Ki Pale malgre son detail manquant',
    abs(budget_total_contractuel(2) - 974556.00) < 0.01, (string)budget_total_contractuel(2));
cas('Part des ressources humaines de Koule Ki Pale a 24,63 %',
    abs((float)budget_part_rh(2) - 24.63) < 0.01, (string)budget_part_rh(2));
cas('Detail de KesKle complet, detail de Koule Ki Pale a saisir',
    budget_detail_manquant(1) === [] && budget_detail_manquant(2) !== [],
    count(budget_detail_manquant(2)) . ' rubrique(s) KKP non ventilees');

echo "\n== Budget : controles a l'imputation\n";
$l11 = budget_ligne('1.1');          // Coordonnateur, 8 mois x 120 000
$l22 = budget_ligne('2.2');          // Compte Google Play, forfait 3 325
$l10 = budget_ligne('10');           // Provision pour imprevus

// Huit mois deja consommes, pour pouvoir refuser le neuvieme.
etape('Poser huit mois consommes sur la ligne 1.1', function () use ($pdo, $l11) {
    $pdo->exec("INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, created_by) VALUES (1, 'REC2-0001', 'service_particulier', 1, 'recette', 1)");
    $d1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
                   VALUES (1, ?, ?, 'mois', 8, 120000, 960000, CURDATE())")->execute([$d1, (int)$l11['id']]);
});

$r = budget_controle_imputation((int)$l11['id'], 120000.0, 1.0);
cas('Imputer un neuvieme mois sur une ligne budgetee a huit mois est refuse',
    refuse($r, 'quantite') || refuse($r, 'montant'), messages($r));
$r = budget_controle_imputation((int)$l11['id'], 0.0, 1.0, true);
cas('La derogation du Coordinateur leve le seul controle de quantite',
    !refuse($r, 'quantite'), messages($r));

$r = budget_controle_imputation((int)$l10['id'], 1000.0, 1.0);
cas('Imputer directement sur la provision pour imprevus est refuse',
    refuse($r, 'provision') || refuse($r, 'nature'), messages($r));

// Ligne au forfait : un reglement en deux temps, avance puis solde, doit passer.
etape('Poser un premier versement sur la ligne au forfait 2.2', function () use ($pdo, $l22) {
    $pdo->exec("INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, created_by) VALUES (1, 'REC2-0002', 'service_compagnie', 1, 'recette forfait', 1)");
    $d2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
                   VALUES (1, ?, ?, 'forfait', 1, 2000, 2000, CURDATE())")->execute([$d2, (int)$l22['id']]);
});
$r = budget_controle_imputation((int)$l22['id'], 1325.0, 1.0);
cas('Second versement sur une ligne au forfait dont l\'enveloppe reste disponible : accepte',
    $r === [], messages($r));
$r = budget_controle_imputation((int)$l22['id'], 5000.0, 1.0);
cas('Depassement en montant sur la meme ligne au forfait : refuse',
    refuse($r, 'montant'), messages($r));

echo "\n== Budget : controles a la reallocation\n";
// Plancher : 960 000 sont consommes sur 1.1
$r = budget_controle_reallocation(['1.1' => -100000.0, '1.2' => 100000.0]);
cas('Reallouer une ligne en dessous de son montant deja consomme est refuse',
    refuse($r['refus'], 'plancher'), messages($r['refus']));

// Variation : la rubrique 2 pese 83 325 ; +30 000 font +36 %, au-dela du blocage a 25 %.
$r = budget_controle_reallocation(['2.1' => 30000.0, '1.2' => -30000.0]);
cas('Reallouer au-dela de 25 % sans autorisation televersee est refuse',
    refuse($r['refus'], 'variation'), messages($r['refus']));
$r = budget_controle_reallocation(['2.1' => 30000.0, '1.2' => -30000.0], [], ['variation' => 1]);
cas('Le meme mouvement passe avec l\'autorisation televersee',
    !refuse($r['refus'], 'variation'), messages($r['refus']));

// Plafond : le budget de gestion est deja a 5 599 889,14 pour un plafond de 5 600 000.
$r = budget_controle_reallocation(['3.3' => 5000.0]);
cas('Reallouer au-dela du plafond contractuel du projet est refuse',
    refuse($r['refus'], 'plafond'), messages($r['refus']));
$r = budget_controle_reallocation(['3.3' => 100.0]);
cas('Une hausse qui reste sous le plafond passe',
    !refuse($r['refus'], 'plafond'), messages($r['refus']));

// Provision : mobilisation vers une ligne de la rubrique 3 (334 000).
$r = budget_controle_reallocation(['10' => -50000.0, '3.1' => 50000.0]);
cas('Mobiliser la provision sans autorisation est refuse',
    refuse($r['refus'], 'provision'), messages($r['refus']));
$r = budget_controle_reallocation(['10' => -50000.0, '3.1' => 50000.0], [], ['provision' => 1]);
cas('Mobilisation sous le seuil de variation : la seule autorisation de mobilisation suffit',
    $r['refus'] === [], messages($r['refus']));

// Au-dela du seuil : +120 000 sur la rubrique 3 font +35,9 %, blocage a 25 %.
$r = budget_controle_reallocation(['10' => -120000.0, '3.1' => 120000.0], [], ['provision' => 1]);
cas('Mobiliser la provision au-dela du seuil de variation avec une seule autorisation est refuse',
    refuse($r['refus'], 'variation'), messages($r['refus']));
$r = budget_controle_reallocation(['10' => -120000.0, '3.1' => 120000.0], [], ['provision' => 1, 'variation' => 2]);
cas('Les deux autorisations separees debloquent la mobilisation',
    $r['refus'] === [], messages($r['refus']));

// Deux autorisations exigees separement : donc deux pieces distinctes.
$empreinte = str_repeat('a', 64);
etape('Verser deux pieces d\'autorisation', function () use ($pdo, $empreinte) {
    $ins = $pdo->prepare("INSERT INTO fichiers (nom_genere, chemin, extension, mime, taille, empreinte)
                          VALUES (?, ?, 'pdf', 'application/pdf', 1, ?)");
    $ins->execute(['REC2-AUTORISATION-A.pdf', 'coffre/rec2-a.pdf', $empreinte]);
    $ins->execute(['REC2-AUTORISATION-B.pdf', 'coffre/rec2-b.pdf', str_repeat('b', 64)]);
});
$fA = (int)$pdo->query("SELECT id FROM fichiers WHERE nom_genere = 'REC2-AUTORISATION-A.pdf' ORDER BY id DESC LIMIT 1")->fetchColumn();
$fB = (int)$pdo->query("SELECT id FROM fichiers WHERE nom_genere = 'REC2-AUTORISATION-B.pdf' ORDER BY id DESC LIMIT 1")->fetchColumn();
$r = budget_controle_reallocation(['10' => -120000.0, '3.1' => 120000.0], [], ['provision' => $fA, 'variation' => $fA]);
cas('La meme piece ne vaut pas les deux autorisations',
    refuse($r['refus'], 'autorisations'), messages($r['refus']));
$r = budget_controle_reallocation(['10' => -120000.0, '3.1' => 120000.0], [], ['provision' => $fA, 'variation' => $fB]);
cas('Deux pieces distinctes sont acceptees', $r['refus'] === [], messages($r['refus']));

echo "\n== Budget : une reallocation qui aboutit\n";
$avant = budget_ligne('3.1');
etape('Appliquer un mouvement interne a la rubrique 3',
    fn() => budget_appliquer_reallocation(['3.2' => -20000.0, '3.1' => 20000.0], [], 'Recette phase 2'));
$apres = budget_ligne('3.1');
cas('Le budget de gestion a bouge',
    abs((float)$apres['montant_gestion'] - (float)$avant['montant_gestion'] - 20000.0) < 0.01,
    $avant['montant_gestion'] . ' -> ' . $apres['montant_gestion']);
cas('Le budget contractuel n\'a pas bouge', (float)$apres['montant'] === (float)$avant['montant']);
cas('Le total du budget de gestion est inchange', abs(budget_total_gestion(1) - 5599889.14) < 0.01,
    (string)budget_total_gestion(1));
$trace = (int)$pdo->query("SELECT COUNT(*) FROM journal_audit WHERE module = 'budget' AND action = 'budget_realloue'")->fetchColumn();
cas('La reallocation a laisse sa trace au journal d\'audit', $trace > 0, $trace . ' entree(s)');

echo "\n== Budget : couts indirects recalcules\n";
$ind = budget_couts_indirects_constates(1);
cas('Sept pour cent des couts directs constates',
    abs($ind['enveloppe'] - round($ind['directs_constates'] * 0.07, 2)) < 0.01,
    $ind['directs_constates'] . ' x 7 % = ' . $ind['enveloppe']);
cas('La provision et les couts indirects sont hors assiette',
    $ind['directs_constates'] > 0 && $ind['directs_constates'] <= 4984325.00,
    (string)$ind['directs_constates']);

echo "\n== Cloisonnement\n";
$_SESSION['projet_id'] = 2; $_SESSION['projet_code'] = 'KKP'; param_oublier();
cas('Koule Ki Pale mesure la variation entre lignes, pas entre rubriques',
    param('granularite_variation') === 'ligne', (string)param('granularite_variation'));
cas('Sa provision est la ligne mixte 5.1, imputable',
    (budget_ligne_provision()['code'] ?? '') === '5.1' && (budget_ligne_provision()['nature'] ?? '') === 'imputable');
$r = budget_controle_imputation((int)budget_ligne_provision()['id'], 1000.0, 1.0);
cas('Sur une ligne mixte, les frais bancaires s\'imputent sans autorisation',
    !refuse($r, 'provision'), messages($r));
// Les 910 800 de couts directs du contrat KKP incluent les 40 000 de la ligne
// mixte : les frais bancaires sont une charge reelle et non une provision, donc
// ils entrent dans l'assiette des 7 %.
$provKkp = budget_ligne_provision();
etape('Imputer des frais bancaires sur la ligne mixte', function () use ($pdo, $provKkp) {
    $pdo->exec("INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, created_by) VALUES (2, 'REC2-KKP-01', 'service_compagnie', 1, 'recette KKP', 1)");
    $d = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
                   VALUES (2, ?, ?, 'forfait', 1, 1000, 1000, CURDATE())")->execute([$d, (int)$provKkp['id']]);
});
$indKkp = budget_couts_indirects_constates(2);
cas('La ligne mixte compte dans l\'assiette des couts indirects',
    abs($indKkp['directs_constates'] - 1000.0) < 0.01, (string)$indKkp['directs_constates']);

// Un budget non ventile mesure a la rubrique ne doit pas afficher 100 % de
// variation : la rubrique vaut son sous-total des deux cotes tant que le detail
// n'est pas saisi, sinon plus aucun mouvement ne passerait.
param_set('granularite_variation', 'rubrique', 'Recette : controle du repli');
param_oublier();
$bloquantes = array_filter(budget_variations(2),
    fn($g) => $g['variation_pct'] !== null && $g['variation_pct'] >= 25);
cas('Un budget non ventile ne bloque aucune rubrique',
    $bloquantes === [], count($bloquantes) . ' groupe(s) au-dela du seuil');
param_set('granularite_variation', 'ligne', 'Recette : retour a la lecture FOKAL');
param_oublier();
cas('La granularite de Koule Ki Pale est rendue a la ligne',
    param('granularite_variation') === 'ligne', (string)param('granularite_variation'));

$ligneKeskle = budget_ligne('1.1', 1);
$r = budget_controle_imputation((int)$ligneKeskle['id'], 1000.0, 1.0);
cas('Imputer sur la ligne d\'un autre projet est refuse', refuse($r, 'cloisonnement'), messages($r));

echo "\n== Rendu des ecrans\n";
// La recette validait la bibliotheque sans jamais rendre une page : un TypeError
// dans un gabarit passait donc inapercu, et coupait l'ecran en plein milieu sans
// rien afficher en production, ou display_errors est a Off. On rend chaque page
// et on verifie qu'elle va jusqu'au bout de son document.
$_SERVER['REQUEST_METHOD'] = 'GET';
$ecrans = [
    'Budget - nomenclature'     => 'modules/budget/index.php',
    'Budget - reallocation'     => 'modules/budget/realloc.php',
    'Budget - detail du contrat' => 'modules/budget/nomenclature.php',
    'Tiers - referentiel'       => 'modules/tiers/index.php',
    'Tiers - contrats'          => 'modules/tiers/contrats.php',
    'Tiers - beneficiaires'     => 'modules/tiers/beneficiaires.php',
];
$rendre = function (string $page): array {
    ob_start();
    try {
        require __DIR__ . '/../' . $page;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        return [false, get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
    // Une page tronquee est le symptome exact du TypeError avale : elle commence
    // normalement et s'arrete au milieu du tableau.
    return [str_contains($html, '</html>'), strlen($html) . ' octets'];
};
foreach ([1 => 'KESKLE', 2 => 'KKP'] as $pid => $code) {
    $_SESSION['projet_id'] = $pid;
    $_SESSION['projet_code'] = $code;
    param_oublier();
    foreach ($ecrans as $lib => $page) {
        [$ok2, $detail] = $rendre($page);
        cas($lib . ' (' . $code . ')', $ok2, $detail);
    }
}

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
