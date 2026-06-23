<?php
// lib/FavoritesDatabase.php - Per-user favorites management with SQLite

require_once __DIR__ . '/Database.php';

class FavoritesDatabase extends Database
{
    private array $statusCache = [];
    private int $cacheTtl = 60;

    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/favorites.db';
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
            CREATE TABLE IF NOT EXISTS favorites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                favorited_at INTEGER NOT NULL,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
            );
            
            CREATE UNIQUE INDEX IF NOT EXISTS idx_file_user ON favorites(file_id, user_id);
            CREATE INDEX IF NOT EXISTS idx_user_id ON favorites(user_id);
            CREATE INDEX IF NOT EXISTS idx_favorited_at ON favorites(favorited_at);
        ');
    }

    public function registerFile($absolutePath): ?int
    {
        return parent::registerFile($absolutePath);
    }

    public function toggleFavorite($absolutePath, int $userId) {
        $fileId = $this->registerFile($absolutePath);
        if (!$fileId) {
            return null;
        }
        
        $stmt = $this->db->prepare('
            SELECT id FROM favorites WHERE file_id = :file_id AND user_id = :user_id
        ');
        $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existing) {
            $stmt = $this->db->prepare('DELETE FROM favorites WHERE file_id = :file_id AND user_id = :user_id');
            $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            return false;
        } else {
            $stmt = $this->db->prepare('
                INSERT INTO favorites (file_id, user_id, favorited_at)
                VALUES (:file_id, :user_id, :favorited_at)
            ');
            $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
            $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
            $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        }
    }

    public function addFavorite($absolutePath, int $userId) {
        $fileId = $this->registerFile($absolutePath);
        if (!$fileId) {
            return false;
        }
        
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO favorites (file_id, user_id, favorited_at)
            VALUES (:file_id, :user_id, :favorited_at)
        ');
        $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
        
        return $stmt->execute() !== false;
    }

    public function removeFavorite($absolutePath) {
        $normalizedPath = $this->normalizePath($absolutePath);
        
        $stmt = $this->db->prepare('
            DELETE FROM favorites 
            WHERE file_id = (
                SELECT id FROM files WHERE file_path = :path
            )
        ');
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        
        return $stmt->execute() !== false;
    }

    public function isFavorited($absolutePath, int $userId) {
        $normalizedPath = $this->normalizePath($absolutePath);
        
        $stmt = $this->db->prepare('
            SELECT fav.favorited_at
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            WHERE f.file_path = :path AND fav.user_id = :user_id
        ');
        
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
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
        
        $results = [];
        
        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);
        
        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        
        $stmt = $this->db->prepare("
            SELECT f.file_path, fav.favorited_at
            FROM files f
            LEFT JOIN favorites fav ON f.id = fav.file_id AND fav.user_id = :user_id
            WHERE f.file_path IN ($placeholders)
        ");
        
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 2, $path, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $results[$originalPath] = [
                'favorited' => $row['favorited_at'] !== null,
                'favorited_at' => $row['favorited_at']
            ];
        }
        
        foreach ($absolutePaths as $path) {
            if (!isset($results[$path])) {
                $results[$path] = ['favorited' => false];
            }
        }

        $this->statusCache[$cacheKey] = $results;
        
        return $results;
    }

    public function getAllFavorites(int $userId) {
        $stmt = $this->db->prepare('
            SELECT f.file_path, fav.favorited_at
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            WHERE fav.user_id = :user_id
            ORDER BY fav.favorited_at DESC
        ');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        
        $favorites = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $favorites[] = [
                'file_path' => $row['file_path'],
                'favorited_at' => $row['favorited_at']
            ];
        }
        
        return $favorites;
    }

    public function getStats(int $userId) {
        $stmt = $this->db->query('SELECT COUNT(*) as total FROM files');
        $total = $stmt->fetchArray(SQLITE3_ASSOC)['total'];
        
        $stmt = $this->db->prepare('SELECT COUNT(*) as favorited FROM favorites WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $favorited = $result->fetchArray(SQLITE3_ASSOC)['favorited'];
        
        return [
            'total_files' => $total,
            'favorited_files' => $favorited,
            'unfavorited_files' => $total - $favorited
        ];
    }

    public function getFavoritesCountInFolder($folderPath, int $userId) {
        $normalizedPath = $this->normalizePath($folderPath);
        
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            WHERE f.file_path LIKE :path AND fav.user_id = :user_id
        ');
        
        $stmt->bindValue(':path', $normalizedPath . '%', SQLITE3_TEXT);
        $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row ? (int)$row['count'] : 0;
    }
}
