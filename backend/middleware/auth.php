<?php
/**
 * Authentication Middleware
 * Verify JWT tokens and user roles
 */

require_once __DIR__ . '/../utils/JWT.php';

/**
 * Verify JWT token middleware
 */
function authMiddleware() {
    $token = JWT::getTokenFromHeader();

    if (!$token) {
        Response::unauthorized('No token provided');
    }

    try {
        $decoded = JWT::decode($token);

        // Store user info in global variable for access in routes
        $GLOBALS['user'] = [
            'id' => $decoded['id'],
            'email' => $decoded['email'],
            'role' => $decoded['role'] ?? 'user'
        ];

    } catch (Exception $e) {
        Response::unauthorized('Invalid or expired token');
    }
}

/**
 * Verify admin role middleware
 */
function adminMiddleware() {
    // First authenticate
    authMiddleware();

    // Then check if admin
    if (!isset($GLOBALS['user']) || $GLOBALS['user']['role'] !== 'admin') {
        Response::forbidden('Admin access required');
    }
}

/**
 * Verify admin or instructor role middleware
 */
function instructorMiddleware() {
    // First authenticate
    authMiddleware();

    // Then check if admin or instructor
    $role = $GLOBALS['user']['role'] ?? '';
    if ($role !== 'admin' && $role !== 'instructor') {
        Response::forbidden('Instructor or admin access required');
    }
}

/**
 * Get current authenticated user
 */
function getCurrentUser() {
    return $GLOBALS['user'] ?? null;
}

/**
 * Check if current user is admin
 */
function isAdmin() {
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

/**
 * Check if current user is instructor or admin
 */
function isInstructor() {
    $user = getCurrentUser();
    return $user && ($user['role'] === 'admin' || $user['role'] === 'instructor');
}
