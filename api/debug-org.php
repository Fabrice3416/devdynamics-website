<?php
/**
 * Debug Organization Info
 * Quick diagnostic to see what's in the database
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $org = $db->fetchOne("SELECT * FROM organization_info LIMIT 1");

    echo json_encode([
        'success' => true,
        'data' => $org,
        'fields' => $org ? array_keys($org) : []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
