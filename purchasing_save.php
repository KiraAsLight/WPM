<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $requiredFields = [
            'pon_id',
            'po_date',
            'purchase_item_id',
            'quantity',
            'unit_price',
            'vendor_id',
            'po_number'
        ];

        foreach ($requiredFields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field $field harus diisi");
            }
        }

        // Sanitize and validate data
        $pon_id = (int)$_POST['pon_id'];
        $po_date = $_POST['po_date'];
        $purchase_item_id = (int)$_POST['purchase_item_id'];
        $quantity = (int)$_POST['quantity'];
        $unit_price = (float)$_POST['unit_price'];
        $vendor_id = (int)$_POST['vendor_id'];
        $po_number = trim($_POST['po_number']);
        $notes = trim($_POST['notes'] ?? '');

        // Calculate values
        $total_amount = (float)$_POST['total_amount'];
        $ppn = (float)$_POST['ppn'];
        $grand_total = (float)$_POST['grand_total'];

        // Validate numeric values
        if ($quantity <= 0) {
            throw new Exception("Quantity harus lebih dari 0");
        }

        if ($unit_price <= 0) {
            throw new Exception("Harga satuan harus lebih dari 0");
        }

        // Check if PO number already exists
        $existingPO = fetchOne("SELECT id FROM purchase_orders WHERE po_number = ?", [$po_number]);
        if ($existingPO) {
            throw new Exception("Nomor PO '{$po_number}' sudah digunakan");
        }

        // Check if PON exists
        $ponExists = fetchOne("SELECT id FROM pon WHERE id = ?", [$pon_id]);
        if (!$ponExists) {
            throw new Exception("Project (PON) tidak valid");
        }

        // Check if purchase item exists
        $itemExists = fetchOne("SELECT id FROM purchase_items WHERE id = ? AND is_active = 1", [$purchase_item_id]);
        if (!$itemExists) {
            throw new Exception("Purchase item tidak valid");
        }

        // Check if vendor exists
        $vendorExists = fetchOne("SELECT id FROM vendors WHERE id = ? AND is_active = 1", [$vendor_id]);
        if (!$vendorExists) {
            throw new Exception("Vendor tidak valid");
        }

        // Insert data
        $data = [
            'pon_id' => $pon_id,
            'po_date' => $po_date,
            'purchase_item_id' => $purchase_item_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'total_amount' => $total_amount,
            'ppn' => $ppn,
            'grand_total' => $grand_total,
            'vendor_id' => $vendor_id,
            'po_number' => $po_number,
            'notes' => $notes,
            'status' => 'Draft'
        ];

        $result = insert('purchase_orders', $data);

        if ($result) {
            // Success - redirect to purchase list with success message
            $_SESSION['success_message'] = "Purchase Order berhasil dibuat!";
            header('Location: purchasing_task_detail.php?success=1');
            exit;
        } else {
            throw new Exception("Gagal menyimpan data purchase order");
        }
    } catch (Exception $e) {
        // Error - redirect back to form with error message
        $_SESSION['error_message'] = $e->getMessage();
        $_SESSION['form_data'] = $_POST;
        header('Location: purchase_new.php?error=1');
        exit;
    }
} else {
    // If not POST method, redirect to form
    header('Location: purchase_new.php');
    exit;
}
