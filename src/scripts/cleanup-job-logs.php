#!/usr/bin/env php
<?php
// cleanup-job-logs.php - Clean up old job output logs
// Usage: cleanup-job-logs.php [--days=N]
// Default: 14 days

$jobsDir = '/var/www/jobs';
$defaultDays = 14;
$days = $defaultDays;

// Parse command line arguments
if (isset($argv[1])) {
    if (preg_match('/^--days=(\d+)$/', $argv[1], $matches)) {
        $days = (int) $matches[1];
        if ($days < 1) {
            echo "Error: days must be at least 1\n";
            exit(1);
        }
    } elseif ($argv[1] === '--help' || $argv[1] === '-h') {
        echo "Cleanup old job output logs\n";
        echo "Usage: cleanup-job-logs.php [--days=N]\n";
        echo "  --days=N  Delete output files older than N days (default: $defaultDays)\n";
        echo "\nExample:\n";
        echo "  cleanup-job-logs.php          # Delete files older than 14 days\n";
        echo "  cleanup-job-logs.php --days=7  # Delete files older than 7 days\n";
        exit(0);
    }
}

if (!is_dir($jobsDir)) {
    echo "Jobs directory not found: $jobsDir\n";
    exit(1);
}

$cutoffTime = time() - ($days * 86400);
$deletedCount = 0;
$freedBytes = 0;

$files = glob("$jobsDir/*.output");
if ($files === false) {
    echo "Error reading jobs directory\n";
    exit(1);
}

foreach ($files as $file) {
    $mtime = filemtime($file);
    if ($mtime !== false && $mtime < $cutoffTime) {
        $size = filesize($file);
        if (unlink($file)) {
            $deletedCount++;
            $freedBytes += $size;
        }
    }
}

// Format freed space
if ($freedBytes >= 1048576) {
    $freedStr = round($freedBytes / 1048576, 2) . ' MB';
} elseif ($freedBytes >= 1024) {
    $freedStr = round($freedBytes / 1024, 2) . ' KB';
} else {
    $freedStr = $freedBytes . ' bytes';
}

echo "Cleanup complete\n";
echo "Deleted $deletedCount file(s), freed $freedStr\n";