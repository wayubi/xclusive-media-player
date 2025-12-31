<?php

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // =====================
    // DELETE
    // =====================
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

    // =====================
    // AUDIT
    // =====================
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

// =====================
// CONFIG / SETTINGS
// =====================
$root_directory = './volumes';
$root_directory_absolute = realpath($root_directory);
if (!$root_directory_absolute) die('Root directory not found');

$is_mobile = stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Mobile') !== false
          || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Android') !== false;

$raw_parts = array_values(array_filter($_GET['selected-path'] ?? [], 'strlen'));
$selected_path_parts = [];

$cursor = $root_directory_absolute;

foreach ($raw_parts as $part) {
    $next = $cursor . '/' . $part;
    if (!is_dir($next)) {
        break;
    }
    $selected_path_parts[] = $part;
    $cursor = $next;
}

$selected_path = implode('/', $selected_path_parts);
$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 3)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || $_GET['muted'] === 'true';
$fileCount = (int)($_GET['fileCount'] ?? 0);

// =====================
// HELPERS
// =====================
function getSubfolders(string $path): array {
    if (!is_dir($path)) {
        return [];
    }

    $folders = scandir($path);
    $filtered = array_filter($folders, fn($d) => $d !== '.' && $d !== '..' && is_dir("$path/$d"));
    usort($filtered, function($a, $b) {
        return strcasecmp($a, $b);
    });
    return array_values($filtered);
}

function getFiles(string $path): array {
    if (!is_dir($path)) return [];

    $files = [];
    $dirIterator = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($it as $file) {
        if (!$file->isFile()) continue;

        // Skip audit files
        if ($file->getFilename() === '.audited') continue;

        // Normalize path safely
        $pathname = $file->getPathname();

        if (!mb_check_encoding($pathname, 'UTF-8')) {
            $pathname = iconv('UTF-8', 'UTF-8//IGNORE', $pathname);
        }

        // Only log if the file literally doesn't exist (rare)
        if (!file_exists($pathname)) {
            error_log("Warning: File truly missing or invalid UTF-8: $pathname");
        }

        $files[] = $pathname;
    }

    usort($files, fn($a, $b) => @filemtime($b) <=> @filemtime($a));
    return $files;
}

function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb): string {
    $fsPath = str_replace('\\','/',realpath($fsPath));
    $rootFs = str_replace('\\','/',realpath($rootFs));
    $relative = str_starts_with($fsPath,$rootFs) ? substr($fsPath,strlen($rootFs)) : $fsPath;
    return $rootWeb.'/'.ltrim($relative,'/');
}

function getCurrentPath(string $root, string $selected_path): string {
    $real = realpath($root.($selected_path ? '/'.$selected_path : '')) ?: $root;
    return str_starts_with($real,$root) ? $real : $root;
}

