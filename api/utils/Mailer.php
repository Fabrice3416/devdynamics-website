<?php
/**
 * Mailer — sends a multipart/mixed email (text + HTML alternative + PDF
 * attachment) using PHP's mail(). On Hostinger this routes through their
 * outbound mail server.
 *
 * Several headers and structural choices below are deliberate to reduce
 * the chance of the message landing in spam:
 *  - The From address defaults to the configured ACP_NOTIFY_EMAIL (a real
 *    Hostinger mailbox covered by the domain's SPF), instead of a forged
 *    noreply@ address that no SPF record claims.
 *  - A Date and a Message-ID header are added (their absence is itself a
 *    spam signal).
 *  - A multipart/alternative body provides a text/plain fallback, which
 *    every reputable mailer ships and several filters expect.
 *  - The envelope sender (-f flag) is aligned with the From domain so SPF
 *    checks pass.
 */

class Mailer {
    /**
     * Send an HTML email with a PDF attachment.
     *
     * @param string $to          recipient address
     * @param string $subject     email subject (UTF-8)
     * @param string $bodyHtml    HTML body
     * @param string $pdfPath     path to the PDF on disk
     * @param string $pdfName     attachment filename
     * @param string|null $from   sender address (defaults to env config)
     * @param string|null $replyTo
     * @return bool
     */
    public static function sendWithPdf($to, $subject, $bodyHtml, $pdfPath, $pdfName, $from = null, $replyTo = null) {
        if (!file_exists($pdfPath)) {
            error_log("Mailer: PDF not found at {$pdfPath}");
            return false;
        }

        // Resolve the From address: explicit param > SMTP_FROM > ACP_NOTIFY_EMAIL > recipient.
        // Sending FROM the recipient mailbox is a working last resort because
        // we know that mailbox is real and covered by the domain's SPF.
        if (!$from) {
            $from = getenv('SMTP_FROM') ?: (getenv('ACP_NOTIFY_EMAIL') ?: $to);
        }
        $fromName = getenv('SMTP_FROM_NAME') ?: 'DevDynamics — ACP';
        $fromDomain = self::extractDomain($from);

        $boundaryMixed = 'mix_' . md5(uniqid('', true));
        $boundaryAlt   = 'alt_' . md5(uniqid('', true));
        $eol = "\r\n";

        $messageId = '<' . md5(uniqid('', true) . microtime(true)) . '@' . $fromDomain . '>';
        $date = date('r');

        $headers = [];
        $headers[] = 'From: ' . self::encodeHeaderName($fromName) . " <{$from}>";
        $headers[] = 'Reply-To: ' . ($replyTo ?: $from);
        $headers[] = "Date: {$date}";
        $headers[] = "Message-ID: {$messageId}";
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"";
        $headers[] = 'X-Mailer: DevDynamics-ACP/1.1';
        $headers[] = 'X-Auto-Response-Suppress: All';

        $bodyText = self::htmlToText($bodyHtml);
        $pdfData  = chunk_split(base64_encode(file_get_contents($pdfPath)));
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $pdfName);

        // multipart/mixed
        //   ├─ multipart/alternative
        //   │    ├─ text/plain
        //   │    └─ text/html
        //   └─ application/pdf (attachment)
        $body  = "--{$boundaryMixed}{$eol}";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"{$eol}{$eol}";

        $body .= "--{$boundaryAlt}{$eol}";
        $body .= "Content-Type: text/plain; charset=UTF-8{$eol}";
        $body .= "Content-Transfer-Encoding: 8bit{$eol}{$eol}";
        $body .= $bodyText . $eol . $eol;

        $body .= "--{$boundaryAlt}{$eol}";
        $body .= "Content-Type: text/html; charset=UTF-8{$eol}";
        $body .= "Content-Transfer-Encoding: 8bit{$eol}{$eol}";
        $body .= $bodyHtml . $eol . $eol;
        $body .= "--{$boundaryAlt}--{$eol}{$eol}";

        $body .= "--{$boundaryMixed}{$eol}";
        $body .= "Content-Type: application/pdf; name=\"{$safeName}\"{$eol}";
        $body .= "Content-Transfer-Encoding: base64{$eol}";
        $body .= "Content-Disposition: attachment; filename=\"{$safeName}\"{$eol}{$eol}";
        $body .= $pdfData . $eol;
        $body .= "--{$boundaryMixed}--{$eol}";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        // -f sets the envelope sender used by the receiving server for SPF.
        // Aligning it with the From domain is what makes SPF actually pass.
        $sent = @mail($to, $encodedSubject, $body, implode($eol, $headers), "-f{$from}");
        if (!$sent) {
            error_log("Mailer: mail() failed sending to {$to}");
        }
        return $sent;
    }

    /**
     * Quick HTML → plain-text conversion for the multipart/alternative
     * fallback. Not perfect, but enough to give spam filters a real
     * text/plain part to score.
     */
    private static function htmlToText($html) {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private static function encodeHeaderName($name) {
        // RFC 2047 encoding for non-ASCII characters in display names
        if (preg_match('/[\x80-\xff]/', $name)) {
            return '=?UTF-8?B?' . base64_encode($name) . '?=';
        }
        return $name;
    }

    private static function extractDomain($email) {
        $parts = explode('@', $email);
        return count($parts) === 2 ? $parts[1] : 'localhost';
    }
}
