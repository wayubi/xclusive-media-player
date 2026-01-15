<?php

// ================================
// POST HANDLERS
// ================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (($data['action'] ?? null) === 'delete' && !empty($data['files'])) {
        $ch = curl_init('http://php-cli:8080/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'action' => 'delete',
                'files'  => $data['files']
            ])
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo json_encode(['error' => curl_error($ch)]);
        } else {
            echo $response;
        }
        curl_close($ch);
        exit;
    }

    if (($data['action'] ?? null) === 'audit' && !empty($data['path'])) {
        $ch = curl_init('http://php-cli:8080/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'action' => 'audit',
                'path'   => $data['path'],
                'count'  => (int)($data['count'] ?? 0)
            ])
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            echo json_encode(['error' => curl_error($ch)]);
        } else {
            echo $response;
        }
        curl_close($ch);
        exit;
    }
}

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

// 2. Compute current absolute path from incoming segments (base for goto)
$current_abs_path = $root_directory_absolute;
foreach ($path_segments as $seg) {
    $next = $current_abs_path . '/' . $seg;
    if (is_dir($next)) {
        $current_abs_path = $next;
    } else {
        // Safety: stop if invalid segment
        break;
    }
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
    foreach ($path_segments as $seg) {
        $next = $current_abs_path . '/' . $seg;
        if (is_dir($next)) {
            $current_abs_path = $next;
        } else {
            // Stop on invalid segment to prevent errors
            break;
        }
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
        if (is_dir($next)) {
            $current_abs_path = $next;
        }
        // If not a dir → silently stay (you can add feedback later)
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

// If no navigation action → use incoming path[] as-is
$selected_path_parts_final = $path_segments;
$selected_path = implode('/', $selected_path_parts_final);

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

    usort($files, fn($a, $b) => @filemtime($b) <=> @filemtime($a));
    return array_values($files);
}

function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb): string {
    $fsPath = str_replace('\\', '/', realpath($fsPath));
    $rootFs = str_replace('\\', '/', realpath($rootFs));
    $relative = str_starts_with($fsPath, $rootFs) ? substr($fsPath, strlen($rootFs)) : $fsPath;
    $segments = array_map('rawurlencode', explode('/', ltrim($relative, '/')));
    return rtrim($rootWeb, '/') . '/' . implode('/', $segments);
}

function getCurrentPath(string $root, string $selected_path): string {
    $real = realpath($root . ($selected_path ? '/' . $selected_path : '')) ?: $root;
    return str_starts_with($real, $root) ? $real : $root;
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
<style>
/* ==========================================================================
   SEXY MODERN DARK THEME - MERGED 2026 EDITION
   ========================================================================== */

:root {
  --bg: #0f0f11;
  --surface: #17171c;
  --surface-hover: #22222a;
  --accent: #7c3aed;
  --accent-glow: #a78bfa;
  --text: #f1f1f3;
  --text-secondary: #a0a0b0;
  --border: #33333a;
  --radius-sm: 10px;
  --radius: 16px;
  --shadow-sm: 0 4px 16px rgba(0,0,0,0.38);
  --shadow-lg: 0 12px 40px rgba(0,0,0,0.55);
  --transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
  --grid-gap: 12px;
}

* { box-sizing: border-box; }

html, body {
  margin: 0;
  padding: 0;
  height: 100%;
  font-family: 'Inter', system-ui, sans-serif;
  font-weight: 500;
  background: var(--bg);
  color: var(--text);
  overflow: hidden;
}

#options-form {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 8px;
  padding: 8px 16px;
  background: linear-gradient(to bottom, rgba(20,20,28,0.92), rgba(15,15,22,0.88));
  backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  z-index: 100;
}

#folder-select-container {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

#folder-select-container button {
    padding: 8px 14px;
    background: linear-gradient(145deg, #2a2a35, #22222c);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    white-space: nowrap;
}

#folder-select-container button:hover {
    background: var(--surface-hover);
    transform: translateY(-1px);
    box-shadow: 0 2px 10px rgba(124,58,237,0.2);
}

#options-form select,
#options-form button {
  padding: 6px 12px;
  font-size: 0.95rem;
  border: none;
  border-radius: var(--radius);
  background: var(--surface);
  color: var(--text);
  font-weight: 500;
  cursor: pointer;
  transition: var(--transition);
  height: 36px;
  min-width: 36px;
  white-space: nowrap;
}

#options-form select:hover,
#options-form button:hover {
  background: var(--surface-hover);
  transform: translateY(-1px);
}

