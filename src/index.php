<?php
// =====================
// CONFIG / SETTINGS
// =====================
$root_directory = './volumes';
$root_directory_absolute = realpath($root_directory);

if (!$root_directory_absolute) {
    die('Root directory not found');
}

$is_mobile = stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Mobile') !== false
          || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'Android') !== false;

// GET parameters
$selected_path_parts = $_GET['selected-path'] ?? [];
if (!is_array($selected_path_parts)) {
    $selected_path_parts = [];
}
$selected_path_parts = array_values(array_filter($selected_path_parts, 'strlen')); // remove empty

$selected_path = implode('/', $selected_path_parts);

$selected_columns = $is_mobile ? 1 : max(1, min(6, (int)($_GET['columns'] ?? 3)));
$selected_rows    = $is_mobile ? 1 : max(1, min(6, (int)($_GET['rows'] ?? 2)));
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || $_GET['muted'] === 'true';
$audited = !empty($_GET['audited']);
$fileCount = (int)($_GET['fileCount'] ?? 0);
$delete_file = !empty($_GET['delete']) ? rawurldecode($_GET['delete']) : null;

// =====================
// HELPER FUNCTIONS
// =====================
function getSubfolders(string $path): array {
    if (!is_dir($path)) return [];
    $dirs = array_filter(scandir($path), fn($d) => $d !== '.' && $d !== '..' && is_dir($path . '/' . $d));
    sort($dirs, SORT_STRING);
    return array_values($dirs);
}

function getFiles(string $path): array {
    if (!is_dir($path)) return [];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $file = $fileInfo->getPathname();
        $ext = strtolower($fileInfo->getExtension());

        if (in_array($ext, ['backup', 'original'])) continue;

        $files[] = $file;
    }

    // Sort newest first
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

function filesystemToWebPath(string $fsPath, string $rootFs, string $rootWeb): string {
    $fsPath = str_replace('\\', '/', realpath($fsPath));
    $rootFs = str_replace('\\', '/', $rootFs);

    if (str_starts_with($fsPath, $rootFs)) {
        $relative = substr($fsPath, strlen($rootFs));
    } else {
        $relative = $fsPath;
    }

    return $rootWeb . '/' . ltrim($relative, '/');
}

// =====================
// DELETE ACTION
// =====================
if ($delete_file && file_exists($delete_file)) {
    $trashDirectory = '/tmp/4cg-trash';
    if (!is_dir($trashDirectory)) {
        mkdir($trashDirectory, 0777, true);
    }
    $safeName = uniqid() . '_' . basename($delete_file);
    rename($delete_file, $trashDirectory . '/' . $safeName);
    exit;
}

// =====================
// PATH INITIALIZATION
// =====================
$current_path = $root_directory_absolute . ($selected_path ? '/' . $selected_path : '');
$current_path = realpath($current_path) ?: $root_directory_absolute;

if (!str_starts_with($current_path, $root_directory_absolute)) {
    // Security: prevent traversal outside root
    $current_path = $root_directory_absolute;
    $selected_path_parts = [];
    $selected_path = '';
}

// Audit file
$auditFile = $current_path . '/.audited';
$auditedText = is_file($auditFile) ? trim(file_get_contents($auditFile)) : '';

// =====================
// FILE COLLECTION & WEB PATHS
// =====================
$allFilesRaw = getFiles($current_path);

$webRoot = '/' . trim($root_directory, './');
$allFiles = array_map(fn($file) => filesystemToWebPath($file, $root_directory_absolute, $webRoot), $allFilesRaw);

// =====================
// AUDIO COVER GENERATION
// =====================
require_once __DIR__ . '/lib/audioCovers.php';
$audioThumbsRaw = generateAudioCovers($allFilesRaw);

$audioThumbs = [];
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

foreach ($audioThumbsRaw as $audioFs => $thumbFs) {
    $audioWeb = filesystemToWebPath($audioFs, $root_directory_absolute, $webRoot);
    $thumbWeb = $docRoot ? '/' . ltrim(str_replace('\\', '/', str_replace($docRoot, '', realpath($thumbFs))), '/') : '';
    $audioThumbs[$audioWeb] = $thumbWeb ?: 'cache/no-cover.jpg';
}

