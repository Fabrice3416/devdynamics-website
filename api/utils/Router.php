<?php
/**
 * Simple Router Class
 * Handles REST API routing
 */

class Router {
    private static $instance = null;
    private $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => []
    ];

    private function __construct() {}

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register GET route
     */
    public function get($path, $callback, $middleware = []) {
        $this->addRoute('GET', $path, $callback, $middleware);
    }

    /**
     * Register POST route
     */
    public function post($path, $callback, $middleware = []) {
        $this->addRoute('POST', $path, $callback, $middleware);
    }

    /**
     * Register PUT route
     */
    public function put($path, $callback, $middleware = []) {
        $this->addRoute('PUT', $path, $callback, $middleware);
    }

    /**
     * Register DELETE route
     */
    public function delete($path, $callback, $middleware = []) {
        $this->addRoute('DELETE', $path, $callback, $middleware);
    }

    /**
     * Add route to routes array
     */
    private function addRoute($method, $path, $callback, $middleware) {
        $this->routes[$method][] = [
            'path' => $path,
            'callback' => $callback,
            'middleware' => $middleware,
            'pattern' => $this->pathToPattern($path)
        ];
    }

    /**
     * Convert path to regex pattern
     */
    private function pathToPattern($path) {
        // Replace :param with regex pattern
        $pattern = preg_replace('/\/:([^\/]+)/', '/(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    /**
     * Route the request
     */
    public function route($method, $uri) {
        // Check if method exists
        if (!isset($this->routes[$method])) {
            Response::error('Method not allowed', 405);
        }

        // Find matching route
        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract params
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // Run middleware
                foreach ($route['middleware'] as $middleware) {
                    call_user_func($middleware);
                }

                // Call route callback
                call_user_func($route['callback'], $params);
                return;
            }
        }

        // No route found
        Response::notFound('Route not found');
    }

    /**
     * Get request body as array
     */
    public static function getBody() {
        $body = file_get_contents('php://input');
        return json_decode($body, true) ?: [];
    }

    /**
     * Get query parameters
     */
    public static function getQuery() {
        return $_GET;
    }

    /**
     * Get specific query parameter
     */
    public static function getQueryParam($key, $default = null) {
        return $_GET[$key] ?? $default;
    }

    /**
     * Get request headers
     */
    public static function getHeaders() {
        return getallheaders() ?: [];
    }

    /**
     * Get specific header
     */
    public static function getHeader($key, $default = null) {
        $headers = self::getHeaders();
        return $headers[$key] ?? $default;
    }
}
