<?php
// lib/FavoritesDatabase.php - Global favorites management with SQLite

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
                file_id INTEGER NOT NULL UNIQUE,
                favorited_at INTEGER NOT NULL,
                FOREIGN KEY (file_id) REFERENCES files(id) ON DELETE CASCADE
            );
            
            CREATE INDEX IF NOT EXISTS idx_file_id ON favorites(file_id);
            CREATE INDEX IF NOT EXISTS idx_favorited_at ON favorites(favorited_at);
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
     * Toggle favorite status for a file
     * Returns true if now favorited, false if unfavorited
     */
    public function toggleFavorite($absolutePath) {
        $fileId = $this->registerFile($absolutePath);
        if (!$fileId) {
            return null;
        }
        
        // Check if already favorited
        $stmt = $this->db->prepare('
            SELECT id FROM favorites WHERE file_id = :file_id
        ');
        $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $existing = $result->fetchArray(SQLITE3_ASSOC);
        
        if ($existing) {
            // Remove from favorites
            $stmt = $this->db->prepare('DELETE FROM favorites WHERE file_id = :file_id');
            $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
            $stmt->execute();
            return false;
        } else {
            // Add to favorites
            $stmt = $this->db->prepare('
                INSERT INTO favorites (file_id, favorited_at)
                VALUES (:file_id, :favorited_at)
            ');
            $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
            $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
            $stmt->execute();
            return true;
        }
    }
    
    /**
     * Add a file to favorites
     */
    public function addFavorite($absolutePath) {
        $fileId = $this->registerFile($absolutePath);
        if (!$fileId) {
            return false;
        }
        
        // Insert or ignore if already exists
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO favorites (file_id, favorited_at)
            VALUES (:file_id, :favorited_at)
        ');
        $stmt->bindValue(':file_id', $fileId, SQLITE3_INTEGER);
        $stmt->bindValue(':favorited_at', time(), SQLITE3_INTEGER);
        
        return $stmt->execute() !== false;
    }
    
    /**
     * Remove a file from favorites
     */
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
    
    /**
     * Check if a file is favorited
     */
    public function isFavorited($absolutePath) {
        $normalizedPath = $this->normalizePath($absolutePath);
        
        $stmt = $this->db->prepare('
            SELECT fav.favorited_at
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            WHERE f.file_path = :path
        ');
        
        $stmt->bindValue(':path', $normalizedPath, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row !== false;
    }
    
    /**
     * Get favorite status for multiple files (batch operation)
     * Returns array with absolute paths as keys and favorite status as values
     */
    public function getFavoriteStatusBatch($absolutePaths) {
        if (empty($absolutePaths)) {
            return [];
        }

        // Check cache first
        $cacheKey = md5(implode('|', $absolutePaths));
        if (isset($this->statusCache[$cacheKey])) {
            return $this->statusCache[$cacheKey];
        }
        
        $results = [];
        
        // Normalize all paths
        $normalizedPaths = array_map([$this, 'normalizePath'], $absolutePaths);
        $pathMap = array_combine($normalizedPaths, $absolutePaths);
        
        // Build IN clause
        $placeholders = implode(',', array_fill(0, count($normalizedPaths), '?'));
        
        $stmt = $this->db->prepare("
            SELECT f.file_path, fav.favorited_at
            FROM files f
            LEFT JOIN favorites fav ON f.id = fav.file_id
            WHERE f.file_path IN ($placeholders)
        ");
        
        foreach ($normalizedPaths as $i => $path) {
            $stmt->bindValue($i + 1, $path, SQLITE3_TEXT);
        }
        
        $result = $stmt->execute();
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $originalPath = $pathMap[$row['file_path']];
            $results[$originalPath] = [
                'favorited' => $row['favorited_at'] !== null,
                'favorited_at' => $row['favorited_at']
            ];
        }
        
        // Fill in missing files as not favorited
        foreach ($absolutePaths as $path) {
            if (!isset($results[$path])) {
                $results[$path] = ['favorited' => false];
            }
        }

        // Cache results
        $this->statusCache[$cacheKey] = $results;
        
        return $results;
    }
    
    /**
     * Get all favorited files
     */
    public function getAllFavorites() {
        $stmt = $this->db->query('
            SELECT f.file_path, fav.favorited_at
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            ORDER BY fav.favorited_at DESC
        ');
        
        $favorites = [];
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $favorites[] = [
                'file_path' => $row['file_path'],
                'favorited_at' => $row['favorited_at']
            ];
        }
        
        return $favorites;
    }
    
    /**
     * Get statistics
     */
    public function getStats() {
        $stmt = $this->db->query('SELECT COUNT(*) as total FROM files');
        $total = $stmt->fetchArray(SQLITE3_ASSOC)['total'];
        
        $stmt = $this->db->query('SELECT COUNT(*) as favorited FROM favorites');
        $favorited = $stmt->fetchArray(SQLITE3_ASSOC)['favorited'];
        
        return [
            'total_files' => $total,
            'favorited_files' => $favorited,
            'unfavorited_files' => $total - $favorited
        ];
    }
    
    /**
     * Get count of favorites in a specific folder (recursively)
     */
    public function getFavoritesCountInFolder($folderPath) {
        $normalizedPath = $this->normalizePath($folderPath);
        
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as count
            FROM favorites fav
            JOIN files f ON f.id = fav.file_id
            WHERE f.file_path LIKE :path
        ');
        
        $stmt->bindValue(':path', $normalizedPath . '%', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        
        return $row ? (int)$row['count'] : 0;
    }
}
