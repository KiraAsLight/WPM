<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Task List';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Get PON parameter
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';

if (!$ponCode) {
    header('Location: tasklist.php');
    exit;
}

// Verify PON exists
$ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
if (!$ponRecord) {
    header('Location: tasklist.php?error=pon_not_found');
    exit;
}

// Get all workshop items
// $items = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ? ORDER BY no ASC', [$ponCode]);
$items = fetchAll('
    SELECT lw.*, v.name as vendor_name 
    FROM logistik_workshop lw 
    LEFT JOIN vendors v ON lw.vendor_id = v.id 
    WHERE lw.pon = ? 
    ORDER BY lw.no ASC
', [$ponCode]);

// Handle item deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $itemId = (int)$_GET['id'];
    delete('logistik_workshop', 'id = :id', ['id' => $itemId]);
    header('Location: logistik_workshop.php?pon=' . urlencode($ponCode) . '&deleted=1');
    exit;
}

// Success messages
$successMsg = '';
if (isset($_GET['added'])) {
    $successMsg = 'Item workshop berhasil ditambahkan!';
} elseif (isset($_GET['updated'])) {
    $successMsg = 'Item workshop berhasil diupdate!';
} elseif (isset($_GET['deleted'])) {
    $successMsg = 'Item workshop berhasil dihapus!';
} elseif (isset($_GET['imported'])) {
    $count = (int)$_GET['imported'];
    $successMsg = "Berhasil import {$count} data workshop!";
}

