<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header('Location: index.php');
  exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Dashboard';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// ✅ FUNGSI: Hitung Integrated Progress dari semua divisi
function getIntegratedProgress($ponCode)
{
  static $cache = [];

  if (isset($cache[$ponCode])) {
    return $cache[$ponCode];
  }

  // Data dari semua divisi
  $tasks = fetchAll('SELECT * FROM tasks WHERE pon = ?', [$ponCode]);
  $fabrikasiItems = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ?', [$ponCode]);
  $logistikWorkshopItems = fetchAll('SELECT * FROM logistik_workshop WHERE pon = ?', [$ponCode]);
  $logistikSiteItems = fetchAll('SELECT * FROM logistik_site WHERE pon = ?', [$ponCode]);

  $completedItems = 0;
  $totalItems = 0;

  // Hitung progress dari tasks
  foreach ($tasks as $task) {
    $totalItems++;
    if (strtolower($task['status'] ?? '') === 'done') {
      $completedItems++;
    }
  }

  // Hitung progress dari fabrikasi
  foreach ($fabrikasiItems as $item) {
    $totalItems++;
    if ((int)($item['progress_calculated'] ?? 0) == 100) {
      $completedItems++;
    }
  }

  // Hitung progress dari logistik workshop
  foreach ($logistikWorkshopItems as $item) {
    $totalItems++;
    if ($item['status'] === 'Terkirim') {
      $completedItems++;
    }
  }

  // Hitung progress dari logistik site
  foreach ($logistikSiteItems as $item) {
    $totalItems++;
    if ($item['status'] === 'Diterima') {
      $completedItems++;
    }
  }

  $progress = $totalItems > 0 ? (int)round(($completedItems / $totalItems) * 100) : 0;
  $cache[$ponCode] = $progress;
  return $progress;
}

// ✅ DATA UNTUK DASHBOARD

// 1. Total Projects & Statistics - FIXED: tambahkan type casting
$totalProjects = (int)(fetchOne("SELECT COUNT(*) as total FROM pon")['total'] ?? 0);
$activeProjects = (int)(fetchOne("SELECT COUNT(*) as total FROM pon WHERE status IN ('Progres', 'Pending')")['total'] ?? 0);
$completedProjects = (int)(fetchOne("SELECT COUNT(*) as total FROM pon WHERE status = 'Selesai'")['total'] ?? 0);
$delayedProjects = (int)(fetchOne("SELECT COUNT(*) as total FROM pon WHERE status = 'Delayed'")['total'] ?? 0);

