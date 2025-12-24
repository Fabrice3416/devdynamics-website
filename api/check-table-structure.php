<?php
/**
 * Check Table Structure
 * Diagnostic tool to see the actual table structure
 *
 * Usage: https://votre-domaine.com/api/check-table-structure.php?token=CHECK_TABLE_2025
 */

define('SECURITY_TOKEN', 'CHECK_TABLE_2025');

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
    <title>Check Table Structure - DevDynamics</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1000px;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: white;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }
        code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 5px;
        }
        .btn-danger {
            background: #dc3545;
        }
        pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Table Structure Diagnostic</h1>

<?php

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    echo '<div class="info">';
    echo '<strong>Database:</strong> ' . (getenv('DB_NAME') ?: 'devdynamics_db');
    echo '</div>';

    // Get table structure
    echo '<h2>Table: organization_info</h2>';

    $columns = $conn->query("DESCRIBE organization_info")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($columns)) {
        echo '<div class="error">❌ Table does not exist!</div>';
    } else {
        echo '<div class="success">✅ Table exists with ' . count($columns) . ' columns</div>';

        echo '<h3>Column Structure:</h3>';
        echo '<table>';
        echo '<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>';

        foreach ($columns as $col) {
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars($col['Field']) . '</strong></td>';
            echo '<td>' . htmlspecialchars($col['Type']) . '</td>';
            echo '<td>' . htmlspecialchars($col['Null']) . '</td>';
            echo '<td>' . htmlspecialchars($col['Key']) . '</td>';
            echo '<td>' . htmlspecialchars($col['Default'] ?? 'NULL') . '</td>';
            echo '<td>' . htmlspecialchars($col['Extra']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        // Check if table has data
        $count = $conn->query("SELECT COUNT(*) as count FROM organization_info")->fetch(PDO::FETCH_ASSOC);

        echo '<div class="info">';
        echo '<strong>Rows in table:</strong> ' . $count['count'];
        echo '</div>';

        if ($count['count'] > 0) {
            $data = $db->fetchOne("SELECT * FROM organization_info LIMIT 1");
            echo '<h3>Sample Data:</h3>';
            echo '<pre>' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        }

        // Generate INSERT statement based on actual columns
        echo '<h3>🔧 Fix: Insert Default Data</h3>';

        $columnNames = array_column($columns, 'Field');
        $columnNames = array_filter($columnNames, function($col) {
            return !in_array($col, ['id', 'created_at', 'updated_at']);
        });

        if (isset($_GET['insert']) && $_GET['insert'] === 'confirm') {
            try {
                // Build dynamic INSERT based on actual columns
                $fields = [];
                $values = [];
                $placeholders = [];

                if (in_array('name', $columnNames)) {
                    $fields[] = 'name';
                    $values[] = 'DevDynamics';
                    $placeholders[] = '?';
                }
                if (in_array('mission', $columnNames)) {
                    $fields[] = 'mission';
                    $values[] = 'Empowering communities through technology education and innovation';
                    $placeholders[] = '?';
                }
                if (in_array('email', $columnNames)) {
                    $fields[] = 'email';
                    $values[] = 'contact@dev-dynamics.org';
                    $placeholders[] = '?';
                }
                if (in_array('phone', $columnNames)) {
                    $fields[] = 'phone';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('address', $columnNames)) {
                    $fields[] = 'address';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('whatsapp', $columnNames)) {
                    $fields[] = 'whatsapp';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('facebook_url', $columnNames)) {
                    $fields[] = 'facebook_url';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('twitter_url', $columnNames)) {
                    $fields[] = 'twitter_url';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('linkedin_url', $columnNames)) {
                    $fields[] = 'linkedin_url';
                    $values[] = '';
                    $placeholders[] = '?';
                }
                if (in_array('instagram_url', $columnNames)) {
                    $fields[] = 'instagram_url';
                    $values[] = '';
                    $placeholders[] = '?';
                }

                $sql = "INSERT INTO organization_info (" . implode(', ', $fields) . ")
                        VALUES (" . implode(', ', $placeholders) . ")";

                $db->query($sql, $values);
                $id = $db->lastInsertId();

                echo '<div class="success">';
                echo '✅ <strong>Success!</strong> Default organization data inserted (ID: ' . $id . ')';
                echo '</div>';

                $newData = $db->fetchOne("SELECT * FROM organization_info WHERE id = ?", [$id]);
                echo '<h3>Inserted Data:</h3>';
                echo '<pre>' . json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';

            } catch (Exception $e) {
                echo '<div class="error">';
                echo '❌ <strong>Insert failed:</strong> ' . htmlspecialchars($e->getMessage());
                echo '</div>';
            }
        } else {
            echo '<div class="info">';
            echo '<p>Click the button below to insert default organization data:</p>';
            echo '<a href="?token=' . SECURITY_TOKEN . '&insert=confirm" class="btn">✅ Insert Default Data</a>';
            echo '</div>';
        }
    }

    // Check users table too
    echo '<h2>Table: users</h2>';
    try {
        $userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch(PDO::FETCH_ASSOC);
        echo '<div class="info">';
        echo '<strong>Users in database:</strong> ' . $userCount['count'];
        echo '</div>';

        if ($userCount['count'] > 0) {
            $users = $db->fetchAll("SELECT id, full_name, email, role FROM users");
            echo '<table>';
            echo '<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th></tr>';
            foreach ($users as $user) {
                echo '<tr>';
                echo '<td>' . $user['id'] . '</td>';
                echo '<td>' . htmlspecialchars($user['full_name']) . '</td>';
                echo '<td>' . htmlspecialchars($user['email']) . '</td>';
                echo '<td><strong>' . htmlspecialchars($user['role']) . '</strong></td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    } catch (Exception $e) {
        echo '<div class="error">❌ Users table error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }

    echo '<div class="info">';
    echo '<h3>🗑️ Security</h3>';
    echo '<p>After fixing the issue, delete this diagnostic file:</p>';
    if (isset($_GET['delete']) && $_GET['delete'] === 'confirm') {
        if (unlink(__FILE__)) {
            echo '<div class="success">✅ File deleted!</div>';
        } else {
            echo '<div class="error">❌ Could not delete file.</div>';
        }
    } else {
        echo '<a href="?token=' . SECURITY_TOKEN . '&delete=confirm" class="btn btn-danger">🗑️ Delete This File</a>';
    }
    echo '</div>';

} catch (Exception $e) {
    echo '<div class="error">';
    echo '<strong>❌ Database Error:</strong><br>';
    echo htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>

    </div>
</body>
</html>
