<?php
declare(strict_types=1);

/**
 * Systeme d'alertes : INSERT dans table alertes + envoi email.
 *
 * Utilise mail() natif PHP qui passe par le relais sortant Hostinger
 * (meme mecanisme que api/utils/Mailer.php du site public - SPF deja configure).
 *
 * Pas de dependance PHPMailer pour l'envoi simple. PHPMailer reste utilise
 * uniquement si on a besoin d'attacher des PDF (cf. api/utils/Mailer.php).
 *
 * Si l'envoi echoue : l'alerte est marquee 'echec' en BDD, un cron pourra retenter.
 */

require_once __DIR__ . '/db.php';

/**
 * @param string $type           Type d'alerte (voir ENUM table alertes)
 * @param string $destinataire   Email destinataire
 * @param string $sujet
 * @param string $message        Corps texte (peut etre du HTML)
 * @param array  $reference      ['type' => 'imputations', 'id' => 42]
 * @return int                   ID de l'alerte
 */
function alerte_envoyer(string $type, string $destinataire, string $sujet, string $message, array $reference = []): int
{
    $stmt = db()->prepare(
        "INSERT INTO alertes (type, destinataire, sujet, message, statut_envoi, reference_id, reference_type)
         VALUES (?, ?, ?, ?, 'en_attente', ?, ?)"
    );
    $stmt->execute([
        $type,
        $destinataire,
        $sujet,
        $message,
        $reference['id'] ?? null,
        $reference['type'] ?? null,
    ]);
    $id = (int)db()->lastInsertId();

    if (envoyer_email_natif($destinataire, $sujet, $message)) {
        $stmt = db()->prepare("UPDATE alertes SET statut_envoi='envoye', sent_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
    } else {
        $stmt = db()->prepare(
            "UPDATE alertes SET statut_envoi='echec', tentative_compteur = tentative_compteur + 1 WHERE id=?"
        );
        $stmt->execute([$id]);
    }
    return $id;
}

/**
 * Envoi email via mail() natif PHP (route Hostinger - meme mecanisme que le site public).
 * Configuration : section 'smtp' de config.php (from_email, from_name).
 * SPF/DKIM Hostinger couvre les envois depuis dev-dynamics.org.
 */
function envoyer_email_natif(string $to, string $subject, string $bodyHtml): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log("alerts: email invalide '$to'");
        return false;
    }

    $cfg = config();
    $from     = $cfg['smtp']['from_email'] ?? 'noreply@dev-dynamics.org';
    $fromName = $cfg['smtp']['from_name']  ?? 'Portail DEVDYNAMICS / ACP';
    $fromDomain = substr(strrchr($from, '@'), 1) ?: 'dev-dynamics.org';

    $boundary = 'alt_' . md5(uniqid('', true));
    $eol = "\r\n";
    $messageId = '<' . md5(uniqid('', true) . microtime(true)) . '@' . $fromDomain . '>';

    // Headers (memes choix que api/utils/Mailer.php pour eviter le spam)
    $headers = [];
    $headers[] = 'From: ' . encode_header_name($fromName) . " <$from>";
    $headers[] = "Reply-To: $from";
    $headers[] = 'Date: ' . date('r');
    $headers[] = "Message-ID: $messageId";
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $headers[] = 'X-Mailer: DevDynamics-Portail/1.0';
    $headers[] = 'X-Auto-Response-Suppress: All';

    // Corps multipart/alternative (text + html) - SPF-friendly
    $bodyText = html_to_text($bodyHtml);

    $body  = "--$boundary$eol";
    $body .= "Content-Type: text/plain; charset=UTF-8$eol";
    $body .= "Content-Transfer-Encoding: 8bit$eol$eol";
    $body .= $bodyText . $eol . $eol;

    $body .= "--$boundary$eol";
    $body .= "Content-Type: text/html; charset=UTF-8$eol";
    $body .= "Content-Transfer-Encoding: 8bit$eol$eol";
    $body .= $bodyHtml . $eol . $eol;
    $body .= "--$boundary--$eol";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    // -f aligne le sender-envelope avec From pour passer SPF
    $sent = @mail($to, $encodedSubject, $body, implode($eol, $headers), "-f$from");
    if (!$sent) {
        error_log("alerts: mail() echec vers $to");
    }
    return $sent;
}

function html_to_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/[ \t]+/", ' ', $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return trim($text);
}

function encode_header_name(string $name): string
{
    if (preg_match('/[\x80-\xff]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return $name;
}

/**
 * Helper : INSERT dans audit_log.
 */
function audit_log(string $action, ?string $description = null, ?string $refType = null, ?int $refId = null): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO audit_log (user_id, action, description, reference_type, reference_id, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $description,
            $refType,
            $refId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}
