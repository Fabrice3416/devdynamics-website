<?php
/**
 * DevDynamics API - Main Entry Point
 * Compatible with Hostinger shared hosting
 */

// Set timezone
date_default_timezone_set('UTC');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// CORS Headers - Restreint aux domaines autorisés
$allowed_origins = [
    'https://dev-dynamics.org',
    'https://www.dev-dynamics.org',
    'http://localhost',
    'http://127.0.0.1'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: https://dev-dynamics.org');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-HTTP-Method-Override, x-http-method-override');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Load dependencies
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/utils/Router.php';
require_once __DIR__ . '/middleware/auth.php';

// Load all routes
require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/students.php';
require_once __DIR__ . '/routes/courses.php';
require_once __DIR__ . '/routes/course-content.php';
require_once __DIR__ . '/routes/quiz-management.php';
require_once __DIR__ . '/routes/certificates.php';
require_once __DIR__ . '/routes/programs.php';
require_once __DIR__ . '/routes/blog.php';
require_once __DIR__ . '/routes/donations.php';
require_once __DIR__ . '/routes/contact.php';
require_once __DIR__ . '/routes/organization.php';
require_once __DIR__ . '/routes/testimonials.php';
require_once __DIR__ . '/routes/sponsors.php';
require_once __DIR__ . '/routes/admin.php';

// Initialize Router
$router = Router::getInstance();

// Get request method and URI
$method = $_SERVER['REQUEST_METHOD'];

// Support PUT and DELETE via POST with _method parameter
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (isset($body['_method'])) {
        $method = strtoupper($body['_method']);
    } elseif (isset($_POST['_method'])) {
        $method = strtoupper($_POST['_method']);
    } elseif (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
        $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }
}

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove base path if running in subdirectory
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/') {
    $uri = substr($uri, strlen($basePath));
}

// Route the request
try {
    $router->route($method, $uri);
} catch (Exception $e) {
    Response::error($e->getMessage(), 500);
}
