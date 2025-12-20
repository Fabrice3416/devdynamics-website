<?php
/**
 * Course Content Routes
 * GET /api/content/courses/:courseId/modules - Get course modules
 * GET /api/content/modules/:moduleId - Get single module
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Get course modules with lessons
$router->get('\/content/courses/:courseId/modules', function($params) use ($db) {
    try {
        // Get all modules for the course
        $modules = $db->fetchAll(
            "SELECT * FROM course_modules WHERE course_id = ? ORDER BY order_index ASC",
            [$params['courseId']]
        );

        // Get lessons for each module
        foreach ($modules as &$module) {
            $lessons = $db->fetchAll(
                "SELECT * FROM course_lessons WHERE module_id = ? ORDER BY order_index ASC",
                [$module['id']]
            );
            $module['lessons'] = $lessons;
        }

        Response::success($modules);
    } catch (Exception $e) {
        Response::error('Failed to fetch modules: ' . $e->getMessage(), 500);
    }
});

// Get single module with lessons
$router->get('\/content/modules/:moduleId', function($params) use ($db) {
    try {
        $module = $db->fetchOne(
            "SELECT * FROM course_modules WHERE id = ? LIMIT 1",
            [$params['moduleId']]
        );

        if (!$module) {
            Response::notFound('Module not found');
        }

        // Get lessons for this module
        $lessons = $db->fetchAll(
            "SELECT * FROM course_lessons WHERE module_id = ? ORDER BY order_index ASC",
            [$params['moduleId']]
        );

        $module['lessons'] = $lessons;

        Response::success($module);
    } catch (Exception $e) {
        Response::error('Failed to fetch module: ' . $e->getMessage(), 500);
    }
});
