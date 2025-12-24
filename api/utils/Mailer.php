<?php
/**
 * Mailer Utility - Envoi d'emails via SMTP
 * Compatible avec Hostinger
 */

class Mailer {
    private static $config = null;

    /**
     * Charge la configuration SMTP depuis .env
     */
    private static function loadConfig() {
        if (self::$config !== null) {
            return self::$config;
        }

        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            throw new Exception('Fichier .env introuvable');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $config = [];

        foreach ($lines as $line) {
            // Ignorer les commentaires
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parser la ligne
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $config[trim($key)] = trim($value);
            }
        }

        self::$config = $config;
        return $config;
    }

    /**
     * Envoie un email
     *
     * @param string $to Email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $message Corps du message (HTML supporté)
     * @param string|null $replyTo Email de réponse (optionnel)
     * @return bool Succès ou échec
     */
    public static function send($to, $subject, $message, $replyTo = null) {
        try {
            $config = self::loadConfig();

            // Configuration SMTP
            $smtpHost = $config['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $smtpPort = $config['SMTP_PORT'] ?? 587;
            $smtpUsername = $config['SMTP_USERNAME'] ?? '';
            $smtpPassword = $config['SMTP_PASSWORD'] ?? '';
            $fromEmail = $config['SMTP_FROM_EMAIL'] ?? 'contact@dev-dynamics.org';
            $fromName = $config['SMTP_FROM_NAME'] ?? 'DevDynamics';

            // Validation
            if (empty($smtpUsername) || empty($smtpPassword)) {
                error_log('SMTP: Configuration manquante');
                return false;
            }

            // Headers
            $headers = [];
            $headers[] = "MIME-Version: 1.0";
            $headers[] = "Content-Type: text/html; charset=UTF-8";
            $headers[] = "From: $fromName <$fromEmail>";

            if ($replyTo) {
                $headers[] = "Reply-To: $replyTo";
            }

            // Configuration ini pour SMTP (Hostinger)
            ini_set('SMTP', $smtpHost);
            ini_set('smtp_port', $smtpPort);
            ini_set('sendmail_from', $fromEmail);

            // Tentative d'envoi
            $result = mail($to, $subject, $message, implode("\r\n", $headers));

            if ($result) {
                error_log("Email envoyé avec succès à: $to");
            } else {
                error_log("Échec de l'envoi d'email à: $to");
            }

            return $result;

        } catch (Exception $e) {
            error_log('Erreur envoi email: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envoie une notification de nouveau message de contact
     *
     * @param array $data Données du formulaire de contact
     * @return bool
     */
    public static function sendContactNotification($data) {
        $config = self::loadConfig();
        $toEmail = $config['SMTP_TO_EMAIL'] ?? 'contact@dev-dynamics.org';

        $subject = "Nouveau message de contact - DevDynamics";

        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #008080; color: white; padding: 20px; text-align: center; }
                .content { background: #f9f9f9; padding: 20px; margin: 20px 0; }
                .field { margin-bottom: 15px; }
                .label { font-weight: bold; color: #008080; }
                .footer { text-align: center; color: #666; font-size: 12px; padding: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Nouveau Message de Contact</h2>
                </div>
                <div class='content'>
                    <div class='field'>
                        <span class='label'>Nom:</span><br>
                        " . htmlspecialchars($data['name']) . "
                    </div>
                    <div class='field'>
                        <span class='label'>Email:</span><br>
                        " . htmlspecialchars($data['email']) . "
                    </div>
                    <div class='field'>
                        <span class='label'>Téléphone:</span><br>
                        " . htmlspecialchars($data['phone'] ?? 'Non fourni') . "
                    </div>
                    <div class='field'>
                        <span class='label'>Sujet:</span><br>
                        " . htmlspecialchars($data['subject']) . "
                    </div>
                    <div class='field'>
                        <span class='label'>Message:</span><br>
                        " . nl2br(htmlspecialchars($data['message'])) . "
                    </div>
                    <div class='field'>
                        <span class='label'>Date:</span><br>
                        " . date('d/m/Y à H:i') . "
                    </div>
                </div>
                <div class='footer'>
                    <p>Ce message a été envoyé via le formulaire de contact du site DevDynamics</p>
                    <p><a href='https://dev-dynamics.org'>https://dev-dynamics.org</a></p>
                </div>
            </div>
        </body>
        </html>
        ";

        return self::send($toEmail, $subject, $message, $data['email']);
    }

    /**
     * Envoie une confirmation au visiteur
     *
     * @param array $data Données du formulaire de contact
     * @return bool
     */
    public static function sendContactConfirmation($data) {
        $subject = "Merci de nous avoir contacté - DevDynamics";

        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #008080; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { text-align: center; color: #666; font-size: 12px; padding: 20px; border-top: 1px solid #ddd; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Merci de nous avoir contacté!</h2>
                </div>
                <div class='content'>
                    <p>Bonjour " . htmlspecialchars($data['name']) . ",</p>

                    <p>Nous avons bien reçu votre message et nous vous remercions de votre intérêt pour DevDynamics.</p>

                    <p>Notre équipe examinera votre demande et vous répondra dans les plus brefs délais.</p>

                    <p><strong>Votre message:</strong></p>
                    <p style='background: #f9f9f9; padding: 15px; border-left: 4px solid #008080;'>
                        " . nl2br(htmlspecialchars($data['message'])) . "
                    </p>

                    <p>Cordialement,<br>
                    <strong>L'équipe DevDynamics</strong></p>
                </div>
                <div class='footer'>
                    <p>DevDynamics - Association éducative et technologique<br>
                    Cap-Haïtien, Haïti</p>
                    <p>
                        <a href='https://dev-dynamics.org'>Site web</a> |
                        <a href='mailto:contact@dev-dynamics.org'>contact@dev-dynamics.org</a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";

        return self::send($data['email'], $subject, $message);
    }
}
