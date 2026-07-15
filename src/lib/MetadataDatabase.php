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
                filename TEXT,
                
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
            CREATE INDEX IF NOT EXISTS idx_filename ON files(filename);
            CREATE INDEX IF NOT EXISTS idx_files_xxhash ON files(xxhash);

            CREATE TABLE IF NOT EXISTS folder_cache (
                folder_path TEXT NOT NULL,
                file_path TEXT NOT NULL,
                filename TEXT NOT NULL,
                sort_modified INTEGER,
                sort_name TEXT,
                sort_duration REAL,
                sort_filesize INTEGER,
                sort_resolution INTEGER,
                sort_bitrate INTEGER,
                sort_fps REAL,
                PRIMARY KEY (folder_path, file_path)
            );
            CREATE INDEX IF NOT EXISTS idx_cache_mod ON folder_cache(folder_path, sort_modified);
            CREATE INDEX IF NOT EXISTS idx_cache_name ON folder_cache(folder_path, sort_name);
            CREATE INDEX IF NOT EXISTS idx_cache_dur ON folder_cache(folder_path, sort_duration);
            CREATE INDEX IF NOT EXISTS idx_cache_size ON folder_cache(folder_path, sort_filesize);
            CREATE INDEX IF NOT EXISTS idx_cache_res ON folder_cache(folder_path, sort_resolution);
            CREATE INDEX IF NOT EXISTS idx_cache_bit ON folder_cache(folder_path, sort_bitrate);
            CREATE INDEX IF NOT EXISTS idx_cache_fps ON folder_cache(folder_path, sort_fps);
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
        $now            = time();

        $isOptimized        = 1;
        $optimizationIssues = null;
        if (isset($metadata['optimizationStatus'])) {
            $isOptimized        = $metadata['optimizationStatus']['isOptimized'] ? 1 : 0;
            $optimizationIssues = json_encode($metadata['optimizationStatus']['issues'] ?? []);
        }

        // Compute xxhash BEFORE touching the DB so the write transaction
        // isn't held open while waiting for a shell exec to finish.
        $xxhash = null;
        $xxhashOutput = Utils::executeCommand('xxhsum ' . escapeshellarg($fsPath));
        if ($xxhashOutput) {
            $xxhash = explode(' ', trim($xxhashOutput))[0] ?? null;
        }

        // Single UPSERT: no prior SELECT needed, no stale-snapshot risk.
        // ON CONFLICT preserves the original id and created_at of existing rows.
        $stmt = $this->db->prepare('
            INSERT INTO files (
                file_path, web_path, file_size, modified_time, extension, filename,
                duration, bitrate, container,
                video_codec, video_width, video_height, video_fps, video_pix_fmt,
                audio_codec, audio_channels, audio_sample_rate,
                text_encoding, is_optimized, optimization_issues, xxhash,
                created_at, updated_at
            ) VALUES (
                :file_path, :web_path, :file_size, :modified_time, :extension, :filename,
                :duration, :bitrate, :container,
                :video_codec, :video_width, :video_height, :video_fps, :video_pix_fmt,
                :audio_codec, :audio_channels, :audio_sample_rate,
                :text_encoding, :is_optimized, :optimization_issues, :xxhash,
                :now, :now
            )
            ON CONFLICT(file_path) DO UPDATE SET
                web_path            = excluded.web_path,
                file_size           = excluded.file_size,
                modified_time       = excluded.modified_time,
                extension           = excluded.extension,
                filename            = excluded.filename,
                duration            = excluded.duration,
                bitrate             = excluded.bitrate,
                container           = excluded.container,
                video_codec         = excluded.video_codec,
                video_width         = excluded.video_width,
                video_height        = excluded.video_height,
                video_fps           = excluded.video_fps,
                video_pix_fmt       = excluded.video_pix_fmt,
                audio_codec         = excluded.audio_codec,
                audio_channels      = excluded.audio_channels,
                audio_sample_rate   = excluded.audio_sample_rate,
                text_encoding       = excluded.text_encoding,
                is_optimized        = excluded.is_optimized,
                optimization_issues = excluded.optimization_issues,
                xxhash              = excluded.xxhash,
                updated_at          = excluded.updated_at
        ');

        $stmt->bindValue(':file_path',          $normalizedPath,                          SQLITE3_TEXT);
        $stmt->bindValue(':web_path',           $webPath,                                 SQLITE3_TEXT);
        $stmt->bindValue(':file_size',          filesize($fsPath),                        SQLITE3_INTEGER);
        $stmt->bindValue(':modified_time',      filemtime($fsPath),                       SQLITE3_INTEGER);
        $stmt->bindValue(':extension',          pathinfo($fsPath, PATHINFO_EXTENSION),    SQLITE3_TEXT);
        $stmt->bindValue(':filename',           basename($fsPath),                        SQLITE3_TEXT);
        $stmt->bindValue(':duration',           $metadata['duration']            ?? null, SQLITE3_FLOAT);
        $stmt->bindValue(':bitrate',            $metadata['bitrate']             ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':container',          $metadata['container']           ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':video_codec',        $metadata['video']['codec']      ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':video_width',        $metadata['video']['width']      ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':video_height',       $metadata['video']['height']     ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':video_fps',          $metadata['video']['fps']        ?? null, SQLITE3_FLOAT);
        $stmt->bindValue(':video_pix_fmt',      $metadata['video']['pix_fmt']    ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':audio_codec',        $metadata['audio']['codec']      ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':audio_channels',     $metadata['audio']['channels']   ?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':audio_sample_rate',  $metadata['audio']['sample_rate']?? null, SQLITE3_INTEGER);
        $stmt->bindValue(':text_encoding',      $metadata['text']['encoding']    ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':is_optimized',       $isOptimized,                             SQLITE3_INTEGER);
        $stmt->bindValue(':optimization_issues',$optimizationIssues,                      SQLITE3_TEXT);
        $stmt->bindValue(':xxhash',             $xxhash,                                  SQLITE3_TEXT);
        $stmt->bindValue(':now',                $now,                                      SQLITE3_INTEGER);

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
        $stmt->execute();

        $cacheStmt = $this->db->prepare('DELETE FROM folder_cache WHERE file_path = :path');
        $cacheStmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        return $cacheStmt->execute() !== false;
    }

    public function findMoveCandidate(string $xxhash, int $fileSize, int $modifiedTime, string $currentPath): ?array
    {
        $normalizedPath = $this->normalizePath($currentPath);
        $stmt = $this->db->prepare('
            SELECT id, file_path, web_path FROM files
            WHERE xxhash = :xxhash AND file_size = :size AND modified_time = :mtime
            AND file_path != :current
        ');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
        $stmt->bindValue(':mtime', $modifiedTime, SQLITE3_INTEGER);
        $stmt->bindValue(':current', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!file_exists($row['file_path'])) {
                return $row;
            }
        }
        return null;
    }

    public function updateFilePaths(int $id, string $newPath, string $newWebPath): bool
    {
        $normalizedPath = $this->normalizePath($newPath);
        $stmt = $this->db->prepare('UPDATE files SET file_path = :path, web_path = :web, filename = :filename, extension = :extension, updated_at = :updated WHERE id = :id');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $stmt->bindValue(':web', $newWebPath, SQLITE3_TEXT);
        $stmt->bindValue(':filename', basename($newPath), SQLITE3_TEXT);
        $stmt->bindValue(':extension', pathinfo($newPath, PATHINFO_EXTENSION), SQLITE3_TEXT);
        $stmt->bindValue(':updated', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function rebuildFolderCache(): void
    {
        $this->db->exec('DELETE FROM folder_cache');

        $result = $this->db->query('
            SELECT file_path, filename, modified_time, duration, file_size,
                   video_width, video_height, bitrate, video_fps
            FROM files
        ');

        $this->db->exec('BEGIN TRANSACTION');
        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO folder_cache
                (folder_path, file_path, filename, sort_modified, sort_name,
                 sort_duration, sort_filesize, sort_resolution, sort_bitrate, sort_fps)
            VALUES
                (:folder, :path, :name, :mod, :name,
                 :dur, :size, :res, :bit, :fps)
        ');

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $folder = dirname($row['file_path']);
            $stmt->bindValue(':folder', $folder, SQLITE3_TEXT);
            $stmt->bindValue(':path', $row['file_path'], SQLITE3_TEXT);
            $stmt->bindValue(':name', $row['filename'] ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':mod', $row['modified_time'], SQLITE3_INTEGER);
            $stmt->bindValue(':dur', $row['duration'] ?? 0, SQLITE3_FLOAT);
            $stmt->bindValue(':size', $row['file_size'], SQLITE3_INTEGER);
            $stmt->bindValue(':res', ($row['video_width'] ?? 0) * ($row['video_height'] ?? 0), SQLITE3_INTEGER);
            $stmt->bindValue(':bit', $row['bitrate'] ?? 0, SQLITE3_INTEGER);
            $stmt->bindValue(':fps', $row['video_fps'] ?? 0, SQLITE3_FLOAT);
            $stmt->execute();
        }
        $this->db->exec('COMMIT');
    }

    public function getFilesFromFolder(string $folderPath, string $sortField = 'modified', string $sortDirection = 'desc'): array
    {
        $normalizedFolder = $this->normalizePath($folderPath);

        $sortColumn = match ($sortField) {
            'name'       => 'sort_name',
            'duration'   => 'sort_duration',
            'filesize'   => 'sort_filesize',
            'resolution' => 'sort_resolution',
            'bitrate'    => 'sort_bitrate',
            'fps'        => 'sort_fps',
            default      => 'sort_modified',
        };
        $direction = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';

        $stmt = $this->db->prepare("
            SELECT file_path, filename FROM folder_cache
            WHERE folder_path = :folder OR folder_path LIKE :prefix
            ORDER BY $sortColumn $direction
        ");
        $stmt->bindValue(':folder', $normalizedFolder, SQLITE3_TEXT);
        $stmt->bindValue(':prefix', $normalizedFolder . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $files = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'file_path' => $row['file_path'],
                'filename'  => $row['filename'] ?? basename($row['file_path']),
            ];
        }

        if ($sortField === 'name') {
            usort($files, function ($a, $b) use ($sortDirection) {
                $cmp = strnatcmp($a['filename'], $b['filename']);
                return $sortDirection === 'asc' ? $cmp : -$cmp;
            });
        }

        return array_map(fn($f) => $f['file_path'], $files);
    }

    public function getOptimizationStatusBatchByFolder(string $folderPath): array
    {
        $normalized = $this->normalizePath($folderPath);
        $stmt = $this->db->prepare("
            SELECT file_path, is_optimized, optimization_issues
            FROM files
            WHERE file_path LIKE :prefix
        ");
        $stmt->bindValue(':prefix', $normalized . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $statuses = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $statuses[$row['file_path']] = [
                'isOptimized' => (bool)$row['is_optimized'],
                'issues'      => json_decode($row['optimization_issues'] ?? '[]', true) ?: [],
            ];
        }
        return $statuses;
    }

    /**
     * Execute a raw SQL query and return result
     */
    public function query($sql) {
        return $this->db->query($sql);
    }
    
    /**
     * Get all files in a folder from database with dynamic sorting
     * Returns array of absolute file paths
     * 
     * @param string $folderPath The folder path to scan
     * @param string $sortField Field to sort by: modified, name, duration, filesize, resolution, bitrate, fps
     * @param string $sortDirection Direction: asc or desc
     * @return array Array of file paths
     */
    public function getFilesByFolder(string $folderPath, string $sortField = 'modified', string $sortDirection = 'desc'): array
    {
        $normalizedFolder = $this->normalizePath($folderPath);
        
        // Build ORDER BY clause based on sort parameters
        $orderBy = $this->buildOrderByClause($sortField, $sortDirection);
        
        $stmt = $this->db->prepare("
            SELECT file_path, filename FROM files
            WHERE file_path LIKE :path_prefix
            $orderBy
        ");
        
        $stmt->bindValue(':path_prefix', $normalizedFolder . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();
        
        $files = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $files[] = [
                'file_path' => $row['file_path'],
                'filename' => $row['filename'] ?? basename($row['file_path'])
            ];
        }
        
        // Apply natural sorting for name field in PHP (handles numeric strings correctly)
        if ($sortField === 'name') {
            usort($files, function($a, $b) use ($sortDirection) {
                $cmp = strnatcmp($a['filename'], $b['filename']);
                return $sortDirection === 'asc' ? $cmp : -$cmp;
            });
        }
        
        // Extract just file_path for backward compatibility
        return array_map(fn($f) => $f['file_path'], $files);
    }
    
    /**
     * Build SQL ORDER BY clause based on sort field and direction
     */
    private function buildOrderByClause(string $sortField, string $sortDirection): string
    {
        // Normalize direction
        $direction = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';
        
        // Map sort field to SQL column/expression
        switch ($sortField) {
            case 'name':
                // Sort by filename column (stored as basename)
                return "ORDER BY COALESCE(filename, '') $direction";
                
            case 'duration':
                return "ORDER BY COALESCE(duration, 0) $direction";
                
            case 'filesize':
                return "ORDER BY file_size $direction";
                
            case 'resolution':
                // Sort by pixel count (width * height)
                return "ORDER BY COALESCE(video_width * video_height, 0) $direction";
                
            case 'bitrate':
                return "ORDER BY COALESCE(bitrate, 0) $direction";
                
            case 'fps':
                return "ORDER BY COALESCE(video_fps, 0) $direction";
                
            case 'modified':
            default:
                return "ORDER BY modified_time $direction";
        }
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
        if (empty($folderPaths)) {
            return [];
        }

        $results = [];
        $stmt = $this->db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_optimized = 1 THEN 1 ELSE 0 END) as optimized,
                SUM(CASE WHEN is_optimized = 0 THEN 1 ELSE 0 END) as unoptimized
            FROM files 
            WHERE file_path LIKE :path_prefix
        ');

        foreach ($folderPaths as $folderPath) {
            $normalizedFolder = $this->normalizePath($folderPath);
            $stmt->bindValue(':path_prefix', $normalizedFolder . '/%', SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $results[$folderPath] = [
                'total' => (int)($row['total'] ?? 0),
                'optimized' => (int)($row['optimized'] ?? 0),
                'unoptimized' => (int)($row['unoptimized'] ?? 0)
            ];
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