#folder-select {
  min-width: 220px;
  max-width: 380px;
  font-size: 1.05rem;
  background: var(--surface);
  border: 1px solid var(--border);
}

/* ========================================================================== 
   MAIN GRID
   ========================================================================== */
#grid {
  display: grid;
  gap: var(--grid-gap);
  padding: 16px;
  height: calc(100% - 64px);
  grid-auto-rows: minmax(0, 1fr);
}

/* ========================================================================== 
   VIDEO / IMAGE TILES
   ========================================================================== */
.video-container {
  position: relative;
  overflow: hidden;
  border-radius: var(--radius);
  background: #0a0a0c;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
  /* backdrop-filter: blur(2px); */
}

.video-container:hover {
  /* transform: scale(1.03); */
  box-shadow: 0 16px 48px rgba(0,0,0,0.65),
              0 0 0 1px rgba(124,58,237,0.18);
  z-index: 5;
}

.video-container video,
.video-container img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  /* transition: transform 0.45s ease; */
}

/* ========================================================================== 
   FILE INFO OVERLAY
   ========================================================================== */
.overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(to top, rgba(0,0,0,0.85) 0%, transparent 60%);
  color: white;
  opacity: 0;
  transition: opacity 0.35s ease;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 16px;
  pointer-events: none;
  /* backdrop-filter: blur(6px); */
  border-radius: var(--radius);
}

.video-container:hover .overlay {
  opacity: 1;
  pointer-events: auto;
}

.overlay > div:first-child {
  font-weight: 600;
  font-size: 1.05rem;
  margin-bottom: 4px;
  text-shadow: 0 1px 4px rgba(0,0,0,0.7);
}

.overlay > div:last-child {
  font-size: 0.82rem;
  opacity: 0.85;
  letter-spacing: 0.3px;
}

/* ========================================================================== 
   CENTRAL FLOATING CONTROLS
   ========================================================================== */
.central-overlay {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  gap: 16px;
  opacity: 0;
  transition: opacity 0.4s ease;
  pointer-events: none;
  z-index: 10;
}

.video-container:hover .central-overlay {
  opacity: 1;
  pointer-events: auto;
}

.central-overlay button {
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: none;
  background: rgba(30,30,40,0.75);
  backdrop-filter: blur(8px);
  color: white;
  font-size: 1.5rem;
  cursor: pointer;
  transition: all 0.25s ease;
  box-shadow: 0 4px 16px rgba(0,0,0,0.4);
}

.central-overlay button:hover {
  background: rgba(124,58,237,0.65);
  transform: scale(1.15);
  box-shadow: 0 8px 30px rgba(124,58,237,0.45);
}

/* ========================================================================== 
   SEARCH OVERLAY
   ========================================================================== */
#search-overlay {
  position: fixed;
  inset: 0;
  background: rgba(12,12,18,0.94);
  backdrop-filter: blur(16px);
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 10000;
}

.search-container {
  position: relative;
  width: min(92%, 760px);
}

#search-input {
  width: 100%;
  padding: 22px 70px 22px 24px;
  font-size: 1.4rem;
  background: rgba(30,30,40,0.75);
  border: none;
  border-radius: 18px;
  color: white;
  outline: none;
  box-shadow: 0 0 0 2px rgba(124,58,237,0.3);
  transition: all 0.35s ease;
}

#search-input:focus {
  box-shadow: 0 0 0 3px var(--accent),
              0 0 30px rgba(124,58,237,0.4);
  background: rgba(40,40,55,0.8);
}

#search-clear {
  position: absolute;
  right: 18px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: #888;
  font-size: 2rem;
  cursor: pointer;
  transition: color 0.3s;
}

#search-clear:hover {
  color: var(--accent-glow);
}

/* ========================================================================== 
   MOBILE ADJUSTMENTS
   ========================================================================== */
@media (max-width: 768px) {
  #grid {
    gap: 8px;
    padding: 10px;
  }
  
  #options-form {
    padding: 10px 12px;
    gap: 6px;
  }
  
  #folder-select-container select {
    max-width: 160px;
    min-width: 120px;
  }
  
  #options-form select[name="columns"],
  #options-form select[name="rows"] {
    min-width: 60px;
  }
}
</style>

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
  <button type="button" id="refresh" onclick="window.location.reload()">🔄</button>
  <button type="button" id="clear" onclick="window.location.href='index.php'">🧹</button>
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
// Core state
let allVideos = <?= json_encode($allFiles, JSON_UNESCAPED_SLASHES) ?>;
let allFilesWithPaths = <?= json_encode($allFilesRaw, JSON_UNESCAPED_SLASHES) ?>;
const audioThumbs = <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>;
let muted = <?= $muted ? 'true' : 'false' ?>;
const totalCells = <?= $total_cells ?>;
let startIndex = 0;
let lastFullscreen = { file: null, time: 0 };
let fullscreenMode = 'tile';
let originalVideos = [...allVideos];
let currentSearch = '';

