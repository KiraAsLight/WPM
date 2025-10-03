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
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
            font-size: 28px;
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

        .table-container {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            min-width: 1400px;
            border-collapse: collapse;
        }

        .data-table thead {
            background: #374151;
        }

        .data-table th {
            padding: 12px 16px;
            text-align: center;
            color: var(--muted);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .data-table td {
            text-align: center;
            padding: 12px 16px;
            color: var(--text);
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .data-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

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

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .progress-display {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            flex: 1;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
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
                    <div class="stat-value"><?= number_format($totalWeight, 2) ?> kg</div>
                    <div class="stat-label">Total Weight</div>
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
                                    <th class="text-center">No</th>
                                    <th>AssyMarking</th>
                                    <th>Rv</th>
                                    <th>Name</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-center">Barang Jadi</th>
                                    <th class="text-center">Belum Jadi</th>
                                    <th class="text-center">Progress</th>
                                    <th>Dimensions</th>
                                    <th class="text-center">Length (mm)</th>
                                    <th class="text-center">Weight (kg)</th>
                                    <th class="text-center">Total Weight (kg)</th>
                                    <th>Remarks</th>
                                    <th class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item):
                                    $barangJadi = $item['barang_jadi'] ?? 0;
                                    $barangBelumJadi = $item['barang_belum_jadi'] ?? 0;
                                    $progress = $item['progress_calculated'] ?? 0;
                                ?>
                                    <tr>
                                        <td class="text-center"><?= h($item['no']) ?></td>
                                        <td><?= h($item['assy_marking']) ?></td>
                                        <td><?= h($item['rv']) ?></td>
                                        <td><?= h($item['name']) ?></td>
                                        <td class="text-center"><?= h($item['qty']) ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-success"><?= h($barangJadi) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-warning"><?= h($barangBelumJadi) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div class="progress-bar-container" style="width: 80px;">
                                                    <div class="progress-bar-fill" style="width: <?= h($progress) ?>%"></div>
                                                </div>
                                                <span style="font-size: 12px; color: var(--muted); min-width: 30px;">
                                                    <?= h($progress) ?>%
                                                </span>
                                            </div>
                                        </td>
                                        <td><?= h($item['dimensions']) ?></td>
                                        <td class="text-center"><?= number_format((float)$item['length_mm'], 2) ?></td>
                                        <td class="text-center"><?= number_format((float)$item['weight_kg'], 2) ?></td>
                                        <td class="text-center"><?= number_format((float)$item['total_weight_kg'], 2) ?></td>
                                        <td><?= h($item['remarks']) ?></td>
                                        <td class="text-center">
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