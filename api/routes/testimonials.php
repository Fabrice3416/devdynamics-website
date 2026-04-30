<?php
/**
 * Testimonials Routes
 * GET /api/testimonials - Get testimonials
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get all testimonials
$router->get('\/testimonials', function($params) use ($db) {
    try {
        $testimonials = $db->fetchAll(
            "SELECT * FROM testimonials ORDER BY created_at DESC"
        );

        Response::success($testimonials);
    } catch (Exception $e) {
        Response::error('Failed to fetch testimonials: ' . $e->getMessage(), 500);
    }
});
