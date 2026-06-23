<?php

require_once __DIR__ . '/lib/UserDatabase.php';
require_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/MetadataExtractor.php';
require_once __DIR__ . '/lib/AuditDatabase.php';
require_once __DIR__ . '/lib/FavoritesDatabase.php';
require_once __DIR__ . '/lib/MetadataDatabase.php';
require_once __DIR__ . '/lib/actions/ActionHandler.php';
require_once __DIR__ . '/lib/actions/DeleteAction.php';
require_once __DIR__ . '/lib/actions/MetadataAction.php';
require_once __DIR__ . '/lib/actions/AuditAction.php';
require_once __DIR__ . '/lib/actions/FavoritesAction.php';
require_once __DIR__ . '/lib/actions/ShareAction.php';
require_once __DIR__ . '/lib/actions/TerminalAction.php';
require_once __DIR__ . '/lib/actions/EditTextAction.php';
require_once __DIR__ . '/lib/session.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Streaming endpoint for terminal job output
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['stream']) && !empty($_GET['jobId'])) {
    $perms = UserDatabase::getRolePermissions($_SESSION['user_role'] ?? '');
    if (!in_array('terminal', $perms)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $metaDb = new MetadataDatabase();
    $handler = new TerminalAction(['command' => 'stream', 'jobId' => $_GET['jobId']], '/var/www/html', $metaDb);
    $handler->streamJob($_GET['jobId']);
    exit;
}

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

// list_scripts is a terminal subcommand
if ($action === 'list_scripts') {
    $data['action'] = 'terminal';
    $data['command'] = 'list_scripts';
    $action = 'terminal';
}

$actionPermissions = [
    'delete'              => ['delete'],
    'audit'               => ['audit'],
    'terminal'            => ['terminal'],
    'favorite'            => ['favorites'],
];

if (isset($actionPermissions[$action])) {
    $perms = UserDatabase::getRolePermissions($_SESSION['user_role'] ?? '');
    $required = $actionPermissions[$action];
    if (count(array_intersect($required, $perms)) !== count($required)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$root = realpath(__DIR__ . '/volumes');
$metaDb = new MetadataDatabase();

$actionMap = [
    'delete'              => DeleteAction::class,
    'metadata_batch'      => MetadataAction::class,
    'audit'               => AuditAction::class,
    'favorite'            => FavoritesAction::class,
    'share'               => ShareAction::class,
    'terminal'            => TerminalAction::class,
    'edit_text'           => EditTextAction::class,
];

if (!isset($actionMap[$action])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$handlerClass = $actionMap[$action];
$handler = new $handlerClass($data, $root, $metaDb);
$handler->handle();
