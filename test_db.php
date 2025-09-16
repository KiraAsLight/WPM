<?php
declare(strict_types=1);

require_once 'config.php';

// Test database connection
echo "<h1>Database Connection Test</h1>";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✓ Database connection successful!</p>";

    // Test query
    $stmt = $pdo->query('SELECT VERSION() as version');
    $result = $stmt->fetch();
    echo "<p>Database Version: " . h($result['version'] ?? 'Unknown') . "</p>";

    // Check if tables exist
    $tables = ['pon', 'tasks'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        $exists = $stmt->rowCount() > 0;
        $status = $exists ? "<span style='color: green;'>✓ Exists</span>" : "<span style='color: red;'>✗ Not found</span>";
        echo "<p>Table '$table': $status</p>";
    }

    // Test data count
    if (tableExists('pon')) {
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM pon');
        $count = $stmt->fetch()['count'];
        echo "<p>PON records: $count</p>";
    }

    if (tableExists('tasks')) {
        $stmt = $pdo->query('SELECT COUNT(*) as count FROM tasks');
        $count = $stmt->fetch()['count'];
        echo "<p>Task records: $count</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . h($e->getMessage()) . "</p>";
    echo "<p>Please check your database configuration in config.php</p>";
}

/**
 * Check if table exists
 */
function tableExists(string $table): bool {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}
?>

<hr>
<h2>Configuration Info</h2>
<p><strong>Host:</strong> <?= h(DB_HOST) ?></p>
<p><strong>Database:</strong> <?= h(DB_NAME) ?></p>
<p><strong>User:</strong> <?= h(DB_USER) ?></p>
<p><strong>Charset:</strong> <?= h(DB_CHARSET) ?></p>

<hr>
<h2>Next Steps</h2>
<ol>
    <li>Ensure MySQL server is running</li>
    <li>Create database 'wiratama_db' if not exists</li>
    <li>Run the migration script from database_migration.sql</li>
    <li>Import data from JSON files if needed</li>
    <li>Update PHP files to use database instead of JSON</li>
</ol>

<p><a href="index.php">Back to Home</a></p>
