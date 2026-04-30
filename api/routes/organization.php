<?php
/**
 * Organization Routes
 * GET /api/organization - Get organization info
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get organization information
$router->get('\/organization', function($params) use ($db) {
    try {
        $organization = $db->fetchOne(
            "SELECT * FROM organization_info LIMIT 1"
        );

        if (!$organization) {
            Response::notFound('Organization information not found');
        }

        // Get founders
        $founders = $db->fetchAll(
            "SELECT * FROM founders ORDER BY display_order ASC"
        );

        $organization['founders'] = $founders;

        Response::success($organization);
    } catch (Exception $e) {
        Response::error('Failed to fetch organization info: ' . $e->getMessage(), 500);
    }
});
