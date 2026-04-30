<?php
/**
 * Mailer — minimal helper to send a multipart/mixed email
 * with a PDF attachment using PHP's mail().
 *
 * On a misconfigured server mail() returns false; the caller
 * should treat the return value as best-effort.
 */

class Mailer {
    /**
     * Send an HTML email with a PDF attachment.
     *
     * @param string $to          recipient address
     * @param string $subject     email subject
     * @param string $bodyHtml    HTML body
     * @param string $pdfPath     path to the PDF file on disk
     * @param string $pdfName     filename to display in the email
     * @param string|null $from   sender address (default: noreply@ domain of $to)
     * @param string|null $replyTo
     * @return bool
     */
    public static function sendWithPdf($to, $subject, $bodyHtml, $pdfPath, $pdfName, $from = null, $replyTo = null) {
        if (!file_exists($pdfPath)) {
            error_log("Mailer: PDF not found at {$pdfPath}");
            return false;
        }

        $domain = self::extractDomain($to);
        $from = $from ?: "noreply@{$domain}";

        $boundary = '----=_Part_' . md5(uniqid('', true));
        $eol = "\r\n";

        $headers = [];
        $headers[] = "From: DevDynamics <{$from}>";
        if ($replyTo) $headers[] = "Reply-To: {$replyTo}";
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";
        $headers[] = "X-Mailer: DevDynamics-ACP/1.0";

        $pdfData = chunk_split(base64_encode(file_get_contents($pdfPath)));
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $pdfName);

        $body  = "--{$boundary}{$eol}";
        $body .= "Content-Type: text/html; charset=UTF-8{$eol}";
        $body .= "Content-Transfer-Encoding: 8bit{$eol}{$eol}";
        $body .= $bodyHtml . $eol . $eol;

        $body .= "--{$boundary}{$eol}";
        $body .= "Content-Type: application/pdf; name=\"{$safeName}\"{$eol}";
        $body .= "Content-Transfer-Encoding: base64{$eol}";
        $body .= "Content-Disposition: attachment; filename=\"{$safeName}\"{$eol}{$eol}";
        $body .= $pdfData . $eol;

        $body .= "--{$boundary}--{$eol}";

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $sent = @mail($to, $encodedSubject, $body, implode($eol, $headers));
        if (!$sent) {
            error_log("Mailer: mail() failed sending to {$to}");
        }
        return $sent;
    }

    private static function extractDomain($email) {
        $parts = explode('@', $email);
        return count($parts) === 2 ? $parts[1] : 'localhost';
    }
}
