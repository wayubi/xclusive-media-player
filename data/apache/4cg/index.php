<?php
// =====================
// CONFIG / SETTINGS
// =====================
$root_directory = './volumes';
$root_directory_absolute = getcwd() . '/volumes';

$is_mobile = stripos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false || stripos($_SERVER['HTTP_USER_AGENT'], 'Android') !== false;

// GET parameters
$selected_category = $_GET['selected-category'] ?? null;
$selected_board = $_GET['selected-board'] ?? null;
$selected_folder = $_GET['selected-folder'] ?? null;
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
function getDirectories($path, $exclude = []) {
    $list = glob($path . '/*');
    $dirs = [];
    foreach ($list as $l) {
        $name = basename($l);
        if (!in_array($name, $exclude)) {
            $dirs[] = $name;
        }
    }
    sort($dirs);
    return $dirs;
}

function getFiles($path) {
    $files = glob($path . '/*', GLOB_BRACE);
    $files = array_filter($files, function ($file) {
        return substr($file, -7) !== '.backup' && substr($file, -9) !== '.original';
    });
    usort($files, function ($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    return $files;
}

// =====================
// DELETE ACTION
// =====================
if ($delete_file && file_exists($delete_file)) {
    $trashDirectory = '/tmp/4cg-trash';
    if (!file_exists($trashDirectory)) mkdir($trashDirectory, 0777, true);
    $trashFilePath = $trashDirectory . '/' . uniqid() . '_' . basename($delete_file);
    rename($delete_file, $trashFilePath);
    // For DOM-only flow, we don't redirect on delete anymore
    exit;
}

// =====================
// INITIALIZATION
// =====================
$categories = getDirectories($root_directory);
if (!$selected_category) $selected_category = $categories[0];

$boards = getDirectories("$root_directory/$selected_category");
if (!$selected_board) $selected_board = $boards[0];

$folders = getDirectories("$root_directory/$selected_category/$selected_board");
sort($folders);
if (!$selected_folder) $selected_folder = $folders[0];

$current_selected_directory = "$root_directory/$selected_category/$selected_board/$selected_folder";
$auditFile = "$current_selected_directory/.audited";
$auditedText = file_exists($auditFile) ? trim(file_get_contents($auditFile)) : '';

// =====================
// FILE COLLECTION
// =====================
if ($selected_folder === '__all__') {
    $allFiles = [];
    foreach ($folders as $folder) {
        $dir = "$root_directory/$selected_category/$selected_board/$folder";
        $allFiles = array_merge($allFiles, getFiles($dir));
    }
    usort($allFiles, function($a, $b){ return filemtime($b) <=> filemtime($a); });
} else {
    $allFiles = getFiles($current_selected_directory);
}

// =====================
// ACTIONS
// =====================
// if ($completed) {
//     $source = "$root_directory/$selected_category/$selected_board/$selected_folder";
//     $destination = "$root_directory/complete/$selected_board/$selected_folder";
//     rename($source, $destination);
//     header("Location: index.php?selected-category=$selected_category&selected-board=$selected_board");
//     exit;
// }

if ($audited) {
    file_put_contents($auditFile, date('m/d/y') . " / $fileCount" . PHP_EOL);
    header("Location: index.php?selected-category=$selected_category&selected-board=$selected_board&selected-folder=$selected_folder");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Video Grid</title>
<style>
/* ---------- General ---------- */
body {
  margin: 0;
  padding: 0;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
  background-color: #121212;
  color: #f0f0f0;
}
a { text-decoration: none; color: #1e90ff; }

/* ---------- Form / Options ---------- */
#form {
  padding: 12px 20px;
  background-color: #1f1f1f;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 10px;
  border-bottom: 1px solid #333;
}
#options-form select, #options-form button {
  padding: 6px 10px;
  border-radius: 6px;
  border: none;
  background-color: #2c2c2c;
  color: #f0f0f0;
  font-size: 14px;
  cursor: pointer;
  transition: 0.2s;
}
#options-form select:hover, #options-form button:hover { background-color: #3a3a3a; }
#file-count, #audit-text { font-weight: bold; margin-left: 10px; margin-right: 10px; }

/* ---------- Grid ---------- */
#grid {
  display: grid;
  grid-template-columns: repeat(<?php echo $selected_columns; ?>, 1fr);
  grid-template-rows: repeat(<?php echo $selected_rows; ?>, minmax(0, 1fr));
  gap: 8px;
  padding: 10px;
  height: calc(100vh - 72px);
}

.video-container {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  border-radius: 8px;
  background-color: black;
}
.video-container video, .video-container img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  background-color: black;
  border-radius: 8px;
  transition: transform 0.2s, box-shadow 0.2s;
}
.video-container:hover video, .video-container:hover img {
  transform: scale(1.03);
  box-shadow: 0 4px 20px rgba(0,0,0,0.5);
}

