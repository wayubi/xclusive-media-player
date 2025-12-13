<?php
// getAudioCover.php
require_once __DIR__ . '/lib/getid3/getid3.php'; // path to getID3 library

$cacheDir = __DIR__ . '/tmp/audio-covers';
if (!file_exists($cacheDir)) mkdir($cacheDir, 0777, true);

$file = $_GET['file'] ?? null;
if (!$file || !file_exists($file)) {
    http_response_code(404);
    exit(json_encode(['error'=>'File not found']));
}

$hash = md5(realpath($file));
$cachedFile = "$cacheDir/$hash.png";

// If cached, return it
if (!file_exists($cachedFile)) {
    $getID3 = new getID3;
    $info = $getID3->analyze($file);
    getid3_lib::CopyTagsToComments($info);

    $coverData = null;

    if (!empty($info['comments']['picture'][0]['data'])) {
        $coverData = $info['comments']['picture'][0]['data'];
    }

    if ($coverData) {
        file_put_contents($cachedFile, $coverData);
    } else {
        // fallback: placeholder image
        $img = imagecreatetruecolor(300,300);
        $bg = imagecolorallocate($img, 50,50,50);
        imagefill($img, 0,0, $bg);
        $textColor = imagecolorallocate($img, 255,255,255);
        imagestring($img, 5, 20, 130, basename($file), $textColor);
        ob_start();
        imagepng($img);
        imagedestroy($img);
        $coverData = ob_get_clean();
        file_put_contents($cachedFile, $coverData);
    }
}

// Return URL
header('Content-Type: application/json');
$relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $cachedFile);
echo json_encode(['cover' => $relativePath]);
