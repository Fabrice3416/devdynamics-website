<?php
declare(strict_types=1);

/**
 * Controle de conformite statique, a lancer avant d'annoncer un module livre.
 *
 * Il ne lit aucune base et ne teste aucun comportement : il traque les trois
 * familles d'ecart que les recettes ne voient pas, parce qu'une recette verifie ce
 * que le code fait, jamais ce qu'il aurait du faire.
 *
 *   1. Une fonction de bibliotheque que personne n'appelle. Ecrire le controle et
 *      oublier de l'appeler laisse une regle qui ne bloque plus rien - et la
 *      recette, si elle appelle la fonction directement, passe au vert.
 *   2. Une valeur d'ENUM du schema que le code n'ecrit jamais. Un etat prevu au
 *      modele et jamais pose est une etape du cycle qui n'existe pas.
 *   3. Un parametre de l'annexe F que personne ne lit. Le Coordinateur le saisit,
 *      et il ne sert a rien.
 *
 * Chaque exception legitime se declare dans son allowlist, avec sa raison. C'est
 * la declaration qui compte : elle oblige a dire pourquoi, ce qui est exactement
 * le controle qui manquait.
 *
 *   php bousol/tests/conformite.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI seulement\n");
}

$racine = dirname(__DIR__);

/** Le code applicatif, hors bibliotheques tierces et hors recettes. */
function sources(string $racine): array
{
    $fichiers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $chemin = $f->getPathname();
        if (!str_ends_with($chemin, '.php')) {
            continue;
        }
        if (str_contains($chemin, '/lib/') || str_contains($chemin, '/tests/')) {
            continue;
        }
        $fichiers[] = $chemin;
    }
    sort($fichiers);
    return $fichiers;
}

$sources = sources($racine);
$corpus = [];
foreach ($sources as $f) {
    $corpus[$f] = (string)file_get_contents($f);
}

$ecarts = 0;
$note = function (string $titre, array $items, array $allowlist): void {
    global $ecarts;
    echo "\n== $titre\n";
    $restants = [];
    foreach ($items as $cle => $detail) {
        if (isset($allowlist[$cle])) {
            echo '  toléré  ' . $cle . '  [' . $allowlist[$cle] . "]\n";
            continue;
        }
        $restants[$cle] = $detail;
    }
    foreach ($restants as $cle => $detail) {
        echo '  ÉCART   ' . $cle . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
        $ecarts++;
    }
    if (!$restants) {
        echo "  aucun écart\n";
    }
};

// ---------------------------------------------------------------------
// 1. Fonctions de bibliotheque sans appelant applicatif
// ---------------------------------------------------------------------

$sansAppelant = [];
foreach ($sources as $f) {
    if (!str_contains($f, '/includes/')) {
        continue;
    }
    if (!preg_match_all('/^function\s+([a-z_][a-z0-9_]*)\s*\(/mi', $corpus[$f], $m)) {
        continue;
    }
    foreach ($m[1] as $nom) {
        $appels = 0;
        foreach ($corpus as $autre => $texte) {
            $n = preg_match_all('/(?<![a-z0-9_$>])' . preg_quote($nom, '/') . '\s*\(/i', $texte);
            if ($autre === $f) {
                $n -= 1;   // sa propre declaration
            }
            $appels += max(0, $n);
        }
        if ($appels === 0) {
            $sansAppelant[$nom] = basename($f);
        }
    }
}

$note('Fonctions de bibliothèque sans appelant applicatif', $sansAppelant, [
    // Les ecritures types attendent le module qui les emettra. Elles sont ecrites
    // et recettees des maintenant parce que la partie double doit tenir d'un seul
    // tenant, pas au fil des modules.
    'ecriture_encaissement_tranche' => 'attend Financement, phase 7',
    'numero_piece_suivant'          => 'appelée par reglement_numeroter_piece',
    // Deux fonctions du socle qui attendent leur module. Le rendu documentaire
    // n'a aucune donnee propre et n'est pas un module (CDC 7.2) : il sera cable
    // par Depenses et Restitution, qui produisent les documents de l'annexe E.
    'creer_document'                => 'attend la génération des documents de l\'annexe E',
    'decoder_png_base64'            => 'attend la capture de spécimen au doigt, régime électronique',
    'audit_ecrire'                  => 'appelée par audit() et audit_strict()',
    'recette_garde'                 => 'appelée par les recettes seules',
    'recette_nettoyer'              => 'appelée par les recettes seules',
    'sources'                       => 'ce fichier',
]);

// ---------------------------------------------------------------------
// 2. Valeurs d'ENUM du schema que le code n'ecrit jamais
// ---------------------------------------------------------------------

$schema = (string)file_get_contents($racine . '/database/schema.sql');
$seed   = (string)file_get_contents($racine . '/database/seed.sql');

/**
 * Les modules livres. Un etat prevu au modele et jamais pose n'est un ecart que
 * si son module est cense fonctionner : ailleurs, c'est du modele en avance sur
 * le code, ce que le CDC assume. Cette liste s'allonge a chaque phase, et c'est
 * elle qui fait entrer un module dans le perimetre du controle.
 */
