<?php
  $is_mobile = strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false || strpos($_SERVER['HTTP_USER_AGENT'], 'Android') !== false;
  $selected_category = isset($_GET['selected-category']) ? $_GET['selected-category'] : null;
  $selected_board = isset($_GET['selected-board']) ? $_GET['selected-board'] : null;
  $selected_folder = isset($_GET['selected-folder']) ? $_GET['selected-folder'] : null;
  $selected_columns = $is_mobile ? 1 : (isset($_GET['columns']) ? $_GET['columns'] : 3);
  $selected_rows = $is_mobile ? 1 : (isset($_GET['rows']) ? $_GET['rows'] : 2);  
  $total_cells = $selected_columns * $selected_rows;
  $muted = (!isset($_GET['muted']) || ($_GET['muted'] == 'true')) ? true : false;
  $completed = isset($_GET['completed']) ? $_GET['completed'] : false;

  $root_directory = './4chan-boards';
  $root_directory_absolute = getcwd() . '/4chan-boards';

  if ($completed) {
    $source = $root_directory_absolute . '/' . $selected_category . '/' . $selected_board . '/' . $selected_folder;
    $destination = $root_directory_absolute . '/complete/' . $selected_board . '/' . $selected_folder;
    rename($source, $destination);
    Header("Location: index.php?selected-category=$selected_category&selected-board=$selected_board");
    exit;
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      margin: 0;
      padding: 0;
      font-size: 16px;
      font-family: sans-serif;
      color: white;
      background-color: black;
    }

    a {
      text-decoration: none;
    }

    #grid {
      display: grid;
      grid-template-columns: repeat(<?php echo $selected_columns; ?>, 1fr);
      grid-template-rows: repeat(<?php echo $selected_rows; ?>, minmax(0, 1fr)); /* <-- makes rows flexible */
      gap: 8px;
      padding: 10px;
      height: calc(100vh - 72px); /* account for form + padding */
    }

    #grid iframe {
      width: 100%;
      height: 100%;
      border-radius: 8px;
      border: 1px solid #333;
      background-color: black;
      transition: transform 0.2s, box-shadow 0.2s;
      display: block;
    }

    #footer, #form {
      padding: 10px 0 10px;
      text-align: center;
    }

    iframe {
      width: 100%;
      height: 100%;
      /* border: 1px solid #ccc; Optional: Add a border around each iframe */
      /* border: 1px solid black; */
      border: 1px;
    }

    #options-form {
      text-align: center;
    }

    #options-form select {
      padding: 4px;
    }

    button {
      padding: 3px 10px 3px;
      cursor: pointer;
    }
  </style>
</head>
<body>

