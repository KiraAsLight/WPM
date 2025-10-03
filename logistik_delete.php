<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';

if (!$itemId || !$ponCode) {
    header('Location: tasklist.php');
    exit;
}

// Verify item exists
$item = fetchOne('SELECT * FROM logistik_items WHERE id = ? AND pon = ?', [$itemId, $ponCode]);
if (!$item) {
    header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&error=not_found');
    exit;
}

try {
    // Delete deliveries first (foreign key constraint)
    delete('logistik_deliveries', 'logistik_item_id = :id', ['id' => $itemId]);

    // Delete item
    delete('logistik_items', 'id = :id', ['id' => $itemId]);

    header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&deleted=1');
    exit;
} catch (Exception $e) {
    error_log('Logistik Delete Error: ' . $e->getMessage());
    header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&error=delete_failed');
    exit;
}
