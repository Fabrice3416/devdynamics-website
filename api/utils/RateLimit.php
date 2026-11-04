<?php
/**
 * Limite d'envois par adresse IP pour les formulaires publics.
 *
 * L'adresse n'est jamais conservee, seulement son empreinte. Si la table manque
 * ou que la base hoquette, l'envoi passe : mieux vaut un message de trop qu'un
 * visiteur refuse par erreur.
 */

function rate_limited($db, $scope, $max, $seconds) {
    $key = hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $seconds = (int) $seconds;

    try {
        // MySQL applique les affectations dans l'ordre : hits lit l'ancien window_start
        $db->query(
            "INSERT INTO rate_limits (key_hash, hits, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                hits = IF(window_start < NOW() - INTERVAL {$seconds} SECOND, 1, hits + 1),
                window_start = IF(window_start < NOW() - INTERVAL {$seconds} SECOND, NOW(), window_start)",
            [$key]
        );
        $row = $db->fetchOne("SELECT hits FROM rate_limits WHERE key_hash = ? LIMIT 1", [$key]);

        if (random_int(1, 100) === 1) {
            $db->query("DELETE FROM rate_limits WHERE window_start < NOW() - INTERVAL 1 DAY");
        }
        return (int) ($row['hits'] ?? 0) > $max;
    } catch (Exception $e) {
        error_log('rate_limited: ' . $e->getMessage());
        return false;
    }
}
