<?php
/**
 * Contact Routes
 * GET    /api/contact - Get contact messages (admin)
 * POST   /api/contact - Submit contact form (public)
 * PUT    /api/contact/:id/status - Update message status (admin)
 * DELETE /api/contact/:id - Delete message (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get all contact messages (admin only)
$router->get('\/contact', function($params) use ($db) {
    try {
        $messages = $db->fetchAll(
            "SELECT * FROM contact_messages ORDER BY created_at DESC"
        );

        Response::success($messages);
    } catch (Exception $e) {
        Response::error('Failed to fetch messages: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Submit contact form (public)
$router->post('\/contact', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['name']) || empty($body['email']) || empty($body['message'])) {
        Response::error('Name, email and message are required', 400);
    }

    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Invalid email format', 400);
    }

    try {
        $db->query(
            "INSERT INTO contact_messages (name, email, phone, subject, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'unread', NOW())",
            [
                $body['name'],
                $body['email'],
                $body['phone'] ?? null,
                $body['subject'] ?? null,
                $body['message']
            ]
        );

        $messageId = $db->lastInsertId();

        Response::success([
            'message_id' => $messageId
        ], 'Message sent successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to send message: ' . $e->getMessage(), 500);
    }
});

// Update message status (admin only)
$router->put('\/contact/:id/status', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['status'])) {
        Response::error('Status is required', 400);
    }

    if (!in_array($body['status'], ['unread', 'read', 'replied'])) {
        Response::error('Invalid status', 400);
    }

    try {
        $message = $db->fetchOne(
            "SELECT id FROM contact_messages WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$message) {
            Response::notFound('Message not found');
        }

        $db->query(
            "UPDATE contact_messages SET status = ? WHERE id = ?",
            [$body['status'], $params['id']]
        );

        Response::success(null, 'Message status updated');
    } catch (Exception $e) {
        Response::error('Failed to update message: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Delete message (admin only)
$router->delete('\/contact/:id', function($params) use ($db) {
    try {
        $message = $db->fetchOne(
            "SELECT id FROM contact_messages WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$message) {
            Response::notFound('Message not found');
        }

        $db->query("DELETE FROM contact_messages WHERE id = ?", [$params['id']]);

        Response::success(null, 'Message deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete message: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
