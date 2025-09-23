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

// Calculate overall statistics
$totalTasks = count($allTasks);
$todoTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
$onProgressTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
$doneTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

// Calculate percentages for overall pie chart
$todoPercent = $totalTasks > 0 ? round(($todoTasks / $totalTasks) * 100) : 0;
$progressPercent = $totalTasks > 0 ? round(($onProgressTasks / $totalTasks) * 100) : 0;
$donePercent = $totalTasks > 0 ? round(($doneTasks / $totalTasks) * 100) : 0;

// Calculate division statistics
$divisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
$divisionStats = [];

foreach ($divisions as $div) {
    $divTasks = array_filter($allTasks, fn($t) => $t['division'] === $div);
    $divTotal = count($divTasks);
    $divTodo = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
    $divProgress = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
    $divDone = count(array_filter($divTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));

    // Calculate percentages
    $divTodoPercent = $divTotal > 0 ? round(($divTodo / $divTotal) * 100) : 0;
    $divProgressPercent = $divTotal > 0 ? round(($divProgress / $divTotal) * 100) : 0;
    $divDonePercent = $divTotal > 0 ? round(($divDone / $divTotal) * 100) : 0;

    $divisionStats[$div] = [
        'name' => $div,
        'total' => $divTotal,
        'todo' => $divTodo,
        'progress' => $divProgress,
        'done' => $divDone,
        'todo_percent' => $divTodoPercent,
        'progress_percent' => $divProgressPercent,
        'done_percent' => $divDonePercent
    ];
}
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
                <!-- Total Task Keseluruhan -->
                <div class="overview-card">
                    <div class="card-title">Total Task Keseluruhan</div>
                    <div class="pie-chart-container">
                        <svg class="pie-chart" viewBox="0 0 42 42">
                            <!-- To Do -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#9ca3af" stroke-width="3"
                                stroke-dasharray="<?= $todoPercent ?> <?= 100 - $todoPercent ?>"
                                stroke-dashoffset="25"
                                data-tooltip="To Do (<?= $todoTasks ?> tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- On Progress -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#06b6d4" stroke-width="3"
                                stroke-dasharray="<?= $progressPercent ?> <?= 100 - $progressPercent ?>"
                                stroke-dashoffset="<?= 25 - $todoPercent ?>"
                                data-tooltip="On Progress (<?= $onProgressTasks ?> tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Done -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#10b981" stroke-width="3"
                                stroke-dasharray="<?= $donePercent ?> <?= 100 - $donePercent ?>"
                                stroke-dashoffset="<?= 25 - $todoPercent - $progressPercent ?>"
                                data-tooltip="Done (<?= $doneTasks ?> tasks)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Center text -->
                            <text x="21" y="21" text-anchor="middle" fill="var(--text)" font-size="6" font-weight="600">
                                <?= $totalTasks ?>
                            </text>
                            <text x="21" y="26" text-anchor="middle" fill="var(--muted)" font-size="4">
                                Total Tasks
                            </text>
                        </svg>
                        <div class="tooltip" id="tooltip"></div>
                    </div>
                </div>

                <!-- Jumlah Task Per-Divisi -->
                <div class="overview-card">
                    <div class="card-title">Jumlah Task Per-Divisi</div>
                    <div class="bar-chart">
                        <?php
                        $maxTasks = max(array_map(fn($d) => $d['total'], $divisionStats));
                        foreach ($divisionStats as $div):
                            $width = $maxTasks > 0 ? ($div['total'] / $maxTasks) * 100 : 0;
                        ?>
                            <div class="bar-item">
                                <div class="bar-label"><?= h($div['name']) ?></div>
                                <div class="bar-container">
                                    <div class="bar-fill <?= strtolower($div['name']) ?>" style="width: <?= $width ?>%">
                                        <div class="bar-progress-text">On Progress (<?= $div['progress'] ?>)</div>
                                    </div>
                                </div>
                                <div class="bar-value"><?= $div['total'] ?></div>
                            </div>
                        <?php endforeach; ?>
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
                        <div class="detail-label">Berat</div>
                        <div class="detail-value"><?= h(kg((float)($ponRecord['berat'] ?? 0) * (int)($ponRecord['qty'] ?? 1))) ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Progress</div>
                        <div class="detail-value"><?= (int)($ponRecord['progress'] ?? 0) ?>%</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value"><?= h(ucfirst($ponRecord['status'] ?? 'Progres')) ?></div>
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
                                        data-tooltip="To Do (<?= $div['todo'] ?> tasks)"
                                        onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <!-- On Progress -->
                                    <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                        fill="transparent" stroke="#06b6d4" stroke-width="3"
                                        stroke-dasharray="<?= $div['progress_percent'] ?> <?= 100 - $div['progress_percent'] ?>"
                                        stroke-dashoffset="<?= 25 - $div['todo_percent'] ?>"
                                        data-tooltip="On Progress (<?= $div['progress'] ?> tasks)"
                                        onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <!-- Done -->
                                    <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                        fill="transparent" stroke="#10b981" stroke-width="3"
                                        stroke-dasharray="<?= $div['done_percent'] ?> <?= 100 - $div['done_percent'] ?>"
                                        stroke-dashoffset="<?= 25 - $div['todo_percent'] - $div['progress_percent'] ?>"
                                        data-tooltip="Done (<?= $div['done'] ?> tasks)"
                                        onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                                    <!-- Center text -->
                                    <text x="21" y="23" text-anchor="middle" fill="var(--text)" font-size="5" font-weight="600">
                                        <?= $div['total'] ?> Tasks
                                    </text>
                                </svg>
                                <div class="tooltip" id="tooltip-<?= strtolower($div['name']) ?>"></div>
                            </div>
                            <div class="division-name"><?= h($div['name']) ?></div>
                            <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($div['name']) ?>" class="view-task-btn">
                                Lihat Task
                            </a>
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

        // Tooltip functionality
        function showTooltip(event, element) {
            const tooltip = document.getElementById('tooltip') || document.getElementById('tooltip-' + element.closest('.division-card')?.querySelector('.division-name')?.textContent.toLowerCase()) || document.getElementById('tooltip');
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