<?php
/**
 * Tests de la feuille de présence — s'exécute sans base de données.
 *
 *     php tests/presence.php
 *
 * Écrit le PDF d'essai dans le chemin donné en argument, s'il y en a un.
 */

$ROOT = dirname(__DIR__);

class Router {
    public $routes = [];
    public static function getInstance() { static $i; return $i ??= new self(); }
    public function __call($method, $args) { $this->routes[] = [$method, $args[0], $args[2] ?? []]; }
}
class Database {
    public static function getInstance() { return new self(); }
}

require $ROOT . '/api/routes/attendance.php';

$failures = 0;
$check = function($label, $ok) use (&$failures) {
    echo($ok ? '  ok  ' : ' FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};

$valid = ['last_name' => ' Saint  Fleur ', 'first_name' => 'Anaïs', 'identifier' => '001-234-567 8', 'gender' => 'F', 'age' => '24'];

[$values, $error] = attendance_validate($valid);
$check('une saisie correcte passe', $error === null);
$check('le nom est nettoyé', $values['last_name'] === 'Saint Fleur');
$check("l'identifiant perd tirets et espaces", $values['identifier'] === '0012345678');
$check("l'âge devient un entier", $values['age'] === 24);

foreach ([
    'nom vide' => ['last_name' => '  '],
    'prénom vide' => ['first_name' => ''],
    'identifiant trop court' => ['identifier' => '123'],
    'identifiant avec symboles' => ['identifier' => "1234567'OR1"],
    'sexe inconnu' => ['gender' => 'X'],
    'âge hors limites' => ['age' => '300'],
    'âge non numérique' => ['age' => 'vingt'],
    'champ tableau' => ['last_name' => ['a']],
] as $label => $override) {
    $check("refusé : {$label}", attendance_validate(array_merge($valid, $override))[1] !== null);
}

// Les routes de lecture et d'export ne doivent jamais s'ouvrir au public
$router = Router::getInstance();
foreach ($router->routes as [$method, $path, $middleware]) {
    $public = $method === 'post';
    $check("{$method} {$path} " . ($public ? 'public' : 'réservé aux admins'),
        $public ? $middleware === [] : $middleware === ['adminMiddleware']);
}
$check("l'export est déclaré", (bool) array_filter($router->routes, fn($r) => $r[1] === '\/attendance/export'));

$rows = [];
for ($i = 1; $i <= 70; $i++) {
    $rows[] = ['last_name' => "Nom{$i}", 'first_name' => 'Prénom', 'identifier' => sprintf('%010d', $i), 'gender' => $i % 2 ? 'F' : 'M', 'age' => 20 + $i % 30];
}
$rows[3]['last_name'] = 'JEAN-BAPTISTE DE LA TOUR SAINT-FLEUR DESROSIERS DU MOULIN';

$pdf = attendance_pdf($rows);
$check('le PDF commence par son en-tête', str_starts_with($pdf, '%PDF-'));
$check('le logo est intégré', str_contains($pdf, '/DCTDecode'));
$check('le titre porte ses accents', str_contains($pdf, mb_convert_encoding(ATTENDANCE_TITLE, 'Windows-1252', 'UTF-8')));
foreach (PDF_LETTERHEAD_CONTACT as $line) {
    $check("l'en-tête porte : {$line}", str_contains($pdf, mb_convert_encoding($line, 'Windows-1252', 'UTF-8')));
}
$check("l'ancienne adresse a disparu", !str_contains($pdf, 'Port-au-Prince'));
$check('70 lignes tiennent sur plusieurs pages', substr_count($pdf, '/Type /Page ') > 1);
$check('une feuille vide se génère', str_starts_with(attendance_pdf([]), '%PDF-'));

if (isset($argv[1])) file_put_contents($argv[1], $pdf);

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
