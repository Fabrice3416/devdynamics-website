<?php
declare(strict_types=1);

/**
 * Connexion PDO MySQL (singleton) + acces a la configuration.
 * Usage : $pdo = db();  $cfg = config();
 */

final class Db
{
    private static ?PDO $pdo = null;

    public static function getInstance(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = config()['db'];
        $dsn = !empty($cfg['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4')
            : sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['name'], $cfg['charset'] ?? 'utf8mb4');
        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            error_log('Bousol DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            exit('Erreur de connexion a la base de donnees.');
        }
        return self::$pdo;
    }
}

function db(): PDO
{
    return Db::getInstance();
}

/**
 * Emplacement du fichier de configuration, par ordre de preference.
 *
 * Le cahier des charges (7.4) le veut hors depot ET hors racine web : sous la racine,
 * il ne serait protege que par le .htaccess, et un hebergement mutualise reinitialise
 * periodiquement les permissions des fichiers servis. Place un cran au-dessus de
 * public_html, il n'est atteignable par aucune URL, quelles que soient ses permissions.
 */
function config_path(): ?string
{
    static $trouve = false;
    static $chemin = null;
    if ($trouve) {
        return $chemin;
    }
    $trouve = true;
    $candidats = [];
    if ($env = getenv('BOUSOL_CONFIG')) {
        $candidats[] = $env;
    }
    $candidats[] = dirname(root_dir(), 2) . '/bousol-config.php';  // hors racine web
    $candidats[] = __DIR__ . '/config.php';                        // repli historique
    foreach ($candidats as $c) {
        if (is_file($c) && is_readable($c)) {
            $chemin = $c;
            return $chemin;
        }
    }
    return null;
}

/** Le fichier de configuration est-il hors de la racine web ? */
function config_hors_racine_web(): bool
{
    $c = config_path();
    return $c !== null && !str_starts_with(realpath($c) ?: $c, realpath(root_dir()) ?: root_dir());
}

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = config_path();
        if ($file === null) {
            http_response_code(500);
            exit('Configuration manquante : copier includes/config.example.php vers '
               . dirname(root_dir(), 2) . '/bousol-config.php');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['app']['timezone'] ?? 'America/Port-au-Prince');
    }
    return $cfg;
}

function base_path(string $path = ''): string
{
    return (config()['app']['base_path'] ?? '/bousol/') . ltrim($path, '/');
}

function root_dir(): string
{
    return dirname(__DIR__);
}
