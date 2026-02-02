<?php

require_once __DIR__ . '/lib/AuditDatabase.php';
require_once __DIR__ . '/lib/FavoritesDatabase.php';

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

// Check delete authorization for delete actions
if ($action === 'delete') {
    $deleteEnabled = isset($_COOKIE['delete_enabled']) && $_COOKIE['delete_enabled'] === '1';
    
    if (!$deleteEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Delete functionality is disabled. Authorization required.']);
        exit;
    }
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

function countFolderContents(string $path): array {
    $stats = ['files' => 0, 'hidden_files' => 0, 'subfolders' => 0];
    
    if (!is_dir($path)) {
        return $stats;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $stats['files']++;
            // Check if hidden (starts with .)
            if ($file->getFilename()[0] === '.') {
                $stats['hidden_files']++;
            }
        } elseif ($file->isDir()) {
            $stats['subfolders']++;
        }
    }
    
    // Subtract 1 from subfolders if we're counting the root folder itself
    if ($stats['subfolders'] > 0) {
        $stats['subfolders']--;
    }
    
    return $stats;
}

function deleteRecursive(string $path): bool {
    if (!file_exists($path)) {
        return true;
    }
    
    if (is_file($path)) {
        return unlink($path);
    }
    
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                rmdir($file->getPathname());
            }
        }
        
        return rmdir($path);
    }
    
    return false;
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
        $recursive = $data['recursive'] ?? false;
        $deleteFolder = $data['delete_folder'] ?? false;
        
        if ($recursive && $deleteFolder) {
            // Recursive folder deletion mode
            $folder = $data['folder'] ?? null;
            if (!$folder) {
                http_response_code(400);
                echo json_encode(['error' => 'No folder path provided for recursive deletion']);
                exit;
            }
            
            $decodedFolder = urldecode($folder);
            $cleanFolder = ltrim(preg_replace('#^/volumes/#i', '', $decodedFolder), '/');
            $fsPath = realpath($root . '/' . $cleanFolder);
            
            if (!$fsPath || !str_starts_with($fsPath, $root) || !is_dir($fsPath)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid folder path']);
                exit;
            }
            
            // Get parent path before deletion
            $parentPath = dirname($fsPath);
            $parentWebPath = '/volumes/' . ltrim(str_replace($root, '', $parentPath), '/');
            if ($parentWebPath === '/volumes/') {
                $parentWebPath = '/volumes';
            }
            
            // Count items before deletion
            $stats = countFolderContents($fsPath);
            
            // Perform recursive deletion
            $deleted = deleteRecursive($fsPath);
            
            if ($deleted) {
                echo json_encode([
                    'status' => 'success',
                    'deleted' => $stats,
                    'parent_path' => $parentWebPath,
                    'message' => "Deleted {$stats['files']} files ({$stats['hidden_files']} hidden), {$stats['subfolders']} subfolders, and the folder"
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete folder']);
            }
        } else {
            // Original single file deletion mode
            if (empty($files)) {
                http_response_code(400);
                echo json_encode(['error' => 'No files provided']);
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

                if (unlink($fsPath)) {
                    $results[$file] = 'deleted';
                } else {
                    $results[$file] = 'failed';
                }
            }

            echo json_encode(['status' => 'ok', 'results' => $results]);
        }
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

    case 'toggle_favorite':
        $file = $files[0] ?? null;
        if (!$file) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file']);
            exit;
        }
        
        // Convert web path to filesystem path
        $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', urldecode($file)), '/');
        $fsPath = realpath($root . '/' . $cleanFile);
        
        if (!$fsPath || !str_starts_with($fsPath, $root) || !file_exists($fsPath)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid file path: $file"]);
            exit;
        }
        
        try {
            $favDb = new FavoritesDatabase();
            $isFavorited = $favDb->toggleFavorite($fsPath);
            
            echo json_encode([
                'status' => 'ok',
                'favorited' => $isFavorited,
                'file' => $file
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to toggle favorite: ' . $e->getMessage()]);
        }
        break;

    case 'favorites_status_batch':
        // Get favorite status for multiple files
        $filePaths = $data['file_paths'] ?? [];
        
        if (!is_array($filePaths)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file_paths']);
            exit;
        }
        
        try {
            $favDb = new FavoritesDatabase();
            $statuses = $favDb->getFavoriteStatusBatch($filePaths);
            
            echo json_encode([
                'status' => 'ok',
                'favorite_statuses' => $statuses
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to get favorite status: ' . $e->getMessage()
            ]);
        }
        break;

    case 'get_favorites_count':
        // Get count of favorites in current folder
        $folderPath = $data['folder_path'] ?? null;
        
        if (!$folderPath) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing folder_path']);
            exit;
        }
        
        try {
            $favDb = new FavoritesDatabase();
            $count = $favDb->getFavoritesCountInFolder($folderPath);
            
            echo json_encode([
                'status' => 'ok',
                'count' => $count
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => 'Failed to get favorites count: ' . $e->getMessage()
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}