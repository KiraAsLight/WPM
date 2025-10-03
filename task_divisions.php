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

// Get all tasks for this PON
$allTasks = fetchAll('SELECT * FROM tasks WHERE pon = ?', [$ponCode]);

// === INTEGRASI DATA FABRIKASI ===
$fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);

// === INTEGRASI DATA LOGISTIK ===
// Get logistik workshop data
$logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);

// Get logistik site data  
$logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);

// Hitung statistik logistik workshop (dengan status baru)
$workshopTerkirim = 0;
$workshopBelumTerkirim = 0;

if (!empty($logistikWorkshopItems)) {
    foreach ($logistikWorkshopItems as $item) {
        if ($item['status'] === 'Terkirim') {
            $workshopTerkirim++;
        } else {
            $workshopBelumTerkirim++;
        }
    }
}

// Hitung statistik logistik site (dengan status baru)
$siteDiterima = 0;
$siteMenunggu = 0;

if (!empty($logistikSiteItems)) {
    foreach ($logistikSiteItems as $item) {
        if ($item['status'] === 'Diterima') {
            $siteDiterima++;
        } else {
            $siteMenunggu++;
        }
    }
}

// Gabungkan data logistik workshop + site
$logistikTotalItems = count($logistikWorkshopItems) + count($logistikSiteItems);
$logistikSelesai = $workshopTerkirim + $siteDiterima;
$logistikBelumSelesai = $workshopBelumTerkirim + $siteMenunggu;

// Hitung persentase untuk logistik (mapping ke ToDo/Done)
$logistikDonePercent = $logistikTotalItems > 0 ? round(($logistikSelesai / $logistikTotalItems) * 100) : 0;
$logistikTodoPercent = 100 - $logistikDonePercent;

// Calculate overall statistics - INTEGRATE FABRIKASI & LOGISTIK DATA
$totalTasks = count($allTasks);
$totalFabrikasiItems = count($fabrikasiItems);

// Hitung status tasks
$todoTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
$onProgressTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
$doneTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

// Hitung status fabrikasi items
$fabrikasiTodo = 0;
$fabrikasiProgress = 0;
$fabrikasiDone = 0;

if (!empty($fabrikasiItems)) {
    foreach ($fabrikasiItems as $item) {
        $progress = $item['progress_calculated'] ?? 0;
        if ($progress == 100) {
            $fabrikasiDone++;
        } elseif ($progress > 0) {
            $fabrikasiProgress++;
        } else {
            $fabrikasiTodo++;
        }
    }
}

// INTEGRATE: Total Items/Tasks = Tasks dari divisi lain + Items dari fabrikasi + Items dari logistik
$integratedTotal = $totalTasks + $totalFabrikasiItems + $logistikTotalItems;
$integratedTodo = $todoTasks + $fabrikasiTodo + $logistikBelumSelesai;
$integratedProgress = $onProgressTasks + $fabrikasiProgress;
$integratedDone = $doneTasks + $fabrikasiDone + $logistikSelesai;

// Calculate percentages for integrated data
$todoPercent = $integratedTotal > 0 ? round(($integratedTodo / $integratedTotal) * 100) : 0;
$progressPercent = $integratedTotal > 0 ? round(($integratedProgress / $integratedTotal) * 100) : 0;
$donePercent = $integratedTotal > 0 ? round(($integratedDone / $integratedTotal) * 100) : 0;

// Calculate overall project progress (include fabrikasi & logistik)
$totalProgressValue = 0;
$maxProgressValue = 0;

// Progress dari tasks
foreach ($allTasks as $task) {
    $maxProgressValue += 100;
    $totalProgressValue += (int)($task['progress'] ?? 0);
}

// Progress dari fabrikasi items
foreach ($fabrikasiItems as $item) {
    $maxProgressValue += 100;
    $totalProgressValue += ($item['progress_calculated'] ?? 0);
}

// Progress dari logistik items
foreach ($logistikWorkshopItems as $item) {
    $maxProgressValue += 100;
    $totalProgressValue += ($item['progress'] ?? 0);
}

foreach ($logistikSiteItems as $item) {
    $maxProgressValue += 100;
    $totalProgressValue += ($item['progress'] ?? 0);
}

$overallProgress = $maxProgressValue > 0 ? round(($totalProgressValue / $maxProgressValue) * 100) : 0;

// Calculate division statistics
$divisions = ['Engineering', 'Purchasing', 'Pabrikasi', 'Logistik'];
$divisionStats = [];

