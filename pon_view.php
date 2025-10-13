<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'PON';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Get job_no parameter
$jobNo = isset($_GET['job_no']) ? trim($_GET['job_no']) : '';

if (!$jobNo) {
    header('Location: pon.php');
    exit;
}

// Get PON data
$ponRecord = fetchOne('SELECT * FROM pon WHERE job_no = ?', [$jobNo]);

if (!$ponRecord) {
    header('Location: pon.php?error=pon_not_found');
    exit;
}

$ponCode = $ponRecord['pon'];

// ✅ FUNGSI: Hitung Integrated Progress (sama seperti di tasklist.php)
function getIntegratedProgress($ponCode)
{
    static $cache = [];

    if (isset($cache[$ponCode])) {
        return $cache[$ponCode];
    }

    $tasks = fetchAll('SELECT * FROM tasks WHERE pon = ?', [$ponCode]);
    $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);
    $logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);
    $logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);

    $completedItems = 0;
    $totalItems = 0;

    // Tasks: Status "Done" = completed
    foreach ($tasks as $task) {
        $totalItems++;
        if (strtolower($task['status'] ?? '') === 'done') {
            $completedItems++;
        }
    }

    // Fabrikasi: Progress 100% = completed
    foreach ($fabrikasiItems as $item) {
        $totalItems++;
        if (($item['progress_calculated'] ?? 0) == 100) {
            $completedItems++;
        }
    }

    // Logistik Workshop: Status "Terkirim" = completed
    foreach ($logistikWorkshopItems as $item) {
        $totalItems++;
        if ($item['status'] === 'Terkirim') {
            $completedItems++;
        }
    }

    // Logistik Site: Status "Diterima" = completed
    foreach ($logistikSiteItems as $item) {
        $totalItems++;
        if ($item['status'] === 'Diterima') {
            $completedItems++;
        }
    }

    // Hitung persentase
    $progress = $totalItems > 0 ? (int)round(($completedItems / $totalItems) * 100) : 0;

    $cache[$ponCode] = $progress;
    return $progress;
}

// ✅ HITUNG STATISTIK TERINTEGRASI
// Total weight dari fabrikasi + logistik workshop
$weightData = fetchOne("
    SELECT 
        COALESCE(SUM(fi.total_weight_kg), 0) as fabrikasi_weight,
        COALESCE(SUM(lw.total_weight_kg), 0) as logistik_weight,
        COUNT(fi.id) as fabrikasi_items,
        COUNT(lw.id) as logistik_items
    FROM pon p
    LEFT JOIN fabrikasi_items fi ON p.pon = fi.pon
    LEFT JOIN logistik_workshop lw ON p.pon = lw.pon
    WHERE p.job_no = ?
    GROUP BY p.job_no
", [$jobNo]);

// Konversi ke float
$fabrikasiWeight = (float)($weightData['fabrikasi_weight'] ?? 0);
$logistikWeight = (float)($weightData['logistik_weight'] ?? 0);
$totalWeight = $fabrikasiWeight + $logistikWeight;

// ✅ HITUNG STATISTIK TASK & ITEM TERINTEGRASI
$integratedStats = [
    'total' => 0,
    'done' => 0,
    'progress' => 0,
    'todo' => 0
];

// Data dari tasks
$tasks = fetchAll('SELECT * FROM tasks WHERE pon = ?', [$ponCode]);
foreach ($tasks as $task) {
    $integratedStats['total']++;
    $status = strtolower($task['status'] ?? '');
    if ($status === 'done') {
        $integratedStats['done']++;
    } elseif ($status === 'on proses') {
        $integratedStats['progress']++;
    } else {
        $integratedStats['todo']++;
    }
}

// Data dari fabrikasi_items
$fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);
foreach ($fabrikasiItems as $item) {
    $integratedStats['total']++;
    $progress = $item['progress_calculated'] ?? 0;
    if ($progress == 100) {
        $integratedStats['done']++;
    } elseif ($progress > 0) {
        $integratedStats['progress']++;
    } else {
        $integratedStats['todo']++;
    }
}

// Data dari logistik_workshop
$logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);
foreach ($logistikWorkshopItems as $item) {
    $integratedStats['total']++;
    if ($item['status'] === 'Terkirim') {
        $integratedStats['done']++;
    } else {
        $integratedStats['todo']++;
    }
}

