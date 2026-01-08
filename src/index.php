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

    if (($data['action'] ?? null) === 'metadata' && !empty($data['file'])) {
        $ch = curl_init('http://php-cli:8080/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode([
                'action' => 'metadata',
                'file'   => $data['file']
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

$selected_path_parts = array_values(array_filter($_GET['selected-path'] ?? [], 'strlen'));
$cursor = $root_directory_absolute;
$selected_path_parts_final = [];

foreach ($selected_path_parts as $part) {
    $next = $cursor . '/' . $part;
    if (!is_dir($next)) break;
    $selected_path_parts_final[] = $part;
    $cursor = $next;
}

$selected_path = implode('/', $selected_path_parts_final);
$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 3)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || $_GET['muted'] === 'true';

// ================================
// FILESYSTEM HELPERS
// ================================
function getSubfolders(string $path): array {
    if (!is_dir($path)) return [];
    $folders = scandir($path);
    $filtered = array_filter($folders, fn($d) => $d !== '.' && $d !== '..' && is_dir("$path/$d"));
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
    foreach ($it as $file) {
        if (!$file->isFile() || $file->getFilename() === '.audited') continue;
        $pathname = $file->getPathname();
        if (!mb_check_encoding($pathname, 'UTF-8')) {
            $pathname = iconv('UTF-8', 'UTF-8//IGNORE', $pathname);
        }
        if (!file_exists($pathname)) {
            error_log("Missing file: $pathname");
        }
        $files[] = $pathname;
    }
    usort($files, fn($a, $b) => @filemtime($b) <=> @filemtime($a));
    return $files;
}

function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb): string {
    $fsPath = str_replace('\\', '/', realpath($fsPath));
    $rootFs = str_replace('\\', '/', realpath($rootFs));
    $relative = str_starts_with($fsPath, $rootFs) ? substr($fsPath, strlen($rootFs)) : $fsPath;
    return $rootWeb . '/' . ltrim($relative, '/');
}

function getCurrentPath(string $root, string $selected_path): string {
    $real = realpath($root . ($selected_path ? '/' . $selected_path : '')) ?: $root;
    return str_starts_with($real, $root) ? $real : $root;
}