<div id="form">
  <?php $download_root_directory = '/opt/4chan-downloads'; ?>
  <?php
    $directory = $root_directory . '/*';
    $list = glob($directory);
    $categories = [];
    foreach ($list as $l) {
      #if (basename($l) === 'downloads') continue;
      $categories[] = basename($l);
    }
    sort($categories);
    $selected_category = isset($_GET['selected-category']) ? $_GET['selected-category'] : $categories[0];

    $directory = $root_directory . '/' . $selected_category . '/*';
    $list = glob($directory);
    $boards = [];
    foreach ($list as $l) {
      $boards[] = basename($l);
    }
    sort($boards);
    $selected_board = isset($_GET['selected-board']) ? $_GET['selected-board'] : $boards[0];

    $directory = $download_root_directory . '/' . $selected_board . '/*';
    $list = glob($directory);
    $downloading_folders = [];
    foreach ($list as $l) {
      $downloading_folders[] = basename($l);
    }

    $directory = $root_directory . '/' . $selected_category . '/' . $selected_board . '/*';
    $list = glob($directory);
    $folders = [];
    foreach ($list as $l) {
      $folders[] = basename($l);
    }
    rsort($folders);
    $selected_folder = isset($_GET['selected-folder']) ? $_GET['selected-folder'] : $folders[0];
  ?>
  <?php
    $files = glob($root_directory . '/' . $selected_category . '/' . $selected_board . '/' . $selected_folder . '/*', GLOB_BRACE);
    $files = array_filter($files, function($file) {
	    #return substr($file, -7) !== '.backup';
	    return substr($file, -7) !== '.backup' && substr($file, -9) !== '.original';
    });
    $startIndex = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
    $currentFiles = array_slice($files, $startIndex, $total_cells);
  ?>
  <form id="options-form" method="get" action="index.php">
    <span id="file-count"><?php echo ($startIndex+1); ?> / <?php echo count($files); ?></span>
    <select name="selected-category" id="selected-category" onchange="submitForm('selected-category')">
      <?php foreach ($categories as $category): ?>
        <option <?php if ($selected_category == $category) echo 'selected'; ?> value="<?php echo $category; ?>"><?php echo $category; ?></option>
      <?php endforeach; ?>
    </select>
    <select name="selected-board" id="selected-board" onchange="submitForm('selected-board')">
      <?php foreach ($boards as $board): ?>
        <option <?php if ($selected_board == $board) echo 'selected'; ?> value="<?php echo $board; ?>"><?php echo $board; ?></option>
      <?php endforeach; ?>
    </select>
    <select name="selected-folder" id="selected-folder" onchange="submitForm('selected-folder')">
      <?php foreach ($folders as $folder): ?>
        <option <?php if ($selected_folder == $folder) echo 'selected'; ?> value="<?php echo $folder; ?>"><?php
          echo $folder;
          if (in_array($folder, $downloading_folders)) {
            echo ' [!]';
          }
        ?></option>
      <?php endforeach; ?>
    </select>
    <select name="columns" id="columns-select" onchange="submitForm()">
      <?php for ($column = 1; $column <= 5; $column++): ?>
        <option <?php if ($selected_columns == $column) echo 'selected'; ?> value="<?php echo $column; ?>"><?php echo $column; ?></option>
      <?php endfor; ?>
    </select>
    <select name="rows" id="rows-select" onchange="submitForm()">
      <?php for ($row = 1; $row <= 5; $row++): ?>
        <option <?php if ($selected_rows == $row) echo 'selected'; ?> value="<?php echo $row; ?>"><?php echo $row; ?></option>
      <?php endfor; ?>
    </select>
    <input type="hidden" name="muted" value="<?php echo $muted ? 'true' : 'false'; ?>">
    <button id="mute-button" type="button" onclick="toggleMute()"><?php echo $muted ? "&#x1F507;" : "&#x1F50A;"; ?></button>
    <?php if ($selected_category == 'new' && !in_array($selected_folder, $downloading_folders)): ?>
    <button id="clear-button" type="button" onclick="moveToCompleted()">Move to Complete</button>
    <?php endif; ?>
    <button id="clear-button" type="button" onclick="window.location.href = window.location.href">Refresh</button>
    <button id="clear-button" type="button" onclick="window.location.href = 'index.php'">Clear</button>
    <button id="prev-button" type="button" onclick="navigateVideos(-totalCells)"><</button>
    <button id="next-button" type="button" onclick="navigateVideos(totalCells)">></button>
  </form>
</div>

<div id="grid">
  <?php foreach ($currentFiles as $file): ?>
    <iframe src="video.php?file=<?php echo $file; ?>&muted=<?php echo $muted; ?>"></iframe>
  <?php endforeach; ?>
</div>

<div id="footer">
  <a href="https://boards.4chan.org/gif/catalog">4chan.org</a>
</div>

  <script>

    function toggleMute() {
      let button = document.getElementById("mute-button");
      let hidden = document.querySelector("input[type=hidden][name=muted]");
      let muted = button.innerHTML === '🔇';
      muted = !muted;
      console.log(muted);
      button.innerHTML = muted ? '🔇' : '🔊';
      hidden.value = muted ? 'true' : 'false';
      submitForm();
    }

    function submitForm(changedSelectId = '') {
      const form = document.getElementById('options-form');

      if (changedSelectId === 'selected-category') {
        // Remove board and folder selection when category changes
        form.querySelector('#selected-board').selectedIndex = -1;
        form.querySelector('#selected-folder').selectedIndex = -1;
      } else if (changedSelectId === 'selected-board') {
        // Remove folder selection when board changes
        form.querySelector('#selected-folder').selectedIndex = -1;
      }

      form.submit();
    }

    function moveToCompleted() {
      var urlParams = new URLSearchParams(window.location.search);
      window.location.href = 'index.php?' + urlParams.toString() + '&completed=true';
    }

    var totalCells = <?php echo $total_cells; ?>;
    function navigateVideos(offset) {
      var urlParams = new URLSearchParams(window.location.search);
      var startIndex = urlParams.get('start') || 0;
      startIndex = parseInt(startIndex) + offset;
      console.log(startIndex);
      // startIndex = Math.max(0, parseInt(startIndex) + offset);
      var totalFiles = <?php echo count($files); ?>;
      if (startIndex < 0) {
        startIndex = totalFiles + startIndex;
      } else if (startIndex >= totalFiles) {
        startIndex = 0;
      }
      // if (offset < 0) {
      //   startIndex = (totalFiles + startIndex + offset) % totalFiles;
      // } else {
      //   startIndex = Math.max(0, startIndex + offset);
      // }
      // if (startIndex < 0) {
      //   startIndex = totalFiles + startIndex;
      // }
      urlParams.set('start', startIndex);
      window.location.href = 'index.php?' + urlParams.toString();
    }
  </script>

</body>
</html>

