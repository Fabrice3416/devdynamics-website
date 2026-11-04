<?php
/**
 * Tests des formulaires — sans base de donnees. Couvre les regles qui ne
 * doivent pas ceder : validation, regles apres reponses, suspension, empreinte
 * d'appareil, agregats et exports.
 *
 *     php tests/forms.php
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
    public static $query = [];
    public static function getInstance() { static $i; return $i ??= new self(); }
    public static function getBody() { return self::$body; }
    public static function getQueryParam($key, $default = null) { return self::$query[$key] ?? $default; }
    public function __call($method, $args) { $this->routes["{$method} {$args[0]}"] = ['run' => $args[1], 'mw' => $args[2] ?? []]; }
}
class Database {
    public $log = [];
    public $form = null;
    public $fields = [];
    public $duplicate = false;
    public $committed = 0;
    public static function getInstance() { static $i; return $i ??= new self(); }
    public function query($sql, $params = []) {
        $this->log[] = [$sql, $params];
        if ($this->duplicate && str_contains($sql, 'INSERT INTO form_responses')) throw new Exception('Duplicate entry');
    }
    public function fetchAll($sql, $params = []) { $this->log[] = [$sql, $params]; return str_contains($sql, 'FROM form_fields') ? $this->fields : []; }
    public function fetchOne($sql, $params = []) { $this->log[] = [$sql, $params]; return str_contains($sql, 'COUNT(*)') ? ['total' => 0] : $this->form; }
    public function beginTransaction() {}
    public function commit() { $this->committed++; }
    public function rollback() {}
    public function lastInsertId() { return 7; }
}

require "{$ROOT}/api/routes/projects.php";
require "{$ROOT}/api/routes/forms.php";

$failures = 0;
$check = function ($label, $ok) use (&$failures) {
    echo ($ok ? '  ok  ' : ' FAIL ') . $label . "\n";
    if (!$ok) $failures++;
};
$call = function ($route, $params = [], $body = []) {
    Router::$body = $body;
    try { (Router::getInstance()->routes[$route]['run'])($params); } catch (Halt $halt) { return $halt; }
    return null;
};

$field = fn($id, $type, $extra = []) => array_merge(['id' => $id, 'type' => $type, 'label' => "Q{$id}", 'help_text' => null, 'required' => 0, 'hidden' => 0, 'chart' => 'none', 'options' => null], $extra);
$fields = [
    $field(1, 'text', ['required' => 1]), $field(2, 'textarea'), $field(3, 'number'), $field(4, 'date'),
    $field(5, 'single', ['options' => '["A","B"]']), $field(6, 'multiple', ['options' => '["A","B","C"]']),
    $field(7, 'select', ['options' => '["X","Y"]']), $field(8, 'scale'), $field(9, 'yesno'),
    $field(10, 'text', ['hidden' => 1, 'required' => 1]),
];

// ---------- Validation des reponses ----------

$complete = [1 => ' Marie ', 2 => 'Long', 3 => '12,5', 4 => '2026-11-04', 5 => 'A', 6 => ['A', 'C'], 7 => 'Y', 8 => '4', 9 => 'oui'];
[$values, $error] = form_validate_answers($fields, $complete);
$check('une réponse complète passe', $error === null);
$check('le texte est nettoyé', $values[1] === 'Marie');
$check('la virgule décimale est acceptée', $values[3] === '12.5');
$check('un choix multiple devient une liste', $values[6] === '["A","C"]');
$check('une question masquée obligatoire ne bloque pas', !isset($values[10]));
$check('une question masquée est ignorée même envoyée', !isset(form_validate_answers($fields, $complete + [10 => 'x'])[0][10]));

foreach ([
    'question obligatoire vide' => [1 => '  '],
    'nombre invalide' => [3 => 'douze'],
    'date impossible' => [4 => '2026-02-31'],
    'choix inconnu' => [5 => 'Z'],
    'choix multiple inconnu' => [6 => ['A', 'Z']],
    'choix multiple non liste' => [6 => 'A'],
    'liste inconnue' => [7 => 'Z'],
    'échelle à 6' => [8 => '6'],
    'échelle à 0' => [8 => '0'],
    'oui/non inconnu' => [9 => 'peut-être'],
    'texte en tableau' => [2 => ['x']],
    'texte trop long' => [1 => str_repeat('a', 501)],
] as $label => $override) {
    $check("refusé : {$label}", form_validate_answers($fields, array_replace($complete, $override))[1] !== null);
}
$check('refusé : formulaire vide', form_validate_answers([$field(1, 'text')], [])[1] !== null);

// ---------- Etat du formulaire ----------

$check('ouvert sans date', form_state(['status' => 'open', 'closes_at' => null], '2026-11-04 10:00:00') === 'open');
$check('ouvert avant la date', form_state(['status' => 'open', 'closes_at' => '2026-11-05 00:00:00'], '2026-11-04 10:00:00') === 'open');
$check('fermé une fois la date atteinte', form_state(['status' => 'open', 'closes_at' => '2026-11-04 10:00:00'], '2026-11-04 10:00:00') === 'closed');
$check('suspendu reste suspendu', form_state(['status' => 'suspended', 'closes_at' => null], '2026-11-04 10:00:00') === 'suspended');

// ---------- Regles apres reponses ----------

$existing = [$field(1, 'text'), $field(5, 'single', ['options' => '["A","B"]'])];
$input = [
    ['id' => 1, 'type' => 'text', 'label' => 'Nom'],
    ['id' => 5, 'type' => 'single', 'label' => 'Choix', 'options' => ['A', 'B', 'C']],
];
[$normal, $removed, $error] = form_normalize_fields($input, $existing, true);
$check('modifier un libellé et ajouter un choix reste permis', $error === null && $removed === []);
$check('ajouter une question reste permis', form_normalize_fields(array_merge($input, [['type' => 'yesno', 'label' => 'Nouvelle']]), $existing, true)[2] === null);
$check('avec réponses : retirer une question est refusé', form_normalize_fields([$input[0]], $existing, true)[2] !== null);
$check('sans réponse : retirer une question est permis', form_normalize_fields([$input[0]], $existing, false)[1] === [5]);
$check('avec réponses : changer le type est refusé', form_normalize_fields([['id' => 1, 'type' => 'number', 'label' => 'x'], $input[1]], $existing, true)[2] !== null);
$check('avec réponses : retirer un choix est refusé', form_normalize_fields([$input[0], ['id' => 5, 'type' => 'single', 'label' => 'x', 'options' => ['A', 'C']]], $existing, true)[2] !== null);
$check("une question d'un autre formulaire est refusée", form_normalize_fields([['id' => 99, 'type' => 'text', 'label' => 'x']], $existing, false)[2] !== null);
$check('un choix unique demande deux options', form_normalize_fields([['type' => 'single', 'label' => 'x', 'options' => ['A', ' ']]], [], false)[2] !== null);
$check('un formulaire sans question est refusé', form_normalize_fields([], [], false)[2] !== null);
$check('un texte libre ne peut pas avoir de graphique', form_normalize_fields([['type' => 'text', 'label' => 'x', 'chart' => 'bar']], [], false)[0][0]['chart'] === 'none');
$check('un choix garde son graphique', form_normalize_fields([['type' => 'yesno', 'label' => 'x', 'chart' => 'pie']], [], false)[0][0]['chart'] === 'pie');

// ---------- Agregats ----------

$stats = form_field_stats($field(6, 'multiple', ['options' => '["A","B"]']), ['["A"]', '["A","B"]']);
$check('choix multiple : chaque choix compte', $stats['series'] === [['label' => 'A', 'count' => 2], ['label' => 'B', 'count' => 1]]);
$stats = form_field_stats($field(8, 'scale'), ['1', '5', '5']);
$check('échelle : cinq barres et la moyenne', count($stats['series']) === 5 && $stats['series'][4]['count'] === 2 && $stats['average'] === 3.67);
$stats = form_field_stats($field(9, 'yesno'), ['oui', 'oui', 'non']);
$check('oui/non : libellés lisibles', $stats['series'][0] === ['label' => 'Oui', 'count' => 2]);
$stats = form_field_stats($field(3, 'number'), array_map('strval', range(1, 20)));
$check('nombres variés : six tranches, aucun oublié', count($stats['series']) === 6 && array_sum(array_column($stats['series'], 'count')) === 20);
$stats = form_field_stats($field(3, 'number'), ['2', '2', '3']);
$check('peu de nombres : une barre par valeur', count($stats['series']) === 2 && $stats['series'][0]['count'] === 2);
$check('nombre sans réponse : pas d\'erreur', form_field_stats($field(3, 'number'), [])['n'] === 0);

// ---------- Routes et securite ----------

$routes = Router::getInstance()->routes;
foreach (array_filter($routes, fn($r) => str_contains($r, '/forms'), ARRAY_FILTER_USE_KEY) as $route => $definition) {
    $public = str_contains($route, '/forms/public/');
    $check($route . ($public ? ' public' : ' réservé aux admins'), $public ? $definition['mw'] === [] : $definition['mw'] === ['adminMiddleware']);
}

$source = file_get_contents("{$ROOT}/api/routes/forms.php");
preg_match_all('/SELECT[^"]*device_hash|f\.\*|r\.\*|FROM form_responses[^"]*\*/', $source, $leaks);
$check("aucune lecture ne sélectionne l'empreinte", $leaks[0] === []);
$check("l'empreinte ne porte pas le jeton en clair", form_device_hash(1, 'abc') !== 'abc' && strlen(form_device_hash(1, 'abc')) === 64);
$check('un même appareil diffère selon le formulaire', form_device_hash(1, 'abc') !== form_device_hash(2, 'abc'));

