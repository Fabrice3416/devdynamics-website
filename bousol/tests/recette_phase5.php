<?php
declare(strict_types=1);

/**
 * Recette de la phase 5 : module Remuneration.
 * Les seize regles des sections 4.4 et 4.5, plus les cas qui doivent reussir.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase5.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/remuneration.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 5 - Remuneration');
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

/** Le televersement exige un vrai POST : on pose le rapport en base pour la suite. */
function poser_rapport(PDO $pdo, int $contratId, int $mois, string $autorite = 'coordinateur'): int
{
    $f = enregistrer_contenu('rapport ' . $contratId . '-' . $mois . '-' . random_int(1, 1e9),
        'pdf', 'application/pdf', 'scans', 'REC5-RAPPORT-' . $contratId . '-' . $mois . '.pdf');
    $pdo->prepare('INSERT INTO rapports_execution (projet_id, contrat_id, mois, date_remise, date_versement, fichier_id, autorite)
                   VALUES (?,?,?,CURDATE(),CURDATE(),?,?)')
        ->execute([projet_id(), $contratId, $mois, (int)$f['id'], $autorite]);
    return (int)$pdo->lastInsertId();
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'raf';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

// Deux intervenants : un mensuel recurrent, un au forfait avec avance.
$pdo->exec("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', 'REC5 Developpeur', 'Lead technique')");
$dev = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', 'REC5 Formateur', 'Formateur')");
$formateur = (int)$pdo->lastInsertId();
$lAE11 = budget_ligne('AE1.1');   // Developpeur lead technique, 7 mois x 130 000
$lAE21 = budget_ligne('AE2.1');   // Formateur, 6 jours x 40 000

$pdo->prepare("INSERT INTO contrats (projet_id, tiers_id, ligne_id, type, fonction, date_debut, date_fin,
                                     unite, quantite, montant_unitaire, montant_total, taux_acompte_defaut, created_by)
               VALUES (1,?,?,'service','REC5 lead technique','2026-01-01','2026-08-31','mois',7,130000,910000,2,1)")
     ->execute([$dev, (int)$lAE11['id']]);
$contratDev = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO contrats (projet_id, tiers_id, ligne_id, type, fonction, date_debut, date_fin,
                                     unite, quantite, montant_unitaire, montant_total, taux_acompte_defaut,
                                     avance_autorisee, part_avance, created_by)
               VALUES (1,?,?,'service','REC5 formation','2026-05-01','2026-06-30','jour',6,40000,240000,2,1,40,1)")
     ->execute([$formateur, (int)$lAE21['id']]);
$contratFormateur = (int)$pdo->lastInsertId();

echo "== Rapport d'execution\n";
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Le Coordinateur ne verse pas le rapport au dossier',
    rapport_verser($contratDev, 91, date('Y-m-d'), ['name' => 'x.pdf']), 'Responsable Administratif');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Un rapport sans piece numerisee est refuse',
    rapport_verser($contratDev, 91, date('Y-m-d'), null), 'obligatoire');

$rapDev = poser_rapport($pdo, $contratDev, 91);
$r = rapport($rapDev);
cas('Le rapport conserve sa date de remise et sa date de versement',
    $r['date_remise'] !== null && $r['date_versement'] !== null);
cas('L\'autorite d\'acceptation est un attribut du rapport',
    in_array($r['autorite'], array_keys(AUTORITES_ACCEPTATION), true), $r['autorite']);

echo "\n== Certificat d'acceptation\n";
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne delivre pas le certificat', rapport_accepter($rapDev), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Aucune prestation sans certificat prealable',
    prestation_calculer($rapDev), 'certificat');
$res = rapport_accepter($rapDev);
cas('Le Coordinateur delivre le certificat', !empty($res['success']), $res['error'] ?? '');
refuse_avec('Un rapport deja accepte ne se represente pas', rapport_accepter($rapDev), 'déjà');

