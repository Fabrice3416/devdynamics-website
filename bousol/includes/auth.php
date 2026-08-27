<?php
declare(strict_types=1);

/**
 * Sessions, authentification, projet courant, droits, reauthentification.
 *
 * Le role n'est pas un attribut de l'utilisateur mais une AFFECTATION, lien entre
 * une personne, un projet et un role (CDC 2.0, 1.8). Un utilisateur ne voit que les
 * projets auxquels il est affecte, et travaille toujours a l'interieur d'un seul.
 *
 * L'administrateur de l'outil est unique et exterieur aux projets : il les cree et
 * n'y saisit rien. A ne pas confondre avec l'Administrateur des budgets, qui est le
 * Responsable Administratif et Financier.
 *
 * La qualite de mandataire est un attribut du TIERS (la personne), attachee au compte
 * bancaire et non au projet.
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

function user_id(): ?int      { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function user_nom(): ?string   { return $_SESSION['user_nom'] ?? null; }
function user_tiers_id(): ?int { return isset($_SESSION['tiers_id']) ? (int)$_SESSION['tiers_id'] : null; }
function user_est_mandataire(): bool { return !empty($_SESSION['est_mandataire']); }
function user_est_admin_outil(): bool { return !empty($_SESSION['admin_outil']); }
function is_logged_in(): bool  { return user_id() !== null; }

/** Projet courant, et role que l'utilisateur y tient. */
function projet_id(): ?int     { return isset($_SESSION['projet_id']) ? (int)$_SESSION['projet_id'] : null; }
function projet_code(): ?string { return $_SESSION['projet_code'] ?? null; }
function projet_intitule(): ?string { return $_SESSION['projet_intitule'] ?? null; }
function user_role(): ?string  { return $_SESSION['role_projet'] ?? null; }

/**
 * Projets auxquels l'utilisateur est affecte aujourd'hui.
 * L'administrateur de l'outil les voit tous, sans role a l'interieur.
 */
function projets_accessibles(): array
{
    if (!is_logged_in()) {
        return [];
    }
    if (user_est_admin_outil()) {
        return db()->query("SELECT p.*, NULL AS role FROM projets p WHERE p.statut <> 'archive' ORDER BY p.intitule")->fetchAll();
    }
    $stmt = db()->prepare(
        "SELECT p.*, a.role
           FROM affectations a JOIN projets p ON p.id = a.projet_id
          WHERE a.utilisateur_id = ? AND p.statut <> 'archive'
            AND a.date_debut <= CURDATE() AND (a.date_fin IS NULL OR a.date_fin >= CURDATE())
          ORDER BY p.intitule"
    );
    $stmt->execute([user_id()]);
    return $stmt->fetchAll();
}

/** Role tenu par l'utilisateur dans un projet donne, ou null s'il n'y est pas affecte. */
function role_dans_projet(int $projetId, ?int $userId = null): ?string
{
    $stmt = db()->prepare(
        'SELECT role FROM affectations
          WHERE utilisateur_id = ? AND projet_id = ?
            AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())
          ORDER BY FIELD(role, \'coordinateur\', \'raf\', \'mandataire\') LIMIT 1'
    );
    $stmt->execute([$userId ?? user_id(), $projetId]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (string)$r;
}

/**
 * Selectionne le projet courant. Refuse si l'utilisateur n'y est pas affecte :
 * l'absence d'affectation vaut absence d'acces, y compris en lecture (CDC 1.8).
 */
function choisir_projet(int $projetId): bool
{
    $stmt = db()->prepare("SELECT * FROM projets WHERE id = ? AND statut <> 'archive'");
    $stmt->execute([$projetId]);
    $p = $stmt->fetch();
    if (!$p) {
        return false;
    }
    $role = role_dans_projet($projetId);
    if ($role === null && !user_est_admin_outil()) {
        audit('noyau', 'acces_projet_refuse', 'projet', $projetId, 'Aucune affectation en cours');
        return false;
    }
    $_SESSION['projet_id']       = (int)$p['id'];
    $_SESSION['projet_code']     = $p['code'];
    $_SESSION['projet_intitule'] = $p['intitule'];
    $_SESSION['role_projet']     = $role;
    return true;
}

/** Aucune donnee d'execution ne se consulte hors d'un projet. */
function require_projet(): void
{
    require_login();
    if (projet_id() === null) {
        redirect(base_path('projets.php'));
    }
}

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
/** Droit a l'interieur du projet courant. La matrice de l'annexe B ne joue jamais transversalement. */
function require_role(array $roles): void
{
    require_projet();
    if (!in_array(user_role(), $roles, true)) {
        audit('noyau', 'acces_refuse', 'projet', projet_id(), 'Rôle ' . (user_role() ?? 'aucun') . ' · URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        exit('403 - Acces refuse');
    }
}

/** Creer un projet, y designer un coordinateur, prononcer la cloture : l'administrateur de l'outil seul. */
function require_admin_outil(): void
{
    require_login();
    if (!user_est_admin_outil()) {
        audit('noyau', 'acces_refuse', null, null, 'Administration de l\'outil · URI: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        exit('403 - Reserve a l\'administrateur de l\'outil');
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
    $_SESSION['admin_outil']     = (int)$u['admin_outil'] === 1;
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
