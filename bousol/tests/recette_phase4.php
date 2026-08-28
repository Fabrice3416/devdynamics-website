<?php
declare(strict_types=1);

/**
 * Recette de la phase 4 : module Depenses.
 * Couvre les cas de l'annexe G qui relevent du dossier, de sa checklist et de son
 * cycle, plus les cas qui doivent reussir.
 *
 *   BOUSOL_RECETTE=oui php bousol/tests/recette_phase4.php
 *
 * Rejouable : le nettoyage commun de _garde.php retire ses traces.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/depenses.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 4 - Depenses');
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

/** Le televersement exige un vrai POST : on coche la case en base pour la suite. */
function poser_piece(PDO $pdo, int $dossierId, string $type, ?string $date = null): void
{
    $pdo->prepare("UPDATE pieces SET statut = 'recue', date_piece = ? WHERE dossier_id = ? AND type = ?")
        ->execute([$date ?? date('Y-m-d'), $dossierId, $type]);
}

function poser_toutes_pieces(PDO $pdo, int $dossierId, string $moment): void
{
    $pdo->prepare("UPDATE pieces SET statut = 'recue', date_piece = CURDATE()
                    WHERE dossier_id = ? AND moment = ? AND statut = 'attendue'")
        ->execute([$dossierId, $moment]);
}

$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['admin_outil'] = true; $_SESSION['projet_id'] = 1;
$_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'raf';
$_SESSION['est_mandataire'] = false;
param_oublier();
recette_nettoyer($pdo);

echo "== Ouverture d'un dossier\n";
refuse_avec('Ouvrir un dossier d\'un type desactive est refuse',
    dossier_ouvrir(['type' => 'contrat_travail', 'tiers_id' => 2, 'objet' => 'REC4-contrat de travail']),
    'désactivé');
refuse_avec('Ouvrir un dossier d\'un type inconnu est refuse',
    dossier_ouvrir(['type' => 'inexistant', 'tiers_id' => 2, 'objet' => 'REC4-inconnu']), 'inconnu');
refuse_avec('Un dossier sans objet est refuse',
    dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => '']), 'objet');
refuse_avec('Un beneficiaire inconnu est refuse',
    dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 999999, 'objet' => 'REC4-tiers inconnu']), 'inconnu');

$r = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => 'REC4-achat de telephones', 'montant_prevu' => 20000]);
cas('Un dossier conforme s\'ouvre', !empty($r['success']), $r['numero'] ?? ($r['error'] ?? ''));
$dos = (int)($r['id'] ?? 0);
$pieces = pieces_dossier($dos);
cas('La checklist du type est creee a l\'ouverture', count($pieces) === count(TYPES_DOSSIER['achat_bien']['pieces']),
    count($pieces) . ' cases');
cas('Chaque case nait attendue et vide',
    count(array_filter($pieces, fn($p) => $p['statut'] === 'attendue' || $p['statut'] === 'sans_objet')) === count($pieces));

echo "\n== Mise en concurrence\n";
$seuil = param('seuil_proformas');
if ($seuil === null) {
    // Sans seuil, aucune mise en concurrence n'est exigible et la moitie de cette
    // section ne testerait rien. On le pose une fois : les parametres sont
    // historises, cette version-la reste et c'est ce que le CDC veut.
    param_set('seuil_proformas', '10000', 'Valeur posee par la recette de la phase 4');
    $seuil = param('seuil_proformas');
}
cas('Le seuil de mise en concurrence est un parametre du projet',
    $seuil !== null && (float)$seuil > 0, 'seuil ' . ($seuil ?? 'a definir'));
proforma_ajouter($dos, 2, 18000);
proforma_ajouter($dos, 3, 20000);
$props = proformas_dossier($dos);
cas('Les proformas se versent au dossier', count($props) === 2, count($props) . ' offres');
$plusChere = null; $moinsChere = null;
foreach ($props as $p) {
    if ($moinsChere === null || (float)$p['montant'] < (float)$moinsChere['montant']) { $moinsChere = $p; }
    if ($plusChere === null || (float)$p['montant'] > (float)$plusChere['montant']) { $plusChere = $p; }
}
refuse_avec('Choisir une offre autre que la moins-disante sans motif est refuse',
    proforma_retenir((int)$plusChere['id']), 'motif écrit');
