<?php
// =====================
// CONFIG / SETTINGS
// =====================
$root_directory = './volumes';
$root_directory_absolute = getcwd() . '/volumes';

$is_mobile = stripos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false || stripos($_SERVER['HTTP_USER_AGENT'], 'Android') !== false;

// GET parameters
$selected_path_parts = $_GET['selected-path'] ?? [];

if (!is_array($selected_path_parts)) {
    $selected_path_parts = [];
}

// remove empty values (from "[Select]")
$selected_path_parts = array_values(array_filter($selected_path_parts));

// build string path
$selected_path = implode('/', $selected_path_parts);

$selected_columns = $is_mobile ? 1 : ($_GET['columns'] ?? 3);
$selected_rows = $is_mobile ? 1 : ($_GET['rows'] ?? 2);
$total_cells = $selected_columns * $selected_rows;

$muted = !isset($_GET['muted']) || ($_GET['muted'] === 'true');
$audited = $_GET['audited'] ?? false;
$fileCount = $_GET['fileCount'] ?? 0;
$delete_file = isset($_GET['delete']) ? rawurldecode($_GET['delete']) : null;

// =====================
// HELPER FUNCTIONS
// =====================
function getSubfolders($path) {
    if (!is_dir($path)) return [];
    $dirs = [];
    foreach (glob($path . '/*', GLOB_ONLYDIR) as $dir) {
        $dirs[] = basename($dir);
    }
    sort($dirs);
    return $dirs;
}

function getFiles($path) {
    if (!is_dir($path)) return [];

    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $path,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $file = $fileInfo->getPathname();

        if (
            str_ends_with($file, '.backup') ||
            str_ends_with($file, '.original')
        ) {
            continue;
        }

        $files[] = $file;
    }

    // newest first
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

    return $files;
}

// =====================
// DELETE ACTION
// =====================
if ($delete_file && file_exists($delete_file)) {
    $trashDirectory = '/tmp/4cg-trash';
    if (!file_exists($trashDirectory)) mkdir($trashDirectory, 0777, true);
    rename($delete_file, $trashDirectory . '/' . uniqid() . '_' . basename($delete_file));
    exit;
}

// =====================
// PATH INITIALIZATION
// =====================
$current_path = $root_directory . ($selected_path ? '/' . $selected_path : '');
$folders = getSubfolders($current_path);

// Audit file
$auditFile = "$current_path/.audited";
$auditedText = file_exists($auditFile) ? trim(file_get_contents($auditFile)) : '';

// =====================
// FILE COLLECTION
// =====================
$allFilesRaw = getFiles($current_path);

// Convert filesystem paths to web URLs relative to site root
$allFiles = array_map(function($file) use ($root_directory) {
    $relative = str_replace('\\','/', $file);
    if (str_starts_with($relative, $root_directory)) {
        $relative = substr($relative, strlen($root_directory));
    }
    $relative = ltrim($relative, '/'); 
    return '/'.$root_directory.'/'.$relative;
}, $allFilesRaw);

// =====================
// AUDIO COVER GENERATION
// =====================
require_once __DIR__ . '/lib/audioCovers.php';
$audioThumbsRaw = generateAudioCovers($allFilesRaw);

$audioThumbs = [];

$docRoot = realpath($_SERVER['DOCUMENT_ROOT']);

foreach ($audioThumbsRaw as $audioFsPath => $thumbFsPath) {

    // ---- audio file: always under /volumes ----
    $audioWeb = str_replace('\\', '/', $audioFsPath);
    $audioWeb = str_replace(realpath(getcwd()), '', $audioWeb);
    $audioWeb = '/' . ltrim($audioWeb, '/');

    // ---- thumb file: may live OUTSIDE /volumes ----
    $thumbWeb = str_replace('\\', '/', $thumbFsPath);
    $thumbWeb = str_replace($docRoot, '', $thumbWeb);
    $thumbWeb = '/' . ltrim($thumbWeb, '/');

    $audioThumbs[$audioWeb] = $thumbWeb;
}

// =====================
// AUDIT ACTION
// =====================
if ($audited) {
    file_put_contents($auditFile, date('Y-m-d H:i:s') . " / $fileCount" . PHP_EOL);
    header("Location: index.php?selected-path=" . urlencode($selected_path) . "&columns=$selected_columns&rows=$selected_rows");
    exit;
}

