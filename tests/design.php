<?php
/**
 * Tests du site public refondu — s'executent sans navigateur ni base de donnees.
 *
 *     php tests/design.php
 *
 * Couvre ce qui s'etait degrade en silence sur l'ancien site : les contrastes,
 * le mode sombre, les emojis, les pieds de page divergents et les liens morts.
 */

$ROOT = dirname(__DIR__);
$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    echo ($ok ? '  ok  ' : ' FAIL ') . $label . ($ok || $detail === '' ? '' : " : {$detail}") . "\n";
    if (!$ok) $failures++;
};

// Pages du nouveau site. A etendre a chaque phase.
$pages = array_map(fn($p) => "{$ROOT}/{$p}", [
    'index.html', 'pages/a-propos.html', 'pages/essor.html', 'pages/soutenir.html',
    'pages/contact.html', 'pages/confidentialite.html', 'pages/realisations.html', 'pages/realisation.html', 'pages/formulaire.html', 'pages/partenaires.html', 'pages/temoignage.html',
]);
// Liens du menu vers des pages des phases suivantes. A vider avant la bascule.
$aVenir = [];

$css = file_get_contents("{$ROOT}/css/site.css");
$cssAdmin = file_get_contents("{$ROOT}/css/pages/admin.css");

// ---------- Contrastes ----------

function luminance($hex) {
    $c = array_map(function ($v) {
        $v = hexdec($v) / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function ratio($a, $b) {
    [$hi, $lo] = [max(luminance($a), luminance($b)), min(luminance($a), luminance($b))];
    return ($hi + 0.05) / ($lo + 0.05);
}
function tokens($block) {
    preg_match_all('/--([a-z-]+):\s*(#[0-9a-f]{6})/i', $block, $m);
    return array_combine($m[1], $m[2]);
}

preg_match('/^:root \{(.*?)^\}/ms', $css, $clair);
preg_match('/:root\[data-theme="dark"\] \{(.*?)^\}/ms', $css, $sombre);
$light = tokens($clair[1]);
$modes = ['clair' => $light, 'sombre' => array_merge($light, tokens($sombre[1]))];

// texte, fond, seuil
$paires = [
    ['text', 'surface', 4.5], ['text', 'surface-alt', 4.5],
    ['text-muted', 'surface', 4.5], ['text-muted', 'surface-alt', 4.5],
    ['link', 'surface', 4.5], ['link', 'surface-alt', 4.5],
    ['on-primary', 'primary', 4.5], ['on-primary', 'primary-hover', 4.5],
    ['primary-hover', 'on-primary', 4.5],
    ['on-accent', 'accent', 4.5], ['on-accent', 'accent-hover', 4.5],
    ['on-primary-soft', 'primary-soft', 4.5],
    ['footer-text', 'footer-bg', 4.5], ['footer-muted', 'footer-bg', 4.5],
    ['danger', 'surface', 4.5], ['success', 'surface', 4.5],
    ['footer-text', 'sidebar-hover', 4.5],
    ['border', 'surface', 1.2],
    ['on-logo-plate', 'logo-plate', 4.5],
];
foreach ($modes as $mode => $t) {
    foreach ($paires as [$fg, $bg, $min]) {
        $r = ratio($t[$fg], $t[$bg]);
        $check("{$mode} : {$fg} sur {$bg}", $r >= $min, sprintf('%.2f:1, minimum %.1f', $r, $min));
    }
}

// Les deux blocs sombres (reglage de l'appareil et attribut) doivent rester identiques
preg_match('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{(.*?)\}/s', $css, $auto);
$check('mode sombre : les deux definitions concordent', tokens($auto[1]) === tokens($sombre[1]));

// ---------- Couleurs en dur ----------

$regles = preg_replace('/\/\*.*?\*\//s', '', $css);
$regles = preg_replace('/^:root[^{]*\{.*?^\}/ms', '', $regles);
$regles = preg_replace('/@media \(prefers-color-scheme: dark\) \{\s*:root:not\(\[data-theme="light"\]\) \{.*?\}\s*\}/s', '', $regles);
preg_match_all('/#[0-9a-f]{3,8}\b(?![^{]*\{)|\b(?:white|black)\b(?![^{]*\{)|rgba?\(/i', $regles, $durs);
$check('aucune couleur en dur hors des variables', !$durs[0], implode(', ', array_unique($durs[0])));
preg_match_all('/#[0-9a-f]{3,8}\b(?![^{]*\{)|\b(?:white|black)\b(?![^{]*\{)|rgba?\(/i', preg_replace('/\/\*.*?\*\//s', '', $cssAdmin), $dursAdmin);
$check('admin : aucune couleur en dur', !$dursAdmin[0], implode(', ', array_unique($dursAdmin[0])));

// ---------- Pages ----------

$emoji = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2B50}\x{2705}\x{2B06}]/u';
foreach (array_merge($pages, ["{$ROOT}/pages/admin-dashboard.html", "{$ROOT}/pages/admin-login.html", "{$ROOT}/pages/presence.html", "{$ROOT}/css/pages/admin.css", "{$ROOT}/js/pages/admin-dashboard.js", "{$ROOT}/js/pages/admin-login.js", "{$ROOT}/js/utils.js", "{$ROOT}/js/api.js", "{$ROOT}/css/site.css", "{$ROOT}/js/site.js", "{$ROOT}/js/pages/realisations.js", "{$ROOT}/js/pages/admin-projects.js", "{$ROOT}/js/pages/formulaire.js", "{$ROOT}/js/pages/admin-forms.js", "{$ROOT}/js/pages/vitrine.js", "{$ROOT}/js/pages/admin-modules.js"]) as $fichier) {
    $check('aucun emoji : ' . basename($fichier), !preg_match($emoji, file_get_contents($fichier)));
}

foreach ($pages as $page) {
    $nom = basename($page);
    $html = file_get_contents($page);
    $check("{$nom} : une seule balise h1", substr_count($html, '<h1') === 1);
    $check("{$nom} : viewport et description", str_contains($html, 'name="viewport"') && str_contains($html, 'name="description"'));
    $check("{$nom} : icones a version fixe", str_contains($html, 'icons-webfont@3.49.0/dist/tabler-icons.min.css'));
    $check("{$nom} : aucune mention retiree", !preg_match('/sponsor|programmes?\b|blog|\bcours\b|K[eè]sKl[eè]|Bous[oò]l|Koul[eè]|500 [ée]tudiants/iu', strip_tags($html)));

    preg_match_all('/(?:href|src)="([^"#]+)"/', $html, $liens);
    foreach (array_unique($liens[1]) as $lien) {
        if (preg_match('#^(https?:|mailto:|tel:)#', $lien)) continue;
        $chemin = preg_replace('/\?.*$/', '', $lien);
        if (in_array(basename($chemin), $aVenir, true)) continue;
        $check("{$nom} : lien {$chemin}", is_file(dirname($page) . '/' . $chemin));
    }
}

foreach (['formulaire', 'temoignage'] as $page) {
    $check("{$page}.html : non indexee", str_contains(file_get_contents("{$ROOT}/pages/{$page}.html"), 'noindex'));
}

exec('php ' . escapeshellarg("{$ROOT}/tools/build-layout.php") . ' --check', $sortie, $code);
$check('en-tete et pied de page a jour dans toutes les pages', $code === 0, implode(' ', $sortie));

echo $failures ? "\n{$failures} echec(s)\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