function renderFolderSelects(array $selected_parts, string $root_abs): void {
    $parent = '';
    foreach ($selected_parts as $part) {
        $folderPath = $root_abs . ($parent ? '/' . $parent : '');
        $subs = getSubfolders($folderPath);
        echo '<select name="selected-path[]" onchange="this.form.submit()">';
        echo '<option value="">[Select]</option>';
        foreach ($subs as $f) echo "<option value=\"$f\"" . ($f === $part ? ' selected' : '') . ">$f</option>";
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

// =====================
// MAIN
// =====================
$current_path = getCurrentPath($root_directory_absolute, $selected_path);
if (!str_starts_with($current_path,$root_directory_absolute)) {
    $current_path = $root_directory_absolute;
    $selected_path_parts = [];
    $selected_path = '';
}

$auditFile = $current_path.'/.audited';
$auditedText = is_file($auditFile) ? trim(file_get_contents($auditFile)) : '';

$allFilesRaw = getFiles($current_path);
$webRoot = '/'.trim($root_directory,'./');
$allFiles = array_map(fn($f)=>filesystemToWebPath($f,$root_directory_absolute,$webRoot),$allFilesRaw);

require_once __DIR__.'/lib/audioCovers.php';
$audioThumbsRaw = generateAudioCovers($allFilesRaw);

$audioThumbs = [];
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
foreach ($audioThumbsRaw as $audioFs=>$thumbFs) {
    $audioWeb = filesystemToWebPath($audioFs,$root_directory_absolute,$webRoot);
    $thumbWeb = $docRoot ? '/'.ltrim(str_replace('\\','/',str_replace($docRoot,'',realpath($thumbFs))),'/') : '';
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
@media (max-width:768px){
  #form { flex-direction:row; justify-content:space-between; gap:6px; padding:6px 10px; }
  #form span[id="file-count"], #form select[name="columns"], #form select[name="rows"], #form button[id="refresh"], #form button[id="clear"], #form button[id="audit"], #form button[id="previous"], #form button[id="next"], #form span[id="audit-text"] { display:none; }
}
</style>
</head>
<body>

<div id="form">
<form id="options-form" method="get" action="index.php">
  <span id="file-count">1 / <?php echo count($allFiles); ?></span>
  <div id="folder-select-container"><?php renderFolderSelects($selected_path_parts, $root_directory_absolute); ?></div>
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

<script>
// ===== Optimized Grid JS =====
const allVideos = <?= json_encode($allFiles, JSON_UNESCAPED_SLASHES) ?>;
const audioThumbs = <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>;
let muted = <?= $muted ? 'true' : 'false' ?>;
const totalCells = <?= $total_cells ?>;
let startIndex = 0;
let lastFullscreen = { file: null, time: 0 };
let fullscreenMode = 'tile';

const audioQueue = [];
let activeAudioLoads = 0;
const MAX_CONCURRENT_AUDIO = 36;

const buttonStyle = 'font-size:20px;padding:6px 10px;border:none;border-radius:6px;background:rgba(0,0,0,0.6);color:white;cursor:pointer;pointer-events:auto;';
const centralOverlayStyle = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;gap:10px;z-index:10;opacity:0;transition:opacity 0.2s;pointer-events:none;';

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

function addFileInfoOverlay(container, file) {
    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    // overlay.style.position = 'absolute';
    // overlay.style.bottom = '4px';
    // overlay.style.left = '4px';
    overlay.style.background = 'rgba(0,0,0,0.5)';
    overlay.style.color = 'white';
    overlay.style.padding = '2px 4px';
    overlay.style.fontSize = '16px';
    overlay.style.borderRadius = '4px';

    const ext = file.split('.').pop().toLowerCase();
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const isVideo = ['mp4','webm','mkv'].includes(ext);

    // if (isImage) {
    //     const img = new Image();
    //     img.onload = () => {
    //         overlay.innerHTML = `${file} (${img.width}×${img.height})`;
    //     };
    //     img.src = file; // or dataset.src if using lazy loading
    // } else if (isVideo) {
    //     const video = document.createElement('video');
    //     video.preload = 'metadata';
    //     video.onloadedmetadata = () => {
    //         overlay.innerHTML = `${file} (${video.videoWidth}×${video.videoHeight})`;
    //     };
    //     video.src = file;
    // } else {
    //     overlay.innerHTML = file; // for audio or unsupported files
    // }

    overlay.innerHTML = file;

    container.appendChild(overlay);
}

function addCentralOverlay(container, mediaEl, file) {
    const overlay = document.createElement('div');
    overlay.style.cssText = centralOverlayStyle + 'display:flex;justify-content:space-between;';

    // ===== Left: Multi-select / delete X button =====
    const selectBtn = document.createElement('button');
    selectBtn.innerHTML = '🗙';
    selectBtn.style.cssText = buttonStyle + 'background:gray;'; // GRAY by default
    selectBtn.dataset.file = file;
    selectBtn.dataset.selected = 'false';

    selectBtn.onclick = e => {
        e.stopPropagation();
        const selected = selectBtn.dataset.selected === 'true';
        if (!selected) {
            // First click → select
            selectBtn.dataset.selected = 'true';
            selectBtn.style.background = 'red';
        } else {
            // Second click → trigger delete
            const allSelectedBtns = document.querySelectorAll('#grid .video-container button[data-selected="true"]');
            const filesToDelete = Array.from(allSelectedBtns).map(b => b.dataset.file);
            if (!filesToDelete.length) return;
            if (!confirm(`Delete ${filesToDelete.length} file(s)?`)) return;

            fetch('index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete', files: filesToDelete })
            })
            .then(resp => resp.json())
            .then(data => {
                if (data.error) {
                    alert('Error deleting files: ' + data.error);
                    console.error(data);
                    return;
                }

                filesToDelete.forEach(f => {
                    const idx = allVideos.indexOf(f);
                    if (idx !== -1) allVideos.splice(idx,1);
                });
                startIndex = Math.min(startIndex, Math.max(0, allVideos.length - totalCells));
                renderGrid();
            })
            .catch(err => {
                console.error('Delete request failed', err);
                alert('Failed to delete files. See console for details.');
            });
        }
    };
    overlay.appendChild(selectBtn);

    // ===== Right: Fullscreen button (only for audio/video) =====
    const fsBtn = document.createElement('button');
    fsBtn.innerHTML = '⛶';
    fsBtn.style.cssText = buttonStyle;
    fsBtn.onclick = e => {
        e.stopPropagation();
        // Safely get currentTime only if mediaEl exists and has it
        const time = (mediaEl && typeof mediaEl.currentTime === 'number') ? mediaEl.currentTime : 0;
        startFullscreenFrom(file, time);
    };
    overlay.appendChild(fsBtn);

    if (mediaEl && (mediaEl.tagName === 'VIDEO' || mediaEl.tagName === 'AUDIO')) {
        // ===== Right: Mute/unmute button =====
        const muteBtn = document.createElement('button');
        muteBtn.innerHTML = mediaEl.muted ? '🔇' : '🔊';
        muteBtn.style.cssText = buttonStyle;
        muteBtn.onclick = e => {
            e.stopPropagation();
            document.querySelectorAll('#grid audio, #grid video').forEach(m => m !== mediaEl && (m.muted = true));
            document.querySelectorAll('#grid .video-container button:nth-child(3)').forEach(b => b.innerHTML = '🔇');
            mediaEl.muted = false;
            muteBtn.innerHTML = '🔊';
            lastFullscreen = { file: null, time: 0 };
            mediaEl.play().catch(() => {});
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
    const isLastFs = lastFullscreen.file === file;
    let mediaEl = null;

    if (isVideo) {
        mediaEl = document.createElement('video');
        mediaEl.loop = true;
        mediaEl.playsInline = true;
        mediaEl.preload = 'none';
        mediaEl.dataset.src = file;

        const visibleVideos = allVideos.slice(startIndex, startIndex + totalCells)
            .filter(f => ['mp4','webm','mkv'].includes(f.split('.').pop().toLowerCase()));
        mediaEl.muted = muted || (!isLastFs && visibleVideos[0] !== file);
        if (isLastFs) mediaEl.muted = false;

        container.appendChild(mediaEl);
    }
    else if (isAudio) {
        container.style.cssText = 'position:relative;display:flex;flex-direction:column;justify-content:center;align-items:center;';
        mediaEl = document.createElement('audio');
        mediaEl.controls = false;
        mediaEl.preload = 'metadata';
        mediaEl.loop = true;
        mediaEl.style.width = '100%';
        mediaEl.dataset.src = file;

        const visibleAudios = allVideos.slice(startIndex, startIndex + totalCells)
            .filter(f => ['mp3','wav','ogg'].includes(f.split('.').pop().toLowerCase()));
        const shouldUnmute = isLastFs || (!lastFullscreen.file && !muted && visibleAudios[0] === file);
        mediaEl.muted = !shouldUnmute;

        const img = document.createElement('img');
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;cursor:pointer;border-radius:8px;';
        img.dataset.src = audioThumbs[file] || 'cache/no-cover.jpg';
        img.onclick = () => startFullscreenFrom(file, mediaEl.currentTime);
        container.appendChild(img);
        container.appendChild(mediaEl);
        audioQueue.push(mediaEl);
    }
    else if (isImage) {
        const img = document.createElement('img');
        img.loading = 'lazy';
        img.decoding = 'async';
        img.dataset.src = file;
        // img.onclick = () => startFullscreenFrom(file);
        container.appendChild(img);
    }
    else {
        container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
    }

    addCentralOverlay(container, mediaEl, file);
    addFileInfoOverlay(container, file);

    if ((isVideo || isAudio) && isLastFs && lastFullscreen.time > 0) {
        mediaEl.addEventListener('loadedmetadata', () => { mediaEl.currentTime = lastFullscreen.time; }, { once: true });
    }

    return container;
}

function renderGrid() {
    const grid = document.getElementById('grid');
    grid.querySelectorAll('video, audio').forEach(m => { m.pause(); m.src = ''; m.load(); });
    grid.innerHTML = '';

    const visible = allVideos.slice(startIndex, Math.min(startIndex + totalCells, allVideos.length));
    if (lastFullscreen.file && !isFileVisible(lastFullscreen.file)) lastFullscreen = { file:null, time:0 };
    
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
        if (lastFullscreen.file) {
            const media = [...grid.querySelectorAll('audio, video')].find(m => m.src?.endsWith(lastFullscreen.file));
            if (media) { media.currentTime = lastFullscreen.time||0; media.muted=false; media.play().catch(()=>{}); return; }
        }
        grid.querySelectorAll('audio, video').forEach(m => !m.muted && m.play().catch(()=>{}));
    }, 100);

    document.getElementById('file-count').innerText = `${startIndex+1} / ${allVideos.length}`;
}