// =====================
// BREADCRUMB PATHS
// =====================
$path_parts = $selected_path_parts;

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
    max-width: 180px;          /* adjust to taste */
    min-width: 120px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
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
    #grid { 
        display:grid; 
        grid-template-columns: repeat(<?php echo $selected_columns; ?>,1fr); 
        grid-template-rows: repeat(<?php echo $selected_rows; ?>,1fr); 
        gap:8px; 
        padding:10px; 
        height:calc(100% - 72px); 
    }
}
</style>
</head>
<body>

<div id="form">
<form id="options-form" method="get" action="index.php">
  <span id="file-count"><?php echo 1; ?> / <?php echo count($allFiles); ?></span>

  <div id="folder-select-container">
  <?php
  // =====================
  // PHP Folder Selects
  // =====================
  $parent = '';
  foreach ($path_parts as $level => $part) {
      $subfolders = getSubfolders($root_directory . ($parent ? '/' . $parent : ''));
      echo '<select name="selected-path[]" onchange="this.form.submit()">';
      echo '<option value="">[Select]</option>';
      foreach ($subfolders as $f) {
          $sel = ($f === $part) ? ' selected' : '';
          echo "<option value=\"$f\"$sel>$f</option>";
      }
      echo '</select>';
      $parent .= ($parent ? '/' : '') . $part;
  }

  // Add next level select if current folder has subfolders
  $subfolders = getSubfolders($current_path);
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

    <!-- Columns select -->
    <!-- <label for="columns">Columns:</label> -->
    <select name="columns" id="columns" onchange="this.form.submit()">
    <?php
    for ($c = 1; $c <= 6; $c++) {
        $sel = ($c == $selected_columns) ? ' selected' : '';
        echo "<option value=\"$c\"$sel>$c</option>";
    }
    ?>
    </select>

    <!-- Rows select -->
    <!-- <label for="rows">Rows:</label> -->
    <select name="rows" id="rows" onchange="this.form.submit()">
    <?php
    for ($r = 1; $r <= 6; $r++) {
        $sel = ($r == $selected_rows) ? ' selected' : '';
        echo "<option value=\"$r\"$sel>$r</option>";
    }
    ?>
    </select>

  <input type="hidden" name="muted" value="<?php echo $muted ? 'true':'false'; ?>">

  <button type="button" id="mute-button" onclick="toggleMute()"><?php echo $muted ? '🔇':'🔊'; ?></button>
  <button type="button" onclick="playAll()">▶</button>
  <button type="button" onclick="shufflePlay()">🔀</button>
  <button type="button" id="refresh" onclick="window.location.reload()">🔄</button>
  <button type="button" id="clear" onclick="window.location.href='index.php'">🧹</button>
  <button type="button" id="audit" onclick="runAudit(<?php echo count($allFiles); ?>)">📝</button>
  <button type="button" id="previous" onclick="prevGrid()">◀</button>
  <button type="button" id="next" onclick="nextGrid()">▶</button>

  <span id="audit-text">[ <?php echo htmlspecialchars($auditedText); ?> ]</span>
</form>
</div>

<div id="grid"></div>

<script>
let allVideos = <?php echo json_encode($allFiles, JSON_UNESCAPED_SLASHES); ?>;
let audioThumbs = <?php echo json_encode($audioThumbs, JSON_UNESCAPED_SLASHES); ?>;
let muted = <?php echo $muted ? 'true':'false'; ?>;
let totalCells = <?php echo $total_cells; ?>;
let startIndex = 0;

// Track fullscreen state
let lastFullscreenAudio = null;
let lastFullscreenTime = 0;
let lastFullscreenVideo = null;
let lastFullscreenVideoTime = 0;

let fullscreenMode = 'tile';

// Audio loading queue to avoid browser concurrency stalls
let audioQueue = [];
let activeAudioLoads = 0;
const MAX_CONCURRENT_AUDIO = 36;  // Safe for Firefox

