<?php
// router.php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly if they exist
$fullPath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($fullPath)) {
    return false; // built-in server serves the file
}

// Otherwise, fallback to index.php
require_once __DIR__ . '/index.php';