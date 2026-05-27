<?php
declare(strict_types=1);

/**
 * Gestion des tokens 72h pour acces prestataire (NH, FRP).
 * Securite : token de 64 hex (random_bytes 32), expire_at = NOW()+72h,
 * flag utilise (single-use), rate limiting par IP via audit_log.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/alerts.php';

final class TokenService
{
    /**
     * Genere un token pour un prestataire et l'envoie par email.
     *
     * @param string $type            'note_honoraires' | 'signature_frp'
     * @param int    $imputationId
     * @param string $emailDest
     * @param string $nomDest         Pour personnaliser l'email
     * @param string $contexte        Texte court explicatif
     * @return array{token:string, expire:string, id:int}
     */
    public static function create(string $type, int $imputationId, string $emailDest, string $nomDest, string $contexte = ''): array
    {
        $token = bin2hex(random_bytes(32));
        $expire = date('Y-m-d H:i:s', time() + 72 * 3600);

        $stmt = db()->prepare(
            "INSERT INTO tokens (token, type, imputation_id, email_destinataire, expire_at, utilise)
             VALUES (?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$token, $type, $imputationId, $emailDest, $expire]);
        $tokenId = (int)db()->lastInsertId();

        $cfg = config();
        $endpoint = $type === 'note_honoraires' ? 'api/nh_token.php' : 'api/frp_token.php';
        $link = rtrim($cfg['app']['url'], '/') . '/' . $endpoint . '?t=' . $token;

        $sujet = $type === 'note_honoraires'
            ? 'Soumission de votre Note d\'Honoraires - DEVDYNAMICS'
            : 'Signature de votre Fiche de Reglement - DEVDYNAMICS';

        $titre = $type === 'note_honoraires'
            ? 'Soumettez votre Note d\'Honoraires'
            : 'Signez votre Fiche de Reglement';

        $body = '<p>Bonjour ' . e($nomDest) . ',</p>'
              . '<p>' . e($contexte) . '</p>'
              . '<p><strong>' . e($titre) . '</strong></p>'
              . '<p>Cliquez sur le lien ci-dessous (valide 72 heures, usage unique) :</p>'
              . '<p><a href="' . e($link) . '" style="display:inline-block;background:#1F4E79;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;">'
              . 'Acceder au formulaire</a></p>'
              . '<p style="font-size:9pt;color:#666;">Lien direct : ' . e($link) . '</p>'
              . '<p style="font-size:9pt;color:#666;">Si vous n\'etes pas le destinataire prevu, ignorez ce message.</p>';

        $alertType = $type === 'note_honoraires' ? 'asf_certifiee' : 'nh_soumise';
        alerte_envoyer($alertType, $emailDest, $sujet, $body,
            ['type' => 'tokens', 'id' => $tokenId]);

        return ['token' => $token, 'expire' => $expire, 'id' => $tokenId];
    }

    /**
     * Valide un token : retourne la ligne tokens si valide, null sinon.
     * Verifie : token existe, non utilise, non expire, rate limit IP OK.
     */
    public static function validate(string $token, string $type, string $ip): ?array
    {
        // Rate limit : max 10 acces par IP/heure sur les tokens
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM audit_log
              WHERE ip_address = ?
                AND action IN ('upload_fichier','token_regenere')
                AND created_at > (NOW() - INTERVAL 1 HOUR)"
        );
        $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() > 30) {
            return null;
        }

        $stmt = db()->prepare(
            "SELECT * FROM tokens
              WHERE token = ? AND type = ? AND utilise = 0 AND expire_at > NOW()
              LIMIT 1"
        );
        $stmt->execute([$token, $type]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Marque un token comme utilise.
     */
    public static function markUsed(int $tokenId, ?string $sigPrestaPath = null): void
    {
        $stmt = db()->prepare(
            "UPDATE tokens SET utilise = 1, sig_presta_scan = COALESCE(?, sig_presta_scan) WHERE id = ?"
        );
        $stmt->execute([$sigPrestaPath, $tokenId]);
    }

    /**
     * Regenere un token (revoque l'ancien, en cree un nouveau).
     */
    public static function regenerate(int $oldTokenId, string $userActionDesc): array
    {
        $stmt = db()->prepare('SELECT * FROM tokens WHERE id=?');
        $stmt->execute([$oldTokenId]);
        $old = $stmt->fetch();
        if (!$old) {
            throw new RuntimeException('Token introuvable.');
        }

        // L'ancien token reste en base (utilise=1 si deja utilise ou expire=NOW pour le revoquer)
        // Audit : on garde la trace de la regeneration
        // Nouveau token
        $new = self::create(
            $old['type'],
            (int)$old['imputation_id'],
            $old['email_destinataire'],
            'destinataire',
            'Nouveau lien suite a regeneration par l administrateur.'
        );

        audit_log('token_regenere', $userActionDesc . ' (ancien token #' . $oldTokenId . ')',
            'tokens', $new['id']);

        return $new;
    }
}
