<?php

require_once __DIR__ . '/lib/UserDatabase.php';
require_once __DIR__ . '/lib/session.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$permissions = UserDatabase::getRolePermissions($_SESSION['user_role'] ?? '');

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
    $nav_value = $_GET['goto_folder'];
    $nav_action = str_contains($nav_value, '/') ? 'jump' : 'down';
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
    } elseif ($nav_action === 'jump') {
        $jump = $root_directory_absolute . '/' . ltrim($nav_value, '/');
        $resolved = realpath($jump);
        $current_abs_path = $resolved !== false && str_starts_with($resolved, $root_directory_absolute)
            ? $resolved : $root_directory_absolute;
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
    if (isset($_GET['sort']))    $query[] = 'sort=' . urlencode($_GET['sort']);

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

// Parse sort parameter (format: field_direction, e.g., "modified_desc", "name_asc")
$sort_param = $_GET['sort'] ?? 'modified_desc';
$sort_parts = explode('_', $sort_param, 2);
$sort_field = $sort_parts[0] ?? 'modified';
$sort_direction = $sort_parts[1] ?? 'desc';

// Validate sort values
$valid_sort_fields = ['modified', 'name', 'duration', 'filesize', 'resolution', 'bitrate', 'fps'];
$valid_sort_directions = ['asc', 'desc'];

if (!in_array($sort_field, $valid_sort_fields)) {
    $sort_field = 'modified';
}
if (!in_array($sort_direction, $valid_sort_directions)) {
    $sort_direction = 'desc';
}

// ================================
// FILESYSTEM HELPERS (using Utils)
// ================================

function renderSingleFolderSelect(array $selected_parts, string $current_abs_path, $auditDb, $metaDb, array $folderStats = [], array $folderAuditStats = [], array $subfolders = [], array $permissions = []): void {
    $is_root = empty($selected_parts);
    $has_children = !empty($subfolders);
    
    $current_file_count = $folderStats[$current_abs_path]['total'] ?? 0;
    $current_folder_name = ($is_root ? '🏠 Home' : '📂 ' . basename($current_abs_path)) . ' (' . $current_file_count . ')';
    
    // Build parent hierarchy
    $parents = [];
    $accumulated = '';
    $parts_count = count($selected_parts);
    foreach ($selected_parts as $i => $part) {
        if ($i === $parts_count - 1) break;
        $accumulated = ($accumulated ? $accumulated . '/' : '') . $part;
        $parents[] = [
            'path' => $i === 0 ? '/' : $accumulated,
            'label' => $i === 0 ? '🏠 Home' : '📂 ' . $part,
        ];
    }
    ?>
    <select name="goto_folder" id="folder-select" class="folder-select-mobile"
            onchange="this.form.submit()">
        <option value="" disabled selected>
            <?= $current_folder_name ?><?= $has_children || !$is_root ? ' ▾' : '' ?>
        </option>

        <?php if (!$is_root): ?>
            <?php foreach ($parents as $p): ?>
                <option value="<?= htmlspecialchars($p['path']) ?>">
                    <?= $p['label'] ?>
                </option>
            <?php endforeach; ?>
            <?php if ($has_children): ?>
            <option disabled>──────────</option>
            <?php endif; ?>
        <?php endif; ?>

        <?php foreach ($subfolders as $folder): ?>
            <?php
            $subfolderPath = $current_abs_path . DIRECTORY_SEPARATOR . $folder;
            
            $stats = $folderStats[$subfolderPath] ?? ['total' => 0, 'optimized' => 0, 'unoptimized' => 0];
            $auditStats = $folderAuditStats[$subfolderPath] ?? ['status' => 'none_audited', 'total' => 0, 'audited' => 0];
            $subfolder_file_count = $stats['total'];
            $subfolder_status = $auditStats['status'] ?? 'none_audited';
            
            if (in_array('audit', $permissions)) {
                switch ($subfolder_status) {
                    case 'all_audited':
                        $subfolder_icon = in_array('optimize', $permissions) && $stats['unoptimized'] > 0 ? '🔧' : '✅';
                        break;
                    case 'some_audited':
                        $subfolder_icon = '⚠️';
                        break;
                    case 'none_audited':
                    default:
                        $subfolder_icon = $subfolder_file_count === 0 ? '📁' : '🆕';
                        break;
                }
            } else {
                $subfolder_icon = $subfolder_file_count === 0 ? '📁' : '📂';
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
$favDb = null;
if (in_array('favorites', $permissions)) {
    $favDb = new FavoritesDatabase();
}
$metaDb = new MetadataDatabase();

// Get files from folder cache (pre-computed by refresh_metadata)
$allFilesRaw = $metaDb->getFilesFromFolder($current_path, $sort_field, $sort_direction);
$webRoot = '/' . trim($root_directory, './');
$allFiles = array_map(fn($f) => Utils::filesystemToWebPath($f, $root_directory_absolute, $webRoot), $allFilesRaw);
$allFilesCount = count($allFiles);

// Batch fetch audit, favorites, and optimization statuses by folder (single JOIN query each)
$auditStatuses = $auditDb->getAuditStatusBatchByFolder($current_path);
$favoritesStatuses = $favDb ? $favDb->getFavoritesStatusBatchByFolder($current_path, (int)($_SESSION['user_id'] ?? 0)) : [];
$optimizationStatuses = $metaDb->getOptimizationStatusBatchByFolder($current_path);

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
if ($favDb) {
    foreach ($allFilesRaw as $i => $fsPath) {
        $webPath = $allFiles[$i];
        $isFav = $favoritesStatuses[$fsPath]['favorited'] ?? false;
        $favoritesMap[$webPath] = $isFav;
        if ($isFav) $favoritesCount++;
    }
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

// Read pre-computed audio covers from refresh_metadata cache
$audioCoversFile = __DIR__ . '/cache/audio-covers-map.json';
$audioThumbs = (file_exists($audioCoversFile)) ? (json_decode(file_get_contents($audioCoversFile), true) ?? []) : [];
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
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    </head>

    <body>

        <form id="options-form" method="get" action="index.php"
              class="<?= !in_array('audit', $permissions) ? 'no-audit' : '' ?>">
            <!-- File counter -->
            <!-- <span id="file-count" style="min-width: 100px;">1 / <?= $allFilesCount ?></span> -->
            
            <?php foreach ($selected_path_parts_final as $part): ?>
                <input type="hidden" name="path[]" value="<?= htmlspecialchars($part) ?>">
            <?php endforeach; ?>
            
            <!-- Navigation controls -->
            <div id="folder-select-container">
                <?php
                // Build home URL - NO sort parameter (reset to default on home)
                $homeParams = [];
                if (isset($_GET['columns'])) $homeParams[] = 'columns=' . (int)$_GET['columns'];
                if (isset($_GET['rows'])) $homeParams[] = 'rows=' . (int)$_GET['rows'];
                if (isset($_GET['muted'])) $homeParams[] = 'muted=' . urlencode($_GET['muted']);
                $homeParams[] = 't=' . time();
                $homeUrl = 'index.php' . (empty($homeParams) ? '' : '?' . implode('&', $homeParams));
                ?>
                <a href="<?= htmlspecialchars($homeUrl) ?>" 
                   style="text-decoration: none;" class="home-btn">
                    <button type="button" title="Go to root folder">
                        🏠
                    </button>
                </a>
                
                <?php if (!empty($selected_path_parts_final)): ?>
                    <!-- <button type="submit" name="goto" value=".." 
                            onclick="this.form.action = 'index.php?t=' + Date.now();"
                            title="Go back to parent folder">
                        ←
                    </button> -->
                <?php endif; ?>
                <?php renderSingleFolderSelect($selected_path_parts_final, $current_path, $auditDb, $metaDb, $folderStats, $folderAuditStats, $subfolders, $permissions); ?>
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

            <!-- Sort control -->
            <select name="sort" onchange="this.form.submit()" title="Sort order">
                <option value="modified_desc" <?= $sort_field === 'modified' && $sort_direction === 'desc' ? 'selected' : '' ?>>🕐 Newest</option>
                <option value="modified_asc" <?= $sort_field === 'modified' && $sort_direction === 'asc' ? 'selected' : '' ?>>🕑 Oldest</option>
                <option value="name_asc" <?= $sort_field === 'name' && $sort_direction === 'asc' ? 'selected' : '' ?>>📛 A-Z</option>
                <option value="name_desc" <?= $sort_field === 'name' && $sort_direction === 'desc' ? 'selected' : '' ?>>📛 Z-A</option>
                <option value="duration_asc" <?= $sort_field === 'duration' && $sort_direction === 'asc' ? 'selected' : '' ?>>⏱️ Shortest</option>
                <option value="duration_desc" <?= $sort_field === 'duration' && $sort_direction === 'desc' ? 'selected' : '' ?>>⏱️ Longest</option>
                <option value="filesize_asc" <?= $sort_field === 'filesize' && $sort_direction === 'asc' ? 'selected' : '' ?>>📦 Smallest</option>
                <option value="filesize_desc" <?= $sort_field === 'filesize' && $sort_direction === 'desc' ? 'selected' : '' ?>>📦 Largest</option>
                <option value="resolution_asc" <?= $sort_field === 'resolution' && $sort_direction === 'asc' ? 'selected' : '' ?>>📐 Lowest Res</option>
                <option value="resolution_desc" <?= $sort_field === 'resolution' && $sort_direction === 'desc' ? 'selected' : '' ?>>📐 Highest Res</option>
                <option value="bitrate_asc" <?= $sort_field === 'bitrate' && $sort_direction === 'asc' ? 'selected' : '' ?>>📶 Lowest Bitrate</option>
                <option value="bitrate_desc" <?= $sort_field === 'bitrate' && $sort_direction === 'desc' ? 'selected' : '' ?>>📶 Highest Bitrate</option>
                <option value="fps_asc" <?= $sort_field === 'fps' && $sort_direction === 'asc' ? 'selected' : '' ?>>🎬 Lowest FPS</option>
                <option value="fps_desc" <?= $sort_field === 'fps' && $sort_direction === 'desc' ? 'selected' : '' ?>>🎬 Highest FPS</option>
            </select>

            <!-- Hidden state -->
            <input type="hidden" name="muted" value="<?= $muted?'true':'false' ?>">
            
            <!-- Action buttons -->
            <button type="button" id="mute-button" onclick="toggleMute()" title="Toggle mute">
                <?= $muted?'🔇':'🔊' ?>
            </button>
            <?php if ($is_mobile && in_array('audit', $permissions)): ?>
            <span class="mobile-group" style="display:flex;flex:0 0 100%;align-items:center;gap:6px;padding-top:6px;margin-left:-3px">
            <?php endif; ?>
            <span style="background:rgba(148,163,184,0.1);border:1px solid rgba(148,163,184,0.2);padding:6px 10px;border-radius:14px;font-size:0.85rem;font-weight:600;color:var(--text-secondary);white-space:nowrap;cursor:default;height:28px;display:inline-flex;align-items:center">
                <span onclick="playAll()" title="Play all" style="cursor:pointer">▶️</span>
                <span onclick="shufflePlay()" title="Shuffle" style="cursor:pointer;margin-left:8px">🔀</span>
                <?php if (in_array('favorites', $permissions)): ?>
                <span onclick="playFavorites()" title="Play favorites" style="cursor:pointer;margin-left:8px">❤️</span>
                <?php endif; ?>
            </span>
            
            <?php if (in_array('favorites', $permissions)): ?>
            <span id="favorites-text" style="
                background: rgba(236, 72, 153, 0.1);
                border: 1px solid rgba(236, 72, 153, 0.2);
                padding: 6px 10px;
                border-radius: 14px;
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
                height: 28px;
                display: inline-flex;
                align-items: center;
            ">
                <span id="favorites-count" title="Click to filter favorites">❤️ <?= $favoritesCount ?></span>
            </span>
            <?php endif; ?>
            
            <?php if (in_array('audit', $permissions)): ?>
            <span id="audit-text" style="
                background: rgba(168, 85, 247, 0.1);
                border: 1px solid rgba(168, 85, 247, 0.2);
                padding: 6px 10px;
                border-radius: 14px;
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
                height: 28px;
                display: inline-flex;
                align-items: center;
            ">
                <?php if ($latestAuditDate): ?>
                    📅 <?= htmlspecialchars($latestAuditDate) ?> • ✅ <?= $auditCount ?> • <span id="unaudited-count" title="Click to filter unaudited files">⚠️ <?= $unAuditedCount ?></span>
                <?php else: ?>
                    <span id="unaudited-count" title="Click to filter unaudited files">⚠️ Not audited</span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <?php if ($is_mobile && in_array('audit', $permissions)): ?>
            </span>
            <?php endif; ?>
            
            <?php if (in_array('optimize', $permissions)): ?>
            <!-- Optimization status -->
            <span id="optimization-text" style="
                background: rgba(107, 114, 128, 0.1);
                border: 1px solid rgba(107, 114, 128, 0.2);
                padding: 6px 10px;
                border-radius: 14px;
                font-size: 0.85rem;
                font-weight: 600;
                color: var(--text-secondary);
                white-space: nowrap;
                height: 28px;
                display: inline-flex;
                align-items: center;
                cursor: pointer;
            " data-initial-unoptimized="<?= $unoptimizedCount ?>" data-initial-optimized="<?= $optimizedCount ?>">
                <span id="optimization-status-text" title="Checking optimization status...">⏳ Scanning...</span>
            </span>
            <?php endif; ?>
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

        <!-- Path browser overlay -->
        <div id="path-overlay" style="display:none;">
            <div class="search-container">
                <span id="path-display" class="path-display">/volumes</span>
                <input type="text" 
                       id="path-input" 
                       placeholder="Type path (e.g., /volumes/folder1) and press Enter" 
                       autocomplete="off" 
                       spellcheck="false" />
                <button id="path-go" title="Go to path">⇨</button>
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
                optimizedCount: <?= $optimizedCount ?>,
                unoptimizedCount: <?= $unoptimizedCount ?>,
                muted: <?= $muted ? 'true' : 'false' ?>,
                totalCells: <?= $total_cells ?>,
                selectedColumns: <?= $selected_columns ?>,
                sort: {
                    field: <?= json_encode($sort_field) ?>,
                    direction: <?= json_encode($sort_direction) ?>
                },
                webRoot: <?= json_encode($webRoot) ?>,
                rootDirAbs: <?= json_encode($root_directory_absolute) ?>,
                currentPath: <?= json_encode(str_replace($root_directory_absolute, '', $current_path)) ?>,
                permissions: <?= json_encode($permissions) ?>
            };
        </script>
        <script type="module" src="/assets/js/main.js"></script>

    </body>
</html>