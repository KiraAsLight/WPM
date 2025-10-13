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

// Get all fabrikasi items
$items = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ? ORDER BY no ASC', [$ponCode]);

// Success message
$successMsg = '';
if (isset($_GET['imported'])) {
    $count = (int)$_GET['imported'];
    $successMsg = "Berhasil import {$count} data fabrikasi!";
} elseif (isset($_GET['updated'])) {
    $successMsg = 'Data berhasil diupdate!';
} elseif (isset($_GET['deleted'])) {
    $successMsg = 'Data berhasil dihapus!';
} elseif (isset($_GET['added'])) {
    $successMsg = 'Data berhasil ditambahkan!';
}

// Calculate totals
$totalItems = count($items);
$totalQty = array_sum(array_column($items, 'qty'));
$totalBarangJadi = array_sum(array_column($items, 'barang_jadi'));
$totalProgress = $totalQty > 0 ? round(($totalBarangJadi / $totalQty) * 100) : 0;
$completionRate = $totalItems > 0 ? round((count(array_filter($items, fn($i) => ($i['progress_calculated'] ?? 0) == 100)) / $totalItems) * 100) : 0;
$totalWeight = array_sum(array_column($items, 'total_weight_kg'));

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Fabrikasi - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        /* Layout Grid - Full Viewport Height */
        .layout {
            display: grid;
            grid-template-areas:
                "sidebar header"
                "sidebar content"
                "sidebar footer";
            grid-template-columns: 260px 1fr;
            grid-template-rows: auto 1fr auto;
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            grid-area: sidebar;
            overflow-y: auto;
        }

        .header {
            grid-area: header;
        }

        .content {
            grid-area: content;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 24px;
        }

        .footer {
            grid-area: footer;
        }

        /* Page Header */
        .page-header {
            flex-shrink: 0;
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

        /* Stats Grid */
        .stats-grid {
            flex-shrink: 0;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 16px;
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
            font-size: 11px;
            color: var(--muted);
            font-weight: 500;
        }

        /* Success Message */
        .success-msg {
            flex-shrink: 0;
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

        /* Table Section - Flex Grow */
        .table-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            min-height: 0;
        }

        .section-header-bar {
            flex-shrink: 0;
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

        /* Table Container - Scrollable */
        .table-container {
            flex: 1;
            overflow: auto;
            position: relative;
            min-height: 0;
        }

        /* Data Table */
        .data-table {
            width: auto;
            min-width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .data-table thead {
            background: #374151;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }

        .data-table th {
            padding: 12px 16px;
            text-align: center;
            color: var(--muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            white-space: nowrap;
            vertical-align: middle;
            background: #374151;
        }

        .data-table th:last-child {
            border-right: none;
        }

        .data-table td {
            padding: 10px 16px;
            color: var(--text);
            font-size: 12px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid rgba(255, 255, 255, 0.05);
            vertical-align: middle;
            white-space: nowrap;
        }

        .data-table td:last-child {
            border-right: none;
        }

        .data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        /* Column Specific Widths */
        .data-table th:nth-child(1),
        .data-table td:nth-child(1) {
            width: 50px;
            min-width: 50px;
            text-align: center;
        }

        .data-table td:nth-child(2) {
            max-width: 150px;
            text-align: center;
        }

        .data-table td:nth-child(3) {
            max-width: 80px;
            text-align: center;
        }

        .data-table td:nth-child(4) {
            max-width: 250px;
            white-space: normal;
            word-wrap: break-word;
            text-align: left;
        }

        .data-table td:nth-child(5),
        .data-table td:nth-child(6),
        .data-table td:nth-child(7) {
            text-align: center;
            min-width: 80px;
        }

        .data-table td:nth-child(8) {
            min-width: 120px;
            text-align: center;
        }

        .data-table td:nth-child(9) {
            max-width: 120px;
            text-align: center;
        }

        .data-table td:nth-child(10),
        .data-table td:nth-child(11),
        .data-table td:nth-child(12) {
            text-align: right;
            min-width: 80px;
        }

        .data-table td:nth-child(13) {
            max-width: 200px;
            white-space: normal;
            text-align: left;
        }

        .data-table td:nth-child(14) {
            width: 90px;
            min-width: 90px;
            text-align: center;
        }

        /* Scrollbar Styling */
        .table-container::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        .table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 6px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: rgba(59, 130, 246, 0.6);
            border-radius: 6px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: rgba(59, 130, 246, 0.9);
        }

        .table-container::-webkit-scrollbar-corner {
            background: rgba(255, 255, 255, 0.08);
        }

        .table-container {
            scrollbar-width: auto;
            scrollbar-color: rgba(59, 130, 246, 0.6) rgba(255, 255, 255, 0.08);
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 6px;
            justify-content: center;
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

        /* Empty State */
        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
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

        /* Progress Bar */
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
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }

        .bg-success {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.3);
        }

        .bg-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        /* Text Alignment */
        .text-left {
            text-align: left !important;
        }

        .text-center {
            text-align: center !important;
        }

        .text-right {
            text-align: right !important;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .page-header {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }

            .header-left {
                flex-direction: column;
                align-items: stretch;
            }

            .header-actions {
                flex-direction: column;
            }

            .header-actions .btn {
                width: 100%;
                justify-content: center;
            }
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
            <div class="title">Data Fabrikasi - <?= strtoupper(h($ponCode)) ?></div>
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
                    <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali
                    </a>
                    <div class="page-title">Data Fabrikasi</div>
                </div>
                <div class="header-actions">
                    <a href="fabrikasi_new.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                        <i class="bi bi-plus-lg"></i>
                        Tambah Data
                    </a>
                    <a href="fabrikasi_import.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                        <i class="bi bi-upload"></i>
                        <?= empty($items) ? 'Import Data' : 'Import Ulang' ?>
                    </a>
                    <?php if (!empty($items)): ?>
                        <a href="fabrikasi_export.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-success">
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
                    <div class="stat-value"><?= number_format($totalBarangJadi) ?></div>
                    <div class="stat-label">Barang Jadi</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $totalProgress ?>%</div>
                    <div class="stat-label">Overall Progress</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $completionRate ?>%</div>
                    <div class="stat-label">Completion Rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($totalWeight, 2) ?></div>
                    <div class="stat-label">Total Weight (kg)</div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="table-section">
                <div class="section-header-bar">
                    <div class="section-title">Daftar Item Fabrikasi</div>
                </div>

                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>Belum ada data fabrikasi</p>
                        <a href="fabrikasi_import.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-primary">
                            <i class="bi bi-upload"></i>
                            Import Data
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>AssyMarking</th>
                                    <th>Rv</th>
                                    <th>Name</th>
                                    <th>Qty</th>
                                    <th>Barang<br>Jadi</th>
                                    <th>Belum<br>Jadi</th>
                                    <th>Progress</th>
                                    <th>Dimensions</th>
                                    <th>Length<br>(mm)</th>
                                    <th>Weight<br>(kg)</th>
                                    <th>Total Weight<br>(kg)</th>
                                    <th>Remarks</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item):
                                    $barangJadi = $item['barang_jadi'] ?? 0;
                                    $barangBelumJadi = $item['barang_belum_jadi'] ?? 0;
                                    $progress = $item['progress_calculated'] ?? 0;
                                ?>
                                    <tr>
                                        <td><?= h($item['no']) ?></td>
                                        <td><?= h($item['assy_marking']) ?></td>
                                        <td><?= h($item['rv']) ?></td>
                                        <td><?= h($item['name']) ?></td>
                                        <td><?= h($item['qty']) ?></td>
                                        <td>
                                            <span class="badge bg-success"><?= h($barangJadi) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?= h($barangBelumJadi) ?></span>
                                        </td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px; justify-content: center;">
                                                <div class="progress-bar-container">
                                                    <div class="progress-bar-fill" style="width: <?= h($progress) ?>%"></div>
                                                </div>
                                                <span style="font-size: 11px; color: var(--muted); min-width: 35px;">
                                                    <?= h($progress) ?>%
                                                </span>
                                            </div>
                                        </td>
                                        <td><?= h($item['dimensions']) ?></td>
                                        <td><?= number_format((float)$item['length_mm'], 2) ?></td>
                                        <td><?= number_format((float)$item['weight_kg'], 2) ?></td>
                                        <td><?= number_format((float)$item['total_weight_kg'], 2) ?></td>
                                        <td><?= h($item['remarks']) ?></td>
                                        <td>
                                            <div class="action-btns">
                                                <a href="fabrikasi_edit.php?id=<?= $item['id'] ?>&pon=<?= urlencode($ponCode) ?>"
                                                    class="btn-icon edit"
                                                    title="Edit">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="fabrikasi_delete.php?id=<?= $item['id'] ?>&pon=<?= urlencode($ponCode) ?>"
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

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Data Fabrikasi</footer>
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