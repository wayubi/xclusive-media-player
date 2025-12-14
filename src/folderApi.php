<?php
$rootDir = './volumes';
$path = $_GET['path'] ?? '';
$fullPath = $rootDir . ($path ? '/' . $path : '');
$dirs = [];
if (is_dir($fullPath)) {
    foreach (glob($fullPath . '/*', GLOB_ONLYDIR) as $dir) {
        $dirs[] = basename($dir);
    }
}
sort($dirs);
header('Content-Type: application/json');
echo json_encode($dirs);