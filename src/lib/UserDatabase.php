<?php

require_once __DIR__ . '/Database.php';

class UserDatabase extends Database
{
    public function __construct($dbPath = null) {
        $dbPath = $dbPath ?? __DIR__ . '/../../db/users.db';
        parent::__construct($dbPath);
    }

    protected function createTables(): void
    {
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at INTEGER NOT NULL,
                last_login_at INTEGER
            );

            CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        ');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at INTEGER NOT NULL,
                success INTEGER NOT NULL DEFAULT 0
            );

            CREATE INDEX IF NOT EXISTS idx_attempts_ip ON login_attempts(ip_address);
            CREATE INDEX IF NOT EXISTS idx_attempts_time ON login_attempts(attempted_at);
        ');
    }

    public function createUser(string $username, string $password, string $role = 'user'): bool
    {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $now = time();

        $stmt = $this->db->prepare('
            INSERT INTO users (username, password_hash, role, created_at)
            VALUES (:username, :hash, :role, :created)
        ');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':role', $role, SQLITE3_TEXT);
        $stmt->bindValue(':created', $now, SQLITE3_INTEGER);

        return $stmt->execute() !== false;
    }

    public function verifyPassword(string $username, string $password): ?int
    {
        $stmt = $this->db->prepare('
            SELECT id, password_hash FROM users WHERE username = :username
        ');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if (!$row) {
            return null;
        }

        if (!password_verify($password, $row['password_hash'])) {
            return null;
        }

        return (int)$row['id'];
    }

    public function getUser(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, role, created_at, last_login_at
            FROM users WHERE id = :id
        ');
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return $row ?: null;
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->db->prepare('
            UPDATE users SET last_login_at = :now WHERE id = :id
        ');
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function isRateLimited(string $ipAddress): bool
    {
        $cutoff = time() - 900;
        $stmt = $this->db->prepare('
            SELECT COUNT(*) as attempts
            FROM login_attempts
            WHERE ip_address = :ip
            AND attempted_at > :cutoff
            AND success = 0
        ');
        $stmt->bindValue(':ip', $ipAddress, SQLITE3_TEXT);
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        return ($row['attempts'] ?? 0) >= 5;
    }

    public function logAttempt(string $ipAddress, bool $success): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO login_attempts (ip_address, attempted_at, success)
            VALUES (:ip, :now, :success)
        ');
        $stmt->bindValue(':ip', $ipAddress, SQLITE3_TEXT);
        $stmt->bindValue(':now', time(), SQLITE3_INTEGER);
        $stmt->bindValue(':success', $success ? 1 : 0, SQLITE3_INTEGER);
        $stmt->execute();
    }

    public function cleanupAttempts(int $olderThanDays = 7): int
    {
        $cutoff = time() - ($olderThanDays * 86400);
        $stmt = $this->db->prepare('
            DELETE FROM login_attempts WHERE attempted_at < :cutoff
        ');
        $stmt->bindValue(':cutoff', $cutoff, SQLITE3_INTEGER);
        $stmt->execute();
        return $this->db->changes();
    }
}
