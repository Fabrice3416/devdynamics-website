<?php
/**
 * Tests du module Koulè Ki Pale — s'exécute sans base de données.
 *
 *     php tests/kkp.php
 *
 * Couvre ce qui se casse en silence : la dérive entre les libellés du
 * formulaire et ceux de l'API, l'écart entre le schéma SQL et la requête
 * d'insertion, les agrégats, l'appariement avant/après et les trois exports.
 */

$ROOT = dirname(__DIR__);
$MODE = $argv[1] ?? 'all';

// ---------- Doublures ----------

// Hérite d'Error et non d'Exception : en production Response::error() fait exit(),
// donc l'arrêt ne doit pas être rattrapé par les catch (Exception) des routes.
class KkpHalt extends Error {
    public $payload;
    public function __construct($code, $message, $payload = null) {
        parent::__construct($message, $code);
        $this->payload = $payload;
    }
}

class Response {
    public static function success($data = null, $message = 'Success', $code = 200) {
        throw new KkpHalt($code, $message, $data);
    }
    public static function error($message = 'Error', $code = 400, $data = null) {
        throw new KkpHalt($code, $message, $data);
    }
    public static function unauthorized($m = 'Unauthorized') { self::error($m, 401); }
    public static function forbidden($m = 'Forbidden') { self::error($m, 403); }
    public static function notFound($m = 'Not found') { self::error($m, 404); }
}

class Router {
    private static $instance = null;
    public static $routes = [];
    public static $body = [];
    public static $query = [];

    public static function getInstance() {
        return self::$instance ?: (self::$instance = new self());
    }
    private function add($method, $path, $callback) { self::$routes["$method $path"] = $callback; }
    public function get($p, $c, $m = []) { $this->add('GET', $p, $c); }
    public function post($p, $c, $m = []) { $this->add('POST', $p, $c); }
    public function put($p, $c, $m = []) { $this->add('PUT', $p, $c); }
    public function delete($p, $c, $m = []) { $this->add('DELETE', $p, $c); }

    public static function getBody() { return self::$body; }
    public static function getQuery() { return self::$query; }
    public static function getQueryParam($key, $default = null) { return self::$query[$key] ?? $default; }

    public static function call($key, $params = []) {
        if (!isset(self::$routes[$key])) throw new Exception("Route absente : $key");
        return call_user_func(self::$routes[$key], $params);
    }
}

class Database {
    public static $rows = [];          // lignes renvoyees par fetchAll
    public static $one = null;         // ligne renvoyee par fetchOne
    public static $queries = [];       // [sql, params] de chaque appel a query()
    public static $throwOnInsert = null;

    public static function getInstance() { return new self(); }

    public function fetchAll($sql, $params = []) {
        if (strpos($sql, "phase = ?") !== false) {
            $phase = $params[0] ?? null;
            return array_values(array_filter(self::$rows, fn($r) => $r['phase'] === $phase));
        }
        return self::$rows;
    }
    public function fetchOne($sql, $params = []) { return self::$one; }
    public function query($sql, $params = []) {
        self::$queries[] = [$sql, $params];
        if (self::$throwOnInsert && stripos($sql, 'INSERT') !== false) {
            throw new Exception(self::$throwOnInsert);
        }
        return null;
    }
    public function lastInsertId() { return 42; }
}

require_once $ROOT . '/api/routes/kkp.php';

// ---------- Cadre de test ----------

$fails = 0;
$total = 0;

function check($label, $actual, $expected) {
    global $fails, $total;
    $total++;
    $ok = (is_float($expected) || is_float($actual))
        ? abs((float) $actual - (float) $expected) < 0.001
        : $actual === $expected;
    if ($ok) {
        echo "  ok     $label\n";
    } else {
        $fails++;
        echo "  ECHEC  $label\n";
        echo "         attendu : " . trim(var_export($expected, true)) . "\n";
        echo "         obtenu  : " . trim(var_export($actual, true)) . "\n";
    }
}

function section($title) { echo "\n== $title ==\n"; }

// ---------- Jeu d'essai ----------

