<?php
/**
 * Organization Routes
 * GET /api/organization - Get organization info
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get organization information
$router->get('\/organization\/info', function($params) use ($db) {
    try {
        $organization = $db->fetchOne(
            "SELECT * FROM organization_info LIMIT 1"
        );

        if (!$organization) {
            Response::notFound('Organization information not found');
        }

        Response::success($organization);
    } catch (Exception $e) {
        Response::error('Failed to fetch organization info: ' . $e->getMessage(), 500);
    }
});

// Get founders/team members
$router->get('\/organization\/founders', function($params) use ($db) {
    try {
        $founders = $db->fetchAll(
            "SELECT * FROM founders ORDER BY order_position ASC"
        );

        Response::success($founders);
    } catch (Exception $e) {
        Response::error('Failed to fetch founders: ' . $e->getMessage(), 500);
    }
});
