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

    // Raison du dernier echec, sans le mot de passe : PHPMailer ne le cite jamais
    public static $lastError = null;

    /**
     * Rend true si le courriel est parti. Une panne ne remonte jamais au visiteur.
     */
    public static function notifyContact(array $message) {
        return self::send(
            'Message du site : ' . ($message['subject'] ?: $message['name']),
            self::contactBody($message),
            $message['email'],
            $message['name']
        );
    }

    /**
     * Ce que le serveur a lu dans api/.env, pour comprendre un envoi qui echoue.
     */
    public static function configuration() {
        $username = getenv('SMTP_USERNAME') ?: 'contact@dev-dynamics.org';
        return [
            'host' => getenv('SMTP_HOST') ?: 'smtp.hostinger.com',
            'port' => (int) (getenv('SMTP_PORT') ?: 465),
            'username' => $username,
            'to' => getenv('NOTIFY_EMAIL') ?: $username,
            'has_password' => (bool) getenv('SMTP_PASSWORD'),
        ];
    }

    public static function send($subject, $body, $replyTo = null, $replyName = '') {
        self::$lastError = null;
        $config = self::configuration();

        if (!$config['has_password']) {
            self::$lastError = 'SMTP_PASSWORD est absent ou vide dans api/.env';
            error_log('Mailer : ' . self::$lastError);
            return false;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->Port = $config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = getenv('SMTP_PASSWORD');
            $mail->SMTPSecure = $config['port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            // Le visiteur attend la reponse de l'API : une panne SMTP ne doit pas le bloquer
            $mail->Timeout = 8;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($config['username'], getenv('SMTP_FROM_NAME') ?: 'DevDynamics');
            $mail->addAddress($config['to']);
            if ($replyTo) $mail->addReplyTo($replyTo, $replyName);
            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body = $body;

            return $mail->send();
        } catch (Exception $e) {
            self::$lastError = $e->getMessage();
            error_log('Mailer : ' . self::$lastError);
            return false;
        }
    }
}
