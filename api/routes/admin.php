<?php
/**
 * Admin Routes
 * GET    /api/admin/dashboard/stats - Dashboard statistics (admin)
 * GET    /api/admin/users - List all users (admin)
 * PUT    /api/admin/users/:id/role - Update user role (admin)
 * DELETE /api/admin/users/:id - Delete user (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Chaque compteur est lu a part : une table indisponible ne fait pas tomber tout l'ecran
function admin_count($db, $sql) {
    try {
        return (int) $db->fetchOne($sql)['total'];
    } catch (Exception $e) {
        error_log('admin_count : ' . $e->getMessage());
        return null;
    }
}

$router->get('\/admin/dashboard/stats', function($params) use ($db) {
    Response::success([
        'messages_new' => admin_count($db, "SELECT COUNT(*) AS total FROM contact_messages WHERE status = 'new'"),
        'testimonials_pending' => admin_count($db, "SELECT COUNT(*) AS total FROM project_testimonials WHERE status = 'pending'"),
        'forms_open' => admin_count($db, "SELECT COUNT(*) AS total FROM forms WHERE status = 'open'"),
        'responses_week' => admin_count($db, "SELECT COUNT(*) AS total FROM form_responses WHERE created_at >= NOW() - INTERVAL 7 DAY"),
        'projects_published' => admin_count($db, "SELECT COUNT(*) AS total FROM projects WHERE status = 'published'"),
    ]);
}, ['adminMiddleware']);

$router->get('\/admin/users', function($params) use ($db) {
    try {
        $users = $db->fetchAll(
            "SELECT id, full_name, email, role, created_at FROM users ORDER BY created_at DESC"
        );

        Response::success($users);
    } catch (Exception $e) {
        Response::error('Failed to fetch users: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Update user role (admin only)
$router->put('\/admin/users/:id/role', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['role'])) {
        Response::error('Role is required', 400);
    }

    if (!in_array($body['role'], ['admin', 'editor', 'student'])) {
        Response::error('Invalid role', 400);
    }

    try {
        $user = $db->fetchOne(
            "SELECT id FROM users WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$user) {
            Response::notFound('User not found');
        }

        $db->query(
            "UPDATE users SET role = ? WHERE id = ?",
            [$body['role'], $params['id']]
        );

        Response::success(null, 'User role updated');
    } catch (Exception $e) {
        Response::error('Failed to update user role: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Delete user (admin only)
$router->delete('\/admin/users/:id', function($params) use ($db) {
    try {
        $currentUser = getCurrentUser();

        // Prevent self-deletion
        if ($currentUser['id'] == $params['id']) {
            Response::error('Cannot delete your own account', 400);
        }

        $user = $db->fetchOne(
            "SELECT id FROM users WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$user) {
            Response::notFound('User not found');
        }

        $db->query("DELETE FROM users WHERE id = ?", [$params['id']]);

        Response::success(null, 'User deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete user: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
