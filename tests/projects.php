<?php
/**
 * Tests des projets et de l'envoi d'images — sans base de donnees.
 *
 *     php tests/projects.php
 */

$ROOT = dirname(__DIR__);

class Halt extends Error {
    public $data;
    public function __construct($code, $message, $data = null) { parent::__construct($message, $code); $this->data = $data; }
}
class Response {
    public static function success($data = null, $message = '', $code = 200) { throw new Halt($code, $message, $data); }
    public static function error($message = '', $code = 400) { throw new Halt($code, $message); }
    public static function notFound($message = '') { throw new Halt(404, $message); }
}
class Router {
    public $routes = [];
    public static $body = [];
    public static function getInstance() { static $i; return $i ??= new self(); }
    public static function getBody() { return self::$body; }
    public function __call($method, $args) { $this->routes["{$method} {$args[0]}"] = ['run' => $args[1], 'mw' => $args[2] ?? []]; }
}
class Database {
    public $queries = [];
    public $next = [];
    public $duplicates = 0;
    public static function getInstance() { static $i; return $i ??= new self(); }
    public function query($sql, $params = []) {
        $this->queries[] = [$sql, $params];
        if (str_starts_with(trim($sql), 'INSERT') && $this->duplicates-- > 0) throw new Exception('Duplicate entry');
    }
    public function fetchAll($sql, $params = []) { $this->queries[] = [$sql, $params]; return $this->next; }
    public function fetchOne($sql, $params = []) { $this->queries[] = [$sql, $params]; return $this->next ?: null; }
}

require "{$ROOT}/api/routes/projects.php";
require "{$ROOT}/api/routes/uploads.php";

