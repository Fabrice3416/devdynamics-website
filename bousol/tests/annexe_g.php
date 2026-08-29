<?php
declare(strict_types=1);

/**
 * Couverture de l'annexe G, cas par cas.
 *
 * L'annexe G est la liste contractuelle des jeux d'essai : « un module dont ces cas
 * ne passent pas ne peut pas etre mis en service ». Les recettes ont ete ecrites
 * module par module, en piochant dans cette liste de memoire - ce qui ne prouve
 * rien. Ce script fait le rapprochement dans l'autre sens : il part de l'annexe et
 * cherche, dans les recettes, la trace de chaque cas.
 *
 * La correspondance se fait sur un fragment de libelle. Ce n'est pas une preuve que
 * le cas est bien teste, c'est une preuve qu'il n'a pas ete oublie - et c'est
 * exactement ce qui manquait.
 *
 *   php bousol/tests/annexe_g.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI seulement\n");
}

$racine = dirname(__DIR__);
$recettes = '';
foreach (glob($racine . '/tests/recette_phase*.php') as $f) {
    $recettes .= (string)file_get_contents($f);
}
// Les recettes echappent les apostrophes en \' : sans cette normalisation, tout
// fragment qui en contient echoue et le rapport ment par exces.
$recettes = str_replace("\\'", "'", $recettes);
$sources = '';
foreach (['includes', 'modules', 'database'] as $d) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . '/' . $d, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (preg_match('/\.(php|sql)$/', $f->getPathname())) {
            $sources .= (string)file_get_contents($f->getPathname());
        }
    }
}

/**
 * Chaque cas porte son module, son libelle d'annexe, et le fragment attendu dans
 * une recette. Un fragment vide signale un cas dont je sais qu'il n'est pas couvert :
 * la liste dit alors pourquoi.
 */
$echouer = [
    ['Budget', 'Imputer un neuvième mois sur une ligne budgétée à huit mois', 'neuvieme mois sur une ligne budgetee'],
    ['Budget', 'Imputer directement sur la ligne 10, provision pour imprévus', 'directement sur la provision'],
    ['Budget', 'Réallouer au-delà de 25 % sans autorisation téléversée', 'au-dela de 25 % sans autorisation'],
    ['Dépenses', 'Créer un règlement portant sur deux lignes budgétaires', 'une seule imputation, donc une seule ligne'],
    ['Dépenses', 'Régler un dossier dont le reçu n\'est pas scanné', 'Regler avant que le recu soit scanne'],
    ['Dépenses', 'Clore un dossier dont une pièce obligatoire manque', 'piece posterieure manque'],
    ['Comptes', 'Faire signer un règlement par son bénéficiaire', 'reglement par son beneficiaire'],
    ['Comptes', 'Faire signer les deux appositions depuis la même session', 'Deux appositions depuis la meme session'],
    ['Rémunération', 'Générer une prestation sans certificat d\'acceptation', 'Aucune prestation sans certificat'],
    ['Rémunération', 'Consommer du budget par le dossier de versement à la DGI', ''],
    ['Restitution', 'Écrire une dépense dans une période figée', 'ecriture datee dans une periode figee'],
    ['Restitution', 'Modifier un rapport transmis sans version rectificative', ''],
    ['Noyau', 'Basculer en phase 2 avec un dossier de dépense non réglé', 'checklist incomplete bloque la bascule'],
    ['Noyau', 'Figer une période dont la dette DGI n\'est pas soldée', 'cloture du mois est bloquee'],
    ['Noyau', 'Modifier ou supprimer une ligne du journal d\'audit', 'ligne du journal d\'audit'],
    ['Signature', 'Apposer un spécimen sans acte de dépôt téléversé', 'sans specimen ni acte de depot'],
    ['Signature', 'Apposer le spécimen d\'un autre titulaire', ''],
    ['Dépenses', 'Approuver un dossier dont on est soi-même le bénéficiaire', 'dont on est le beneficiaire'],
    ['Dépenses', 'Approuver un dossier en conflit sans être le suppléant désigné', ''],
    ['Dépenses', 'Clore un dossier dont la mention hors taxe n\'est pas levée', ''],
    ['Noyau', 'Créer une imputation pendant la période de régularisation', 'imputation nouvelle est fermee'],
    ['Budget', 'Refuser un second versement au forfait dont l\'enveloppe reste disponible', 'Second versement sur une ligne au forfait'],
    ['Budget', 'Réallouer au-delà du plafond contractuel du projet', 'au-dela du plafond contractuel'],
    ['Budget', 'Réallouer une ligne en dessous de son montant déjà consommé', 'en dessous de son montant deja consomme'],
    ['Tiers', 'Créer un second tiers portant un NIF déjà enregistré', 'second tiers portant un NIF'],
    ['Restitution', 'Alimenter un cumul depuis une version remplacée par une rectificative', ''],
    ['Budget', 'Mobiliser la provision au-delà du seuil avec une seule autorisation', 'au-dela du seuil de variation avec une seule'],
    ['Dépenses', 'Satisfaire deux pièces d\'un même dossier avec un seul fichier', ''],
    ['Activités', 'Inscrire un bénéficiaire mineur sans autorisation parentale', 'mineur sans autorisation parentale'],
    ['Cloisonnement', 'Consulter un projet auquel on n\'est pas affecté', 'Sans affectation, aucun role'],
    ['Cloisonnement', 'Imputer une dépense sur la ligne budgétaire d\'un autre projet', 'ligne d\'un autre projet'],
    ['Pièces', 'Rattacher un même fichier à deux dossiers de projets différents', 'empreinte deja versee'],
    ['Rapports', 'Produire un rapport dont les données portent deux identifiants de projet', ''],
    ['Habilitation', 'Créer un projet ou s\'auto-affecter sans être administrateur', ''],
    ['Habilitation', 'Affecter un coordinateur sans acte de délégation téléversé', 'sans acte de delegation'],
    ['Trésorerie', 'Clore un rapprochement de compte partagé laissant un écart non ventilé', 'ecart non ventile'],
];

