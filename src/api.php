<?php

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
 * Check if MP4/MOV/M4V file has faststart enabled (moov atom before mdat)
 * Returns: true = faststart OK, false = needs faststart, null = error or not MP4
 */
function checkMoovAtomPosition(string $filepath): ?bool
{
    $fh = fopen($filepath, 'rb');
    if (!$fh) return null;
    
    try {
        while (!feof($fh)) {
            $header = fread($fh, 8);
            if (strlen($header) < 8) break;
            
            list(, $size) = unpack('N', substr($header, 0, 4));
            $type = substr($header, 4, 4);
            
            if ($type === 'moov') {
                return true;  // moov before mdat = faststart OK
            }
            if ($type === 'mdat') {
                return false; // mdat before moov = needs faststart
            }
            
            // Skip atom body
            if ($size === 0) break;
            if ($size === 1) {
                // Extended 64-bit size - skip extended size field
                fseek($fh, 8, SEEK_CUR);
            } else {
                // Normal size - skip atom body (size includes 8-byte header)
                fseek($fh, $size - 8, SEEK_CUR);
            }
        }
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
    }
    return null;
}

function getMetadataForFile(string $webPath, string $root, MetadataDatabase $metaDb): ?array
{
    $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $webPath), '/');
    $fsPath = realpath($root . '/' . $cleanFile);

    if (!$fsPath || !str_starts_with($fsPath, $root) || !is_file($fsPath)) {
        return ['file' => basename($webPath), 'folder' => '', 'optimizationStatus' => ['isOptimized' => true, 'issues' => []]];
    }

    // Check database first
    $metadata = $metaDb->getMetadata($webPath, $fsPath);
    if ($metadata !== null) {
        return $metadata;
    }

    // Generate new metadata
    $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
    $textExtensions = ['txt', 'nfo', 'sfv', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini'];

    // Handle text files specially - don't use ffprobe
    if (in_array($ext, $textExtensions)) {
        $output = [
            'file'     => basename($fsPath),
            'folder'   => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'text'     => [
                'encoding' => detectTextEncoding($fsPath),
            ],
            'optimizationStatus' => [
                'isOptimized' => true,
                'issues' => []
            ],
        ];

        $metaDb->saveMetadata($webPath, $fsPath, $output);
        return $output;
    }

    try {
        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
            escapeshellarg($fsPath)
        );

        $raw = shell_exec($cmd);
        if ($raw === null || $raw === '') {
            $output = [
                'file'     => basename($fsPath),
                'folder'   => basename(dirname($fsPath)),
                'filesize' => filesize($fsPath),
                'optimizationStatus' => [
                    'isOptimized' => true,
                    'issues' => []
                ],
            ];
            $metaDb->saveMetadata($webPath, $fsPath, $output);
            return $output;
        }

        $meta = json_decode($raw, true);
        if ($meta === null) {
            $output = [
                'file'     => basename($fsPath),
                'folder'   => basename(dirname($fsPath)),
                'filesize' => filesize($fsPath),
                'optimizationStatus' => [
                    'isOptimized' => true,
                    'issues' => []
                ],
            ];
            $metaDb->saveMetadata($webPath, $fsPath, $output);
            return $output;
        }

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

        // Determine optimization status
        $isOptimized = true;
        $issues = [];

        // Check container format
        $nonStreamingContainers = ['avi', 'flv', 'wmv', 'mkv', 'mpeg', 'mpg'];
        if (in_array($ext, $nonStreamingContainers)) {
            $isOptimized = false;
            $issues[] = 'Non-streaming container: ' . strtoupper($ext);
        }

        // Check video codec
        $nonStreamingCodecs = ['wmv3', 'flv1', 'wmv2', 'mpeg4', 'wmv1', 'mpeg1video'];
        if ($video && in_array($video['codec_name'] ?? '', $nonStreamingCodecs)) {
            $isOptimized = false;
            $issues[] = 'Non-streaming codec: ' . ($video['codec_name'] ?? 'unknown');
        }

        // Check faststart for MP4/MOV/M4V files
        if (in_array($ext, ['mp4', 'mov', 'm4v'])) {
            $hasFaststart = checkMoovAtomPosition($fsPath);
            if ($hasFaststart === false) {
                $isOptimized = false;
                $issues[] = 'Faststart not enabled (moov atom not at start)';
            }
        }

        // Calculate FPS safely
        $fps = null;
        if ($video && isset($video['avg_frame_rate']) && $video['avg_frame_rate'] !== '0/0') {
            $fpsParts = explode('/', $video['avg_frame_rate']);
            if (count($fpsParts) === 2 && $fpsParts[1] != 0) {
                $fps = (float)$fpsParts[0] / (float)$fpsParts[1];
            }
        }

        $output = [
            'file'     => basename($fsPath),
            'folder'   => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'duration' => $meta['format']['duration'] ?? null,
            'bitrate'  => isset($meta['format']['bit_rate']) ? (int)$meta['format']['bit_rate'] : null,
            'container'=> $meta['format']['format_name'] ?? null,
            'video'    => $video ? [
                'codec'   => $video['codec_name'] ?? null,
                'width'   => $video['width'] ?? null,
                'height'  => $video['height'] ?? null,
                'fps'     => $fps,
                'pix_fmt' => $video['pix_fmt'] ?? null,
            ] : null,
            'audio'    => $audio ? [
                'codec'       => $audio['codec_name'] ?? null,
                'channels'    => $audio['channels'] ?? null,
                'sample_rate' => $audio['sample_rate'] ?? null,
            ] : null,
            'optimizationStatus' => [
                'isOptimized' => $isOptimized,
                'issues' => $issues
            ],
        ];

        $metaDb->saveMetadata($webPath, $fsPath, $output);
        return $output;

    } catch (Exception $e) {
        $output = [
            'file'     => basename($fsPath),
            'folder'   => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'optimizationStatus' => [
                'isOptimized' => true,
                'issues' => []
            ],
        ];
        $metaDb->saveMetadata($webPath, $fsPath, $output);
        return $output;
    }
}