// Audio loading queue
const audioQueue = [];
let activeAudioLoads = 0;
const MAX_CONCURRENT_VIDEO = totalCells;

const videoQueue = [];
let activeVideoLoads = 0;
const MAX_CONCURRENT_AUDIO = totalCells;

const buttonStyle = 'font-size:20px;padding:6px 10px;border:none;border-radius:6px;background:rgba(0,0,0,0.6);color:white;cursor:pointer;pointer-events:auto;';
const centralOverlayStyle = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;gap:10px;z-index:10;opacity:0;transition:opacity 0.2s;pointer-events:none;';

// Create a fixed number of reusable <video> and <audio> elements
const MAX_POOL_SIZE = 48;

const videoPool = [];
const audioPool = [];

// Pre-create reusable elements
for (let i = 0; i < MAX_POOL_SIZE; i++) {
    const video = document.createElement('video');
    video.preload = 'auto';
    video.playsInline = true;
    video.loop = true;
    video.controls = false;
    videoPool.push(video);

    const audio = document.createElement('audio');
    audio.preload = 'auto';
    audio.playsInline = true;
    audio.loop = true;
    audioPool.push(audio);
}

// ================================
// MEDIA HELPERS
// ================================
function processAudioQueue() {
    while (activeAudioLoads < MAX_CONCURRENT_AUDIO && audioQueue.length) {
        const audio = audioQueue.shift();
        if (!audio?.dataset?.src) continue;
        activeAudioLoads++;
        audio.src = audio.dataset.src;
        delete audio.dataset.src;
        audio.load();
        const done = () => { activeAudioLoads = Math.max(0, activeAudioLoads - 1); processAudioQueue(); };
        audio.addEventListener('loadedmetadata', done, { once: true });
        audio.addEventListener('error', done, { once: true });
    }
}

function processVideoQueue() {
    while (activeVideoLoads < MAX_CONCURRENT_VIDEO && videoQueue.length) {
        const video = videoQueue.shift();
        if (!video?.dataset?.src) continue;
        activeVideoLoads++;
        video.src = video.dataset.src;
        delete video.dataset.src;
        video.load();
        const done = () => { activeVideoLoads = Math.max(0, activeVideoLoads - 1); processVideoQueue(); };
        video.addEventListener('loadedmetadata', () => {
            video.play().catch(() => {});
            done();
        }, { once: true });
        video.addEventListener('error', done, { once: true });
    }
}

function isFileVisible(file) {
    const end = Math.min(startIndex + totalCells, allVideos.length);
    return allVideos.slice(startIndex, end).includes(file);
}

async function addFileInfoOverlay(container, file) {
    container.style.position ||= 'relative';

    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.style.cssText = `
        background: rgba(0,0,0,0.6);
        color: white;
        padding: 4px 6px;
        font-size: 14px;
        border-radius: 4px;
        pointer-events: none;
        display: inline-block;
    `;

    const filenameElem = document.createElement('div');
    filenameElem.textContent = file;
    filenameElem.style.fontWeight = 'bold';

    const metaElem = document.createElement('div');
    metaElem.style.fontSize = '12px';
    metaElem.style.marginTop = '2px';

    overlay.appendChild(filenameElem);
    overlay.appendChild(metaElem);
    container.appendChild(overlay);

    /* -- deprecated in favor of metadata batch --
    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'metadata', 
                file: decodeURIComponent(file)
            })
        });
        if (!res.ok) throw new Error('Metadata failed');
        const meta = await res.json();

        const parts = [];
        if (meta.folder) parts.push(meta.folder);
        if (meta.video?.width && meta.video?.height) parts.push(`${meta.video.width}×${meta.video.height}`);
        if (meta.duration) {
            let sec = Math.floor(meta.duration);
            const h = Math.floor(sec / 3600); sec %= 3600;
            const m = Math.floor(sec / 60); sec %= 60;
            parts.push(`${h ? h + 'h ' : ''}${m ? m + 'm ' : ''}${sec}s`);
        }
        if (meta.filesize) parts.push((meta.filesize / 1024 / 1024).toFixed(2) + ' MB');
        if (meta.video) {
            if (meta.video.codec) parts.push(meta.video.codec);
            if (meta.video.fps) parts.push(`${meta.video.fps} FPS`);
        }
        if (meta.bitrate) parts.push(Math.round(meta.bitrate / 1000) + ' kbps');

        metaElem.textContent = parts.join(' • ');

        if (meta.file) filenameElem.textContent = meta.file;

        if (currentSearch && !meta.file.toLowerCase().includes(currentSearch)) {
            const folderMatchTag = document.createElement('div');
            folderMatchTag.textContent = 'Folder match';
            folderMatchTag.style.cssText = 'font-size:11px; color:#7c3aed; margin-top:4px;';
            metaElem.appendChild(folderMatchTag);
        }

    } catch {
        metaElem.textContent = '';
        filenameElem.textContent = file;
    }
    */
}

