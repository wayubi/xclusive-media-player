<?php
// lib/MetadataDatabase.php - File metadata management with SQLite

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Utils.php';
require_once __DIR__ . '/MetadataExtractor.php';

class MetadataDatabase extends Database
{
    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/metadata.db';
        parent::__construct($dbPath);
    }

    protected function createTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT NOT NULL UNIQUE,
                web_path TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                modified_time INTEGER NOT NULL,
                extension TEXT,
                
                -- Media metadata
                duration REAL,
                bitrate INTEGER,
                container TEXT,
                video_codec TEXT,
                video_width INTEGER,
                video_height INTEGER,
                video_fps REAL,
                video_pix_fmt TEXT,
                audio_codec TEXT,
                audio_channels INTEGER,
                audio_sample_rate INTEGER,
                
                -- Text file metadata
                text_encoding TEXT,
                
                -- Optimization status
                is_optimized INTEGER DEFAULT 1,
                optimization_issues TEXT,
                
                -- File checksum for integrity verification
                xxhash TEXT,
                
                -- Timestamps
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            
            CREATE INDEX IF NOT EXISTS idx_file_path ON files(file_path);
            CREATE INDEX IF NOT EXISTS idx_web_path ON files(web_path);
            CREATE INDEX IF NOT EXISTS idx_updated_at ON files(updated_at);
        ');
    }

    public function getMetadata($webPath, $fsPath) {
        return $this->fetchMetadata($webPath, $fsPath);
    }

    private function fetchMetadata($webPath, $fsPath) {
        if (!file_exists($fsPath)) {
            return null;
        }
        
        $normalizedPath = $this->normalizePath($fsPath);
        $currentMtime = filemtime($fsPath);
        $currentSize = filesize($fsPath);
        
        $stmt = $this->db->prepare('
            SELECT * FROM files 
            WHERE file_path = :path
        ');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        if (!$row) {
            return null;
        }
        
        if ($row['modified_time'] != $currentMtime || $row['file_size'] != $currentSize) {
            return null;
        }
        
        return $this->rowToMetadata($row);
    }

    /**
     * Get metadata for multiple files (batch operation)
     * Returns array with webPath as key, metadata as value
     */
    public function getMetadataBatch(array $webPaths, array $fsPaths): array
    {
        if (empty($webPaths)) {
            return [];
        }

        $results = [];
        $needsExtraction = [];

        foreach ($webPaths as $i => $webPath) {
            $fsPath = $fsPaths[$i] ?? '';
            
            if (!file_exists($fsPath)) {
                $results[$webPath] = null;
                continue;
            }

            $normalizedPath = $this->normalizePath($fsPath);
            $currentMtime = @filemtime($fsPath);
            $currentSize = @filesize($fsPath);

            $stmt = $this->db->prepare('SELECT * FROM files WHERE file_path = :path');
            $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);

            if ($row && $row['modified_time'] == $currentMtime && $row['file_size'] == $currentSize) {
                $results[$webPath] = $this->rowToMetadata($row);
            } else {
                $needsExtraction[$webPath] = $fsPath;
            }
        }

        // Batch extract metadata for files not in cache
        if (!empty($needsExtraction)) {
            foreach ($needsExtraction as $webPath => $fsPath) {
                $output = MetadataExtractor::extract($fsPath);
                $this->saveMetadata($webPath, $fsPath, $output);
                $output['file'] = base64_encode($output['file']);
                $results[$webPath] = $output;
            }
        }

        return $results;
    }
    
    /**
     * Save or update metadata for a file
     */
    public function saveMetadata($webPath, $fsPath, $metadata) {
        $normalizedPath = $this->normalizePath($fsPath);
        $now = time();
        
        // Extract optimization status
        $isOptimized = 1;
        $optimizationIssues = null;
        if (isset($metadata['optimizationStatus'])) {
            $isOptimized = $metadata['optimizationStatus']['isOptimized'] ? 1 : 0;
            $optimizationIssues = json_encode($metadata['optimizationStatus']['issues'] ?? []);
        }
        
        // Check if record exists
        $stmt = $this->db->prepare('SELECT id FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);
        
        // Calculate xxhash
        $xxhash = null;
        $xxhashOutput = Utils::executeCommand('xxhsum ' . escapeshellarg($fsPath));
        if ($xxhashOutput) {
            $xxhashParts = explode(' ', trim($xxhashOutput));
            $xxhash = $xxhashParts[0] ?? null;
        }
        
        if ($existing) {
            // Update existing
            $stmt = $this->db->prepare('
                UPDATE files SET
                    web_path = :web_path,
                    file_size = :file_size,
                    modified_time = :modified_time,
                    extension = :extension,
                    duration = :duration,
                    bitrate = :bitrate,
                    container = :container,
                    video_codec = :video_codec,
                    video_width = :video_width,
                    video_height = :video_height,
                    video_fps = :video_fps,
                    video_pix_fmt = :video_pix_fmt,
                    audio_codec = :audio_codec,
                    audio_channels = :audio_channels,
                    audio_sample_rate = :audio_sample_rate,
                    text_encoding = :text_encoding,
                    is_optimized = :is_optimized,
                    optimization_issues = :optimization_issues,
                    xxhash = :xxhash,
                    updated_at = :updated_at
                WHERE file_path = :file_path
            ');
        } else {
            // Insert new
            $stmt = $this->db->prepare('
                INSERT INTO files (
                    file_path, web_path, file_size, modified_time, extension,
                    duration, bitrate, container,
                    video_codec, video_width, video_height, video_fps, video_pix_fmt,
                    audio_codec, audio_channels, audio_sample_rate,
                    text_encoding, is_optimized, optimization_issues, xxhash,
                    created_at, updated_at
                ) VALUES (
                    :file_path, :web_path, :file_size, :modified_time, :extension,
                    :duration, :bitrate, :container,
                    :video_codec, :video_width, :video_height, :video_fps, :video_pix_fmt,
                    :audio_codec, :audio_channels, :audio_sample_rate,
                    :text_encoding, :is_optimized, :optimization_issues, :xxhash,
                    :created_at, :updated_at
                )
            ');
            $stmt->bindValue(':created_at', $now, SQLITE3_INTEGER);
        }
        
        // Bind common values
        $stmt->bindValue(':file_path', $normalizedPath, SQLITE3_TEXT);
        $stmt->bindValue(':web_path', $webPath, SQLITE3_TEXT);
        $stmt->bindValue(':file_size', filesize($fsPath), SQLITE3_INTEGER);
        $stmt->bindValue(':modified_time', filemtime($fsPath), SQLITE3_INTEGER);
        $stmt->bindValue(':extension', pathinfo($fsPath, PATHINFO_EXTENSION), SQLITE3_TEXT);
        $stmt->bindValue(':duration', $metadata['duration'] ?? null, SQLITE3_FLOAT);
        $stmt->bindValue(':bitrate', $metadata['bitrate'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':container', $metadata['container'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':video_codec', $metadata['video']['codec'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':video_width', $metadata['video']['width'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':video_height', $metadata['video']['height'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':video_fps', $metadata['video']['fps'] ?? null, SQLITE3_FLOAT);
        $stmt->bindValue(':video_pix_fmt', $metadata['video']['pix_fmt'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':audio_codec', $metadata['audio']['codec'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':audio_channels', $metadata['audio']['channels'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':audio_sample_rate', $metadata['audio']['sample_rate'] ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':text_encoding', $metadata['text']['encoding'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':is_optimized', $isOptimized, SQLITE3_INTEGER);
        $stmt->bindValue(':optimization_issues', $optimizationIssues, SQLITE3_TEXT);
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $stmt->bindValue(':updated_at', $now, SQLITE3_INTEGER);
        
        return $stmt->execute() !== false;
    }
    
    /**
     * Get optimization statistics for a folder
     */
    public function getOptimizationStats($folderPath) {
        $normalizedFolder = $this->normalizePath($folderPath);
        
        $stmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_optimized = 1 THEN 1 ELSE 0 END) as optimized,
                SUM(CASE WHEN is_optimized = 0 THEN 1 ELSE 0 END) as unoptimized
            FROM files 
            WHERE file_path LIKE :path_prefix
        ');
        
        $stmt->bindValue(':path_prefix', $normalizedFolder . '%', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return [
            'optimized' => (int)($row['optimized'] ?? 0),
            'unoptimized' => (int)($row['unoptimized'] ?? 0),
            'total' => (int)($row['total'] ?? 0)
        ];
    }
    
    /**
     * Get optimization status for multiple files (batch operation)
     * Returns array with absolute paths as keys and optimization status as values
     */
    public function getOptimizationStatusBatch($absolutePaths) {
        if (empty($absolutePaths)) {
            return [];
        }
        
        $results = [];
        
        // Normalize all paths
        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);
        
        // Build IN clause
        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        
        $stmt = $this->db->prepare("
            SELECT file_path, is_optimized, optimization_issues
            FROM files
            WHERE file_path IN ($placeholders)
        ");
        
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        // Initialize all as not having status (will default to optimized in JS)
        foreach ($absolutePaths as $path) {
            $results[$path] = null;
        }
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $isOptimized = (bool)$row['is_optimized'];
            $issues = json_decode($row['optimization_issues'] ?? '[]', true);
            
            $results[$originalPath] = [
                'isOptimized' => $isOptimized,
                'issues' => $issues ?: []
            ];
        }
        
        return $results;
    }
    
    /**
     * Delete metadata for a specific file
     */
    public function deleteMetadata($fsPath) {
        $normalizedPath = $this->normalizePath($fsPath);
        
        $stmt = $this->db->prepare('DELETE FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }
    
    /**
     * Execute a raw SQL query and return result
     */
    public function query($sql) {
        return $this->db->query($sql);
    }
    
    /**
     * Get all files in a folder from database (sorted by modified_time DESC like filesystem scan)
     * Returns array of absolute file paths
     */
    public function getFilesByFolder(string $folderPath): array
    {
        $normalizedFolder = $this->normalizePath($folderPath);
        
        $stmt = $this->db->prepare('
            SELECT file_path FROM files
            WHERE file_path LIKE :path_prefix
            ORDER BY modified_time DESC
        ');
        
        $stmt->bindValue(':path_prefix', $normalizedFolder . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $files = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = $row['file_path'];
        }
        
        return $files;
    }

    /**
     * Get immediate subfolders from database (sorted alphabetically like scandir)
     */
    public function getSubfoldersByFolder(string $folderPath): array
    {
        $normalizedFolder = $this->normalizePath($folderPath);
        $prefix = $normalizedFolder . '/';
        
        $stmt = $this->db->prepare("
            SELECT DISTINCT 
                SUBSTR(file_path, LENGTH(:prefix) + 1, INSTR(SUBSTR(file_path, LENGTH(:prefix) + 1), '/') - 1) as subfolder
            FROM files
            WHERE file_path LIKE :path_wildcard
            AND file_path != :exact_path
            AND INSTR(SUBSTR(file_path, LENGTH(:prefix) + 1), '/') > 0
        ");
        
        $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $stmt->bindValue(':path_wildcard', $prefix . '%', SQLITE3_TEXT);
        $stmt->bindValue(':exact_path', $normalizedFolder, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $folders = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $subfolder = $row['subfolder'] ?? '';
            if ($subfolder !== '' && $subfolder !== false) {
                $folders[] = $subfolder;
            }
        }
        
        usort($folders, 'strcasecmp');
        return array_values(array_unique($folders));
    }

    /**
     * Get folder statistics from database (file counts for dropdown)
     */
    public function getFolderStats(string $folderPath): array
    {
        $normalizedFolder = $this->normalizePath($folderPath);
        
        $stmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_optimized = 1 THEN 1 ELSE 0 END) as optimized,
                SUM(CASE WHEN is_optimized = 0 THEN 1 ELSE 0 END) as unoptimized
            FROM files 
            WHERE file_path LIKE :path_prefix
        ');
        
        $stmt->bindValue(':path_prefix', $normalizedFolder . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return [
            'total' => (int)($row['total'] ?? 0),
            'optimized' => (int)($row['optimized'] ?? 0),
            'unoptimized' => (int)($row['unoptimized'] ?? 0)
        ];
    }

    /**
     * Get folder stats for multiple folders at once (batch operation)
     * Returns array with folder paths as keys
     */
    public function getFolderStatsBatch(array $folderPaths): array
    {
        $results = [];
        
        foreach ($folderPaths as $folderPath) {
            $results[$folderPath] = $this->getFolderStats($folderPath);
        }
        
        return $results;
    }

    /**
     * Convert database row to metadata array
     */
    private function rowToMetadata($row) {
        $metadata = [
            'file' => base64_encode(basename($row['file_path'])),
            'folder' => basename(dirname($row['file_path'])),
            'filesize' => (int)$row['file_size'],
        ];
        
        // Add video metadata if present
        if ($row['video_codec']) {
            $metadata['video'] = [
                'codec' => $row['video_codec'],
                'width' => $row['video_width'] ? (int)$row['video_width'] : null,
                'height' => $row['video_height'] ? (int)$row['video_height'] : null,
                'fps' => $row['video_fps'] ? (float)$row['video_fps'] : null,
                'pix_fmt' => $row['video_pix_fmt'],
            ];
        }
        
        // Add audio metadata if present
        if ($row['audio_codec']) {
            $metadata['audio'] = [
                'codec' => $row['audio_codec'],
                'channels' => $row['audio_channels'] ? (int)$row['audio_channels'] : null,
                'sample_rate' => $row['audio_sample_rate'] ? (int)$row['audio_sample_rate'] : null,
            ];
        }
        
        // Add text metadata if present
        if ($row['text_encoding']) {
            $metadata['text'] = [
                'encoding' => $row['text_encoding'],
            ];
        }
        
        // Add other fields
        if ($row['duration']) {
            $metadata['duration'] = (float)$row['duration'];
        }
        if ($row['bitrate']) {
            $metadata['bitrate'] = (int)$row['bitrate'];
        }
        if ($row['container']) {
            $metadata['container'] = $row['container'];
        }
        if ($row['xxhash']) {
            $metadata['xxhash'] = $row['xxhash'];
        }
        
        // Add optimization status
        $metadata['optimizationStatus'] = [
            'isOptimized' => (bool)$row['is_optimized'],
            'issues' => json_decode($row['optimization_issues'] ?? '[]', true),
        ];
        
        return $metadata;
    }
}
