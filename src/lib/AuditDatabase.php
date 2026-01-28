<?php
// lib/AuditDatabase.php - Global audit state management with SQLite

class AuditDatabase {
    private $db;
    private $dbPath;
    
    public function __construct($dbPath = null) {
        $this->dbPath = $dbPath ?? __DIR__ . '/../../db/audit.db';
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
        
        // Create tables if they don't exist
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT NOT NULL UNIQUE,
                file_size INTEGER NOT NULL,
                modified_time INTEGER NOT NULL,
                created_at INTEGER NOT NULL
            );
            
            CREATE INDEX IF NOT EXISTS idx_file_path ON files(file_path);
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                audited_at INTEGER NOT NULL,
                audit_date TEXT NOT NULL,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
            );
            
            CREATE INDEX IF NOT EXISTS idx_file_id ON audit_log(file_id);
            CREATE INDEX IF NOT EXISTS idx_audited_at ON audit_log(audited_at);
        ');
    }
    
    /**
     * Register a file and return its unique ID
     * Uses full absolute path as unique identifier
     */
    public function registerFile($absolutePath) {
        if (!file_exists($absolutePath)) {
            return null;
        }
        
        $fileSize = filesize($absolutePath);
        $modifiedTime = filemtime($absolutePath);
        
        // Normalize path (remove any ./ or ../ and make consistent)
        $normalizedPath = $this->normalizePath($absolutePath);
        
        // Try to insert, ignore if exists
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO files (file_path, file_size, modified_time, created_at)
            VALUES (:path, :size, :mtime, :created)
        ');
        
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
        $stmt->bindValue(':mtime', $modifiedTime, SQLITE3_INTEGER);
        $stmt->bindValue(':created', time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        // Get the file ID
        $stmt = $this->db->prepare('SELECT id FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row ? $row['id'] : null;
    }
    
    /**
     * Mark a file as audited
     */
    public function markAsAudited($absolutePath) {
        $fileId = $this->registerFile($absolutePath);
        if (!$fileId) {
            return false;
        }
        
        $auditDate = date('ymd');
        
        // Check if already audited
        $stmt = $this->db->prepare('
            SELECT id FROM audit_log 
            WHERE file_id = :file_id 
            ORDER BY audited_at DESC 
            LIMIT 1
        ');
        $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existing) {
            // Update existing audit
            $stmt = $this->db->prepare('
                UPDATE audit_log 
                SET audited_at = :audited_at,
                    audit_date = :date
                WHERE id = :id
            ');
            $stmt->bindValue(':audited_at', time(), SQLITE3_INTEGER);
            $stmt->bindValue(':date', $auditDate, SQLITE3_TEXT);
            $stmt->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
        } else {
            // Insert new audit
            $stmt = $this->db->prepare('
                INSERT INTO audit_log (file_id, audit_date, audited_at)
                VALUES (:file_id, :date, :audited_at)
            ');
            $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
            $stmt->bindValue(':date', $auditDate, SQLITE3_TEXT);
            $stmt->bindValue(':audited_at', time(), SQLITE3_INTEGER);
        }
        
        return $stmt->execute() !== false;
    }
    
    /**
     * Check if a file is audited
     */
    public function isAudited($absolutePath) {
        $normalizedPath = $this->normalizePath($absolutePath);
        
        $stmt = $this->db->prepare('
            SELECT al.audit_date, al.audited_at
            FROM audit_log al
            JOIN files f ON f.id = al.file_id
            WHERE f.file_path = :path
            ORDER BY al.audited_at DESC
            LIMIT 1
        ');
        
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row !== false ? $row : null;
    }
    
    /**
     * Get audit info for multiple files (batch operation)
     * Returns array with absolute paths as keys and audit info as values
     */
    public function getAuditStatusBatch($absolutePaths) {
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
            SELECT f.file_path, al.audit_date, al.audited_at
            FROM files f
            LEFT JOIN audit_log al ON f.id = al.file_id
            WHERE f.file_path IN ($placeholders)
            GROUP BY f.file_path
            HAVING al.audited_at = MAX(al.audited_at) OR al.audited_at IS NULL
        ");
        
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $results[$originalPath] = $row['audited_at'] ? [
                'audited' => true,
                'audit_date' => $row['audit_date'],
                'audited_at' => $row['audited_at']
            ] : [
                'audited' => false
            ];
        }
        
        // Fill in missing files as not audited
        foreach ($absolutePaths as $path) {
            if (!isset($results[$path])) {
                $results[$path] = ['audited' => false];
            }
        }
        
        return $results;
    }
    
    /**
     * Batch audit multiple files
     */
    public function auditBatch($absolutePaths) {
        $this->db->exec('BEGIN TRANSACTION');
        
        try {
            $successCount = 0;
            foreach ($absolutePaths as $path) {
                if ($this->markAsAudited($path)) {
                    $successCount++;
                }
            }
            
            $this->db->exec('COMMIT');
            return $successCount;
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        $stmt = $this->db->query('SELECT COUNT(*) as total FROM files');
        $total = $stmt->fetchArray(SQLITE3_ASSOC)['total'];
        
        $stmt = $this->db->query('
            SELECT COUNT(DISTINCT file_id) as audited 
            FROM audit_log
        ');
        $audited = $stmt->fetchArray(SQLITE3_ASSOC)['audited'];
        
        return [
            'total_files' => $total,
            'audited_files' => $audited,
            'unaudited_files' => $total - $audited
        ];
    }
    
    /**
     * Clean up deleted files from database
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
     * Normalize file path for consistent storage
     */
    private function normalizePath($path) {
        // Get real path (resolves symlinks, removes ./ and ../)
        $real = realpath($path);
        if ($real === false) {
            // If file doesn't exist yet, normalize the path string
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