function nextGrid() { startIndex = (startIndex + totalCells) % allVideos.length; renderGrid(); }
function prevGrid() { startIndex = (startIndex - totalCells + allVideos.length) % allVideos.length; renderGrid(); }

function toggleMute() { muted = !muted; document.getElementById('mute-button').innerHTML = muted?'🔇':'🔊'; renderGrid(); }

function startFullscreenFrom(file,startTime=0){ fullscreenMode='tile'; document.querySelectorAll('#grid video, #grid audio').forEach(m=>m.pause()); lastFullscreen={file,time:startTime}; startFullscreenPlayer(allVideos,allVideos.indexOf(file),startTime); }

function createFullscreenMedia(playlist,i,startTime){
    const ext = playlist[i].split('.').pop().toLowerCase();
    const isAudio = ['mp3','wav','ogg'].includes(ext);
    const media = isAudio ? document.createElement('audio') : document.createElement('video');
    media.src = playlist[i];
    media.currentTime = startTime;
    if(!isAudio){ media.controls=true; media.loop=true; media.playsInline=true; media.style.cssText='width:100%;height:100%;object-fit:contain;'; } 
    else { media.controls=true; media.autoplay=true; media.style.cssText='width:100%;height:40px;'; }
    media.muted = muted;
    media.addEventListener('loadedmetadata',()=>media.play().catch(()=>{}),{once:true});
    return {media,isAudio};
}

