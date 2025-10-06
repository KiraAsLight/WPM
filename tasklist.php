<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// ✅ FUNCTION: Calculate Integrated Progress untuk PON - FIXED LOGIC
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

    // ✅ LOGIC TASKS: Status "Done" = 100% progress
    foreach ($tasks as $task) {
        $totalItems++;
        if (strtolower($task['status'] ?? '') === 'done') {
            $completedItems++;
        }
    }

    // ✅ LOGIC FABRIKASI: Progress 100% = completed
    foreach ($fabrikasiItems as $item) {
        $totalItems++;
        if (($item['progress_calculated'] ?? 0) == 100) {
            $completedItems++;
        }
    }

    // ✅ LOGIC LOGISTIK WORKSHOP: Status "Terkirim" = completed
    foreach ($logistikWorkshopItems as $item) {
        $totalItems++;
        if ($item['status'] === 'Terkirim') {
            $completedItems++;
        }
    }

    // ✅ LOGIC LOGISTIK SITE: Status "Diterima" = completed
    foreach ($logistikSiteItems as $item) {
        $totalItems++;
        if ($item['status'] === 'Diterima') {
            $completedItems++;
        }
    }

    // Hitung persentase berdasarkan item yang completed
    $progress = $totalItems > 0 ? (int)round(($completedItems / $totalItems) * 100) : 0;

    $cache[$ponCode] = $progress;
    return $progress;
}

// ✅ FUNCTION: Get Last Activity Date dari semua tables
function getLastActivityDate($ponCode)
{
    $lastDates = [];

    // 1. Cek dari tasks
    $tasks = fetchAll('SELECT updated_at FROM tasks WHERE pon = ? ORDER BY updated_at DESC LIMIT 1', [$ponCode]);
    if (!empty($tasks)) {
        $lastDates[] = $tasks[0]['updated_at'];
    }

    // 2. Cek dari fabrikasi_items
    $fabrikasi = fetchAll('SELECT updated_at FROM fabrikasi_items WHERE pon = ? ORDER BY updated_at DESC LIMIT 1', [$ponCode]);
    if (!empty($fabrikasi)) {
        $lastDates[] = $fabrikasi[0]['updated_at'];
    }

    // 3. Cek dari logistik_workshop
    $logistikWorkshop = fetchAll('SELECT updated_at FROM logistik_workshop WHERE pon = ? ORDER BY updated_at DESC LIMIT 1', [$ponCode]);
    if (!empty($logistikWorkshop)) {
        $lastDates[] = $logistikWorkshop[0]['updated_at'];
    }

    // 4. Cek dari logistik_site
    $logistikSite = fetchAll('SELECT updated_at FROM logistik_site WHERE pon = ? ORDER BY updated_at DESC LIMIT 1', [$ponCode]);
    if (!empty($logistikSite)) {
        $lastDates[] = $logistikSite[0]['updated_at'];
    }

    // 5. Cek dari pon table sendiri
    $pon = fetchOne('SELECT updated_at FROM pon WHERE pon = ?', [$ponCode]);
    if ($pon && $pon['updated_at']) {
        $lastDates[] = $pon['updated_at'];
    }

    // Return tanggal terbaru
    if (!empty($lastDates)) {
        rsort($lastDates);
        return $lastDates[0];
    }

    return null;
}

// ✅ FUNCTION: Determine Status berdasarkan Progress & Tanggal
function calculatePonStatus($ponCode, $dateFinish, $currentStatus = 'Progres')
{
    $progress = getIntegratedProgress($ponCode);

    // 1. Jika progress 100% → Status SELESAI
    if ($progress == 100) {
        return 'Selesai';
    }

    $today = new DateTime();
    $finishDate = $dateFinish ? new DateTime($dateFinish) : null;

    // 2. Jika sudah lewat tanggal selesai → Status DELAYED
    if ($finishDate && $today > $finishDate) {
        return 'Delayed';
    }

    // 3. Cek apakah ada aktivitas dalam 30 hari terakhir
    $lastActivityDate = getLastActivityDate($ponCode);
    if ($lastActivityDate) {
        $lastActivity = new DateTime($lastActivityDate);
        $interval = $today->diff($lastActivity);
        $daysInactive = $interval->days;

        // Jika tidak ada aktivitas selama 30 hari → Status PENDING
        if ($daysInactive >= 30) {
            return 'Pending';
        }
    }

    // 4. Default → Status PROGRES
    return 'Progres';
}

