<?php
// Debug routing

echo "<h2>Debug Info</h2>";
echo "<pre>";

echo "REQUEST_URI: " . $_SERVER['REQUEST_URI'] . "\n";
echo "SCRIPT_NAME: " . $_SERVER['SCRIPT_NAME'] . "\n";

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "Parsed URI: " . $uri . "\n";

$basePath = dirname($_SERVER['SCRIPT_NAME']);
echo "Base Path: " . $basePath . "\n";

if ($basePath !== '/') {
    $uri = substr($uri, strlen($basePath));
}

echo "Final URI for routing: " . $uri . "\n";
echo "</pre>";

echo "<h3>Expected routes:</h3>";
echo "<ul>";
echo "<li>GET /api/courses</li>";
echo "<li>GET /api/programs</li>";
echo "<li>GET /api/organization</li>";
echo "</ul>";
