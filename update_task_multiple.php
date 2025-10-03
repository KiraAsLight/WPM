<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$fieldsJson = isset($_POST['fields']) ? $_POST['fields'] : '';

if (!$taskId || !$fieldsJson) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$fields = json_decode($fieldsJson, true);

if (!is_array($fields) || empty($fields)) {
    echo json_encode(['success' => false, 'error' => 'Invalid fields data']);
    exit;
}

// Whitelist allowed fields untuk keamanan
$allowedFields = [
    'title',
    'satuan',
    'vendor',
    'status',
    'progress',
    'pic',
    'keterangan',
    'start_date',
    'due_date',
    'files',
    'no_po',
    'foto'
];

// Filter hanya field yang diizinkan
$updateFields = [];
$params = ['id' => $taskId];

foreach ($fields as $field => $value) {
    if (in_array($field, $allowedFields)) {
        $updateFields[] = "{$field} = :{$field}";
        $params[$field] = $value;
    }
}

if (empty($updateFields)) {
    echo json_encode(['success' => false, 'error' => 'No valid fields to update']);
    exit;
}

try {
    // Build and execute update query
    $sql = "UPDATE tasks SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = :id";
    $stmt = getDBConnection()->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'message' => 'Multiple fields updated successfully',
        'updated_count' => count($updateFields)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