$res = proforma_retenir((int)$plusChere['id'], 'Délai de livraison de 3 jours contre 6 semaines');
cas('La meme offre passe avec son motif ecrit', !empty($res['success']), $res['error'] ?? '');
$res = proforma_retenir((int)$moinsChere['id']);
cas('La moins-disante se retient sans motif', !empty($res['success']), $res['error'] ?? '');

echo "\n== Imputation, premiere etape bloquante\n";
$l21 = budget_ligne('2.1');   // Telephones de test, 4 unites x 20 000
$l11 = budget_ligne('1.1');
refuse_avec('Imputer sur une ligne non imputable est refuse',
    dossier_imputer($dos, (int)budget_ligne('10')['id'], 1, 1000, 'forfait'), 'non imputable');
$ligneAutreProjet = (int)$pdo->query("SELECT id FROM lignes_budgetaires WHERE projet_id = 2 AND nature = 'imputable' LIMIT 1")->fetchColumn();
refuse_avec('Imputer sur la ligne d\'un autre projet est refuse',
    dossier_imputer($dos, $ligneAutreProjet, 1, 1000, 'forfait'), 'autre projet');
refuse_avec('Imputer au-dela du solde de la ligne est refuse',
    dossier_imputer($dos, (int)$l21['id'], 10, 20000, 'unite'), 'solde');

// « Imputer au budget : Coordinateur L, RAF E » (annexe B), et la derogation de
// quantite est un acte du Coordinateur, anterieur et distinct.
$_SESSION['role_projet'] = 'raf';
refuse_avec('Le RAF ne s\'accorde pas la derogation de quantite',
    dossier_deroger_quantite($dos, 'je passe outre'), 'Coordinateur');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Le Coordinateur n\'impute pas, il en a la lecture',
    dossier_imputer($dos, (int)$l21['id'], 1, 20000, 'unite'), 'Responsable Administratif');
refuse_avec('Une derogation sans motif ecrit est refusee',
    dossier_deroger_quantite($dos, ''), 'motif');
$_SESSION['role_projet'] = 'raf';

$res = dossier_imputer($dos, (int)$l21['id'], 1, 20000, 'unite');
cas('Une imputation conforme aboutit', !empty($res['success']), $res['error'] ?? '');
$imp = imputation_dossier($dos);
cas('Le dossier porte son imputation', $imp !== null && abs((float)$imp['montant'] - 20000) < 0.01,
    $imp ? htg((float)$imp['montant']) : '');
cas('Le dossier passe a l\'etat impute', (dossier($dos)['statut'] ?? '') === 'en_concurrence' || (dossier($dos)['statut'] ?? '') === 'impute',
    dossier($dos)['statut'] ?? '');
$res = dossier_imputer($dos, (int)$l21['id'], 2, 20000, 'unite');
cas('Reimputer remplace au lieu d\'ajouter', !empty($res['success'])
    && (int)$pdo->query("SELECT COUNT(*) FROM imputations WHERE dossier_id = $dos")->fetchColumn() === 1);
dossier_imputer($dos, (int)$l21['id'], 1, 20000, 'unite');

$proformaCase = null;
foreach (pieces_dossier($dos) as $p) {
    if ($p['type'] === 'proforma') { $proformaCase = $p; }
}
cas('Une imputation qui franchit le seuil rearme la mise en concurrence',
    $proformaCase !== null && (int)$proformaCase['obligatoire'] === 1,
    $proformaCase['statut'] ?? 'case absente');

echo "\n== Etats du cycle constates sur les pieces\n";
dossier_avancer_sur_piece($dos, 'bon_commande');
cas('Le bon de commande fait passer le dossier a l\'etat commande',
    (dossier($dos)['statut'] ?? '') === 'commande', dossier($dos)['statut'] ?? '');