foreach ($divisions as $div) {
    if ($div === 'Pabrikasi') {
        // KONSISTEN DENGAN DIVISI LAIN: Task = Item, Status berdasarkan progress
        $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);

        if (!empty($fabrikasiItems)) {
            $totalItems = count($fabrikasiItems);

            // Hitung status berdasarkan progress_calculated
            $doneItems = count(array_filter($fabrikasiItems, fn($i) => ($i['progress_calculated'] ?? 0) == 100));
            $progressItems = count(array_filter($fabrikasiItems, fn($i) => ($i['progress_calculated'] ?? 0) > 0 && ($i['progress_calculated'] ?? 0) < 100));
            $todoItems = $totalItems - $doneItems - $progressItems;

            // Map ke status yang sama seperti divisi lain
            $divisionStats[$div] = [
                'name' => $div,
                'total' => $totalItems,
                'todo' => $todoItems,
                'progress' => $progressItems,
                'done' => $doneItems,
                'todo_percent' => $totalItems > 0 ? round(($todoItems / $totalItems) * 100) : 0,
                'progress_percent' => $totalItems > 0 ? round(($progressItems / $totalItems) * 100) : 0,
                'done_percent' => $totalItems > 0 ? round(($doneItems / $totalItems) * 100) : 0
            ];
        } else {
            // Fallback jika tidak ada data fabrikasi
            $divTasks = array_filter($allTasks, fn($t) => $t['division'] === $div);
            $divTotal = count($divTasks);
            $divTodo = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
            $divProgress = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
            $divDone = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

            $divisionStats[$div] = [
                'name' => $div,
                'total' => $divTotal,
                'todo' => $divTodo,
                'progress' => $divProgress,
                'done' => $divDone,
                'todo_percent' => $divTotal > 0 ? round(($divTodo / $divTotal) * 100) : 0,
                'progress_percent' => $divTotal > 0 ? round(($divProgress / $divTotal) * 100) : 0,
                'done_percent' => $divTotal > 0 ? round(($divDone / $divTotal) * 100) : 0
            ];
        }
    } elseif ($div === 'Logistik') {
        // Gunakan data dari logistik_workshop dan logistik_site dengan mapping status
        $divisionStats[$div] = [
            'name' => $div,
            'total' => $logistikTotalItems,
            'todo' => $logistikBelumSelesai,      // Belum Terkirim/Menunggu = To Do
            'progress' => 0,                      // Logistik tidak ada status progress
            'done' => $logistikSelesai,           // Terkirim/Diterima = Done
            'todo_percent' => $logistikTodoPercent,
            'progress_percent' => 0,              // Tidak ada progress
            'done_percent' => $logistikDonePercent
        ];
    } else {
        // Normal calculation for other divisions
        $divTasks = array_filter($allTasks, fn($t) => $t['division'] === $div);
        $divTotal = count($divTasks);
        $divTodo = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
        $divProgress = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
        $divDone = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

        $divisionStats[$div] = [
            'name' => $div,
            'total' => $divTotal,
            'todo' => $divTodo,
            'progress' => $divProgress,
            'done' => $divDone,
            'todo_percent' => $divTotal > 0 ? round(($divTodo / $divTotal) * 100) : 0,
            'progress_percent' => $divTotal > 0 ? round(($divProgress / $divTotal) * 100) : 0,
            'done_percent' => $divTotal > 0 ? round(($divDone / $divTotal) * 100) : 0
        ];
    }
}

// Calculate division statistics for bar chart - INTEGRATE FABRIKASI & LOGISTIK
$divisionStatusStats = [];
$statusList = ['To Do', 'On Proses', 'Hold', 'Waiting Approve', 'Done'];

// HITUNG INTEGRATED TOTALS TERPISAH
$integratedStatusCounts = [
    'To Do' => 0,
    'On Proses' => 0,
    'Hold' => 0,
    'Waiting Approve' => 0,
    'Done' => 0
];

