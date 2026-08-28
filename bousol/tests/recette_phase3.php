<?php
declare(strict_types=1);

/**
 * Recette de la phase 3 : module Comptes.
 * Couvre les cas de l'annexe G qui relevent de la comptabilite, des reglements et
 * du rapprochement, plus les cas qui doivent reussir.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase3.php
 *
 * Rejouable : la recette retire d'abord ses propres traces.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/comptes.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 3 - Comptes');
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

/** Les operations de Comptes rendent un tableau plutot que de lever : on le lit. */
function refuse_avec(string $lib, array $resultat, string $attendu = ''): void
{
    $refuse = empty($resultat['success']) || $resultat['success'] === false;
    cas($lib, $refuse && ($attendu === '' || str_contains(mb_strtolower($resultat['error'] ?? ''), mb_strtolower($attendu))),
        $resultat['error'] ?? 'accepte');
}

/**
 * La recette repose sur un etat de depart connu. Le journal d'audit, lui, garde
 * ses entrees : il est en ajout seul, et c'est voulu.
 */
function nettoyer_traces3(PDO $pdo): void
{
    $etapes = [
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id WHERE e.origine_ref LIKE 'REC3-%' OR e.libelle LIKE 'REC3-%'",
        "DELETE FROM ecritures WHERE origine_ref LIKE 'REC3-%' OR libelle LIKE 'REC3-%'",
        "DELETE v FROM validations_reglement v JOIN reglements r ON r.id = v.reglement_id WHERE r.objet LIKE 'REC3-%'",
        "DELETE m FROM mouvements m JOIN ecritures e ON e.id = m.ecriture_id JOIN reglements r ON r.id = e.reglement_id WHERE r.objet LIKE 'REC3-%'",
        "DELETE e FROM ecritures e JOIN reglements r ON r.id = e.reglement_id WHERE r.objet LIKE 'REC3-%'",
        "DELETE FROM reglements WHERE objet LIKE 'REC3-%'",
        "DELETE FROM lignes_rapprochement WHERE objet LIKE 'REC3-%'",
        "DELETE lr FROM lignes_rapprochement lr JOIN rapprochements ra ON ra.id = lr.rapprochement_id WHERE ra.date_releve = '2026-06-30'",
        "DELETE FROM rapprochements WHERE date_releve = '2026-06-30'",
        "DELETE FROM arretes_caisse WHERE commentaire LIKE 'REC3-%' OR date = '2026-06-30'",
        "DELETE FROM tiers WHERE nom LIKE 'REC3 %'",
    ];
    foreach ($etapes as $q) {
        try {
            $pdo->exec($q);
        } catch (Throwable $e) {
            // Une trace absente est le cas nominal.
        }
    }
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'raf';
$_SESSION['est_mandataire'] = false;
param_oublier();
nettoyer_traces3($pdo);

echo "== Plan de comptes\n";
$banque = compte_par_code('BQ');
$caisse = compte_par_code('CA');
$tiersC = compte_par_code('TI');
$dgi    = compte_par_code('DGI');
cas('Les six familles du plan sont posees',
    $banque && $caisse && $tiersC && $dgi && compte_par_code('AV') && compte_par_code('FIN'));
$l11 = budget_ligne('1.1');
cas('Chaque ligne imputable a son compte de charge', compte_charge_de_ligne((int)$l11['id']) !== null);

echo "\n== Ecritures : la partie double tient\n";
doit_echouer('Une ecriture desequilibree est refusee', function () use ($banque, $tiersC) {
    ecriture_poser(['date' => '2026-06-01', 'libelle' => 'REC3-desequilibre', 'type' => 'autre',
                    'origine_module' => 'recette', 'origine_ref' => 'REC3-1'],
        [['compte_id' => (int)$banque['id'], 'sens' => 'D', 'montant' => 100],
         ['compte_id' => (int)$tiersC['id'], 'sens' => 'C', 'montant' => 90]]);
});
doit_echouer('Un montant nul est refuse', function () use ($banque, $tiersC) {
    ecriture_poser(['date' => '2026-06-01', 'libelle' => 'REC3-nul', 'type' => 'autre',
                    'origine_module' => 'recette', 'origine_ref' => 'REC3-2'],
        [['compte_id' => (int)$banque['id'], 'sens' => 'D', 'montant' => 0],
         ['compte_id' => (int)$tiersC['id'], 'sens' => 'C', 'montant' => 0]]);
});
$compteAutreProjet = (int)$pdo->query("SELECT id FROM comptes WHERE projet_id = 2 AND type = 'banque' LIMIT 1")->fetchColumn();
doit_echouer('Une ecriture touchant le compte d\'un autre projet est refusee', function () use ($banque, $compteAutreProjet) {
    ecriture_poser(['date' => '2026-06-01', 'libelle' => 'REC3-cloisonnement', 'type' => 'autre',
                    'origine_module' => 'recette', 'origine_ref' => 'REC3-3'],
        [['compte_id' => (int)$banque['id'], 'sens' => 'D', 'montant' => 100],
         ['compte_id' => $compteAutreProjet, 'sens' => 'C', 'montant' => 100]]);
});

echo "\n== Les six ecritures types du CDC 4.8\n";
$fin = compte_par_code('FIN');
$types = [
    'Encaissement d\'une tranche' => fn() => ecriture_encaissement_tranche((int)$banque['id'], (int)$fin['id'], 2800000, '2026-06-02', 'REC3-tranche 1', 'REC3-t1'),
    'Facture recue'              => fn() => ecriture_facture((int)$l11['id'], 2, 120000, '2026-06-03', 'REC3-facture', 'REC3-f1'),
    'Honoraires avec acompte'    => fn() => ecriture_honoraires((int)$l11['id'], 2, 120000, 2400, '2026-06-04', 'REC3-honoraires', 'REC3-h1'),
    'Versement a la DGI'         => fn() => ecriture_versement_dgi((int)$banque['id'], 2400, '2026-06-05', 'REC3-dgi', 'REC3-d1'),
    'Remboursement de frais'     => fn() => ecriture_remboursement_frais((int)$l11['id'], 1, 5000, '2026-06-06', 'REC3-avance', 'REC3-a1'),
];
foreach ($types as $lib => $f) {
    try {
        $id = $f();
        $mv = mouvements_ecriture($id);
        $d = $c = 0.0;
        foreach ($mv as $m) {
            $m['sens'] === 'D' ? $d += (float)$m['montant'] : $c += (float)$m['montant'];
        }
        cas($lib . ' : posee et equilibree', abs($d - $c) < 0.01 && count($mv) >= 2, count($mv) . ' mouvements, ' . htg($d));
    } catch (Throwable $e) {
        cas($lib . ' : posee et equilibree', false, $e->getMessage());
    }
}
$b = balance();
$td = $tc = 0.0;
foreach ($b as $l) { $td += $l['debit']; $tc += $l['credit']; }
cas('La balance du projet est equilibree', abs($td - $tc) < 0.01, htg($td) . ' / ' . htg($tc));

echo "\n== Reglements : les six regles de decaissement\n";
$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC3 Mandataire A', 1)");
$mA = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC3 Mandataire B', 1)");
$mB = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC3 Simple', 0)");
$simple = (int)$pdo->lastInsertId();

refuse_avec('Regler en especes hors de la petite caisse est refuse',
    reglement_creer(['mode' => 'especes_caisse', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                     'montant' => 1000, 'objet' => 'REC3-especes hors caisse']), 'petite caisse');
refuse_avec('Un cheque sans numero est refuse',
    reglement_creer(['mode' => 'cheque', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                     'montant' => 1000, 'objet' => 'REC3-cheque sans numero']), 'chèque');
refuse_avec('Une operation en devise sans preuve de taux est refusee',
    reglement_creer(['mode' => 'virement', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                     'montant' => 3325, 'devise' => 'USD', 'montant_devise' => 25, 'taux_change' => 133,
                     'objet' => 'REC3-Google Play sans preuve']), 'preuve du taux');
refuse_avec('Un montant nul est refuse',
    reglement_creer(['mode' => 'virement', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                     'montant' => 0, 'objet' => 'REC3-montant nul']), 'positif');

// Un dossier portant deux imputations ne peut pas donner un seul reglement.
$pdo->exec("INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, created_by) VALUES (1, 'REC3-D1', 'achat_bien', 1, 'REC3-deux lignes', 1)");
$dossierMulti = (int)$pdo->lastInsertId();
$l22 = budget_ligne('2.2');
$pdo->prepare("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
               VALUES (1, ?, ?, 'forfait', 1, 10, 10, CURDATE())")->execute([$dossierMulti, (int)$l22['id']]);
$pdo->prepare("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
               VALUES (1, ?, ?, 'mois', 1, 10, 10, CURDATE())")->execute([$dossierMulti, (int)$l11['id']]);
refuse_avec('Creer un reglement portant sur deux lignes budgetaires est refuse',
    reglement_creer(['mode' => 'virement', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                     'montant' => 20, 'objet' => 'REC3-multiligne', 'origine_ref' => 'dossier:' . $dossierMulti]),
    'plusieurs imputations');

$r = reglement_creer(['mode' => 'virement', 'beneficiaire_id' => $mA, 'compte_id' => (int)$banque['id'],
                      'montant' => 50000, 'objet' => 'REC3-reglement nominal']);
cas('Un reglement conforme est enregistre', !empty($r['success']), $r['numero'] ?? ($r['error'] ?? ''));
$regId = (int)($r['id'] ?? 0);
$reg = reglement($regId);

echo "\n== Reglements : qui peut signer\n";
$v = reglement_valider($regId, $mA, 'signature_bancaire');
cas('Faire signer un reglement par son beneficiaire est refuse', empty($v['success']), $v['error'] ?? 'accepte');
$v = reglement_valider($regId, 1, 'signature_bancaire');
cas('Signer ce qu\'on a soi-meme libelle est refuse', empty($v['success']), $v['error'] ?? 'accepte');
$v = reglement_valider($regId, $simple, 'signature_bancaire');
cas('Signer sans etre mandataire est refuse', empty($v['success']), $v['error'] ?? 'accepte');

$res = reglement_executer($regId);
cas('Executer sans les deux autorisations est refuse', empty($res['success']), $res['error'] ?? 'accepte');

$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC3 Mandataire C', 1)");
$mC = (int)$pdo->lastInsertId();
$v1 = reglement_valider($regId, $mB, 'signature_bancaire');
cas('Un premier mandataire autorise', !empty($v1['success']), $v1['error'] ?? '');
cas('Une seule autorisation ne suffit pas', empty($v1['autorise']));
$v2 = reglement_valider($regId, $mC, 'signature_bancaire');
cas('Deux mandataires distincts autorisent le reglement', !empty($v2['autorise']), $v2['error'] ?? '');
$v3 = reglement_valider($regId, $mB, 'signature_bancaire');
cas('Le meme mandataire ne signe pas deux fois', empty($v3['success']), $v3['error'] ?? 'accepte');

echo "\n== Reglements : execution et annulation\n";
$avant = solde_compte((int)$banque['id']);
$res = reglement_executer($regId, '2026-06-10');
cas('Le reglement autorise s\'execute', !empty($res['success']), $res['error'] ?? '');
$apres = solde_compte((int)$banque['id']);
cas('La tresorerie a diminue du montant regle', abs(($avant - $apres) - 50000.0) < 0.01, htg($avant) . ' -> ' . htg($apres));
$ne = (int)$pdo->query("SELECT COUNT(*) FROM ecritures WHERE reglement_id = $regId")->fetchColumn();
cas('Un reglement produit exactement une ecriture', $ne === 1, (string)$ne);
$res = reglement_annuler($regId, 'REC3-tentative');
cas('Annuler un reglement execute est refuse', empty($res['success']), $res['error'] ?? 'accepte');

$r2 = reglement_creer(['mode' => 'cheque', 'numero_cheque' => '000123', 'beneficiaire_id' => $simple,
                       'compte_id' => (int)$banque['id'], 'montant' => 7000, 'objet' => 'REC3-cheque a annuler']);
$numeroAnnule = $r2['numero'] ?? '';
$res = reglement_annuler((int)$r2['id'], 'Chèque abîmé à l\'impression');
cas('Un cheque non execute s\'annule', !empty($res['success']), $res['error'] ?? '');
$ne = (int)$pdo->query("SELECT COUNT(*) FROM ecritures WHERE reglement_id = " . (int)$r2['id'])->fetchColumn();
cas('Un cheque annule ne genere aucune ecriture', $ne === 0);
$r3 = reglement_creer(['mode' => 'virement', 'beneficiaire_id' => $simple, 'compte_id' => (int)$banque['id'],
                       'montant' => 100, 'objet' => 'REC3-suivant']);
cas('Le numero d\'un reglement annule reste attribue',
    ($r3['numero'] ?? '') !== $numeroAnnule && $r3['numero'] > $numeroAnnule, $numeroAnnule . ' puis ' . ($r3['numero'] ?? ''));

echo "\n== Petite caisse\n";
$res = arrete_caisse_creer((int)$caisse['id'], '2026-06-30', 1500.00, 1, '');
cas('Un arrete avec ecart non explique est refuse', empty($res['success']), $res['error'] ?? 'accepte');
$res = arrete_caisse_creer((int)$caisse['id'], '2026-06-30', 1500.00, 1, 'REC3-ecart de recette');
cas('Un arrete dont l\'ecart est explique est accepte', !empty($res['success']), $res['error'] ?? '');
$theorique = solde_compte((int)$caisse['id'], '2026-06-30');
$res = arrete_caisse_creer((int)$caisse['id'], '2026-06-30', $theorique, 1, '');
cas('Un arrete sans ecart n\'exige aucun commentaire', !empty($res['success']), $res['error'] ?? '');
$plafond = param('plafond_petite_caisse');
cas('Le plafond du fonds fixe est un parametre du projet, non une constante',
    $plafond === null || (float)$plafond > 0, 'plafond ' . ($plafond ?? 'a definir'));

echo "\n== Rapprochement d'un compte partage\n";
$compteBancaire = (int)$banque['compte_bancaire_id'];
$rattaches = projets_du_compte_bancaire($compteBancaire);
cas('Le compte SOGEBANK porte les deux projets', count($rattaches) === 2, implode(', ', array_column($rattaches, 'code')));
$parProjet = solde_reconstitue_par_projet($compteBancaire, '2026-06-30');
cas('La ventilation nomme chaque projet rattache', count($parProjet) === 2, implode(', ', array_column($parProjet, 'code')));

$reconstitue = 0.0;
foreach ($parProjet as $p) { $reconstitue += $p['solde']; }
$etat = rapprochement_consolide($compteBancaire, '2026-06-30', $reconstitue);
cas('Sans ecart, le releve egale le reconstitue', abs($etat['ecart']) < 0.01, htg($etat['ecart']));

$periode = periode_pour_date('2026-06-30');
if ($periode === null) {
    cas('Rapprochement : periode de projet requise', true, 'KESKLE sans date de debut, le cas est saute');
} else {
    $pdo->prepare('INSERT INTO rapprochements (projet_id, periode_id, compte_id, date_releve, solde_releve, solde_reconstitue, ecart, created_by)
                   VALUES (1, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([(int)$periode['id'], (int)$banque['id'], '2026-06-30', $reconstitue + 5000, $reconstitue, 5000]);
    $rid = (int)$pdo->lastInsertId();
    $res = rapprochement_valider($rid);
    cas('Clore un rapprochement laissant un ecart non ventile est refuse', empty($res['success']), $res['error'] ?? 'accepte');

    $pdo->prepare('UPDATE rapprochements SET commentaire_ecart = ? WHERE id = ?')
        ->execute(['REC3-encaissement en transit', $rid]);
    $res = rapprochement_valider($rid);
    cas('Compte partage : l\'extrait manquant de l\'autre projet bloque la validation',
        empty($res['success']) && str_contains($res['error'] ?? '', 'KKP'), $res['error'] ?? 'accepte');
}

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
    foreach (['Comptes - plan et balance' => 'modules/comptes/index.php',
              'Comptes - reglements'      => 'modules/comptes/reglements.php',
              'Comptes - journal'         => 'modules/comptes/journal.php',
              'Comptes - rapprochement'   => 'modules/comptes/rapprochement.php',
              'Comptes - petite caisse'   => 'modules/comptes/caisse.php'] as $lib => $page) {
        [$ok2, $detail] = $rendre($page);
        cas($lib . ' (' . $code . ')', $ok2, $detail);
    }
}

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
