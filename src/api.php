<?php

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

switch ($action) {
    case 'delete':
        if (empty($files)) {
            http_response_code(400);
            echo json_encode(['error' => 'No files provided']);
            exit;
        }

        $trash = $root . '/.trash';
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
        $path = $data['path'] ?? null;
        $count = (int)($data['count'] ?? 0);

        if (!$path) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing path']);
            exit;
        }

        // Audit paths don't have /volumes prefix usually, but clean anyway
        $cleanPath = ltrim(preg_replace('#^./volumes/#i', '', $path), '/');
        $fsPath = realpath($root . '/' . $cleanPath);
        
        if (!$fsPath || !str_starts_with($fsPath, $root) || !is_dir($fsPath)) {
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

        // Strip /volumes prefix - frontend sends web paths
        $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $file), '/');
        $fsPath = realpath($root . '/' . $cleanFile);
        
        if (!$fsPath || !str_starts_with($fsPath, $root) || !is_file($fsPath)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid file path: $file (resolved: $fsPath)"]);
            exit;
        }

        $cacheDir = $root . '/.metadata';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }

        $hash = sha1($fsPath);
        $cacheFile = $cacheDir . '/' . $hash . '.json';

        if (!file_exists($cacheFile)) {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s',
                escapeshellarg($fsPath)
            );

            $raw = shell_exec($cmd);
            if ($raw === null || $raw === '') {
                http_response_code(500);
                echo json_encode(['error' => 'ffprobe execution failed']);
                exit;
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
        }

        echo file_get_contents($cacheFile);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}