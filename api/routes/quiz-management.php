<?php
/**
 * Quiz Management Routes
 * POST /api/quiz-management/modules/:moduleId/quiz - Create module quiz (admin)
 * GET  /api/quiz-management/modules/:moduleId/quiz - Get module quiz
 */

$router = Router::getInstance();
$db = Database::getInstance();

// Create quiz for module (admin only)
$router->post('\/quiz-management/modules/:moduleId/quiz', function($params) use ($db) {
    $body = Router::getBody();

    if (empty($body['title']) || empty($body['questions'])) {
        Response::error('Title and questions are required', 400);
    }

    try {
        $db->beginTransaction();

        // Create quiz
        $db->query(
            "INSERT INTO quizzes (module_id, title, description, passing_score, time_limit, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $params['moduleId'],
                $body['title'],
                $body['description'] ?? null,
                $body['passing_score'] ?? 70,
                $body['time_limit'] ?? null
            ]
        );

        $quizId = $db->lastInsertId();

        // Insert questions
        if (isset($body['questions']) && is_array($body['questions'])) {
            foreach ($body['questions'] as $index => $question) {
                $db->query(
                    "INSERT INTO quiz_questions (quiz_id, question_text, question_type, options, correct_answer, points, order_index)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [
                        $quizId,
                        $question['question_text'],
                        $question['question_type'] ?? 'multiple_choice',
                        json_encode($question['options'] ?? []),
                        $question['correct_answer'],
                        $question['points'] ?? 1,
                        $index
                    ]
                );
            }
        }

        $db->commit();

        Response::success(['quiz_id' => $quizId], 'Quiz created successfully', 201);
    } catch (Exception $e) {
        $db->rollback();
        Response::error('Failed to create quiz: ' . $e->getMessage(), 500);
    }
}, ['adminMiddleware']);

// Get module quiz
$router->get('\/quiz-management/modules/:moduleId/quiz', function($params) use ($db) {
    try {
        // Get quiz
        $quiz = $db->fetchOne(
            "SELECT * FROM quizzes WHERE module_id = ? LIMIT 1",
            [$params['moduleId']]
        );

        if (!$quiz) {
            Response::notFound('Quiz not found');
        }

        // Get questions
        $questions = $db->fetchAll(
            "SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY order_index ASC",
            [$quiz['id']]
        );

        // Decode options JSON
        foreach ($questions as &$question) {
            if ($question['options']) {
                $question['options'] = json_decode($question['options'], true);
            }
        }

        $quiz['questions'] = $questions;

        Response::success($quiz);
    } catch (Exception $e) {
        Response::error('Failed to fetch quiz: ' . $e->getMessage(), 500);
    }
});