function processAudioQueue() {
    while (activeAudioLoads < MAX_CONCURRENT_AUDIO && audioQueue.length > 0) {
        const audio = audioQueue.shift();

        if (!audio?.dataset?.src) continue;

        activeAudioLoads++;

        const src = audio.dataset.src;
        delete audio.dataset.src;

        audio.src = src;

        // 🔥 THIS IS THE FIX
        audio.load();

        // audio.play().catch(() => {});

        const done = () => {
            activeAudioLoads = Math.max(0, activeAudioLoads - 1);
            processAudioQueue();
        };

        audio.addEventListener('loadedmetadata', done, { once: true });
        audio.addEventListener('error', done, { once: true });
    }
}

// Helper
function isFileVisible(file) {
    const endIndex = Math.min(startIndex + totalCells, allVideos.length);
    const visibleFiles = allVideos.slice(startIndex, endIndex);
    return visibleFiles.includes(file);
}

// --------------------
// Render grid
// --------------------
function renderGrid() {
    const grid = document.getElementById('grid');

    const oldMedia = grid.querySelectorAll('video, audio');
    oldMedia.forEach(m => { m.pause(); m.src = ''; m.load(); });

    grid.innerHTML = '';

    const endIndex = Math.min(startIndex + totalCells, allVideos.length);
    const visible = allVideos.slice(startIndex, endIndex);

    // Clear lastFullscreen if no longer visible
    if (lastFullscreenAudio && !isFileVisible(lastFullscreenAudio)) {
        lastFullscreenAudio = null;
        lastFullscreenTime = 0;
    }

    visible.forEach((file) => {
        const container = document.createElement('div');
        container.className = 'video-container';

        const ext = file.split('.').pop().toLowerCase();

        if (['mp4','webm','mkv'].includes(ext)) {
            const video = document.createElement('video');
            video.loop = true;
            video.playsInline = true;
            video.preload = 'none';
            video.dataset.src = file;
            video.style.width = '100%';
            video.style.height = '100%';
            video.style.objectFit = 'contain';
            container.appendChild(video);

            const isLastFullscreenVideo = (lastFullscreenVideo === file);
            if (isLastFullscreenVideo) {
                video.currentTime = lastFullscreenVideoTime;
                video.muted = false;
                video.play().catch(() => {});
            } else if (!muted) {
                const visibleVideos = visible.filter(f => ['mp4','webm','mkv'].includes(f.split('.').pop().toLowerCase()));
                video.muted = visibleVideos[0] !== file; // only first visible unmuted
            } else {
                video.muted = true;
            }

            // Overlay & unmute button logic remains as you have it
            const overlay = document.createElement('div');
            overlay.style.cssText = `
                position:absolute;
                top:50%; left:50%;
                transform:translate(-50%,-50%);
                display:flex;
                gap:10px;
                z-index:10;
                opacity:0;
                transition:opacity 0.2s;
                pointer-events:none;
            `;

            // Fullscreen button
            const fsBtn = document.createElement('button');
            fsBtn.innerHTML = '⛶';
            fsBtn.style.cssText = `
                font-size:20px;
                padding:6px 10px;
                border:none;
                border-radius:6px;
                background:rgba(0,0,0,0.6);
                color:white;
                cursor:pointer;
                pointer-events:auto;
            `;
            fsBtn.onclick = (e) => {
                e.stopPropagation();
                startFullscreenFrom(file, video.currentTime);
            };
            overlay.appendChild(fsBtn);

            // Overlay unmute button
            const unmuteBtn = document.createElement('button');
            unmuteBtn.innerHTML = video.muted ? '🔇' : '🔊';
            unmuteBtn.style.cssText = fsBtn.style.cssText;
            unmuteBtn.onclick = (e) => {
                e.stopPropagation();

                // Mute all other audios/videos
                document.querySelectorAll('#grid audio, #grid video').forEach(m => {
                    if (m !== video) m.muted = true;
                });

                // Reset all unmute buttons icons
                document.querySelectorAll('#grid .video-container').forEach(vc => {
                    const btn = vc.querySelector('button:nth-child(2)'); // second button = unmute
                    if (btn) btn.innerHTML = '🔇';
                });

                // Unmute this video
                video.muted = false;
                video.play().catch(() => {});
                lastFullscreenAudio = null;
                lastFullscreenTime = 0;

                // Update this video's icon
                unmuteBtn.innerHTML = '🔊';
            };
            overlay.appendChild(unmuteBtn);

            container.appendChild(overlay);

            container.addEventListener('mouseenter', () => {
                overlay.style.opacity = '1';
                overlay.style.pointerEvents = 'auto';
            });
            container.addEventListener('mouseleave', () => {
                overlay.style.opacity = '0';
                overlay.style.pointerEvents = 'none';
            });
        }
        
        else if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            const img = document.createElement('img');
            img.loading = 'lazy';
            img.decoding = 'async';
            img.dataset.src = file;
            img.ondblclick = () => startFullscreenFrom(file);
            container.appendChild(img);

        } else if (['mp3','wav','ogg'].includes(ext)) {
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.justifyContent = 'center';
            container.style.alignItems = 'center';

            const audio = document.createElement('audio');
            audio.controls = true;
            audio.preload = 'metadata';
            audio.loop = true;
            audio.style.width = '100%';
            audio.dataset.src = file;

            const isLastFullscreen = (lastFullscreenAudio === file);

            // Decide sound: only one audio unmuted
            if (isLastFullscreen) {
                audio.muted = false;
            } else if (lastFullscreenAudio === null && !muted) {
                const visibleAudios = visible.filter(f => ['mp3','wav','ogg'].includes(f.split('.').pop().toLowerCase()));
                const isFirstAudio = visibleAudios[0] === file;
                audio.muted = !isFirstAudio;
            } else {
                audio.muted = true;
            }

            const img = document.createElement('img');
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.objectFit = 'cover';
            img.dataset.src = audioThumbs[file] ?? 'cache/no-cover.jpg';
            img.onclick = () => startFullscreenFrom(file, audio.currentTime);
            container.appendChild(img);
            container.appendChild(audio);

            // Manual unmute → take control
            audio.addEventListener('volumechange', () => {
                if (!audio.muted) {
                    // Mute all other audios
                    document.querySelectorAll('#grid audio, #grid video').forEach(m => {
                        if (m !== audio) m.muted = true;
                    });

                    // Clear fullscreen tracking
                    lastFullscreenAudio = null;
                    lastFullscreenTime = 0;

                    // Play this audio immediately
                    audio.play().catch(() => {});
                }
            });

            const setupAfterLoad = () => {
                if (isLastFullscreen && lastFullscreenTime > 0) {
                    audio.currentTime = lastFullscreenTime;
                }
                // audio.play().catch(() => {});
            };

            audio.dataset.setupPending = isLastFullscreen ? 'true' : 'false';
            audio.addEventListener('loadedmetadata', setupAfterLoad, { once: true });

        } else {
            container.innerHTML = `<div style="color:red; padding:4px;">Unsupported: ${file}</div>`;
        }

        const overlay = document.createElement('div');
        overlay.className = 'overlay';
        overlay.innerHTML = `<span>${file}</span> <button onclick="deleteGridFile('${file}')">Delete</button>`;
        container.appendChild(overlay);

        grid.appendChild(container);
    });

    // Lazy loading + autoplay
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const el = entry.target;
                const tag = el.tagName.toLowerCase();

                if (el.dataset.src) {
                    if (tag === 'audio' && el.dataset.src) {
                        audioQueue.push(el);
                        processAudioQueue();
                    } else if (tag === 'video') {
                        el.src = el.dataset.src;
                        el.play().catch(() => {});
                    } else if (tag === 'img') {
                        el.src = el.dataset.src;
                    }
                    delete el.dataset.src;
                }

                // Restore time for returning fullscreen audio
                if (tag === 'audio' && el.dataset.setupPending === 'true') {
                    el.addEventListener('canplay', () => {
                        if (lastFullscreenAudio === el.src && lastFullscreenTime > 0) {
                            el.currentTime = lastFullscreenTime;
                        }
                    }, { once: true });
                }

                observer.unobserve(el);
            }
        });
    }, { root: null, threshold: 0.01 });

    // Auto-play logic for grid
    setTimeout(() => {
        const audios = Array.from(grid.querySelectorAll('audio'));
        let audioToPlay = null;

        // 1️⃣ If returning from fullscreen, resume that audio
        if (lastFullscreenAudio) {
            audioToPlay = audios.find(a => a.src.endsWith(lastFullscreenAudio));
            if (audioToPlay) {
                audioToPlay.currentTime = lastFullscreenTime || 0;
                audioToPlay.muted = false;
                audioToPlay.play().catch(() => {});
                return;
            }
        }

        // 2️⃣ Otherwise, play first unmuted audio in grid
        audioToPlay = audios.find(a => !a.muted);
        if (audioToPlay) {
            audioToPlay.play().catch(() => {});
        }
    }, 100); // small delay ensures IntersectionObserver & load queue processed

    grid.querySelectorAll('video, audio, img[data-src]').forEach(el => observer.observe(el));

    document.getElementById('file-count').innerText = `${startIndex + 1} / ${allVideos.length}`;
}