// Calculate statistics
$totalItems = count($items);
$totalWeight = array_sum(array_column($items, 'total_weight_kg'));
$totalQty = array_sum(array_column($items, 'qty'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Workshop Logistik - <?= h($appName) ?></title>
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

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            border: 1px solid;
        }

        .btn-primary {
            background: #1d4ed8;
            border-color: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn-success {
            background: #059669;
            border-color: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
        }

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

        /* PERBAIKAN: Container tabel dengan fixed height dan scrolling */
        .table-container {
            overflow: auto;
            max-height: 600px;
            /* Tinggi maksimal untuk scrolling vertikal */
            position: relative;
        }

        /* Header table tetap di atas saat scroll */
        .data-table thead {
            background: #374151;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table {            
            width: 100%;
            min-width: 1800px;
            border-collapse: collapse;
        }

        .data-table th {
            padding: 10px 12px;
            text-align: center;
            color: var(--muted);
            font-weight: 600;
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            vertical-align: middle;
            background: #374151;
            /* Pastikan background konsisten */
        }

        .data-table td {
            text-align: center;
            padding: 10px 12px;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            white-space: nowrap;
        }

        .data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Custom Scrollbar Styling */
        .table-container::-webkit-scrollbar {
            width: 8px;
            /* Scrollbar vertikal */
            height: 8px;
            /* Scrollbar horizontal */
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

        .table-container::-webkit-scrollbar-corner {
            background: rgba(255, 255, 255, 0.05);
        }

        /* Untuk browser Firefox */
        .table-container {
            scrollbar-width: thin;
            scrollbar-color: rgba(147, 197, 253, 0.3) rgba(255, 255, 255, 0.05);
        }

        .action-btns {
            justify-content: center;
            display: flex;
            gap: 6px;
        }

        .btn-icon {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            font-size: 12px;
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

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .progress-bar-container {
            width: 80px;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-todo {
            background: rgba(156, 163, 175, 0.2);
            color: #d1d5db;
        }

        .status-on-proses {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .status-hold {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .status-waiting-approve {
            background: rgba(168, 85, 247, 0.2);
            color: #c4b5fd;
        }

        .status-done {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .remarks-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .remarks-terkirim {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .remarks-belum-terkirim {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .status-terkirim {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-belum-terkirim {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
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
                <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php"><span class="icon bi-bar-chart"></span> Progres Divisi</a>
                <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
            </nav>
        </aside>

        <header class="header">
            <div class="title">Workshop Logistik - <?= strtoupper(h($ponCode)) ?></div>
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
                    <a href="logistik_menu.php?pon=<?= urlencode($ponCode) ?>" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali ke Menu
                    </a>
                    <div class="page-title">Management Workshop</div>
                </div>
                <div class="header-actions">
                    <a href="logistik_workshop_import.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                        <i class="bi bi-upload"></i>
                        <?= empty($items) ? 'Import Data' : 'Import Ulang' ?>
                    </a>
                    <a href="logistik_workshop_new.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-success">
                        <i class="bi bi-plus-lg"></i>
                        Tambah Item
                    </a>
                    <?php if (!empty($items)): ?>
                        <a href="logistik_workshop_export.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                            <i class="bi bi-download"></i>
                            Export Excel
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($successMsg): ?>
                <div class="success-msg">
                    <i class="bi bi-check-circle"></i>
                    <?= h($successMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalItems ?></div>
                    <div class="stat-label">Total Items</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($totalQty) ?></div>
                    <div class="stat-label">Total Quantity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($totalWeight, 2) ?></div>
                    <div class="stat-label">Total Weight (kg)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?= count(array_filter($items, fn($item) => $item['status'] === 'Terkirim')) ?>
                    </div>
                    <div class="stat-label">Items Terkirim</div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="table-section">
                <div class="section-header-bar">
                    <div class="section-title">Daftar Item Workshop</div>
                </div>

                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>Belum ada data workshop</p>
                        <a href="logistik_workshop_new.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i>
                            Tambah Item Pertama
                        </a>
                    </div>
                <?php else: ?>
                    <!-- PERBAIKAN: Table container dengan scrolling -->
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="text-center">No</th>
                                    <th>Nama Parts</th>
                                    <th>Marking</th>
                                    <th class="text-center">QTY<br>(Pcs)</th>
                                    <th>Dimensions<br>(mm)</th>
                                    <th class="text-right">Length<br>(mm)</th>
                                    <th class="text-right">Unit Weight<br>(Kg/Pc)</th>
                                    <th class="text-right">Total Weight<br>(Kg)</th>
                                    <th>Vendor</th>
                                    <th>Surat Jalan<br>Tanggal</th>
                                    <th>Surat Jalan<br>Nomor</th>
                                    <th class="text-center">Ready<br>CGI</th>
                                    <th class="text-center">O/S<br>DHJ</th>
                                    <th>Remarks</th>
                                    <th class="text-center">Progress</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td class="text-center"><?= h($item['no']) ?></td>
                                        <td style="text-align: left;"><?= h($item['nama_parts']) ?></td>
                                        <td><?= h($item['marking']) ?></td>
                                        <td class="text-center"><?= h($item['qty']) ?></td>
                                        <td><?= h($item['dimensions']) ?></td>
                                        <td class="text-right"><?= number_format((float)$item['length_mm'], 2) ?></td>
                                        <td class="text-right"><?= number_format((float)$item['unit_weight_kg'], 2) ?></td>
                                        <td class="text-right"><?= number_format((float)$item['total_weight_kg'], 2) ?></td>
                                        <td><?= h($item['vendor_name']) ?></td>
                                        <td><?= $item['surat_jalan_tanggal'] ? h(dmy($item['surat_jalan_tanggal'])) : '-' ?></td>
                                        <td><?= h($item['surat_jalan_nomor']) ?: '-' ?></td>
                                        <td class="text-center"><?= h($item['ready_cgi']) ?></td>
                                        <td class="text-center"><?= h($item['os_dhj']) ?></td>
                                        <td><?= h($item['remarks']) ?></td>                                       <td class="text-center">
                                            <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar-fill" style="width: <?= (int)$item['progress'] ?>%"></div>
                                                </div>
                                                <span style="font-size: 11px; color: var(--muted);"><?= (int)$item['progress'] ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $item['status'])) ?>">
                                                <?= h($item['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="action-btns">
                                                <a href="logistik_workshop_edit.php?id=<?= $item['id'] ?>&pon=<?= urlencode($ponCode) ?>"
                                                    class="btn-icon edit"
                                                    title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="logistik_workshop.php?pon=<?= urlencode($ponCode) ?>&delete=1&id=<?= $item['id'] ?>"
                                                    class="btn-icon delete"
                                                    title="Hapus"
                                                    onclick="return confirm('Yakin ingin menghapus item ini?')">
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

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Workshop Logistik</footer>
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