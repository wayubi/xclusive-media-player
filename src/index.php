<?php

require_once __DIR__ . '/post-handler.php';

// ================================
// DELETE PROTECTION
// ================================
// Load .env file
$envFile = __DIR__ . '/../.env';
$deleteSecretCode = null;

if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/DELETE_SECRET_CODE\s*=\s*(.+)/', $envContent, $matches)) {
        $deleteSecretCode = trim($matches[1]);
    }
}

// Check for delete parameter in URL
if (isset($_GET['delete'])) {
    if ($_GET['delete'] === 'off') {
        // Disable deletes - clear cookie
        setcookie('delete_enabled', '', time() - 3600, '/');
        $_COOKIE['delete_enabled'] = '';
        
        // Redirect to clean URL
        $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: $cleanUrl");
        exit;
    } elseif ($deleteSecretCode && $_GET['delete'] === $deleteSecretCode) {
        // Enable deletes - set cookie (valid for 30 days)
        setcookie('delete_enabled', '1', time() + (30 * 24 * 60 * 60), '/');
        $_COOKIE['delete_enabled'] = '1';
        
        // Redirect to clean URL
        $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
        header("Location: $cleanUrl");
        exit;
    }
}

// Check if deletes are enabled
$deleteEnabled = isset($_COOKIE['delete_enabled']) && $_COOKIE['delete_enabled'] === '1';

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

$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 5)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || $_GET['muted'] === 'true';

// ================================
// FILESYSTEM HELPERS (using Utils)
// ================================