function createFullscreenImage(playlist, index) {
    const src = playlist[index];
    const img = document.createElement('img');
    img.src = src;
    img.style.cssText = `
        max-width: 95vw;
        max-height: 92vh;
        object-fit: contain;
        border-radius: 8px;
        box-shadow: 0 0 40px rgba(0,0,0,0.6);
    `;
    return img;
}

function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
    if (!playlist.length) return;
    let i = index;
    const container = document.createElement('div');
    container.style.cssText =
        'position:fixed;top:0;left:0;width:100%;height:100%;background:black;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;';
    document.body.appendChild(container);

    const currentFile = playlist[i];
    const ext = currentFile.split('.').pop().toLowerCase();
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(ext);
    const isAudio = ['mp3','wav','ogg'].includes(ext);

    let media;
    let thumb = null;

    if (isImage) {
        media = document.createElement('img');
        media.src = currentFile;
        media.style.cssText = 'max-width:94vw;max-height:90vh;object-fit:contain;border-radius:6px;';
    } else {
        media = isAudio ? document.createElement('audio') : document.createElement('video');
        media.src = currentFile;
        media.currentTime = startTime;
        media.autoplay = true;
        media.playsInline = true;
        media.controls = true;
        media.muted = (fullscreenMode === 'playlist') ? muted : false;

        if (isAudio) {
            media.style.cssText = 'width:100%;height:40px;';
            thumb = document.createElement('img');
            thumb.src = audioThumbs[currentFile] || 'cache/no-cover.jpg';
            thumb.style.cssText = 'width:100%;height:100%;object-fit:contain;';
            container.appendChild(thumb);
        } else {
            media.style.cssText = 'width:100%;height:100%;object-fit:contain;';
        }

        media.addEventListener('loadedmetadata', () => media.play().catch(() => {}), {once:true});
    }

    container.appendChild(media);

    // === Main loop behavior decision ===
    const isSingleTileFullscreen = fullscreenMode === 'tile';
    
    media.loop = isSingleTileFullscreen && !isImage;  // loop both audio & video in tile mode

    if (!media.loop && !isImage) {
        media.onended = () => play(i + 1);
    }
    // ===================================

    function play(idx) {
        i = (idx + playlist.length) % playlist.length;
        const nextFile = playlist[i];
        const nextExt = nextFile.split('.').pop().toLowerCase();
        const nextIsImage = ['jpg','jpeg','png','gif','webp'].includes(nextExt);
        const nextIsAudio = ['mp3','wav','ogg'].includes(nextExt);

        if (nextIsImage) {
            container.innerHTML = '';
            media = document.createElement('img');
            media.src = nextFile;
            media.style.cssText = 'max-width:94vw;max-height:90vh;object-fit:contain;border-radius:6px;';
            container.appendChild(media);
            media.ondblclick = close;
        } else {
            // Clean previous media
            container.removeChild(media);
            if (thumb) {
                container.removeChild(thumb);
                thumb = null;
            }

            media = nextIsAudio ? document.createElement('audio') : document.createElement('video');
            media.src = nextFile;
            media.autoplay = true;
            media.playsInline = true;
            media.controls = true;
            media.muted = (fullscreenMode === 'playlist') ? muted : false;

            if (nextIsAudio) {
                media.style.cssText = 'width:100%;height:40px;';
                thumb = document.createElement('img');
                thumb.src = audioThumbs[nextFile] || 'cache/no-cover.jpg';
                thumb.style.cssText = 'width:100%;height:100%;object-fit:contain;';
                container.appendChild(thumb);
            } else {
                media.style.cssText = 'width:100%;height:100%;object-fit:contain;';
            }

            container.appendChild(media);

            // Re-apply same loop logic
            media.loop = isSingleTileFullscreen && !nextIsImage;
            if (!media.loop && !nextIsImage) {
                media.onended = () => play(i + 1);
            }

            media.play().catch(() => {});
        }
    }

    function close() {
        if (!isImage) {
            lastFullscreen.time = media.currentTime;
            if (!isAudio) lastFullscreen.file = playlist[i];
        } else {
            lastFullscreen.time = 0;
            lastFullscreen.file = playlist[i];
        }
        startIndex = Math.floor(allVideos.indexOf(playlist[i]) / totalCells) * totalCells;
        renderGrid();
        container.remove();
        document.removeEventListener('keydown', keyHandler);
    }

    media.ondblclick = close;
    if (thumb) thumb.ondblclick = close;

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

    const keyHandler = e => {
        if (e.key === 'Escape') close();
        if (e.key === 'Delete') {
            if (!confirm('Delete this file?')) return;
            const del = playlist[i];
            playlist.splice(i, 1);
            const globalIdx = allVideos.indexOf(del);
            if (globalIdx !== -1) allVideos.splice(globalIdx, 1);
            fetch('index.php?delete=' + encodeURIComponent(del));
            renderGrid();
            if (!playlist.length) close();
            else play(i % playlist.length);
        }
    };
    document.addEventListener('keydown', keyHandler);

    container.addEventListener('click', function(e) {
        if (e.target === container) {
            close();
        }
    });
}

