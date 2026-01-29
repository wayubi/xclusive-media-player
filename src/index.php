<?php

require_once __DIR__ . '/post-handler.php';

// ================================
// CONFIG & PATH HANDLING
// ================================
$root_directory = './volumes';
$root_directory_absolute = realpath($root_directory) ?: die('Root directory not found');

$is_mobile = stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Mobile') !== false
          || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Android') !== false;

// ================================
// NAVIGATION HANDLING
// ================================

// 1. Get incoming path segments (used only if no goto)
$path_segments = array_filter(
    (array)($_GET['path'] ?? []),
    fn($v) => is_string($v) && strlen(trim($v)) > 0 && $v !== '..'
);

// If no navigation action → use incoming path[] as-is
$selected_path_parts_final = $path_segments;
$selected_path = implode('/', $selected_path_parts_final);

// 2. Compute current absolute path from incoming segments (base for goto)
$current_abs_path = $root_directory_absolute;
$segments = explode('/', trim($selected_path, '/'));
foreach ($segments as $seg) {
    $current_abs_path = $current_abs_path . '/' . $seg;
}

// 3. Handle navigation actions (both Go Back and folder selection)
$nav_action = null;
$nav_value = null;

if (isset($_GET['goto']) && $_GET['goto'] === '..') {
    $nav_action = 'up';
} elseif (!empty($_GET['goto_folder'])) {
    $nav_action = 'down';
    $nav_value = $_GET['goto_folder'];
}

// Only process if there's a navigation request
if ($nav_action !== null) {
    // Build current absolute path from incoming path[] segments (safe base)
    $current_abs_path = $root_directory_absolute;
    $segments = explode('/', trim(implode('/', $path_segments), '/'));
    foreach ($segments as $seg) {
        $current_abs_path = $current_abs_path . '/' . $seg;
    }

    // Apply the navigation
    if ($nav_action === 'up') {
        $current_abs_path = dirname($current_abs_path);
        // Safety clamp: never go above root
        if (strpos($current_abs_path, $root_directory_absolute) !== 0) {
            $current_abs_path = $root_directory_absolute;
        }
    } elseif ($nav_action === 'down') {
        $next = $current_abs_path . '/' . $nav_value;
        $current_abs_path = $next;
    }

    // Rebuild clean path segments from the final physical path
    $relative = substr($current_abs_path, strlen($root_directory_absolute));
    $clean_segments = array_filter(
        explode('/', trim(str_replace('\\', '/', $relative), '/')),
        fn($v) => $v !== ''
    );

    // Build clean query string for redirect
    $query = [];
    foreach ($clean_segments as $seg) {
        $query[] = 'path[]=' . urlencode($seg);
    }
    // Preserve other params
    if (isset($_GET['columns'])) $query[] = 'columns=' . (int)$_GET['columns'];
    if (isset($_GET['rows']))    $query[] = 'rows=' . (int)$_GET['rows'];
    if (isset($_GET['muted']))   $query[] = 'muted=' . $_GET['muted'];

    // Cache buster
    $query[] = 't=' . time();

    $redirect = 'index.php' . (empty($query) ? '' : '?' . implode('&', $query));

    // Perform the redirect to clean URL
    header("Location: $redirect");
    exit;
}

$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 3)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || $_GET['muted'] === 'true';

// ================================
// FILESYSTEM HELPERS
// ================================
function getExcludedFolders(): array {
    return ['.metadata', '.trash'];
}

function getSubfolders(string $path): array {
    if (!is_dir($path)) return [];

    $folders = scandir($path);
    $excluded = getExcludedFolders();

    $filtered = array_filter($folders, function($d) use ($path, $excluded) {
        return $d !== '.' && $d !== '..' && !in_array($d, $excluded) && is_dir("$path/$d");
    });

    usort($filtered, 'strcasecmp');
    return array_values($filtered);
}