/**
 * Detect the encoding of a text file
 * Returns 'utf-8', 'ascii', 'iso-8859-1' (ANSI), or 'binary'
 */
function detectTextEncoding(string $filePath): string
{
    $handle = fopen($filePath, 'rb');
    if (!$handle) {
        return 'binary';
    }

    // Read first 8KB for detection
    $content = fread($handle, 8192);
    fclose($handle);

    // Check for UTF-8 BOM
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        return 'utf-8-bom';
    }

    // Try to detect encoding using mb_check_encoding
    if (mb_check_encoding($content, 'UTF-8')) {
        // Check if it's pure ASCII
        if (preg_match('/^[\x00-\x7F]*$/', $content)) {
            return 'ascii';
        }
        return 'utf-8';
    }

    // Check if it could be Windows-1252 / ISO-8859-1 (ANSI)
    if (mb_check_encoding($content, 'Windows-1252') || mb_check_encoding($content, 'ISO-8859-1')) {
        return 'ansi';
    }

    return 'binary';
}

// Terminal command helper functions
function parseTerminalCommand($commandLine) {
    $args = [];
    $current = '';
    $inQuotes = false;
    $quoteChar = '';
    
    for ($i = 0; $i < strlen($commandLine); $i++) {
        $char = $commandLine[$i];
        
        if (($char === '"' || $char === "'") && !$inQuotes) {
            $inQuotes = true;
            $quoteChar = $char;
        } elseif ($char === $quoteChar && $inQuotes) {
            $inQuotes = false;
            $quoteChar = '';
        } elseif ($char === ' ' && !$inQuotes) {
            if ($current !== '') {
                $args[] = $current;
                $current = '';
            }
        } else {
            $current .= $char;
        }
    }
    
    if ($current !== '') {
        $args[] = $current;
    }
    
    return $args;
}

function resolvePath($path, $currentDir, $root) {
    // Handle ~ as /volumes
    if ($path === '~' || str_starts_with($path, '~/')) {
        $path = '/volumes' . substr($path, 1);
    }
    
    // Handle relative paths
    if (!str_starts_with($path, '/')) {
        $path = $currentDir . '/' . $path;
    }
    
    // Resolve to real path
    $realPath = realpath($path);
    
    // Security check: must be within /volumes
    if (!$realPath || !str_starts_with($realPath, $root)) {
        return null;
    }
    
    return $realPath;
}

