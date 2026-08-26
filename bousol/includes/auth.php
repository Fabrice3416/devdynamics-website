<?php
declare(strict_types=1);

/**
 * Sessions, authentification, CSRF, roles, reauthentification pour signature.
 *
 * Roles applicatifs (CDC 1.6) : coordinateur | raf | mandataire
 * La qualite de mandataire est un attribut du TIERS (personne), pas du role.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';

const SESSION_NAME = 'BOUSOL_SID';
const ROLES = ['coordinateur', 'raf', 'mandataire'];

function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $cfg = config();
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cfg['app']['base_path'] ?? '/bousol/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $ttl = (int)($cfg['app']['session_ttl'] ?? 3600);
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $ttl) {
        destroy_session();
        redirect(base_path('login.php?expired=1'));
    }
    $_SESSION['last_activity'] = time();
}

function destroy_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        session_destroy();
    }
}

function user_id(): ?int     { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function user_role(): ?string { return $_SESSION['user_role'] ?? null; }
function user_nom(): ?string  { return $_SESSION['user_nom'] ?? null; }
function user_tiers_id(): ?int { return isset($_SESSION['tiers_id']) ? (int)$_SESSION['tiers_id'] : null; }
function user_est_mandataire(): bool { return !empty($_SESSION['est_mandataire']); }
function is_logged_in(): bool { return user_id() !== null; }

function require_login(): void
{
    if (!is_logged_in()) {
        redirect(base_path('login.php'));
    }
    if (!empty($_SESSION['doit_changer_mdp']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'profil.php') {
        redirect(base_path('profil.php?mdp=1'));
    }
}

/** @param string[] $roles */
function require_role(array $roles): void
{
    require_login();
    if (!in_array(user_role(), $roles, true)) {
        audit('noyau', 'acces_refuse', null, null, 'URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
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
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        exit('403 - Token CSRF invalide');
    }
}

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare(
        'SELECT u.*, t.nom AS tiers_nom, t.est_mandataire
           FROM utilisateurs u JOIN tiers t ON t.id = u.tiers_id
          WHERE u.email = ? AND u.actif = 1 LIMIT 1'
    );
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']         = (int)$u['id'];
    $_SESSION['user_role']       = $u['role'];
    $_SESSION['user_nom']        = $u['tiers_nom'];
    $_SESSION['user_email']      = $u['email'];
    $_SESSION['tiers_id']        = (int)$u['tiers_id'];
    $_SESSION['est_mandataire']  = (int)$u['est_mandataire'] === 1;
    $_SESSION['doit_changer_mdp'] = (int)$u['doit_changer_mdp'] === 1;
    $_SESSION['last_activity']   = time();
    $_SESSION['reauth_at']       = 0;

    db()->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?')->execute([(int)$u['id']]);
    audit('noyau', 'login', 'utilisateur', (int)$u['id'], 'Connexion reussie');
}

function logout_user(): void
{
    if (is_logged_in()) {
        audit('noyau', 'logout', 'utilisateur', user_id(), 'Deconnexion');
    }
    destroy_session();
}

function login_attempts_exceeded(string $ip): bool
{
    $cfg = config()['security'];
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM journal_audit
              WHERE ip = ? AND action = 'login_echec' AND horodatage > (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([$ip, (int)($cfg['login_window'] ?? 300)]);
        return (int)$stmt->fetchColumn() >= (int)($cfg['login_max_attempts'] ?? 5);
    } catch (Throwable) {
        return false;
    }
}

function log_failed_login(string $email): void
{
    audit('noyau', 'login_echec', null, null, 'email: ' . substr($email, 0, 80), null, null, null, null);
}

/**
 * Reauthentification distincte de l'ouverture de session (CDC 1.8, 7.4).
 * Obligatoire avant toute apposition de specimen.
 */
function reauthenticate(string $password): bool
{
    $stmt = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = ? AND actif = 1');
    $stmt->execute([user_id()]);
    $hash = $stmt->fetchColumn();
    if ($hash && password_verify($password, (string)$hash)) {
        $_SESSION['reauth_at'] = time();
        audit('signature', 'reauthentification', 'utilisateur', user_id(), 'Reauthentification reussie');
        return true;
    }
    audit('signature', 'reauthentification_echec', 'utilisateur', user_id());
    return false;
}

function reauth_valid(): bool
{
    $ttl = (int)(config()['security']['reauth_ttl'] ?? 120);
    return (time() - (int)($_SESSION['reauth_at'] ?? 0)) <= $ttl;
}

function hash_password(string $plain): string
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => (int)(config()['security']['bcrypt_cost'] ?? 12)]);
}

start_secure_session();