function renderSingleFolderSelect(array $selected_parts, string $current_abs_path, $auditDb, $metaDb, array $folderStats = [], array $folderAuditStats = [], array $subfolders = []): void {
    $is_root = empty($selected_parts);
    $has_children = !empty($subfolders);
    
    // Get file count from pre-calculated stats
    $current_file_count = $folderStats[$current_abs_path]['total'] ?? 0;
    
    $current_icon = $is_root ? '🏠' : '📂';
    $current_folder_name = $current_icon . ' ' . ($is_root ? 'Root' : basename($current_abs_path)) . ' (' . $current_file_count . ')';
    ?>
    <select name="goto_folder" id="folder-select" class="folder-select-mobile"
            onchange="this.form.submit()"
            autofocus>
        <option value="" disabled selected>
            <?= $current_folder_name ?><?= $has_children ? ' ▾' : '' ?>
        </option>

            <?php foreach ($subfolders as $folder): ?>
                <?php
                $subfolderPath = $current_abs_path . DIRECTORY_SEPARATOR . $folder;
                
                $stats = $folderStats[$subfolderPath] ?? ['total' => 0, 'optimized' => 0, 'unoptimized' => 0];
                $auditStats = $folderAuditStats[$subfolderPath] ?? ['status' => 'none_audited', 'total' => 0, 'audited' => 0];
                $subfolder_file_count = $stats['total'];
                $subfolder_status = $auditStats['status'] ?? 'none_audited';
                
                switch ($subfolder_status) {
                    case 'all_audited':
                        if ($stats['unoptimized'] > 0) {
                            $subfolder_icon = '🔧';
                        } else {
                            $subfolder_icon = '✅';
                        }
                        break;
                    case 'some_audited':
                        $subfolder_icon = '⚠️';
                        break;
                    case 'none_audited':
                    default:
                        if ($subfolder_file_count === 0) {
                            $subfolder_icon = '📁';
                        } else {
                            $subfolder_icon = '🆕';
                        }
                        break;
                }
            ?>
            <option value="<?= htmlspecialchars($folder) ?>">
                <?= $subfolder_icon ?> <?= htmlspecialchars($folder) ?> (<?= $subfolder_file_count ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

// ================================
// MAIN LOGIC
// ================================
require_once __DIR__ . '/lib/Utils.php';
require_once __DIR__ . '/lib/AuditDatabase.php';
require_once __DIR__ . '/lib/FavoritesDatabase.php';
require_once __DIR__ . '/lib/MetadataDatabase.php';

$current_path = Utils::getCurrentPath($root_directory_absolute, $selected_path);

$auditDb = new AuditDatabase();
$favDb = new FavoritesDatabase();
$metaDb = new MetadataDatabase();

// Get files from database (DB is source of truth)
$allFilesRaw = $metaDb->getFilesByFolder($current_path);
$webRoot = '/' . trim($root_directory, './');
$allFiles = array_map(fn($f) => Utils::filesystemToWebPath($f, $root_directory_absolute, $webRoot), $allFilesRaw);
$allFilesCount = count($allFiles);

// Batch fetch audit and favorites statuses (lightweight)
// Get batch statuses for all filters (same pattern as favorites/audit)
$auditStatuses = $auditDb->getAuditStatusBatch($allFilesRaw);
$favoritesStatuses = $favDb->getFavoriteStatusBatch($allFilesRaw);
$optimizationStatuses = $metaDb->getOptimizationStatusBatch($allFilesRaw);

// Get optimization stats using SQL COUNT (lightweight)
$optimizationStats = $metaDb->getOptimizationStats($current_path);

// Build maps for JavaScript - single pass
$auditStatusMap = [];
$auditCount = 0;
$latestAuditDate = '';

foreach ($allFilesRaw as $i => $fsPath) {
    $webPath = $allFiles[$i];
    
    // Audit status
    $auditStatusMap[$webPath] = $auditStatuses[$fsPath]['audited'] ?? false;
    if (!empty($auditStatuses[$fsPath]['audited'])) {
        $auditCount++;
        if (($auditStatuses[$fsPath]['audit_date'] ?? '') > $latestAuditDate) {
            $latestAuditDate = $auditStatuses[$fsPath]['audit_date'];
        }
    }
}

$unAuditedCount = $allFilesCount - $auditCount;
$optimizedCount = $optimizationStats['optimized'];
$unoptimizedCount = $optimizationStats['unoptimized'];

// Build optimization status map (same pattern as favorites/audit)
$optimizationStatusMap = [];
foreach ($allFilesRaw as $i => $fsPath) {
    $webPath = $allFiles[$i];
    $status = $optimizationStatuses[$fsPath] ?? null;
    if ($status !== null) {
        $optimizationStatusMap[$webPath] = $status;
    }
}

// Favorites map
$favoritesMap = [];
$favoritesCount = 0;
foreach ($allFilesRaw as $i => $fsPath) {
    $webPath = $allFiles[$i];
    $isFav = $favoritesStatuses[$fsPath]['favorited'] ?? false;
    $favoritesMap[$webPath] = $isFav;
    if ($isFav) $favoritesCount++;
}

// Get subfolders from database
$subfolders = $metaDb->getSubfoldersByFolder($current_path);

// Get stats for current folder + subfolders
$allFolderPaths = array_merge([$current_path], array_map(fn($f) => $current_path . DIRECTORY_SEPARATOR . $f, $subfolders));
$folderStats = $metaDb->getFolderStatsBatch($allFolderPaths);

// Pass total counts from metadata.db to audit query
$totalCounts = [];
foreach ($allFolderPaths as $path) {
    $totalCounts[$path] = $folderStats[$path]['total'] ?? 0;
}
$folderAuditStats = $auditDb->getFolderStatsBatch($allFolderPaths, $totalCounts);

require_once __DIR__ . '/lib/audioCovers.php';
$audioThumbsRaw = generateAudioCovers($allFilesRaw);

$audioThumbs = [];
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
foreach ($audioThumbsRaw as $audioFs => $thumbFs) {
    $audioWeb = Utils::filesystemToWebPath($audioFs, $root_directory_absolute, $webRoot);
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
        
        <link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
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
                        🏠
                    </button>
                </a>
                
                <?php if (!empty($selected_path_parts_final)): ?>
                    <button type="submit" name="goto" value=".." 
                            onclick="this.form.action = 'index.php?t=' + Date.now();"
                            title="Go back to parent folder">
                        ←
                    </button>
                <?php endif; ?>
                <?php renderSingleFolderSelect($selected_path_parts_final, $current_path, $auditDb, $metaDb, $folderStats, $folderAuditStats, $subfolders); ?>
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
                    📅 <?= htmlspecialchars($latestAuditDate) ?> • ✅ <?= $auditCount ?> • <span id="unaudited-count" title="Click to filter unaudited files">⚠️ <?= $unAuditedCount ?></span>
                <?php else: ?>
                    <span id="unaudited-count" title="Click to filter unaudited files">⚠️ Not audited</span>
                <?php endif; ?>
            </span>
            
            <!-- Optimization status -->
            <span id="optimization-text" style="
                background: rgba(107, 114, 128, 0.1);
                border: 1px solid rgba(107, 114, 128, 0.2);
                padding: 6px 12px;
                border-radius: 12px;
                font-size: 0.8rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
                cursor: pointer;
            " data-initial-unoptimized="<?= $unoptimizedCount ?>" data-initial-optimized="<?= $optimizedCount ?>">
                <span id="optimization-status-text" title="Checking optimization status...">⏳ Scanning...</span>
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
                allFilesWithPaths: <?= json_encode(array_map('base64_encode', $allFilesRaw), JSON_UNESCAPED_SLASHES) ?>,
                audioThumbs: <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>,
                auditStatusMap: <?= json_encode($auditStatusMap, JSON_UNESCAPED_SLASHES) ?>,
                favoritesMap: <?= json_encode($favoritesMap, JSON_UNESCAPED_SLASHES) ?>,
                favoritesCount: <?= $favoritesCount ?>,
                optimizationStatusMap: <?= json_encode($optimizationStatusMap, JSON_UNESCAPED_SLASHES) ?>,
                muted: <?= $muted ? 'true' : 'false' ?>,
                totalCells: <?= $total_cells ?>,
                selectedColumns: <?= $selected_columns ?>,
                webRoot: <?= json_encode($webRoot) ?>,
                rootDirAbs: <?= json_encode($root_directory_absolute) ?>,
                currentPath: <?= json_encode(str_replace($root_directory_absolute, '', $current_path)) ?>,
                deleteEnabled: <?= $deleteEnabled ? 'true' : 'false' ?>
            };
        </script>
        <script type="module" src="/assets/js/main.js"></script>

    </body>
</html>