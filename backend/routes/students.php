<?php
/**
 * Students Routes
 * POST   /api/students/register - Student registration
 * POST   /api/students/login - Student login
 * GET    /api/students/profile - Get student profile
 * PUT    /api/students/profile - Update profile
 * PUT    /api/students/change-password - Change password
 * GET    /api/students/enrollments - Get enrollments
 * GET    /api/students/courses/:courseId/progress - Get course progress
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Student registration
$router->post('\/students/register', function($params) use ($db) {
    $body = Router::getBody();

    // Validate input
    if (empty($body['email']) || empty($body['password']) || empty($body['name'])) {
        Response::error('Name, email and password are required', 400);
    }

    if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
        Response::error('Invalid email format', 400);
    }

    try {
        // Check if email exists
        $existing = $db->fetchOne(
            "SELECT id FROM students WHERE email = ? LIMIT 1",
            [$body['email']]
        );

        if ($existing) {
            Response::error('Email already registered', 409);
        }

        // Hash password
        $hashedPassword = password_hash($body['password'], PASSWORD_BCRYPT);

        // Insert student
        $db->query(
            "INSERT INTO students (name, email, password, phone, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$body['name'], $body['email'], $hashedPassword, $body['phone'] ?? null]
        );

        $studentId = $db->lastInsertId();

        // Generate token
        $token = JWT::encode([
            'id' => $studentId,
            'email' => $body['email'],
            'role' => 'student'
        ]);

        Response::success([
            'token' => $token,
            'student' => [
                'id' => $studentId,
                'name' => $body['name'],
                'email' => $body['email']
            ]
        ], 'Student registered successfully', 201);

    } catch (Exception $e) {
        Response::error('Registration failed: ' . $e->getMessage(), 500);
    }
});

// Student login
$router->post('\/students/login', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['email']) || empty($body['password'])) {
        Response::error('Email and password are required', 400);
    }

    try {
        $student = $db->fetchOne(
            "SELECT * FROM students WHERE email = ? LIMIT 1",
            [$body['email']]
        );

        if (!$student || !password_verify($body['password'], $student['password'])) {
            Response::error('Invalid credentials', 401);
        }

        $token = JWT::encode([
            'id' => $student['id'],
            'email' => $student['email'],
            'role' => 'student'
        ]);

        Response::success([
            'token' => $token,
            'student' => [
                'id' => $student['id'],
                'name' => $student['name'],
                'email' => $student['email'],
                'phone' => $student['phone']
            ]
        ], 'Login successful');

    } catch (Exception $e) {
        Response::error('Login failed: ' . $e->getMessage(), 500);
    }
});

// Get student profile
$router->get('\/students/profile', function($params) use ($db) {
    try {
        $user = getCurrentUser();

        $student = $db->fetchOne(
            "SELECT id, name, email, phone, created_at FROM students WHERE id = ? LIMIT 1",
            [$user['id']]
        );

        if (!$student) {
            Response::notFound('Student not found');
        }

        Response::success($student);

    } catch (Exception $e) {
        Response::error('Failed to get profile: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);

// Update student profile
$router->put('\/students/profile', function($params) use ($db) {
    $body = Router::getBody();
    $user = getCurrentUser();

    try {
        $updates = [];
        $values = [];

        if (isset($body['name'])) {
            $updates[] = "name = ?";
            $values[] = $body['name'];
        }
        if (isset($body['phone'])) {
            $updates[] = "phone = ?";
            $values[] = $body['phone'];
        }

        if (empty($updates)) {
            Response::error('No fields to update', 400);
        }

        $values[] = $user['id'];
        $sql = "UPDATE students SET " . implode(', ', $updates) . " WHERE id = ?";

        $db->query($sql, $values);

        Response::success(null, 'Profile updated successfully');

    } catch (Exception $e) {
        Response::error('Failed to update profile: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);

// Change password
$router->put('\/students/change-password', function($params) use ($db) {
    $body = Router::getBody();
    $user = getCurrentUser();

    if (empty($body['currentPassword']) || empty($body['newPassword'])) {
        Response::error('Current password and new password are required', 400);
    }

    try {
        $student = $db->fetchOne(
            "SELECT password FROM students WHERE id = ? LIMIT 1",
            [$user['id']]
        );

        if (!password_verify($body['currentPassword'], $student['password'])) {
            Response::error('Current password is incorrect', 401);
        }

        $newHash = password_hash($body['newPassword'], PASSWORD_BCRYPT);
        $db->query("UPDATE students SET password = ? WHERE id = ?", [$newHash, $user['id']]);

        Response::success(null, 'Password changed successfully');

    } catch (Exception $e) {
        Response::error('Failed to change password: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);

// Get student enrollments
$router->get('\/students/enrollments', function($params) use ($db) {
    try {
        $user = getCurrentUser();

        $enrollments = $db->fetchAll(
            "SELECT ce.*, c.title, c.description, c.thumbnail, c.duration
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             WHERE ce.student_id = ?
             ORDER BY ce.enrolled_at DESC",
            [$user['id']]
        );

        Response::success($enrollments);

    } catch (Exception $e) {
        Response::error('Failed to get enrollments: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);

// Get course progress
$router->get('\/students/courses/:courseId/progress', function($params) use ($db) {
    try {
        $user = getCurrentUser();
        $courseId = $params['courseId'];

        // Get enrollment
        $enrollment = $db->fetchOne(
            "SELECT * FROM course_enrollments WHERE student_id = ? AND course_id = ? LIMIT 1",
            [$user['id'], $courseId]
        );

        if (!$enrollment) {
            Response::notFound('Enrollment not found');
        }

        // Get completed lessons
        $progress = $db->fetchAll(
            "SELECT * FROM student_progress WHERE student_id = ? AND course_id = ?",
            [$user['id'], $courseId]
        );

        Response::success([
            'enrollment' => $enrollment,
            'progress' => $progress
        ]);

    } catch (Exception $e) {
        Response::error('Failed to get progress: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);
