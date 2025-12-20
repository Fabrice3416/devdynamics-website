<?php
/**
 * Donations Routes
 * GET  /api/donations - Get donations (admin)
 * GET  /api/donations/stats - Donation statistics (admin)
 * POST /api/donations - Create donation (public)
 * PUT  /api/donations/:id/status - Update donation status (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get all donations (admin only)
$router->get('\/donations', function($params) use ($db) {
    try {
        $donations = $db->fetchAll(
            "SELECT * FROM donations ORDER BY created_at DESC"
        );

        Response::success($donations);
    } catch (Exception $e) {
        Response::error('Failed to fetch donations: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Get donation statistics (admin only)
$router->get('\/donations/stats', function($params) use ($db) {
    try {
        $stats = [
            'total' => $db->fetchOne("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM donations"),
            'completed' => $db->fetchOne("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM donations WHERE payment_status = 'completed'"),
            'pending' => $db->fetchOne("SELECT COUNT(*) as count, COALESCE(SUM(amount), 0) as total FROM donations WHERE payment_status = 'pending'"),
            'recent' => $db->fetchAll("SELECT * FROM donations ORDER BY created_at DESC LIMIT 5")
        ];

        Response::success($stats);
    } catch (Exception $e) {
        Response::error('Failed to fetch donation stats: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Create donation (public)
$router->post('\/donations', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['donor_name']) || empty($body['amount']) || empty($body['email'])) {
        Response::error('Donor name, email and amount are required', 400);
    }

    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Invalid email format', 400);
    }

    if ($body['amount'] <= 0) {
        Response::error('Amount must be greater than 0', 400);
    }

    try {
        $db->query(
            "INSERT INTO donations (donor_name, email, phone, amount, message, payment_status, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', NOW())",
            [
                $body['donor_name'],
                $body['email'],
                $body['phone'] ?? null,
                $body['amount'],
                $body['message'] ?? null
            ]
        );

        $donationId = $db->lastInsertId();

        Response::success([
            'donation_id' => $donationId,
            'message' => 'Thank you for your donation!'
        ], 'Donation recorded successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to process donation: ' . $e->getMessage(), 500);
    }
});

// Update donation status (admin only)
$router->put('\/donations/:id/status', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['payment_status'])) {
        Response::error('Payment status is required', 400);
    }

    if (!in_array($body['payment_status'], ['pending', 'completed', 'failed', 'refunded'])) {
        Response::error('Invalid payment status', 400);
    }

    try {
        $donation = $db->fetchOne(
            "SELECT id FROM donations WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$donation) {
            Response::notFound('Donation not found');
        }

        $db->query(
            "UPDATE donations SET payment_status = ? WHERE id = ?",
            [$body['payment_status'], $params['id']]
        );

        Response::success(null, 'Donation status updated');
    } catch (Exception $e) {
        Response::error('Failed to update donation: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