// On n'accepte pas son propre rapport : le contrat du Coordinateur releve de l'AG.
$pdo->prepare('UPDATE contrats SET tiers_id = 1 WHERE id = ?')->execute([$contratDev]);
$rapSoi = poser_rapport($pdo, $contratDev, 92, 'assemblee_generale');
refuse_avec('On n\'accepte pas son propre rapport d\'execution', rapport_accepter($rapSoi), 'propre rapport');
$pdo->prepare('UPDATE contrats SET tiers_id = ? WHERE id = ?')->execute([$dev, $contratDev]);

echo "\n== Prestation : brut, acompte fige, net\n";
$_SESSION['role_projet'] = 'raf';
$avantDgi = solde_compte((int)compte_par_code('DGI')['id']);
$res = prestation_calculer($rapDev, 1.0);
cas('La prestation se calcule sur un rapport accepte', !empty($res['success']), $res['error'] ?? '');
cas('Le brut suit le contrat', abs(($res['brut'] ?? 0) - 130000) < 0.01, htg($res['brut'] ?? 0));
cas('L\'acompte est de 2 % du brut', abs(($res['acompte'] ?? 0) - 2600) < 0.01, htg($res['acompte'] ?? 0));
cas('Le net est le brut moins l\'acompte', abs(($res['net'] ?? 0) - 127400) < 0.01, htg($res['net'] ?? 0));
$prest = prestation((int)$res['id']);
cas('Le taux est fige sur la prestation', abs((float)$prest['taux_acompte'] - 2.0) < 0.01, $prest['taux_acompte']);

// Un changement ulterieur du contrat ne doit rien changer a la prestation reglee.
$pdo->prepare('UPDATE contrats SET taux_acompte_defaut = 10 WHERE id = ?')->execute([$contratDev]);
$prest2 = prestation((int)$res['id']);
cas('Un changement de taux au contrat ne modifie pas retroactivement la prestation',
    abs((float)$prest2['taux_acompte'] - 2.0) < 0.01, $prest2['taux_acompte']);
$pdo->prepare('UPDATE contrats SET taux_acompte_defaut = 2 WHERE id = ?')->execute([$contratDev]);

$apresDgi = solde_compte((int)compte_par_code('DGI')['id']);
cas('L\'acompte naît au credit de la dette DGI avec la prestation',
    abs(($avantDgi - $apresDgi) - 2600) < 0.01, htg($apresDgi - $avantDgi));
$charge = compte_charge_de_ligne((int)$lAE11['id']);
cas('La ligne est consommee pour le brut, pas pour le net',
    abs(solde_compte((int)$charge['id']) - 130000) < 0.01, htg(solde_compte((int)$charge['id'])));
refuse_avec('Un contrat n\'a qu\'une prestation par mois', prestation_calculer($rapDev), 'déjà une prestation');

// « Le second versement solde l'avance » : solder, c'est completer, jamais depasser.
$rapPlafond = poser_rapport($pdo, $contratDev, 96);
$_SESSION['role_projet'] = 'coordinateur';
rapport_accepter($rapPlafond);
$_SESSION['role_projet'] = 'raf';
refuse_avec('La somme des versements ne depasse pas le brut du contrat',
    prestation_calculer($rapPlafond, 10.0), 'il en reste');

echo "\n== Dossier de la prestation : le brut impute, le net regle\n";
$res2 = prestation_ouvrir_dossier((int)$res['id']);
cas('Le dossier de la prestation s\'ouvre', !empty($res2['success']), $res2['error'] ?? '');
$imp = imputation_dossier((int)$res2['dossier_id']);
cas('L\'imputation porte le brut', $imp !== null && abs((float)$imp['montant'] - 130000) < 0.01,
    $imp ? htg((float)$imp['montant']) : '');
refuse_avec('La prestation n\'ouvre qu\'un dossier', prestation_ouvrir_dossier((int)$res['id']), 'déjà son dossier');

