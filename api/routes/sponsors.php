<?php
/**
 * Sponsors Routes
 * GET /api/sponsors - Get sponsors
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get all sponsors
$router->get('\/sponsors', function($params) use ($db) {
    try {
        $sponsors = $db->fetchAll(
            "SELECT * FROM sponsors ORDER BY tier ASC, name ASC"
        );

        Response::success($sponsors);
    } catch (Exception $e) {
        Response::error('Failed to fetch sponsors: ' . $e->getMessage(), 500);
    }
});