function addCentralOverlay(container, mediaEl, file) {
    const overlay = document.createElement('div');
    overlay.style.cssText = centralOverlayStyle + 'display:flex;justify-content:space-between;';

    const selectBtn = document.createElement('button');
    selectBtn.innerHTML = '🗙';
    selectBtn.style.cssText = buttonStyle + 'background:gray;';
    selectBtn.dataset.file = file;
    selectBtn.dataset.selected = 'false';
    selectBtn.onclick = e => {
        e.stopPropagation();
        if (selectBtn.dataset.selected === 'false') {
            selectBtn.dataset.selected = 'true';
            selectBtn.style.background = 'red';
        } else {
            const selected = Array.from(document.querySelectorAll('#grid .video-container button[data-selected="true"]'));
            const filesToDelete = selected.map(b => b.dataset.file);
            if (!filesToDelete.length || !confirm(`Delete ${filesToDelete.length} file(s)?`)) return;
            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', files: filesToDelete })
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) return alert('Delete error: ' + data.error);
                filesToDelete.forEach(f => {
                    const idx = allVideos.indexOf(f);
                    if (idx !== -1) allVideos.splice(idx, 1);
                });
                startIndex = Math.min(startIndex, Math.max(0, allVideos.length - totalCells));
                renderGrid();
            })
            .catch(() => alert('Delete failed'));
        }
    };
    overlay.appendChild(selectBtn);

    const fsBtn = document.createElement('button');
    fsBtn.innerHTML = '⛶';
    fsBtn.style.cssText = buttonStyle;
    fsBtn.onclick = e => {
        e.stopPropagation();
        const time = mediaEl && typeof mediaEl.currentTime === 'number' ? mediaEl.currentTime : 0;
        startFullscreenFrom(file, time);
    };
    overlay.appendChild(fsBtn);

    if (mediaEl && (mediaEl.tagName === 'VIDEO' || mediaEl.tagName === 'AUDIO')) {
        const muteBtn = document.createElement('button');
        muteBtn.className = 'mute-btn';
        muteBtn.innerHTML = mediaEl.muted ? '🔇' : '🔊';
        muteBtn.style.cssText = buttonStyle;
        muteBtn.onclick = e => {
            e.stopPropagation();
            document.querySelectorAll('#grid audio, #grid video').forEach(m => m.muted = true);
            mediaEl.muted = false;
            lastFullscreen = { file: null, time: 0 };
            mediaEl.play().catch(() => {});
            syncMuteIcons();
        };
        overlay.appendChild(muteBtn);
    }

    container.appendChild(overlay);
    container.addEventListener('mouseenter', () => overlay.style.opacity = '1');
    container.addEventListener('mouseleave', () => overlay.style.opacity = '0');
}