function getFiles(string $path): array {
    if (!is_dir($path)) return [];
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $excluded = getExcludedFolders();

    foreach ($it as $file) {
        if (!$file->isFile() || $file->getFilename() === '.audited') continue;
        $pathname = $file->getPathname();

        foreach ($excluded as $folder) {
            if (strpos($pathname, "/$folder/") !== false) {
                continue 2; // Skip this file if any excluded folder is in the path
            }
        }

        if (!mb_check_encoding($pathname, 'UTF-8')) {
            $pathname = iconv('UTF-8', 'UTF-8//IGNORE', $pathname);
        }
        if (!file_exists($pathname)) {
            error_log("Missing file: $pathname");
        }
        $files[] = $pathname;
    }

    $mtimes = array_map(function($file) {
        $realPath = htmlspecialchars_decode($file, ENT_QUOTES | ENT_HTML5);
        return @filemtime($realPath) ?: 0;
    }, $files);
    array_multisort($mtimes, SORT_DESC, $files);
    return array_values($files);
}

function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb): string {
    static $rootFsReal = null;
    
    if ($rootFsReal === null) {
        $rootFsReal = realpath($rootFs);
    }

    $fsPath = str_replace('\\', '/', $fsPath);
    $relative = str_starts_with($fsPath, $rootFsReal) 
        ? substr($fsPath, strlen($rootFsReal)) 
        : $fsPath;
    
    $segments = array_map('rawurlencode', explode('/', ltrim($relative, '/')));
    return rtrim($rootWeb, '/') . '/' . implode('/', $segments);
}

function getCurrentPath(string $root, string $selected_path): string {
    static $cachedRoot = null;
    if ($cachedRoot === null) {
        $cachedRoot = realpath($root) ?: $root;
    }
    if (str_contains($selected_path, '..')) {
        return $cachedRoot;
    }
    $fullPath = $cachedRoot . DIRECTORY_SEPARATOR . ltrim($selected_path, '/\\');
    $real = realpath($fullPath);
    return ($real && str_starts_with($real, $cachedRoot)) ? $real : $cachedRoot;
}

function hasUnauditedFiles(string $folderPath, $auditDb): bool {
    if (!is_dir($folderPath)) return false;
    
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folderPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    $excluded = getExcludedFolders();
    
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getFilename() === '.audited') continue;
        $pathname = $file->getPathname();
        
        foreach ($excluded as $folder) {
            if (strpos($pathname, "/$folder/") !== false) {
                continue 2;
            }
        }
        
        $files[] = $pathname;
        
        // Early exit optimization: check every 10 files
        if (count($files) >= 10) {
            $statuses = $auditDb->getAuditStatusBatch($files);
            foreach ($statuses as $status) {
                if (!$status['audited']) {
                    return true; // Found an unaudited file
                }
            }
            $files = []; // Clear the batch
        }
    }
    
    // Check remaining files
    if (!empty($files)) {
        $statuses = $auditDb->getAuditStatusBatch($files);
        foreach ($statuses as $status) {
            if (!$status['audited']) {
                return true;
            }
        }
    }
    
    return false;
}

