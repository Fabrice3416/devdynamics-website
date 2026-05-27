<?php
declare(strict_types=1);

/**
 * Authentification, sessions, CSRF, controle de role
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

const SESSION_NAME = 'PORTAIL_SID';

function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $cfg = config();
    $httpsOnly = ($_SERVER['HTTPS'] ?? '') === 'on'
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/portail/',
        'domain'   => '',
        'secure'   => $httpsOnly,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();

    // CSRF token genere une fois par session
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // Timeout 60 min
    $ttl = $cfg['app']['session_ttl'] ?? 3600;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $ttl) {
        destroy_session();
        header('Location: /portail/login.php?expired=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function destroy_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

function user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function user_role(): ?string
{
    return $_SESSION['role'] ?? null;
}

function user_email(): ?string
{
    return $_SESSION['email'] ?? null;
}

function user_nom(): ?string
{
    return $_SESSION['nom_complet'] ?? null;
}

function is_logged_in(): bool
{
    return user_id() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /portail/login.php');
        exit;
    }
}

/**
 * Verifie que l'utilisateur connecte a un des roles autorises.
 * Sinon : 403 + audit log.
 *
 * @param string[] $roles
 */
function check_role(array $roles): void
{
    require_login();
    if (!in_array(user_role(), $roles, true)) {
        try {
            $stmt = db()->prepare(
                "INSERT INTO audit_log (user_id, action, description, ip_address, user_agent)
                 VALUES (?, 'logout', ?, ?, ?)"
            );
            $stmt->execute([
                user_id(),
                'Tentative acces non autorise : ' . ($_SERVER['REQUEST_URI'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('audit_log insert failed: ' . $e->getMessage());
        }
        http_response_code(403);
        exit('403 - Acces refuse');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($submitted) || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted)) {
        http_response_code(403);
        exit('403 - Token CSRF invalide');
    }
}

/**
 * Connecte un utilisateur (apres verification password).
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$user['id'];
    $_SESSION['role']         = $user['role'];
    $_SESSION['email']        = $user['email'];
    $_SESSION['nom_complet']  = $user['nom_complet'];
    $_SESSION['last_activity'] = time();

    try {
        $stmt = db()->prepare('UPDATE users SET derniere_connexion = NOW() WHERE id = ?');
        $stmt->execute([(int)$user['id']]);

        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, action, description, ip_address, user_agent)
             VALUES (?, 'login', 'Connexion reussie', ?, ?)"
        );
        $stmt->execute([
            (int)$user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('login_user audit failed: ' . $e->getMessage());
    }
}

function logout_user(): void
{
    if (is_logged_in()) {
        try {
            $stmt = db()->prepare(
                "INSERT INTO audit_log (user_id, action, description, ip_address, user_agent)
                 VALUES (?, 'logout', 'Deconnexion', ?, ?)"
            );
            $stmt->execute([
                user_id(),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('logout audit failed: ' . $e->getMessage());
        }
    }
    destroy_session();
}

/**
 * Rate limiting basique pour les tentatives de login.
 * Compte les tentatives 'login' echouees (champ description = 'echec_login')
 * sur les X dernieres secondes pour une IP donnee.
 */
function login_attempts_exceeded(string $ip): bool
{
    $cfg = config();
    $max    = $cfg['security']['login_max_attempts'] ?? 5;
    $window = $cfg['security']['login_window'] ?? 300;

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE ip_address = ?
                AND action = 'login'
                AND description = 'echec_login'
                AND created_at > (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, $window]);
        return ((int)$stmt->fetchColumn()) >= $max;
    } catch (Throwable $e) {
        return false;
    }
}

function log_failed_login(string $email, string $ip): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, action, description, ip_address, user_agent)
             VALUES (NULL, 'login', ?, ?, ?)"
        );
        $stmt->execute([
            'echec_login - email: ' . substr($email, 0, 80),
            $ip,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('log_failed_login failed: ' . $e->getMessage());
    }
}

// Demarre la session pour toute page qui inclut auth.php
start_secure_session();
