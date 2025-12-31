<?php
// api.php — simple internal-only API

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;
$file = $data['file'] ?? null;

if (!$action || !$file) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

switch ($action) {
    case 'delete':
        error_log("Deleting file: $file");
        $fileReal = realpath($file);
        if (!$fileReal || !file_exists($fileReal)) {
            error_log("File not found: $file");
            http_response_code(404);
            echo json_encode(['error' => 'File not found']);
            exit;
        }

        $trash = __DIR__.'/volumes/.trash';
        if (!is_dir($trash) && !mkdir($trash, 0777, true) && !is_dir($trash)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create trash directory']);
            exit;
        }

        $newName = $trash.'/'.uniqid().'_'.basename($fileReal);
        if (!rename($fileReal, $newName)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to move file']);
            exit;
        }

        echo json_encode(['status'=>'ok','moved_to'=>$newName]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}