function createMediaContainer(file) {
    const container = document.createElement('div');
    container.className = 'video-container';

    const ext = file.split('.').pop().toLowerCase();
    const isAudio = ['mp3','wav','ogg'].includes(ext);
    const isVideo = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv'].includes(ext);
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);

    let mediaEl = null;

    if (isVideo || isAudio) {
        // Reuse from pool if available, otherwise create new
        mediaEl = isVideo 
            ? (videoPool.pop() || document.createElement('video'))
            : (audioPool.pop() || document.createElement('audio'));

        // Reset the reused element completely
        mediaEl.pause();
        mediaEl.src = '';
        mediaEl.currentTime = 0;
        mediaEl.dataset.src = file;
        mediaEl.dataset.file = file;

        // Re-apply common properties
        mediaEl.loop = true;
        mediaEl.playsInline = true;
        mediaEl.controls = false;
        mediaEl.preload = 'auto';

        if (isVideo) {
            mediaEl.poster = audioThumbs[file] || 'cache/no-cover.jpg';
        }

        // Mute / unmute logic
        const isRecentFullscreen = lastFullscreen.file === file && isFileVisible(file);
        let shouldBeUnmuted = false;
        if (!muted) {
            if (isRecentFullscreen) shouldBeUnmuted = true;
            else {
                const visibleMedia = allVideos.slice(startIndex, startIndex + totalCells)
                    .filter(f => /\.(mp4|webm|mkv|mp3|wav|ogg)$/i.test(f));
                if (visibleMedia[0] === file) shouldBeUnmuted = true;
            }
        }
        mediaEl.muted = !shouldBeUnmuted;

        if (isAudio) {
            container.style.cssText = 'display:flex;flex-direction:column;justify-content:center;align-items:center;';
            const img = document.createElement('img');
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer;border-radius:8px;';
            img.src = audioThumbs[file] || 'cache/no-cover.jpg'; // Use src directly (no lazy for covers)
            img.onclick = () => startFullscreenFrom(file, mediaEl.currentTime);
            container.appendChild(img);
        }

        if (isVideo) videoQueue.push(mediaEl);
        if (isAudio) audioQueue.push(mediaEl);

        container.appendChild(mediaEl);
    }
    else if (isImage) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.dataset.src = file;
        container.appendChild(img);
    } 
    else {
        container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
    }

    // Add overlays and event handlers (unchanged)
    addCentralOverlay(container, mediaEl, file);
    addFileInfoOverlay(container, file);

    // Restore time if this was the last fullscreen item
    if ((isVideo || isAudio) && lastFullscreen.file === file && lastFullscreen.time > 0) {
        mediaEl.addEventListener('loadedmetadata', () => {
            mediaEl.currentTime = lastFullscreen.time;
        }, { once: true });
    }

    return container;
}

// ================================
// GRID RENDERING & NAVIGATION
// ================================
function renderGrid() {
    const grid = document.getElementById('grid');

    // Recycle existing media elements back into the pool
    grid.querySelectorAll('video, audio').forEach(el => {
        el.pause();
        el.src = '';
        el.currentTime = 0;
        // Remove any one-time listeners if you added more
        el.replaceWith(el.cloneNode(true)); // Optional: cleanest way to remove listeners
        if (el.tagName === 'VIDEO') {
            videoPool.push(el);
        } else if (el.tagName === 'AUDIO') {
            audioPool.push(el);
        }
    });

    // Now clear the grid safely
    grid.innerHTML = '';

    const visibleCount = Math.min(
        totalCells,
        Math.max(0, allVideos.length - startIndex)
    );

    const cols = Math.min(visibleCount, <?= $selected_columns ?>);
    const rows = Math.ceil(visibleCount / cols);
    grid.style.gridTemplateColumns = `repeat(${cols}, 1fr)`;
    grid.style.gridAutoRows = `${100 / rows}%`;

    grid.querySelectorAll('video, audio').forEach(m => { m.pause(); m.src = ''; m.load(); });
    grid.innerHTML = '';

    const visible = allVideos.slice(startIndex, Math.min(startIndex + totalCells, allVideos.length));
    if (lastFullscreen.file && !isFileVisible(lastFullscreen.file)) lastFullscreen = { file: null, time: 0 };

    const fragment = document.createDocumentFragment();
    visible.forEach(file => fragment.appendChild(createMediaContainer(file)));

    // After creating containers...
    const visibleFiles = visible.map(f => decodeURIComponent(f)); // your web paths

    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            action: 'metadata_batch', 
            files: visibleFiles 
        })
    })
    .then(r => r.json())
    .then(metas => {
        Array.from(grid.children).forEach((container, idx) => {
            const file = visible[idx];  // encoded web path
            const meta = metas[file] || {};

            const filenameElem = container.querySelector('.overlay > div:first-child');
            const metaElem = container.querySelector('.overlay > div:last-child');

            filenameElem.textContent = meta.file || decodeURIComponent(file.split('/').pop());

            const parts = [];
            if (meta.folder) parts.push(meta.folder);
            if (meta.video?.width && meta.video?.height) parts.push(`${meta.video.width}×${meta.video.height}`);
            if (meta.duration) {
                let sec = Math.floor(meta.duration);
                const h = Math.floor(sec / 3600); sec %= 3600;
                const m = Math.floor(sec / 60); sec %= 60;
                parts.push(`${h ? h + 'h ' : ''}${m ? m + 'm ' : ''}${sec}s`);
            }
            if (meta.filesize) parts.push((meta.filesize / 1024 / 1024).toFixed(2) + ' MB');
            if (meta.video) {
                if (meta.video.codec) parts.push(meta.video.codec);
                if (meta.video.fps) parts.push(`${meta.video.fps} FPS`);
            }
            if (meta.bitrate) parts.push(Math.round(meta.bitrate / 1000) + ' kbps');

            metaElem.textContent = parts.join(' • ');
        });
    })
    .catch(() => {
        // Fallback: show filenames only, or retry single fetches
    });

    grid.appendChild(fragment);

    processAudioQueue();
    processVideoQueue();

    const recentMedia = [...grid.querySelectorAll('audio, video')]
        .find(m => m.dataset.file === lastFullscreen.file);
    if (recentMedia) {
        recentMedia.currentTime = lastFullscreen.time || 0;
        recentMedia.play().catch(() => {});
    }
    enforceSingleUnmuted();
    syncMuteIcons();

    document.getElementById('file-count').innerText = 
        currentSearch 
            ? `Filtered: ${startIndex + 1} / ${allVideos.length} (of ${originalVideos.length})`
            : `${startIndex + 1} / ${allVideos.length}`;
}