dossier_avancer_sur_piece($dos, 'bon_reception');
cas('Le bon de reception fait passer le dossier a l\'etat receptionne',
    (dossier($dos)['statut'] ?? '') === 'receptionne', dossier($dos)['statut'] ?? '');

echo "\n== Approbation\n";
$res = dossier_approuver($dos);
cas('Le RAF n\'approuve pas un dossier', empty($res['success']), $res['error'] ?? 'accepte');
$rSoi = dossier_ouvrir(['type' => 'frais_voyage', 'tiers_id' => 1, 'objet' => 'REC4-mes propres frais']);
$dosSoi = (int)($rSoi['id'] ?? 0);
dossier_imputer($dosSoi, (int)$l11['id'], 1, 1000, 'forfait');
$_SESSION['role_projet'] = 'coordinateur';
refuse_avec('Approuver un dossier dont on est le beneficiaire est refuse',
    dossier_approuver($dosSoi), 'suppléant');
$res = dossier_approuver($dos);
cas('Le Coordinateur approuve un dossier dont il n\'est pas beneficiaire', !empty($res['success']), $res['error'] ?? '');

echo "\n== Reglement, seconde etape bloquante\n";
$banque = compte_par_code('BQ');
refuse_avec('Regler avant que le recu soit scanne est refuse',
    dossier_demander_reglement($dos, ['compte_id' => (int)$banque['id']]), 'signé du fournisseur');
$manquantes = dossier_pieces_manquantes($dos, 'avant');
cas('Les pieces prealables au paiement sont nommees', $manquantes !== [], implode(', ', $manquantes));

poser_toutes_pieces($pdo, $dos, 'avant');
refuse_avec('Au-dela du seuil, moins de trois proformas bloque le reglement',
    dossier_demander_reglement($dos, ['compte_id' => (int)$banque['id']]), 'trois proformas');
proforma_ajouter($dos, 4, 22000);
// Une offre a deja ete retenue plus haut : on la libere pour eprouver le cas.
$pdo->prepare('UPDATE proformas SET retenu = 0, motif_choix = NULL WHERE dossier_id = ?')->execute([$dos]);
refuse_avec('Trois offres sans choix arrete bloquent encore',
    dossier_demander_reglement($dos, ['compte_id' => (int)$banque['id']]), 'retenue');
$offres = proformas_dossier($dos);
proforma_retenir((int)$offres[0]['id']);
$res = dossier_demander_reglement($dos, ['compte_id' => (int)$banque['id']]);
cas('Le reglement se demande une fois les pieces et la concurrence reunies',
    !empty($res['success']), $res['error'] ?? '');
$regId = (int)($res['id'] ?? 0);
$reg = reglement($regId);
cas('Le reglement porte le montant impute',
    $reg !== null && abs((float)$reg['montant'] - 20000) < 0.01, $reg ? htg((float)$reg['montant']) : '');
cas('Le reglement porte le beneficiaire du dossier',
    $reg !== null && (int)$reg['beneficiaire_id'] === 2);
cas('Le reglement pointe sur son dossier',
    $reg !== null && $reg['origine_ref'] === 'dossier:' . $dos && $reg['origine_module'] === 'depenses',
    $reg['origine_ref'] ?? '');

echo "\n== Ecart entre le recu et le reglement\n";
poser_piece($pdo, $dos, 'recu_beneficiaire', date('Y-m-d', strtotime('-30 days')));
$alertes = ecart_recu_reglement($dos, date('Y-m-d'));
$delai = param('ecart_recu_reglement_jours');
cas('Un recu vieux de trente jours alerte si le delai est parametre',
    $delai === null ? $alertes === [] : $alertes !== [],
    $delai === null ? 'delai non parametre, aucune alerte' : implode(' ', $alertes));
poser_piece($pdo, $dos, 'recu_beneficiaire', date('Y-m-d', strtotime('+5 days')));
$alertes = ecart_recu_reglement($dos, date('Y-m-d'));
cas('Un recu posterieur au reglement alerte toujours',
    $alertes !== [] && str_contains($alertes[0], 'postérieur'), implode(' ', $alertes));
