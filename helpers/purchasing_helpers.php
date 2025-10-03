<?php

/**
 * Purchasing Helper Functions
 * Mendukung sistem purchasing dengan dropdown dinamis dan AJAX
 */

/**
 * Get purchase items untuk dropdown
 */
function getPurchaseItems()
{
    try {
        return fetchAll('SELECT id, name, description FROM purchase_items WHERE is_active = 1 ORDER BY name');
    } catch (Exception $e) {
        error_log('Error getting purchase items: ' . $e->getMessage());
        return [
            ['id' => 0, 'name' => 'Fabrikasi', 'description' => 'Item fabrikasi'],
            ['id' => 0, 'name' => 'Bondek', 'description' => 'Bondek material'],
            ['id' => 0, 'name' => 'Aksesoris', 'description' => 'Aksesoris konstruksi'],
            ['id' => 0, 'name' => 'Baut', 'description' => 'Baut dan fastener'],
            ['id' => 0, 'name' => 'Angkur', 'description' => 'Angkur bolt'],
            ['id' => 0, 'name' => 'Bearing', 'description' => 'Bearing komponen'],
            ['id' => 0, 'name' => 'Pipa', 'description' => 'Pipa dan fitting']
        ];
    }
}

/**
 * Get vendors untuk dropdown
 */
function getVendors()
{
    try {
        return fetchAll('SELECT id, name, contact_person, phone, email FROM vendors WHERE is_active = 1 ORDER BY name');
    } catch (Exception $e) {
        error_log('Error getting vendors: ' . $e->getMessage());
        return [
            ['id' => 0, 'name' => 'PT. Duta Hita Jaya', 'contact_person' => 'Budi Santoso'],
            ['id' => 0, 'name' => 'PT. Maja Makmur', 'contact_person' => 'Siti Rahayu'],
            ['id' => 0, 'name' => 'PT. Citra Baja', 'contact_person' => 'Ahmad Wijaya']
        ];
    }
}

/**
 * Get purchasing tasks dengan struktur lengkap
 */
function getPurchasingTasks($ponCode)
{
    try {
        $sql = "SELECT 
                    t.id,
                    t.pon,
                    t.title as purchase_item,
                    t.vendor,
                    t.no_po,
                    t.start_date,
                    t.due_date as finish_date,
                    t.date_po,
                    t.status,
                    t.progress,
                    t.keterangan,
                    t.files,
                    t.created_at,
                    t.updated_at
                FROM tasks t
                WHERE t.pon = ? AND t.division = 'Purchasing'
                ORDER BY t.date_po DESC, t.created_at DESC";

        return fetchAll($sql, [$ponCode]);
    } catch (Exception $e) {
        error_log('Error getting purchasing tasks: ' . $e->getMessage());
        return [];
    }
}

/**
 * Create purchasing task baru
 */