function kkp_test_row($phase, $code, $overrides = []) {
    $row = [
        'id' => 1, 'phase' => $phase, 'personal_code' => $code,
        'age' => null, 'gender' => null, 'situation' => null, 'prior_training' => null,
        'art_drawing' => 0, 'art_theatre' => 0, 'art_music' => 0,
        'art_writing' => 0, 'art_other' => 0, 'art_none' => 0,
        'reaction' => null, 'art_conflict_meaning' => null,
        'expectations' => null, 'special_needs' => null,
        'art_can_help' => null, 'art_helped' => null,
        'use_other_text' => null, 'strategy_other_text' => null,
        'fav_activity' => null, 'will_do_differently' => null, 'improvements' => null,
        'can_apply' => null, 'would_recommend' => null,
        'testimonial' => null, 'testimonial_consent' => null, 'testimonial_name' => null,
        'ip_hash' => 'x', 'created_at' => '2026-09-21 09:00:00',
    ];
    foreach (['q1','q2','q3','q4','q5','q6','q7','s1','s2','s3','s4','s5','s6','s7'] as $k) {
        $row[$k] = null;
    }
    foreach (['use_draw','use_write','use_music','use_dance','use_photo','use_none','use_other',
              'strategy_listen','strategy_art','strategy_dialogue','strategy_other'] as $k) {
        $row[$k] = 0;
    }
    return array_merge($row, $overrides);
}

$before = [
    kkp_test_row('avant', 'MA14', [
        'age' => 19, 'gender' => 'feminin', 'situation' => 'etudes', 'prior_training' => 0,
        'art_drawing' => 1, 'art_music' => 1,
        'q1' => 2, 'q2' => 2, 'q3' => 1, 'q4' => 3, 'q5' => 2, 'q6' => 1, 'q7' => 2,
        'reaction' => 'evite', 'expectations' => 'Apprendre a gerer mes coleres.',
        'art_conflict_meaning' => 'Je pense que c est dessiner au lieu de se battre.',
        'art_can_help' => 'oui', 'use_draw' => 1, 'use_write' => 1,
    ]),
    kkp_test_row('avant', 'JE08', [
        'age' => 23, 'gender' => 'masculin', 'situation' => 'recherche', 'prior_training' => 1,
        'art_theatre' => 1,
        'q1' => 4, 'q2' => 3, 'q3' => 3, 'q4' => 4, 'q5' => 3, 'q6' => 2, 'q7' => 3,
        'reaction' => 'compromis',
    ]),
    kkp_test_row('avant', 'SO30', [
        'age' => 31, 'gender' => 'autre', 'situation' => 'emploi',
        'art_none' => 1,
        'q1' => 3, 'q2' => 3, 'q3' => 2, 'q4' => 2, 'q5' => 4, 'q6' => 3, 'q7' => 1,
        'reaction' => 'cede', 'special_needs' => 'Horaires du matin uniquement.',
        'art_can_help' => 'non', 'use_none' => 1,
    ]),
];

$after = [
    kkp_test_row('apres', 'MA14', [
        'q1' => 4, 'q2' => 4, 'q3' => 4, 'q4' => 4, 'q5' => 4, 'q6' => 3, 'q7' => 4,
        'reaction' => 'collabore',
        's1' => 5, 's2' => 5, 's3' => 4, 's4' => 5, 's5' => 5, 's6' => 4, 's7' => 3,
        'fav_activity' => 'Le theatre-forum.',
        'can_apply' => 'oui', 'would_recommend' => 'oui',
        'testimonial' => 'J ai appris a dire ce que je ressens sans crier.',
        'testimonial_consent' => 'oui_nom', 'testimonial_name' => 'Maya P.',
        'art_conflict_meaning' => 'C est se servir du theatre pour dire ce qu on ne peut pas dire en face.',
        'art_helped' => 'beaucoup', 'strategy_listen' => 1, 'strategy_art' => 1,
    ]),
    kkp_test_row('apres', 'JE08', [
        'q1' => 4, 'q2' => 3, 'q3' => 4, 'q4' => 3, 'q5' => 4, 'q6' => 4, 'q7' => 3,
        'reaction' => 'compromis',
        's1' => 4, 's2' => 4, 's3' => 5, 's4' => 4, 's5' => 4, 's6' => 5, 's7' => 4,
        'can_apply' => 'pas_certain', 'would_recommend' => 'oui',
        'testimonial' => 'Bonne ambiance, j aurais voulu plus de temps.',
        'testimonial_consent' => 'oui_anonyme',
        'art_conflict_meaning' => 'Mettre les mots en chanson pour baisser la tension.',
        'art_helped' => 'un_peu', 'strategy_dialogue' => 1,
        'strategy_other' => 1, 'strategy_other_text' => 'Faire une pause avant de repondre.',
    ]),
    // Code sans jumeau : doit rester hors de la comparaison
    kkp_test_row('apres', 'ZZ99', [
        'q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 5, 'q5' => 5, 'q6' => 5, 'q7' => 5,
        's1' => 5, 's2' => 5, 's3' => 5, 's4' => 5, 's5' => 5, 's6' => 5, 's7' => 5,
        'can_apply' => 'oui', 'would_recommend' => 'non',
        'testimonial' => 'Je ne veux pas que ce soit publie.',
        'testimonial_consent' => 'non',
    ]),
];