const MODULES_LIVRES = ['NOYAU', 'SIGNATURE', 'TIERS', 'BUDGET', 'COMPTES', 'DEPENSES', 'REMUNERATION'];

// Chaque table appartient a la section de schema.sql qui la precede.
$tablesSurveillees = [];
$moduleCourant = null;
foreach (explode("\n", $schema) as $ligne) {
    if (preg_match('/^--\s*MODULE\s+([A-Z]+)/', $ligne, $m)) {
        $moduleCourant = $m[1];
    } elseif (preg_match('/^CREATE TABLE (\w+)/', $ligne, $m) && in_array((string)$moduleCourant, MODULES_LIVRES, true)) {
        $tablesSurveillees[$m[1]] = $moduleCourant;
    }
}

$jamais = [];
$tableCourante = null;
foreach (explode("\n", $schema) as $ligne) {
    if (preg_match('/^CREATE TABLE (\w+)/', $ligne, $m)) {
        $tableCourante = $m[1];
        continue;
    }
    if ($tableCourante === null || !isset($tablesSurveillees[$tableCourante])) {
        continue;
    }
    if (!preg_match("/^\s*(\w+)\s+ENUM\(([^)]+)\)/i", $ligne, $m)) {
        continue;
    }
    [$tout, $colonne, $valeurs] = $m;
    preg_match_all("/'([^']+)'/", $valeurs, $vm);
    foreach ($vm[1] as $valeur) {
        $trouve = str_contains($seed, "'" . $valeur . "'");
        foreach ($corpus as $texte) {
            if ($trouve) {
                break;
            }
            $trouve = str_contains($texte, "'" . $valeur . "'") || str_contains($texte, '"' . $valeur . '"');
        }
        if (!$trouve) {
            $jamais[$tableCourante . '.' . $colonne . ' = ' . $valeur] = $tablesSurveillees[$tableCourante];
        }
    }
}

$note('Valeurs d\'ENUM jamais écrites, dans les tables des modules livrés', $jamais, [
    // Tout le vocabulaire de la cloture, du figement et de la bascule appartient a
    // Restitution et a la phase 8 : le modele est en avance sur le code, ce que le
    // CDC assume puisqu'il decrit le cycle de vie complet des le depart.
    'phases.statut = close'            => 'clôture de phase, attend la bascule',
    'periodes.statut = en_cloture'     => 'clôture de période, attend Restitution',
    'periodes.statut = figee'          => 'figement de période, attend Restitution',
    'documents.statut = fige'          => 'figement d\'un document, attend Restitution',
    'documents.statut = remplace'      => 'version remplacée, attend Restitution',
    'reouvertures.statut = close'      => 'réouverture après bascule, attend la phase 8',
    'projets.statut = creation'        => 'un projet naît actif ; l\'état intermédiaire attend un besoin réel',
    'projets_comptes.role = secondaire' => 'second compte bancaire par projet, aucun bailleur ne l\'impose à ce jour',
    'contrats.type = travail'    => 'contrat de travail, type désactivé par le CDC 4.2',
    'contrats.statut = suspendu' => 'suspension d\'un contrat, prévue au modèle, sans écran à ce stade',
    'imputations.nature = memoire' => 'imputation pour mémoire, attend le versement DGI de Rémunération',
    'contrats.autorite_acceptation = assemblee_generale' => 'acceptation du rapport du Coordinateur, attend Rémunération',
]);

// ---------------------------------------------------------------------
// 3. Parametres de l'annexe F que personne ne lit
// ---------------------------------------------------------------------

require_once $racine . '/includes/referentiels.php';
$nonLus = [];
foreach (array_keys(PARAMETRES_REGISTRE) as $cle) {
    $lu = false;
    foreach ($corpus as $f => $texte) {
        if (str_contains($f, 'referentiels.php')) {
            continue;
        }
        if (str_contains($texte, "'" . $cle . "'")) {
            $lu = true;
            break;
        }
    }
    if (!$lu) {
        $nonLus[$cle] = PARAMETRES_REGISTRE[$cle][0];
    }
}

$note('Paramètres de l\'annexe F que personne ne lit', $nonLus, [
    'exemplaires_mention'           => 'attend l\'impression des documents, Restitution',
    'delai_accuse_phase2_heures'    => 'attend la phase de suivi post-clôture',
    'delai_correctif_phase2_jours'  => 'attend la phase de suivi post-clôture',
    'representant_legal'            => 'attend les documents générés qui le nomment',
    'seuil_concurrence_devise'      => 'attend la conversion du seuil FOKAL en gourdes',
    'duree_regularisation_jours'    => 'attend la bascule, phase 8',
    'regime_signature_defaut'       => 'lu par le module Signature à la livraison du régime électronique',
]);

echo "\n" . ($ecarts === 0
    ? "Aucun écart. Le module peut être annoncé livré.\n"
    : $ecarts . " écart(s) à traiter ou à justifier dans l'allowlist.\n");
exit($ecarts > 0 ? 1 : 0);