foreach ($divisions as $div) {
    if ($div === 'Pabrikasi') {
        // Untuk Pabrikasi, gunakan data dari fabrikasi_items
        $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);

        $statusCounts = [
            'To Do' => 0,
            'On Proses' => 0,
            'Hold' => 0,
            'Waiting Approve' => 0,
            'Done' => 0
        ];

        if (!empty($fabrikasiItems)) {
            foreach ($fabrikasiItems as $item) {
                $progress = $item['progress_calculated'] ?? 0;
                if ($progress == 100) {
                    $statusCounts['Done']++;
                    $integratedStatusCounts['Done']++;
                } elseif ($progress > 0) {
                    $statusCounts['On Proses']++;
                    $integratedStatusCounts['On Proses']++;
                } else {
                    $statusCounts['To Do']++;
                    $integratedStatusCounts['To Do']++;
                }
            }
        }

        $divisionStatusStats[$div] = [
            'name' => $div,
            'total' => count($fabrikasiItems),
            'statuses' => $statusCounts
        ];
    } elseif ($div === 'Logistik') {
        // Untuk Logistik, gunakan data dari logistik_workshop dan logistik_site
        $statusCounts = [
            'To Do' => 0,
            'On Proses' => 0,
            'Hold' => 0,
            'Waiting Approve' => 0,
            'Done' => 0
        ];

        // Mapping status logistik ke status standar
        // Belum Terkirim/Menunggu = To Do, Terkirim/Diterima = Done

        // Process workshop items
        foreach ($logistikWorkshopItems as $item) {
            if ($item['status'] === 'Terkirim') {
                $statusCounts['Done']++;
                $integratedStatusCounts['Done']++;
            } else {
                $statusCounts['To Do']++;
                $integratedStatusCounts['To Do']++;
            }
        }

        // Process site items
        foreach ($logistikSiteItems as $item) {
            if ($item['status'] === 'Diterima') {
                $statusCounts['Done']++;
                $integratedStatusCounts['Done']++;
            } else {
                $statusCounts['To Do']++;
                $integratedStatusCounts['To Do']++;
            }
        }

        $divisionStatusStats[$div] = [
            'name' => $div,
            'total' => $logistikTotalItems,
            'statuses' => $statusCounts
        ];
    } else {
        // Normal calculation for other divisions
        $divTasks = array_filter($allTasks, fn($t) => $t['division'] === $div);

        $statusCounts = [
            'To Do' => 0,
            'On Proses' => 0,
            'Hold' => 0,
            'Waiting Approve' => 0,
            'Done' => 0
        ];

        foreach ($divTasks as $task) {
            $status = $task['status'] ?? 'To Do';
            if (isset($statusCounts[$status])) {
                $statusCounts[$status]++;
                $integratedStatusCounts[$status]++;
            }
        }

        $divisionStatusStats[$div] = [
            'name' => $div,
            'total' => count($divTasks),
            'statuses' => $statusCounts
        ];
    }
}

