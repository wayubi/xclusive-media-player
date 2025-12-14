<?php
require_once __DIR__ . '/../vendor/getid3/getid3.php';

/**
 * @param array $files absolute file paths
 * @return array map: audioFile => thumbRelativePath
 */
function generateAudioCovers(array $files): array
{
    $cacheDir = __DIR__ . '/../cache/audio-covers';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0777, true);
    }

    $getID3 = new getID3;
    $map = [];

    foreach ($files as $file) {
        if (!is_file($file)) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ['mp3','wav','ogg'])) continue;

        $hash = md5(realpath($file));
        $thumbAbs = "$cacheDir/$hash.jpg";
        $thumbRel = "cache/audio-covers/$hash.jpg";

        // ✅ Already cached
        if (file_exists($thumbAbs)) {
            $map[$file] = $thumbRel;
            continue;
        }

        // 🔨 Generate
        $info = $getID3->analyze($file);
        getid3_lib::CopyTagsToComments($info);

        $coverData =
            $info['comments']['picture'][0]['data']
            ?? $info['id3v2']['APIC'][0]['data']
            ?? null;

        if ($coverData) {
            file_put_contents($thumbAbs, $coverData);
        } else {
            // placeholder
            copy(__DIR__ . '/../cache/no-cover.jpg', $thumbAbs);
        }

        $map[$file] = $thumbRel;
    }

    return $map;
}