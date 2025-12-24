<?php
/**
 * Initialize Organization Info
 * Adds default organization data if table is empty
 *
 * Usage: https://votre-domaine.com/api/init-organization.php?token=INIT_ORG_2025
 */

define('SECURITY_TOKEN', 'INIT_ORG_2025');

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
    <title>Initialize Organization - DevDynamics</title>
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
        h1 { color: #333; }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #28a745;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #dc3545;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            border-left: 4px solid #2196F3;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .data-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>🏢 Initialize Organization Info</h1>

<?php

try {
    $db = Database::getInstance();

    echo '<div class="info">';
    echo '<strong>Database:</strong> ' . (getenv('DB_NAME') ?: 'devdynamics_db') . '<br>';
    echo '<strong>Table:</strong> organization_info';
    echo '</div>';

    // Check if organization_info exists
    $existing = $db->fetchOne("SELECT * FROM organization_info LIMIT 1");

    if ($existing) {
        echo '<div class="info">';
        echo '<strong>ℹ️ Status:</strong> Organization info already exists (ID: ' . $existing['id'] . ')';
        echo '</div>';

        echo '<div class="data-box">';
        echo '<h3>Current Data:</h3>';
        echo '<strong>Name:</strong> ' . htmlspecialchars($existing['name'] ?? 'N/A') . '<br>';
        echo '<strong>Email:</strong> ' . htmlspecialchars($existing['email'] ?? 'N/A') . '<br>';
        echo '<strong>Phone:</strong> ' . htmlspecialchars($existing['phone'] ?? 'N/A') . '<br>';
        echo '<strong>Mission:</strong> ' . htmlspecialchars(substr($existing['mission'] ?? '', 0, 100)) . '...<br>';
        echo '</div>';

    } else {
        echo '<div class="info">';
        echo 'Table is empty. Inserting default organization data...';
        echo '</div>';

        // Insert default organization info
        $db->query(
            "INSERT INTO organization_info (name, mission, address, phone, email, whatsapp, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'DevDynamics',
                'Empowering communities through technology education and innovation',
                '',
                '',
                'contact@dev-dynamics.org',
                ''
            ]
        );

        $id = $db->lastInsertId();

        echo '<div class="success">';
        echo '<strong>✅ Success!</strong> Organization info created (ID: ' . $id . ')';
        echo '</div>';

        $newData = $db->fetchOne("SELECT * FROM organization_info WHERE id = ?", [$id]);

        echo '<div class="data-box">';
        echo '<h3>Inserted Data:</h3>';
        echo '<strong>Name:</strong> ' . htmlspecialchars($newData['name']) . '<br>';
        echo '<strong>Email:</strong> ' . htmlspecialchars($newData['email']) . '<br>';
        echo '<strong>Mission:</strong> ' . htmlspecialchars($newData['mission']) . '<br>';
        echo '</div>';
    }

    echo '<div class="info">';
    echo '<h3>✅ Next Steps:</h3>';
    echo '<ol>';
    echo '<li>Go to your admin dashboard</li>';
    echo '<li>Navigate to Organization Settings</li>';
    echo '<li>Update the information with your actual data</li>';
    echo '<li><strong>Delete this file</strong> for security</li>';
    echo '</ol>';
    echo '</div>';

    // Self-destruct option
    if (isset($_GET['delete']) && $_GET['delete'] === 'confirm') {
        if (unlink(__FILE__)) {
            echo '<div class="success">✅ This file has been deleted!</div>';
        } else {
            echo '<div class="error">❌ Could not delete file. Please delete manually.</div>';
        }
    } else {
        echo '<a href="?token=' . SECURITY_TOKEN . '&delete=confirm" class="btn">🗑️ Delete This File</a>';
    }

} catch (Exception $e) {
    echo '<div class="error">';
    echo '<strong>❌ Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';

    echo '<div class="info">';
    echo '<h4>Possible Issues:</h4>';
    echo '<ul>';
    echo '<li>Table <code>organization_info</code> does not exist</li>';
    echo '<li>Database connection failed</li>';
    echo '<li>Check your <code>.env</code> configuration</li>';
    echo '</ul>';
    echo '</div>';
}
?>

    </div>
</body>
</html>
