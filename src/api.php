<?php

require_once __DIR__ . '/lib/AuditDatabase.php';

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

// Normalize files to array
$files = $data['files'] ?? $data['file'] ?? [];
if (!is_array($files)) {
    $files = $files ? [$files] : [];
}

$root = realpath(__DIR__ . '/volumes');
$cacheDir = '/tmp/.metadata';

if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

function getMetadataForFile(string $webPath, string $root, string $cacheDir): ?array
{
    $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $webPath), '/');
    $fsPath = realpath($root . '/' . $cleanFile);

    if (!$fsPath || !str_starts_with($fsPath, $root) || !is_file($fsPath)) {
        return null;
    }

    $hash = sha1($fsPath);
    $cacheFile = $cacheDir . '/' . $hash . '.json';

    if (file_exists($cacheFile)) {
        $json = file_get_contents($cacheFile);
        return json_decode($json, true) ?: null;
    }

    $cmd = sprintf(
        'ffprobe -v quiet -print_format json -show_format -show_streams %s',
        escapeshellarg($fsPath)
    );

    $raw = shell_exec($cmd);
    if ($raw === null || $raw === '') {
        return null;
    }

    $meta = json_decode($raw, true) ?? [];

    $video = null;
    $audio = null;

    foreach ($meta['streams'] ?? [] as $stream) {
        if ($stream['codec_type'] === 'video' && !$video) {
            $video = $stream;
        }
        if ($stream['codec_type'] === 'audio' && !$audio) {
            $audio = $stream;
        }
    }

    $output = [
        'file'     => basename($fsPath),
        'folder'   => basename(dirname($fsPath)),
        'filesize' => filesize($fsPath),
        'duration' => $meta['format']['duration'] ?? null,
        'bitrate'  => isset($meta['format']['bit_rate']) ? (int)$meta['format']['bit_rate'] : null,
        'video'    => $video ? [
            'codec'   => $video['codec_name'] ?? null,
            'width'   => $video['width'] ?? null,
            'height'  => $video['height'] ?? null,
            'fps'     => isset($video['avg_frame_rate']) && $video['avg_frame_rate'] !== '0/0'
                ? eval('return ' . $video['avg_frame_rate'] . ';')
                : null,
            'pix_fmt' => $video['pix_fmt'] ?? null,
        ] : null,
        'audio'    => $audio ? [
            'codec'       => $audio['codec_name'] ?? null,
            'channels'    => $audio['channels'] ?? null,
            'sample_rate' => $audio['sample_rate'] ?? null,
        ] : null,
    ];

    file_put_contents($cacheFile, json_encode($output, JSON_PRETTY_PRINT));

    return $output;
}

switch ($action) {
    case 'delete':
        if (empty($files)) {
            http_response_code(400);
            echo json_encode(['error' => 'No files provided']);
            exit;
        }

        $trash = '/tmp/.trash';
        if (!is_dir($trash) && !mkdir($trash, 0777, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create trash directory']);
            exit;
        }

        $results = [];

        foreach ($files as $file) {
            $decodedFile = urldecode($file);
            // Strip /volumes prefix
            $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $decodedFile), '/');
            $fsPath = realpath($root . '/' . $cleanFile);

            if (!$fsPath || !str_starts_with($fsPath, $root) || !file_exists($fsPath)) {
                $results[$file] = 'not_found';
                continue;
            }

            $newName = $trash . '/' . uniqid() . '_' . basename($fsPath);
            if (rename($fsPath, $newName)) {
                $results[$file] = 'moved';
            } else {
                $results[$file] = 'failed';
            }
        }

        echo json_encode(['status' => 'ok', 'results' => $results]);
        break;

    case 'audit':
        // NEW: Global audit using SQLite
        $filePaths = $data['file_paths'] ?? [];
        
        if (!is_array($filePaths) || empty($filePaths)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or missing file_paths']);
            exit;
        }
        
        try {
            $auditDb = new AuditDatabase();
            
            // Convert web paths to absolute paths and audit them
            $absolutePaths = [];
            foreach ($filePaths as $fsPath) {
                if (file_exists($fsPath)) {
                    $absolutePaths[] = $fsPath;
                }
            }
            
            $auditedCount = $auditDb->auditBatch($absolutePaths);
            $stats = $auditDb->getStats();
            
            echo json_encode([
                'status' => 'ok',
                'date' => date('ymd'),
                'count' => $auditedCount,
                'total_audited' => $stats['audited_files'],
                'total_files' => $stats['total_files']
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Audit failed: ' . $e->getMessage()
            ]);
        }
        break;

    case 'audit_status_batch':
        // NEW: Get audit status for multiple files
        $filePaths = $data['file_paths'] ?? [];
        
        if (!is_array($filePaths)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file_paths']);
            exit;
        }
        
        try {
            $auditDb = new AuditDatabase();
            $statuses = $auditDb->getAuditStatusBatch($filePaths);
            
            echo json_encode([
                'status' => 'ok',
                'audit_statuses' => $statuses
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to get audit status: ' . $e->getMessage()
            ]);
        }
        break;

    case 'metadata':
        $file = $files[0] ?? null;
        if (!$file) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file']);
            exit;
        }

        $meta = getMetadataForFile($file, $root, $cacheDir);

        if ($meta === null) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid file path: $file"]);
            exit;
        }

        echo json_encode($meta);
        break;

    case 'metadata_batch':
        if (empty($files)) {
            http_response_code(400);
            echo json_encode(['error' => 'No files provided']);
            exit;
        }

        // Safety limit - prevent someone sending 1000+ files at once
        $maxBatch = 36;
        $files = array_slice($files, 0, $maxBatch);

        $results = [];

        foreach ($files as $file) {
            $meta = getMetadataForFile($file, $root, $cacheDir);
            $results[$file] = $meta;  // null if failed/invalid
        }

        echo json_encode($results);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}