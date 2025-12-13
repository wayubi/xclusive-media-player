<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>WebM Video Player</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100vh;
      /* background-color: #f0f0f0; */
      background-color: black;
    }

    video, img {
      width: 95%;
      max-width: 800px;
      max-height: 90%;
      /* border: 1px solid #ccc; */
    }

    /* button {
      margin-top: 10px;
      padding: 8px 16px;
      font-size: 10px;
      cursor: pointer;
    } */

    button {
      margin-top: 5px;
      padding: 3px 10px 3px;
      cursor: pointer;
    }
  </style>
</head>
<body>
  <?php
    $file = $_GET['file'];
    $muted = isset($_GET['muted']) ? (boolean) $_GET['muted'] : true;
    if (isset($_GET['delete'])) {

      $trashDirectory = '/tmp/4cg-trash';
      if (!file_exists($trashDirectory)) {
        mkdir($trashDirectory, 0777, true);
      }
      $fileName = basename($file);
      $trashFilePath = $trashDirectory . '/' . uniqid() . '_' . $fileName;
      rename($file, $trashFilePath);

      var_dump($file);
      var_dump($trashFilePath);
      exit;
    }
  ?>

  <!-- File Viewer -->
  <?php
  $fileType = pathinfo($file, PATHINFO_EXTENSION);
  // var_dump($file);
  switch ($fileType) {
    case 'webm':
      echo '<video autoplay loop controls';
      if ($muted) echo ' muted';
      echo '>';
      echo '<source src="' . $file . '" type="video/webm">';
      echo '</video>';
      break;
    case 'mp4':
      echo '<video autoplay loop controls';
      if ($muted) echo ' muted';
      echo '>';
      echo '<source src="' . $file . '" type="video/mp4">';
      echo '</video>';
      break;
    case 'gif':
      echo '<img src="' . $file . '" alt="GIF">';
      break;
    case 'jpg':
    case 'jpeg':
      echo '<img src="' . $file . '" alt="JPEG">';
      break;
    case 'png':
      echo '<img src="' . $file . '" alt="PNG">';
      break;
    default:
      echo 'Unsupported file type';
      var_dump($file);
  }
  ?>

  <!-- Delete Button -->
  <button onclick="deleteFile(null)">Delete File</button>

  <script>
    function deleteFile() {
      // if (confirm('Are you sure you want to delete this file?')) {
        window.location.href = 'video.php?file=<?php echo $file; ?>&delete=1';
      // }
    }
  </script>

</body>
</html>