// 2. ✅ PERBAIKAN: Total Weight HANYA dari 2 sumber (fabrikasi + logistik_workshop) - konsisten dengan pon.php
$totalWeightData = fetchOne("
    SELECT 
        COALESCE(SUM(fabrikasi_weight), 0) as total_fabrikasi,
        COALESCE(SUM(logistik_weight), 0) as total_logistik
    FROM (
        SELECT 
            p.pon,
            COALESCE(SUM(fi.total_weight_kg), 0) as fabrikasi_weight,
            COALESCE(SUM(lw.total_weight_kg), 0) as logistik_weight
        FROM pon p
        LEFT JOIN fabrikasi_items fi ON p.pon = fi.pon
        LEFT JOIN logistik_workshop lw ON p.pon = lw.pon
        GROUP BY p.pon
    ) as weight_summary
");

// FIXED: Konsisten dengan logic pon.php - hanya dari 2 sumber
$fabrikasiWeight = (float)($totalWeightData['total_fabrikasi'] ?? 0);
$logistikWeight = (float)($totalWeightData['total_logistik'] ?? 0);
$totalWeight = $fabrikasiWeight + $logistikWeight;

// 3. Recent Projects dengan Integrated Progress
$recentProjects = fetchAll("
    SELECT p.*
    FROM pon p
    ORDER BY p.created_at DESC 
    LIMIT 5
");

// Hitung integrated progress untuk recent projects
foreach ($recentProjects as &$project) {
  $project['integrated_progress'] = getIntegratedProgress($project['pon']);

  // ✅ PERBAIKAN: Hitung berat untuk recent projects dengan logic yang sama
  $ponCode = $project['pon'];
  $projectWeight = fetchOne("
        SELECT 
            COALESCE(SUM(fi.total_weight_kg), 0) + COALESCE(SUM(lw.total_weight_kg), 0) as total_weight
        FROM pon p
        LEFT JOIN fabrikasi_items fi ON p.pon = fi.pon
        LEFT JOIN logistik_workshop lw ON p.pon = lw.pon
        WHERE p.pon = ?
        GROUP BY p.pon
    ", [$ponCode]);

  $project['total_weight'] = (float)($projectWeight['total_weight'] ?? 0);
}
unset($project);

// 4. Project Status Distribution untuk Chart
$statusDistribution = fetchAll("
    SELECT status, COUNT(*) as count 
    FROM pon 
    GROUP BY status
");

// 5. Division Statistics dengan progress rata-rata - FIXED: type casting untuk AVG
$divisionStats = fetchAll("
    SELECT 
        division,
        COUNT(*) as total_tasks,
        AVG(CAST(progress AS DECIMAL(5,2))) as avg_progress,
        SUM(CASE WHEN status = 'Done' THEN 1 ELSE 0 END) as completed_tasks
    FROM tasks 
    WHERE division IS NOT NULL 
    GROUP BY division
");

// Data division untuk ring chart - FIXED: pastikan nilai integer
$divisions = [];
$divNames = ['Engineering', 'Purchasing', 'Pabrikasi', 'Logistik'];
foreach ($divNames as $dn) {
  $found = array_filter($divisionStats, fn($d) => $d['division'] === $dn);
  if ($found) {
    $div = reset($found);
    $divisions[] = [
      'name' => $dn,
      'progress' => (int)round((float)($div['avg_progress'] ?? 0)), // FIXED: type casting
      'total_tasks' => (int)($div['total_tasks'] ?? 0),
      'completed_tasks' => (int)($div['completed_tasks'] ?? 0)
    ];
  } else {
    $divisions[] = [
      'name' => $dn,
      'progress' => 0,
      'total_tasks' => 0,
      'completed_tasks' => 0
    ];
  }
}

// 6. Upcoming Deadlines (tasks due dalam 7 hari)
$upcomingDeadlines = fetchAll("
    SELECT t.title, t.pon, t.due_date, t.status, t.progress, p.nama_proyek, p.client
    FROM tasks t
    LEFT JOIN pon p ON t.pon = p.pon
    WHERE t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    AND t.status NOT IN ('Done', 'Hold')
    ORDER BY t.due_date ASC
    LIMIT 8
");

// 7. Recent Activities
$recentActivities = [];
try {
  // Activities dari task updates
  $recentTasks = fetchAll('SELECT t.title, t.pon, t.division, t.updated_at, t.status, p.client 
                            FROM tasks t 
                            LEFT JOIN pon p ON t.pon = p.pon 
                            WHERE t.updated_at IS NOT NULL 
                            ORDER BY t.updated_at DESC 
                            LIMIT 6');

  foreach ($recentTasks as $task) {
    $recentActivities[] = [
      'type' => 'task_update',
      'message' => "Task '{$task['title']}' di divisi {$task['division']} diupdate",
      'pon' => $task['pon'],
      'client' => $task['client'] ?? 'N/A',
      'time' => $task['updated_at']
    ];
  }

  // Activities dari PON creation
  $recentPons = fetchAll('SELECT pon, client, status, created_at 
                           FROM pon 
                           ORDER BY created_at DESC 
                           LIMIT 4');

  foreach ($recentPons as $pon) {
    $recentActivities[] = [
      'type' => 'pon_created',
      'message' => "PON baru '{$pon['pon']}' untuk client {$pon['client']}",
      'pon' => $pon['pon'],
      'client' => $pon['client'],
      'time' => $pon['created_at']
    ];
  }

  // Sort dan limit activities
  usort($recentActivities, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
  $recentActivities = array_slice($recentActivities, 0, 8);
} catch (Exception $e) {
  $recentActivities = [
    [
      'type' => 'system',
      'message' => 'Sistem dashboard berhasil dimuat',
      'pon' => 'SYSTEM',
      'client' => 'Internal',
      'time' => date('Y-m-d H:i:s')
    ]
  ];
}

// 8. Top Projects by Weight untuk chart - FIXED: gunakan logic weight yang sama
$topWeightPon = fetchAll("
    SELECT 
        p.pon,
        p.nama_proyek,
        p.client,
        COALESCE(SUM(fi.total_weight_kg), 0) + COALESCE(SUM(lw.total_weight_kg), 0) as total_weight
    FROM pon p
    LEFT JOIN fabrikasi_items fi ON p.pon = fi.pon
    LEFT JOIN logistik_workshop lw ON p.pon = lw.pon
    GROUP BY p.id, p.pon, p.nama_proyek, p.client
    HAVING total_weight > 0
    ORDER BY total_weight DESC 
    LIMIT 10
");

// FIXED: Pastikan maxWeight adalah float
$weights = array_map(fn($p) => (float)($p['total_weight'] ?? 0), $topWeightPon);
$maxWeight = $weights ? max($weights) : 1;

// Helper functions - FIXED: tambahkan type casting
function getStatusColor($status)
{
  $status = strtolower((string)$status);
  switch ($status) {
    case 'selesai':
      return '#10b981';
    case 'progres':
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
  if ($weight >= 1000) {
    return number_format($weight / 1000, 2) . ' ton';
  }
  return number_format($weight, 2) . ' kg';
}

function formatDate($date)
{
  if (!$date) return '-';
  return date('d M Y', strtotime($date));
}

function daysUntil($date)
{
  if (!$date) return null;
  $now = new DateTime();
  $future = new DateTime($date);
  $interval = $now->diff($future);
  return $interval->days;
}

?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($activeMenu) ?> - <?= h($appName) ?></title>
  <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
  <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
  <link rel="stylesheet" href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    :root {
      --primary: #3b82f6;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1f2937;
    }

    /* Statistics Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px -5px rgba(0, 0, 0, 0.15);
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary), var(--info));
    }

    .stat-card.success::before {
      background: linear-gradient(90deg, var(--success), #34d399);
    }

    .stat-card.warning::before {
      background: linear-gradient(90deg, var(--warning), #fbbf24);
    }

    .stat-card.danger::before {
      background: linear-gradient(90deg, var(--danger), #f87171);
    }

    .stat-content {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .stat-icon {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      background: rgba(59, 130, 246, 0.1);
      color: var(--primary);
    }

    .stat-card.success .stat-icon {
      background: rgba(16, 185, 129, 0.1);
      color: var(--success);
    }

    .stat-card.warning .stat-icon {
      background: rgba(245, 158, 11, 0.1);
      color: var(--warning);
    }

    .stat-card.danger .stat-icon {
      background: rgba(239, 68, 68, 0.1);
      color: var(--danger);
    }

    .stat-text {
      flex: 1;
    }

    .stat-value {
      font-size: 24px;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 4px;
      color: var(--text);
    }

    .stat-label {
      font-size: 13px;
      color: var(--muted);
      font-weight: 600;
    }

    .stat-trend {
      font-size: 11px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
      margin-top: 4px;
    }

    .trend-up {
      color: var(--success);
    }

    .trend-down {
      color: var(--danger);
    }

    /* Progress Divisi Section */
    .prog-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 20px;
      margin-top: 16px;
    }

    .prog-item {
      text-align: center;
      padding: 16px;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    .ring {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: conic-gradient(#3b82f6 calc(var(--val) * 1%), rgba(255, 255, 255, 0.1) 0);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto;
      position: relative;
    }

    .ring::before {
      content: '';
      position: absolute;
      width: 60px;
      height: 60px;
      background: var(--card-bg);
      border-radius: 50%;
    }

    .ring span {
      position: relative;
      z-index: 1;
      font-weight: 700;
      font-size: 14px;
      color: #93c5fd;
    }

    /* Dashboard Layout */
    .dashboard-layout {
      display: grid;
      grid-template-areas:
        "recent-projects activity"
        "weight-chart activity";
      grid-template-columns: 2fr 1fr;
      grid-template-rows: 1fr 1fr;
      gap: 20px;
      height: 600px;
      margin-top: 20px;
    }

    @media (max-width: 1200px) {
      .dashboard-layout {
        grid-template-areas:
          "recent-projects"
          "weight-chart"
          "activity";
        grid-template-columns: 1fr;
        grid-template-rows: 250px 250px 300px;
        height: auto;
      }
    }

    /* Recent Projects Section */
    .recent-projects {
      grid-area: recent-projects;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .projects-scroll {
      flex: 1;
      overflow: auto;
      margin: -8px;
      padding: 8px;
    }

    .project-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .project-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      background: rgba(255, 255, 255, 0.02);
      border: 1px solid var(--border);
      border-radius: 8px;
      transition: all 0.2s;
      text-decoration: none;
      color: inherit;
    }

    .project-item:hover {
      background: rgba(255, 255, 255, 0.05);
      transform: translateX(4px);
    }

    .project-avatar {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: linear-gradient(135deg, var(--primary), var(--info));
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      color: white;
      font-size: 12px;
      flex-shrink: 0;
    }

    .project-content {
      flex: 1;
      min-width: 0;
    }

    .project-name {
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 2px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .project-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 11px;
      color: var(--muted);
    }

    .project-progress {
      width: 80px;
      flex-shrink: 0;
    }

    .progress-bar {
      width: 100%;
      height: 6px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      border-radius: 3px;
      transition: width 0.3s ease;
    }

    /* Activity Section */
    .aktivitas-section {
      grid-area: activity;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .aktivitas-scroll {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      margin: -8px;
      padding: 8px;
    }

    .activity-item {
      display: flex;
      gap: 10px;
      padding: 8px 0;
      border-bottom: 1px solid var(--border);
      font-size: 12px;
    }

    .activity-item:last-child {
      border-bottom: none;
    }

    .activity-icon {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      flex-shrink: 0;
    }

    .activity-icon.task {
      background: rgba(59, 130, 246, 0.2);
      color: #93c5fd;
    }

    .activity-icon.pon {
      background: rgba(34, 197, 94, 0.2);
      color: #86efac;
    }

    .activity-icon.system {
      background: rgba(156, 163, 175, 0.2);
      color: #d1d5db;
    }

    .activity-content {
      flex: 1;
      min-width: 0;
    }

    .activity-message {
      color: var(--text);
      font-size: 11px;
      line-height: 1.3;
      margin-bottom: 2px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .activity-meta {
      color: var(--muted);
      font-size: 10px;
      display: flex;
      gap: 6px;
    }

    /* Weight Chart Section */
    .berat-section {
      grid-area: weight-chart;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .berat-scroll {
      flex: 1;
      overflow-y: auto;
      overflow-x: hidden;
      margin: -8px;
      padding: 8px;
    }

    .bars {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .bar {
      flex-shrink: 0;
    }

    .bar .meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
      font-size: 11px;
      color: var(--text);
    }

    .bar .track {
      height: 8px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
      overflow: hidden;
    }

    .bar .fill {
      height: 100%;
      background: linear-gradient(90deg, #3b82f6, #06b6d4);
      border-radius: 4px;
      transition: width 0.3s ease;
    }

    /* Section Headers */
    .section-header {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 12px;
      color: var(--text);
      font-weight: 600;
      font-size: 14px;
    }

    .section-header .icon {
      color: #93c5fd;
      font-size: 16px;
    }

    /* Status Badges */
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-progres {
      background: rgba(59, 130, 246, 0.15);
      color: #93c5fd;
    }

    .status-selesai {
      background: rgba(16, 185, 129, 0.15);
      color: #86efac;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.15);
      color: #fcd34d;
    }

    .status-delayed {
      background: rgba(239, 68, 68, 0.15);
      color: #fca5a5;
    }

    /* Deadline Warnings */
    .deadline-warning {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px;
      background: rgba(245, 158, 11, 0.1);
      border: 1px solid rgba(245, 158, 11, 0.3);
      border-radius: 6px;
      margin-top: 8px;
      font-size: 11px;
    }

    .deadline-icon {
      color: #f59e0b;
      font-size: 14px;
    }

    .deadline-text {
      flex: 1;
      color: #fcd34d;
    }

    .days-left {
      font-size: 10px;
      background: #f59e0b;
      color: white;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 600;
    }

    /* Empty States */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: var(--muted);
    }

    .empty-state i {
      font-size: 32px;
      margin-bottom: 12px;
      opacity: 0.5;
    }

    .empty-state p {
      margin-bottom: 0;
      font-size: 13px;
    }

    /* Scrollbars */
    .projects-scroll::-webkit-scrollbar,
    .aktivitas-scroll::-webkit-scrollbar,
    .berat-scroll::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    .projects-scroll::-webkit-scrollbar-track,
    .aktivitas-scroll::-webkit-scrollbar-track,
    .berat-scroll::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
    }

    .projects-scroll::-webkit-scrollbar-thumb,
    .aktivitas-scroll::-webkit-scrollbar-thumb,
    .berat-scroll::-webkit-scrollbar-thumb {
      background: rgba(147, 197, 253, 0.5);
      border-radius: 3px;
    }
  </style>
</head>

<body>
  <div class="layout">
    <!-- Sidebar -->
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

    <!-- Header -->
    <header class="header">
      <div class="title"><?= h($activeMenu) ?></div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <!-- Statistics Cards -->
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-content">
            <div class="stat-icon">
              <i class="bi bi-journal-text"></i>
            </div>
            <div class="stat-text">
              <div class="stat-value"><?= $totalProjects ?></div>
              <div class="stat-label">Total Projects</div>
              <div class="stat-trend trend-up">
                <i class="bi bi-arrow-up"></i>
                <?= $activeProjects ?> aktif
              </div>
            </div>
          </div>
        </div>

        <div class="stat-card success">
          <div class="stat-content">
            <div class="stat-icon">
              <i class="bi bi-check-circle"></i>
            </div>
            <div class="stat-text">
              <div class="stat-value"><?= $completedProjects ?></div>
              <div class="stat-label">Completed</div>
              <div class="stat-trend trend-up">
                <i class="bi bi-arrow-up"></i>
                <?= $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100) : 0 ?>% rate
              </div>
            </div>
          </div>
        </div>

        <div class="stat-card warning">
          <div class="stat-content">
            <div class="stat-icon">
              <i class="bi bi-clock"></i>
            </div>
            <div class="stat-text">
              <div class="stat-value"><?= $delayedProjects ?></div>
              <div class="stat-label">Delayed</div>
              <div class="stat-trend trend-down">
                <i class="bi bi-exclamation-triangle"></i>
                Perlu perhatian
              </div>
            </div>
          </div>
        </div>

        <div class="stat-card danger">
          <div class="stat-content">
            <div class="stat-icon">
              <i class="bi bi-box-seam"></i>
            </div>
            <div class="stat-text">
              <div class="stat-value"><?= formatWeight($totalWeight) ?></div>
              <div class="stat-label">Total Weight (Fabrikasi + Logistik)</div> <!-- PERBAIKI LABEL -->
              <div class="stat-trend trend-up">
                <i class="bi bi-arrow-up"></i>
                Fabrikasi: <?= formatWeight($fabrikasiWeight) ?> <!-- TAMBAHKAN DETAIL -->
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Progress Divisi Section -->
      <section class="section">
        <div class="hd">Progres Divisi Terintegrasi</div>
        <div class="bd">
          <div class="prog-grid">
            <?php foreach ($divisions as $div): ?>
              <div class="prog-item">
                <div class="ring" style="--val: <?= (int)$div['progress'] ?>;">
                  <span><?= (int)$div['progress'] ?>%</span>
                </div>
                <div style="text-align:center; margin-top:8px;">
                  <div style="font-weight:700; color:#93c5fd; font-size:13px;"><?= h($div['name']) ?></div>
                  <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                    <?= $div['completed_tasks'] ?>/<?= $div['total_tasks'] ?> tasks
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- Upcoming Deadlines -->
      <?php if (!empty($upcomingDeadlines)): ?>
        <section class="section">
          <div class="hd">Deadline Mendatang (7 Hari)</div>
          <div class="bd">
            <div style="display: flex; flex-direction: column; gap: 8px;">
              <?php foreach ($upcomingDeadlines as $task): ?>
                <div class="deadline-warning">
                  <i class="bi bi-calendar-x deadline-icon"></i>
                  <div class="deadline-text">
                    <strong><?= h($task['title']) ?></strong> - <?= h($task['nama_proyek']) ?>
                  </div>
                  <?php $daysLeft = daysUntil($task['due_date']); ?>
                  <?php if ($daysLeft !== null): ?>
                    <div class="days-left"><?= $daysLeft ?> hari</div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <!-- Dashboard Layout: Recent Projects, Weight Chart, Activity -->
      <div class="dashboard-layout">
        <!-- Recent Projects Section -->
        <div class="recent-projects">
          <div class="section-header">
            <i class="bi bi-clock icon"></i>
            <span>Proyek Terbaru</span>
          </div>
          <div class="projects-scroll">
            <div class="project-list">
              <?php if (empty($recentProjects)): ?>
                <div class="empty-state">
                  <i class="bi bi-journal-text"></i>
                  <p>Belum ada proyek</p>
                </div>
              <?php else: ?>
                <?php foreach ($recentProjects as $project): ?>
                  <a href="pon_view.php?pon=<?= urlencode($project['pon']) ?>" class="project-item">
                    <div class="project-avatar">
                      <?= substr($project['pon'] ?? 'PON', -2) ?>
                    </div>
                    <div class="project-content">
                      <div class="project-name"><?= h($project['nama_proyek']) ?></div>
                      <div class="project-meta">
                        <span><?= h($project['client']) ?></span>
                        <span>•</span>
                        <span class="status-badge status-<?= strtolower($project['status']) ?>">
                          <?= h($project['status']) ?>
                        </span>
                      </div>
                    </div>
                    <div class="project-progress">
                      <div class="progress-bar">
                        <div class="progress-fill"
                          style="width: <?= $project['integrated_progress'] ?>%; 
                                                            background: <?= getProgressColor($project['integrated_progress']) ?>">
                        </div>
                      </div>
                      <div style="font-size: 10px; color: var(--muted); text-align: center; margin-top: 4px;">
                        <?= $project['integrated_progress'] ?>%
                      </div>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Weight Chart Section -->
        <div class="berat-section">
          <div class="section-header">
            <i class="bi bi-bar-chart icon"></i>
            <span>Berat Proyek Terbanyak</span>
          </div>
          <div class="berat-scroll">
            <div class="bars">
              <?php foreach ($topWeightPon as $i => $project): ?>
                <div class="bar">
                  <div class="meta">
                    <span>#<?= $i + 1 ?> <?= h($project['pon']) ?></span>
                    <span><?= formatWeight($project['total_weight']) ?></span>
                  </div>
                  <div class="track">
                    <div class="fill" style="width: <?= $project['total_weight'] > 0 ? (int)(($project['total_weight'] / $maxWeight) * 100) : 0 ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>

              <?php if (empty($topWeightPon)): ?>
                <div class="empty-state">
                  <i class="bi bi-bar-chart"></i>
                  <p>Belum ada data berat</p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Activity Section -->
        <div class="aktivitas-section">
          <div class="section-header">
            <i class="bi bi-clock-history icon"></i>
            <span>Riwayat Aktivitas</span>
          </div>
          <div class="aktivitas-scroll">
            <?php if (empty($recentActivities)): ?>
              <div class="empty-state">
                <i class="bi bi-clock-history"></i>
                <p>Belum ada aktivitas</p>
              </div>
            <?php else: ?>
              <?php foreach ($recentActivities as $activity): ?>
                <div class="activity-item">
                  <div class="activity-icon <?= $activity['type'] === 'task_update' ? 'task' : ($activity['type'] === 'pon_created' ? 'pon' : 'system') ?>">
                    <?php if ($activity['type'] === 'task_update'): ?>
                      <i class="bi bi-list-task"></i>
                    <?php elseif ($activity['type'] === 'pon_created'): ?>
                      <i class="bi bi-plus-circle"></i>
                    <?php else: ?>
                      <i class="bi bi-gear"></i>
                    <?php endif; ?>
                  </div>
                  <div class="activity-content">
                    <div class="activity-message"><?= h($activity['message']) ?></div>
                    <div class="activity-meta">
                      <span><?= h($activity['pon']) ?></span>
                      <span>•</span>
                      <span><?= date('d/m H:i', strtotime($activity['time'])) ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Project Management Dashboard</footer>
  </div>

  <script>
    // Clock functionality
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

    // Animated ring progress
    (function() {
      const rings = document.querySelectorAll('.ring');
      rings.forEach(el => {
        const target = Number(el.style.getPropertyValue('--val')) || 0;
        let cur = 0;
        const step = Math.max(1, Math.round(target / 30));
        const timer = setInterval(() => {
          cur += step;
          if (cur >= target) {
            cur = target;
            clearInterval(timer);
          }
          el.style.setProperty('--val', String(cur));
          const label = el.querySelector('span');
          if (label) label.textContent = cur + '%';
        }, 30);
      });
    })();

    // Add hover effects to stat cards
    document.addEventListener('DOMContentLoaded', function() {
      const statCards = document.querySelectorAll('.stat-card');
      statCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-2px)';
        });
        card.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0)';
        });
      });
    });
  </script>
</body>

</html>