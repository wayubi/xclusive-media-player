<?php
// lib/AuditDatabase.php - Global audit state management with SQLite

require_once __DIR__ . '/Database.php';

class AuditDatabase extends Database
{
    private array $statusCache = [];
    private int $cacheTtl = 60;

    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/audit.db';
        parent::__construct($dbPath);
    }

    protected function createTables(): void
    {
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
    public function registerFile($absolutePath): ?int
    {
        return parent::registerFile($absolutePath);
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
     * Get audit status for multiple folders at once (batch operation)
     * Returns array with folder paths as keys and status as values
     * Uses database as source of truth with SQL-level filtering
     */
    public function getFolderStatsBatch(array $folderPaths): array
    {
        $results = [];
        
        if (empty($folderPaths)) {
            return $results;
        }
        
        // Normalize all folder paths
        $normalizedFolders = [];
        foreach ($folderPaths as $folderPath) {
            $normalizedFolders[] = $this->normalizePath($folderPath);
        }
        
        // Build SQL WHERE clause to filter only files in requested folders
        $conditions = [];
        foreach ($normalizedFolders as $folder) {
            $conditions[] = "file_path LIKE '" . $this->db->escapeString($folder) . "/%'";
        }
        $whereClause = implode(' OR ', $conditions);
        
        $stmt = $this->db->prepare("
            SELECT 
                f.file_path,
                MAX(al.audited_at) as last_audit_at
            FROM files f
            LEFT JOIN audit_log al ON f.id = al.file_id
            WHERE $whereClause
            GROUP BY f.file_path
        ");
        
        $result = $stmt->execute();
        
        // Group files by folder (or immediate subfolder)
        $folderCounts = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $filePath = $row['file_path'];
            
            foreach ($normalizedFolders as $folderPath) {
                $prefix = $folderPath . '/';
                if (str_starts_with($filePath, $prefix)) {
                    $relativePath = substr($filePath, strlen($prefix));
                    $firstSlash = strpos($relativePath, '/');
                    
                    if ($firstSlash === false) {
                        // File is directly in folder
                        if (!isset($folderCounts[$folderPath])) {
                            $folderCounts[$folderPath] = ['total' => 0, 'audited' => 0];
                        }
                        $folderCounts[$folderPath]['total']++;
                        if ($row['last_audit_at']) {
                            $folderCounts[$folderPath]['audited']++;
                        }
                    } else {
                        // File is in subfolder - use subfolder as key
                        $subfolder = substr($relativePath, 0, $firstSlash);
                        $subfolderPath = $folderPath . '/' . $subfolder;
                        
                        if (!isset($folderCounts[$subfolderPath])) {
                            $folderCounts[$subfolderPath] = ['total' => 0, 'audited' => 0];
                        }
                        $folderCounts[$subfolderPath]['total']++;
                        if ($row['last_audit_at']) {
                            $folderCounts[$subfolderPath]['audited']++;
                        }
                    }
                    break;
                }
            }
        }
        
        // Determine status for each folder
        foreach ($normalizedFolders as $folderPath) {
            $counts = $folderCounts[$folderPath] ?? ['total' => 0, 'audited' => 0];
            
            if ($counts['total'] === 0) {
                $results[$folderPath] = ['status' => 'all_audited', 'total' => 0, 'audited' => 0];
            } elseif ($counts['audited'] === $counts['total']) {
                $results[$folderPath] = ['status' => 'all_audited', 'total' => $counts['total'], 'audited' => $counts['audited']];
            } elseif ($counts['audited'] === 0) {
                $results[$folderPath] = ['status' => 'none_audited', 'total' => $counts['total'], 'audited' => 0];
            } else {
                $results[$folderPath] = ['status' => 'some_audited', 'total' => $counts['total'], 'audited' => $counts['audited']];
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
}
