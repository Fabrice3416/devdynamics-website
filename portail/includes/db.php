<?php
declare(strict_types=1);

/**
 * Connexion PDO MySQL - Singleton
 * Usage : $pdo = db();
 */

final class Db
{
    private static ?PDO $pdo = null;

    public static function getInstance(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $configFile = __DIR__ . '/config.php';
        if (!file_exists($configFile)) {
            http_response_code(500);
            exit('Configuration manquante : portail/includes/config.php (copier depuis config.example.php)');
        }

        $config = require $configFile;
        $cfg = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['name'],
            $cfg['charset'] ?? 'utf8mb4'
        );

        try {
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
            ]);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
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
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}
