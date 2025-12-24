<?php
/**
 * Create Admin User Script
 * IMPORTANT: Delete this file after use for security!
 *
 * Usage: https://votre-domaine.com/api/create-admin.php?token=SECURITY_TOKEN_HERE
 */

// Security token (change this to a random value)
define('SECURITY_TOKEN', 'CREATE_ADMIN_2024_SECURE_TOKEN');

// Admin credentials to create
define('ADMIN_EMAIL', 'admin@devdynamics.com');
define('ADMIN_PASSWORD', 'ChangeThisPassword123!');
define('ADMIN_NAME', 'Administrator');

require_once __DIR__ . '/config/database.php';

// Check security token
if (!isset($_GET['token']) || $_GET['token'] !== SECURITY_TOKEN) {
    http_response_code(403);
    die('Access denied. Invalid security token.');
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin - DevDynamics</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-top: 0; }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border: 1px solid #f5c6cb;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border: 1px solid #ffeaa7;
        }
        .info-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #2196F3;
        }
        .credentials {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-family: monospace;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #dc3545;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 15px;
        }
        .btn:hover {
            background: #c82333;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 Create Admin User</h1>

<?php

try {
    $db = Database::getInstance();

    echo '<div class="info-box">';
    echo '<strong>ℹ️ Status:</strong> Connecting to database...<br>';
    echo '<strong>Database:</strong> ' . (getenv('DB_NAME') ?: 'devdynamics_db') . '<br>';
    echo '<strong>Host:</strong> ' . (getenv('DB_HOST') ?: 'localhost') . '<br>';
    echo '</div>';

    // Check if admin already exists
    $existingAdmin = $db->fetchOne(
        "SELECT id, email, role FROM users WHERE email = ? LIMIT 1",
        [ADMIN_EMAIL]
    );

    if ($existingAdmin) {
        echo '<div class="warning">';
        echo '<strong>⚠️ Warning:</strong> Admin user already exists!<br><br>';
        echo '<strong>User ID:</strong> ' . $existingAdmin['id'] . '<br>';
        echo '<strong>Email:</strong> ' . $existingAdmin['email'] . '<br>';
        echo '<strong>Role:</strong> ' . $existingAdmin['role'] . '<br><br>';
        echo 'Updating password...';
        echo '</div>';

        // Update existing admin
        $hashedPassword = password_hash(ADMIN_PASSWORD, PASSWORD_BCRYPT);
        $db->query(
            "UPDATE users SET password_hash = ?, role = 'admin', full_name = ? WHERE email = ?",
            [$hashedPassword, ADMIN_NAME, ADMIN_EMAIL]
        );

        echo '<div class="success">';
        echo '<strong>✅ Success:</strong> Admin password updated successfully!';
        echo '</div>';

    } else {
        echo '<div class="info-box">';
        echo 'Creating new admin user...';
        echo '</div>';

        // Create new admin
        $hashedPassword = password_hash(ADMIN_PASSWORD, PASSWORD_BCRYPT);
        $db->query(
            "INSERT INTO users (full_name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [ADMIN_NAME, ADMIN_EMAIL, $hashedPassword]
        );

        $userId = $db->lastInsertId();

        echo '<div class="success">';
        echo '<strong>✅ Success:</strong> Admin user created successfully!<br>';
        echo '<strong>User ID:</strong> ' . $userId;
        echo '</div>';
    }

    // Verify password
    $verify = $db->fetchOne(
        "SELECT id, email, password_hash, role FROM users WHERE email = ? LIMIT 1",
        [ADMIN_EMAIL]
    );

    if (password_verify(ADMIN_PASSWORD, $verify['password_hash'])) {
        echo '<div class="success">';
        echo '<strong>✅ Password Verification:</strong> SUCCESS';
        echo '</div>';
    } else {
        echo '<div class="error">';
        echo '<strong>❌ Password Verification:</strong> FAILED';
        echo '</div>';
    }

    // Display credentials
    echo '<div class="credentials">';
    echo '<h3>📋 Login Credentials</h3>';
    echo '<strong>Email:</strong> ' . ADMIN_EMAIL . '<br>';
    echo '<strong>Password:</strong> ' . ADMIN_PASSWORD . '<br>';
    echo '<strong>Role:</strong> admin<br>';
    echo '</div>';

    echo '<div class="warning">';
    echo '<h3>⚠️ IMPORTANT SECURITY STEPS</h3>';
    echo '<ol>';
    echo '<li><strong>Change the password immediately</strong> after first login</li>';
    echo '<li><strong>Delete this file</strong> from your server right now!</li>';
    echo '<li>This script should never be accessible in production</li>';
    echo '</ol>';
    echo '</div>';

    echo '<div class="info-box">';
    echo '<h3>🔗 Next Steps</h3>';
    echo '<ol>';
    echo '<li>Login at: <code>' . (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'your-domain.com') . '/api/auth/login</code></li>';
    echo '<li>Test the login with the credentials above</li>';
    echo '<li><strong>DELETE THIS FILE:</strong> <code>api/create-admin.php</code></li>';
    echo '</ol>';
    echo '</div>';

    // Self-destruct option
    if (isset($_GET['delete']) && $_GET['delete'] === 'confirm') {
        if (unlink(__FILE__)) {
            echo '<div class="success">';
            echo '<strong>✅ Security:</strong> This file has been deleted successfully!';
            echo '</div>';
        } else {
            echo '<div class="error">';
            echo '<strong>❌ Error:</strong> Could not delete file. Please delete manually: <code>' . __FILE__ . '</code>';
            echo '</div>';
        }
    } else {
        echo '<a href="?token=' . SECURITY_TOKEN . '&delete=confirm" class="btn">🗑️ Delete This File Now</a>';
    }

} catch (Exception $e) {
    echo '<div class="error">';
    echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';

    echo '<div class="info-box">';
    echo '<h4>Troubleshooting:</h4>';
    echo '<ul>';
    echo '<li>Check that your <code>.env</code> file exists in the <code>api</code> folder</li>';
    echo '<li>Verify database credentials in <code>.env</code></li>';
    echo '<li>Ensure the <code>users</code> table exists</li>';
    echo '<li>Check database connection from Hostinger</li>';
    echo '</ul>';
    echo '</div>';
}
?>

    </div>
</body>
</html>