function renderSingleFolderSelect(array $selected_parts, string $current_abs_path, $auditDb): void {
    $is_root = empty($selected_parts);
    $subfolders = getSubfolders(path: $current_abs_path);
    $has_children = !empty($subfolders);
    
    // Get current folder name and check if it has unaudited files
    $current_has_unaudited = hasUnauditedFiles($current_abs_path, $auditDb);
    $current_icon = $is_root ? '🏠' : ($current_has_unaudited ? '📂' : '📂');
    $current_folder_name = $current_icon . ' ' . ($is_root ? 'Root' : basename($current_abs_path));
    ?>
    <select name="goto_folder" id="folder-select"
            onchange="this.form.submit()"
            autofocus>
        <option value="" disabled selected>
            <?= $current_folder_name ?><?= $has_children ? ' ▾' : '' ?>
        </option>

        <?php foreach ($subfolders as $folder): ?>
            <?php
                $subfolderPath = $current_abs_path . DIRECTORY_SEPARATOR . $folder;
                $subfolder_has_unaudited = hasUnauditedFiles($subfolderPath, $auditDb);
                $subfolder_icon = $subfolder_has_unaudited ? '⚠️' : '✅';
            ?>
            <option value="<?= htmlspecialchars($folder) ?>">
                <?= $subfolder_icon ?> <?= htmlspecialchars($folder) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

// ================================
// MAIN LOGIC
// ================================
$current_path = getCurrentPath($root_directory_absolute, $selected_path);

// NEW: Use SQLite for audit status
require_once __DIR__ . '/lib/AuditDatabase.php';
$auditDb = new AuditDatabase();

// NEW: Use SQLite for favorites
require_once __DIR__ . '/lib/FavoritesDatabase.php';
$favDb = new FavoritesDatabase();

$allFilesRaw = getFiles($current_path);
$webRoot = '/' . trim($root_directory, './');
$allFiles = array_map(fn($f) => filesystemToWebPath($f, $root_directory_absolute, $webRoot), $allFilesRaw);
$allFilesCount = count($allFiles);

// Get audit statuses for all files
$auditStatuses = $auditDb->getAuditStatusBatch($allFilesRaw);

// Count audited vs unaudited
$auditedCount = 0;
$latestAuditDate = '';
foreach ($auditStatuses as $status) {
    if ($status['audited']) {
        $auditedCount++;
        if ($status['audit_date'] > $latestAuditDate) {
            $latestAuditDate = $status['audit_date'];
        }
    }
}
$unAuditedCount = $allFilesCount - $auditedCount;

// Create a map of web path -> audit status for JS
$auditStatusMap = [];
foreach ($allFilesRaw as $i => $fsPath) {
    $webPath = $allFiles[$i];
    $auditStatusMap[$webPath] = $auditStatuses[$fsPath]['audited'] ?? false;
}

// Get favorites statuses for all files
$favoritesStatuses = $favDb->getFavoriteStatusBatch($allFilesRaw);

// Create a map of web path -> favorite status for JS
$favoritesMap = [];
foreach ($allFilesRaw as $i => $fsPath) {
    $webPath = $allFiles[$i];
    $favoritesMap[$webPath] = $favoritesStatuses[$fsPath]['favorited'] ?? false;
}

// Get total favorites count in this folder
$favoritesCount = $favDb->getFavoritesCountInFolder($current_path);

require_once __DIR__ . '/lib/audioCovers.php';
$audioThumbsRaw = generateAudioCovers($allFilesRaw);

$audioThumbs = [];
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
foreach ($audioThumbsRaw as $audioFs => $thumbFs) {
    $audioWeb = filesystemToWebPath($audioFs, $root_directory_absolute, $webRoot);
    $thumbWeb = $docRoot ? '/' . ltrim(str_replace('\\', '/', str_replace($docRoot, '', realpath($thumbFs))), '/') : '';
    $audioThumbs[$audioWeb] = $thumbWeb ?: 'cache/no-cover.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <meta name="theme-color" content="#0a0a0f">
        <title>Xclusive Media Player</title>
        
        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link rel="shortcut icon" href="/favicon.svg">
        
        <link rel="stylesheet" href="/assets/css/app.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    </head>

    <body>

        <form id="options-form" method="get" action="index.php">
            <!-- File counter -->
            <span id="file-count" style="min-width: 100px;">1 / <?= $allFilesCount ?></span>
            
            <?php foreach ($selected_path_parts_final as $part): ?>
                <input type="hidden" name="path[]" value="<?= htmlspecialchars($part) ?>">
            <?php endforeach; ?>
            
            <!-- Navigation controls -->
            <div id="folder-select-container" style="display: flex; align-items: center; gap: 10px;">
                <?php
                // Build home URL with preserved parameters
                $homeParams = [];
                if (isset($_GET['columns'])) $homeParams[] = 'columns=' . (int)$_GET['columns'];
                if (isset($_GET['rows'])) $homeParams[] = 'rows=' . (int)$_GET['rows'];
                if (isset($_GET['muted'])) $homeParams[] = 'muted=' . urlencode($_GET['muted']);
                $homeParams[] = 't=' . time();
                $homeUrl = 'index.php' . (empty($homeParams) ? '' : '?' . implode('&', $homeParams));
                ?>
                <a href="<?= htmlspecialchars($homeUrl) ?>" 
                   style="text-decoration: none;">
                    <button type="button" title="Go to root folder">
                        🏠 Home
                    </button>
                </a>
                
                <?php if (!empty($selected_path_parts_final)): ?>
                    <button type="submit" name="goto" value=".." 
                            onclick="this.form.action = 'index.php?t=' + Date.now();"
                            title="Go back to parent folder">
                        ← Back
                    </button>
                <?php endif; ?>
                <?php renderSingleFolderSelect($selected_path_parts_final, $current_path, $auditDb); ?>
            </div>

            <!-- Grid controls -->
            <select name="columns" onchange="this.form.submit()" title="Columns">
                <?php for ($c=1;$c<=6;$c++): ?>
                    <option value="<?= $c ?>" <?= $c==$selected_columns?'selected':'' ?>><?= $c ?> col</option>
                <?php endfor; ?>
            </select>

            <select name="rows" onchange="this.form.submit()" title="Rows">
                <?php for ($r=1;$r<=6;$r++): ?>
                    <option value="<?= $r ?>" <?= $r==$selected_rows?'selected':'' ?>><?= $r ?> row</option>
                <?php endfor; ?>
            </select>

            <!-- Hidden state -->
            <input type="hidden" name="muted" value="<?= $muted?'true':'false' ?>">
            
            <!-- Action buttons -->
            <button type="button" id="mute-button" onclick="toggleMute()" title="Toggle mute">
                <?= $muted?'🔇':'🔊' ?>
            </button>
            <button type="button" onclick="playAll()" title="Play all">▶️</button>
            <button type="button" onclick="shufflePlay()" title="Shuffle play">🔀</button>
            <button type="button" onclick="playFavorites()" title="Play favorites">❤️</button>
            <button type="button" id="audit" onclick="runAudit()" title="Audit files">📋</button>
            <button type="button" id="previous" onclick="prevGrid()" title="Previous">◀</button>
            <button type="button" id="next" onclick="nextGrid()" title="Next">▶</button>
            
            <!-- Favorites status -->
            <span id="favorites-text" style="
                background: rgba(236, 72, 153, 0.1);
                border: 1px solid rgba(236, 72, 153, 0.2);
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
            ">
                <span id="favorites-count" title="Click to filter favorites">❤️ <?= $favoritesCount ?></span>
            </span>
            
            <!-- Audit status with better styling -->
            <span id="audit-text" style="
                background: rgba(168, 85, 247, 0.1);
                border: 1px solid rgba(168, 85, 247, 0.2);
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
            ">
                <?php if ($latestAuditDate): ?>
                    📅 <?= htmlspecialchars($latestAuditDate) ?> • ✅ <?= $auditedCount ?> • <span id="unaudited-count" title="Click to filter unaudited files">⚠️ <?= $unAuditedCount ?></span>
                <?php else: ?>
                    <span id="unaudited-count" title="Click to filter unaudited files">⚠️ Not audited</span>
                <?php endif; ?>
            </span>
        </form>

        <!-- Main grid -->
        <div id="grid"></div>

        <!-- Search overlay -->
        <div id="search-overlay" style="display:none;">
            <div class="search-container">
                <input type="text" 
                       id="search-input" 
                       placeholder="🔍 Search files and folders... (Press Enter)" 
                       autocomplete="off" 
                       spellcheck="false" />
                <button id="search-clear" title="Clear search">✕</button>
            </div>
        </div>

        <!-- App bootstrap data -->
        <script>
            window.APP = {
                allVideos: <?= json_encode($allFiles, JSON_UNESCAPED_SLASHES) ?>,
                allFilesWithPaths: <?= json_encode($allFilesRaw, JSON_UNESCAPED_SLASHES) ?>,
                audioThumbs: <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>,
                auditStatusMap: <?= json_encode($auditStatusMap, JSON_UNESCAPED_SLASHES) ?>,
                favoritesMap: <?= json_encode($favoritesMap, JSON_UNESCAPED_SLASHES) ?>,
                favoritesCount: <?= $favoritesCount ?>,
                muted: <?= $muted ? 'true' : 'false' ?>,
                totalCells: <?= $total_cells ?>,
                selectedColumns: <?= $selected_columns ?>,
                webRoot: <?= json_encode($webRoot) ?>,
                rootDirAbs: <?= json_encode($root_directory_absolute) ?>,
                currentPath: <?= json_encode($current_path) ?>
            };
        </script>
        <script type="module" src="/assets/js/main.js"></script>

    </body>
</html>