// Navigation
function nextGrid() { startIndex = (startIndex + totalCells) % allVideos.length; renderGrid(); }
function prevGrid() { startIndex = (startIndex - totalCells + allVideos.length) % allVideos.length; renderGrid(); }

// Delete
function deleteGridFile(file) {
    if (!confirm('Delete this file?')) return;
    const idx = allVideos.indexOf(file);
    if (idx !== -1) allVideos.splice(idx, 1);
    fetch('index.php?delete=' + encodeURIComponent(file));
    if (startIndex >= allVideos.length) startIndex = Math.max(0, allVideos.length - totalCells);
    renderGrid();
}

// Mute toggle
function toggleMute() {
    muted = !muted;
    document.getElementById('mute-button').innerHTML = muted ? '🔇' : '🔊';
    renderGrid();  // Re-renders and applies new mute state (sound on/off, but playback continues)
}

// --------------------
// Enter fullscreen
// --------------------
function startFullscreenFrom(file, startTime = 0) {
    fullscreenMode = 'tile';
    document.querySelectorAll('#grid video, #grid audio').forEach(m => m.pause());

    const idx = allVideos.indexOf(file);
    if (idx === -1) return;

    const ext = file.split('.').pop().toLowerCase();
    if (['mp3','wav','ogg'].includes(ext)) {
        lastFullscreenAudio = file;
        lastFullscreenTime = startTime;
    } else if (['mp4','webm','mkv'].includes(ext)) {
        lastFullscreenVideo = file;
        lastFullscreenVideoTime = startTime;
    }

    startFullscreenPlayer(allVideos, idx, startTime);
}