function nextGrid() {
    startIndex = (startIndex + totalCells) % allVideos.length;
    renderGrid();
}

function prevGrid() {
    startIndex = (startIndex - totalCells + allVideos.length) % allVideos.length;
    renderGrid();
}

function computeGridDimensions(count, maxCols) {
    if (count <= maxCols) {
        return { cols: count, rows: 1 };
    }

    const cols = maxCols;
    const rows = Math.ceil(count / cols);
    return { cols, rows };
}

// ================================
// MUTE & SINGLE AUDIO CONTROL
// ================================
function toggleMute() {
    muted = !muted;
    document.getElementById('mute-button').innerHTML = muted ? '🔇' : '🔊';
    if (muted) lastFullscreen = { file: null, time: 0 };
    renderGrid();
}

function enforceSingleUnmuted() {
    const media = Array.from(document.querySelectorAll('#grid video, #grid audio'));
    if (!media.length) return;

    let target = null;
    if (!muted) {
        if (lastFullscreen.file) target = media.find(m => m.dataset.file === lastFullscreen.file);
        if (!target) target = media[0];
    }

    media.forEach(m => m.muted = true);
    if (target) {
        target.muted = false;
        target.play().catch(() => {});
    }
}

function syncMuteIcons() {
    document.querySelectorAll('#grid .video-container').forEach(container => {
        const media = container.querySelector('video, audio');
        const btn = container.querySelector('.mute-btn');
        if (media && btn) btn.innerHTML = media.muted ? '🔇' : '🔊';
    });
}

// ================================
// FULLSCREEN PLAYER
// ================================
function startFullscreenFrom(file, startTime = 0) {
    fullscreenMode = 'tile';
    document.querySelectorAll('#grid video, #grid audio').forEach(m => m.pause());
    lastFullscreen = { file, time: startTime };
    startFullscreenPlayer(allVideos, allVideos.indexOf(file), startTime);
}