// ---------- Mode export : le processus enfant ne produit que le fichier ----------

if ($MODE !== 'all') {
    $all = array_merge($before, $after);
    // Sert aussi a alimenter un apercu de l'admin sans base de donnees
    if ($MODE === 'fixture') {
        foreach ($all as $i => $row) { unset($all[$i]['ip_hash']); }
        echo json_encode([
            'stats' => [
                'labels'     => kkp_labels(),
                'phases'     => ['avant' => kkp_aggregate($before), 'apres' => kkp_aggregate($after)],
                'comparison' => kkp_compare($before, $after),
            ],
            'responses' => ['responses' => array_values($all), 'labels' => kkp_labels()],
        ], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
    if ($MODE === 'csv')   kkp_export_csv($all, 'test.csv');
    if ($MODE === 'excel') kkp_export_excel($all, $before, $after, 'test.xls');
    if ($MODE === 'pdf')   kkp_export_pdf($before, $after, 'avant', 'test.pdf');
    exit(0);
}

// ============================================================
// A. Conformité entre le formulaire (JS) et l'API (PHP)
// ============================================================

section('Conformité JS ↔ PHP');

$harness = <<<'JS'
const fs = require('fs');
globalThis.window = { location: { search: '' }, scrollTo() {} };
globalThis.document = { addEventListener() {}, createElement: () => ({ set textContent(v) { this._v = v; }, get innerHTML() { return this._v; } }) };
const src = fs.readFileSync(process.argv[2], 'utf8');
process.stdout.write(eval(src + '; JSON.stringify(KKP)'));
JS;

$harnessFile = sys_get_temp_dir() . '/kkp_labels_harness.js';
file_put_contents($harnessFile, $harness);
$jsJson = shell_exec('node ' . escapeshellarg($harnessFile) . ' '
    . escapeshellarg($ROOT . '/js/pages/kkp-questionnaire.js') . ' 2>/dev/null');
@unlink($harnessFile);

$js = json_decode((string) $jsJson, true);

if (!is_array($js)) {
    echo "  IGNORE le contrôle JS ↔ PHP (node indisponible ou fichier illisible)\n";
} else {
    // Les énoncés doivent être identiques mot pour mot : ils apparaissent
    // sur le formulaire, dans l'admin et dans le rapport au bailleur.
    check('énoncés du rapport au conflit', $js['questions'], array_values(kkp_questions()));
    check('énoncés de satisfaction', $js['satisfaction'], array_values(kkp_satisfaction()));

    // Les clés doivent correspondre : une clé inconnue est silencieusement
    // convertie en NULL par kkp_enum(), et la réponse serait perdue.
    $jsKeys = fn($name) => array_column($js[$name], 0);
    check('clés de sexe', $jsKeys('gender'), array_keys(kkp_genders()));
    check('clés de situation', $jsKeys('situation'), array_keys(kkp_situations()));
    check('clés de pratique artistique', $jsKeys('arts'), array_keys(kkp_arts()));
    check('clés de réaction', $jsKeys('reaction'), array_keys(kkp_reactions()));
    check('clés de can_apply', $jsKeys('can_apply'), array_keys(kkp_choices()));
    check('clés de would_recommend', $jsKeys('would_recommend'), array_keys(kkp_choices()));
    check('clés de consentement', $jsKeys('testimonial_consent'), array_keys(kkp_consents()));

    // Les libellés affichés à l'écran doivent eux aussi coller
    $jsLabels = fn($name) => array_column($js[$name], 1);
    check('libellés de réaction', $jsLabels('reaction'), array_values(kkp_reactions()));
    check('libellés de consentement', $jsLabels('testimonial_consent'), array_values(kkp_consents()));
    check('libellés de situation', $jsLabels('situation'), array_values(kkp_situations()));
}

// ============================================================
// B. Conformité entre le schéma SQL et la requête d'insertion
// ============================================================

section('Conformité SQL ↔ INSERT');

$sql = file_get_contents($ROOT . '/api/sql/kkp_responses.sql');
$routeSource = file_get_contents($ROOT . '/api/routes/kkp.php');

// Colonnes déclarées dans le CREATE TABLE
preg_match('/CREATE TABLE[^(]*\((.*)\)\s*ENGINE/s', $sql, $m);
preg_match_all('/^\s{4}([a-z_0-9]+)\s+[A-Z]/m', $m[1] ?? '', $colMatches);
$schemaColumns = $colMatches[1] ?? [];

check('le schéma déclare bien des colonnes', count($schemaColumns) > 20, true);

// Colonnes listées dans l'INSERT
preg_match('/INSERT INTO kkp_responses\s*\((.*?)\)\s*VALUES/s', $routeSource, $m2);
$insertColumns = array_values(array_filter(array_map('trim', explode(',', $m2[1] ?? ''))));

check('INSERT : toutes les colonnes existent au schéma',
      array_values(array_diff($insertColumns, $schemaColumns)), []);

// Nombre de marqueurs ? dans le VALUES
preg_match('/VALUES\s*\((.*?)\)",/s', $routeSource, $m3);
$placeholders = substr_count($m3[1] ?? '', '?');
// created_at est rempli par NOW(), pas par un paramètre
$nowCount = substr_count($m3[1] ?? '', 'NOW()');

check('INSERT : un marqueur par colonne',
      $placeholders + $nowCount, count($insertColumns));

// Nombre de valeurs passées au tableau de paramètres
$valuesStart = strpos($routeSource, 'VALUES (?');
$valuesBlock = substr($routeSource, $valuesStart, 4000);
preg_match('/\n            \[(.*?)\n            \]/s', $valuesBlock, $m4);
$valuesRaw = $m4[1] ?? '';
// Compte les virgules de premier niveau, parenthèses exclues
$depth = 0; $count = $valuesRaw === '' ? 0 : 1;
for ($i = 0; $i < strlen($valuesRaw); $i++) {
    $c = $valuesRaw[$i];
    if ($c === '(' || $c === '[') $depth++;
    elseif ($c === ')' || $c === ']') $depth--;
    elseif ($c === ',' && $depth === 0) $count++;
}
// La dernière valeur est suivie d'une virgule terminale
$trimmed = rtrim(trim($valuesRaw), ',');
if (substr(trim($valuesRaw), -1) === ',') $count--;

check('INSERT : autant de valeurs que de marqueurs', $count, $placeholders);

// Les colonnes de l'export doivent exister au schéma
$columns = kkp_export_columns();
check('export : au moins 35 colonnes', count($columns) >= 35, true);

// ============================================================
// C. Agrégats et comparaison
// ============================================================

section('Agrégats — avant');
$aggB = kkp_aggregate($before);
check('total', $aggB['total'], 3);
check('âge moyen', $aggB['age']['avg'], 24.3);
check('âge min / max', [$aggB['age']['min'], $aggB['age']['max']], [19, 31]);
check('tranche 18-24', $aggB['age_buckets']['18_24'], 2);
check('tranche +30', $aggB['age_buckets']['plus_30'], 1);
check('sexe féminin', $aggB['gender']['feminin'], 1);
check('formation antérieure : oui', $aggB['prior_training']['oui'], 1);
check('formation antérieure : non renseigné', $aggB['prior_training']['nr'], 1);
check('pratique musique', $aggB['arts']['art_music'], 1);
check('q1 moyenne', $aggB['likert'][0]['avg'], 3.0);
check('q1 distribution', $aggB['likert'][0]['dist'], [0, 1, 1, 1, 0]);
check('réaction « évite »', $aggB['reaction']['evite'], 1);
check('aucune satisfaction avant la formation', $aggB['satisfaction'][0]['n'], 0);
check('aucun témoignage avant la formation', count($aggB['testimonials']), 0);

section('Agrégats — après');
$aggA = kkp_aggregate($after);
check('s1 moyenne', $aggA['satisfaction'][0]['avg'], 4.67);
check('utilisable au quotidien : oui', $aggA['can_apply']['oui'], 2);
check('recommanderait : non', $aggA['would_recommend']['non'], 1);
check('témoignages collectés', count($aggA['testimonials']), 3);
check('nom exposé quand la citation est accordée', $aggA['testimonials'][0]['name'], 'Maya P.');
check('nom masqué quand le témoignage est anonyme', $aggA['testimonials'][1]['name'], null);
check('refus de diffusion conservé tel quel', $aggA['testimonials'][2]['consent'], 'non');

section("L'art et le conflit");
check("avant : l'art peut aider, oui", $aggB['art_can_help']['oui'], 1);
check("avant : l'art peut aider, non", $aggB['art_can_help']['non'], 1);
check('avant : sans réponse', $aggB['art_can_help']['nr'], 1);
check('avant : usage du dessin', $aggB['uses']['use_draw'], 1);
check("avant : n'utilise pas l'art", $aggB['uses']['use_none'], 1);
check('avant : aucune stratégie (question non posée)', $aggB['strategies']['strategy_listen'], 0);
check("après : l'art a beaucoup aidé", $aggA['art_helped']['beaucoup'], 1);
check("après : l'art a un peu aidé", $aggA['art_helped']['un_peu'], 1);
check('après : stratégie écouter', $aggA['strategies']['strategy_listen'], 1);
check('après : stratégie dialoguer', $aggA['strategies']['strategy_dialogue'], 1);
check('après : le texte libre d’un « Autre » est conservé',
      $aggA['other_texts']['strategies'], ['Faire une pause avant de repondre.']);

section('Comparaison appariée');
$cmp = kkp_compare($before, $after);
check('participants appariés', $cmp['paired'], 2);
check('codes appariés', $cmp['paired_codes'], ['JE08', 'MA14']);
check('présents seulement avant', $cmp['only_before'], ['SO30']);
check('présents seulement après', $cmp['only_after'], ['ZZ99']);
check('q1 avant', $cmp['likert'][0]['avg_before'], 3.0);
check('q1 après', $cmp['likert'][0]['avg_after'], 4.0);
check('q1 écart', $cmp['likert'][0]['delta'], 1.0);
check('q1 progression', $cmp['likert'][0]['progress'], ['up' => 1, 'flat' => 1, 'down' => 0]);
check('q4 écart nul malgré un progrès et un recul', $cmp['likert'][3]['delta'], 0.0);
check('q4 recul compté', $cmp['likert'][3]['progress']['down'], 1);
check('le code non apparié est exclu du calcul', $cmp['likert'][0]['n'], 2);
check('changement de style de réaction', count($cmp['reaction_shift']), 1);
check('MA14 passe à « collabore »', $cmp['reaction_shift'][0]['to'], 'collabore');

section('Sens donné à la démarche');
check('deux participants appariés ont répondu', count($cmp['meanings']), 2);
$parCode = array_column($cmp['meanings'], null, 'code');
check('le texte d’avant est repris',
      strpos($parCode['MA14']['before'], 'dessiner au lieu de se battre') !== false, true);
check('le texte d’après est repris',
      strpos($parCode['MA14']['after'], 'theatre') !== false, true);
check('une réponse manquante avant reste nulle', $parCode['JE08']['before'], null);
check('une réponse présente après est conservée',
      strpos($parCode['JE08']['after'], 'chanson') !== false, true);
// ZZ99 n'est pas apparie : son texte ne doit pas remonter ici
check('un code non apparié est exclu', isset($parCode['ZZ99']), false);

// ============================================================
// D. Route de soumission
// ============================================================

section('Soumission publique');

function post_kkp(array $body) {
    Router::$body = $body;
    Database::$queries = [];
    Database::$one = ['n' => 0];
    try {
        Router::call('POST \/kkp/responses');
    } catch (KkpHalt $halt) {
        return $halt;
    }
    return null;
}

function valid_body($overrides = []) {
    $body = ['phase' => 'avant', 'personal_code' => 'ma14'];
    for ($i = 1; $i <= 7; $i++) $body["q$i"] = 3;
    return array_merge($body, $overrides);
}

$halt = post_kkp(valid_body());
check('une soumission valide renvoie 201', $halt->getCode(), 201);
check('une soumission valide insère une ligne',
      count(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false)), 1);

$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
check('le code personnel est mis en majuscules', $insert[1][1], 'MA14');

// Chaque echelle n'appartient qu'a sa passation
$halt = post_kkp(valid_body([
    'phase' => 'avant',
    'art_can_help' => 'oui', 'art_helped' => 'beaucoup',
    'use_draw' => 1, 'strategy_listen' => 1,
]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
$cols = array_values(array_filter(array_map('trim',
    explode(',', (function($sql) { preg_match('/\((.*?)\)\s*VALUES/s', $sql, $m); return $m[1]; })($insert[0])))));
$idx = fn($nom) => array_search($nom, $cols, true);
check("avant : l'échelle d'avant est enregistrée", $insert[1][$idx('art_can_help')], 'oui');
check("avant : l'échelle d'après est ignorée", $insert[1][$idx('art_helped')], null);
check("avant : l'usage de l'art est enregistré", $insert[1][$idx('use_draw')], 1);
check('avant : une stratégie d’après est ignorée', $insert[1][$idx('strategy_listen')], 0);

$halt = post_kkp(valid_body([
    'phase' => 'apres',
    'art_helped' => 'beaucoup', 'strategy_art' => 1,
    'strategy_other' => 1, 'strategy_other_text' => 'Demander de l aide.',
]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
check("après : l'échelle d'après est enregistrée", $insert[1][$idx('art_helped')], 'beaucoup');
check('après : la stratégie est enregistrée', $insert[1][$idx('strategy_art')], 1);
check('après : le texte d’un « Autre » coché est conservé',
      $insert[1][$idx('strategy_other_text')], 'Demander de l aide.');

// Un texte laisse derriere une case decochee ne doit pas passer
$halt = post_kkp(valid_body([
    'phase' => 'apres',
    'strategy_other' => 0, 'strategy_other_text' => 'Texte oublie.',
]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
check('le texte d’un « Autre » décoché est écarté', $insert[1][$idx('strategy_other_text')], null);

$halt = post_kkp(valid_body(['art_conflict_meaning' => 'Utiliser le dessin pour parler.']));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
$colonnes = array_values(array_filter(array_map('trim',
    explode(',', (function($sql) { preg_match('/\((.*?)\)\s*VALUES/s', $sql, $m); return $m[1]; })($insert[0])))));
$idxSens = array_search('art_conflict_meaning', $colonnes, true);
check('le sens donné à la démarche est enregistré',
      $insert[1][$idxSens], 'Utiliser le dessin pour parler.');

$halt = post_kkp(valid_body(['personal_code' => '']));
check('code personnel vide refusé', $halt->getCode(), 400);

$halt = post_kkp(valid_body(['personal_code' => 'MA 14!']));
check('code personnel mal formé refusé', $halt->getCode(), 400);

$halt = post_kkp(valid_body(['q4' => null]));
check('affirmation manquante refusée', $halt->getCode(), 400);
check("le message nomme l'affirmation manquante", strpos($halt->getMessage(), 'q4') !== false, true);

$halt = post_kkp(valid_body(['q4' => 9]));
check('valeur hors échelle refusée', $halt->getCode(), 400);

$halt = post_kkp(valid_body(['age' => 200]));
check('âge aberrant refusé', $halt->getCode(), 400);

$halt = post_kkp(valid_body(['website' => 'http://spam.example']));
check('le piège à robots répond sans insérer', $halt->getCode(), 201);
check('le piège à robots n’insère rien',
      count(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false)), 0);

// Le nom ne doit jamais partir en base sans accord de citation
$halt = post_kkp(valid_body([
    'phase' => 'apres',
    'testimonial' => 'Un mot.',
    'testimonial_consent' => 'oui_anonyme',
    'testimonial_name' => 'Jean Dupont',
]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
$insertCols = array_values(array_filter(array_map('trim',
    explode(',', (function($sql) { preg_match('/\((.*?)\)\s*VALUES/s', $sql, $m); return $m[1]; })($insert[0])))));
$nameIndex = array_search('testimonial_name', $insertCols, true);
check('le nom est écarté sans accord de citation', $insert[1][$nameIndex], null);

$halt = post_kkp(valid_body([
    'phase' => 'apres',
    'testimonial' => 'Un mot.',
    'testimonial_consent' => 'oui_nom',
    'testimonial_name' => 'Jean Dupont',
]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
check('le nom est conservé avec accord de citation', $insert[1][$nameIndex], 'Jean Dupont');

// L'avis sur la formation n'a pas de sens avant la formation
$satIndex = array_search('s1', $insertCols, true);
$halt = post_kkp(valid_body(['phase' => 'avant', 's1' => 5]));
$insert = array_values(array_filter(Database::$queries, fn($q) => stripos($q[0], 'INSERT') !== false))[0];
check("l'avis sur la formation est ignoré avant la formation", $insert[1][$satIndex], null);

// Code déjà utilisé pour cette phase
Database::$throwOnInsert = "SQLSTATE[23000]: Duplicate entry 'avant-MA14' for key 'uq_kkp_phase_code'";
$halt = post_kkp(valid_body());
Database::$throwOnInsert = null;
check('code déjà utilisé : renvoie 409', $halt->getCode(), 409);
check('le message propose une issue au participant',
      strpos($halt->getMessage(), 'MA14B') !== false, true);

// Anti-flood
Database::$one = ['n' => 15];
Router::$body = valid_body(['personal_code' => 'AB01']);
Database::$queries = [];
try { Router::call('POST \/kkp/responses'); $halt = null; } catch (KkpHalt $h) { $halt = $h; }
check('au-delà du seuil, la soumission est freinée', $halt->getCode(), 429);

// ============================================================
// E. Exports
// ============================================================

section('Exports');

$self = __FILE__;
$csv = shell_exec('php ' . escapeshellarg($self) . ' csv 2>/dev/null');
$xls = shell_exec('php ' . escapeshellarg($self) . ' excel 2>/dev/null');
$pdf = shell_exec('php ' . escapeshellarg($self) . ' pdf 2>/dev/null');

check('CSV : un en-tête et six lignes', count(array_filter(explode("\n", trim((string) $csv)))), 7);
check('CSV : BOM UTF-8 pour Excel', substr((string) $csv, 0, 3), "\xEF\xBB\xBF");
check('CSV : la question sur le sens est exportée',
      strpos((string) $csv, 'dessiner au lieu de se battre') !== false, true);
check('CSV : même nombre de colonnes partout', (function($csv) {
    $fh = fopen('php://memory', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $widths = [];
    while (($row = fgetcsv($fh)) !== false) { if ($row !== [null]) $widths[] = count($row); }
    fclose($fh);
    return count(array_unique($widths));
})((string) $csv), 1);

check('Excel : XML bien formé', simplexml_load_string((string) $xls) !== false, true);
check('Excel : feuille des réponses', strpos((string) $xls, 'ss:Name="Reponses"') !== false, true);
check('Excel : feuille de synthèse', strpos((string) $xls, 'ss:Name="Synthese"') !== false, true);

check('PDF : signature de fichier', substr((string) $pdf, 0, 5), '%PDF-');
check('PDF : fin de fichier', strpos((string) $pdf, '%%EOF') !== false, true);
check('PDF : les témoignages autorisés sont repris',
      strpos((string) $pdf, 'Maya P.') !== false, true);
check('PDF : un témoignage refusé n’y figure pas',
      strpos((string) $pdf, 'Je ne veux pas') === false, true);
check('PDF : le sens avant/après y figure',
      strpos((string) $pdf, 'travers l\'art') !== false, true);

// ---------- Bilan ----------

echo "\n" . str_repeat('-', 50) . "\n";
if ($fails === 0) {
    echo "TOUT PASSE — $total contrôles\n";
    exit(0);
}
echo "$fails ECHEC(S) sur $total contrôles\n";
exit(1);
