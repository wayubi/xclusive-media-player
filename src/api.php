<?php

require_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/MetadataExtractor.php';
require_once __DIR__ . '/lib/AuditDatabase.php';
require_once __DIR__ . '/lib/FavoritesDatabase.php';
require_once __DIR__ . '/lib/MetadataDatabase.php';
require_once __DIR__ . '/lib/actions/ActionHandler.php';
require_once __DIR__ . '/lib/actions/DeleteAction.php';
require_once __DIR__ . '/lib/actions/MetadataAction.php';
require_once __DIR__ . '/lib/actions/AuditAction.php';
require_once __DIR__ . '/lib/actions/AuditStatusAction.php';
require_once __DIR__ . '/lib/actions/FavoritesAction.php';
require_once __DIR__ . '/lib/actions/TerminalAction.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed - POST required']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action parameter']);
    exit;
}

if ($action === 'delete') {
    $deleteEnabled = isset($_COOKIE['delete_enabled']) && $_COOKIE['delete_enabled'] === '1';
    
    if (!$deleteEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Delete functionality is disabled. Authorization required.']);
        exit;
    }
}

$root = realpath(__DIR__ . '/volumes');
$metaDb = new MetadataDatabase();

$actionMap = [
    'delete' => DeleteAction::class,
    'metadata' => MetadataAction::class,
    'metadata_batch' => MetadataAction::class,
    'audit' => AuditAction::class,
    'audit_status_batch' => AuditStatusAction::class,
    'toggle_favorite' => FavoritesAction::class,
    'favorites_status_batch' => FavoritesAction::class,
    'get_favorites_count' => FavoritesAction::class,
    'terminal' => TerminalAction::class,
];

if (!isset($actionMap[$action])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$handlerClass = $actionMap[$action];
$handler = new $handlerClass($data, $root, $metaDb);
$handler->handle();