$db = Database::getInstance();
$open = ['id' => 3, 'status' => 'open', 'closes_at' => null, 'one_per_device' => 1, 'thanks_message' => 'Merci', 'title' => 'T', 'intro' => null];
$db->fields = [$field(1, 'text', ['required' => 1])];
$token = 'abcdef0123456789abcdef';
$post = fn($body) => $call('post \/forms/public/:slug/responses', ['slug' => 's'], $body);

$db->form = $open;
$db->log = [];
$halt = $post(['answers' => [1 => 'Oui'], 'device_token' => $token]);
$check('une réponse valide est acceptée', $halt->getCode() === 201 && $db->committed === 1);
$insert = current(array_filter($db->log, fn($q) => str_contains($q[0], 'INSERT INTO form_responses')));
$check('seule l\'empreinte est enregistrée', $insert[1][1] === form_device_hash(3, $token) && !in_array($token, $insert[1], true));

$db->duplicate = true;
$check('seconde réponse du même appareil : 409', $post(['answers' => [1 => 'Oui'], 'device_token' => $token])->getCode() === 409);
$db->duplicate = false;
$check('sans jeton, refus quand le contrôle est actif', $post(['answers' => [1 => 'Oui']])->getCode() === 400);
$db->form = ['one_per_device' => 0] + $open;
$db->log = [];
$check('contrôle désactivé : pas de jeton requis', $post(['answers' => [1 => 'Oui']])->getCode() === 201);
$insert = current(array_filter($db->log, fn($q) => str_contains($q[0], 'INSERT INTO form_responses')));
$check('contrôle désactivé : aucune empreinte', $insert[1][1] === null);

