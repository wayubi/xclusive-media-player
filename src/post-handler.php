<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!is_array($data) || empty($data['action'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$ch = curl_init('http://php-cli:8080/api.php');

switch ($data['action']) {
    case 'delete':
        // Check for recursive folder deletion
        if (!empty($data['recursive']) && !empty($data['delete_folder']) && !empty($data['folder'])) {
            $payload = [
                'action' => 'delete',
                'recursive' => true,
                'delete_folder' => true,
                'folder' => $data['folder'],
            ];
        } elseif (!empty($data['files'])) {
            // Original file deletion
            $payload = [
                'action' => 'delete',
                'files'  => $data['files'],
            ];
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing files or folder parameter']);
            exit;
        }
        break;

    case 'audit':
        if (empty($data['path'])) {
            break;
        }

        $payload = [
            'action' => 'audit',
            'path'   => $data['path'],
            'filenames' => $data['filenames'] ?? [],
        ];
        break;

    case 'terminal':
        if (empty($data['command'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing command parameter']);
            exit;
        }

        $payload = [
            'action' => 'terminal',
            'command' => $data['command'],
            'currentDir' => $data['currentDir'] ?? '/volumes',
        ];
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_COOKIE         => http_build_query($_COOKIE, '', '; '),
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['error' => curl_error($ch)]);
} else {
    echo $response;
}

curl_close($ch);
exit;