// --------------------
// Fullscreen player (only small change in close())
// --------------------
function startFullscreenPlayer(playlist, index = 0, startTime = 0) {
    if (!playlist.length) return;
    let i = index;

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:black;display:flex;align-items:center;justify-content:center;flex-direction:column;z-index:9999;';
    document.body.appendChild(container);

    const ext = playlist[i].split('.').pop().toLowerCase();
    let media, thumb;

    if (['mp4','webm','mkv'].includes(ext)) {
        media = document.createElement('video');
        media.controls = true;       // overlay buttons replace native controls
        media.loop = true;            // optional, if you want looping in fullscreen
        media.muted = muted;          // start muted if grid is muted
        media.playsInline = true;
        media.style.width = '100%';
        media.style.height = '100%';
        media.style.objectFit = 'contain';
        media.src = playlist[i];      // <- important: set src immediately
        media.currentTime = startTime;

        // Only play after metadata is loaded
        media.addEventListener('loadedmetadata', () => {
            media.play().catch(() => {});
        }, { once: true });

        container.appendChild(media);
    }

    else if (['mp3','wav','ogg'].includes(ext)) {
        media = document.createElement('audio');
        media.controls = true;
        media.autoplay = true;
        media.muted = muted;
        media.style.width = '100%';
        media.style.height = '40px';
        media.src = playlist[i];
        media.currentTime = startTime;

        thumb = document.createElement('img');
        thumb.src = audioThumbs[playlist[i]] ?? 'cache/no-cover.jpg';
        thumb.style.width = '100%';
        thumb.style.height = '100%';
        thumb.style.objectFit = 'contain';

        container.appendChild(thumb);
        container.appendChild(media);
    }
    
    else {
        // media = document.createElement('video');
        // media.src = playlist[i];
        // media.autoplay = true;
        // media.muted = muted;
        // media.controls = true;
        // media.style.width = '100%';
        // media.style.height = '100%';
        // media.style.objectFit = 'contain';
        // media.currentTime = startTime;
        // container.appendChild(media);
    }

    function play(idx) {
        i = (idx + playlist.length) % playlist.length;
        const nextFile = playlist[i];
        const nextExt = nextFile.split('.').pop().toLowerCase();

        media.src = nextFile;
        media.play().catch(() => {});

        if (thumb && ['mp3','wav','ogg'].includes(nextExt)) {
            thumb.src = audioThumbs[nextFile] ?? 'cache/no-cover.jpg';
        }

        if (['mp3','wav','ogg'].includes(nextExt)) {
            lastFullscreenAudio = nextFile;
        }
    }

    function close() {
        const ext = playlist[i].split('.').pop().toLowerCase();

        if (['mp3','wav','ogg'].includes(ext)) {
            lastFullscreenTime = media.currentTime;
        } else if (['mp4','webm','mkv'].includes(ext)) {
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
        media.loop = true;          // <-- loop the audio
        media.onended = null;       // no need to handle next track
    } else {
        media.loop = false;
        media.onended = () => play(i + 1); // normal playlist behavior
    }

    // Navigation (wheel, touch, keys) unchanged...
    container.addEventListener('wheel', e => { e.preventDefault(); e.deltaY > 0 ? play(i + 1) : play(i - 1); }, { passive: false });

    let fsTouchStartY = 0;
    container.addEventListener('touchstart', e => { if (e.touches.length === 1) fsTouchStartY = e.touches[0].clientY; }, { passive: true });
    container.addEventListener('touchend', e => {
        const deltaY = e.changedTouches[0].clientY - fsTouchStartY;
        if (Math.abs(deltaY) > 50) deltaY < 0 ? play(i + 1) : play(i - 1);
    }, { passive: true });

    const keyHandler = e => {
        if (e.key === 'Escape') close();
        if (e.key === 'Delete') {
            if (!confirm('Delete this file?')) return;
            const deleted = playlist[i];
            playlist.splice(i, 1);
            const idxAll = allVideos.indexOf(deleted);
            if (idxAll !== -1) allVideos.splice(idxAll, 1);
            fetch('index.php?delete=' + encodeURIComponent(deleted));
            renderGrid();
            if (playlist.length === 0) { close(); return; }
            play(i % playlist.length);
        }
    };
    document.addEventListener('keydown', keyHandler);
}

// Play all / shuffle unchanged
function playAll() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    startFullscreenPlayer(allVideos, startIndex);
}

