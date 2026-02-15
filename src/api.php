<?php

require_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/MetadataExtractor.php';
require_once __DIR__ . '/lib/AuditDatabase.php';
require_once __DIR__ . '/lib/FavoritesDatabase.php';
require_once __DIR__ . '/lib/MetadataDatabase.php';

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

// Decode base64-encoded filesystem paths
$files = Utils::decodeBase64Paths($files);

$root = realpath(__DIR__ . '/volumes');

// Initialize metadata database
$metaDb = new MetadataDatabase();

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
        $pathname = $file->getPathname();
        $filename = $file->getFilename();
        
        // Skip hidden directories and their contents
        $pathParts = explode('/', dirname($pathname));
        foreach ($pathParts as $part) {
            if (!empty($part) && $part[0] === '.') {
                continue 2; // Skip this entry if in a hidden directory
            }
        }
        
        if ($file->isFile()) {
            $stats['files']++;
            // Check if hidden (starts with .)
            if ($filename[0] === '.') {
                $stats['hidden_files']++;
            }
        } elseif ($file->isDir()) {
            // Skip hidden directories
            if ($filename[0] !== '.') {
                $stats['subfolders']++;
            }
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

/**
 * Get metadata for a file
 * Uses MetadataExtractor for all extraction logic
 */
function getMetadataForFile(string $webPath, string $root, MetadataDatabase $metaDb): ?array
{
    $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $webPath), '/');
    $cleanFile = urldecode($cleanFile);
    $fsPath = realpath($root . '/' . $cleanFile);

    if (!$fsPath || !str_starts_with($fsPath, $root) || !is_file($fsPath)) {
        return ['file' => base64_encode(basename($webPath)), 'folder' => '', 'optimizationStatus' => ['isOptimized' => true, 'issues' => []]];
    }

    // Check database first
    $metadata = $metaDb->getMetadata($webPath, $fsPath);
    if ($metadata !== null) {
        if (isset($metadata['file'])) {
            $metadata['file'] = base64_encode($metadata['file']);
        }
        return $metadata;
    }

    // Extract metadata using MetadataExtractor
    $output = MetadataExtractor::extract($fsPath);

    // Save to database
    $metaDb->saveMetadata($webPath, $fsPath, $output);

    // Return base64_encoded for JSON compatibility
    $output['file'] = base64_encode($output['file']);
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
            
            $fsPath = Utils::resolveWebPathForDir($folder, $root);
            
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
                
                // Try multiple path variations
                $candidatePaths = [
                    $root . '/' . $cleanFile,
                    $root . '/' . urldecode($cleanFile),
                ];
                
                $fsPath = null;
                foreach ($candidatePaths as $candidate) {
                    $resolved = realpath($candidate);
                    if ($resolved && str_starts_with($resolved, $root)) {
                        $fsPath = $resolved;
                        break;
                    }
                }

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
        
        // Decode base64-encoded filesystem paths
        $filePaths = Utils::decodeBase64Paths($filePaths);
        
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
        
        // Decode base64-encoded filesystem paths
        $filePaths = Utils::decodeBase64Paths($filePaths);
        
        try {
            $auditDb = new AuditDatabase();
            $statuses = $auditDb->getAuditStatusBatch($filePaths);
            
            echo json_encode([
                'status' => 'ok',
                'audit_statuses' => Utils::encodeArrayKeysBase64($statuses)
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

        // Convert filesystem path to web path for getMetadataForFile
        $webPath = Utils::filesystemToWebPath($file, $root);
        $meta = getMetadataForFile($webPath, $root, $metaDb);

        if ($meta === null) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid file path: " . base64_encode($file)]);
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
            // Convert filesystem path to web path for getMetadataForFile
            $webPath = Utils::filesystemToWebPath($file, $root);
            $meta = getMetadataForFile($webPath, $root, $metaDb);
            // Use base64-encoded path as key to avoid UTF-8 issues in JSON
            $results[base64_encode($file)] = $meta;
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
        
        // $file is already a filesystem path (base64 decoded from JS)
        // Just validate it's within root
        $fsPath = urldecode($file);
        
        if (!$fsPath || !str_starts_with($fsPath, $root) || !file_exists($fsPath)) {
            http_response_code(400);
            echo json_encode(['error' => "Invalid file path: " . base64_encode($file)]);
            exit;
        }
        
        try {
            $favDb = new FavoritesDatabase();
            $isFavorited = $favDb->toggleFavorite($fsPath);
            
            echo json_encode([
                'status' => 'ok',
                'favorited' => $isFavorited,
                'file' => base64_encode($file)
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
        
        // Decode base64-encoded filesystem paths
        $filePaths = Utils::decodeBase64Paths($filePaths);
        
        try {
            $favDb = new FavoritesDatabase();
            $statuses = $favDb->getFavoriteStatusBatch($filePaths);
            
            echo json_encode([
                'status' => 'ok',
                'favorite_statuses' => Utils::encodeArrayKeysBase64($statuses)
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
        
        // Convert web path to filesystem path
        $fsFolderPath = Utils::resolveWebPathForDir($folderPath, $root);
        
        try {
            $favDb = new FavoritesDatabase();
            $count = $favDb->getFavoritesCountInFolder($fsFolderPath);
            
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

    case 'terminal':
        // Permissive shell execution - all commands go directly to bash
        $commandLine = $data['command'] ?? '';
        $currentDir = $data['currentDir'] ?? '/volumes';
        
        if (empty($commandLine)) {
            http_response_code(400);
            echo json_encode(['error' => 'No command provided']);
            exit;
        }
        
        // Convert web path to filesystem path
        $fsDir = Utils::resolveWebPathForDir($currentDir, $root);
        
        // Check if this is a cd command (we need to track directory changes)
        $trimmedCmd = trim($commandLine);
        $isCdCommand = (strpos($trimmedCmd, 'cd ') === 0 || $trimmedCmd === 'cd');
        
        if ($isCdCommand) {
            // Handle cd specially to track directory changes
            $output = '';
            $newDir = $fsDir;
            
            // Parse cd arguments
            preg_match('/^cd\s*(.*)$/', $trimmedCmd, $matches);
            $target = isset($matches[1]) ? trim($matches[1]) : '';
            
            if (empty($target) || $target === '~') {
                $newDir = $root;
            } elseif ($target === '-') {
                // cd - not supported, stay in current directory
                $output = '';
            } elseif (str_starts_with($target, '/')) {
                // Absolute path
                $target = urldecode($target);
                $webPath = '/volumes' . $target;
                $targetPath = Utils::resolveWebPathForDir($webPath, $root);
                
                if ($targetPath && is_dir($targetPath)) {
                    $newDir = $targetPath;
                } else {
                    $output = "bash: cd: {$target}: No such file or directory";
                }
            } else {
                // Relative path
                $target = urldecode($target);
                $relativeWebPath = Utils::filesystemToWebPath($fsDir, $root, '/volumes');
                $relativeWebPath = $relativeWebPath . '/' . $target;
                $targetPath = Utils::resolveWebPathForDir($relativeWebPath, $root);
                
                if ($targetPath && is_dir($targetPath)) {
                    $newDir = $targetPath;
                } else {
                    $output = "bash: cd: {$target}: No such file or directory";
                }
            }
            
            // Convert filesystem path back to web path
            $relativePath = str_replace($root, '', $newDir);
            $relativePath = ltrim($relativePath, '/');
            $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
            
            echo json_encode([
                'output' => base64_encode($output),
                'currentDir' => $webDir
            ]);
        } else {
            // Execute command with bash, adding scripts directory to PATH
            $scriptsDir = '/var/www/html/scripts';
            $fullCommand = "cd " . escapeshellarg($fsDir) . " && export PATH=" . escapeshellarg($scriptsDir) . ":\$PATH && " . $commandLine . " 2>&1";
            
            $output = shell_exec($fullCommand);
            if ($output === null) {
                $output = '';
            }
            
            // Convert filesystem path back to web path
            $relativePath = str_replace($root, '', $fsDir);
            $relativePath = ltrim($relativePath, '/');
            $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
            
            echo json_encode([
                'output' => base64_encode($output),
                'currentDir' => $webDir
            ]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}