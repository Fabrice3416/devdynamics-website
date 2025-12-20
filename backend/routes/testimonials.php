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

// Get featured testimonials
$router->get('\/testimonials\/featured', function($params) use ($db) {
    try {
        $testimonials = $db->fetchAll(
            "SELECT * FROM testimonials WHERE is_featured = 1 ORDER BY created_at DESC LIMIT 6"
        );

        Response::success($testimonials);
    } catch (Exception $e) {
        Response::error('Failed to fetch featured testimonials: ' . $e->getMessage(), 500);
    }
});