function cmdLs($path, $currentDir, $root) {
    $targetPath = resolvePath($path, $currentDir, $root);
    
    if ($targetPath === null) {
        return "ls: cannot access '{$path}': No such file or directory";
    }
    
    if (!is_dir($targetPath)) {
        // If it's a file, just show the file
        $stat = stat($targetPath);
        $perms = fileperms($targetPath);
        $permString = sprintf('%o', $perms & 0777);
        $size = filesize($targetPath);
        $date = date('M d H:i', $stat['mtime']);
        $name = basename($targetPath);
        return "-rw-r--r-- 1 root root " . str_pad($size, 10) . " {$date} {$name}";
    }
    
    $output = [];
    $entries = scandir($targetPath);
    
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        
        $fullPath = $targetPath . '/' . $entry;
        $stat = stat($fullPath);
        $perms = fileperms($fullPath);
        $isDir = is_dir($fullPath);
        $permString = ($isDir ? 'd' : '-') . 'rwxrwxrwx';
        $size = $isDir ? 4096 : filesize($fullPath);
        $date = date('M d H:i', $stat['mtime']);
        
        $output[] = sprintf('%s %s %s %s %s',
            $permString,
            str_pad($stat['nlink'], 2),
            str_pad($size, 10),
            $date,
            $entry . ($isDir ? '/' : '')
        );
    }
    
    return implode("\n", $output);
}

function cmdCd($path, $currentDir, $root) {
    $targetPath = resolvePath($path, $currentDir, $root);
    
    if ($targetPath === null) {
        return [
            'output' => "cd: {$path}: No such file or directory",
            'newDir' => $currentDir
        ];
    }
    
    if (!is_dir($targetPath)) {
        return [
            'output' => "cd: {$path}: Not a directory",
            'newDir' => $currentDir
        ];
    }
    
    return [
        'output' => '',
        'newDir' => $targetPath
    ];
}

function cmdCat($path, $currentDir, $root) {
    $targetPath = resolvePath($path, $currentDir, $root);
    
    if ($targetPath === null) {
        return "cat: {$path}: No such file or directory";
    }
    
    if (is_dir($targetPath)) {
        return "cat: {$path}: Is a directory";
    }
    
    // Safety: only show text files, limit size
    $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
    $textExtensions = ['txt', 'nfo', 'sfv', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini', 'sh', 'php', 'js', 'css', 'html'];
    
    if (!in_array($ext, $textExtensions) && !empty($ext)) {
        return "cat: {$path}: Binary file (use with caution)";
    }
    
    $size = filesize($targetPath);
    if ($size > 1048576) { // 1MB limit
        return "cat: {$path}: File too large (max 1MB)";
    }
    
    $content = file_get_contents($targetPath);
    if ($content === false) {
        return "cat: {$path}: Permission denied";
    }
    
    return $content;
}

function cmdMkdir($path, $currentDir, $root) {
    // Build the full path
    $newPath = str_starts_with($path, '/') ? $path : $currentDir . '/' . $path;
    
    // Resolve to real path to check if it already exists
    $realPath = realpath($newPath);
    
    if ($realPath !== false) {
        // Path exists - check if it's a directory or file
        if (is_dir($realPath)) {
            return "mkdir: cannot create directory '{$path}': File exists";
        } else {
            return "mkdir: cannot create directory '{$path}': File exists";
        }
    }
    
    // Check if parent directory is within root
    $parentDir = dirname($newPath);
    $realParent = realpath($parentDir);
    
    if ($realParent === false || !str_starts_with($realParent, $root)) {
        return "mkdir: cannot create directory '{$path}': Permission denied";
    }
    
    // Create the directory
    if (@mkdir($newPath, 0755, true)) {
        return '';
    } else {
        $error = error_get_last();
        return "mkdir: cannot create directory '{$path}': " . ($error['message'] ?? 'Permission denied');
    }
}

function cmdRm($path, $currentDir, $root) {
    $targetPath = resolvePath($path, $currentDir, $root);
    
    if ($targetPath === null) {
        return "rm: cannot remove '{$path}': No such file or directory";
    }
    
    if (is_dir($targetPath)) {
        return "rm: cannot remove '{$path}': Is a directory";
    }
    
    if (unlink($targetPath)) {
        return '';
    } else {
        return "rm: cannot remove '{$path}': Permission denied";
    }
}

function cmdRmdir($path, $currentDir, $root) {
    $targetPath = resolvePath($path, $currentDir, $root);
    
    if ($targetPath === null) {
        return "rmdir: failed to remove '{$path}': No such file or directory";
    }
    
    if (!is_dir($targetPath)) {
        return "rmdir: failed to remove '{$path}': Not a directory";
    }
    
    $entries = scandir($targetPath);
    $hasEntries = false;
    foreach ($entries as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $hasEntries = true;
            break;
        }
    }
    
    if ($hasEntries) {
        return "rmdir: failed to remove '{$path}': Directory not empty";
    }
    
    if (rmdir($targetPath)) {
        return '';
    } else {
        return "rmdir: failed to remove '{$path}': Permission denied";
    }
}