// Data dari logistik_site
$logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);
foreach ($logistikSiteItems as $item) {
    $integratedStats['total']++;
    if ($item['status'] === 'Diterima') {
        $integratedStats['done']++;
    } else {
        $integratedStats['todo']++;
    }
}

// ✅ HITUNG PROGRESS PER DIVISI TERINTEGRASI
$divisions = ['Engineering', 'Purchasing', 'Pabrikasi', 'Logistik'];
$divisionStats = [];

foreach ($divisions as $div) {
    if ($div === 'Pabrikasi') {
        // Untuk Pabrikasi, gunakan data dari fabrikasi_items
        $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);
        $totalItems = count($fabrikasiItems);

        $doneItems = 0;
        $progressItems = 0;
        $todoItems = 0;

        foreach ($fabrikasiItems as $item) {
            $progress = $item['progress_calculated'] ?? 0;
            if ($progress == 100) {
                $doneItems++;
            } elseif ($progress > 0) {
                $progressItems++;
            } else {
                $todoItems++;
            }
        }

        $divisionStats[$div] = [
            'name' => $div,
            'total' => $totalItems,
            'done' => $doneItems,
            'progress' => $progressItems,
            'todo' => $todoItems,
            'progress_percent' => $totalItems > 0 ? round(($doneItems / $totalItems) * 100) : 0
        ];
    } elseif ($div === 'Logistik') {
        // Untuk Logistik, gabungkan workshop + site
        $logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);
        $logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);

        $totalItems = count($logistikWorkshopItems) + count($logistikSiteItems);
        $doneItems = 0;

        foreach ($logistikWorkshopItems as $item) {
            if ($item['status'] === 'Terkirim') {
                $doneItems++;
            }
        }
        foreach ($logistikSiteItems as $item) {
            if ($item['status'] === 'Diterima') {
                $doneItems++;
            }
        }

        $divisionStats[$div] = [
            'name' => $div,
            'total' => $totalItems,
            'done' => $doneItems,
            'progress' => 0, // Logistik tidak ada status progress
            'todo' => $totalItems - $doneItems,
            'progress_percent' => $totalItems > 0 ? round(($doneItems / $totalItems) * 100) : 0
        ];
    } else {
        // Untuk divisi lain (Engineering, Purchasing), gunakan tasks
        $divTasks = fetchAll('SELECT * FROM tasks WHERE pon = ? AND division = ?', [$ponCode, $div]);
        $totalTasks = count($divTasks);

        $doneTasks = 0;
        $progressTasks = 0;
        $todoTasks = 0;

        foreach ($divTasks as $task) {
            $status = strtolower($task['status'] ?? '');
            if ($status === 'done') {
                $doneTasks++;
            } elseif ($status === 'on proses') {
                $progressTasks++;
            } else {
                $todoTasks++;
            }
        }

        $divisionStats[$div] = [
            'name' => $div,
            'total' => $totalTasks,
            'done' => $doneTasks,
            'progress' => $progressTasks,
            'todo' => $todoTasks,
            'progress_percent' => $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0
        ];
    }
}

// ✅ HITUNG PROGRESS KESELURUHAN TERINTEGRASI
$integratedProgress = getIntegratedProgress($ponCode);