$db->form = ['status' => 'suspended'] + $open;
$check('suspendu : envoi refusé', $post(['answers' => [1 => 'Oui'], 'device_token' => $token])->getCode() === 403);
$halt = $call('get \/forms/public/:slug', ['slug' => 's']);
$check('suspendu : la page dit son état sans les questions', $halt->data['state'] === 'suspended' && !isset($halt->data['fields']));
$db->form = ['closes_at' => '2020-01-01 00:00:00'] + $open;
$check('date passée : envoi refusé', $post(['answers' => [1 => 'Oui'], 'device_token' => $token])->getCode() === 403);
$db->form = ['status' => 'draft'] + $open;
$check('brouillon : introuvable en lecture', $call('get \/forms/public/:slug', ['slug' => 's'])->getCode() === 404);
$check('brouillon : introuvable à l\'envoi', $post(['answers' => [1 => 'Oui'], 'device_token' => $token])->getCode() === 404);
$db->form = $open;
$db->log = [];
$check('champ piège rempli : rien n\'est écrit', $post(['website' => 'bot', 'answers' => [1 => 'x']])->getCode() === 201 && $db->log === []);

$db->fields = [$field(1, 'text'), $field(10, 'text', ['hidden' => 1])];
$check('public : une question masquée n\'est pas envoyée', count($call('get \/forms/public/:slug', ['slug' => 's'])->data['fields']) === 1);