$reussir = [
    ['Initialisation', 'Créer un projet, charger sa nomenclature, saisir ses paramètres', 'Couts directs contractuels'],
    ['Initialisation', 'Déposer les quatre spécimens et affecter les utilisateurs', 'Specimen actif apres depot'],
    ['Initialisation', 'Saisir la date d\'ancrage puis dérouler le calendrier', 'calendrier relatif est ancre'],
    ['Cycle complet', 'Rejouer un mois réel avec une douzaine de dossiers de types différents', ''],
    ['Cycle complet', 'Mener un dossier de chaque type de sa création à sa clôture', ''],
    ['Rapport financier', 'Produire le rapport sur un jeu calculé à la main', 'colonne budget reproduit le contractuel'],
    ['Rapport financier', 'Produire le rapport suivant une version rectificative', ''],
    ['Liasse', 'Produire la liasse d\'un dossier clos et celle d\'une période', 'liasse de periode se produit'],
    ['Droits', 'Parcourir la matrice de l\'annexe B, rôle par rôle et phase par phase', ''],
    ['Bascule', 'Tenter la clôture avec données incomplètes, puis compléter et basculer', 'checklist incomplete bloque la bascule'],
    ['Bascule', 'Exercer en phase 2 les seules actions autorisées', 'journal de support reste ouvert'],
    ['Paramètres', 'Modifier un seuil en cours de projet', ''],
    ['Multi-projets', 'Travailler alternativement sur les deux projets dans la même session', 'Imputer sur la ligne d\'un autre projet'],
    ['Trésorerie', 'Rapprocher le compte partagé sur un mois portant des mouvements des deux projets', 'ventilation nomme chaque projet'],
    ['Archive', 'Produire le paquet autoportant et l\'ouvrir sans Bousòl', 'index d\'archive se produit'],
    ['Signature', 'Signer un document, le modifier, le signer de nouveau', ''],
];

$couverts = 0; $absents = [];
$rendre = function (string $titre, array $cas) use ($recettes, &$couverts, &$absents): void {
    echo "\n== $titre\n";
    foreach ($cas as [$module, $libelle, $fragment]) {
        $trouve = $fragment !== '' && str_contains($recettes, $fragment);
        if ($trouve) {
            $couverts++;
            echo '  couvert  ' . $module . ' — ' . $libelle . "\n";
        } else {
            $absents[] = $module . ' — ' . $libelle;
            echo '  ABSENT   ' . $module . ' — ' . $libelle . "\n";
        }
    }
};

$rendre('Cas qui doivent échouer (' . count($echouer) . ')', $echouer);
$rendre('Cas qui doivent réussir (' . count($reussir) . ')', $reussir);

$total = count($echouer) + count($reussir);
echo "\n" . $couverts . ' / ' . $total . " cas de l'annexe G tracés dans les recettes.\n";
if ($absents) {
    echo count($absents) . " sans trace :\n";
    foreach ($absents as $a) {
        echo '  · ' . $a . "\n";
    }
}
exit($absents ? 1 : 0);