function createPurchasingTask($data)
{
    try {
        $taskData = [
            'pon' => $data['pon'],
            'division' => 'Purchasing',
            'title' => $data['purchase_item'],
            'vendor' => $data['vendor'],
            'no_po' => $data['no_po'] ?? '',
            'start_date' => $data['start_date'],
            'due_date' => $data['finish_date'] ?? $data['start_date'],
            'date_po' => $data['date_po'] ?? $data['start_date'],
            'status' => $data['status'] ?? 'ToDo',
            'progress' => 0,
            'keterangan' => $data['keterangan'] ?? '',
            'files' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $taskId = insert('tasks', $taskData);

        // Update PON progress
        if (function_exists('updatePonProgress')) {
            updatePonProgress($data['pon']);
        }

        return $taskId;
    } catch (Exception $e) {
        error_log('Error creating purchasing task: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Update purchasing task
 */
function updatePurchasingTask($taskId, $data)
{
    try {
        $updateData = [
            'title' => $data['purchase_item'],
            'vendor' => $data['vendor'],
            'no_po' => $data['no_po'] ?? '',
            'start_date' => $data['start_date'],
            'due_date' => $data['finish_date'] ?? $data['start_date'],
            'date_po' => $data['date_po'] ?? $data['start_date'],
            'status' => $data['status'] ?? 'ToDo',
            'progress' => (int)($data['progress'] ?? 0),
            'keterangan' => $data['keterangan'] ?? '',
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $rowsUpdated = update('tasks', $updateData, 'id = :id AND division = :division', [
            'id' => $taskId,
            'division' => 'Purchasing'
        ]);

        // Update PON progress
        if ($rowsUpdated > 0) {
            $task = fetchOne('SELECT pon FROM tasks WHERE id = ?', [$taskId]);
            if ($task && function_exists('updatePonProgress')) {
                updatePonProgress($task['pon']);
            }
        }

        return $rowsUpdated;
    } catch (Exception $e) {
        error_log('Error updating purchasing task: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Delete purchasing task
 */
function deletePurchasingTask($taskId)
{
    try {
        // Get PON before delete untuk update progress
        $task = fetchOne('SELECT pon FROM tasks WHERE id = ? AND division = ?', [$taskId, 'Purchasing']);

        $rowsDeleted = delete('tasks', 'id = :id AND division = :division', [
            'id' => $taskId,
            'division' => 'Purchasing'
        ]);

        // Update PON progress
        if ($rowsDeleted > 0 && $task && function_exists('updatePonProgress')) {
            updatePonProgress($task['pon']);
        }

        return $rowsDeleted;
    } catch (Exception $e) {
        error_log('Error deleting purchasing task: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Get task details by purchase item and vendor
 */
function getTaskDetailsBySelection($ponCode, $purchaseItem, $vendor)
{
    try {
        $sql = "SELECT * FROM tasks 
                WHERE pon = ? AND division = 'Purchasing' 
                AND title = ? AND vendor = ?
                ORDER BY created_at DESC
                LIMIT 1";

        return fetchOne($sql, [$ponCode, $purchaseItem, $vendor]);
    } catch (Exception $e) {
        error_log('Error getting task details: ' . $e->getMessage());
        return null;
    }
}

/**
 * Add new purchase item
 */
function addPurchaseItem($name, $description = '')
{
    try {
        $data = [
            'name' => $name,
            'description' => $description,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return insert('purchase_items', $data);
    } catch (Exception $e) {
        error_log('Error adding purchase item: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Add new vendor
 */
function addVendor($name, $contactPerson = '', $phone = '', $email = '', $address = '')
{
    try {
        $data = [
            'name' => $name,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return insert('vendors', $data);
    } catch (Exception $e) {
        error_log('Error adding vendor: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Handle file upload untuk purchasing
 */
function handlePurchasingFileUpload($file, $taskId)
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $uploadDir = __DIR__ . '/../uploads/purchasing/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = time() . '_' . basename($file['name']);
    $uploadPath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        $relativePath = 'uploads/purchasing/' . $fileName;

        // Save to database
        try {
            $fileData = [
                'task_id' => $taskId,
                'file_name' => $file['name'],
                'file_path' => $relativePath,
                'file_type' => $file['type'],
                'file_size' => $file['size'],
                'upload_date' => date('Y-m-d H:i:s')
            ];

            insert('purchasing_files', $fileData);

            // Update task files field
            update('tasks', ['files' => $relativePath], 'id = :id', ['id' => $taskId]);

            return $relativePath;
        } catch (Exception $e) {
            error_log('Error saving file info: ' . $e->getMessage());
            return $relativePath; // Return path even if DB save fails
        }
    }

    return false;
}

/**
 * Format date untuk display
 */
function formatDateForDisplay($date)
{
    if (!$date) return '';

    $timestamp = strtotime($date);
    if (!$timestamp) return $date;

    return date('d/m/Y', $timestamp);
}

/**
 * Format date untuk form input
 */
function formatDateForInput($date)
{
    if (!$date) return '';

    $timestamp = strtotime($date);
    if (!$timestamp) return $date;

    return date('Y-m-d', $timestamp);
}

/**
 * Get purchasing statistics
 */
function getPurchasingStatistics($ponCode)
{
    try {
        $sql = "SELECT 
                    COUNT(*) as total_tasks,
                    SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_tasks,
                    SUM(CASE WHEN status = 'On Proses' THEN 1 ELSE 0 END) as in_progress_tasks,
                    SUM(CASE WHEN status = 'ToDo' THEN 1 ELSE 0 END) as pending_tasks,
                    AVG(progress) as average_progress,
                    COUNT(DISTINCT vendor) as unique_vendors,
                    COUNT(DISTINCT title) as unique_purchases
                FROM tasks 
                WHERE pon = ? AND division = 'Purchasing'";

        return fetchOne($sql, [$ponCode]) ?: [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'in_progress_tasks' => 0,
            'pending_tasks' => 0,
            'average_progress' => 0,
            'unique_vendors' => 0,
            'unique_purchases' => 0
        ];
    } catch (Exception $e) {
        error_log('Error getting purchasing statistics: ' . $e->getMessage());
        return [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'in_progress_tasks' => 0,
            'pending_tasks' => 0,
            'average_progress' => 0,
            'unique_vendors' => 0,
            'unique_purchases' => 0
        ];
    }
}

/**
 * Validate purchasing task data
 */
function validatePurchasingTaskData($data)
{
    $errors = [];

    if (empty($data['pon'])) {
        $errors['pon'] = 'PON is required';
    }

    if (empty($data['purchase_item'])) {
        $errors['purchase_item'] = 'Purchase item is required';
    }

    if (empty($data['vendor'])) {
        $errors['vendor'] = 'Vendor is required';
    }

    if (empty($data['start_date'])) {
        $errors['start_date'] = 'Start date is required';
    }

    if (!empty($data['start_date']) && !empty($data['finish_date'])) {
        if (strtotime($data['finish_date']) < strtotime($data['start_date'])) {
            $errors['finish_date'] = 'Finish date must be after start date';
        }
    }

    $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'];
    if (!empty($data['status']) && !in_array($data['status'], $validStatuses)) {
        $errors['status'] = 'Invalid status';
    }

    if (isset($data['progress'])) {
        $progress = (int)$data['progress'];
        if ($progress < 0 || $progress > 100) {
            $errors['progress'] = 'Progress must be between 0 and 100';
        }
    }

    return $errors;
}