echo "\n== Avance sur honoraires\n";
refuse_avec('Une avance sur un contrat mensuel recurrent est refusee',
    avance_verser($contratDev, 93, ['name' => 'entente.pdf']), 'mensuel récurrent');
$avances = param('avances_honoraires', '0');
cas('L\'ouverture des avances est un parametre du projet',
    in_array($avances, ['0', '1'], true), 'avances_honoraires = ' . $avances);
if ($avances !== '1') {
    refuse_avec('Sans autorisation du projet, aucune avance',
        avance_verser($contratFormateur, 94, ['name' => 'entente.pdf']), 'pas autorisées');
}

echo "\n== Ratification par l'Assemblee Generale\n";
$rapAg = poser_rapport($pdo, $contratFormateur, 95, 'assemblee_generale');
$_SESSION['role_projet'] = 'coordinateur';
rapport_accepter($rapAg);
$_SESSION['role_projet'] = 'raf';
$resAg = prestation_calculer($rapAg, 1.0);
cas('Une prestation dont l\'autorite est l\'AG naît provisoire',
    !empty($resAg['success']) && (prestation((int)$resAg['id'])['ratification'] ?? '') === 'provisoire',
    prestation((int)($resAg['id'] ?? 0))['ratification'] ?? ($resAg['error'] ?? ''));
$nonRat = prestations_non_ratifiees();
cas('Bousol tient la liste des prestations non ratifiees', count($nonRat) >= 1, count($nonRat) . ' provisoire(s)');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne verse pas la resolution de l\'AG',
    prestation_ratifier([(int)$resAg['id']], ['name' => 'res.pdf']), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Ratifier sans resolution ecrite est refuse',
    prestation_ratifier([(int)$resAg['id']], []), 'obligatoire');

echo "\n== Versement a la DGI\n";
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Le Coordinateur ne prepare pas le versement',
    versement_dgi_preparer(91), 'Responsable Administratif');
$_SESSION['role_projet'] = 'raf';
refuse_avec('Un mois sans acompte n\'a rien a verser', versement_dgi_preparer(99), 'Aucun acompte');

$solde = dette_dgi_soldee(91);
cas('La cloture du mois est bloquee tant que la dette n\'est pas soldee',
    $solde['soldee'] === false, $solde['motif'] ?? '');
$resV = versement_dgi_preparer(91);
cas('Le versement du mois se prepare', !empty($resV['success']), $resV['error'] ?? '');
cas('Le versement porte le total des acomptes du mois',
    abs(($resV['montant'] ?? 0) - 2600) < 0.01, htg($resV['montant'] ?? 0));
$impV = imputation_dossier((int)$resV['dossier_id']);
cas('Sa fiche d\'imputation est pour memoire, a consommation nulle',
    $impV !== null && $impV['nature'] === 'memoire' && abs((float)$impV['montant']) < 0.01,
    $impV ? $impV['nature'] . ' ' . htg((float)$impV['montant']) : '');
$soldeApres = dette_dgi_soldee(91);
cas('La cloture reste bloquee tant que le versement n\'est pas regle',
    $soldeApres['soldee'] === false, $soldeApres['motif'] ?? '');
refuse_avec('Un mois n\'a qu\'un versement', versement_dgi_preparer(91), 'déjà son versement');

$b = balance();
$td = $tc = 0.0;
foreach ($b as $l) { $td += $l['debit']; $tc += $l['credit']; }
cas('La balance reste equilibree apres honoraires et memoire', abs($td - $tc) < 0.01, htg($td) . ' / ' . htg($tc));

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
    foreach (['Rémunération - rapports' => 'modules/remuneration/index.php',
              'Rémunération - DGI'      => 'modules/remuneration/dgi.php'] as $lib => $page) {
        [$r1, $d1] = $rendre($page);
        cas($lib . ' (' . $code . ')', $r1, $d1);
    }
}

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
