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

// Calculate Workshop Statistics - Update untuk status baru
$workshopStats = fetchOne("
    SELECT 
        COUNT(*) as total_items,
        SUM(total_weight_kg) as total_weight,
        SUM(CASE WHEN status = 'Terkirim' THEN total_weight_kg ELSE 0 END) as weight_terkirim,
        SUM(CASE WHEN status = 'Belum Terkirim' THEN total_weight_kg ELSE 0 END) as weight_belum_terkirim,
        SUM(CASE WHEN status = 'Terkirim' THEN 1 ELSE 0 END) as count_terkirim,
        SUM(CASE WHEN status = 'Belum Terkirim' THEN 1 ELSE 0 END) as count_belum_terkirim
    FROM logistik_workshop 
    WHERE pon = ?
", [$ponCode]);

// Calculate Site Statistics - Update untuk status baru
$siteStats = fetchOne("
    SELECT 
        COUNT(*) as total_items,
        SUM(sent_to_site_weight) as total_sent,
        SUM(CASE WHEN status = 'Diterima' THEN sent_to_site_weight ELSE 0 END) as weight_diterima,
        SUM(CASE WHEN status = 'Menunggu' THEN sent_to_site_weight ELSE 0 END) as weight_menunggu,
        SUM(CASE WHEN status = 'Diterima' THEN 1 ELSE 0 END) as count_diterima,
        SUM(CASE WHEN status = 'Menunggu' THEN 1 ELSE 0 END) as count_menunggu
    FROM logistik_site 
    WHERE pon = ?
", [$ponCode]);

// PERBAIKAN: Convert semua nilai weight ke float dan handle null values
$workshopStats = [
    'total_items' => (int)($workshopStats['total_items'] ?? 0),
    'total_weight' => (float)($workshopStats['total_weight'] ?? 0),
    'weight_terkirim' => (float)($workshopStats['weight_terkirim'] ?? 0),
    'weight_belum_terkirim' => (float)($workshopStats['weight_belum_terkirim'] ?? 0),
    'count_terkirim' => (int)($workshopStats['count_terkirim'] ?? 0),
    'count_belum_terkirim' => (int)($workshopStats['count_belum_terkirim'] ?? 0)
];

$siteStats = [
    'total_items' => (int)($siteStats['total_items'] ?? 0),
    'total_sent' => (float)($siteStats['total_sent'] ?? 0),
    'weight_diterima' => (float)($siteStats['weight_diterima'] ?? 0),
    'weight_menunggu' => (float)($siteStats['weight_menunggu'] ?? 0),
    'count_diterima' => (int)($siteStats['count_diterima'] ?? 0),
    'count_menunggu' => (int)($siteStats['count_menunggu'] ?? 0)
];

// Calculate percentages dengan handling division by zero
$workshopStats['terkirim_percent'] = $workshopStats['total_weight'] > 0 ?
    round(($workshopStats['weight_terkirim'] / $workshopStats['total_weight']) * 100) : 0;
$workshopStats['belum_terkirim_percent'] = 100 - $workshopStats['terkirim_percent'];

$siteStats['diterima_percent'] = $siteStats['total_sent'] > 0 ?
    round(($siteStats['weight_diterima'] / $siteStats['total_sent']) * 100) : 0;
$siteStats['menunggu_percent'] = 100 - $siteStats['diterima_percent'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logistik Dashboard - <?= h($appName) ?></title>
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

        .dashboard-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 30px;
        }

        @media (max-width: 1024px) {
            .dashboard-container {
                grid-template-columns: 1fr;
            }
        }

        .menu-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            transition: all 0.3s ease;
        }

        .menu-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .menu-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .menu-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }

        .menu-icon {
            font-size: 32px;
            color: #3b82f6;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 12px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
        }

        .pie-chart-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 20px;
        }

        .pie-chart {
            width: 150px;
            height: 150px;
        }

        .chart-legend {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .legend-terkirim {
            background: #10b981;
        }

        .legend-belum-terkirim {
            background: #ef4444;
        }

        .legend-diterima {
            background: #10b981;
        }

        .legend-menunggu {
            background: #f59e0b;
        }

        .btn-task {
            background: #1d4ed8;
            border: 1px solid #3b82f6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            width: 100%;
            justify-content: center;
        }

        .btn-task:hover {
            background: #1e40af;
        }

        .weight-info {
            text-align: center;
            margin-bottom: 16px;
        }

        .weight-total {
            font-size: 14px;
            color: var(--text);
            font-weight: 600;
        }

        .weight-label {
            font-size: 12px;
            color: var(--muted);
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
            <div class="title">Logistik Dashboard - <?= strtoupper(h($ponCode)) ?></div>
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
                    <div class="page-title">Dashboard Logistik</div>
                </div>
            </div>

            <div class="dashboard-container">
                <!-- Workshop Menu -->
                <div class="menu-card">
                    <div class="menu-header">
                        <div class="menu-title">Workshop</div>
                        <div class="menu-icon"><i class="bi bi-building-gear"></i></div>
                    </div>

                    <div class="weight-info">
                        <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                        <div class="weight-total"><?= number_format($workshopStats['total_weight'], 2) ?> kg</div>
                        <div class="weight-label">Total Weight</div>
                    </div>

                    <div class="pie-chart-container">
                        <svg class="pie-chart" viewBox="0 0 42 42">
                            <!-- Terkirim -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#10b981" stroke-width="3"
                                stroke-dasharray="<?= $workshopStats['terkirim_percent'] ?> <?= 100 - $workshopStats['terkirim_percent'] ?>"
                                stroke-dashoffset="25"
                                data-tooltip="Terkirim (<?= number_format($workshopStats['weight_terkirim'], 2) ?> kg)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Belum Terkirim -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#ef4444" stroke-width="3"
                                stroke-dasharray="<?= $workshopStats['belum_terkirim_percent'] ?> <?= 100 - $workshopStats['belum_terkirim_percent'] ?>"
                                stroke-dashoffset="<?= 25 - $workshopStats['terkirim_percent'] ?>"
                                data-tooltip="Belum Terkirim (<?= number_format($workshopStats['weight_belum_terkirim'], 2) ?> kg)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Center text -->
                            <text x="21" y="21" text-anchor="middle" fill="var(--text)" font-size="6" font-weight="600">
                                <?= $workshopStats['total_items'] ?>
                            </text>
                            <text x="21" y="26" text-anchor="middle" fill="var(--muted)" font-size="4">
                                Items
                            </text>
                        </svg>
                    </div>

                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color legend-terkirim"></div>
                            <span>Terkirim (<?= number_format($workshopStats['weight_terkirim'], 2) ?>)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-belum-terkirim"></div>
                            <span>Belum (<?= number_format($workshopStats['weight_belum_terkirim'], 2) ?>)</span>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-item">
                            <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                            <div class="stat-value"><?= number_format($workshopStats['weight_terkirim'], 2) ?></div>
                            <div class="stat-label">Weight Terkirim (kg)</div>
                        </div>
                        <div class="stat-item">
                            <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                            <div class="stat-value"><?= number_format($workshopStats['weight_belum_terkirim'], 2) ?></div>
                            <div class="stat-label">Weight Belum (kg)</div>
                        </div>
                    </div>

                    <a href="logistik_workshop.php?pon=<?= urlencode($ponCode) ?>" class="btn-task">
                        <i class="bi bi-list-task"></i>
                        Lihat Task Workshop
                    </a>
                </div>

                <!-- Site Menu -->
                <div class="menu-card">
                    <div class="menu-header">
                        <div class="menu-title">Site</div>
                        <div class="menu-icon"><i class="bi bi-geo-alt"></i></div>
                    </div>

                    <div class="weight-info">
                        <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                        <div class="weight-total"><?= number_format($siteStats['total_sent'], 2) ?> kg</div>
                        <div class="weight-label">Total Sent to Site</div>
                    </div>

                    <div class="pie-chart-container">
                        <svg class="pie-chart" viewBox="0 0 42 42">
                            <!-- Diterima -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#10b981" stroke-width="3"
                                stroke-dasharray="<?= $siteStats['diterima_percent'] ?> <?= 100 - $siteStats['diterima_percent'] ?>"
                                stroke-dashoffset="25"
                                data-tooltip="Diterima (<?= number_format($siteStats['weight_diterima'], 2) ?> kg)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Menunggu -->
                            <circle class="pie-slice" cx="21" cy="21" r="15.915"
                                fill="transparent" stroke="#f59e0b" stroke-width="3"
                                stroke-dasharray="<?= $siteStats['menunggu_percent'] ?> <?= 100 - $siteStats['menunggu_percent'] ?>"
                                stroke-dashoffset="<?= 25 - $siteStats['diterima_percent'] ?>"
                                data-tooltip="Menunggu (<?= number_format($siteStats['weight_menunggu'], 2) ?> kg)"
                                onmouseover="showTooltip(event, this)" onmouseout="hideTooltip()"></circle>
                            <!-- Center text -->
                            <text x="21" y="21" text-anchor="middle" fill="var(--text)" font-size="6" font-weight="600">
                                <?= $siteStats['total_items'] ?>
                            </text>
                            <text x="21" y="26" text-anchor="middle" fill="var(--muted)" font-size="4">
                                Items
                            </text>
                        </svg>
                    </div>

                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color legend-diterima"></div>
                            <span>Diterima (<?= number_format($siteStats['weight_diterima'], 2) ?>)</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color legend-menunggu"></div>
                            <span>Menunggu (<?= number_format($siteStats['weight_menunggu'], 2) ?>)</span>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-item">
                            <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                            <div class="stat-value"><?= number_format($siteStats['weight_diterima'], 2) ?></div>
                            <div class="stat-label">Weight Diterima (kg)</div>
                        </div>
                        <div class="stat-item">
                            <!-- PERBAIKAN: Pastikan parameter number_format adalah float -->
                            <div class="stat-value"><?= number_format($siteStats['weight_menunggu'], 2) ?></div>
                            <div class="stat-label">Weight Menunggu (kg)</div>
                        </div>
                    </div>

                    <a href="logistik_site.php?pon=<?= urlencode($ponCode) ?>" class="btn-task">
                        <i class="bi bi-list-task"></i>
                        Lihat Task Site
                    </a>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Logistik Dashboard</footer>
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
            const tooltipId = element.closest('.menu-card').querySelector('.menu-title').textContent.toLowerCase() === 'workshop' ?
                'tooltip-workshop' : 'tooltip-site';
            const tooltip = document.getElementById(tooltipId);

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
                    const tooltip = this.closest('.pie-chart-container').querySelector('.tooltip');
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