/* Overlay */
.video-container .overlay {
  position: absolute;
  bottom: 4px;
  left: 4px;
  right: 4px;
  background-color: rgba(0,0,0,0.7);
  color: #fff;
  font-size: 12px;
  padding: 2px 6px;
  border-radius: 4px;
  opacity: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  pointer-events: none;
  transition: opacity 0.2s;
}
.video-container:hover .overlay { opacity: 1; pointer-events: auto; }

.overlay button {
  background-color: #ff4d4f;
  border: none;
  border-radius: 4px;
  color: white;
  font-size: 10px;
  padding: 2px 6px;
  cursor: pointer;
  margin-left: 6px;
}
.overlay button:hover { background-color: #d9363e; }

/* ---------- Responsive ---------- */
@media (max-width: 768px) {
  #grid { grid-template-columns: 1fr; grid-template-rows: repeat(auto-fill, minmax(200px, 1fr)); }
  #form { flex-direction: column; gap: 8px; }
}
</style>
</head>
<body>

<div id="form">
  <form id="options-form" method="get" action="index.php">
    <span id="file-count"><?php echo 1; ?> / <?php echo count($allFiles); ?></span>

    <select name="selected-category" onchange="submitForm('selected-category')">
      <?php foreach ($categories as $category): ?>
        <option <?php if ($selected_category==$category) echo 'selected'; ?> value="<?php echo $category; ?>"><?php echo $category; ?></option>
      <?php endforeach; ?>
    </select>

    <select name="selected-board" onchange="submitForm('selected-board')">
      <?php foreach ($boards as $board): ?>
        <option <?php if ($selected_board==$board) echo 'selected'; ?> value="<?php echo $board; ?>"><?php echo $board; ?></option>
      <?php endforeach; ?>
    </select>

    <select name="selected-folder" onchange="submitForm('selected-folder')">
      <option value="__all__" <?php if ($selected_folder === '__all__') echo 'selected'; ?>>All folders</option>
      <?php foreach ($folders as $folder): ?>
        <option <?php if ($selected_folder==$folder) echo 'selected'; ?> value="<?php echo $folder; ?>"><?php echo $folder; ?></option>
      <?php endforeach; ?>
    </select>

    <select name="columns" onchange="submitForm()">
      <?php for ($c=1;$c<=5;$c++): ?>
        <option <?php if ($selected_columns==$c) echo 'selected'; ?> value="<?php echo $c; ?>"><?php echo $c; ?></option>
      <?php endfor; ?>
    </select>

    <select name="rows" onchange="submitForm()">
      <?php for ($r=1;$r<=5;$r++): ?>
        <option <?php if ($selected_rows==$r) echo 'selected'; ?> value="<?php echo $r; ?>"><?php echo $r; ?></option>
      <?php endfor; ?>
    </select>

    <input type="hidden" name="muted" value="<?php echo $muted ? 'true':'false'; ?>">
    <button type="button" id="mute-button" onclick="toggleMute()"><?php echo $muted ? '🔇':'🔊'; ?></button>
    <button type="button" onclick="playAll()">▶ Play All</button>
    <button type="button" onclick="shufflePlay()">🔀 Shuffle</button>
    <button type="button" onclick="window.location.href=window.location.href">Refresh</button>
    <button type="button" onclick="window.location.href='index.php'">Clear</button>
    <button type="button" onclick="audit(<?php echo count($allFiles); ?>)">Audit</button>
    <button type="button" onclick="prevGrid()"><</button>
    <button type="button" onclick="nextGrid()">></button>

    <span id="audit-text">[ <?php echo htmlspecialchars($auditedText); ?> ]</span>
  </form>
</div>

<div id="grid"></div>

<script>
let allVideos = <?php echo json_encode($allFiles); ?>;
let muted = <?php echo $muted ? 'true' : 'false'; ?>;
let totalCells = <?php echo $total_cells; ?>;
let startIndex = 0;

// --------------------
// Grid Rendering
// --------------------
function renderGrid(){
  const grid = document.getElementById('grid');

  // Pause old videos to free resources
  const oldVideos = grid.querySelectorAll('video');
  oldVideos.forEach(v => {
    v.pause();
    v.src = '';
    v.load();
  });

  grid.innerHTML = '';

  const endIndex = Math.min(startIndex + totalCells, allVideos.length);
  const visible = allVideos.slice(startIndex, endIndex);

  visible.forEach((file,i) => {
    const container = document.createElement('div');
    container.className = 'video-container';

    const ext = file.split('.').pop().toLowerCase();
    if(['mp4','webm'].includes(ext)){
      const video = document.createElement('video');
      video.loop = true;
      video.muted = muted;
      video.playsInline = true; // mobile inline autoplay
      video.preload = 'metadata'; // only load metadata initially
      video.onclick = () => startFullscreenFrom(allVideos.indexOf(file));

      // Only set src and play after inserted into DOM
      container.appendChild(video);
      requestAnimationFrame(() => {
        video.src = file;
        video.play().catch(() => {
          console.log('Video autoplay blocked, user interaction needed');
        });
      });
    } else if(['jpg','jpeg','png','gif'].includes(ext)){
      const img = document.createElement('img');
      img.src = file;
      img.onclick = () => startFullscreenFrom(allVideos.indexOf(file));
      container.appendChild(img);
    } else {
      container.innerHTML = `<div style="color:red; padding:4px;">Unsupported: ${file}</div>`;
    }

    const overlay = document.createElement('div');
    overlay.className = 'overlay';
    overlay.innerHTML = `<span>${file}</span> <button onclick="deleteGridFile('${file}')" onkeydown="if(event.key==='Enter'){ deleteGridFile('${file}'); }">Delete</button>`;
    container.appendChild(overlay);

    grid.appendChild(container);
  });

  document.getElementById('file-count').innerText = `${startIndex+1} / ${allVideos.length}`;
}

// --------------------
// Navigation
// --------------------
function nextGrid(){
  startIndex = (startIndex + totalCells) % allVideos.length;
  renderGrid();
}
function prevGrid(){
  startIndex = (startIndex - totalCells + allVideos.length) % allVideos.length;
  renderGrid();
}

// --------------------
// Delete file
// --------------------
function deleteGridFile(file){
  if(!confirm('Delete this file?')) return;
  const idx = allVideos.indexOf(file);
  if(idx !== -1) allVideos.splice(idx,1);

  // Trigger server-side delete
  fetch('index.php?delete=' + encodeURIComponent(file));

  // Adjust startIndex if needed
  if(startIndex >= allVideos.length) startIndex = Math.max(0, allVideos.length - totalCells);

  renderGrid();
}

// --------------------
// Mute toggle
// --------------------
function toggleMute(){
  muted = !muted;
  document.getElementById('mute-button').innerHTML = muted ? '🔇':'🔊';
  renderGrid();
}

// --------------------
// Fullscreen player
// --------------------
function startFullscreenPlayer(playlist, index=0){
  if(!playlist.length) return;
  let i = index;

  const container = document.createElement('div');
  container.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:black;display:flex;align-items:center;justify-content:center;z-index:9999;';
  document.body.appendChild(container);

  const video = document.createElement('video');
  video.src = playlist[i];
  video.style.width = '100%';
  video.style.height = '100%';
  video.style.objectFit = 'contain';
  video.autoplay = true;
  video.muted = muted;
  video.controls = true;
  container.appendChild(video);

  function play(idx){
    i = (idx + playlist.length) % playlist.length;
    video.src = playlist[i];
    video.play();
  }

  function close(){
    // Sync grid page with current video
    startIndex = Math.floor(allVideos.indexOf(playlist[i]) / totalCells) * totalCells;
    renderGrid();

    container.remove();
    document.removeEventListener('keydown', keyHandler);
  }

  video.ondblclick = close;
  video.onended = () => play(i+1);

  // --- Wheel scroll for prev/next (fullscreen) ---
  container.addEventListener('wheel', function(e){
    e.preventDefault(); // prevent page scrolling
    if(e.deltaY > 0) play(i + 1); // scroll down → next
    else play(i - 1); // scroll up → previous
  }, {passive:false});

  const keyHandler = e => {
    if(e.key === 'Escape') close();
    if(e.key === 'Delete') {
      let confirmDelete = confirm('Delete this file?');
      if(!confirmDelete) return;

      const deleted = playlist[i];
      playlist.splice(i,1);
      const idxAll = allVideos.indexOf(deleted);
      if(idxAll !== -1) allVideos.splice(idxAll,1);
      fetch('index.php?delete=' + encodeURIComponent(deleted));
      renderGrid();
      if(playlist.length === 0){ close(); return; }
      play(i % playlist.length);
    }
  };
  document.addEventListener('keydown', keyHandler);
}

// --------------------
// Grid double-click
// --------------------
function startFullscreenFrom(idx){
  startFullscreenPlayer(allVideos, idx);
}

// --------------------
// Play all / shuffle
// --------------------
function playAll(){ startFullscreenPlayer(allVideos, startIndex); }
function shufflePlay(){
  let shuffled = [...allVideos];
  for(let i=shuffled.length-1;i>0;i--){
    const j=Math.floor(Math.random()*(i+1));
    [shuffled[i],shuffled[j]]=[shuffled[j],shuffled[i]];
  }
  startFullscreenPlayer(shuffled,0);
}

// --------------------
// Form helpers
// --------------------
function submitForm(changedId=''){
  const form = document.getElementById('options-form');
  if(changedId==='selected-category'){
    form.elements['selected-board'].selectedIndex=0;
    form.elements['selected-folder'].selectedIndex=0;
  } else if(changedId==='selected-board'){
    form.elements['selected-folder'].selectedIndex=0;
  }
  form.submit();
}

function audit(count){
  const url = new URL(window.location.href);
  url.searchParams.set('audited','true');
  url.searchParams.set('fileCount',count);
  window.location.href = url.toString();
}

// --------------------
// GRID SCROLL NAVIGATION
// --------------------
const grid = document.getElementById('grid');
let scrollDebounce = false;

grid.addEventListener('wheel', (e) => {
    e.preventDefault(); // prevent page scrolling

    if(scrollDebounce) return; // simple debounce
    scrollDebounce = true;
    setTimeout(() => scrollDebounce = false, 200); // 200ms between scrolls

    if(e.deltaY < 0){
        prevGrid(); // scroll up → next page
    } else {
        nextGrid(); // scroll down → previous page
    }
}, {passive:false});

// --------------------
// Initial render
// --------------------
renderGrid();
</script>

</body>
</html>
