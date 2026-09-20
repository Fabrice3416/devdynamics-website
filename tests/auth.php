<?php
/**
 * Tests de la connexion administrateur — s'exécute sans base de données.
 *
 *     php tests/auth.php
 *
 * Ces tests existent parce que la connexion a été cassée pendant des mois sans
 * que rien ne le signale : le code lisait $user['password'] alors que la colonne
 * s'appelle password_hash, et password_verify(null) echoue en silence.
 *
 * SCHEMA_USERS ci-dessous recopie la table reelle. Si elle change en base, ces
 * tests doivent changer avec elle — c'est justement ce qui manquait.
 */

$ROOT = dirname(__DIR__);

// Colonnes reelles de la table users (DESCRIBE users, 2026-09-20)
const SCHEMA_USERS = [
    'id', 'email', 'password_hash', 'full_name', 'phone_number', 'date_of_birth',
    'profile_image', 'email_verified', 'is_active', 'role', 'created_at', 'updated_at',
];

// Valeurs acceptees par l'enum users.role
const SCHEMA_ROLES = ['admin', 'editor', 'student'];

// ---------- Doublures ----------

// Herite d'Error : en production Response::error() fait exit(), l'arret ne doit
// donc pas etre rattrape par les catch (Exception) des routes.
class AuthHalt extends Error {
    public $payload;
    public function __construct($code, $message, $payload = null) {
        parent::__construct($message, $code);
        $this->payload = $payload;
    }
}

class Response {
    public static function success($data = null, $message = 'Success', $code = 200) {
        throw new AuthHalt($code, $message, $data);
    }
    public static function error($message = 'Error', $code = 400, $data = null) {
        throw new AuthHalt($code, $message, $data);
    }
    public static function unauthorized($m = 'Unauthorized') { self::error($m, 401); }
    public static function forbidden($m = 'Forbidden') { self::error($m, 403); }
    public static function notFound($m = 'Not found') { self::error($m, 404); }
}

class Router {
    private static $instance = null;
    public static $routes = [];
    public static $body = [];
    public static $headers = [];

    public static function getInstance() {
        return self::$instance ?: (self::$instance = new self());
    }
    private function add($m, $p, $c) { self::$routes["$m $p"] = $c; }
    public function get($p, $c, $mw = []) { $this->add('GET', $p, $c); }
    public function post($p, $c, $mw = []) { $this->add('POST', $p, $c); }
    public function put($p, $c, $mw = []) { $this->add('PUT', $p, $c); }
    public function delete($p, $c, $mw = []) { $this->add('DELETE', $p, $c); }

    public static function getBody() { return self::$body; }
    public static function getHeaders() { return self::$headers; }
    public static function getQueryParam($k, $d = null) { return $d; }

    public static function call($key, $params = []) {
        if (!isset(self::$routes[$key])) throw new Exception("Route absente : $key");
        return call_user_func(self::$routes[$key], $params);
    }
}

class Database {
    public static $user = null;     // ligne renvoyee par le SELECT de connexion
    public static $queries = [];

    public static function getInstance() { return new self(); }
    public function fetchAll($sql, $p = []) { return []; }
    public function fetchOne($sql, $p = []) { self::$queries[] = [$sql, $p]; return self::$user; }
    public function query($sql, $p = []) { self::$queries[] = [$sql, $p]; return null; }
    public function lastInsertId() { return 7; }
}

// JWT.php n'a besoin ni de base ni de .env : on charge le vrai code.
require_once $ROOT . '/api/middleware/auth.php';
require_once $ROOT . '/api/routes/auth.php';

// ---------- Cadre ----------

$fails = 0; $total = 0;
function check($label, $actual, $expected) {
    global $fails, $total;
    $total++;
    if ($actual === $expected) { echo "  ok     $label\n"; return; }
    $fails++;
    echo "  ECHEC  $label\n";
    echo "         attendu : " . trim(var_export($expected, true)) . "\n";
    echo "         obtenu  : " . trim(var_export($actual, true)) . "\n";
}
function section($t) { echo "\n== $t ==\n"; }

