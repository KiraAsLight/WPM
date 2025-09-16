<?php
declare(strict_types=1);

require_once 'config.php';

echo "<h1>Data Migration: JSON to MySQL Database</h1>";

// Function to migrate PON data
function migratePonData(): void {
    $ponPath = DATA_DIR . '/pon.json';

    if (!file_exists($ponPath)) {
        echo "<p style='color: red;'>PON JSON file not found: $ponPath</p>";
        return;
    }

    $json = file_get_contents($ponPath);
    $ponData = json_decode($json, true);

    if (!is_array($ponData)) {
        echo "<p style='color: red;'>Invalid PON JSON data</p>";
        return;
    }

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    try {
        $count = 0;
        foreach ($ponData as $pon) {
            // Check if PON already exists
            $existing = fetchOne('SELECT id FROM pon WHERE pon = ?', [$pon['pon'] ?? '']);
            if ($existing) {
                echo "<p>PON '{$pon['pon']}' already exists, skipping...</p>";
                continue;
            }

            // Prepare data for insertion
            $data = [
                'pon' => $pon['pon'] ?? '',
                'type' => $pon['type'] ?? '',
                'client' => $pon['client'] ?? '',
                'nama_proyek' => $pon['nama_proyek'] ?? '',
                'job_type' => $pon['job_type'] ?? '',
                'berat' => (float) ($pon['berat'] ?? 0),
                'qty' => (int) ($pon['qty'] ?? 0),
                'progress' => (int) ($pon['progress'] ?? 0),
                'date_pon' => $pon['date_pon'] ?? null,
                'date_finish' => $pon['date_finish'] ?? null,
                'status' => $pon['status'] ?? 'Progres',
                'alamat_kontrak' => $pon['alamat_kontrak'] ?? '',
                'no_contract' => $pon['no_contract'] ?? '',
                'pic' => $pon['pic'] ?? '',
                'owner' => $pon['owner'] ?? '',
            ];

            insert('pon', $data);
            $count++;
        }

        $pdo->commit();
        echo "<p style='color: green;'>✓ Migrated $count PON records successfully</p>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>✗ PON migration failed: " . h($e->getMessage()) . "</p>";
    }
}

// Function to migrate Tasks data
function migrateTasksData(): void {
    $tasksPath = DATA_DIR . '/tasks.json';

    if (!file_exists($tasksPath)) {
        echo "<p style='color: red;'>Tasks JSON file not found: $tasksPath</p>";
        return;
    }

    $json = file_get_contents($tasksPath);
    $tasksData = json_decode($json, true);

    if (!is_array($tasksData)) {
        echo "<p style='color: red;'>Invalid Tasks JSON data</p>";
        return;
    }

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    try {
        $count = 0;
        foreach ($tasksData as $task) {
            // Check if PON exists
            $ponExists = fetchOne('SELECT id FROM pon WHERE pon = ?', [$task['pon'] ?? '']);
            if (!$ponExists) {
                echo "<p style='color: orange;'>Warning: PON '{$task['pon']}' not found, skipping task...</p>";
                continue;
            }

            // Check if task already exists (by pon, division, title)
            $existing = fetchOne('SELECT id FROM tasks WHERE pon = ? AND division = ? AND title = ?',
                [$task['pon'] ?? '', $task['division'] ?? '', $task['title'] ?? '']);
            if ($existing) {
                echo "<p>Task '{$task['title']}' for PON '{$task['pon']}' already exists, skipping...</p>";
                continue;
            }

            // Prepare data for insertion
            $data = [
                'pon' => $task['pon'] ?? '',
                'division' => $task['division'] ?? '',
                'title' => $task['title'] ?? '',
                'progress' => (int) ($task['progress'] ?? 0),
                'status' => $task['status'] ?? 'To Do',
                'start_date' => $task['start_date'] ?? null,
                'due_date' => $task['due_date'] ?? null,
            ];

            insert('tasks', $data);
            $count++;
        }

        $pdo->commit();
        echo "<p style='color: green;'>✓ Migrated $count task records successfully</p>";

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<p style='color: red;'>✗ Tasks migration failed: " . h($e->getMessage()) . "</p>";
    }
}

// Run migrations
try {
    echo "<h2>Migrating PON Data...</h2>";
    migratePonData();

    echo "<h2>Migrating Tasks Data...</h2>";
    migrateTasksData();

    echo "<h2>Migration Complete!</h2>";
    echo "<p>You can now update your PHP files to use database queries instead of JSON files.</p>";

} catch (Exception $e) {
    echo "<p style='color: red;'>Migration error: " . h($e->getMessage()) . "</p>";
}

echo "<hr><p><a href='test_db.php'>Test Database Connection</a> | <a href='index.php'>Back to Home</a></p>";
