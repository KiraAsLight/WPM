<?php
declare(strict_types=1);

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'wiratama_db');
define('DB_USER', 'root'); // Ganti dengan username database Anda
define('DB_PASS', ''); // Ganti dengan password database Anda
define('DB_CHARSET', 'utf8mb4');

// PDO Options
define('PDO_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

/**
 * Get PDO database connection
 *
 * @return PDO
 * @throws PDOException
 */
function getDBConnection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, PDO_OPTIONS);
        } catch (PDOException $e) {
            // Log error (in production, don't display)
            error_log('Database connection failed: ' . $e->getMessage());
            throw new PDOException('Database connection failed. Please check your configuration.');
        }
    }

    return $pdo;
}

/**
 * Test database connection
 *
 * @return bool
 */
function testDBConnection(): bool {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->query('SELECT 1');
        return $stmt !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Execute a query with parameters
 *
 * @param string $sql
 * @param array $params
 * @return PDOStatement
 */
function executeQuery(string $sql, array $params = []): PDOStatement {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Get single row from query
 *
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function fetchOne(string $sql, array $params = []): ?array {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch() ?: null;
}

/**
 * Get all rows from query
 *
 * @param string $sql
 * @param array $params
 * @return array
 */
function fetchAll(string $sql, array $params = []): array {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Insert data and return last insert ID
 *
 * @param string $table
 * @param array $data
 * @return int
 */
function insert(string $table, array $data): int {
    $pdo = getDBConnection();
    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));
    $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    return (int) $pdo->lastInsertId();
}

/**
 * Update data
 *
 * @param string $table
 * @param array $data
 * @param string $where
 * @param array $whereParams
 * @return int Number of affected rows
 */
function update(string $table, array $data, string $where, array $whereParams = []): int {
    $pdo = getDBConnection();
    $set = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($data)));
    $sql = "UPDATE $table SET $set WHERE $where";
    $params = array_merge($data, $whereParams);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Delete data
 *
 * @param string $table
 * @param string $where
 * @param array $params
 * @return int Number of affected rows
 */
function delete(string $table, string $where, array $params = []): int {
    $pdo = getDBConnection();
    $sql = "DELETE FROM $table WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

// Timezone setting
date_default_timezone_set('Asia/Jakarta');

// Application constants
define('APP_NAME', 'PT. Wiratama Globalindo Jaya');
define('APP_VERSION', '1.0.0');

// Data directory for fallback (JSON files)
define('DATA_DIR', __DIR__ . '/assets/data');

// Helper function for htmlspecialchars
function h(mixed $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Kilogram formatter
function kg(float $n): string {
    return number_format($n, 0, ',', '.') . ' Kg';
}

// Percentage formatter
function pct(float $n): string {
    return number_format($n) . '%';
}

// Date formatter (YYYY-MM-DD to DD/MM/YYYY)
function dmy(?string $date): string {
    if (!$date) return '-';
    $t = strtotime($date);
    return $t ? date('d/m/Y', $t) : $date;
}

// Date formatter (DD/MM/YYYY to YYYY-MM-DD)
function ymd(?string $date): string {
    if (!$date) return '';
    $t = strtotime(str_replace('/', '-', $date));
    return $t ? date('Y-m-d', $t) : $date;
}
