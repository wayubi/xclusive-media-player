<?php

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
        $this->db->exec('PRAGMA journal_mode = WAL;');
        $this->db->exec('PRAGMA busy_timeout = 5000;');

        $this->createTables();
    }

    abstract protected function createTables(): void;

    public function normalizePath(string $path): string
    {
        return Utils::normalizePath($path);
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

    public function __destruct()
    {
        $this->close();
    }
}
