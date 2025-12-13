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
$completed = $_GET['completed'] ?? false;
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
    header("Location: index.php?" . $_SERVER['QUERY_STRING']); // reload page
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
    $files = $allFiles;
} else {
    $files = getFiles($current_selected_directory);
}

$startIndex = max(0, (int)($_GET['start'] ?? 0));
$currentFiles = array_slice($files, $startIndex, $total_cells);

// =====================
// ACTIONS
// =====================
if ($completed) {
    $source = "$root_directory/$selected_category/$selected_board/$selected_folder";
    $destination = "$root_directory/complete/$selected_board/$selected_folder";
    rename($source, $destination);
    header("Location: index.php?selected-category=$selected_category&selected-board=$selected_board");
    exit;
}

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
#file-count { font-weight: bold; margin-right: 10px; }

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
    <span id="file-count"><?php echo ($startIndex+1); ?> / <?php echo count($files); ?> [ <?php echo htmlspecialchars($auditedText); ?> ]</span>

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
    <button type="button" onclick="window.location.href=window.location.href">Refresh</button>
    <button type="button" onclick="window.location.href='index.php'">Clear</button>
    <button type="button" onclick="audit(<?php echo count($files); ?>)">Audit</button>
    <button type="button" onclick="navigateVideos(-totalCells)"><</button>
    <button type="button" onclick="navigateVideos(totalCells)">></button>
  </form>
</div>

<div id="grid">
  <?php foreach($currentFiles as $file):
      $folderName = basename(dirname($file));
      $fileName = basename($file);
      $fileExt = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  ?>
  <div class="video-container">
    <?php if(in_array($fileExt,['webm','mp4'])): ?>
      <video autoplay loop <?php echo $muted ? 'muted' : ''; ?>>
        <source src="<?php echo htmlspecialchars($file); ?>" type="video/<?php echo $fileExt; ?>">
      </video>
    <?php elseif(in_array($fileExt,['gif','jpg','jpeg','png'])): ?>
      <img src="<?php echo htmlspecialchars($file); ?>" alt="<?php echo htmlspecialchars($fileName); ?>">
    <?php else: ?>
      <div style="color:red; padding:4px;">Unsupported: <?php echo htmlspecialchars($fileName); ?></div>
    <?php endif; ?>
    <div class="overlay">
      <span><?php echo htmlspecialchars("$folderName / $fileName"); ?></span>
      <button onclick="deleteFile('<?php echo rawurlencode($file); ?>')">Delete</button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
var totalCells = <?php echo $total_cells; ?>;

// Toggle mute button
function toggleMute() {
    const button = document.getElementById("mute-button");
    const hidden = document.querySelector("input[name=muted]");
    const muted = button.innerHTML === '🔇';
    const newMuted = !muted;
    button.innerHTML = newMuted ? '🔇' : '🔊';
    hidden.value = newMuted ? 'true' : 'false';
    submitForm(); // resubmit with new mute state
}

// Submit form when a select changes
function submitForm(changedId='') {
    const form = document.getElementById('options-form');

    // Reset dependent selects
    if (changedId === 'selected-category') {
        form.elements['selected-board'].selectedIndex = 0;
        form.elements['selected-folder'].selectedIndex = 0;
    } else if (changedId === 'selected-board') {
        form.elements['selected-folder'].selectedIndex = 0;
    }

    form.submit();
}

// Audit files
function audit(fileCount) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('audited', 'true');
    urlParams.set('fileCount', fileCount);
    window.location.href = 'index.php?' + urlParams.toString();
}

// Navigate through video pages
function navigateVideos(offset) {
    const urlParams = new URLSearchParams(window.location.search);
    let start = parseInt(urlParams.get('start') || 0) + offset;
    const total = <?php echo count($files); ?>;
    if (start < 0) start = total + start;
    else if (start >= total) start = 0;
    urlParams.set('start', start);
    window.location.href = 'index.php?' + urlParams.toString();
}

// Delete a file
function deleteFile(file) {
    if (!file) return;
    if (confirm('Delete this file?')) {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('delete', file);
        window.location.href = 'index.php?' + urlParams.toString();
    }
}
</script>

</body>
</html>