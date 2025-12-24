<?php
/**
 * Script de test pour l'envoi d'emails avec diagnostics détaillés
 * À utiliser uniquement en développement/test
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test d'envoi d'email - DevDynamics</h1>";
echo "<hr>";

// Vérifier que PHPMailer existe
$phpmailerPath = __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
echo "<h2>Vérification de PHPMailer:</h2>";
if (file_exists($phpmailerPath)) {
    echo "<p style='color: green;'>✅ PHPMailer trouvé: $phpmailerPath</p>";
} else {
    echo "<p style='color: red;'>❌ PHPMailer NON trouvé: $phpmailerPath</p>";
    echo "<p><strong>Solution:</strong> Uploadez le dossier 'api/vendor/phpmailer' sur votre serveur</p>";
    exit;
}

// Vérifier que .env existe
$envPath = __DIR__ . '/.env';
echo "<h2>Vérification du fichier .env:</h2>";
if (file_exists($envPath)) {
    echo "<p style='color: green;'>✅ Fichier .env trouvé</p>";
} else {
    echo "<p style='color: red;'>❌ Fichier .env NON trouvé: $envPath</p>";
    echo "<p><strong>Solution:</strong> Créez le fichier api/.env avec votre configuration SMTP</p>";
    exit;
}

// Charger et afficher la configuration (masquer le mot de passe)
echo "<h2>Configuration SMTP détectée:</h2>";
$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$config = [];
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $config[trim($key)] = trim($value);
    }
}

echo "<pre>";
echo "SMTP_HOST: " . ($config['SMTP_HOST'] ?? '❌ NON DÉFINI') . "\n";
echo "SMTP_PORT: " . ($config['SMTP_PORT'] ?? '❌ NON DÉFINI') . "\n";
echo "SMTP_USERNAME: " . ($config['SMTP_USERNAME'] ?? '❌ NON DÉFINI') . "\n";
echo "SMTP_PASSWORD: " . (isset($config['SMTP_PASSWORD']) && !empty($config['SMTP_PASSWORD']) ? '✅ DÉFINI (masqué)' : '❌ NON DÉFINI') . "\n";
echo "SMTP_FROM_EMAIL: " . ($config['SMTP_FROM_EMAIL'] ?? '❌ NON DÉFINI') . "\n";
echo "SMTP_FROM_NAME: " . ($config['SMTP_FROM_NAME'] ?? '❌ NON DÉFINI') . "\n";
echo "SMTP_TO_EMAIL: " . ($config['SMTP_TO_EMAIL'] ?? '❌ NON DÉFINI') . "\n";
echo "</pre>";

// Vérifier les paramètres manquants
$required = ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'SMTP_TO_EMAIL'];
$missing = [];
foreach ($required as $param) {
    if (!isset($config[$param]) || empty($config[$param])) {
        $missing[] = $param;
    }
}

if (!empty($missing)) {
    echo "<p style='color: red;'>❌ Paramètres manquants: " . implode(', ', $missing) . "</p>";
    echo "<p><strong>Solution:</strong> Ajoutez ces paramètres dans votre fichier api/.env</p>";
    exit;
}

echo "<hr>";

// Charger PHPMailer manuellement pour plus de contrôle
require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

echo "<h2>Test d'envoi d'email:</h2>";

$mail = new PHPMailer(true);

try {
    // Configuration serveur SMTP avec debug activé
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;  // Afficher les détails de débogage
    $mail->isSMTP();
    $mail->Host       = $config['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $config['SMTP_USERNAME'];
    $mail->Password   = $config['SMTP_PASSWORD'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = $config['SMTP_PORT'];
    $mail->CharSet    = 'UTF-8';

    // Options SSL
    $mail->SMTPOptions = array(
        'ssl' => array(
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        )
    );

    // Expéditeur
    $mail->setFrom($config['SMTP_FROM_EMAIL'], $config['SMTP_FROM_NAME']);

    // Destinataire
    $mail->addAddress($config['SMTP_TO_EMAIL']);

    // Contenu
    $mail->isHTML(true);
    $mail->Subject = 'Test d\'envoi d\'email - DevDynamics';
    $mail->Body    = '<h1>Email de test</h1><p>Si vous recevez cet email, la configuration SMTP fonctionne correctement!</p>';
    $mail->AltBody = 'Email de test - Si vous recevez cet email, la configuration SMTP fonctionne correctement!';

    echo "<pre style='background: #f0f0f0; padding: 10px; border: 1px solid #ccc;'>";
    $mail->send();
    echo "</pre>";

    echo "<h3 style='color: green;'>✅ Email envoyé avec succès!</h3>";
    echo "<p>Vérifiez votre boîte email: <strong>" . htmlspecialchars($config['SMTP_TO_EMAIL']) . "</strong></p>";
    echo "<p><em>N'oubliez pas de vérifier le dossier spam/courrier indésirable</em></p>";

} catch (Exception $e) {
    echo "</pre>";
    echo "<h3 style='color: red;'>❌ Erreur lors de l'envoi:</h3>";
    echo "<pre style='background: #ffe0e0; padding: 10px; border: 1px solid #ff0000;'>";
    echo htmlspecialchars($e->getMessage());
    echo "</pre>";

    echo "<h3>Solutions possibles:</h3>";
    echo "<ul>";
    echo "<li><strong>SMTP authentication failed:</strong> Vérifiez que le mot de passe dans .env est correct</li>";
    echo "<li><strong>Connection refused:</strong> Vérifiez que le serveur SMTP et le port sont corrects</li>";
    echo "<li><strong>Invalid address:</strong> Vérifiez que l'adresse email est valide</li>";
    echo "<li><strong>TLS/SSL error:</strong> Le serveur peut bloquer les connexions SMTP sortantes</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p style='color: #666; font-size: 12px;'>Supprimez ce fichier après les tests.</p>";
