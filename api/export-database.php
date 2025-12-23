<?php
/**
 * Script pour exporter la structure de la base de données
 * Utilisez ce fichier pour générer un SQL à importer sur Hostinger
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="database-export-' . date('Y-m-d') . '.sql"');

try {
    $db = Database::getInstance();

    echo "-- DevDynamics Database Export\n";
    echo "-- Date: " . date('Y-m-d H:i:s') . "\n";
    echo "-- \n\n";

    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "START TRANSACTION;\n";
    echo "SET time_zone = \"+00:00\";\n\n";

    // Liste des tables
    $tables = [
        'users',
        'students',
        'courses',
        'course_enrollments',
        'programs',
        'blog_posts',
        'testimonials',
        'sponsors',
        'donations',
        'contact_messages',
        'founders',
        'organization_info'
    ];

    foreach ($tables as $table) {
        echo "\n-- --------------------------------------------------------\n";
        echo "-- Structure de la table `$table`\n";
        echo "-- --------------------------------------------------------\n\n";

        try {
            // Get CREATE TABLE statement
            $result = $db->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_ASSOC);

            if ($row && isset($row['Create Table'])) {
                echo "DROP TABLE IF EXISTS `$table`;\n";
                echo $row['Create Table'] . ";\n\n";

                // Export data (limited to avoid huge files)
                $count = $db->fetchOne("SELECT COUNT(*) as count FROM `$table`");
                echo "-- Contient {$count['count']} enregistrements\n\n";
            }
        } catch (Exception $e) {
            echo "-- Table $table n'existe pas\n\n";
        }
    }

    echo "COMMIT;\n";

} catch (Exception $e) {
    echo "-- ERREUR: " . $e->getMessage() . "\n";
}
?>
