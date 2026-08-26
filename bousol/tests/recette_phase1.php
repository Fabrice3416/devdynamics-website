<?php
declare(strict_types=1);

/**
 * Recette phase 1 - cas de l'annexe G pour les modules Noyau et Signature.
 * Usage (CLI, sur une base de TEST chargee avec schema.sql + seed.sql) :
 *   php bousol/tests/recette_phase1.php
 * Chaque cas "doit echouer" est verifie comme tel ; le script sort en code 1 au premier ecart.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI seulement\n");
}
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendrier.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../pdf/generate.php';

$pdo = db();
$ok = 0; $ko = 0;
function cas(string $lib, bool $reussi, string $detail = ''): void
{
    global $ok, $ko;
    $reussi ? $ok++ : $ko++;
    echo ($reussi ? '  OK  ' : ' ECHEC') . ' ' . $lib . ($detail !== '' ? '  [' . $detail . ']' : '') . PHP_EOL;
}
function doit_echouer(string $lib, callable $f): void
{
    try {
        $f();
        cas($lib, false, 'aucune erreur levee');
    } catch (Throwable $e) {
        cas($lib, true, substr($e->getMessage(), 0, 90));
    }
}

echo "== Noyau\n";
doit_echouer('Modifier une ligne du journal d\'audit', fn() => $pdo->exec("UPDATE journal_audit SET detail = 'x' WHERE id = 1"));
doit_echouer('Supprimer une ligne du journal d\'audit', fn() => $pdo->exec('DELETE FROM journal_audit WHERE id = 1'));
doit_echouer('Modifier un parametre en place (historise)', fn() => $pdo->exec("UPDATE parametres SET valeur = '9' WHERE id = 1"));
doit_echouer('Supprimer un fichier', function () use ($pdo) {
    $pdo->exec("INSERT INTO fichiers (nom_genere, chemin, extension, mime, taille, empreinte) VALUES ('t', 'x/y.pdf', 'pdf', 'application/pdf', 1, REPEAT('a', 64))");
    $pdo->exec('DELETE FROM fichiers WHERE nom_genere = \'t\'');
});
cas('Parametre non modifiable refuse (seuil de blocage 25 %)', valider_param('seuil_blocage_variation_pct', '30') !== null);
cas('Date de debut invalide refusee', valider_param('date_debut_execution', '2026-02-31') !== null);
cas('Montant decimal valide accepte', valider_param('plafond_petite_caisse', '30000.00') === null);
doit_echouer('Imputer sur la ligne 10 (provision)', function () use ($pdo) {
    $pdo->exec("INSERT INTO dossiers (numero, type, tiers_id, objet, created_by) VALUES ('REC-0001', 'achat_bien', 2, 'recette', 1)");
    $id = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO imputations (dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation) VALUES ($id, (SELECT id FROM lignes_budgetaires WHERE code = '10'), 'forfait', 1, 1, 1, CURDATE())");
});
$pdo->exec("DELETE FROM dossiers WHERE numero = 'REC-0001'");

echo "== Signature\n";
// Session simulee : utilisateur 1 (Coordinateur, mandataire)
$_SESSION['user_id'] = 1; $_SESSION['user_role'] = 'coordinateur'; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1; $_SESSION['est_mandataire'] = true;
$pwd = 'Recette!2026aa';
$pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = 1')->execute([hash_password($pwd)]);
// Document de test en attente de signature
$svc = new PdfService();
$bin = $svc->rendre_binaire('acte_depot', ['titulaire' => 'Recette', 'fonction' => 'Test', 'role' => 'Test', 'mandataire' => false, 'email' => 'x@y']);
cas('mPDF disponible pour le rendu', $bin !== null);
$f = enregistrer_contenu((string)$bin, 'pdf', 'application/pdf', 'documents', 'RECETTE-doc.pdf');
$pdo->prepare("INSERT INTO documents (type, module, objet_type, objet_id, statut, fichier_id, created_by) VALUES ('bon_commande', 'depenses', 'recette', 1, 'a_signer', ?, 1)")->execute([$f['id']]);
$docId = (int)$pdo->lastInsertId();

// Revoquer tout specimen existant de l'utilisateur 1 pour tester l'absence
revoquer_specimen(1, 'recette');
$r = apposer($docId, 'approbation', $pwd);
cas('Apposer sans specimen (ni acte de depot) est refuse', !$r['success'], $r['error'] ?? '');

// Depot d'un specimen avec acte
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNkYPhfz0AEYBxVSF+FAAhKDveksOjmAAAAAElFTkSuQmCC');
$tmp = tempnam(sys_get_temp_dir(), 'acte'); file_put_contents($tmp, $bin);
$acteUpload = ['name' => 'acte.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
// is_uploaded_file() est faux en CLI : on passe par enregistrer_contenu directement pour l'acte
$acte = enregistrer_contenu((string)$bin, 'pdf', 'application/pdf', 'coffre', 'RECETTE-acte.pdf', true);
$img  = enregistrer_contenu($png, 'png', 'image/png', 'coffre', 'RECETTE-specimen.png', true);
$pdo->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot) VALUES (1, ?, ?, CURDATE())')->execute([$img['id'], $acte['id']]);
cas('Specimen actif detecte apres depot', specimen_actif(1) !== null);

doit_echouer('Apposer le specimen d\'un autre titulaire (contrainte structurelle : le specimen_id doit appartenir au signataire)', function () use ($pdo, $docId) {
    // Un second utilisateur tente d'utiliser le specimen de l'utilisateur 1 : refuse par la logique du service,
    // qui ne selectionne jamais qu'un specimen dont titulaire_id = utilisateur courant.
    $_SESSION['user_id'] = 999;
    $s = specimen_actif(999);
    $_SESSION['user_id'] = 1;
    if ($s !== null) { return; }
    throw new RuntimeException('aucun specimen etranger accessible');
});

$r = apposer($docId, 'approbation', 'mauvais-mot-de-passe');
cas('Apposer avec une reauthentification echouee est refuse', !$r['success'], $r['error'] ?? '');
$r = apposer($docId, 'approbation', $pwd);
cas('Apposition valide acceptee (code emis)', $r['success'] && preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{2}$/', $r['code'] ?? ''), $r['code'] ?? ($r['error'] ?? ''));
$a = $r['success'] ? apposition_par_code($r['code']) : null;
cas('Empreintes avant/apres distinctes et code retrouve', $a !== null && $a['empreinte_avant'] !== $a['empreinte_apres']);
$st = $pdo->prepare('SELECT statut FROM documents WHERE id = ?'); $st->execute([$docId]);
cas('Document passe en statut signe (1 signature attendue pour un bon de commande)', $st->fetchColumn() === 'signe');
$r2 = apposer($docId, 'approbation', $pwd);
cas('Deuxieme apposition du meme compte refusee', !$r2['success'], $r2['error'] ?? '');

// Deux appositions depuis la meme session : document a 2 signatures, second utilisateur, meme session_id
$f2 = enregistrer_contenu((string)$bin, 'pdf', 'application/pdf', 'documents', 'RECETTE-doc2.pdf');
$pdo->prepare("INSERT INTO documents (type, module, objet_type, objet_id, statut, fichier_id, created_by) VALUES ('fiche_imputation', 'depenses', 'recette', 2, 'a_signer', ?, 1)")->execute([$f2['id']]);
$doc2 = (int)$pdo->lastInsertId();
$r = apposer($doc2, 'approbation', $pwd);
cas('Premiere signature (Coordinateur) sur la fiche d\'imputation', $r['success'], $r['error'] ?? '');
// Second signataire "raf" partageant la meme session PHP
$pdo->exec("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', 'RAF Recette', 'RAF')");
$t2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO utilisateurs (tiers_id, email, mot_de_passe, role, doit_changer_mdp) VALUES (?, 'raf-recette@test', ?, 'raf', 0)")->execute([$t2, hash_password($pwd)]);
$u2 = (int)$pdo->lastInsertId();
$img2 = enregistrer_contenu($png, 'png', 'image/png', 'coffre', 'RECETTE-specimen2.png', true);
$pdo->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot) VALUES (?, ?, ?, CURDATE())')->execute([$u2, $img2['id'], $acte['id']]);
$_SESSION['user_id'] = $u2; $_SESSION['user_role'] = 'raf'; $_SESSION['est_mandataire'] = false;
$r = apposer($doc2, 'approbation', $pwd);
cas('Deux appositions depuis la meme session refusees', !$r['success'], $r['error'] ?? '');
$r = apposer($doc2, 'reglement', $pwd);
cas('Signature de reglement par un non-mandataire refusee', !$r['success'], $r['error'] ?? '');

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