// ---------- Utilitaires ----------

const MOT_DE_PASSE = 'MotDePasseDeTest-2026';

function compte($overrides = []) {
    return array_merge([
        'id' => 1,
        'email' => 'contact@dev-dynamics.org',
        'password_hash' => password_hash(MOT_DE_PASSE, PASSWORD_BCRYPT),
        'full_name' => 'Admin',
        'phone_number' => null,
        'date_of_birth' => null,
        'profile_image' => null,
        'email_verified' => 0,
        'is_active' => 1,
        'role' => 'admin',
        'created_at' => '2025-12-18 15:27:03',
        'updated_at' => '2025-12-24 00:42:01',
    ], $overrides);
}

function connexion($email, $motDePasse, $user) {
    Database::$user = $user;
    Database::$queries = [];
    Router::$body = ['email' => $email, 'password' => $motDePasse];
    try {
        Router::call('POST \/auth/login');
    } catch (AuthHalt $halt) {
        return $halt;
    }
    return null;
}

// ============================================================
// A. Conformité entre le code et le schéma réel
// ============================================================

section('Conformité au schéma de users');

/**
 * Le code sans ses commentaires : sinon un commentaire qui cite une ancienne
 * colonne ferait echouer le controle, et on prendrait la prose pour du code.
 */
function code_sans_commentaires($chemin) {
    $net = '';
    foreach (token_get_all(file_get_contents($chemin)) as $jeton) {
        if (is_array($jeton)) {
            if ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT) continue;
            $net .= $jeton[1];
        } else {
            $net .= $jeton;
        }
    }
    return $net;
}

$sources = [
    'api/routes/auth.php'  => code_sans_commentaires($ROOT . '/api/routes/auth.php'),
    'api/routes/admin.php' => code_sans_commentaires($ROOT . '/api/routes/admin.php'),
];

// Colonnes citees dans les requetes qui touchent la table users
$inconnues = [];
foreach ($sources as $fichier => $code) {
    // SELECT <liste> FROM users
    if (preg_match_all('/"SELECT\s+([^"]+?)\s+FROM users/i', $code, $m)) {
        foreach ($m[1] as $liste) {
            if (trim($liste) === '*') continue;
            foreach (explode(',', $liste) as $col) {
                $col = trim($col);
                if ($col !== '' && !in_array($col, SCHEMA_USERS, true)) {
                    $inconnues[] = "$fichier : SELECT $col";
                }
            }
        }
    }
    // INSERT INTO users (<liste>)
    if (preg_match_all('/INSERT INTO users\s*\(([^)]*)\)/i', $code, $m)) {
        foreach ($m[1] as $liste) {
            foreach (explode(',', $liste) as $col) {
                $col = trim($col);
                if ($col !== '' && !in_array($col, SCHEMA_USERS, true)) {
                    $inconnues[] = "$fichier : INSERT $col";
                }
            }
        }
    }
    // UPDATE users SET <col> =
    if (preg_match_all('/UPDATE users SET\s+([a-z_]+)\s*=/is', $code, $m)) {
        foreach ($m[1] as $col) {
            if (!in_array($col, SCHEMA_USERS, true)) {
                $inconnues[] = "$fichier : UPDATE $col";
            }
        }
    }
}
check('aucune colonne inconnue dans les requêtes', $inconnues, []);

// Cles lues sur la ligne utilisateur ($user['...'])
preg_match_all("/\\\$user\['([a-z_]+)'\]/", $sources['api/routes/auth.php'], $m);
$clesLues = array_values(array_unique($m[1]));
check('aucune clé lue hors du schéma',
      array_values(array_diff($clesLues, SCHEMA_USERS)), []);

// Roles acceptes par la route d'administration
preg_match("/in_array\(\\\$body\['role'\],\s*\[(.*?)\]\)/s", $sources['api/routes/admin.php'], $m);
$rolesCode = array_map(fn($r) => trim($r, " '\""), explode(',', $m[1] ?? ''));
check('les rôles acceptés correspondent à l’enum', $rolesCode, SCHEMA_ROLES);