function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
    if (!playlist.length) return;
    let i = index;

    if (window.AndroidPlayer && window.useExoPlayer) {
        AndroidPlayer.playFullscreen(JSON.stringify(playlist), i, startTime);
        return;
    }

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:#000;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;';
    document.body.appendChild(container);

    let mediaEl, thumb;

    function createMedia(file, startTime = 0) {
        const ext = file.split('.').pop().toLowerCase();
        const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
        const isAudio = ['mp3','wav','ogg'].includes(ext);

        if (mediaEl && lastFullscreen.file === file) {
            container.innerHTML = '';
            if (thumb) container.appendChild(thumb);
            container.appendChild(mediaEl);
            return;
        }

        container.innerHTML = '';

        if (isImage) {
            mediaEl = document.createElement('img');
            mediaEl.src = file;
            mediaEl.style.cssText = 'max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 0 40px rgba(0,0,0,0.6);cursor:pointer;';
            mediaEl.ondblclick = close;
            container.appendChild(mediaEl);
        } else {
            mediaEl = isAudio ? document.createElement('audio') : document.createElement('video');
            mediaEl.src = file;
            mediaEl.currentTime = startTime;
            mediaEl.autoplay = true;
            mediaEl.controls = !isAudio;
            mediaEl.playsInline = !isAudio;
            mediaEl.muted = muted;

            if (isAudio) {
                mediaEl.style.cssText = 'width:100%;height:40px;margin-bottom:6px;border-radius:6px;';
                thumb = document.createElement('img');
                thumb.src = audioThumbs[file] || 'cache/no-cover.jpg';
                thumb.style.cssText = 'max-width:95vw;max-height:80vh;object-fit:contain;margin-bottom:6px;border-radius:8px;cursor:pointer;';
                thumb.ondblclick = close;
                container.appendChild(thumb);
            } else {
                mediaEl.style.cssText = 'max-width:95vw;max-height:92vh;object-fit:contain;border-radius:8px;box-shadow:0 0 40px rgba(0,0,0,0.6);cursor:pointer;';
                mediaEl.ondblclick = close;
            }

            container.appendChild(mediaEl);

            mediaEl.addEventListener('loadedmetadata', () => {
                if (!isAudio) {
                    const aspect = mediaEl.videoWidth / mediaEl.videoHeight;
                    mediaEl.style.width = aspect >= 1 ? '95vw' : 'auto';
                    mediaEl.style.height = aspect < 1 ? '92vh' : 'auto';
                    mediaEl.play().catch(() => {});
                }
            }, { once: true });
        }

        const isSingleTile = fullscreenMode === 'tile';
        mediaEl.loop = isSingleTile && !isImage;
        if (!mediaEl.loop && !isImage) mediaEl.onended = () => play(i + 1);

        lastFullscreen.file = file;
        if (!isImage && !isAudio) lastFullscreen.time = startTime;
    }

    function play(idx) {
        i = (idx + playlist.length) % playlist.length;
        const file = playlist[i];

        if (mediaEl && lastFullscreen.file === file) {
            container.innerHTML = '';
            if (thumb) container.appendChild(thumb);
            container.appendChild(mediaEl);
            return;
        }

        if (mediaEl) {
            if (mediaEl.tagName === 'AUDIO' || mediaEl.tagName === 'VIDEO') {
                lastFullscreen.time = mediaEl.currentTime;
            }
            if (thumb) thumb.remove();
            mediaEl.remove();
            mediaEl = null;
            thumb = null;
        }

        createMedia(file, lastFullscreen.file === file ? lastFullscreen.time : 0);
    }

    function close() {
        if (mediaEl && mediaEl.tagName.toLowerCase() !== 'img') {
            lastFullscreen.time = mediaEl.currentTime;
        } else {
            lastFullscreen.time = 0;
        }
        lastFullscreen.file = playlist[i];

        startIndex = Math.floor(allVideos.indexOf(playlist[i]) / totalCells) * totalCells;
        renderGrid();

        if (thumb) thumb.remove();
        if (mediaEl) mediaEl.remove();
        container.remove();
        document.removeEventListener('keydown', keyHandler);
    }

    createMedia(playlist[i], startTime);

    container.addEventListener('wheel', e => {
        e.preventDefault();
        e.deltaY > 0 ? play(i + 1) : play(i - 1);
    }, { passive: false });

    let touchY = 0;
    container.addEventListener('touchstart', e => {
        if (e.touches.length === 1) touchY = e.touches[0].clientY;
    }, { passive: true });

    container.addEventListener('touchend', e => {
        const delta = e.changedTouches[0].clientY - touchY;
        if (Math.abs(delta) > 50) delta < 0 ? play(i + 1) : play(i - 1);
    }, { passive: true });

    const keyHandler = async e => {
        if (e.key === 'Escape') return close();
        if ([38, 40].includes(e.keyCode)) { e.preventDefault(); return close(); }
        if (e.keyCode === 37) { e.preventDefault(); play(i - 1); return; }
        if (e.keyCode === 39) { e.preventDefault(); play(i + 1); return; }

        if (e.key === 'Delete') {
            if (!confirm('Delete this file?')) return;
            const del = playlist[i];
            try {
                const resp = await fetch('index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', files: [del] })
                });
                const data = await resp.json();
                if (data.error) throw new Error(data.error);

                playlist.splice(i, 1);
                const idx = allVideos.indexOf(del);
                if (idx !== -1) allVideos.splice(idx, 1);
                renderGrid();

                if (!playlist.length) return close();
                i = i % playlist.length;
                play(i);
            } catch (err) {
                console.error(err);
                alert('Delete failed');
            }
        }
    };
    document.addEventListener('keydown', keyHandler);

    container.addEventListener('click', e => {
        if (e.target === container) close();
    });
}

function playAll() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    startFullscreenPlayer(allVideos, startIndex);
}

function shufflePlay() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    startFullscreenPlayer([...allVideos].sort(() => Math.random() - 0.5), 0);
}

