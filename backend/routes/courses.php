<?php
/**
 * Courses Routes
 * GET    /api/courses - List all active courses (public)
 * GET    /api/courses/all - List all courses (admin)
 * GET    /api/courses/:id - Get course details
 * POST   /api/courses - Create course (admin)
 * PUT    /api/courses/:id - Update course (admin)
 * DELETE /api/courses/:id - Delete course (admin)
 * POST   /api/courses/:id/enroll - Enroll in course
 * GET    /api/courses/:id/enrollments - Get course enrollments (admin)
 * GET    /api/courses/enrollments/all - Get all enrollments (admin)
 * PUT    /api/courses/enrollments/:id/status - Update enrollment status (admin)
 */

$router = Router::getInstance();
$db = Database::getInstance();

// List all active courses (public)
$router->get('\/courses', function($params) use ($db) {
    try {
        $courses = $db->fetchAll(
            "SELECT * FROM courses WHERE is_active = 1 ORDER BY created_at DESC"
        );

        Response::success($courses);
    } catch (Exception $e) {
        Response::error('Failed to fetch courses: ' . $e->getMessage(), 500);
    }
});

// List all courses (admin only)
$router->get('\/courses/all', function($params) use ($db) {
    try {
        $courses = $db->fetchAll(
            "SELECT * FROM courses ORDER BY created_at DESC"
        );

        Response::success($courses);
    } catch (Exception $e) {
        Response::error('Failed to fetch courses: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Get course by ID
$router->get('\/courses/:id', function($params) use ($db) {
    try {
        $course = $db->fetchOne(
            "SELECT * FROM courses WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$course) {
            Response::notFound('Course not found');
        }

        Response::success($course);
    } catch (Exception $e) {
        Response::error('Failed to fetch course: ' . $e->getMessage(), 500);
    }
});

// Create course (admin only)
$router->post('\/courses', function($params) use ($db) {
    $body = Router::getBody();

    // Validate required fields
    if (empty($body['title']) || empty($body['description'])) {
        Response::error('Title and description are required', 400);
    }

    try {
        $db->query(
            "INSERT INTO courses (title, description, thumbnail, duration, price, instructor, start_date, end_date, max_students, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $body['title'],
                $body['description'],
                $body['thumbnail'] ?? null,
                $body['duration'] ?? null,
                $body['price'] ?? 0,
                $body['instructor'] ?? null,
                $body['start_date'] ?? null,
                $body['end_date'] ?? null,
                $body['max_students'] ?? null,
                $body['is_active'] ?? 1
            ]
        );

        $courseId = $db->lastInsertId();

        Response::success(['id' => $courseId], 'Course created successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to create course: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Update course (admin only)
$router->put('\/courses/:id', function($params) use ($db) {
    $body = Router::getBody();

    try {
        // Check if course exists
        $course = $db->fetchOne("SELECT id FROM courses WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$course) {
            Response::notFound('Course not found');
        }

        $updates = [];
        $values = [];

        $fields = ['title', 'description', 'thumbnail', 'duration', 'price', 'instructor', 'start_date', 'end_date', 'max_students', 'is_active'];
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
        $sql = "UPDATE courses SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->query($sql, $values);

        Response::success(null, 'Course updated successfully');
    } catch (Exception $e) {
        Response::error('Failed to update course: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Delete course (admin only)
$router->delete('\/courses/:id', function($params) use ($db) {
    try {
        $course = $db->fetchOne("SELECT id FROM courses WHERE id = ? LIMIT 1", [$params['id']]);
        if (!$course) {
            Response::notFound('Course not found');
        }

        $db->query("DELETE FROM courses WHERE id = ?", [$params['id']]);

        Response::success(null, 'Course deleted successfully');
    } catch (Exception $e) {
        Response::error('Failed to delete course: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Enroll in course
$router->post('\/courses/:id/enroll', function($params) use ($db) {
    $user = getCurrentUser();
    $body = Router::getBody();

    try {
        // Check if course exists
        $course = $db->fetchOne(
            "SELECT * FROM courses WHERE id = ? AND is_active = 1 LIMIT 1",
            [$params['id']]
        );

        if (!$course) {
            Response::notFound('Course not found or not active');
        }

        // Check if already enrolled
        $existing = $db->fetchOne(
            "SELECT id FROM course_enrollments WHERE student_id = ? AND course_id = ? LIMIT 1",
            [$user['id'], $params['id']]
        );

        if ($existing) {
            Response::error('Already enrolled in this course', 409);
        }

        // Check max students
        if ($course['max_students']) {
            $count = $db->fetchOne(
                "SELECT COUNT(*) as count FROM course_enrollments WHERE course_id = ? AND status = 'enrolled'",
                [$params['id']]
            );

            if ($count['count'] >= $course['max_students']) {
                Response::error('Course is full', 400);
            }
        }

        // Create enrollment
        $db->query(
            "INSERT INTO course_enrollments (student_id, course_id, status, enrolled_at) VALUES (?, ?, 'enrolled', NOW())",
            [$user['id'], $params['id']]
        );

        $enrollmentId = $db->lastInsertId();

        Response::success(['enrollment_id' => $enrollmentId], 'Enrolled successfully', 201);
    } catch (Exception $e) {
        Response::error('Failed to enroll: ' . $e->getMessage(), 500);
    }
}, ['authMiddleware']);

// Get course enrollments (admin only)
$router->get('\/courses/:id/enrollments', function($params) use ($db) {
    try {
        $enrollments = $db->fetchAll(
            "SELECT ce.*, s.name, s.email
             FROM course_enrollments ce
             JOIN students s ON ce.student_id = s.id
             WHERE ce.course_id = ?
             ORDER BY ce.enrolled_at DESC",
            [$params['id']]
        );

        Response::success($enrollments);
    } catch (Exception $e) {
        Response::error('Failed to fetch enrollments: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Get all enrollments (admin only)
$router->get('\/courses/enrollments/all', function($params) use ($db) {
    try {
        $enrollments = $db->fetchAll(
            "SELECT ce.*, c.title as course_title, s.name, s.email
             FROM course_enrollments ce
             JOIN courses c ON ce.course_id = c.id
             JOIN students s ON ce.student_id = s.id
             ORDER BY ce.enrolled_at DESC"
        );

        Response::success($enrollments);
    } catch (Exception $e) {
        Response::error('Failed to fetch enrollments: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Update enrollment status (admin only)
$router->put('\/courses/enrollments/:id/status', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['status'])) {
        Response::error('Status is required', 400);
    }

    if (!in_array($body['status'], ['enrolled', 'completed', 'dropped'])) {
        Response::error('Invalid status', 400);
    }

    try {
        $enrollment = $db->fetchOne(
            "SELECT id FROM course_enrollments WHERE id = ? LIMIT 1",
            [$params['id']]
        );

        if (!$enrollment) {
            Response::notFound('Enrollment not found');
        }

        $db->query(
            "UPDATE course_enrollments SET status = ? WHERE id = ?",
            [$body['status'], $params['id']]
        );

        Response::success(null, 'Enrollment status updated');
    } catch (Exception $e) {
        Response::error('Failed to update enrollment: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);
