<?php

require_once __DIR__ . '/Utils.php';

abstract class Database
{
    protected $db;
    protected $dbPath;

    protected function __construct(string $dbPath)
    {
        $this->dbPath = $dbPath;
        $this->initDatabase();
    }

    protected function initDatabase(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->db = new SQLite3($this->dbPath);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->busyTimeout(5000);

        $this->createTables();
    }

    abstract protected function createTables(): void;

    public function normalizePath(string $path): string
    {
        return Utils::normalizePath($path);
    }

    protected function registerFile(string $absolutePath): ?int
    {
        if (!file_exists($absolutePath)) {
            return null;
        }
        
        $fileSize = @filesize($absolutePath);
        $modifiedTime = @filemtime($absolutePath);
        $normalizedPath = $this->normalizePath($absolutePath);
        
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO files (file_path, file_size, modified_time, created_at)
            VALUES (:path, :size, :mtime, :created)
        ');
        
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
        $stmt->bindValue(':mtime', $modifiedTime, SQLITE3_INTEGER);
        $stmt->bindValue(':created', time(), SQLITE3_INTEGER);
        $stmt->execute();
        
        $stmt = $this->db->prepare('SELECT id FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row ? (int)$row['id'] : null;
    }

    protected function getFileId(string $absolutePath): ?int
    {
        $normalizedPath = $this->normalizePath($absolutePath);
        $stmt = $this->db->prepare('SELECT id FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    public function cleanupDeletedFiles(): int
    {
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

    public function close(): void
    {
        if ($this->db) {
            $this->db->close();
        }
    }
    
    public function getDb(): SQLite3
    {
        return $this->db;
    }

    public function __destruct()
    {
        $this->close();
    }
}