function cmdCp($src, $dst, $currentDir, $root) {
    $srcPath = resolvePath($src, $currentDir, $root);
    $dstPath = resolvePath($dst, $currentDir, $root);
    
    if ($srcPath === null) {
        return "cp: cannot stat '{$src}': No such file or directory";
    }
    
    if (is_dir($srcPath)) {
        return "cp: -r not specified; omitting directory '{$src}'";
    }
    
    // If dst is a directory, append src filename
    if ($dstPath !== null && is_dir($dstPath)) {
        $dstPath = $dstPath . '/' . basename($srcPath);
    } elseif ($dstPath === null) {
        // Try as new path
        $dstPath = str_starts_with($dst, '/') ? $dst : $currentDir . '/' . $dst;
    }
    
    // Security check on destination
    if (!str_starts_with(realpath(dirname($dstPath)) ?: dirname($dstPath), $root)) {
        return "cp: cannot create regular file '{$dst}': Permission denied";
    }
    
    if (copy($srcPath, $dstPath)) {
        return '';
    } else {
        return "cp: cannot create regular file '{$dst}': Permission denied";
    }
}

function cmdMv($src, $dst, $currentDir, $root) {
    $srcPath = resolvePath($src, $currentDir, $root);
    $dstPath = resolvePath($dst, $currentDir, $root);
    
    if ($srcPath === null) {
        return "mv: cannot stat '{$src}': No such file or directory";
    }
    
    // If dst is a directory, append src filename
    if ($dstPath !== null && is_dir($dstPath)) {
        $dstPath = $dstPath . '/' . basename($srcPath);
    } elseif ($dstPath === null) {
        // Try as new path
        $dstPath = str_starts_with($dst, '/') ? $dst : $currentDir . '/' . $dst;
    }
    
    // Security check on destination
    if (!str_starts_with(realpath(dirname($dstPath)) ?: dirname($dstPath), $root)) {
        return "mv: cannot move '{$src}' to '{$dst}': Permission denied";
    }
    
    if (rename($srcPath, $dstPath)) {
        return '';
    } else {
        return "mv: cannot move '{$src}' to '{$dst}': Permission denied";
    }
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

        $meta = getMetadataForFile($file, $root, $metaDb);

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
            $meta = getMetadataForFile($file, $root, $metaDb);
            $results[$file] = $meta;
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

    case 'terminal':
        // Execute terminal commands
        $commandLine = $data['command'] ?? '';
        $currentDir = $data['currentDir'] ?? '/volumes';
        
        if (empty($commandLine)) {
            http_response_code(400);
            echo json_encode(['error' => 'No command provided']);
            exit;
        }
        
        // Parse the command
        $args = parseTerminalCommand($commandLine);
        $cmd = strtolower($args[0] ?? '');
        
        // Convert web path to filesystem path
        // $currentDir comes in as web path (e.g., "/videos/action" or "/volumes/videos/action")
        // We need to convert it to filesystem path (e.g., "/var/www/volumes/videos/action")
        $cleanDir = ltrim(preg_replace('#^/volumes/?#i', '', $currentDir), '/');
        $fsDir = $root . ($cleanDir ? '/' . $cleanDir : '');
        
        // Validate current directory
        $currentDir = realpath($fsDir) ?: $root;
        if (!str_starts_with($currentDir, $root)) {
            $currentDir = $root;
        }
        
        $output = '';
        $newDir = $currentDir;
        
        switch ($cmd) {
            case 'ls':
                $path = $args[1] ?? '.';
                $output = cmdLs($path, $currentDir, $root);
                break;
                
            case 'cd':
                if (!isset($args[1])) {
                    // cd without arguments goes to home directory (/volumes)
                    $newDir = $root;
                    $output = '';
                } else {
                    $result = cmdCd($args[1], $currentDir, $root);
                    $output = $result['output'];
                    $newDir = $result['newDir'];
                }
                break;
                
            case 'pwd':
                // Show web path instead of filesystem path
                $relativePath = str_replace($root, '', $currentDir);
                $relativePath = ltrim($relativePath, '/');
                $output = '/volumes' . ($relativePath ? '/' . $relativePath : '');
                break;
                
            case 'cat':
                if (!isset($args[1])) {
                    $output = 'cat: missing operand';
                } else {
                    $output = cmdCat($args[1], $currentDir, $root);
                }
                break;
                
            case 'mkdir':
                if (!isset($args[1])) {
                    $output = 'mkdir: missing operand';
                } else {
                    $output = cmdMkdir($args[1], $currentDir, $root);
                }
                break;
                
            case 'rm':
                // Check delete authorization
                $deleteEnabled = isset($_COOKIE['delete_enabled']) && $_COOKIE['delete_enabled'] === '1';
                if (!$deleteEnabled) {
                    $output = 'rm: Permission denied. Delete functionality is disabled.';
                } elseif (!isset($args[1])) {
                    $output = 'rm: missing operand';
                } else {
                    $output = cmdRm($args[1], $currentDir, $root);
                }
                break;
                
            case 'rmdir':
                // Check delete authorization
                $deleteEnabled = isset($_COOKIE['delete_enabled']) && $_COOKIE['delete_enabled'] === '1';
                if (!$deleteEnabled) {
                    $output = 'rmdir: Permission denied. Delete functionality is disabled.';
                } elseif (!isset($args[1])) {
                    $output = 'rmdir: missing operand';
                } else {
                    $output = cmdRmdir($args[1], $currentDir, $root);
                }
                break;
                
            case 'cp':
                if (!isset($args[1]) || !isset($args[2])) {
                    $output = 'cp: missing operand';
                } else {
                    $output = cmdCp($args[1], $args[2], $currentDir, $root);
                }
                break;
                
            case 'mv':
                if (!isset($args[1]) || !isset($args[2])) {
                    $output = 'mv: missing operand';
                } else {
                    $output = cmdMv($args[1], $args[2], $currentDir, $root);
                }
                break;
                
            default:
                $output = "Command not found: {$cmd}";
        }
        
        // Convert filesystem path back to web path for the response
        // $newDir is a filesystem path like /var/www/html/videos/action
        // We need to convert it to web path like /videos/action
        $relativePath = str_replace($root, '', $newDir);
        $relativePath = ltrim($relativePath, '/');
        $webDir = '/volumes' . ($relativePath ? '/' . $relativePath : '');
        
        echo json_encode([
            'output' => $output,
            'currentDir' => $webDir
        ]);
        break;

    case 'list_scripts':
        // Scan scripts directory and return available script names (without extensions)
        $scriptsDir = __DIR__ . '/scripts';
        $scripts = [];
        
        if (is_dir($scriptsDir)) {
            $files = scandir($scriptsDir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                // Extract name without extension
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (!empty($name)) {
                    $scripts[] = $name;
                }
            }
        }
        
        echo json_encode([
            'status' => 'ok',
            'scripts' => $scripts
        ]);
        break;

    case 'run_script':
        // Execute a script with real-time streaming output
        $scriptName = $data['script'] ?? '';
        $scriptArgs = $data['args'] ?? [];
        $currentDir = $data['currentDir'] ?? '/volumes';
        
        if (empty($scriptName)) {
            http_response_code(400);
            echo json_encode(['error' => 'No script name provided']);
            exit;
        }
        
        // Find the script file (check various extensions)
        $scriptsDir = __DIR__ . '/scripts';
        $scriptFile = null;
        $extensions = ['sh', 'py', 'pl', 'rb', 'bash'];
        
        foreach ($extensions as $ext) {
            $candidate = $scriptsDir . '/' . $scriptName . '.' . $ext;
            if (file_exists($candidate)) {
                $scriptFile = $candidate;
                break;
            }
        }
        
        // Also check for exact match (no extension)
        if ($scriptFile === null) {
            $candidate = $scriptsDir . '/' . $scriptName;
            if (file_exists($candidate)) {
                $scriptFile = $candidate;
            }
        }
        
        if ($scriptFile === null) {
            http_response_code(404);
            echo json_encode(['error' => "Script not found: {$scriptName}"]);
            exit;
        }
        
        // Convert web path to filesystem path for working directory
        $cleanDir = ltrim(preg_replace('#^/volumes/?#i', '', $currentDir), '/');
        $workingDir = $root . ($cleanDir ? '/' . $cleanDir : '');
        $workingDir = realpath($workingDir) ?: $root;
        
        // Security check
        if (!str_starts_with($workingDir, $root)) {
            $workingDir = $root;
        }
        
        // Build command
        $escapedScript = escapeshellarg($scriptFile);
        $escapedArgs = [];
        foreach ($scriptArgs as $arg) {
            $escapedArgs[] = escapeshellarg($arg);
        }
        $argString = implode(' ', $escapedArgs);
        
        // Determine interpreter based on extension
        $ext = pathinfo($scriptFile, PATHINFO_EXTENSION);
        switch ($ext) {
            case 'py':
                $interpreter = 'python3';
                break;
            case 'pl':
                $interpreter = 'perl';
                break;
            case 'rb':
                $interpreter = 'ruby';
                break;
            default:
                $interpreter = 'bash';
        }
        
        $command = "cd " . escapeshellarg($workingDir) . " && {$interpreter} {$escapedScript}";
        if (!empty($argString)) {
            $command .= " {$argString}";
        }
        
        // Set headers for streaming
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        
        // Execute with streaming output
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            echo "Error: Failed to start script\n";
            exit;
        }
        
        // Close stdin
        fclose($pipes[0]);
        
        // Stream output
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $exitCode = null;
        while (true) {
            $status = proc_get_status($process);
            
            // Read stdout
            $stdout = fread($pipes[1], 8192);
            if ($stdout !== false && $stdout !== '') {
                echo $stdout;
                flush();
            }
            
            // Read stderr
            $stderr = fread($pipes[2], 8192);
            if ($stderr !== false && $stderr !== '') {
                echo $stderr;
                flush();
            }
            
            // Check if process has finished
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(10000); // 10ms
        }
        
        // Get any remaining output
        while (!feof($pipes[1])) {
            $stdout = fread($pipes[1], 8192);
            if ($stdout !== false && $stdout !== '') {
                echo $stdout;
                flush();
            }
        }
        while (!feof($pipes[2])) {
            $stderr = fread($pipes[2], 8192);
            if ($stderr !== false && $stderr !== '') {
                echo $stderr;
                flush();
            }
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        
        // Exit with script's exit code
        exit($exitCode);

    case 'run_ffmpeg':
        // Execute ffmpeg or ffprobe with real-time streaming output
        $cmd = $data['command'] ?? '';  // 'ffmpeg' or 'ffprobe'
        $args = $data['args'] ?? [];
        $fullCommand = $data['fullCommand'] ?? '';
        $currentDir = $data['currentDir'] ?? '/volumes';
        
        if (empty($cmd) || !in_array($cmd, ['ffmpeg', 'ffprobe'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid command. Only ffmpeg and ffprobe are supported.']);
            exit;
        }
        
        // Convert web path to filesystem path for working directory
        $cleanDir = ltrim(preg_replace('#^/volumes/?#i', '', $currentDir), '/');
        $workingDir = $root . ($cleanDir ? '/' . $cleanDir : '');
        $workingDir = realpath($workingDir) ?: $root;
        
        // Security check
        if (!str_starts_with($workingDir, $root)) {
            $workingDir = $root;
        }
        
        // Build the full command
        $escapedArgs = [];
        foreach ($args as $arg) {
            $escapedArgs[] = escapeshellarg($arg);
        }
        $argString = implode(' ', $escapedArgs);
        
        $command = "cd " . escapeshellarg($workingDir) . " && /usr/bin/{$cmd}";
        if (!empty($argString)) {
            $command .= " {$argString}";
        }
        
        // Set headers for streaming
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        
        // Execute with streaming output
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            echo "Error: Failed to start {$cmd}\n";
            exit;
        }
        
        // Close stdin
        fclose($pipes[0]);
        
        // Stream output
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $exitCode = null;
        while (true) {
            $status = proc_get_status($process);
            
            // Read stdout
            $stdout = fread($pipes[1], 8192);
            if ($stdout !== false && $stdout !== '') {
                echo $stdout;
                flush();
            }
            
            // Read stderr
            $stderr = fread($pipes[2], 8192);
            if ($stderr !== false && $stderr !== '') {
                echo $stderr;
                flush();
            }
            
            // Check if process has finished
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(10000); // 10ms
        }
        
        // Get any remaining output
        while (!feof($pipes[1])) {
            $stdout = fread($pipes[1], 8192);
            if ($stdout !== false && $stdout !== '') {
                echo $stdout;
                flush();
            }
        }
        while (!feof($pipes[2])) {
            $stderr = fread($pipes[2], 8192);
            if ($stderr !== false && $stderr !== '') {
                echo $stderr;
                flush();
            }
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        
        // Exit with command's exit code
        exit($exitCode);

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}