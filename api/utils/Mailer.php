<?php
/**
 * Notifications par e-mail, via le SMTP de la boite DevDynamics.
 *
 * Configuration dans api/.env : SMTP_HOST, SMTP_PORT, SMTP_USERNAME,
 * SMTP_PASSWORD, SMTP_FROM_NAME, NOTIFY_EMAIL. Sans mot de passe, rien n'est
 * envoye : le message reste enregistre et visible dans l'administration.
 */

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

class Mailer {
    /**
     * Le corps du courriel de notification. Du texte brut : un message de
     * visiteur ne doit jamais pouvoir y injecter du HTML.
     */
    public static function contactBody(array $message) {
        $lines = [
            'Nouveau message reçu depuis le site dev-dynamics.org.',
            '',
            'Nom : ' . $message['name'],
            'E-mail : ' . $message['email'],
            'Téléphone : ' . ($message['phone'] ?: 'non indiqué'),
            'Sujet : ' . ($message['subject'] ?: 'non indiqué'),
            '',
            $message['message'],
            '',
            '--',
            'Répondre à ce courriel écrit directement à la personne.',
        ];
        return implode("\n", $lines);
    }

    /**
     * Rend true si le courriel est parti. Une panne ne remonte jamais au visiteur.
     */
    public static function notifyContact(array $message) {
        $password = getenv('SMTP_PASSWORD');
        if (!$password) return false;

        try {
            $username = getenv('SMTP_USERNAME') ?: 'contact@dev-dynamics.org';
            $port = (int) (getenv('SMTP_PORT') ?: 465);

            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: 'smtp.hostinger.com';
            $mail->Port = $port;
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            // Le visiteur attend la reponse de l'API : une panne SMTP ne doit pas le bloquer
            $mail->Timeout = 8;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($username, getenv('SMTP_FROM_NAME') ?: 'DevDynamics');
            $mail->addAddress(getenv('NOTIFY_EMAIL') ?: $username);
            $mail->addReplyTo($message['email'], $message['name']);
            $mail->Subject = 'Message du site : ' . ($message['subject'] ?: $message['name']);
            $mail->isHTML(false);
            $mail->Body = self::contactBody($message);

            return $mail->send();
        } catch (Exception $e) {
            error_log('Mailer::notifyContact : ' . $e->getMessage());
            return false;
        }
    }
}
