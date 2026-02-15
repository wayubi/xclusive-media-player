<?php

require_once __DIR__ . '/Utils.php';

class MetadataExtractor
{
    const TEXT_EXTENSIONS = ['txt', 'nfo', 'sfv', 'md', 'log', 'json', 'xml', 'csv', 'yaml', 'yml', 'conf', 'cfg', 'ini'];
    const AUDIO_EXTENSIONS = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];
    const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mkv', 'mov', 'm4v', '3gp', 'flv', 'wmv', 'avi', 'mpg', 'mpeg'];
    const NON_STREAMING_CONTAINERS = ['avi', 'flv', 'wmv', 'mkv', 'mpeg', 'mpg'];
    const NON_STREAMING_CODECS = ['vc1', 'wmv3', 'flv1', 'wmv2', 'mpeg4', 'wmv1', 'mpeg1video'];

    public static function extract(string $fsPath): array
    {
        $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));

        if (in_array($ext, self::TEXT_EXTENSIONS)) {
            return self::extractTextMetadata($fsPath);
        }

        return self::extractMediaMetadata($fsPath);
    }

    private static function extractTextMetadata(string $fsPath): array
    {
        return [
            'file' => basename($fsPath),
            'folder' => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'text' => [
                'encoding' => self::detectTextEncoding($fsPath),
            ],
            'optimizationStatus' => [
                'isOptimized' => true,
                'issues' => []
            ],
        ];
    }

    private static function extractMediaMetadata(string $fsPath): array
    {
        $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));

        $ffprobeData = self::runFfprobe($fsPath);

        if ($ffprobeData === null) {
            return self::createBasicMetadata($fsPath);
        }

        $streams = self::parseStreams($ffprobeData);
        $video = $streams['video'];
        $audio = $streams['audio'];

        $optimization = self::calculateOptimizationStatus($ext, $video, $fsPath);
        $fps = self::calculateFps($video);

        return [
            'file' => basename($fsPath),
            'folder' => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'duration' => $ffprobeData['format']['duration'] ?? null,
            'bitrate' => isset($ffprobeData['format']['bit_rate']) ? (int)$ffprobeData['format']['bit_rate'] : null,
            'container' => $ffprobeData['format']['format_name'] ?? null,
            'video' => $video ? [
                'codec' => $video['codec_name'] ?? null,
                'width' => $video['width'] ?? null,
                'height' => $video['height'] ?? null,
                'fps' => $fps,
                'pix_fmt' => $video['pix_fmt'] ?? null,
            ] : null,
            'audio' => $audio ? [
                'codec' => $audio['codec_name'] ?? null,
                'channels' => $audio['channels'] ?? null,
                'sample_rate' => $audio['sample_rate'] ?? null,
            ] : null,
            'optimizationStatus' => $optimization,
        ];
    }

    private static function runFfprobe(string $fsPath): ?array
    {
        $cmd = [
            'ffprobe',
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $fsPath
        ];

        $output = Utils::executeCommand(implode(' ', array_map('escapeshellarg', $cmd)));

        if (empty($output)) {
            return null;
        }

        $data = json_decode($output, true);

        return $data ?? null;
    }

    private static function parseStreams(array $ffprobeData): array
    {
        $video = null;
        $audio = null;

        foreach ($ffprobeData['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video' && !$video) {
                $video = $stream;
            }
            if ($stream['codec_type'] === 'audio' && !$audio) {
                $audio = $stream;
            }
        }

        return ['video' => $video, 'audio' => $audio];
    }

    private static function calculateOptimizationStatus(string $ext, ?array $video, string $fsPath): array
    {
        $isOptimized = true;
        $issues = [];

        if (in_array($ext, self::NON_STREAMING_CONTAINERS)) {
            $isOptimized = false;
            $issues[] = 'Non-streaming container: ' . strtoupper($ext);
        }

        if ($video && in_array($video['codec_name'] ?? '', self::NON_STREAMING_CODECS)) {
            $isOptimized = false;
            $issues[] = 'Non-streaming codec: ' . ($video['codec_name'] ?? 'unknown');
        }

        if (in_array($ext, ['mp4', 'mov', 'm4v'])) {
            $hasFaststart = self::checkMoovAtomPosition($fsPath);
            if ($hasFaststart === false) {
                $isOptimized = false;
                $issues[] = 'Faststart not enabled (moov atom not at start)';
            }
        }

        return [
            'isOptimized' => $isOptimized,
            'issues' => $issues
        ];
    }

    private static function calculateFps(?array $video): ?float
    {
        if (!$video || !isset($video['avg_frame_rate']) || $video['avg_frame_rate'] === '0/0') {
            return null;
        }

        $fpsParts = explode('/', $video['avg_frame_rate']);
        if (count($fpsParts) === 2 && $fpsParts[1] != 0) {
            return (float)$fpsParts[0] / (float)$fpsParts[1];
        }

        return null;
    }

    public static function checkMoovAtomPosition(string $filepath): ?bool
    {
        $fh = fopen($filepath, 'rb');
        if (!$fh) return null;

        try {
            while (!feof($fh)) {
                $header = fread($fh, 8);
                if (strlen($header) < 8) break;

                list(, $size) = unpack('N', substr($header, 0, 4));
                $type = substr($header, 4, 4);

                if ($type === 'moov') {
                    return true;
                }
                if ($type === 'mdat') {
                    return false;
                }

                if ($size === 0) break;
                if ($size === 1) {
                    fseek($fh, 8, SEEK_CUR);
                } else {
                    fseek($fh, $size - 8, SEEK_CUR);
                }
            }
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }

        return null;
    }

    public static function detectTextEncoding(string $filePath): string
    {
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return 'binary';
        }

        $content = fread($handle, 8192);
        fclose($handle);

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            return 'utf-8-bom';
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            if (preg_match('/^[\x00-\x7F]*$/', $content)) {
                return 'ascii';
            }
            return 'utf-8';
        }

        if (mb_check_encoding($content, 'Windows-1252') || mb_check_encoding($content, 'ISO-8859-1')) {
            return 'ansi';
        }

        return 'binary';
    }

    public static function isTextFile(string $fsPath): bool
    {
        $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
        return in_array($ext, self::TEXT_EXTENSIONS);
    }

    public static function isAudioFile(string $fsPath): bool
    {
        $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
        return in_array($ext, self::AUDIO_EXTENSIONS);
    }

    public static function isVideoFile(string $fsPath): bool
    {
        $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
        return in_array($ext, self::VIDEO_EXTENSIONS);
    }

    private static function createBasicMetadata(string $fsPath): array
    {
        return [
            'file' => basename($fsPath),
            'folder' => basename(dirname($fsPath)),
            'filesize' => filesize($fsPath),
            'optimizationStatus' => [
                'isOptimized' => true,
                'issues' => []
            ],
        ];
    }
}
