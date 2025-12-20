<?php
/**
 * JWT Token Handler
 * Simple JWT implementation without external dependencies
 */

class JWT {
    /**
     * Generate JWT token
     */
    public static function encode($payload, $secret = null) {
        if ($secret === null) {
            $secret = getenv('JWT_SECRET') ?: 'your_jwt_secret_key_here';
        }

        // Create header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);

        // Add expiry to payload
        if (!isset($payload['exp'])) {
            $expiry = getenv('JWT_EXPIRY') ?: 604800; // 7 days default
            $payload['exp'] = time() + (int)$expiry;
        }

        $payload = json_encode($payload);

        // Encode header and payload
        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode($payload);

        // Create signature
        $signature = hash_hmac(
            'sha256',
            $base64UrlHeader . "." . $base64UrlPayload,
            $secret,
            true
        );
        $base64UrlSignature = self::base64UrlEncode($signature);

        // Create JWT
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Decode and verify JWT token
     */
    public static function decode($token, $secret = null) {
        if ($secret === null) {
            $secret = getenv('JWT_SECRET') ?: 'your_jwt_secret_key_here';
        }

        // Split token
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new Exception('Invalid token format');
        }

        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;

        // Verify signature
        $signature = self::base64UrlDecode($base64UrlSignature);
        $expectedSignature = hash_hmac(
            'sha256',
            $base64UrlHeader . "." . $base64UrlPayload,
            $secret,
            true
        );

        if (!hash_equals($signature, $expectedSignature)) {
            throw new Exception('Invalid token signature');
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($base64UrlPayload), true);

        // Check expiry
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token has expired');
        }

        return $payload;
    }

    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Extract token from Authorization header
     */
    public static function getTokenFromHeader() {
        $headers = Router::getHeaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            return null;
        }

        // Extract token from "Bearer <token>"
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
