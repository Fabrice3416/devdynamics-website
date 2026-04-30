<?php
/**
 * Response Utility Class
 * Standardized JSON responses
 */

class Response {
    /**
     * Send success response
     */
    public static function success($data = null, $message = 'Success', $code = 200) {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send error response
     */
    public static function error($message = 'Error', $code = 400, $data = null) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send unauthorized response
     */
    public static function unauthorized($message = 'Unauthorized') {
        self::error($message, 401);
    }

    /**
     * Send forbidden response
     */
    public static function forbidden($message = 'Forbidden') {
        self::error($message, 403);
    }

    /**
     * Send not found response
     */
    public static function notFound($message = 'Not found') {
        self::error($message, 404);
    }

    /**
     * Send validation error response
     */
    public static function validationError($errors, $message = 'Validation failed') {
        self::error($message, 422, $errors);
    }
}