// ✅ FUNCTION: Update Status untuk semua PON
function updateAllPonStatus()
{
    $allPon = fetchAll('SELECT pon, date_finish, status FROM pon');

    foreach ($allPon as $pon) {
        $newStatus = calculatePonStatus($pon['pon'], $pon['date_finish'], $pon['status']);

        // Update hanya jika status berubah
        if ($newStatus !== $pon['status']) {
            update(
                'pon',
                ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')],
                'pon = :pon',
                ['pon' => $pon['pon']]
            );
        }
    }
}

// ✅ AUTO-UPDATE STATUS SETIAP PAGE LOAD
updateAllPonStatus();

$appName = APP_NAME;
$activeMenu = 'Task List';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Muat PON dari database (status sudah terupdate)
$ponRecords = fetchAll('SELECT * FROM pon ORDER BY date_pon DESC');

// Handle search/filter
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';

$filteredPonRecords = $ponRecords;

if ($searchQuery) {
    $filteredPonRecords = array_filter($ponRecords, function ($pon) use ($searchQuery) {
        $searchLower = strtolower($searchQuery);
        return strpos(strtolower($pon['pon'] ?? ''), $searchLower) !== false ||
            strpos(strtolower($pon['client'] ?? ''), $searchLower) !== false ||
            strpos(strtolower($pon['type'] ?? ''), $searchLower) !== false ||
            strpos(strtolower($pon['status'] ?? ''), $searchLower) !== false;
    });
}

if ($statusFilter) {
    $filteredPonRecords = array_filter($filteredPonRecords, function ($pon) use ($statusFilter) {
        return strtolower($pon['status'] ?? '') === strtolower($statusFilter);
    });
}

if ($typeFilter) {
    $filteredPonRecords = array_filter($filteredPonRecords, function ($pon) use ($typeFilter) {
        return strpos(strtolower($pon['type'] ?? ''), strtolower($typeFilter)) !== false;
    });
}

// ✅ HITUNG INTEGRATED STATISTICS - FIXED LOGIC
$integratedTotal = 0;
$integratedDone = 0;

foreach ($ponRecords as $pon) {
    $ponCode = $pon['pon'];

    // === DATA TASKS ===
    $tasks = fetchAll('SELECT * FROM tasks WHERE pon = ?', [$ponCode]);
    $totalTasks = count($tasks);
    $doneTasks = count(array_filter($tasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

    // === DATA FABRIKASI ===
    $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);
    $totalFabrikasiItems = count($fabrikasiItems);
    $doneFabrikasiItems = count(array_filter($fabrikasiItems, fn($i) => ($i['progress_calculated'] ?? 0) == 100));

    // === DATA LOGISTIK ===
    $logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);
    $logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);
    $logistikTotalItems = count($logistikWorkshopItems) + count($logistikSiteItems);

    $doneLogistikWorkshop = count(array_filter($logistikWorkshopItems, fn($i) => $i['status'] === 'Terkirim'));
    $doneLogistikSite = count(array_filter($logistikSiteItems, fn($i) => $i['status'] === 'Diterima'));
    $doneLogistikItems = $doneLogistikWorkshop + $doneLogistikSite;

    // INTEGRATE: Total Items/Tasks = Tasks + Fabrikasi + Logistik
    $ponIntegratedTotal = $totalTasks + $totalFabrikasiItems + $logistikTotalItems;
    $ponIntegratedDone = $doneTasks + $doneFabrikasiItems + $doneLogistikItems;

    $integratedTotal += $ponIntegratedTotal;
    $integratedDone += $ponIntegratedDone;
}

