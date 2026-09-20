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

    // Validate input
    if (empty($body['email']) || empty($body['password'])) {
        Response::error('Email and password are required', 400);
    }

    try {
        // Find user
        $user = $db->fetchOne(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$body['email']]
        );

        if (!$user) {
            Response::error('Invalid credentials', 401);
        }

        // La colonne s'appelle password_hash : lire $user['password'] renvoyait
        // null, et password_verify(null) echoue toujours. La connexion ne
        // pouvait donc jamais aboutir, quel que soit le mot de passe saisi.
        if (!password_verify($body['password'], $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        // Un compte desactive ne doit pas obtenir de jeton. Le controle vient
        // apres la verification du mot de passe : sinon la reponse revelerait
        // l'existence d'un compte a qui ne connait pas son mot de passe.
        if (isset($user['is_active']) && (int) $user['is_active'] !== 1) {
            Response::forbidden('Ce compte est desactive. Contacte un administrateur.');
        }

        // Generate JWT token
        $token = JWT::encode([
            'id' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);

        // Return user info and token
        Response::success([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'full_name' => $user['full_name'],
                'role' => $user['role']
            ]
        ], 'Login successful');

    } catch (Exception $e) {
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

        // Les seuls roles que la colonne accepte sont admin, editor et student
        $role = $body['role'] ?? 'student';
        if (!in_array($role, ['admin', 'editor', 'student'])) {
            $role = 'student';
        }

        $db->query(
            "INSERT INTO users (full_name, email, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$body['name'], $body['email'], $hashedPassword, $role]
        );

        $userId = $db->lastInsertId();

        Response::success([
            'id' => $userId,
            'full_name' => $body['name'],
            'email' => $body['email'],
            'role' => $role
        ], 'User registered successfully', 201);

    } catch (Exception $e) {
        Response::error('Registration failed: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
