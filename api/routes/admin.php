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

// Get dashboard statistics (admin only)
$router->get('\/admin/dashboard/stats', function($params) use ($db) {
    try {
        $stats = [
            'donations' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM donations WHERE status = 'completed'"),
                'pending' => $db->fetchOne("SELECT COUNT(*) as count FROM donations WHERE status = 'pending'")
            ],
            'contacts' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM contact_messages"),
                'unread' => $db->fetchOne("SELECT COUNT(*) as count FROM contact_messages WHERE status = 'new'")
            ],
            'sponsors' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM sponsors")
            ],
            'programs' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM programs")
            ],
            'courses' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM courses"),
                'active' => $db->fetchOne("SELECT COUNT(*) as count FROM courses WHERE is_active = 1")
            ],
            'students' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM students")
            ],
            'enrollments' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM course_enrollments"),
                'active' => $db->fetchOne("SELECT COUNT(*) as count FROM course_enrollments WHERE status = 'approved'")
            ],
            'blog' => [
                'total' => $db->fetchOne("SELECT COUNT(*) as count FROM blog_posts"),
                'published' => $db->fetchOne("SELECT COUNT(*) as count FROM blog_posts WHERE is_published = 1")
            ]
        ];

        Response::success($stats);
    } catch (Exception $e) {
        Response::error('Failed to fetch dashboard stats: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// List all users (admin only)
$router->get('\/admin/users', function($params) use ($db) {
    try {
        $users = $db->fetchAll(
            "SELECT id, full_name as name, email, role, created_at FROM users ORDER BY created_at DESC"
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

    if (!in_array($body['role'], ['admin', 'instructor', 'user'])) {
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
