<?php
/**
 * Monte le numero de version des feuilles de style et des scripts.
 *
 *     php tools/bump-assets-version.php          # monte d'un cran
 *     php tools/bump-assets-version.php --check  # dit seulement ou on en est
 *
 * Pourquoi cet outil existe : les fichiers statiques sont servis avec
 * "cache-control: public, max-age=604800", soit sept jours. Un fichier
 * modifie mais servi a la meme URL reste donc invisible pendant une semaine
 * pour quiconque est deja venu sur le site. Le parametre ?v= change l'URL et
 * force le rechargement.
 *
 * Oublier ce geste donne le pire des cas : la correction est en ligne, le
 * serveur la sert, et personne ne la voit.
 */

$racine = dirname(__DIR__);
$verifierSeulement = in_array('--check', $argv, true);

// Les pages qui referencent des fichiers locaux
$pages = array_merge(
    glob($racine . '/pages/*.html') ?: [],
    file_exists($racine . '/index.html') ? [$racine . '/index.html'] : []
);

if (!$pages) {
    fwrite(STDERR, "Aucune page trouvee.\n");
    exit(1);
}

// ---------- Version en cours ----------

$versions = [];
foreach ($pages as $page) {
    if (preg_match_all('/\?v=(\d+)/', file_get_contents($page), $m)) {
        foreach ($m[1] as $v) $versions[(int) $v] = true;
    }
}

$actuelle = $versions ? max(array_keys($versions)) : 0;
$suivante = $actuelle + 1;

if (count($versions) > 1) {
    echo "Attention : plusieurs versions coexistent (" . implode(', ', array_keys($versions)) . ").\n";
    echo "Elles vont toutes passer a {$suivante}.\n\n";
}

if ($verifierSeulement) {
    echo "Version actuelle : {$actuelle}\n";
    exit(0);
}

// ---------- Reecriture ----------

/**
 * Ajoute ou met a jour ?v= sur un chemin local.
 * Les URL externes (CDN) gardent la leur : ce n'est pas a nous de les versionner.
 */
function versionner($attribut, $chemin, $version) {
    if (strpos($chemin, '//') === 0
        || strpos($chemin, 'http://') === 0
        || strpos($chemin, 'https://') === 0) {
        return null;
    }
    $chemin = preg_replace('/\?v=\d+$/', '', $chemin);
    return $attribut . '="' . $chemin . '?v=' . $version . '"';
}

$modifiees = 0;
$references = 0;

foreach ($pages as $page) {
    $avant = file_get_contents($page);

    $apres = preg_replace_callback(
        '/(href|src)="([^"]+\.(?:css|js)(?:\?v=\d+)?)"/',
        function ($m) use ($suivante, &$references) {
            $remplacement = versionner($m[1], $m[2], $suivante);
            if ($remplacement === null) return $m[0];
            $references++;
            return $remplacement;
        },
        $avant
    );

    if ($apres !== $avant) {
        file_put_contents($page, $apres);
        $modifiees++;
    }
}

echo "Version {$actuelle} -> {$suivante}\n";
echo "{$references} reference(s) dans {$modifiees} page(s) reecrite(s).\n";

if ($modifiees === 0) {
    echo "Rien n'a change : les pages etaient deja a jour.\n";
}