// Hitung rata-rata progress integrated
$avgProgress = $integratedTotal > 0 ? (int)round(($integratedDone / $integratedTotal) * 100) : 0;

// Hitung distribusi berdasarkan jenis jembatan
$typeDistribution = [];
foreach ($ponRecords as $pon) {
    $type = strtolower(trim($pon['type'] ?? ''));
    if (strpos($type, 'rangka') !== false) {
        $typeDistribution['Rangka'] = ($typeDistribution['Rangka'] ?? 0) + 1;
    } elseif (strpos($type, 'gantung') !== false) {
        $typeDistribution['Gantung'] = ($typeDistribution['Gantung'] ?? 0) + 1;
    } elseif (strpos($type, 'bailey') !== false || strpos($type, 'balley') !== false) {
        $typeDistribution['Bailey'] = ($typeDistribution['Bailey'] ?? 0) + 1;
    } elseif (strpos($type, 'girder') !== false) {
        $typeDistribution['Girder'] = ($typeDistribution['Girder'] ?? 0) + 1;
    } else {
        $typeDistribution['Lainnya'] = ($typeDistribution['Lainnya'] ?? 0) + 1;
    }
}

// Redirect ke divisi jika ada PON yang dipilih
$selPon = isset($_GET['pon']) ? (string) $_GET['pon'] : '';
$selDiv = isset($_GET['div']) ? (string) $_GET['div'] : '';

if ($selPon) {
    if (!$selDiv) {
        header('Location: task_divisions.php?pon=' . urlencode($selPon));
        exit;
    }
    header('Location: task_detail.php?pon=' . urlencode($selPon) . '&div=' . urlencode($selDiv));
    exit;
}
?>