function playAll(){ fullscreenMode='playlist'; document.querySelectorAll('#grid audio, #grid video').forEach(m=>m.pause()); startFullscreenPlayer(allVideos,startIndex); }
function shufflePlay(){ fullscreenMode='playlist'; document.querySelectorAll('#grid audio, #grid video').forEach(m=>m.pause()); startFullscreenPlayer([...allVideos].sort(()=>Math.random()-0.5),0); }

function runAudit(count) {
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'audit',
            path: <?= json_encode($selected_path ? $root_directory.'/'.$selected_path : $root_directory) ?>,
            count: count
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            alert('Audit failed: ' + data.error);
            return;
        }

        document.getElementById('audit-text').innerText = `[ ${data.text} ]`;
    })
    .catch(err => {
        console.error('Audit request failed', err);
        alert('Audit request failed');
    });
}

const grid=document.getElementById('grid'); let scrollDebounce=false;
grid.addEventListener('wheel',e=>{ e.preventDefault(); if(scrollDebounce) return; scrollDebounce=true; setTimeout(()=>scrollDebounce=false,200); e.deltaY<0?prevGrid():nextGrid(); },{passive:false});
let touchStartY=0;
grid.addEventListener('touchstart',e=>{ if(e.touches.length===1) touchStartY=e.touches[0].clientY; },{passive:true});
grid.addEventListener('touchend',e=>{ const delta=e.changedTouches[0].clientY-touchStartY; if(Math.abs(delta)>50) delta<0?nextGrid():prevGrid(); },{passive:true});

function setVhUnit(){ document.documentElement.style.setProperty('--vh',`${window.innerHeight*0.01}px`); }
setVhUnit(); window.addEventListener('resize',setVhUnit); window.addEventListener('orientationchange',setVhUnit);

renderGrid();
</script>
</body>
</html>