poser_piece($pdo, $dos, 'recu_beneficiaire');

echo "\n== Cloture\n";
$res = dossier_clore($dos);
cas('Un dossier non regle ne se clot pas', empty($res['success']), $res['error'] ?? 'accepte');

// Le reglement passe par Comptes : deux mandataires distincts du preparateur.
$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC4 Mandataire A', 1)");
$mA = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO tiers (type, nom, est_mandataire) VALUES ('personne', 'REC4 Mandataire B', 1)");
$mB = (int)$pdo->lastInsertId();
reglement_valider($regId, $mA, 'signature_bancaire');
reglement_valider($regId, $mB, 'signature_bancaire');
$res = reglement_executer($regId, date('Y-m-d'));
cas('Le reglement du dossier s\'execute', !empty($res['success']), $res['error'] ?? '');
cas('L\'execution attribue le numero de piece a l\'imputation du dossier',
    preg_match('/^\d{2}-\d{3}$/', (string)($res['numero_piece'] ?? '')) === 1,
    (string)($res['numero_piece'] ?? ''));
cas('Le numero de piece porte la rubrique de la ligne imputee',
    str_starts_with((string)($res['numero_piece'] ?? ''), '02-'), (string)($res['numero_piece'] ?? ''));

dossier_constater_reglement($dos);
cas('Le dossier passe a l\'etat regle', (dossier($dos)['statut'] ?? '') === 'regle', dossier($dos)['statut'] ?? '');
$res = dossier_clore($dos);
cas('Clore un dossier dont une piece posterieure manque est refuse', empty($res['success']), $res['error'] ?? 'accepte');
poser_toutes_pieces($pdo, $dos, 'apres');
$res = dossier_clore($dos);
cas('Le dossier se clot une fois toutes ses pieces reunies', !empty($res['success']), $res['error'] ?? '');
$_SESSION['role_projet'] = 'raf';
$res = dossier_imputer($dos, (int)$l21['id'], 1, 100, 'unite');
cas('Un dossier clos ne se reimpute plus', empty($res['success']), $res['error'] ?? 'accepte');

echo "\n== Abandon\n";
$rAb = dossier_ouvrir(['type' => 'achat_bien', 'tiers_id' => 2, 'objet' => 'REC4-a abandonner']);
$dosAb = (int)($rAb['id'] ?? 0);
dossier_imputer($dosAb, (int)$l21['id'], 1, 5000, 'unite');
refuse_avec('Abandonner sans motif est refuse', dossier_abandonner($dosAb, ''), 'motif');
$res = dossier_abandonner($dosAb, 'Fournisseur en rupture');
cas('Un dossier non regle s\'abandonne', !empty($res['success']), $res['error'] ?? '');
cas('L\'abandon libere l\'imputation', imputation_dossier($dosAb) === null);
cas('Un dossier abandonne ne consomme aucun numero de piece',
    (int)$pdo->query("SELECT COUNT(*) FROM imputations WHERE dossier_id = $dosAb AND numero_piece IS NOT NULL")->fetchColumn() === 0);
$res = dossier_abandonner($dos, 'trop tard');
cas('Un dossier regle ne s\'abandonne pas', empty($res['success']), $res['error'] ?? 'accepte');

echo "\n== Rendu des ecrans\n";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['id'] = $dos;
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
[$r1, $d1] = $rendre('modules/depenses/index.php');
cas('Depenses - liste des dossiers (KESKLE)', $r1, $d1);
[$r2, $d2] = $rendre('modules/depenses/dossier.php');
cas('Depenses - fiche d\'un dossier (KESKLE)', $r2, $d2);
$_SESSION['projet_id'] = 2; $_SESSION['projet_code'] = 'KKP'; param_oublier();
unset($_GET['id']);
[$r3, $d3] = $rendre('modules/depenses/index.php');
cas('Depenses - liste des dossiers (KKP)', $r3, $d3);

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