// =====================
// AUDIT ACTION
// =====================
if ($audited) {
    file_put_contents($auditFile, date('Y-m-d H:i:s') . " / $fileCount" . PHP_EOL);
    $redirect = 'index.php?selected-path=' . implode('&selected-path=', array_map('urlencode', $selected_path_parts))
              . "&columns=$selected_columns&rows=$selected_rows";
    header("Location: $redirect");
    exit;
}

// =====================
// BREADCRUMB PATHS
// =====================
$path_parts = $selected_path_parts;
$subfolders = getSubfolders($current_path);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Media Grid</title>
<style>
html, body { margin:0; padding:0; height:100%; overflow:hidden; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#121212; color:#f0f0f0; }
a { text-decoration:none; color:#1e90ff; }

#form { padding:12px 20px; background:#1f1f1f; display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:10px; border-bottom:1px solid #333; }
#options-form select, #options-form button { padding:6px 10px; border-radius:6px; border:none; background:#2c2c2c; color:#f0f0f0; font-size:14px; cursor:pointer; transition:0.2s; }
#options-form select:hover, #options-form button:hover { background:#3a3a3a; }
#file-count, #audit-text { font-weight:bold; margin:0 10px; }

#folder-select-container { display: inline-flex; gap: 6px; align-items: center; }
#folder-select-container select {
    max-width: 180px; min-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

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

  <div id="folder-select-container">
  <?php
  $parent = '';
  foreach ($path_parts as $level => $part) {
      $folderPath = $root_directory_absolute . ($parent ? '/' . $parent : '');
      $subs = getSubfolders($folderPath);
      echo '<select name="selected-path[]" onchange="this.form.submit()">';
      echo '<option value="">[Select]</option>';
      foreach ($subs as $f) {
          $selected = ($f === $part) ? ' selected' : '';
          echo "<option value=\"$f\"$selected>$f</option>";
      }
      echo '</select>';
      $parent .= ($parent ? '/' : '') . $part;
  }

  if (!empty($subfolders)) {
      echo '<select name="selected-path[]" onchange="this.form.submit()">';
      echo '<option value="">[Select]</option>';
      foreach ($subfolders as $f) {
          echo "<option value=\"$f\">$f</option>";
      }
      echo '</select>';
  }
  ?>
  </div>

  <select name="columns" onchange="this.form.submit()">
    <?php for ($c = 1; $c <= 6; $c++): $sel = ($c == $selected_columns) ? ' selected' : ''; ?>
      <option value="<?= $c ?>"<?= $sel ?>><?= $c ?></option>
    <?php endfor; ?>
  </select>

  <select name="rows" onchange="this.form.submit()">
    <?php for ($r = 1; $r <= 6; $r++): $sel = ($r == $selected_rows) ? ' selected' : ''; ?>
      <option value="<?= $r ?>"<?= $sel ?>><?= $r ?></option>
    <?php endfor; ?>
  </select>

  <input type="hidden" name="muted" value="<?= $muted ? 'true' : 'false' ?>">

  <button type="button" id="mute-button" onclick="toggleMute()"><?= $muted ? '🔇' : '🔊' ?></button>
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
const allVideos = <?= json_encode($allFiles, JSON_UNESCAPED_SLASHES) ?>;
const audioThumbs = <?= json_encode($audioThumbs, JSON_UNESCAPED_SLASHES) ?>;
let muted = <?= $muted ? 'true' : 'false' ?>;
const totalCells = <?= $total_cells ?>;
let startIndex = 0;

let lastFullscreenAudio = null, lastFullscreenTime = 0;
let lastFullscreenVideo = null, lastFullscreenVideoTime = 0;
let fullscreenMode = 'tile';

const audioQueue = [];
let activeAudioLoads = 0;
const MAX_CONCURRENT_AUDIO = 36;

function processAudioQueue() {
    while (activeAudioLoads < MAX_CONCURRENT_AUDIO && audioQueue.length > 0) {
        const audio = audioQueue.shift();
        if (!audio?.dataset?.src) continue;

        activeAudioLoads++;
        const src = audio.dataset.src;
        delete audio.dataset.src;
        audio.src = src;
        audio.load();

        const done = () => {
            activeAudioLoads = Math.max(0, activeAudioLoads - 1);
            processAudioQueue();
        };
        audio.addEventListener('loadedmetadata', done, { once: true });
        audio.addEventListener('error', done, { once: true });
    }
}

function isFileVisible(file) {
    const end = Math.min(startIndex + totalCells, allVideos.length);
    return allVideos.slice(startIndex, end).includes(file);
}

function renderGrid() {
    const grid = document.getElementById('grid');
    grid.querySelectorAll('video, audio').forEach(m => { m.pause(); m.src = ''; m.load(); });
    grid.innerHTML = '';

    const endIndex = Math.min(startIndex + totalCells, allVideos.length);
    const visible = allVideos.slice(startIndex, endIndex);

    if (lastFullscreenAudio && !isFileVisible(lastFullscreenAudio)) {
        lastFullscreenAudio = null;
        lastFullscreenTime = 0;
    }

    visible.forEach(file => {
        const container = document.createElement('div');
        container.className = 'video-container';

        const ext = file.split('.').pop().toLowerCase();

        if (['mp4','webm','mkv'].includes(ext)) {
            const video = document.createElement('video');
            video.loop = true;
            video.playsInline = true;
            video.preload = 'none';
            video.dataset.src = file;

            const isLastFs = lastFullscreenVideo === file;
            if (isLastFs) {
                video.currentTime = lastFullscreenVideoTime;
                video.muted = false;
            } else {
                const visibleVideos = visible.filter(f => ['mp4','webm','mkv'].includes(f.split('.').pop().toLowerCase()));
                video.muted = muted || visibleVideos[0] !== file;
            }

            const overlay = document.createElement('div');
            overlay.style.cssText = 'position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;gap:10px;z-index:10;opacity:0;transition:opacity 0.2s;pointer-events:none;';

            const fsBtn = document.createElement('button');
            fsBtn.innerHTML = '⛶';
            fsBtn.style.cssText = 'font-size:20px;padding:6px 10px;border:none;border-radius:6px;background:rgba(0,0,0,0.6);color:white;cursor:pointer;pointer-events:auto;';
            fsBtn.onclick = e => { e.stopPropagation(); startFullscreenFrom(file, video.currentTime); };
            overlay.appendChild(fsBtn);

            const unmuteBtn = document.createElement('button');
            unmuteBtn.innerHTML = video.muted ? '🔇' : '🔊';
            unmuteBtn.style.cssText = fsBtn.style.cssText;
            unmuteBtn.onclick = e => {
                e.stopPropagation();
                document.querySelectorAll('#grid audio, #grid video').forEach(m => m !== video && (m.muted = true));
                document.querySelectorAll('#grid .video-container button:nth-child(2)').forEach(b => b.innerHTML = '🔇');
                video.muted = false;
                video.play().catch(() => {});
                lastFullscreenAudio = null;
                lastFullscreenTime = 0;
                unmuteBtn.innerHTML = '🔊';
            };
            overlay.appendChild(unmuteBtn);

            container.appendChild(video);
            container.appendChild(overlay);

            container.addEventListener('mouseenter', () => overlay.style.opacity = '1');
            container.addEventListener('mouseleave', () => overlay.style.opacity = '0');
        }

        else if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            const img = document.createElement('img');
            img.loading = 'lazy';
            img.decoding = 'async';
            img.dataset.src = file;
            img.ondblclick = () => startFullscreenFrom(file);
            container.appendChild(img);
        }

        else if (['mp3','wav','ogg'].includes(ext)) {
            container.style.cssText = 'display:flex;flex-direction:column;justify-content:center;align-items:center;';

            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.loop = true;
            audio.style.width = '100%';
            audio.dataset.src = file;

            const isLastFs = lastFullscreenAudio === file;
            if (isLastFs) {
                audio.muted = false;
            } else if (!lastFullscreenAudio && !muted) {
                const visibleAudios = visible.filter(f => ['mp3','wav','ogg'].includes(f.split('.').pop().toLowerCase()));
                audio.muted = visibleAudios[0] !== file;
            } else {
                audio.muted = true;
            }

            const img = document.createElement('img');
            img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
            img.dataset.src = audioThumbs[file] || 'cache/no-cover.jpg';
            img.onclick = () => startFullscreenFrom(file, audio.currentTime);
            container.appendChild(img);
            container.appendChild(audio);

            audio.addEventListener('volumechange', () => {
                if (!audio.muted) {
                    document.querySelectorAll('#grid audio, #grid video').forEach(m => m !== audio && (m.muted = true));
                    lastFullscreenAudio = null;
                    lastFullscreenTime = 0;
                    audio.play().catch(() => {});
                }
            });

            if (isLastFs && lastFullscreenTime > 0) {
                audio.addEventListener('loadedmetadata', () => {
                    audio.currentTime = lastFullscreenTime;
                }, { once: true });
            }
        }

        else {
            container.innerHTML = `<div style="color:red;padding:4px;">Unsupported: ${file}</div>`;
        }

        const fileOverlay = document.createElement('div');
        fileOverlay.className = 'overlay';
        fileOverlay.innerHTML = `<span>${file}</span><button onclick="deleteGridFile('${file}')">Delete</button>`;
        container.appendChild(fileOverlay);

        grid.appendChild(container);
    });

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            const el = entry.target;
            if (el.dataset.src) {
                if (el.tagName === 'AUDIO') {
                    audioQueue.push(el);
                    processAudioQueue();
                } else {
                    el.src = el.dataset.src;
                    if (el.tagName === 'VIDEO') el.play().catch(() => {});
                }
                delete el.dataset.src;
            }
            observer.unobserve(el);
        });
    }, { threshold: 0.01 });

    setTimeout(() => {
        const audios = grid.querySelectorAll('audio');
        if (lastFullscreenAudio) {
            const audio = [...audios].find(a => a.src.endsWith(lastFullscreenAudio));
            if (audio) {
                audio.currentTime = lastFullscreenTime || 0;
                audio.muted = false;
                audio.play().catch(() => {});
                return;
            }
        }
        const toPlay = [...audios].find(a => !a.muted);
        if (toPlay) toPlay.play().catch(() => {});
    }, 100);

    grid.querySelectorAll('video, audio, img[data-src]').forEach(el => observer.observe(el));
    document.getElementById('file-count').innerText = `${startIndex + 1} / ${allVideos.length}`;
}