$failures = 0;
$check = function ($label, $ok) use (&$failures) {
    echo ($ok ? '  ok  ' : ' FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};
$call = function ($route, $params = [], $body = []) {
    Router::$body = $body;
    try {
        (Router::getInstance()->routes[$route]['run'])($params);
    } catch (Halt $halt) {
        return $halt;
    }
    return null;
};

$valid = ['name' => 'Été 2025 : réseaux', 'summary' => 'Résumé', 'type' => 'formation', 'status' => 'published',
          'start_date' => '2025-06-01', 'end_date' => '', 'participants' => '11', 'external_url' => 'https://zonpam.com',
          'image_url' => 'uploads/ab12.jpg'];

// ---------- Saisie ----------

$check('adresse courte sans accent', project_slug('Été 2025 : réseaux') === 'ete-2025-reseaux');
[$values, $error] = project_validate($valid);
$check('une saisie correcte passe', $error === null);
$check('une date vide devient NULL', $values['end_date'] === null);
$check('les participants deviennent un entier', $values['participants'] === 11);
$check('zéro participant reste accepté', project_validate(array_merge($valid, ['participants' => 0]))[0]['participants'] === 0);

foreach ([
    'nom vide' => ['name' => ' '],
    'nom sans lettre' => ['name' => '!!!'],
    'résumé vide' => ['summary' => ''],
    'type inconnu' => ['type' => 'cours'],
    'statut inconnu' => ['status' => 'visible'],
    'date impossible' => ['start_date' => '2025-02-31'],
    'fin avant début' => ['start_date' => '2025-06-01', 'end_date' => '2025-01-01'],
    'participants négatifs' => ['participants' => '-1'],
    'lien javascript' => ['external_url' => 'javascript:alert(1)'],
    'lien avec guillemet' => ['external_url' => 'https://a.b/"onclick=x'],
    'image hors dossier' => ['image_url' => '../api/.env'],
    'image externe' => ['image_url' => 'https://a.b/c.png'],
] as $label => $override) {
    $check("refusé : {$label}", project_validate(array_merge($valid, $override))[1] !== null);
}

// ---------- Protection des routes ----------

$routes = Router::getInstance()->routes;
foreach ($routes as $route => $definition) {
    $public = in_array($route, ['get \/projects', 'get \/projects/:slug'], true);
    $check($route . ($public ? ' public' : ' réservé aux admins'), $public ? $definition['mw'] === [] : $definition['mw'] === ['adminMiddleware']);
}
$keys = array_keys($routes);
$check('/projects/all passe avant /projects/:slug', array_search('get \/projects/all', $keys) < array_search('get \/projects/:slug', $keys));

// ---------- Brouillons ----------

$db = Database::getInstance();
$call('get \/projects');
$check('la liste publique exclut les brouillons', str_contains(end($db->queries)[0], "status = 'published'"));
$call('get \/projects/:slug', ['slug' => 'x']);
$check('la fiche publique exclut les brouillons', str_contains(end($db->queries)[0], "status = 'published'"));
$check('le public ne reçoit pas le statut', !str_contains(PROJECT_PUBLIC_COLUMNS, 'status'));

// ---------- Écriture ----------

$db->duplicates = 1;
$halt = $call('post \/projects', [], $valid);
$check('un nom déjà pris reçoit une autre adresse', $halt->getCode() === 201 && $halt->data['slug'] === 'ete-2025-reseaux-2');
$db->next = [];
$check('modifier un projet absent rend 404', $call('put \/projects/:id', ['id' => 9], $valid)->getCode() === 404);

// ---------- Schéma ----------

$sql = file_get_contents("{$ROOT}/api/sql/refonte_01_projets.sql");
preg_match('/CREATE TABLE IF NOT EXISTS projects \((.*?)\n\) ENGINE/s', $sql, $table);
foreach (array_keys(project_validate($valid)[0]) as $column) {
    $check("la colonne {$column} existe", preg_match('/^\s+' . $column . '\s/m', $table[1]) === 1);
}
foreach (array_map('trim', explode(',', PROJECT_PUBLIC_COLUMNS)) as $column) {
    $check("colonne publique {$column} présente", preg_match('/^\s+' . $column . '\s/m', $table[1]) === 1);
}

foreach ([["'sites-web-cohorte-1'", '12, NULL, NULL'], ["'sites-web-cohorte-2'", '21, NULL, NULL'], ["'sites-web-cohorte-3'", '9, NULL, NULL'],
          ["'reseaux-sociaux'", "11, 'Darlendie Pierre"], ["'depannage-cellulaire'", "13, 'Rodh Fabrice Phanet"],
          ["'zonpam'", "'https://zonpam.com'"], ["'essor'", "'https://appessor.dev-dynamics.org'"]] as [$slug, $attendu]) {
    $bloc = substr($sql, strpos($sql, "({$slug}"));
    $bloc = substr($bloc, 0, strpos($bloc, "),\n") ?: strpos($bloc, ");"));
    $check("réalisation initiale {$slug} : {$attendu}", str_contains($bloc, $attendu));
}
$check('migration rejouable', str_contains($sql, 'CREATE TABLE IF NOT EXISTS') && str_contains($sql, 'INSERT IGNORE'));

// ---------- Images ----------

$dir = sys_get_temp_dir() . '/projects-test-' . uniqid();
mkdir($dir);
$png = "{$dir}/a.png";
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAEBgIApD5fRAAAAABJRU5ErkJggg=='));
$php = "{$dir}/photo.png";
file_put_contents($php, '<?php echo 1;');
$svg = "{$dir}/logo.svg";
file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
$check('un PNG passe', upload_check($png, filesize($png), UPLOAD_ERR_OK)[0] === 'png');
$check('du PHP déguisé en PNG est refusé', upload_check($php, filesize($php), UPLOAD_ERR_OK)[1] !== null);
$check('un SVG est refusé', upload_check($svg, filesize($svg), UPLOAD_ERR_OK)[1] !== null);
$check('plus de 2 Mo est refusé', upload_check($png, UPLOAD_MAX_BYTES + 1, UPLOAD_ERR_OK)[1] !== null);
array_map('unlink', [$png, $php, $svg]);
rmdir($dir);

$check('uploads/ refuse le code', preg_match('/php.*svg/s', file_get_contents("{$ROOT}/uploads/.htaccess")) === 1);

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