// TAMBAHKAN INTEGRATED KE ARRAY UTAMA
$divisionStatusStats['Integrated'] = [
    'name' => 'Total',
    'total' => $integratedTotal,
    'statuses' => $integratedStatusCounts
];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Task List > <?= strtoupper(h($ponCode)) ?> - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet"
        href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
    <style>
        /* Layout for Task Division Overview */
        .overview-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .overview-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .overview-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 20px;
            text-align: center;
        }

        /* Interactive Pie Chart */
        .pie-chart-container {
            position: relative;
            width: 200px;
            height: 200px;
            margin: 0 auto;
        }

        .pie-chart {
            width: 200px;
            height: 200px;
            cursor: pointer;
        }

        .pie-slice {
            transition: transform 0.2s ease;
            cursor: pointer;
        }

        .pie-slice:hover {
            transform: scale(1.05);
            filter: brightness(1.1);
        }

        /* Tooltip */
        .tooltip {
            position: absolute;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            pointer-events: none;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.2s ease;
            white-space: nowrap;
        }

        .tooltip.show {
            opacity: 1;
        }

        .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: rgba(0, 0, 0, 0.9) transparent transparent transparent;
        }

        /* Bar Chart for Task Count */
        .bar-chart {
            margin-top: 20px;
        }

        .bar-item {
            display: flex;
            align-items: center;
            margin-bottom: 12px;
        }

        .bar-label {
            width: 80px;
            font-size: 12px;
            color: var(--text);
            font-weight: 600;
        }

        .bar-container {
            flex: 1;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
            margin: 0 12px;
            position: relative;
        }

        .bar-fill {
            height: 100%;
            border-radius: 10px;
            position: relative;
            transition: width 0.3s ease;
        }

        .bar-fill.purchasing {
            background: linear-gradient(90deg, #3b82f6, #1e40af);
        }

        .bar-fill.pabrikasi {
            background: linear-gradient(90deg, #10b981, #047857);
        }

        .bar-fill.logistik {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .bar-fill.engineering {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        .bar-value {
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
            min-width: 30px;
            text-align: right;
        }

        .bar-progress-text {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: white;
            font-weight: 600;
        }

        /* Project Detail Card */
        .project-detail {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 30px;
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .detail-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .detail-item {
            text-align: center;
        }

        .detail-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 4px;
            font-weight: 500;
        }

        .detail-value {
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
        }

        /* Division Progress Cards */
        .divisions-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 24px;
        }

        .divisions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }

        @media (max-width: 1200px) {
            .divisions-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .divisions-grid {
                grid-template-columns: 1fr;
            }
        }

        .division-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .division-card:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: #3b82f6;
            transform: translateY(-2px);
        }

        .division-pie {
            width: 100px;
            height: 100px;
            margin: 0 auto 16px;
        }

        .division-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 12px;
        }

        .view-task-btn {
            background: #6b7280;
            border: 1px solid #6b7280;
            color: white;
            text-decoration: none;
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .view-task-btn:hover {
            background: #4b5563;
        }

        /* Warna bar berdasarkan status */
        .bar-fill.bg-gray {
            background: linear-gradient(90deg, #9ca3af, #6b7280);
        }

        .bar-fill.bg-blue {
            background: linear-gradient(90deg, #3b82f6, #2563eb);
        }

        .bar-fill.bg-yellow {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .bar-fill.bg-purple {
            background: linear-gradient(90deg, #a855f7, #9333ea);
        }

        .bar-fill.bg-green {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        /* Styling teks di dalam bar */
        .bar-progress-text {
            position: absolute;
            right: 4px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: white;
            font-weight: 600;
            text-shadow: 0 0 2px rgba(0, 0, 0, 0.5);
            white-space: nowrap;
            padding: 0 4px;
            border-radius: 4px;
        }

        /* Agar bar bisa overlap tanpa error */
        .bar-container {
            position: relative;
            height: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            overflow: hidden;
            margin: 0 12px;
        }

        .bar-fill {
            position: absolute;
            top: 0;
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 4px;
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
            <div class="title">Task List > <?= strtoupper(h($ponCode)) ?></div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Overview Statistics -->
            <div class="overview-grid">
                <!-- Total Task Keseluruhan (INTEGRATED) -->
                <div class="overview-card">
                    <div class="card-title">Total Task & Item Keseluruhan</div>
                    <div class="pie-chart-container">
                        <svg class="pie-chart" viewBox="0 0 42 42">
                            <!-- To Do -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#9ca3af" stroke-width="3"
                                stroke-dasharray="<?= $todoPercent ?> <?= 100 - $todoPercent ?>"
                                stroke-dashoffset="25"
                                data-tooltip="To Do (<?= $integratedTodo ?> items/tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- On Progress -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#06b6d4" stroke-width="3"
                                stroke-dasharray="<?= $progressPercent ?> <?= 100 - $progressPercent ?>"
                                stroke-dashoffset="<?= 25 - $todoPercent ?>"
                                data-tooltip="On Progress (<?= $integratedProgress ?> items/tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Done -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#10b981" stroke-width="3"
                                stroke-dasharray="<?= $donePercent ?> <?= 100 - $donePercent ?>"
                                stroke-dashoffset="<?= 25 - $todoPercent - $progressPercent ?>"
                                data-tooltip="Done (<?= $integratedDone ?> items/tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Center text -->
                            <text x="21" y="21" text-anchor="middle" fill="var(--text)" font-size="6" font-weight="600">
                                <?= $integratedTotal ?>
                            </text>
                            <text x="21" y="26" text-anchor="middle" fill="var(--muted)" font-size="4">
                                Total
                            </text>
                            <text x="21" y="31" text-anchor="middle" fill="#3b82f6" font-size="3" font-weight="600">
                                Progress: <?= $overallProgress ?>%
                            </text>
                        </svg>
                        <div class="tooltip" id="tooltip"></div>
                    </div>
                    <div style="text-align: center; margin-top: 12px; font-size: 12px; color: var(--muted);">
                        <div>Tasks/Items: <?= $integratedTotal ?></div>
                        <div>Progress Overall: <?= $overallProgress ?>%</div>
                    </div>
                </div>

                <!-- Jumlah Task & Item Per-Divisi (INTEGRATED) -->
                <div class="overview-card">
                    <div class="card-title">Jumlah Task & Item Per-Divisi</div>
                    <div class="bar-chart">
                        <?php
                        // Hapus Integrated dari array sebelum loop divisi biasa
                        $divisionsForChart = $divisionStatusStats;
                        if (isset($divisionsForChart['Integrated'])) {
                            unset($divisionsForChart['Integrated']);
                        }
                        ?>

                        <!-- Per Division -->
                        <?php foreach ($divisionsForChart as $div): ?>
                            <div class="bar-item">
                                <div class="bar-label"><?= h($div['name']) ?></div>
                                <div class="bar-container">
                                    <?php
                                    $total = $div['total'];
                                    $cumulativeWidth = 0;

                                    // Untuk Pabrikasi hanya show 3 status, untuk divisi lain show semua
                                    $statusesToShow = $div['name'] === 'Pabrikasi' ? ['To Do', 'On Proses', 'Done'] : ($div['name'] === 'Logistik' ? ['To Do', 'Done'] : $statusList);

                                    foreach ($statusesToShow as $status) {
                                        $count = $div['statuses'][$status] ?? 0;
                                        if ($count == 0) continue;

                                        $widthPercent = $total > 0 ? ($count / $total) * 100 : 0;
                                        $cumulativeWidth += $widthPercent;

                                        $colorClass = '';
                                        switch ($status) {
                                            case 'To Do':
                                                $colorClass = 'bg-gray';
                                                break;
                                            case 'On Proses':
                                                $colorClass = 'bg-blue';
                                                break;
                                            case 'Hold':
                                                $colorClass = 'bg-yellow';
                                                break;
                                            case 'Waiting Approve':
                                                $colorClass = 'bg-purple';
                                                break;
                                            case 'Done':
                                                $colorClass = 'bg-green';
                                                break;
                                            default:
                                                $colorClass = 'bg-gray';
                                        }
                                    ?>
                                        <div class="bar-fill <?= $colorClass ?>"
                                            style="width: <?= $widthPercent ?>%; left: <?= $cumulativeWidth - $widthPercent ?>%"
                                            title="<?= h($status) ?> (<?= $count ?>)">
                                            <?php if ($widthPercent >= 15): ?>
                                                <span class="bar-progress-text">
                                                    <?= h($status) ?> (<?= $count ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php } ?>
                                </div>
                                <div class="bar-value"><?= $div['total'] ?></div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($divisionsForChart)): ?>
                            <div style="text-align: center; color: var(--muted); font-size: 12px; padding: 20px;">
                                Tidak ada data task atau items
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Project Detail -->
            <div class="project-detail">
                <div class="detail-header">
                    <div class="detail-title">Detail Proyek</div>
                    <a href="tasklist.php" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali
                    </a>
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-label">Nama Proyek (PON)</div>
                        <div class="detail-value"><?= h($ponRecord['nama_proyek'] ?? $ponCode) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Nama Client</div>
                        <div class="detail-value"><?= h($ponRecord['client'] ?? '-') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Tipe Jembatan</div>
                        <div class="detail-value"><?= h($ponRecord['type'] ?? '-') ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Total Items/Tasks</div>
                        <div class="detail-value">
                            <?= $integratedTotal ?>
                            (<?= $totalTasks ?> tasks + <?= $totalFabrikasiItems ?> fabrikasi + <?= $logistikTotalItems ?> logistik)
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Progress Aktual</div>
                        <div class="detail-value" style="color: #3b82f6; font-weight: 700;"><?= $overallProgress ?>%</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <?php
                            $statusColor = 'var(--text)';
                            if ($overallProgress == 100) $statusColor = '#10b981';
                            elseif ($overallProgress >= 70) $statusColor = '#3b82f6';
                            elseif ($overallProgress >= 30) $statusColor = '#f59e0b';
                            ?>
                            <span style="color: <?= $statusColor ?>; font-weight: 600;">
                                <?= h(ucfirst($ponRecord['status'] ?? 'Progres')) ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Per-Divisi -->
            <div class="divisions-section">
                <div class="section-title">Progress Per-Divisi</div>
                <div class="divisions-grid">
                    <?php foreach ($divisionStats as $div): ?>
                        <div class="division-card">
                            <div class="pie-chart-container">
                                <svg class="division-pie" viewBox="0 0 42 42">
                                    <!-- To Do -->
                                    <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                        fill="transparent" stroke="#9ca3af" stroke-width="3"
                                        stroke-dasharray="<?= $div['todo_percent'] ?> <?= 100 - $div['todo_percent'] ?>"
                                        stroke-dashoffset="25"
                                        data-tooltip="To Do (<?= $div['todo'] ?> <?= $div['name'] === 'Pabrikasi' ? 'items' : ($div['name'] === 'Logistik' ? 'items' : 'tasks') ?>)"
                                        onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <!-- On Progress -->
                                    <?php if ($div['progress_percent'] > 0): ?>
                                        <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                            fill="transparent" stroke="#06b6d4" stroke-width="3"
                                            stroke-dasharray="<?= $div['progress_percent'] ?> <?= 100 - $div['progress_percent'] ?>"
                                            stroke-dashoffset="<?= 25 - $div['todo_percent'] ?>"
                                            data-tooltip="On Progress (<?= $div['progress'] ?> <?= $div['name'] === 'Pabrikasi' ? 'items' : ($div['name'] === 'Logistik' ? 'items' : 'tasks') ?>)"
                                            onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <?php endif; ?>
                                    <!-- Done -->
                                    <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                        fill="transparent" stroke="#10b981" stroke-width="3"
                                        stroke-dasharray="<?= $div['done_percent'] ?> <?= 100 - $div['done_percent'] ?>"
                                        stroke-dashoffset="<?= 25 - $div['todo_percent'] - $div['progress_percent'] ?>"
                                        data-tooltip="Done (<?= $div['done'] ?> <?= $div['name'] === 'Pabrikasi' ? 'items' : ($div['name'] === 'Logistik' ? 'items' : 'tasks') ?>)"
                                        onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <!-- Center text - SEDERHANA seperti divisi lain -->
                                    <text x="21" y="21" text-anchor="middle" fill="var(--text)" font-size="5" font-weight="600">
                                        <?= $div['total'] ?>
                                    </text>
                                    <text x="21" y="26" text-anchor="middle" fill="var(--muted)" font-size="3">
                                        <?= $div['name'] === 'Pabrikasi' || $div['name'] === 'Logistik' ? 'Items' : 'Tasks' ?>
                                    </text>
                                </svg>
                                <div class="tooltip" id="tooltip-<?= strtolower($div['name']) ?>"></div>
                            </div>
                            <div class="division-name">
                                <?= h($div['name']) ?>
                            </div>
                            <?php if ($div['name'] === 'Pabrikasi'): ?>
                                <a href="fabrikasi_list.php?pon=<?= urlencode($ponCode) ?>" class="view-task-btn">
                                    Lihat Task
                                </a>
                            <?php elseif ($div['name'] === 'Logistik'): ?>
                                <a href="logistik_menu.php?pon=<?= urlencode($ponCode) ?>" class="view-task-btn">
                                    Lihat Task
                                </a>
                            <?php else: ?>
                                <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($div['name']) ?>" class="view-task-btn">
                                    Lihat Task
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
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

        // Tooltip functionality - SEDERHANA
        function showTooltip(event, element) {
            const tooltip = document.getElementById('tooltip') ||
                document.getElementById('tooltip-' + element.closest('.division-card')?.querySelector('.division-name')?.textContent.toLowerCase()) ||
                document.getElementById('tooltip');
            if (!tooltip) return;

            const tooltipText = element.getAttribute('data-tooltip');
            tooltip.textContent = tooltipText;
            tooltip.classList.add('show');

            // Position tooltip
            const rect = element.getBoundingClientRect();
            const tooltipRect = tooltip.getBoundingClientRect();

            tooltip.style.left = (rect.left + rect.width / 2 - tooltipRect.width / 2) + 'px';
            tooltip.style.top = (rect.top - tooltipRect.height - 10) + 'px';
        }

        function hideTooltip() {
            const tooltips = document.querySelectorAll('.tooltip');
            tooltips.forEach(tooltip => {
                tooltip.classList.remove('show');
            });
        }

        // Add event listeners to all pie slices
        document.addEventListener('DOMContentLoaded', function() {
            const pieSlices = document.querySelectorAll('.pie-slice');
            pieSlices.forEach(slice => {
                slice.addEventListener('mousemove', function(e) {
                    const tooltip = this.closest('.pie-chart-container').querySelector('.tooltip') || document.getElementById('tooltip');
                    if (tooltip && tooltip.classList.contains('show')) {
                        tooltip.style.left = (e.pageX - tooltip.offsetWidth / 2) + 'px';
                        tooltip.style.top = (e.pageY - tooltip.offsetHeight - 10) + 'px';
                    }
                });
            });
        });
    </script>
</body>

</html>