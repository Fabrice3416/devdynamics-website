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

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Configuration manquante : bousol/includes/config.php (copier depuis config.example.php)');
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
