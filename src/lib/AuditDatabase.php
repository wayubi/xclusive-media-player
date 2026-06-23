<?php
// lib/AuditDatabase.php - Audit state management with SQLite (metadata_file_id based)

require_once __DIR__ . '/Database.php';

class AuditDatabase extends Database
{
    private array $statusCache = [];
    private ?SQLite3 $metaDb = null;

    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/audit.db';
        parent::__construct($dbPath);
    }

    protected function createTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS audit_log (
                metadata_file_id INTEGER NOT NULL UNIQUE,
                audited_at INTEGER NOT NULL,
                audit_date TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_audit_mfid ON audit_log(metadata_file_id);
        ');
    }

    private function getMetadataDb(): SQLite3
    {
        if ($this->metaDb === null) {
            $this->metaDb = new SQLite3(__DIR__ . '/../../db/metadata.db');
            $this->metaDb->busyTimeout(5000);
        }
        return $this->metaDb;
    }

    private function getMetadataId(string $absolutePath): ?int
    {
        $normalizedPath = $this->normalizePath($absolutePath);
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare('SELECT id FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? (int)$row['id'] : null;
    }

    private function getMetadataIdBatch(array $absolutePaths): array
    {
        if (empty($absolutePaths)) return [];

        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);

        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare("SELECT id, file_path FROM files WHERE file_path IN ($placeholders)");
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        $result = $stmt->execute();

        $pathToId = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $pathToId[$originalPath] = (int)$row['id'];
        }
        return $pathToId;
    }

    public function markAsAudited($absolutePath) {
        $metaId = $this->getMetadataId($absolutePath);
        if (!$metaId) {
            return false;
        }

        $auditDate = date('ymd');
        $now = time();

        $stmt = $this->db->prepare('
            INSERT OR REPLACE INTO audit_log (metadata_file_id, audited_at, audit_date)
            VALUES (:id, :audited_at, :date)
        ');
        $stmt->bindValue(':id', $metaId, SQLITE3_INTEGER);
        $stmt->bindValue(':audited_at', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':date', $auditDate, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    public function isAudited($absolutePath) {
        $metaId = $this->getMetadataId($absolutePath);
        if (!$metaId) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT audit_date, audited_at FROM audit_log WHERE metadata_file_id = :id');
        $stmt->bindValue(':id', $metaId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row !== false ? $row : null;
    }

    public function getAuditStatusBatch($absolutePaths) {
        if (empty($absolutePaths)) {
            return [];
        }

        $pathToId = $this->getMetadataIdBatch($absolutePaths);
        $results = [];

        if (!empty($pathToId)) {
            $idPlaceholders = implode(',', array_fill(0, count($pathToId), '?'));
            $stmt = $this->db->prepare("SELECT metadata_file_id, audited_at, audit_date FROM audit_log WHERE metadata_file_id IN ($idPlaceholders)");
            $i = 1;
            foreach ($pathToId as $id) {
                $stmt->bindValue($i++, $id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();

            $idToPath = array_flip($pathToId);
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $originalPath = $idToPath[(int)$row['metadata_file_id']];
                $results[$originalPath] = [
                    'audited' => true,
                    'audit_date' => $row['audit_date'],
                    'audited_at' => $row['audited_at']
                ];
            }
        }

        foreach ($absolutePaths as $path) {
            if (!isset($results[$path])) {
                $results[$path] = ['audited' => false];
            }
        }

        return $results;
    }

    public function getFolderStatsBatch(array $folderPaths, array $totalCounts = []): array
    {
        $results = [];

        if (empty($folderPaths)) {
            return $results;
        }

        $normalizedFolders = array_map([$this, 'normalizePath'], $folderPaths);

        $metaDb = $this->getMetadataDb();
        $conditions = [];
        foreach ($normalizedFolders as $folder) {
            $conditions[] = "file_path LIKE '" . $metaDb->escapeString($folder) . "/%'";
        }
        $whereClause = implode(' OR ', $conditions);

        if (empty($whereClause)) return $results;

        $stmt = $metaDb->prepare("SELECT id, file_path FROM files WHERE $whereClause");
        $result = $stmt->execute();

        $folderFileIds = [];
        $allIds = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $fp = $row['file_path'];
            $fid = (int)$row['id'];

            foreach ($normalizedFolders as $folderPath) {
                $prefix = $folderPath . '/';
                if (str_starts_with($fp, $prefix)) {
                    $relativePath = substr($fp, strlen($prefix));
                    $firstSlash = strpos($relativePath, '/');
                    $key = ($firstSlash === false) ? $folderPath : $folderPath . '/' . substr($relativePath, 0, $firstSlash);
                    $folderFileIds[$key][] = $fid;
                    $allIds[] = $fid;
                    break;
                }
            }
        }

        $auditedIds = [];
        if (!empty($allIds)) {
            $idPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT metadata_file_id FROM audit_log WHERE metadata_file_id IN ($idPlaceholders)");
            foreach ($allIds as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $auditedIds[(int)$row['metadata_file_id']] = true;
            }
        }

        foreach ($normalizedFolders as $folderPath) {
            $total = $totalCounts[$folderPath] ?? 0;
            $fileIds = $folderFileIds[$folderPath] ?? [];
            $audited = 0;
            foreach ($fileIds as $fid) {
                if (isset($auditedIds[$fid])) $audited++;
            }

            if ($total === 0) {
                $results[$folderPath] = ['status' => 'none_audited', 'total' => 0, 'audited' => 0];
            } elseif ($audited === 0) {
                $results[$folderPath] = ['status' => 'none_audited', 'total' => $total, 'audited' => 0];
            } elseif ($audited >= $total) {
                $results[$folderPath] = ['status' => 'all_audited', 'total' => $total, 'audited' => $audited];
            } else {
                $results[$folderPath] = ['status' => 'some_audited', 'total' => $total, 'audited' => $audited];
            }
        }

        return $results;
    }

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

    public function deleteFile(string $absolutePath): bool
    {
        $metaId = $this->getMetadataId($absolutePath);
        if (!$metaId) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM audit_log WHERE metadata_file_id = :id');
        $stmt->bindValue(':id', $metaId, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    public function getStats() {
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->query('SELECT COUNT(*) as total FROM files');
        $total = $stmt->fetchArray(SQLITE3_ASSOC)['total'];

        $stmt = $this->db->query('SELECT COUNT(*) as audited FROM audit_log');
        $audited = $stmt->fetchArray(SQLITE3_ASSOC)['audited'];

        return [
            'total_files' => $total,
            'audited_files' => $audited,
            'unaudited_files' => $total - $audited
        ];
    }

    public function __destruct()
    {
        if ($this->metaDb) {
            $this->metaDb->close();
        }
        parent::__destruct();
    }
}
