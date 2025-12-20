<?php
/**
 * Programs Routes
 * GET    /api/programs - List programs
 * GET    /api/programs/:id - Get program details
 * POST   /api/programs - Create program (admin)
 * PUT    /api/programs/:id - Update program (admin)
 * DELETE /api/programs/:id - Delete program (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// List all programs
$router->get('\/programs', function($params) use ($db) {
    try {
        $programs = $db->fetchAll(
            "SELECT * FROM programs ORDER BY created_at DESC"
        );

        Response::success($programs);
    } catch (Exception $e) {
        Response::error('Failed to fetch programs: ' . $e->getMessage(), 500);
    }
});

// Get program by ID
$router->get('\/programs/:id', function($params) use ($db) {
    try {
        $program = $db->fetchOne(
            "SELECT * FROM programs WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$program) {
            Response::notFound('Program not found');
        }

        Response::success($program);
    } catch (Exception $e) {
        Response::error('Failed to fetch program: ' . $e->getMessage(), 500);
    }
});

// Create program (admin only)
$router->post('\/programs', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['title']) || empty($body['description'])) {
        Response::error('Title and description are required', 400);
    }

    try {
        $db->query(
            "INSERT INTO programs (title, description, category, image_url, created_at) VALUES (?, ?, ?, ?, NOW())",
            [
                $body['title'],
                $body['description'],
                $body['category'] ?? null,
                $body['image_url'] ?? null
            ]
        );

        $programId = $db->lastInsertId();

        Response::success(['id' => $programId], 'Program created successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to create program: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Update program (admin only)
$router->put('\/programs/:id', function($params) use ($db) {
    $body = Router::getBody();

    try {
        $program = $db->fetchOne("SELECT id FROM programs WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$program) {
            Response::notFound('Program not found');
        }

        $updates = [];
        $values = [];

        $fields = ['title', 'description', 'category', 'image_url'];
        foreach ($fields as $field) {
            if (isset($body[$field])) {
                $updates[] = "$field = ?";
                $values[] = $body[$field];
            }
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
        }

        $values[] = $params['id'];
        $sql = "UPDATE programs SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->query($sql, $values);

        Response::success(null, 'Program updated successfully');
    } catch (Exception $e) {
        Response::error('Failed to update program: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Delete program (admin only)
$router->delete('\/programs/:id', function($params) use ($db) {
    try {
        $program = $db->fetchOne("SELECT id FROM programs WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$program) {
            Response::notFound('Program not found');
        }

        $db->query("DELETE FROM programs WHERE id = ?", [$params['id']]);

        Response::success(null, 'Program deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete program: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
