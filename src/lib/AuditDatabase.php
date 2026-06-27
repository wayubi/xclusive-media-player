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

    public function getAuditStatusBatchByFolder(string $folderPath): array
    {
        $normalized = $this->normalizePath($folderPath);
        @$this->db->exec("ATTACH DATABASE '" . __DIR__ . "/../../db/metadata.db' AS meta");
        $stmt = $this->db->prepare("
            SELECT f.file_path, al.audited_at, al.audit_date
            FROM meta.files f
            LEFT JOIN audit_log al ON al.metadata_file_id = f.id
            WHERE f.file_path LIKE :prefix
        ");
        $stmt->bindValue(':prefix', $normalized . '/%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $statuses = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $statuses[$row['file_path']] = [
                'audited'    => $row['audited_at'] !== null,
                'audit_date' => $row['audit_date'] ?? '',
                'audited_at' => $row['audited_at'] ?? 0,
            ];
        }
        return $statuses;
    }

    public function getFolderStatsBatch(array $folderPaths, array $totalCounts = []): array
    {
        if (empty($folderPaths)) {
            return [];
        }

        $normalizedFolders = array_map([$this, 'normalizePath'], $folderPaths);

        // Use the first folder as the umbrella prefix — files under it cover
        // all subfolder paths in a single indexed range scan, avoiding a slow
        // multi-OR chain that would force a full table scan.
        $metaDb = $this->getMetadataDb();
        $umbrella = $normalizedFolders[0];
        $prefix = $umbrella . '/';

        $stmt = $metaDb->prepare("SELECT id, file_path FROM files WHERE file_path LIKE :prefix");
        $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $result = $stmt->execute();

        $folderFileIds = [];
        $allIds = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $fp = $row['file_path'];
            $fid = (int)$row['id'];

            foreach ($normalizedFolders as $folderPath) {
                $p = $folderPath . '/';
                if (str_starts_with($fp, $p)) {
                    $relativePath = substr($fp, strlen($p));
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
            $uniqueIds = array_values(array_unique($allIds));
            $idPlaceholders = implode(',', array_fill(0, count($uniqueIds), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT metadata_file_id FROM audit_log WHERE metadata_file_id IN ($idPlaceholders)");
            foreach ($uniqueIds as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $auditedIds[(int)$row['metadata_file_id']] = true;
            }
        }

        foreach ($normalizedFolders as $folderPath) {
            $total = $totalCounts[$folderPath] ?? 0;
            $fileIds = array_unique($folderFileIds[$folderPath] ?? []);
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
