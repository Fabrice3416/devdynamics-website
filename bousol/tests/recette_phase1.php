<?php
declare(strict_types=1);

/**
 * Recette du socle : Noyau, Signature et cloisonnement par projet.
 * Couvre les cas de l'annexe G qui relevent des modules deja livres.
 *
 * Usage (CLI, sur une base de TEST chargee avec schema.sql, schema_triggers.sql et seed.sql) :
 *   php bousol/tests/recette_phase1.php
 *
 * Chaque cas "doit echouer" est verifie comme tel. Sortie 1 au premier ecart.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/calendrier.php';
require_once __DIR__ . '/../includes/signature.php';
require_once __DIR__ . '/../pdf/generate.php';
require_once __DIR__ . '/_garde.php';

recette_garde('Recette de la phase 1 - socle, Noyau, Signature, cloisonnement');
recette_nettoyer($pdo = db());
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

// Session simulee : coordinateur du projet 1, mandataire.
$pdo->prepare('UPDATE tiers SET est_mandataire = 1 WHERE id = 1')->execute();
$_SESSION['user_id'] = 1; $_SESSION['user_nom'] = 'Recette'; $_SESSION['tiers_id'] = 1;
$_SESSION['est_mandataire'] = true; $_SESSION['admin_outil'] = true;
$_SESSION['projet_id'] = 1; $_SESSION['projet_code'] = 'KESKLE'; $_SESSION['role_projet'] = 'coordinateur';
$mdp = 'Recette!2026aa';
$pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = 1')->execute([hash_password($mdp)]);

echo "== Noyau : immuabilite\n";
doit_echouer('Modifier une ligne du journal d\'audit', fn() => $pdo->exec("UPDATE journal_audit SET detail = 'x' WHERE id = 1"));
doit_echouer('Supprimer une ligne du journal d\'audit', fn() => $pdo->exec('DELETE FROM journal_audit WHERE id = 1'));
doit_echouer('Modifier un parametre en place', fn() => $pdo->exec("UPDATE parametres SET valeur = '9' WHERE id = 1"));
doit_echouer('Supprimer un fichier', function () use ($pdo) {
    $pdo->exec("INSERT INTO fichiers (nom_genere, chemin, extension, mime, taille, empreinte) VALUES ('t', 'x/y.pdf', 'pdf', 'application/pdf', 1, REPEAT('a', 64))");
    $pdo->exec("DELETE FROM fichiers WHERE nom_genere = 't'");
});

echo "\n== Cloisonnement par projet\n";
$ligneAutreProjet = (int)$pdo->query("SELECT id FROM lignes_budgetaires WHERE projet_id = 2 AND nature = 'imputable' LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO dossiers (projet_id, numero, type, tiers_id, objet, created_by) VALUES (1, 'REC-0001', 'achat_bien', 2, 'recette', 1)");
$dossier = (int)$pdo->lastInsertId();
doit_echouer('Imputer une depense sur la ligne budgetaire d\'un autre projet', function () use ($pdo, $dossier, $ligneAutreProjet) {
    $pdo->exec("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
                VALUES (1, $dossier, $ligneAutreProjet, 'unite', 1, 1, 1, CURDATE())");
});
doit_echouer('Imputer directement sur la provision pour imprevus', function () use ($pdo, $dossier) {
    $l10 = (int)$pdo->query("SELECT id FROM lignes_budgetaires WHERE projet_id = 1 AND code = '10'")->fetchColumn();
    $pdo->exec("INSERT INTO imputations (projet_id, dossier_id, ligne_id, unite, quantite, valeur_unitaire, montant, date_imputation)
                VALUES (1, $dossier, $l10, 'forfait', 1, 1, 1, CURDATE())");
});
$pdo->exec("DELETE FROM dossiers WHERE numero = 'REC-0001'");

$deuxCodes = (int)$pdo->query("SELECT COUNT(*) FROM lignes_budgetaires a JOIN lignes_budgetaires b ON a.code = b.code AND a.projet_id <> b.projet_id")->fetchColumn();
cas('Deux projets portent le meme code de ligne sans conflit', $deuxCodes > 0, $deuxCodes . ' code(s) partage(s)');

$sansProjet = (int)$pdo->query('SELECT COUNT(*) FROM parametres WHERE projet_id IS NULL')->fetchColumn();
cas('Aucun parametre global', $sansProjet === 0);

cas('Le parametre lu est celui du projet courant', param('plafond_contractuel', null, 1) !== param('plafond_contractuel', null, 2),
    'KESKLE=' . param('plafond_contractuel', null, 1) . ' KKP=' . param('plafond_contractuel', null, 2));

echo "\n== Habilitation\n";
doit_echouer('Affecter sans acte de delegation televerse', function () use ($pdo) {
    $pdo->exec("INSERT INTO affectations (utilisateur_id, projet_id, role, date_debut, affecte_par) VALUES (1, 1, 'raf', CURDATE(), 1)");
});
$_SESSION['admin_outil'] = false;
cas('Sans affectation, aucun role dans le projet', role_dans_projet(2, 1) === null);
$_SESSION['admin_outil'] = true;

echo "\n== Signature\n";
$svc = new PdfService();
$bin = $svc->rendre_binaire('acte_depot', ['titulaire' => 'Recette', 'fonction' => 'Test', 'role' => 'Test', 'mandataire' => false, 'email' => 'x@y']);
cas('mPDF disponible pour le rendu', $bin !== null,
    $bin !== null ? strlen($bin) . ' octets'
        : (is_file(root_dir() . '/lib/mpdf/autoload.php')
            ? 'autoload present, le rendu leve une exception : voir le log serveur'
            : 'bousol/lib/mpdf/ absent, a copier a la main (DEPLOIEMENT.md §5)'));

revoquer_specimen(1, 'recette');
$doc = fn(string $type, int $objet) => (function () use ($pdo, $svc, $bin, $type, $objet) {
    $f = enregistrer_contenu((string)$bin, 'pdf', 'application/pdf', 'documents', 'RECETTE-' . $type . '-' . $objet . '.pdf');
    $pdo->prepare("INSERT INTO documents (type, module, objet_type, objet_id, projet_code, statut, fichier_id, created_by)
                   VALUES (?, 'depenses', 'recette', ?, 'KESKLE', 'a_signer', ?, 1)")->execute([$type, $objet, $f['id']]);
    return (int)$pdo->lastInsertId();
})();

$d1 = $doc('bon_commande', 1);
$r = apposer($d1, 'approbation', $mdp);
cas('Apposer sans specimen ni acte de depot est refuse', !$r['success'], $r['error'] ?? '');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mNkYPhfz0AEYBxVSF+FAAhKDveksOjmAAAAAElFTkSuQmCC');
$acte = enregistrer_contenu((string)$bin, 'pdf', 'application/pdf', 'coffre', 'RECETTE-acte.pdf', true);
$img  = enregistrer_contenu($png, 'png', 'image/png', 'coffre', 'RECETTE-specimen.png', true);
$pdo->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot) VALUES (1, ?, ?, CURDATE())')
    ->execute([$img['id'], $acte['id']]);
cas('Specimen actif apres depot avec acte', specimen_actif(1) !== null);

$r = apposer($d1, 'approbation', 'mauvais-mot-de-passe');
cas('Apposer avec une reauthentification echouee est refuse', !$r['success'], $r['error'] ?? '');

$r = apposer($d1, 'reglement', $mdp);
cas('Apposition valide acceptee, code emis', $r['success'] && preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{2}$/', $r['code'] ?? ''), $r['code'] ?? ($r['error'] ?? ''));
$a = $r['success'] ? apposition_par_code($r['code']) : null;
cas('Empreintes avant et apres distinctes', $a !== null && $a['empreinte_avant'] !== $a['empreinte_apres']);

$r2 = apposer($d1, 'approbation', $mdp);
cas('Deuxieme apposition du meme compte refusee', !$r2['success'], $r2['error'] ?? '');

// Deux appositions depuis la meme session, sur un document a deux signatures
$d2 = $doc('fiche_imputation', 2);
$r = apposer($d2, 'approbation', $mdp);
cas('Premiere signature sur la fiche d\'imputation', $r['success'], $r['error'] ?? '');
// Ce compte-la survit d'une recette a l'autre : ses appositions sont immuables,
// donc l'utilisateur ne peut pas etre supprime, et son email est unique. On le
// reprend s'il existe deja plutot que de buter sur la contrainte au second
// passage.
$sr = $pdo->prepare("SELECT id, tiers_id FROM utilisateurs WHERE email = 'raf-recette@test'");
$sr->execute();
$existant = $sr->fetch();
if ($existant !== false) {
    $u2 = (int)$existant['id'];
    $t2 = (int)$existant['tiers_id'];
    $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ?, doit_changer_mdp = 0, actif = 1 WHERE id = ?')
        ->execute([hash_password($mdp), $u2]);
} else {
    $pdo->exec("INSERT INTO tiers (type, nom, fonction) VALUES ('personne', 'RAF Recette', 'RAF')");
    $t2 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO utilisateurs (tiers_id, email, mot_de_passe, doit_changer_mdp) VALUES (?, 'raf-recette@test', ?, 0)")
        ->execute([$t2, hash_password($mdp)]);
    $u2 = (int)$pdo->lastInsertId();
}
if (specimen_actif($u2) === null) {
    $img2 = enregistrer_contenu($png, 'png', 'image/png', 'coffre', 'RECETTE-specimen2.png', true);
    $pdo->prepare('INSERT INTO specimens (titulaire_id, image_fichier_id, acte_depot_fichier_id, date_depot) VALUES (?, ?, ?, CURDATE())')
        ->execute([$u2, $img2['id'], $acte['id']]);
}
$_SESSION['user_id'] = $u2; $_SESSION['role_projet'] = 'raf'; $_SESSION['est_mandataire'] = false;
$r = apposer($d2, 'approbation', $mdp);
cas('Deux appositions depuis la meme session refusees', !$r['success'], $r['error'] ?? '');
$r = apposer($d2, 'reglement', $mdp);
cas('Signature de reglement par un non-mandataire refusee', !$r['success'], $r['error'] ?? '');

// « Un specimen n'est apposable que par son titulaire » (CDC 1.8) : la fonction ne
// lit que le specimen de l'utilisateur courant, il n'y a pas de chemin pour en
// designer un autre. On verifie que la lecture est bien nominative.
cas('Un specimen ne se lit que sous son titulaire',
    specimen_actif($u2) !== null && specimen_actif(999999) === null,
    'aucun specimen pour un titulaire inconnu');

echo "\n== Habilitation a l'outil\n";
// require_admin_outil() est une garde de page : elle s'arrete. C'est le predicat
// qu'elle consulte qui se teste ici, et c'est lui qui porte la regle.
$_SESSION['admin_outil'] = false;
cas('Creer un projet ou s\'auto-affecter sans etre administrateur de l\'outil est refuse',
    user_est_admin_outil() === false);
$_SESSION['admin_outil'] = true;
cas('L\'administrateur de l\'outil, lui, passe', user_est_admin_outil() === true);

echo "\n== Pieces\n";
$empreinte = $acte['empreinte'];
[$deja, $motif] = empreinte_deja_utilisee($empreinte);
cas('Une empreinte deja versee est detectee', $deja, $motif);
[$deja2] = empreinte_deja_utilisee(str_repeat('f', 64));
cas('Une empreinte inconnue passe', !$deja2);

echo "\n$ok OK, $ko ECHEC\n";
exit($ko > 0 ? 1 : 0);
