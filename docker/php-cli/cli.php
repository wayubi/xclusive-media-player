<?php
// cli.php - Router for PHP built-in server in php-cli container

// Get the requested URI
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Map requests to actual PHP files
$scriptMap = [
    '/getAudioCover.php' => __DIR__ . '/getAudioCover.php',
];

// Serve static files directly if they exist
$fullPath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($fullPath)) {
    return false; // Built-in server serves the file directly
}

// Check if requested PHP script exists in map
if (isset($scriptMap[$uri])) {
    require_once $scriptMap[$uri];
    exit;
}

// Default fallback: 404
http_response_code(404);
echo json_encode(['error' => 'Not found']);