// Helper functions
function formatDate($date)
{
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

function getStatusColor($status)
{
    $status = strtolower($status);
    switch ($status) {
        case 'selesai':
            return '#10b981';
        case 'progress':
            return '#3b82f6';
        case 'pending':
            return '#f59e0b';
        case 'delayed':
            return '#ef4444';
        default:
            return '#6b7280';
    }
}

function getProgressColor($progress)
{
    $progress = (int)$progress;
    if ($progress >= 90) return '#10b981';
    if ($progress >= 70) return '#3b82f6';
    if ($progress >= 50) return '#f59e0b';
    if ($progress >= 30) return '#f97316';
    return '#ef4444';
}

function formatWeight($weight)
{
    $weight = (float)$weight;
    return number_format($weight, 2);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>View PON - <?= h($ponRecord['job_no']) ?> - <?= h($appName) ?></title>
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

        .action-btns {
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

        .btn-warning {
            background: #f59e0b;
            border-color: #fbbf24;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-success {
            background: #059669;
            border-color: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #047857;
        }

        /* Overview Grid */
        .overview-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .overview-grid {
                grid-template-columns: 1fr;
            }
        }

        .info-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }

        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }

        /* Project Details */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }

        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .detail-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
        }

        .detail-value.large {
            font-size: 16px;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
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

        /* Progress Section */
        .progress-section {
            margin: 20px 0;
            padding: 20px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .progress-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .progress-percent {
            font-size: 18px;
            font-weight: 700;
            color: #3b82f6;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Division Progress */
        .division-progress {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .division-item {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .division-name {
            font-size: 12px;
            color: var(--text);
            font-weight: 600;
            min-width: 100px;
        }

        .division-bar {
            flex: 1;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .division-fill {
            height: 100%;
            border-radius: 3px;
        }

        .division-stats {
            font-size: 11px;
            color: var(--muted);
            min-width: 60px;
            text-align: right;
        }

        /* Weight Breakdown */
        .weight-breakdown {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .weight-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .weight-item:last-child {
            border-bottom: none;
        }

        .weight-label {
            font-size: 13px;
            color: var(--text);
        }

        .weight-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text);
        }

        .weight-total {
            font-size: 16px;
            font-weight: 700;
            color: #3b82f6;
        }

        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .text-area {
            white-space: pre-line;
            line-height: 1.5;
        }

        /* Summary Card */
        .summary-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-top: 16px;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 13px;
        }

        .summary-label {
            color: var(--muted);
        }

        .summary-value {
            color: var(--text);
            font-weight: 600;
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
                <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php">
                    <span class="icon bi-house"></span> Dashboard
                </a>
                <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php">
                    <span class="icon bi-journal-text"></span> PON
                </a>
                <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php">
                    <span class="icon bi-list-check"></span> Task List
                </a>
                <a href="logout.php">
                    <span class="icon bi-box-arrow-right"></span> Logout
                </a>
            </nav>
        </aside>

        <header class="header">
            <div class="title">View PON - <?= h($ponRecord['job_no']) ?></div>
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
                    <a href="pon.php" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali ke Daftar PON
                    </a>
                    <div class="page-title">Detail Project - <?= h($ponRecord['job_no']) ?></div>
                </div>
                <div class="action-btns">
                    <a href="pon_edit.php?job_no=<?= urlencode($jobNo) ?>" class="btn btn-warning">
                        <i class="bi bi-pencil"></i>
                        Edit Project
                    </a>
                    <a href="tasklist.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-success">
                        <i class="bi bi-list-check"></i>
                        Lihat Tasks
                    </a>
                    <a href="pon.php" class="btn btn-primary">
                        <i class="bi bi-journal-text"></i>
                        Semua Project
                    </a>
                </div>
            </div>

            <div class="overview-grid">
                <!-- Left Column - Project Details -->
                <div class="info-card">
                    <div class="card-title">Informasi Project</div>

                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="detail-label">Job Number</div>
                            <div class="detail-value large"><?= h($ponRecord['job_no']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">PON Code</div>
                            <div class="detail-value large"><?= h($ponRecord['pon']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Nama Project</div>
                            <div class="detail-value"><?= h($ponRecord['nama_proyek']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Client</div>
                            <div class="detail-value"><?= h($ponRecord['client']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Project Manager</div>
                            <div class="detail-value"><?= h($ponRecord['project_manager']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="status-badge" style="background: rgba(<?=
                                                                                    hexdec(substr(getStatusColor($ponRecord['status']), 1, 2)) ?>, <?=
                                                                                                    hexdec(substr(getStatusColor($ponRecord['status']), 3, 2)) ?>, <?=
                                                                                                    hexdec(substr(getStatusColor($ponRecord['status']), 5, 2)) ?>, 0.15); 
                                    color: <?= getStatusColor($ponRecord['status']) ?>;
                                    border: 1px solid rgba(<?=
                                                            hexdec(substr(getStatusColor($ponRecord['status']), 1, 2)) ?>, <?=
                                                                                                    hexdec(substr(getStatusColor($ponRecord['status']), 3, 2)) ?>, <?=
                                                                                                    hexdec(substr(getStatusColor($ponRecord['status']), 5, 2)) ?>, 0.3);">
                                    <?= h(ucfirst($ponRecord['status'])) ?>
                                </span>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Tanggal PON</div>
                            <div class="detail-value"><?= formatDate($ponRecord['date_pon']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Tanggal Selesai</div>
                            <div class="detail-value"><?= formatDate($ponRecord['date_finish']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Material Type</div>
                            <div class="detail-value"><?= h($ponRecord['material_type']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Quantity</div>
                            <div class="detail-value"><?= h($ponRecord['qty']) ?> units</div>
                        </div>
                    </div>

                    <div style="margin-top: 20px;">
                        <div class="detail-label">Subject</div>
                        <div class="detail-value text-area"><?= h($ponRecord['subject']) ?></div>
                    </div>

                    <div style="margin-top: 16px;">
                        <div class="detail-label">Alamat Kontrak</div>
                        <div class="detail-value text-area"><?= nl2br(h($ponRecord['alamat_kontrak'])) ?></div>
                    </div>

                    <div class="detail-grid" style="margin-top: 20px;">
                        <div class="detail-item">
                            <div class="detail-label">No. Contract</div>
                            <div class="detail-value"><?= h($ponRecord['no_contract']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Contract Date</div>
                            <div class="detail-value"><?= formatDate($ponRecord['contract_date']) ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Project Start</div>
                            <div class="detail-value"><?= formatDate($ponRecord['project_start']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Statistics & Weight -->
                <div style="display: flex; flex-direction: column; gap: 24px;">
                    <!-- Statistics Card -->
                    <div class="info-card">
                        <div class="card-title">Statistik Project Terintegrasi</div>

                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?= $integratedStats['total'] ?></div>
                                <div class="stat-label">Total Items</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $integratedStats['done'] ?></div>
                                <div class="stat-label">Selesai</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $integratedStats['progress'] ?></div>
                                <div class="stat-label">On Progress</div>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?= $integratedStats['todo'] ?></div>
                                <div class="stat-label">To Do</div>
                            </div>
                        </div>

                        <!-- Progress Section -->
                        <div class="progress-section">
                            <div class="progress-header">
                                <div class="progress-title">Progress Keseluruhan</div>
                                <div class="progress-percent"><?= $integratedProgress ?>%</div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $integratedProgress ?>%; 
                                    background: <?= getProgressColor($integratedProgress) ?>"></div>
                            </div>
                        </div>

                        <!-- Summary -->
                        <div class="summary-card">
                            <div class="summary-item">
                                <div class="summary-label">Items Fabrikasi</div>
                                <div class="summary-value"><?= $weightData['fabrikasi_items'] ?? 0 ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Items Logistik</div>
                                <div class="summary-value"><?= $weightData['logistik_items'] ?? 0 ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total Tasks</div>
                                <div class="summary-value"><?= count($tasks) ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Weight Breakdown -->
                    <div class="info-card">
                        <div class="card-title">Breakdown Berat</div>

                        <div class="weight-breakdown">
                            <div class="weight-item">
                                <div class="weight-label">Fabrikasi Weight</div>
                                <div class="weight-value"><?= formatWeight($fabrikasiWeight) ?> kg</div>
                            </div>
                            <div class="weight-item">
                                <div class="weight-label">Logistik Weight</div>
                                <div class="weight-value"><?= formatWeight($logistikWeight) ?> kg</div>
                            </div>
                            <div class="weight-item" style="border-top: 2px solid var(--border); padding-top: 12px;">
                                <div class="weight-label weight-total">Total Weight</div>
                                <div class="weight-value weight-total"><?= formatWeight($totalWeight) ?> kg</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Division Progress -->
            <div class="info-card">
                <div class="card-title">Progress Per Divisi Terintegrasi</div>

                <div class="division-progress">
                    <?php foreach ($divisionStats as $division): ?>
                        <div class="division-item">
                            <div class="division-name"><?= h($division['name']) ?></div>
                            <div class="division-bar">
                                <div class="division-fill" style="width: <?= $division['progress_percent'] ?>%; 
                                    background: <?= getProgressColor($division['progress_percent']) ?>"></div>
                            </div>
                            <div class="division-stats">
                                <?= $division['done'] ?> / <?= $division['total'] ?> (<?= $division['progress_percent'] ?>%)
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Division Summary -->
                <div class="summary-card" style="margin-top: 20px;">
                    <div class="summary-item">
                        <div class="summary-label">Engineering Tasks</div>
                        <div class="summary-value"><?= $divisionStats['Engineering']['total'] ?? 0 ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Purchasing Tasks</div>
                        <div class="summary-value"><?= $divisionStats['Purchasing']['total'] ?? 0 ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Fabrikasi Items</div>
                        <div class="summary-value"><?= $divisionStats['Pabrikasi']['total'] ?? 0 ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Logistik Items</div>
                        <div class="summary-value"><?= $divisionStats['Logistik']['total'] ?? 0 ?></div>
                    </div>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Project Management</footer>
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