// ---------- Exports ----------

$columns = [$field(1, 'text', ['label' => 'Prénom']), $field(6, 'multiple', ['label' => 'Choix', 'options' => '["A","B"]']), $field(9, 'yesno', ['label' => 'Accord'])];
$rows = form_table($columns, [['id' => 1, 'created_at' => '2026-11-04 10:00:00'], ['id' => 2, 'created_at' => '2026-11-04 11:00:00']], [
    ['response_id' => 1, 'field_id' => 1, 'value' => '=HYPERLINK("x")'],
    ['response_id' => 1, 'field_id' => 6, 'value' => '["A","B"]'],
    ['response_id' => 1, 'field_id' => 9, 'value' => 'oui'],
    ['response_id' => 2, 'field_id' => 1, 'value' => 'Éloïse <b>'],
]);
$check('tableau : valeurs lisibles', $rows[0]['cells'][1] === 'A, B' && $rows[0]['cells'][2] === 'Oui');
$check('tableau : réponse absente vide', $rows[1]['cells'][2] === '');

$csv = form_export_csv($columns, $rows);
$check('CSV : accents pour Excel (BOM)', str_starts_with($csv, "\xEF\xBB\xBF"));
$check('CSV : une formule est neutralisée', str_contains($csv, "'=HYPERLINK") && !preg_match('/;"?=HYPERLINK/', $csv));
$check('CSV : un nombre négatif reste un nombre', form_safe_cell('-5') === '-5');

$xml = form_export_excel(['title' => 'Mon & titre'], $columns, $rows);
$check('Excel : XML valide', @simplexml_load_string($xml) !== false);
$check('Excel : le titre figure', str_contains($xml, 'Mon &amp; titre'));

$stats = array_map(fn($f) => form_field_stats($f, []), $columns);
$pdf = form_export_pdf(['title' => 'Mon titre'], $columns, $rows, $stats);
$check('PDF : document valide avec le logo', str_starts_with($pdf, '%PDF-') && str_contains($pdf, '/DCTDecode'));
$check('PDF : coordonnées DevDynamics', str_contains($pdf, 'contact@dev-dynamics.org'));
$many = array_fill(0, 150, $rows[0]);
$check('PDF : plusieurs pages', substr_count(form_export_pdf(['title' => 'T'], $columns, $many, []), '/Type /Page ') > 1);
foreach ([$csv, $xml] as $export) {
    $check('export sans empreinte', !str_contains($export, 'device'));
}

// ---------- Schema ----------

$sql = file_get_contents("{$ROOT}/api/sql/refonte_02_formulaires.sql");
preg_match("/type\s+ENUM\(([^)]*)\)/", $sql, $types);
$check('les types du schéma concordent', str_replace("'", '', $types[1]) === implode(',', FORM_FIELD_TYPES));
$check('migration rejouable', substr_count($sql, 'CREATE TABLE IF NOT EXISTS') === 4);
$check('un projet avec formulaires reste protégé', str_contains($sql, 'REFERENCES projects (id) ON DELETE RESTRICT'));
$check('une empreinte par appareil et par formulaire', str_contains($sql, 'UNIQUE KEY uniq_form_device (form_id, device_hash)'));

$js = file_get_contents("{$ROOT}/js/pages/admin-forms.js") . file_get_contents("{$ROOT}/js/pages/formulaire.js");
$check("le front n'affiche jamais l'empreinte", !str_contains($js, 'device_hash'));

echo $failures ? "\n{$failures} échec(s)\n" : "\nTout est bon.\n";
exit($failures ? 1 : 0);