// Le front ne doit plus tester un role qui n'existe pas
$fichiersFront = [
    'js/pages/admin-login.js', 'js/pages/admin-dashboard.js', 'js/pages/admin-course-builder.js',
];
$rolesFront = [];
foreach ($fichiersFront as $f) {
    preg_match_all("/user\.role !== '([a-z_]+)'/", file_get_contents($ROOT . '/' . $f), $m);
    foreach ($m[1] as $role) {
        if (!in_array($role, SCHEMA_ROLES, true)) $rolesFront[] = "$f : $role";
    }
}
check('aucun rôle inexistant testé côté navigateur', $rolesFront, []);

// ============================================================
// B. Connexion
// ============================================================

section('Connexion');

$halt = connexion('contact@dev-dynamics.org', MOT_DE_PASSE, compte());
check('bon mot de passe : 200', $halt->getCode(), 200);
check('un jeton est délivré', isset($halt->payload['token']), true);
check('le nom renvoyé est full_name', $halt->payload['user']['full_name'] ?? null, 'Admin');
check('le rôle est renvoyé', $halt->payload['user']['role'] ?? null, 'admin');

// Ce que la regression precedente rendait impossible
check('le jeton se relit', JWT::decode($halt->payload['token'])['email'], 'contact@dev-dynamics.org');

$halt = connexion('contact@dev-dynamics.org', 'mauvais', compte());
check('mauvais mot de passe : 401', $halt->getCode(), 401);

$halt = connexion('inconnu@example.invalid', MOT_DE_PASSE, null);
check('compte inexistant : 401', $halt->getCode(), 401);
check('le message ne distingue pas les deux cas', $halt->getMessage(), 'Invalid credentials');

Router::$body = ['email' => 'contact@dev-dynamics.org'];
try { Router::call('POST \/auth/login'); $halt = null; } catch (AuthHalt $h) { $halt = $h; }
check('mot de passe absent : 400', $halt->getCode(), 400);

// ============================================================
// C. Compte désactivé
// ============================================================

section('Compte désactivé');

$halt = connexion('contact@dev-dynamics.org', MOT_DE_PASSE, compte(['is_active' => 0]));
check('compte désactivé : 403', $halt->getCode(), 403);
check('aucun jeton n’est délivré', isset($halt->payload['token']), false);

// Le controle vient apres le mot de passe : sinon la reponse revelerait
// l'existence d'un compte a qui ne le connait pas.
$halt = connexion('contact@dev-dynamics.org', 'mauvais', compte(['is_active' => 0]));
check('mauvais mot de passe sur compte désactivé : 401, pas 403', $halt->getCode(), 401);

// ============================================================
// D. Rôles
// ============================================================

section('Rôles');

function middlewareAvecRole($role) {
    $token = JWT::encode(['id' => 1, 'email' => 'x@y.z', 'role' => $role]);
    Router::$headers = ['Authorization' => 'Bearer ' . $token];
    try {
        editorMiddleware();
    } catch (AuthHalt $halt) {
        return $halt->getCode();
    }
    return 200;
}

check('un admin passe editorMiddleware', middlewareAvecRole('admin'), 200);
check('un editor passe editorMiddleware', middlewareAvecRole('editor'), 200);
check('un student est refusé', middlewareAvecRole('student'), 403);
check('le rôle instructor n’existe plus', function_exists('instructorMiddleware'), false);
check('editorMiddleware a remplacé instructorMiddleware', function_exists('editorMiddleware'), true);

Router::$headers = [];
try { editorMiddleware(); $code = 200; } catch (AuthHalt $h) { $code = $h->getCode(); }
check('sans jeton : 401', $code, 401);

// ---------- Bilan ----------

echo "\n" . str_repeat('-', 50) . "\n";
if ($fails === 0) {
    echo "TOUT PASSE — $total contrôles\n";
    exit(0);
}
echo "$fails ECHEC(S) sur $total contrôles\n";
exit(1);