function nextGrid() { startIndex = (startIndex + totalCells) % allVideos.length; renderGrid(); }
function prevGrid() { startIndex = (startIndex - totalCells + allVideos.length) % allVideos.length; renderGrid(); }

function deleteGridFile(file) {
    if (!confirm('Delete this file?')) return;
    const idx = allVideos.indexOf(file);
    if (idx !== -1) allVideos.splice(idx, 1);
    fetch('index.php?delete=' + encodeURIComponent(file));
    if (startIndex >= allVideos.length) startIndex = Math.max(0, allVideos.length - totalCells);
    renderGrid();
}

function toggleMute() {
    muted = !muted;
    document.getElementById('mute-button').innerHTML = muted ? '🔇' : '🔊';
    renderGrid();
}

function startFullscreenFrom(file, startTime = 0) {
    fullscreenMode = 'tile';
    document.querySelectorAll('#grid video, #grid audio').forEach(m => m.pause());

    const ext = file.split('.').pop().toLowerCase();
    if (['mp3','wav','ogg'].includes(ext)) {
        lastFullscreenAudio = file;
        lastFullscreenTime = startTime;
    } else if (['mp4','webm','mkv'].includes(ext)) {
        lastFullscreenVideo = file;
        lastFullscreenVideoTime = startTime;
    }

    startFullscreenPlayer(allVideos, allVideos.indexOf(file), startTime);
}

