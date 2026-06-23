<?php
// lib/FavoritesDatabase.php - Per-user favorites management with SQLite (xxhash-based)

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
                xxhash TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                favorited_at INTEGER NOT NULL,
                UNIQUE(xxhash, user_id)
            );
            CREATE INDEX IF NOT EXISTS idx_fav_user_id ON favorites(user_id);
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

    private function getXxhash(string $absolutePath): ?string
    {
        $normalizedPath = $this->normalizePath($absolutePath);
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare('SELECT xxhash FROM files WHERE file_path = :path');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['xxhash'] : null;
    }

    private function getXxhashBatch(array $absolutePaths): array
    {
        if (empty($absolutePaths)) return [];

        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);

        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare("SELECT file_path, xxhash FROM files WHERE file_path IN ($placeholders)");
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        $result = $stmt->execute();

        $xxhashMap = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $xxhashMap[$originalPath] = $row['xxhash'];
        }
        return $xxhashMap;
    }

    public function toggleFavorite($absolutePath, int $userId) {
        $xxhash = $this->getXxhash($absolutePath);
        if (!$xxhash) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT id FROM favorites WHERE xxhash = :xxhash AND user_id = :user_id');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);

        if ($existing) {
            $stmt = $this->db->prepare('DELETE FROM favorites WHERE xxhash = :xxhash AND user_id = :user_id');
            $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            return false;
        } else {
            $stmt = $this->db->prepare('INSERT INTO favorites (xxhash, user_id, favorited_at) VALUES (:xxhash, :user_id, :favorited_at)');
            $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        }
    }

    public function addFavorite($absolutePath, int $userId) {
        $xxhash = $this->getXxhash($absolutePath);
        if (!$xxhash) {
            return false;
        }

        $stmt = $this->db->prepare('INSERT OR IGNORE INTO favorites (xxhash, user_id, favorited_at) VALUES (:xxhash, :user_id, :favorited_at)');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    public function removeFavorite($absolutePath) {
        $xxhash = $this->getXxhash($absolutePath);
        if (!$xxhash) {
            return false;
        }

        $stmt = $this->db->prepare('DELETE FROM favorites WHERE xxhash = :xxhash');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);

        return $stmt->execute() !== false;
    }

    public function isFavorited($absolutePath, int $userId) {
        $xxhash = $this->getXxhash($absolutePath);
        if (!$xxhash) {
            return false;
        }

        $stmt = $this->db->prepare('SELECT favorited_at FROM favorites WHERE xxhash = :xxhash AND user_id = :user_id');
        $stmt->bindValue(':xxhash', $xxhash, SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row !== false;
    }

    public function getFavoriteStatusBatch($absolutePaths, int $userId) {
        if (empty($absolutePaths)) {
            return [];
        }

        $cacheKey = md5(implode('|', $absolutePaths)) . '_u' . $userId;
        if (isset($this->statusCache[$cacheKey])) {
            return $this->statusCache[$cacheKey];
        }

        $xxhashMap = $this->getXxhashBatch($absolutePaths);

        $xxhashToPaths = [];
        foreach ($xxhashMap as $path => $xxhash) {
            if ($xxhash) {
                $xxhashToPaths[$xxhash][] = $path;
            }
        }

        $favoritedXxhashes = [];
        if (!empty($xxhashToPaths)) {
            $placeholders = implode(',', array_fill(0, count($xxhashToPaths), '?'));
            $stmt = $this->db->prepare("SELECT DISTINCT xxhash FROM favorites WHERE xxhash IN ($placeholders) AND user_id = :user_id");
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $i = 1;
            foreach ($xxhashToPaths as $xxhash => $_) {
                $stmt->bindValue($i++, $xxhash, SQLITE3_TEXT);
            }
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $favoritedXxhashes[$row['xxhash']] = true;
            }
        }

        $results = [];
        foreach ($absolutePaths as $path) {
            $xxhash = $xxhashMap[$path] ?? null;
            $results[$path] = [
                'favorited' => $xxhash && isset($favoritedXxhashes[$xxhash]),
            ];
        }

        $this->statusCache[$cacheKey] = $results;

        return $results;
    }

    public function getFavoritesCountInFolder($folderPath, int $userId) {
        $normalizedPath = $this->normalizePath($folderPath);

        $metaDb = $this->getMetadataDb();
        $stmt = $metaDb->prepare('SELECT xxhash FROM files WHERE file_path LIKE :path AND xxhash IS NOT NULL');
        $stmt->bindValue(':path', $normalizedPath . '%', SQLITE3_TEXT);
        $result = $stmt->execute();

        $xxhashes = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $xxhashes[] = $row['xxhash'];
        }

        if (empty($xxhashes)) return 0;

        $deduped = array_unique($xxhashes);
        $placeholders = implode(',', array_fill(0, count($deduped), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT xxhash) as count FROM favorites WHERE xxhash IN ($placeholders) AND user_id = :user_id");
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $i = 1;
        foreach ($deduped as $xxhash) {
            $stmt->bindValue($i++, $xxhash, SQLITE3_TEXT);
        }
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ? (int)$row['count'] : 0;
    }

    public function __destruct()
    {
        if ($this->metaDb) {
            $this->metaDb->close();
        }
        parent::__destruct();
    }
}
