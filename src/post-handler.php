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
        if (empty($data['files'])) {
            break;
        }

        $payload = [
            'action' => 'delete',
            'files'  => $data['files'],
        ];
        break;

    case 'audit':
        if (empty($data['path'])) {
            break;
        }

        $payload = [
            'action' => 'audit',
            'path'   => $data['path'],
            'count'  => (int)($data['count'] ?? 0),
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