// ================================
// AUDIT
// ================================
function runAudit(count) {
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'audit',
            path: <?= json_encode($selected_path ? $root_directory . '/' . $selected_path : $root_directory) ?>,
            count
        })
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('audit-text').innerText = data.error ? `[ Error: ${data.error} ]` : `[ ${data.text} ]`;
    })
    .catch(() => alert('Audit failed'));
}

const optionsForm = document.getElementById('options-form');
addWheelListener(optionsForm);

// ================================
// GRID GESTURES & UTILS
// ================================
const grid = document.getElementById('grid');
let scrollDebounce = false;

addWheelListener(grid);

let touchStartY = 0;
grid.addEventListener('touchstart', e => {
    if (e.touches.length === 1) touchStartY = e.touches[0].clientY;
}, { passive: true });

grid.addEventListener('touchend', e => {
    const delta = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(delta) > 50) delta < 0 ? nextGrid() : prevGrid();
}, { passive: true });

function setVhUnit() {
    document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
}
setVhUnit();
window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);

// ================================
// HELPER FUNCTION FOR WHEEL EVENT HANDLING
// ================================
function addWheelListener(element) {
    element.addEventListener('wheel', (e) => {
        e.preventDefault();
        if (scrollDebounce) return;
        scrollDebounce = true;
        setTimeout(() => scrollDebounce = false, 200);
        e.deltaY < 0 ? prevGrid() : nextGrid();
    }, { passive: false });
}

// ================================
// SEARCH FEATURE
// ================================
const searchableItems = allFilesWithPaths.map(fullPath => {
    const webPath = '<?= $webRoot ?>/' + fullPath
        .replace('<?= $root_directory_absolute ?>/', '')
        .split('/')
        .map(encodeURIComponent)
        .join('/');

    const parts = fullPath.split('/');
    const filename = parts[parts.length - 1];
    const folderPath = parts.slice(0, -1).join('/');
    const folderNames = parts.slice(1, -1);

    return {
        webPath: webPath,
        filename: filename.toLowerCase(),
        folderNames: folderNames.map(n => n.toLowerCase()),
        fullFolderPath: folderPath.toLowerCase()
    };
});

function applySearch(term) {
    term = (term || '').trim().toLowerCase();
    currentSearch = term;

    if (!term) {
        allVideos = searchableItems.map(item => item.webPath);
        startIndex = 0;
        renderGrid();
        document.getElementById('search-overlay').style.display = 'none';
        return;
    }

    const filtered = searchableItems.filter(item => {
        if (item.filename.includes(term)) return true;
        return item.folderNames.some(folder => folder.includes(term));
    });

    allVideos = filtered.map(item => item.webPath);
    startIndex = 0;
    renderGrid();

    document.getElementById('search-overlay').style.display = 'none';
}

function showSearch() {
    const overlay = document.getElementById('search-overlay');
    const input = document.getElementById('search-input');
    if (!overlay || !input) return;
    overlay.style.display = 'flex';
    input.value = currentSearch;
    input.focus();
    input.select();
}

function closeSearch() {
    document.getElementById('search-overlay').style.display = 'none';
    document.getElementById('search-input')?.blur();
}

document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('search-overlay');
    const input = document.getElementById('search-input');
    const clearBtn = document.getElementById('search-clear');

    if (!input || !overlay) return;

    clearBtn?.addEventListener('click', () => {
        input.value = '';
        applySearch('');
    });

    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            applySearch(input.value);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeSearch();
        }
    });

    document.addEventListener('keydown', e => {
        if (document.activeElement.matches('input, textarea')) {
            if (e.key === 'Escape') closeSearch();
            return;
        }
        if (e.key === '/') {
            e.preventDefault();
            showSearch();
        } else if (e.key === 'Escape' && overlay.style.display === 'flex') {
            closeSearch();
        }
    });
});

// Track cover mode
let coverEnabled = true;

// Create a CSS class that disables object-fit
const style = document.createElement('style');
style.textContent = `
  .no-object-fit img,
  .no-object-fit video {
    object-fit: contain;
  }
`;
document.head.appendChild(style);

// Toggle function
function toggleObjectFit() {
  coverEnabled = !coverEnabled;
  const grid = document.getElementById('grid');
  if (coverEnabled) {
    grid.classList.remove('no-object-fit');
  } else {
    grid.classList.add('no-object-fit');
  }
}

// Listen for "c" key press
document.addEventListener('keydown', (e) => {
  if (e.key.toLowerCase() === 'c') {
    toggleObjectFit();
  }
});

renderGrid();
</script>
</body>
</html>