<?php
/**
 * Recopie l'en-tete et le pied de page dans les pages du site public.
 *
 *     php tools/build-layout.php          # met les pages a jour
 *     php tools/build-layout.php --check  # dit seulement si une page a derive
 *
 * Pourquoi cet outil existe : le site est en HTML statique, sans etape de
 * construction. Recopies a la main, l'ancien site avait fini avec trois pieds
 * de page differents. Le modele vit dans tools/layout/ et les pages portent des
 * reperes <!-- layout:header --> ... <!-- /layout:header -->.
 */

$racine = dirname(__DIR__);
$verifierSeulement = in_array('--check', $argv, true);

$pages = array_merge([$racine . '/index.html'], glob($racine . '/pages/*.html') ?: []);
$derives = 0;

foreach ($pages as $page) {
    $avant = file_get_contents($page);
    if (strpos($avant, '<!-- layout:header -->') === false) continue;

    $racineRelative = basename(dirname($page)) === 'pages' ? '../' : '';
    $nom = basename($page);
    $apres = $avant;

    foreach (['header', 'footer'] as $bloc) {
        $modele = rtrim(file_get_contents(__DIR__ . "/layout/{$bloc}.html"));
        $modele = str_replace('{{root}}', $racineRelative, $modele);
        // La page en cours est marquee pour les lecteurs d'ecran comme pour la vue
        $modele = preg_replace_callback('/ data-nav="([^"]+)"/', function ($m) use ($nom) {
            return $m[1] === $nom ? ' aria-current="page"' : '';
        }, $modele);
        $apres = preg_replace_callback(
            "#<!-- layout:{$bloc} -->.*?<!-- /layout:{$bloc} -->#s",
            fn() => "<!-- layout:{$bloc} -->\n  {$modele}\n  <!-- /layout:{$bloc} -->",
            $apres
        );
    }

    if ($apres !== $avant) {
        $derives++;
        echo ($verifierSeulement ? 'A jour requis : ' : 'Mise a jour : ') . $nom . "\n";
        if (!$verifierSeulement) file_put_contents($page, $apres);
    }
}

echo $derives ? "{$derives} page(s) concernee(s).\n" : "Toutes les pages sont a jour.\n";
exit($verifierSeulement && $derives ? 1 : 0);
