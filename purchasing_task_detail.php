<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Purchasing';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Get all purchase orders with related data
$purchaseOrders = fetchAll("
    SELECT po.*, p.pon, p.nama_proyek, pi.name as item_name, pi.unit, v.name as vendor_name
    FROM purchase_orders po
    LEFT JOIN pon p ON po.pon_id = p.id
    LEFT JOIN purchase_items pi ON po.purchase_item_id = pi.id
    LEFT JOIN vendors v ON po.vendor_id = v.id
    ORDER BY po.created_at DESC
");

// Get success message from session
$successMsg = '';
if (isset($_SESSION['success_message'])) {
    $successMsg = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get error message from session
$errorMsg = '';
if (isset($_SESSION['error_message'])) {
    $errorMsg = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Orders - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        .page-header {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }

        .add-purchase-btn {
            background: #1d4ed8;
            border: 1px solid #3b82f6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .add-purchase-btn:hover {
            background: #1e40af;
        }

        /* Message Styles */
        .success-msg {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-msg {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Table Styles */
        .table-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .section-header-bar {
            background: #2d3748;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .purchase-table {
            width: 100%;
            border-collapse: collapse;
        }

        .purchase-table thead {
            background: #374151;
        }

        .purchase-table th {
            padding: 12px 16px;
            text-align: left;
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .purchase-table td {
            padding: 12px 16px;
            color: var(--text);
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .purchase-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Status Badge */
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-draft {
            background: rgba(156, 163, 175, 0.2);
            color: #d1d5db;
        }

        .status-sent {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .status-confirmed {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .status-received {
            background: rgba(168, 85, 247, 0.2);
            color: #c4b5fd;
        }

        .status-completed {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-cancelled {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-icon.edit:hover {
            border-color: #3b82f6;
            color: #93c5fd;
        }

        .btn-icon.delete:hover {
            border-color: #ef4444;
            color: #fca5a5;
        }

        .btn-icon.view:hover {
            border-color: #10b981;
            color: #86efac;
        }

        /* Currency Format */
        .currency {
            font-family: monospace;
            font-weight: 600;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state p {
            margin-bottom: 20px;
        }

        /* Custom Scrollbar */
        .table-container {
            overflow-x: auto;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: rgba(147, 197, 253, 0.3);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: rgba(147, 197, 253, 0.5);
        }
    </style>
</head>

<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <div class="logo" aria-hidden="true"></div>
            </div>
            <nav class="nav">
                <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php"><span class="icon bi-house"></span> Dashboard</a>
                <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php"><span class="icon bi-journal-text"></span> PON</a>
                <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php"><span class="icon bi-list-check"></span> Task List</a>
                <a class="<?= $activeMenu === 'Purchasing' ? 'active' : '' ?>" href="purchase_list.php"><span class="icon bi-cart"></span> Purchasing</a>
                <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php"><span class="icon bi-bar-chart"></span> Progres Divisi</a>
                <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
            </nav>
        </aside>

        <header class="header">
            <div class="title">Purchase Orders</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <a href="dashboard.php" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali
                    </a>
                    <div class="page-title">Purchase Orders</div>
                </div>
                <a href="purchasing_new.php" class="add-purchase-btn">
                    <i class="bi bi-plus-lg"></i>
                    Tambah Purchase Order
                </a>
            </div>

            <!-- Success Message -->
            <?php if ($successMsg): ?>
                <div class="success-msg">
                    <i class="bi bi-check-circle"></i>
                    <?= h($successMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if ($errorMsg): ?>
                <div class="error-msg">
                    <i class="bi bi-exclamation-circle"></i>
                    <?= h($errorMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Purchase Orders Table -->
            <div class="table-section">
                <div class="section-header-bar">
                    <div class="section-title">Daftar Purchase Orders</div>
                    <div style="color: var(--muted); font-size: 13px;">
                        Total: <?= count($purchaseOrders) ?> PO
                    </div>
                </div>

                <?php if (empty($purchaseOrders)): ?>
                    <div class="empty-state">
                        <i class="bi bi-cart"></i>
                        <p>Belum ada data Purchase Order</p>
                        <a href="purchasing_new.php" class="add-purchase-btn" style="display: inline-flex;">
                            <i class="bi bi-plus-lg"></i>
                            Tambah Purchase Order Pertama
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="purchase-table">
                            <thead>
                                <tr>
                                    <th>No. PO</th>
                                    <th>Project</th>
                                    <th>Item</th>
                                    <th>Quantity</th>
                                    <th>Harga Satuan</th>
                                    <th>Total</th>
                                    <th>Vendor</th>
                                    <th>Tanggal PO</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchaseOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($order['po_number']) ?></strong>
                                        </td>
                                        <td>
                                            <div><?= h($order['pon']) ?></div>
                                            <small style="color: var(--muted);"><?= h($order['nama_proyek']) ?></small>
                                        </td>
                                        <td><?= h($order['item_name']) ?></td>
                                        <td>
                                            <?= number_format((int)$order['quantity']) ?>
                                            <span style="color: var(--muted);"><?= h($order['unit']) ?></span>
                                        </td>
                                        <td class="currency">Rp <?= number_format((float)$order['unit_price'], 2) ?></td>
                                        <td class="currency">
                                            <strong>Rp <?= number_format((float)$order['grand_total'], 2) ?></strong>
                                        </td>
                                        <td><?= h($order['vendor_name']) ?></td>
                                        <td><?= h(dmy($order['po_date'])) ?></td>
                                        <td>
                                            <?php
                                            $statusClass = 'status-' . strtolower($order['status']);
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= h(ucfirst($order['status'])) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="purchase_view.php?id=<?= $order['id'] ?>" class="btn-icon view" title="View">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="purchase_edit.php?id=<?= $order['id'] ?>" class="btn-icon edit" title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="purchase_delete.php?id=<?= $order['id'] ?>"
                                                    class="btn-icon delete"
                                                    title="Delete"
                                                    onclick="return confirm('Yakin ingin menghapus PO ini?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Dibangun cepat dengan PHP</footer>
    </div>

    <script>
        (function() {
            const el = document.getElementById('clock');
            if (!el) return;
            const tz = 'Asia/Jakarta';
            let now = new Date(Number(el.dataset.epoch) * 1000);

            function tick() {
                now = new Date(now.getTime() + 1000);
                el.textContent = now.toLocaleString('id-ID', {
                    timeZone: tz,
                    hour12: false
                });
            }
            tick();
            setInterval(tick, 1000);
        })();
    </script>
</body>

</html>