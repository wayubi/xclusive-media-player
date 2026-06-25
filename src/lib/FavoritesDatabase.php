<?php
// lib/FavoritesDatabase.php - Per-user favorites management with SQLite (metadata_file_id based)

require_once __DIR__ . '/Database.php';

class FavoritesDatabase extends Database
{
    private array $statusCache = [];
    private ?SQLite3 $metaDb = null;

    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/favorites.db';
        parent::__construct($dbPath);
    }

    protected function createTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS favorites (
                metadata_file_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                favorited_at INTEGER NOT NULL,
                UNIQUE(metadata_file_id, user_id)
            );
            CREATE INDEX IF NOT EXISTS idx_fav_user_id ON favorites(user_id);
            CREATE INDEX IF NOT EXISTS idx_fav_metadata_file_id ON favorites(metadata_file_id);
            CREATE INDEX IF NOT EXISTS idx_fav_favorited_at ON favorites(favorited_at);
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

    private function getFileInfo(string $absolutePath): ?array
    {
        $normalizedPath = $this->normalizePath($absolutePath);
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare('SELECT id, xxhash FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    private function getIdsByXxhash(string $xxhash): array
    {
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare('SELECT id FROM files WHERE xxhash = :xxhash');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $result = $stmt->execute();
        $ids = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ids[] = (int)$row['id'];
        }
        return $ids;
    }

    public function toggleFavorite($absolutePath, int $userId) {
        $info = $this->getFileInfo($absolutePath);
        if (!$info || !$info['xxhash']) return null;

        $allIds = $this->getIdsByXxhash($info['xxhash']);
        if (empty($allIds)) return null;

        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $this->db->prepare("SELECT 1 FROM favorites WHERE metadata_file_id IN ($placeholders) AND user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);

        if ($existing) {
            $delPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM favorites WHERE metadata_file_id IN ($delPlaceholders) AND user_id = :user_id");
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            foreach ($allIds as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $stmt->execute();
            return false;
        } else {
            $stmt = $this->db->prepare('INSERT INTO favorites (metadata_file_id, user_id, favorited_at) VALUES (:id, :user_id, :favorited_at)');
            $stmt->bindValue(':id', (int)$info['id'], SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        }
    }

    public function addFavorite($absolutePath, int $userId) {
        $info = $this->getFileInfo($absolutePath);
        if (!$info || !$info['xxhash']) return false;

        $allIds = $this->getIdsByXxhash($info['xxhash']);
        if (empty($allIds)) return false;

        // Skip if any instance of this xxhash is already favorited
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $this->db->prepare("SELECT 1 FROM favorites WHERE metadata_file_id IN ($placeholders) AND user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        if ($result->fetchArray(SQLITE3_ASSOC)) return true; // Already favorited

        $stmt = $this->db->prepare('INSERT INTO favorites (metadata_file_id, user_id, favorited_at) VALUES (:id, :user_id, :favorited_at)');
        $stmt->bindValue(':id', (int)$info['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
        return $stmt->execute() !== false;
    }

    public function removeFavorite($absolutePath) {
        $info = $this->getFileInfo($absolutePath);
        if (!$info || !$info['xxhash']) return false;

        $allIds = $this->getIdsByXxhash($info['xxhash']);
        if (empty($allIds)) return false;

        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM favorites WHERE metadata_file_id IN ($placeholders)");
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
        }
        return $stmt->execute() !== false;
    }

    public function isFavorited($absolutePath, int $userId) {
        $info = $this->getFileInfo($absolutePath);
        if (!$info || !$info['xxhash']) return false;

        $allIds = $this->getIdsByXxhash($info['xxhash']);
        if (empty($allIds)) return false;

        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $this->db->prepare("SELECT 1 FROM favorites WHERE metadata_file_id IN ($placeholders) AND user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
        }
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC) !== false;
    }

    public function getFavoriteStatusBatch($absolutePaths, int $userId) {
        if (empty($absolutePaths)) return [];

        $cacheKey = md5(implode('|', $absolutePaths)) . '_u' . $userId;
        if (isset($this->statusCache[$cacheKey])) return $this->statusCache[$cacheKey];

        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);

        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare("SELECT id, file_path, xxhash FROM files WHERE file_path IN ($placeholders)");
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        $result = $stmt->execute();

        $fileInfo = [];
        $xxhashToPaths = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $fileInfo[$originalPath] = ['id' => (int)$row['id'], 'xxhash' => $row['xxhash']];
            if ($row['xxhash']) {
                $xxhashToPaths[$row['xxhash']][] = $originalPath;
            }
        }

        // Get all metadata_file_ids for all relevant xxhashes
        $allIdsByXxhash = [];
        if (!empty($xxhashToPaths)) {
            $xxhashes = array_keys($xxhashToPaths);
            $hPlaceholders = implode(',', array_fill(0, count($xxhashes), '?'));
            $stmt = $metaDb->prepare("SELECT id, xxhash FROM files WHERE xxhash IN ($hPlaceholders)");
            foreach ($xxhashes as $i => $xxhash) {
                $stmt->bindValue($i + 1, $xxhash, SQLITE3_TEXT);
            }
            $idResult = $stmt->execute();
            while ($row = $idResult->fetchArray(SQLITE3_ASSOC)) {
                $allIdsByXxhash[$row['xxhash']][] = (int)$row['id'];
            }
        }

        // Collect all metadata_file_ids and map back to xxhash
        $idToXxhash = [];
        foreach ($allIdsByXxhash as $xxhash => $ids) {
            foreach ($ids as $id) {
                $idToXxhash[$id] = $xxhash;
            }
        }

        $favoritedXxhashes = [];
        if (!empty($idToXxhash)) {
            $allIds = array_keys($idToXxhash);
            $idPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT metadata_file_id FROM favorites WHERE metadata_file_id IN ($idPlaceholders) AND user_id = :user_id");
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            foreach ($allIds as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $favResult = $stmt->execute();
            while ($row = $favResult->fetchArray(SQLITE3_ASSOC)) {
                $favId = (int)$row['metadata_file_id'];
                if (isset($idToXxhash[$favId])) {
                    $favoritedXxhashes[$idToXxhash[$favId]] = true;
                }
            }
        }

        $results = [];
        foreach ($absolutePaths as $path) {
            $info = $fileInfo[$path] ?? null;
            $results[$path] = [
                'favorited' => $info && $info['xxhash'] && isset($favoritedXxhashes[$info['xxhash']]),
            ];
        }

        $this->statusCache[$cacheKey] = $results;
        return $results;
    }

    public function getFavoritesStatusBatchByFolder(string $folderPath, int $userId): array
    {
        $normalized = $this->normalizePath($folderPath);
        $prefix = str_replace(['%', '_'], ['\\%', '\\_'], $normalized) . '/%';
        $metaDb = $this->getMetadataDb();

        // Step 1: Get all files in the folder with their xxhashes
        $stmt = $metaDb->prepare("SELECT id, file_path, xxhash FROM files WHERE file_path LIKE :prefix ESCAPE '\\'");
        $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $result = $stmt->execute();

        $folderFiles = [];
        $xxhashes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $id = (int)$row['id'];
            $folderFiles[] = ['id' => $id, 'file_path' => $row['file_path'], 'xxhash' => $row['xxhash']];
            if ($row['xxhash']) {
                $xxhashes[] = $row['xxhash'];
            }
        }

        if (empty($folderFiles)) return [];

        // Step 2: Find ALL file IDs sharing those xxhashes (content-addressed dedup)
        $deduped = array_unique($xxhashes);
        $allIdsByXxhash = [];
        foreach (array_chunk($deduped, 900) as $chunk) {
            $hPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $metaDb->prepare("SELECT id, xxhash FROM files WHERE xxhash IN ($hPlaceholders)");
            foreach ($chunk as $i => $xxhash) {
                $stmt->bindValue($i + 1, $xxhash, SQLITE3_TEXT);
            }
            $idResult = $stmt->execute();
            while ($row = $idResult->fetchArray(SQLITE3_ASSOC)) {
                $allIdsByXxhash[$row['xxhash']][] = (int)$row['id'];
            }
        }

        // Step 3: Check which xxhashes are favorited by this user
        $allIds = [];
        $idToXxhash = [];
        foreach ($allIdsByXxhash as $xxhash => $ids) {
            foreach ($ids as $id) {
                $allIds[] = $id;
                $idToXxhash[$id] = $xxhash;
            }
        }

        $favoritedXxhashes = [];
        foreach (array_chunk($allIds, 900) as $chunk) {
            $idPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT metadata_file_id FROM favorites WHERE metadata_file_id IN ($idPlaceholders) AND user_id = ?");
            $stmt->bindValue(count($chunk) + 1, $userId, SQLITE3_INTEGER);
            foreach ($chunk as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $favResult = $stmt->execute();
            while ($row = $favResult->fetchArray(SQLITE3_ASSOC)) {
                $favId = (int)$row['metadata_file_id'];
                if (isset($idToXxhash[$favId])) {
                    $favoritedXxhashes[$idToXxhash[$favId]] = true;
                }
            }
        }

        // Step 4: Build result keyed by file_path
        $statuses = [];
        foreach ($folderFiles as $entry) {
            $statuses[$entry['file_path']] = [
                'favorited' => isset($favoritedXxhashes[$entry['xxhash']]),
            ];
        }
        return $statuses;
    }

    public function getFavoritesCountInFolder($folderPath, int $userId) {
        $normalizedPath = $this->normalizePath($folderPath);
        $metaDb = $this->getMetadataDb();

        // Get all ids and xxhashes in this folder
        $stmt = $metaDb->prepare('SELECT id, xxhash FROM files WHERE file_path LIKE :path AND xxhash IS NOT NULL');
        $stmt->bindValue(':path', $normalizedPath . '%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $xxhashes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $xxhashes[] = $row['xxhash'];
        }

        if (empty($xxhashes)) return 0;

        // Get all metadata_file_ids for these xxhashes (including duplicates outside folder)
        $deduped = array_unique($xxhashes);
        $placeholders = implode(',', array_fill(0, count($deduped), '?'));
        $stmt = $metaDb->prepare("SELECT id FROM files WHERE xxhash IN ($placeholders)");
        foreach ($deduped as $i => $xxhash) {
            $stmt->bindValue($i + 1, $xxhash, SQLITE3_TEXT);
        }
        $idResult = $stmt->execute();

        $allIds = [];
        while ($row = $idResult->fetchArray(SQLITE3_ASSOC)) {
            $allIds[] = (int)$row['id'];
        }

        if (empty($allIds)) return 0;

        $idPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT metadata_file_id) as count FROM favorites WHERE metadata_file_id IN ($idPlaceholders) AND user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
        }
        $favResult = $stmt->execute();
        $row = $favResult->fetchArray(SQLITE3_ASSOC);
        return $row ? (int)$row['count'] : 0;
    }

    public function isFavoritedByAnyUser(string $absolutePath): bool
    {
        $info = $this->getFileInfo($absolutePath);
        if (!$info || !$info['xxhash']) return false;

        $allIds = $this->getIdsByXxhash($info['xxhash']);
        if (empty($allIds)) return false;

        foreach (array_chunk($allIds, 900) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT 1 FROM favorites WHERE metadata_file_id IN ($placeholders) LIMIT 1");
            foreach ($chunk as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $result = $stmt->execute();
            if ($result->fetchArray(SQLITE3_ASSOC)) return true;
        }
        return false;
    }

    public function getFavoritedFilesInFolder(string $folderPath): array
    {
        $normalized = Utils::normalizePath($folderPath);
        $prefix = str_replace(['%', '_'], ['\\%', '\\_'], $normalized) . '/%';
        $metaDb = $this->getMetadataDb();

        // Get all ids and xxhashes in this folder
        $stmt = $metaDb->prepare("SELECT id, xxhash FROM files WHERE file_path LIKE :prefix ESCAPE '\\' AND xxhash IS NOT NULL");
        $stmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $result = $stmt->execute();

        $folderFiles = [];
        $xxhashes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $folderFiles[] = ['id' => (int)$row['id'], 'xxhash' => $row['xxhash']];
            $xxhashes[] = $row['xxhash'];
        }

        if (empty($xxhashes)) return [];

        // Get ALL metadata_file_ids sharing these xxhashes (content-addressed dedup)
        $deduped = array_unique($xxhashes);
        $idToPath = [];
        $idToXxhash = [];
        foreach (array_chunk($deduped, 900) as $chunk) {
            $hPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $metaDb->prepare("SELECT id, file_path, xxhash FROM files WHERE xxhash IN ($hPlaceholders)");
            foreach ($chunk as $i => $xxhash) {
                $stmt->bindValue($i + 1, $xxhash, SQLITE3_TEXT);
            }
            $idResult = $stmt->execute();
            while ($row = $idResult->fetchArray(SQLITE3_ASSOC)) {
                $id = (int)$row['id'];
                $idToPath[$id] = $row['file_path'];
                $idToXxhash[$id] = $row['xxhash'];
            }
        }

        if (empty($idToXxhash)) return [];

        // Find which xxhashes are favorited by any user (chunked to avoid SQLite parameter limit)
        $allIds = array_keys($idToXxhash);
        $protectedHashes = [];
        foreach (array_chunk($allIds, 900) as $chunk) {
            $idPlaceholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT metadata_file_id FROM favorites WHERE metadata_file_id IN ($idPlaceholders)");
            foreach ($chunk as $i => $id) {
                $stmt->bindValue($i + 1, $id, SQLITE3_INTEGER);
            }
            $favResult = $stmt->execute();
            while ($row = $favResult->fetchArray(SQLITE3_ASSOC)) {
                $favId = (int)$row['metadata_file_id'];
                if (isset($idToXxhash[$favId])) {
                    $protectedHashes[$idToXxhash[$favId]] = true;
                }
            }
        }

        // Protect any folder file whose content hash is in the protected set
        $paths = [];
        foreach ($folderFiles as $entry) {
            if (isset($protectedHashes[$entry['xxhash']]) && isset($idToPath[$entry['id']])) {
                $resolved = realpath($idToPath[$entry['id']]);
                if ($resolved !== false) {
                    $paths[] = $resolved;
                }
            }
        }
        return $paths;
    }

    public function __destruct()
    {
        if ($this->metaDb) {
            $this->metaDb->close();
        }
        parent::__destruct();
    }
}