<!-- REST OF THE HTML REMAINS THE SAME -->
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Task List - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        /* CSS Styles remain exactly the same as previous version */
        .dashboard-overview {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
        }

        .overview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .overview-header h2 {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
            margin: 0;
        }

        .time-filter select {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 8px 12px;
            color: var(--text);
            font-size: 14px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        @media (max-width: 1024px) {
            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .quick-stats {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: #3b82f6;
            transform: translateY(-2px);
        }

        .stat-card.primary {
            border-left: 4px solid #3b82f6;
        }

        .stat-card.success {
            border-left: 4px solid #10b981;
        }

        .stat-card.warning {
            border-left: 4px solid #f59e0b;
        }

        .stat-card.info {
            border-left: 4px solid #06b6d4;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-card.primary .stat-icon {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }

        .stat-card.success .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .stat-card.warning .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .stat-card.info .stat-icon {
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 14px;
            color: var(--muted);
            font-weight: 500;
        }

        /* Projects Section */
        .projects-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }

        .section-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .view-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .results-count {
            font-size: 14px;
            color: var(--muted);
            font-weight: 500;
        }

        .view-options {
            display: flex;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 4px;
        }

        .view-option {
            background: transparent;
            border: none;
            padding: 8px 12px;
            border-radius: 4px;
            color: var(--muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .view-option.active {
            background: #3b82f6;
            color: white;
        }

        .filter-controls {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-box {
            position: relative;
            width: 250px;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
        }

        .search-box input {
            width: 100%;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px 10px 36px;
            color: var(--text);
            font-size: 14px;
        }

        .search-box input::placeholder {
            color: var(--muted);
        }

        .status-filter,
        .type-filter {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
            color: var(--text);
            font-size: 14px;
            min-width: 140px;
        }

        /* Projects Grid */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }

        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
            }
        }

        .project-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .project-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .project-id {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
        }

        .project-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-selesai {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-progres {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .status-delayed {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }

        .project-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            margin: 0 0 8px 0;
        }

        .project-type {
            font-size: 14px;
            color: var(--muted);
            margin: 0 0 12px 0;
        }

        .project-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        .meta-item i {
            font-size: 14px;
        }

        /* Progress Section */
        .progress-section {
            margin: 16px 0;
            padding: 16px 0;
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .progress-header span {
            font-size: 12px;
            font-weight: 600;
            color: var(--text);
        }

        .progress-value {
            color: #3b82f6 !important;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .division-progress {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .division-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .division-color {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .division-name {
            font-size: 10px;
            color: var(--muted);
            font-weight: 500;
        }

        /* Card Actions */
        .card-actions {
            display: flex;
            justify-content: flex-end;
        }

        .btn-view {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #3b82f6;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }

        .btn-view:hover {
            background: rgba(59, 130, 246, 0.2);
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
            font-size: 14px;
        }

        .clear-filters {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }

        .clear-filters:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Status Info Tooltip */
        .status-info {
            position: absolute;
            top: 10px;
            right: 10px;
            color: var(--muted);
            cursor: help;
        }

        /* Progress Info */
        .progress-info {
            font-size: 10px;
            color: var(--muted);
            text-align: center;
            margin-top: 8px;
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
            <div class="title">Task List</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Overview Section -->
            <div class="dashboard-overview">
                <div class="overview-header">
                    <h2>Project Overview</h2>
                    <div class="time-filter">
                        <select class="filter-select">
                            <option>All Time</option>
                            <option>This Month</option>
                            <option>This Week</option>
                        </select>
                    </div>
                </div>

                <div class="quick-stats">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="bi bi-folder"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $integratedTotal ?></div>
                            <div class="stat-label">Total Items</div>
                            <div class="progress-info">Tasks + Fabrikasi + Logistik</div>
                        </div>
                    </div>

                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="bi bi-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $integratedDone ?></div>
                            <div class="stat-label">Completed Items</div>
                            <div class="progress-info">Done + Progress 100%</div>
                        </div>
                    </div>

                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="bi bi-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $integratedTotal - $integratedDone ?></div>
                            <div class="stat-label">In Progress</div>
                            <div class="progress-info">Active items</div>
                        </div>
                    </div>

                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?= $avgProgress ?>%</div>
                            <div class="stat-label">Completion Rate</div>
                            <div class="progress-info">Based on completed items</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Projects Section -->
            <div class="projects-section">
                <div class="section-toolbar">
                    <div class="view-controls">
                        <span class="results-count">Showing <?= count($filteredPonRecords) ?> projects</span>
                        <div class="view-options">
                            <button class="view-option active" data-view="card">
                                <i class="bi bi-grid"></i>
                            </button>
                            <button class="view-option" data-view="table">
                                <i class="bi bi-list"></i>
                            </button>
                        </div>
                    </div>

                    <div class="filter-controls">
                        <form method="GET" action="" class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search" placeholder="Search projects..."
                                value="<?= h($searchQuery) ?>">
                        </form>

                        <select class="status-filter" onchange="this.form.submit()" name="status">
                            <option value="">All Status</option>
                            <option value="selesai" <?= $statusFilter === 'selesai' ? 'selected' : '' ?>>Selesai</option>
                            <option value="progres" <?= $statusFilter === 'progres' ? 'selected' : '' ?>>Progres</option>
                            <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="delayed" <?= $statusFilter === 'delayed' ? 'selected' : '' ?>>Delayed</option>
                        </select>

                        <select class="type-filter" onchange="this.form.submit()" name="type">
                            <option value="">All Types</option>
                            <option value="rangka" <?= $typeFilter === 'rangka' ? 'selected' : '' ?>>Rangka</option>
                            <option value="gantung" <?= $typeFilter === 'gantung' ? 'selected' : '' ?>>Gantung</option>
                            <option value="bailey" <?= $typeFilter === 'bailey' ? 'selected' : '' ?>>Bailey</option>
                        </select>
                    </div>
                </div>

                <div class="content-area">
                    <!-- Card View -->
                    <div class="projects-grid view-active" id="card-view">
                        <?php if (empty($filteredPonRecords)): ?>
                            <div class="empty-state" style="grid-column: 1 / -1;">
                                <i class="bi bi-inbox"></i>
                                <p>
                                    <?php if ($searchQuery || $statusFilter || $typeFilter): ?>
                                        No projects found matching your filters.
                                    <?php else: ?>
                                        No projects available.
                                    <?php endif; ?>
                                </p>
                                <?php if ($searchQuery || $statusFilter || $typeFilter): ?>
                                    <a href="tasklist.php" class="clear-filters">
                                        Clear Filters
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <?php foreach ($filteredPonRecords as $pon):
                                $progress = getIntegratedProgress($pon['pon']);
                                $status = $pon['status']; // Status sudah terupdate otomatis
                            ?>
                                <div class="project-card" onclick="window.location.href='task_divisions.php?pon=<?= urlencode($pon['pon']) ?>'">
                                    <!-- Status Info Tooltip -->
                                    <div class="status-info" title="Progress Logic:
                                        • Each completed item = +1 to progress
                                        • Tasks: Status 'Done'
                                        • Fabrikasi: Progress 100%  
                                        • Logistik: Status 'Terkirim/Diterima'">
                                        <i class="bi bi-info-circle"></i>
                                    </div>

                                    <!-- Card Header -->
                                    <div class="card-header">
                                        <div class="project-id"><?= h($pon['pon']) ?></div>
                                        <div class="project-status status-<?= strtolower($status) ?>">
                                            <?= h($status) ?>
                                        </div>
                                    </div>

                                    <!-- Project Info -->
                                    <div class="project-info">
                                        <h4 class="project-name"><?= h($pon['client'] ?? 'No Client') ?></h4>
                                        <p class="project-type"><?= h($pon['type'] ?? 'No Type') ?></p>
                                        <div class="project-meta">
                                            <div class="meta-item">
                                                <i class="bi bi-calendar"></i>
                                                Start: <?= h(dmy($pon['date_pon'] ?? 'N/A')) ?>
                                            </div>
                                            <div class="meta-item">
                                                <i class="bi bi-calendar-check"></i>
                                                Finish: <?= h(dmy($pon['date_finish'] ?? 'N/A')) ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Progress Section -->
                                    <div class="progress-section">
                                        <div class="progress-header">
                                            <span>Completion Progress</span>
                                            <span class="progress-value"><?= $progress ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                                        </div>
                                        <div class="progress-info">
                                            Based on completed items across all divisions
                                        </div>
                                    </div>

                                    <!-- Quick Actions -->
                                    <div class="card-actions">
                                        <button class="btn-view" onclick="event.stopPropagation(); window.location.href='task_divisions.php?pon=<?= urlencode($pon['pon']) ?>'">
                                            <i class="bi bi-eye"></i>
                                            View Details
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Smart Task List</footer>
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

        // View Toggle Functionality
        document.addEventListener('DOMContentLoaded', function() {
            const viewOptions = document.querySelectorAll('.view-option');

            viewOptions.forEach(option => {
                option.addEventListener('click', function() {
                    // Remove active class from all options
                    viewOptions.forEach(opt => opt.classList.remove('active'));
                    // Add active class to clicked option
                    this.classList.add('active');

                    const viewType = this.getAttribute('data-view');
                    // Here you can implement view switching logic
                    console.log('Switching to view:', viewType);
                });
            });

            // Auto-submit search on typing (with debounce)
            let searchTimeout;
            const searchInput = document.querySelector('.search-box input');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.closest('form').submit();
                    }, 500);
                });
            }
        });

        // Clear filters function
        function clearFilters() {
            window.location.href = 'tasklist.php';
        }
    </script>
</body>

</html>