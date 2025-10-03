<?php

declare(strict_types=1);

session_start();
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once 'config.php';

// Get JSON data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$taskId = isset($data['task_id']) ? (int)$data['task_id'] : 0;
$field = isset($data['field']) ? trim($data['field']) : '';
$value = isset($data['value']) ? trim($data['value']) : '';

if (!$taskId || !$field) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Whitelist allowed fields
$allowedFields = [
    'title',
    'pic',
    'start_date',
    'due_date',
    'status',
    'progress',
    'keterangan',
    'satuan',
    'vendor',
    'no_po',
    'created_at'
];

if (!in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'message' => 'Invalid field']);
    exit;
}

try {
    // Get existing task
    $task = fetchOne('SELECT * FROM tasks WHERE id = ?', [$taskId]);

    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Task not found']);
        exit;
    }

    // Prepare update data
    $updateData = [$field => $value];

    // Auto-update progress based on status
    if ($field === 'status') {
        if ($value === 'Done') {
            $updateData['progress'] = 100;
        } elseif ($value === 'ToDo') {
            $updateData['progress'] = 0;
        }
    }

    // Add updated timestamp
    $updateData['updated_at'] = date('Y-m-d H:i:s');

    // Update task
    $rowsUpdated = update('tasks', $updateData, 'id = :id', ['id' => $taskId]);

    // Update PON progress if needed
    if (function_exists('updatePonProgress') && isset($task['pon'])) {
        updatePonProgress($task['pon']);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Task updated successfully',
        'updated_fields' => $updateData
    ]);
} catch (Exception $e) {
    error_log('Task Update Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
