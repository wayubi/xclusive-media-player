<?php
// api.php — simple internal-only API with debugging

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? null;
$files = $data['file'] ?? $data['files'] ?? null;

error_log("API request received: " . print_r($data, true));

if (!$action || !$files) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// normalize $files to array
if (!is_array($files)) $files = [$files];

switch ($action) {
    case 'delete':
        $results = [];
        $trash = __DIR__ . '/volumes/.trash';

        if (!is_dir($trash) && !mkdir($trash, 0777, true) && !is_dir($trash)) {
            error_log("Failed to create trash directory: $trash");
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create trash directory']);
            exit;
        }

        foreach ($files as $file) {
            $fsPath = __DIR__ . '/' . ltrim($file, '/');

            error_log("Attempting to delete: $fsPath");

            if (!file_exists($fsPath)) {
                error_log("File not found or inaccessible: $fsPath");
                $results[$file] = 'not_found';
                continue;
            }

            $newName = $trash . '/' . uniqid() . '_' . basename($fsPath);
            if (!rename($fsPath, $newName)) {
                error_log("Failed to move $fsPath -> $newName");
                $results[$file] = 'failed';
                continue;
            }

            error_log("Successfully moved $fsPath -> $newName");
            $results[$file] = $newName;
        }

        echo json_encode(['status' => 'ok', 'results' => $results]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}