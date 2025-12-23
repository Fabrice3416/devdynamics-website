<?php
/**
 * Reset admin password
 */

require_once __DIR__ . '/config/database.php';

echo "=== Reset Admin Password ===\n\n";

try {
    $db = Database::getInstance();

    $email = 'admin@test.com';
    $password = 'admin123';

    // Check if user exists
    $user = $db->fetchOne(
        "SELECT id, email, password_hash FROM users WHERE email = ? LIMIT 1",
        [$email]
    );

    if (!$user) {
        echo "User not found. Creating new admin...\n";

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $db->query(
            "INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            ['Admin Test', $email, $hashedPassword]
        );

        echo "Admin created successfully!\n";
    } else {
        echo "User found (ID: {$user['id']})\n";
        echo "Current password hash: " . substr($user['password_hash'], 0, 30) . "...\n\n";

        // Generate new hash
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        echo "New password hash: " . substr($hashedPassword, 0, 30) . "...\n\n";

        // Update password
        $db->query(
            "UPDATE users SET password_hash = ?, role = 'admin' WHERE email = ?",
            [$hashedPassword, $email]
        );

        echo "Password updated successfully!\n\n";

        // Verify the update
        $verify = $db->fetchOne(
            "SELECT password_hash FROM users WHERE email = ? LIMIT 1",
            [$email]
        );

        echo "Verification - Updated hash: " . substr($verify['password_hash'], 0, 30) . "...\n\n";

        // Test password verification
        if (password_verify($password, $verify['password_hash'])) {
            echo "✓ Password verification test: SUCCESS\n";
        } else {
            echo "✗ Password verification test: FAILED\n";
        }
    }

    echo "\nLogin credentials:\n";
    echo "-------------------\n";
    echo "Email:    $email\n";
    echo "Password: $password\n";
    echo "\nYou can now login at http://localhost/api/auth/login\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
