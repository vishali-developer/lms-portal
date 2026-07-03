<?php
// ============================================================
// db.php — PDO Database Connection (Singleton)
// ============================================================

require_once __DIR__ . '/config.php';

class DB
{
    private static ?PDO $instance = null;

    // Get or create the PDO connection
    public static function connect(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                // Log and show a safe error — never expose credentials
                error_log('DB Connection failed: ' . $e->getMessage());
                http_response_code(500);
                die('<h2 style="font-family:sans-serif;color:#dc2626;padding:2rem;">
                     Database connection failed. Check config.php and ensure MySQL is running.</h2>');
            }
        }
        return self::$instance;
    }

    // Run a prepared query; returns PDOStatement
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch all rows as array of associative arrays
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    // Fetch a single row; returns null if not found
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    // Return the last inserted auto-increment ID
    public static function lastInsertId(): string
    {
        return self::connect()->lastInsertId();
    }

    // Return row count of last statement
    public static function rowCount(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    // Begin a transaction
    public static function beginTransaction(): void
    {
        self::connect()->beginTransaction();
    }

    // Commit transaction
    public static function commit(): void
    {
        self::connect()->commit();
    }

    // Rollback transaction
    public static function rollback(): void
    {
        self::connect()->rollBack();
    }
}