function shufflePlay() {
    fullscreenMode = 'playlist';
    document.querySelectorAll('#grid audio, #grid video').forEach(m => m.pause());
    let shuffled = [...allVideos];
    for (let i = shuffled.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
    }
    startFullscreenPlayer(shuffled, 0);
}

function runAudit(count) {
    const url = new URL(window.location.href);
    url.searchParams.set('audited', 'true');
    url.searchParams.set('fileCount', count);
    window.location.href = url.toString();
}

// Grid navigation unchanged
const grid = document.getElementById('grid');
let scrollDebounce = false;
grid.addEventListener('wheel', e => { e.preventDefault(); if(scrollDebounce) return; scrollDebounce=true; setTimeout(()=>scrollDebounce=false,200); e.deltaY<0?prevGrid():nextGrid(); }, {passive:false});
let touchStartY=0;
grid.addEventListener('touchstart', e => { if(e.touches.length===1) touchStartY=e.touches[0].clientY; }, {passive:true});
grid.addEventListener('touchend', e => { const deltaY=e.changedTouches[0].clientY-touchStartY; if(Math.abs(deltaY)>50) deltaY<0?nextGrid():prevGrid(); }, {passive:true});

// Initial render
renderGrid();

// Mobile vh fix
function setVhUnit(){ let vh=window.innerHeight*0.01; document.documentElement.style.setProperty('--vh', `${vh}px`); }
setVhUnit();
window.addEventListener('resize', setVhUnit);
window.addEventListener('orientationchange', setVhUnit);
</script>
</body>
</html>