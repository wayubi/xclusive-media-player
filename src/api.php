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

if (!$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action']);
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

    case 'audit':
        $path  = $data['path'] ?? null;
        $count = (int)($data['count'] ?? 0);

        if (!$path) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing path']);
            exit;
        }

        $fsPath = realpath(__DIR__ . '/' . ltrim($path, '/'));
        if (!$fsPath || !is_dir($fsPath)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid path']);
            exit;
        }

        $auditFile = $fsPath . '/.audited';
        $timestamp = date('ymd');
        $line = "$timestamp / $count";

        if (file_put_contents($auditFile, $line . PHP_EOL) === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to write audit file']);
            exit;
        }

        echo json_encode([
            'status' => 'ok',
            'text'   => $line
        ]);
        break;

    case 'metadata':
        $file = $files[0] ?? null;
        if (!$file) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file']);
            exit;
        }

        // Resolve filesystem path safely
        $root = realpath(__DIR__ . '/volumes');
        $fsPath = realpath(__DIR__ . '/' . ltrim($file, '/'));

        if (!$fsPath || !str_starts_with($fsPath, $root) || !is_file($fsPath)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file path']);
            exit;
        }

        $cacheDir = $root . '/.metadata';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $hash = sha1($fsPath);
        $cacheFile = "$cacheDir/$hash.json";

        if (!file_exists($cacheFile)) {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s',
                escapeshellarg($fsPath)
            );

            $raw = shell_exec($cmd);
            if (!$raw) {
                http_response_code(500);
                echo json_encode(['error' => 'ffprobe failed']);
                exit;
            }

            $meta = json_decode($raw, true);

            // ---- Normalize useful fields ----
            $video = null;
            $audio = null;

            foreach ($meta['streams'] ?? [] as $s) {
                if ($s['codec_type'] === 'video' && !$video) $video = $s;
                if ($s['codec_type'] === 'audio' && !$audio) $audio = $s;
            }

            $out = [
                'file'      => basename($fsPath),
                'filesize'  => filesize($fsPath),
                'duration'  => isset($meta['format']['duration']) ? (float)$meta['format']['duration'] : null,
                'bitrate'   => isset($meta['format']['bit_rate']) ? (int)$meta['format']['bit_rate'] : null,

                'video' => $video ? [
                    'codec'   => $video['codec_name'] ?? null,
                    'width'   => $video['width'] ?? null,
                    'height'  => $video['height'] ?? null,
                    'fps'     => isset($video['avg_frame_rate']) && $video['avg_frame_rate'] !== '0/0'
                        ? eval('return ' . $video['avg_frame_rate'] . ';')
                        : null,
                    'pix_fmt' => $video['pix_fmt'] ?? null,
                ] : null,

                'audio' => $audio ? [
                    'codec'     => $audio['codec_name'] ?? null,
                    'channels'  => $audio['channels'] ?? null,
                    'sample_rate' => $audio['sample_rate'] ?? null,
                ] : null,
            ];

            file_put_contents($cacheFile, json_encode($out, JSON_PRETTY_PRINT));
        }

        echo file_get_contents($cacheFile);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}