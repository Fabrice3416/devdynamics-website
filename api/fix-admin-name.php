<?php
/**
 * Fix Admin Name
 * Updates the admin user to have a proper full_name
 */

require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance();

    // Update admin user with proper name
    $db->query(
        "UPDATE users SET full_name = ? WHERE email = ?",
        ['Administrateur', 'contact@dev-dynamics.org']
    );

    // Verify
    $admin = $db->fetchOne(
        "SELECT id, email, full_name, role FROM users WHERE email = ?",
        ['contact@dev-dynamics.org']
    );

    echo json_encode([
        'success' => true,
        'message' => 'Admin name updated successfully',
        'admin' => $admin
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