function renderFolderSelects(array $selected_parts, string $root_abs): void {
    $parent = '';
    foreach ($selected_parts as $part) {
        $folderPath = $root_abs . ($parent ? '/' . $parent : '');
        $subs = getSubfolders($folderPath);
        echo '<select name="selected-path[]" onchange="this.form.submit()">';
        echo '<option value="">[Select]</option>';
        foreach ($subs as $f) {
            echo "<option value=\"$f\"" . ($f === $part ? ' selected' : '') . ">$f</option>";
        }
        echo '</select>';
        $parent .= ($parent ? '/' : '') . $part;
    }
    $folderPath = $root_abs . ($parent ? '/' . $parent : '');
    $subs = getSubfolders($folderPath);
    if ($subs) {
        echo '<select name="selected-path[]" onchange="this.form.submit()">';
        echo '<option value="" selected>[Select]</option>';
        foreach ($subs as $f) echo "<option value=\"$f\">$f</option>";
        echo '</select>';
    }
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
html, body { margin:0; padding:0; height:100%; overflow:hidden; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#121212; color:#f0f0f0; }
#form { padding:12px 20px; background:#1f1f1f; display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:10px; border-bottom:1px solid #333; }
#options-form select, #options-form button { padding:6px 10px; border-radius:6px; border:none; background:#2c2c2c; color:#f0f0f0; font-size:14px; cursor:pointer; transition:0.2s; }
#options-form select:hover, #options-form button:hover { background:#3a3a3a; }
#file-count, #audit-text { font-weight:bold; margin:0 10px; }
#folder-select-container { display: inline-flex; gap: 6px; align-items: center; }
#folder-select-container select { max-width: 180px; min-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#grid { display:grid; grid-template-columns: repeat(<?php echo $selected_columns; ?>,1fr); grid-template-rows: repeat(<?php echo $selected_rows; ?>,1fr); gap:8px; padding:10px; height:calc(100% - 72px); }
.video-container { position:relative; width:100%; height:100%; overflow:hidden; border-radius:8px; background:black; }
.video-container video, .video-container img { width:100%; height:100%; object-fit:contain; display:block; border-radius:8px; transition: transform 0.2s, box-shadow 0.2s; }
.video-container:hover video, .video-container:hover img { transform:scale(1.03); box-shadow:0 4px 20px rgba(0,0,0,0.5); }
.video-container .overlay { position:absolute; top:4px; left:4px; right:4px; background:rgba(0,0,0,0.7); color:#fff; font-size:12px; padding:2px 6px; border-radius:4px; opacity:0; display:flex; justify-content:space-between; align-items:center; pointer-events:none; transition:opacity 0.2s; z-index:10; }
.video-container:hover .overlay { opacity:1; pointer-events:auto; }
.overlay button { background:#ff4d4f; border:none; border-radius:4px; color:#fff; font-size:10px; padding:2px 6px; cursor:pointer; margin-left:6px; }
.overlay button:hover { background:#d9363e; }
#search-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:10000; display:flex; align-items:center; justify-content:center; }
.search-container { position:relative; width:90%; max-width:700px; }
#search-input { width:100%; padding:18px 60px 18px 20px; font-size:20px; border:none; border-radius:12px; background:#1f1f1f; color:white; outline:none; box-shadow:0 0 0 2px #444; }
#search-input:focus { box-shadow:0 0 0 3px #0066ff; }
#search-clear { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:transparent; border:none; color:#888; font-size:28px; cursor:pointer; padding:8px; }
#search-clear:hover { color:#ff4d4f; }
@media (max-width:768px) {
  #form { flex-direction:row; justify-content:space-between; gap:6px; padding:6px 10px; }
  #form span[id="file-count"], #form select[name="columns"], #form select[name="rows"], #form button[id="refresh"], #form button[id="clear"], #form button[id="audit"], #form button[id="previous"], #form button[id="next"], #form span[id="audit-text"] { display:none; }
}
</style>
</head>
<body>

<div id="form">
<form id="options-form" method="get" action="index.php">
  <span id="file-count">1 / <?php echo count($allFiles); ?></span>
  <div id="folder-select-container"><?php renderFolderSelects($selected_path_parts_final, $root_directory_absolute); ?></div>
  <select name="columns" onchange="this.form.submit()"><?php for ($c=1;$c<=6;$c++): ?><option value="<?= $c ?>" <?= $c==$selected_columns?'selected':'' ?>><?= $c ?></option><?php endfor; ?></select>
  <select name="rows" onchange="this.form.submit()"><?php for ($r=1;$r<=6;$r++): ?><option value="<?= $r ?>" <?= $r==$selected_rows?'selected':'' ?>><?= $r ?></option><?php endfor; ?></select>
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
</div>

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
const MAX_CONCURRENT_AUDIO = 36;

const buttonStyle = 'font-size:20px;padding:6px 10px;border:none;border-radius:6px;background:rgba(0,0,0,0.6);color:white;cursor:pointer;pointer-events:auto;';
const centralOverlayStyle = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;gap:10px;z-index:10;opacity:0;transition:opacity 0.2s;pointer-events:none;';

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

    try {
        const res = await fetch('index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'metadata', file })
        });
        if (!res.ok) throw new Error('Metadata failed');
        const meta = await res.json();

        const parts = [];
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

    } catch {
        metaElem.textContent = '';
        filenameElem.textContent = file;
    }
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
    const isVideo = ['mp4','webm','mkv'].includes(ext);
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);

    let mediaEl = null;

    if (isVideo || isAudio) {
        mediaEl = isVideo ? document.createElement('video') : document.createElement('audio');
        mediaEl.loop = true;
        mediaEl.playsInline = true;
        mediaEl.preload = 'none';
        mediaEl.dataset.src = file;
        mediaEl.dataset.file = file;

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
            img.dataset.src = audioThumbs[file] || 'cache/no-cover.jpg';
            img.onclick = () => startFullscreenFrom(file, mediaEl.currentTime);
            container.appendChild(img);
            audioQueue.push(mediaEl);
        }

        container.appendChild(mediaEl);
    } else if (isImage) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.dataset.src = file;
        container.appendChild(img);
    } else {
        container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
    }

    addCentralOverlay(container, mediaEl, file);
    addFileInfoOverlay(container, file);

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
    grid.querySelectorAll('video, audio').forEach(m => { m.pause(); m.src = ''; m.load(); });
    grid.innerHTML = '';

    const visible = allVideos.slice(startIndex, Math.min(startIndex + totalCells, allVideos.length));
    if (lastFullscreen.file && !isFileVisible(lastFullscreen.file)) lastFullscreen = { file: null, time: 0 };

    const fragment = document.createDocumentFragment();
    visible.forEach(file => fragment.appendChild(createMediaContainer(file)));
    grid.appendChild(fragment);

    processAudioQueue();

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting && entry.target.dataset.src) {
                entry.target.src = entry.target.dataset.src;
                delete entry.target.dataset.src;
                if (entry.target.tagName === 'VIDEO') entry.target.play().catch(() => {});
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.01 });

    grid.querySelectorAll('video, img[data-src]').forEach(el => observer.observe(el));

    setTimeout(() => {
        const recentMedia = [...grid.querySelectorAll('audio, video')]
            .find(m => m.dataset.file === lastFullscreen.file);
        if (recentMedia) {
            recentMedia.currentTime = lastFullscreen.time || 0;
            recentMedia.play().catch(() => {});
        }
        enforceSingleUnmuted();
        syncMuteIcons();
    }, 150);

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

// ================================
// GRID GESTURES & UTILS
// ================================
const grid = document.getElementById('grid');
let scrollDebounce = false;

grid.addEventListener('wheel', e => {
    e.preventDefault();
    if (scrollDebounce) return;
    scrollDebounce = true;
    setTimeout(() => scrollDebounce = false, 200);
    e.deltaY < 0 ? prevGrid() : nextGrid();
}, { passive: false });

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
// SEARCH FEATURE
// ================================
function applySearch(term) {
    term = (term || '').trim().toLowerCase();
    currentSearch = term;
    allVideos = term ? originalVideos.filter(f => f.toLowerCase().includes(term)) : [...originalVideos];
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

renderGrid();
</script>
</body>
</html>