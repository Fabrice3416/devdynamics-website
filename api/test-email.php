<?php
/**
 * Script de test pour l'envoi d'emails
 * À utiliser uniquement en développement/test
 * Ce fichier sera ignoré par Git (.gitignore)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/utils/Mailer.php';

echo "<h1>Test d'envoi d'email - DevDynamics</h1>";
echo "<hr>";

// Données de test
$testData = [
    'name' => 'Test Utilisateur',
    'email' => 'test@example.com',
    'phone' => '+509 1234-5678',
    'subject' => 'Test d\'envoi d\'email',
    'message' => 'Ceci est un message de test pour vérifier que l\'envoi d\'emails fonctionne correctement avec PHPMailer et Hostinger SMTP.'
];

echo "<h2>Configuration SMTP:</h2>";
echo "<pre>";
echo "Serveur: smtp.hostinger.com\n";
echo "Port: 587\n";
echo "Encryption: STARTTLS\n";
echo "</pre>";

echo "<h2>Test 1: Email de notification à l'admin</h2>";
echo "<p>Envoi en cours...</p>";

try {
    $result1 = Mailer::sendContactNotification($testData);

    if ($result1) {
        echo "<p style='color: green;'>✅ Email de notification envoyé avec succès!</p>";
    } else {
        echo "<p style='color: red;'>❌ Échec de l'envoi de l'email de notification</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";

echo "<h2>Test 2: Email de confirmation au visiteur</h2>";
echo "<p>Envoi en cours...</p>";

try {
    $result2 = Mailer::sendContactConfirmation($testData);

    if ($result2) {
        echo "<p style='color: green;'>✅ Email de confirmation envoyé avec succès!</p>";
    } else {
        echo "<p style='color: red;'>❌ Échec de l'envoi de l'email de confirmation</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erreur: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";

echo "<h2>Résumé:</h2>";
echo "<ul>";
echo "<li>Notification admin: " . ($result1 ? "✅ Succès" : "❌ Échec") . "</li>";
echo "<li>Confirmation visiteur: " . ($result2 ? "✅ Succès" : "❌ Échec") . "</li>";
echo "</ul>";

echo "<hr>";
echo "<p><strong>Note:</strong> Vérifiez votre boîte email <code>contact@dev-dynamics.org</code> pour l'email de notification.</p>";
echo "<p><strong>Note:</strong> Vérifiez aussi le dossier spam/courrier indésirable.</p>";
echo "<br>";
echo "<p style='color: #666; font-size: 12px;'>Ce fichier de test doit être supprimé en production.</p>";
