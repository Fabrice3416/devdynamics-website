<?php
/**
 * Certificates Routes
 * GET /api/certificates/:id - Get certificate details
 * GET /api/certificates/verify/:code - Verify certificate
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get certificate by ID
$router->get('\/certificates/:id', function($params) use ($db) {
    try {
        $certificate = $db->fetchOne(
            "SELECT c.*, s.name as student_name, co.title as course_title
             FROM certificates c
             JOIN students s ON c.student_id = s.id
             JOIN courses co ON c.course_id = co.id
             WHERE c.id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$certificate) {
            Response::notFound('Certificate not found');
        }

        Response::success($certificate);
    } catch (Exception $e) {
        Response::error('Failed to fetch certificate: ' . $e->getMessage(), 500);
    }
});

// Verify certificate by code
$router->get('\/certificates/verify/:code', function($params) use ($db) {
    try {
        $certificate = $db->fetchOne(
            "SELECT c.*, s.name as student_name, s.email, co.title as course_title
             FROM certificates c
             JOIN students s ON c.student_id = s.id
             JOIN courses co ON c.course_id = co.id
             WHERE c.certificate_code = ? LIMIT 1",
            [$params['code']]
        );

        if (!$certificate) {
            Response::success([
                'valid' => false,
                'message' => 'Certificate not found'
            ]);
        } else {
            Response::success([
                'valid' => true,
                'certificate' => $certificate
            ]);
        }
    } catch (Exception $e) {
        Response::error('Failed to verify certificate: ' . $e->getMessage(), 500);
    }
});
