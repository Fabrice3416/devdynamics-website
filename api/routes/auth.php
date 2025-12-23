<?php
/**
 * Authentication Routes
 * POST /api/auth/login - User login
 * POST /api/auth/register - User registration (admin only)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Login route
$router->post('\/auth/login', function($params) use ($db) {
    $body = Router::getBody();

    // DEBUG: Log request
    error_log("LOGIN ATTEMPT: " . json_encode($body));

    // Validate input
    if (empty($body['email']) || empty($body['password'])) {
        error_log("LOGIN ERROR: Missing email or password");
        Response::error('Email and password are required', 400);
    }

    try {
        // Find user
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$body['email']]
        );

        if (!$user) {
            error_log("LOGIN ERROR: User not found - " . $body['email']);
            Response::error('Invalid credentials', 401);
        }

        error_log("LOGIN: User found - ID: " . $user['id']);

        // Verify password
        $passwordMatch = password_verify($body['password'], $user['password_hash']);
        error_log("LOGIN: Password verify result: " . ($passwordMatch ? 'TRUE' : 'FALSE'));

        if (!$passwordMatch) {
            error_log("LOGIN ERROR: Invalid password for " . $body['email']);
            Response::error('Invalid credentials', 401);
        }

        error_log("LOGIN: Password verified successfully");

        // Generate JWT token
        $token = JWT::encode([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);

        error_log("LOGIN: JWT token generated successfully");

        // Return user info and token
        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'name' => $user['full_name'],
                'role' => $user['role']
            ]
        ], 'Login successful');

    } catch (Exception $e) {
        error_log("LOGIN EXCEPTION: " . $e->getMessage());
        error_log("LOGIN EXCEPTION TRACE: " . $e->getTraceAsString());
        Response::error('Login failed: ' . $e->getMessage(), 500);
    }
});

// Register route (admin only)
$router->post('\/auth/register', function($params) use ($db) {
    $body = Router::getBody();

    // Validate input
    if (empty($body['email']) || empty($body['password']) || empty($body['name'])) {
        Response::error('Name, email and password are required', 400);
    }

    // Validate email format
    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Invalid email format', 400);
    }

    try {
        // Check if user exists
        $existing = $db->fetchOne(
            "SELECT id FROM users WHERE email = ? LIMIT 1",
            [$body['email']]
        );

        if ($existing) {
            Response::error('User already exists', 409);
        }

        // Hash password
        $hashedPassword = password_hash($body['password'], PASSWORD_BCRYPT);

        // Set role (default to user if not specified)
        $role = $body['role'] ?? 'user';
        if (!in_array($role, ['admin', 'instructor', 'user'])) {
            $role = 'user';
        }

        // Insert user
        $db->query(
            "INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$body['name'], $body['email'], $hashedPassword, $role]
        );

        $userId = $db->lastInsertId();

        Response::success([
            'id' => $userId,
            'name' => $body['name'],
            'email' => $body['email'],
            'role' => $role
        ], 'User registered successfully', 201);

    } catch (Exception $e) {
        Response::error('Registration failed: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
