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

function renderSingleFolderSelect(array $selected_parts, string $current_abs_path): void {
    $is_root = empty($selected_parts);
    $subfolders = getSubfolders(path: $current_abs_path);
    $has_children = !empty($subfolders);
    ?>
    <select name="goto_folder" id="folder-select"
            onchange="this.form.submit()"
            style="min-width:220px; max-width:360px; font-size:1.05rem;"
            autofocus>
        <option value="" disabled selected>
            — <?= $has_children ? 'Select subfolder' : ($is_root ? 'No folders found' : 'No subfolders') ?> —
        </option>

        <?php foreach ($subfolders as $folder): ?>
            <option value="<?= htmlspecialchars($folder) ?>">
                <?= htmlspecialchars($folder) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

// ================================
// MAIN LOGIC
// ================================
$current_path = getCurrentPath($root_directory_absolute, $selected_path);
$auditFile = $current_path . '/.audited';
$auditedText = is_file($auditFile) ? trim(file_get_contents($auditFile)) : '';

$allFilesRaw = getFiles($current_path);
$webRoot = '/' . trim($root_directory, './');
$allFiles = array_map(fn($f) => filesystemToWebPath($f, $root_directory_absolute, $webRoot), $allFilesRaw);

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
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Xclusive Media Player</title>
        <link rel="stylesheet" href="/assets/css/app.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    </head>

    <body>

        <form id="options-form" method="get" action="index.php">
            <span id="file-count">1 / <?php echo count($allFiles); ?></span>
            <?php foreach ($selected_path_parts_final as $part): ?>
                <input type="hidden" name="path[]" value="<?= htmlspecialchars($part) ?>">
            <?php endforeach; ?>
            <div id="folder-select-container" style="display: flex; align-items: center; gap: 10px;">
                <?php if (!empty($selected_path_parts_final)): ?>
                    <button type="submit" name="goto" value=".." 
                    onclick="this.form.action = 'index.php?t=' + Date.now();"
                    style="padding: 8px 14px; background: var(--surface-hover); 
                    border: 1px solid var(--border); border-radius: var(--radius);
                    color: var(--text); font-weight: 500; cursor: pointer;">
                    ↑ Go Back
                    </button>
                <?php endif; ?>
                <?php renderSingleFolderSelect($selected_path_parts_final, $current_path); ?>
            </div>

            <select name="columns" onchange="this.form.submit()">
                <?php for ($c=1;$c<=6;$c++): ?>
                    <option value="<?= $c ?>" <?= $c==$selected_columns?'selected':'' ?>><?= $c ?></option>
                <?php endfor; ?>
            </select>

            <select name="rows" onchange="this.form.submit()">
                <?php for ($r=1;$r<=6;$r++): ?>
                    <option value="<?= $r ?>" <?= $r==$selected_rows?'selected':'' ?>><?= $r ?></option>
                <?php endfor; ?>
            </select>

        <input type="hidden" name="muted" value="<?= $muted?'true':'false' ?>">
        
        <button type="button" id="mute-button" onclick="toggleMute()"><?= $muted?'🔇':'🔊' ?></button>
        <button type="button" onclick="playAll()">▶</button>
        <button type="button" onclick="shufflePlay()">🔀</button>
        <!-- <button type="button" id="refresh" onclick="window.location.reload()">🔄</button>
        <button type="button" id="clear" onclick="window.location.href='index.php'">🧹</button> -->
        <button type="button" id="audit" onclick="runAudit(<?= count($allFiles) ?>)">📝</button>
        <button type="button" id="previous" onclick="prevGrid()">◀</button>
        <button type="button" id="next" onclick="nextGrid()">▶</button>
        <span id="audit-text">[ <?= htmlspecialchars($auditedText) ?> ]</span>
        </form>

        <div id="grid"></div>

        <div id="search-overlay" style="display:none;">
            <div class="search-container">
                <input type="text" id="search-input" placeholder="Search filenames… (Enter to filter, ESC to cancel)" autocomplete="off" />
                <button id="search-clear" title="Clear search">✕</button>
            </div>
        </div>

        <script>
            window.APP = {
                allVideos: <?= json_encode($allFiles, JSON_UNESCAPED_SLASHES) ?>,
                allFilesWithPaths: <?= json_encode($allFilesRaw, JSON_UNESCAPED_SLASHES) ?>,
                audioThumbs: <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>,
                muted: <?= $muted ? 'true' : 'false' ?>,
                totalCells: <?= $total_cells ?>,
                selectedColumns: <?= $selected_columns ?>,
                webRoot: <?= json_encode($webRoot) ?>,
                rootDirAbs: <?= json_encode($root_directory_absolute) ?>,
                auditPath: <?= json_encode(
                    $selected_path ? $root_directory . '/' . $selected_path : $root_directory
                ) ?>
            };
        </script>
        <script type="module" src="/assets/js/main.js"></script>

    </body>
</html>