function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
    if (!playlist.length) return;
    let i = index;

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:black;display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:9999;';
    document.body.appendChild(container);

    const ext = playlist[i].split('.').pop().toLowerCase();
    let media, thumb;

    if (['mp4','webm','mkv'].includes(ext)) {
        media = document.createElement('video');
        media.controls = true;
        media.loop = true;
        media.playsInline = true;
        media.muted = muted;
        media.style.cssText = 'width:100%;height:100%;object-fit:contain;';
        media.src = playlist[i];
        media.currentTime = startTime;
        media.addEventListener('loadedmetadata', () => media.play().catch(() => {}), { once: true });
        container.appendChild(media);
    } else if (['mp3','wav','ogg'].includes(ext)) {
        media = document.createElement('audio');
        media.controls = true;
        media.autoplay = true;
        media.muted = muted;
        media.style.cssText = 'width:100%;height:40px;';
        media.src = playlist[i];
        media.currentTime = startTime;

        thumb = document.createElement('img');
        thumb.src = audioThumbs[playlist[i]] || 'cache/no-cover.jpg';
        thumb.style.cssText = 'width:100%;height:100%;object-fit:contain;';
        container.appendChild(thumb);
        container.appendChild(media);
    }

    function play(idx) {
        i = (idx + playlist.length) % playlist.length;
        const nextFile = playlist[i];
        media.src = nextFile;
        media.play().catch(() => {});
        if (thumb) thumb.src = audioThumbs[nextFile] || 'cache/no-cover.jpg';
        if (['mp3','wav','ogg'].includes(nextFile.split('.').pop().toLowerCase())) {
            lastFullscreenAudio = nextFile;
        }
    }

    function close() {
        const currentExt = playlist[i].split('.').pop().toLowerCase();
        if (['mp3','wav','ogg'].includes(currentExt)) {
            lastFullscreenTime = media.currentTime;
        } else {
            lastFullscreenVideoTime = media.currentTime;
            lastFullscreenVideo = playlist[i];
        }

        startIndex = Math.floor(allVideos.indexOf(playlist[i]) / totalCells) * totalCells;
        renderGrid();
        container.remove();
        document.removeEventListener('keydown', keyHandler);
    }

    media.ondblclick = close;
    if (thumb) thumb.ondblclick = close;

    if (fullscreenMode === 'tile' && ['mp3','wav','ogg'].includes(ext)) {
        media.loop = true;
    } else {
        media.loop = false;
        media.onended = () => play(i + 1);
    }

    container.addEventListener('wheel', e => { e.preventDefault(); e.deltaY > 0 ? play(i + 1) : play(i - 1); }, { passive: false });
    let touchY = 0;
    container.addEventListener('touchstart', e => { if (e.touches.length === 1) touchY = e.touches[0].clientY; }, { passive: true });
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
            if (playlist.length === 0) close();
            else play(i % playlist.length);
        }
    };
    document.addEventListener('keydown', keyHandler);
}

function playAll() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    startFullscreenPlayer(allVideos, startIndex);
}

function shufflePlay() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    const shuffled = [...allVideos].sort(() => Math.random() - 0.5);
    startFullscreenPlayer(shuffled, 0);
}

function runAudit(count) {
    const url = new URL(location);
    url.searchParams.set('audited', 'true');
    url.searchParams.set('fileCount', count);
    location.href = url;
}

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
grid.addEventListener('touchstart', e => { if (e.touches.length === 1) touchStartY = e.touches[0].clientY; }, { passive: true });
grid.addEventListener('touchend', e => {
    const delta = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(delta) > 50) delta < 0 ? nextGrid() : prevGrid();
}, { passive: true });

renderGrid();

function setVhUnit() {
    document.documentElement.style.setProperty('--vh', `${window.innerHeight * 0.01}px`);
}
setVhUnit();
window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);
</script>
</body>
</html>