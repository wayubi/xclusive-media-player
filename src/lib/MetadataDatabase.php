<?php
// lib/MetadataDatabase.php - File metadata management with SQLite

class MetadataDatabase {
    private $db;
    private $dbPath;
    
    private static function executeCommand(string $command, ?string $cwd = null): string {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        
        if (!is_resource($process)) {
            return '';
        }
        
        fclose($pipes[0]);
        
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        proc_close($process);
        
        return $output;
    }
    
    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/../../db/metadata.db';
        $this->initDatabase();
    }
    
    private function initDatabase() {
        // Ensure directory exists
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        
        // Open SQLite database
        $this->db = new SQLite3($this->dbPath);
        
        // Enable WAL mode for better concurrency
        $this->db->exec('PRAGMA journal_mode = WAL;');
        $this->db->exec('PRAGMA busy_timeout = 5000;');
        
        // Create tables if they don't exist
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
    
    /**
     * Get metadata for a single file
     * Returns null if not found or if stale
     */
    public function getMetadata($webPath, $fsPath) {
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
            return null; // Not in database
        }
        
        // Check if stale (modified time or size changed)
        if ($row['modified_time'] != $currentMtime || $row['file_size'] != $currentSize) {
            return null; // Stale, needs refresh
        }
        
        // Build metadata array matching old format
        return $this->rowToMetadata($row);
    }
    
    /**
     * Get metadata for multiple files (batch operation)
     */
    public function getMetadataBatch($webPaths, $root, $webRoot) {
        $results = [];
        
        foreach ($webPaths as $webPath) {
            $fsPath = $this->webToFilesystemPath($webPath, $root, $webRoot);
            $metadata = $this->getMetadata($webPath, $fsPath);
            $results[$webPath] = $metadata;
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
        $xxhashOutput = self::executeCommand('xxhsum ' . escapeshellarg($fsPath));
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
     * Clean up entries for deleted files
     */
    public function cleanupDeletedFiles() {
        $stmt = $this->db->query('SELECT id, file_path FROM files');
        $deleted = 0;
        
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            if (!file_exists($row['file_path'])) {
                $delStmt = $this->db->prepare('DELETE FROM files WHERE id = :id');
                $delStmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
                $delStmt->execute();
                $deleted++;
            }
        }
        
        return $deleted;
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
     * Convert database row to metadata array
     */
    private function rowToMetadata($row) {
        $metadata = [
            'file' => basename($row['file_path']),
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
    
    /**
     * Convert web path to filesystem path
     */
    private function webToFilesystemPath($webPath, $root, $webRoot) {
        $cleanFile = ltrim(preg_replace('#^/volumes/#i', '', $webPath), '/');
        return realpath($root . '/' . $cleanFile);
    }
    
    /**
     * Normalize file path for consistent storage
     */
    private function normalizePath($path) {
        $real = realpath($path);
        if ($real === false) {
            $real = str_replace('\\', '/', $path);
        } else {
            $real = str_replace('\\', '/', $real);
        }
        return $real;
    }
    
    /**
     * Close database connection
     */
    public function close() {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}
