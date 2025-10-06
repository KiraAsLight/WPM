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

// Handle delete request first
if (isset($_GET['delete'])) {
  $delJobNo = $_GET['delete'];
  try {
    delete('pon', 'job_no = :job_no', ['job_no' => $delJobNo]);
    header('Location: pon.php?deleted=1');
    exit;
  } catch (Exception $e) {
    header('Location: pon.php?error=delete_failed');
    exit;
  }
}

// Muat PON dari database dengan calculated berat
$pons = fetchAll("
    SELECT *,
           CASE 
               WHEN material_type = 'AG25' THEN qty * 25
               WHEN material_type = 'AG32' THEN qty * 32
               WHEN material_type = 'AG50' THEN qty * 50
               ELSE 0
           END as berat_calculated
    FROM pon 
    ORDER BY project_start DESC, created_at DESC
");

// Hitung statistics
$totalBerat = array_sum(array_map(fn($r) => (float) $r['berat_calculated'], $pons));
$avgProgress = count($pons) > 0 ? (int) round(array_sum(array_map(fn($r) => (int) $r['progress'], $pons)) / count($pons)) : 0;

// Hitung status distribution
$statusCounts = [
  'Progress' => 0,
  'Selesai' => 0,
  'Pending' => 0,
  'Delayed' => 0
];

foreach ($pons as $pon) {
  $status = $pon['status'];
  if (isset($statusCounts[$status])) {
    $statusCounts[$status]++;
  }
}

// Data untuk charts
$monthlyData = [];
$statusData = [
  ['name' => 'In Progress', 'value' => $statusCounts['Progress'], 'color' => '#f59e0b'],
  ['name' => 'Completed', 'value' => $statusCounts['Selesai'], 'color' => '#10b981'],
  ['name' => 'Pending', 'value' => $statusCounts['Pending'], 'color' => '#6b7280'],
  ['name' => 'Delayed', 'value' => $statusCounts['Delayed'], 'color' => '#ef4444']
];

// Material distribution
$materialData = [];
foreach ($pons as $pon) {
  $material = $pon['material_type'];
  if (!isset($materialData[$material])) {
    $materialData[$material] = 0;
  }
  $materialData[$material]++;
}

// Helper function untuk progress color
function getProgressColor($progress)
{
  if ($progress >= 90) return '#10b981';
  if ($progress >= 70) return '#3b82f6';
  if ($progress >= 50) return '#f59e0b';
  if ($progress >= 30) return '#f97316';
  return '#ef4444';
}

// Format date untuk display
function formatDate($date)
{
  if (!$date) return '-';
  return date('d/m/Y', strtotime($date));
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PON - <?= h($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet"
    href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .table-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .search-input {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px 12px;
      border-radius: 8px;
      min-width: 280px;
      font-size: 14px;
    }

    .search-input::placeholder {
      color: var(--muted);
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #1d4ed8;
      border: 1px solid #3b82f6;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-primary:hover {
      background: #1e40af;
      transform: translateY(-1px);
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: transparent;
      border: 1px solid var(--border);
      color: var(--text);
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 500;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .btn-danger {
      background: #dc2626;
      border-color: #ef4444;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .btn-danger:hover {
      background: #b91c1c;
    }

    .btn-warning {
      background: #f59e0b;
      border-color: #fbbf24;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .btn-info {
      background: #0ea5e9;
      border-color: #38bdf8;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .action-buttons {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }

    /* Enhanced Table Styles */
    .project-table {
      width: 100%;
      border-collapse: collapse;
      background: var(--card-bg);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .project-table th {
      background: rgba(255, 255, 255, 0.03);
      padding: 16px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--muted);
      border-bottom: 1px solid var(--border);
      text-align: left;
    }

    .project-table td {
      padding: 20px 12px;
      vertical-align: top;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .project-row:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Project Information Styles */
    .job-number {
      font-weight: 700;
      font-size: 14px;
      color: #3b82f6;
      margin-bottom: 4px;
    }

    .project-name {
      font-weight: 600;
      font-size: 15px;
      line-height: 1.3;
      margin-bottom: 6px;
      color: var(--text);
    }

    .project-subject {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.4;
    }

    /* Client & Location Styles */
    .client-name {
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 8px;
      color: var(--text);
    }

    .project-location {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.5;
      margin-bottom: 6px;
    }

    .contract-info {
      font-size: 12px;
      color: var(--muted);
      font-family: 'Courier New', monospace;
    }

    /* Technical Specs Styles */
    .specs-grid {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .spec-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
    }

    .spec-label {
      color: var(--muted);
      font-weight: 500;
    }

    .spec-value {
      font-weight: 600;
      color: var(--text);
    }

    /* Progress Bar */
    .progress-container {
      min-width: 120px;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }

    .progress-percent {
      font-weight: 700;
      font-size: 14px;
      color: var(--text);
    }

    .progress-label {
      font-size: 11px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .progress-bar {
      height: 8px;
      background: var(--border);
      border-radius: 4px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      border-radius: 4px;
      transition: width 0.3s ease;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    /* Timeline Styles */
    .timeline-info {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .date-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--text);
    }

    .date-item i {
      color: var(--muted);
      width: 14px;
    }

    .date-item.completed {
      color: #10b981;
    }

    .date-item.completed i {
      color: #10b981;
    }

    /* Status Badges */
    .status-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-progress {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .status-selesai {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .status-pending {
      background: rgba(107, 114, 128, 0.15);
      color: #6b7280;
      border: 1px solid rgba(107, 114, 128, 0.3);
    }

    .status-delayed {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .delay-warning {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      color: #ef4444;
      margin-top: 6px;
      padding: 4px 8px;
      background: rgba(239, 68, 68, 0.1);
      border-radius: 4px;
    }

    /* Dashboard Cards */
    .grid-4 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .stat-card {
      transition: transform 0.2s;
      background: var(--card-bg);
      border-radius: 12px;
      padding: 20px;
      border: 1px solid var(--border);
    }

    .stat-card:hover {
      transform: translateY(-2px);
    }

    .stat-card .card-body {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .stat-icon {
      width: 54px;
      height: 54px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
    }

    .stat-content .value {
      font-size: 28px;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 4px;
    }

    .stat-content .label {
      font-size: 13px;
      color: var(--muted);
      font-weight: 500;
    }

    /* Charts Section */
    .charts-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
    }

    .chart-card {
      background: var(--card-bg);
      border-radius: 12px;
      padding: 20px;
      border: 1px solid var(--border);
    }

    .chart-header {
      display: flex;
      justify-content: between;
      align-items: center;
      margin-bottom: 16px;
    }

    .chart-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text);
    }

    .chart-container {
      position: relative;
      height: 250px;
    }

    /* Notifications */
    .notice {
      background: rgba(34, 197, 94, 0.12);
      color: #86efac;
      border: 1px solid rgba(34, 197, 94, 0.35);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .notice i {
      font-size: 16px;
    }

    .error-notice {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #fecaca;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted);
    }

    .empty-state i {
      font-size: 64px;
      opacity: 0.3;
      margin-bottom: 16px;
    }

    .empty-state h3 {
      font-size: 18px;
      margin-bottom: 8px;
      color: var(--text);
    }

    .empty-state p {
      font-size: 14px;
      margin-bottom: 20px;
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
        <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php">
          <span class="icon bi-bar-chart"></span> Progress Divisi
        </a>
        <a href="logout.php">
          <span class="icon bi-box-arrow-right"></span> Logout
        </a>
      </nav>
    </aside>

    <!-- Header -->
    <header class="header">
      <div class="title">Project Order Notification (PON)</div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <!-- Dashboard Overview -->
      <div class="grid-4">
        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1);">
              <i class="bi bi-journal-text" style="color: #3b82f6;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= count($pons) ?></div>
              <div class="label">Total Projects</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);">
              <i class="bi bi-check-circle" style="color: #10b981;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= $statusCounts['Selesai'] ?></div>
              <div class="label">Completed</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);">
              <i class="bi bi-clock" style="color: #f59e0b;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= $statusCounts['Delayed'] ?></div>
              <div class="label">Delayed</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1);">
              <i class="bi bi-box-seam" style="color: #8b5cf6;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= h(kg($totalBerat)) ?></div>
              <div class="label">Total Weight</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts Section -->
      <div class="charts-grid">
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">Project Status Distribution</h3>
          </div>
          <div class="chart-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>

        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">Material Types Distribution</h3>
          </div>
          <div class="chart-container">
            <canvas id="materialChart"></canvas>
          </div>
        </div>
      </div>

      <section class="section">
        <div class="hd">
          <div style="display: flex; justify-content: between; align-items: center;">
            <span>PROJECT LIST</span>
            <div style="display: flex; gap: 12px; align-items: center;">
              <a href="#" class="btn-secondary">
                <i class="bi bi-download"></i> Export CSV
              </a>
              <a href="pon_new.php" class="btn-primary">
                <i class="bi bi-plus-circle"></i> New Project
              </a>
            </div>
          </div>
        </div>
        <div class="bd">
          <?php if (isset($_GET['added'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project baru berhasil ditambahkan.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['deleted'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project berhasil dihapus.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['updated'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project berhasil diupdate.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
            <div class="notice error-notice">
              <i class="bi bi-exclamation-circle"></i>
              Gagal menghapus project.
            </div>
          <?php endif; ?>

          <div class="table-actions">
            <input class="search-input" id="q" type="search" placeholder="Cari Job No/Project/Client..." oninput="filterTable()">
          </div>

          <div style="overflow: auto; border-radius: 12px;">
            <table class="project-table">
              <thead>
                <tr>
                  <th>JOB NO</th>
                  <th>PROJECT INFORMATION</th>
                  <th>CLIENT & LOCATION</th>
                  <th>TECHNICAL SPECS</th>
                  <th>PROGRESS</th>
                  <th>TIMELINE</th>
                  <th>STATUS</th>
                  <th>ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pons)): ?>
                  <tr>
                    <td colspan="8">
                      <div class="empty-state">
                        <i class="bi bi-journal-text"></i>
                        <h3>No Projects Found</h3>
                        <p>Get started by creating your first project</p>
                        <a href="pon_new.php" class="btn-primary">
                          <i class="bi bi-plus-circle"></i> Create Project
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($pons as $r):
                    $status = strtolower($r['status']);
                    $isDelayed = $r['status'] === 'Delayed' || ($r['date_finish'] && strtotime($r['date_finish']) < time() && $r['status'] !== 'Selesai');
                  ?>
                    <tr class="project-row">
                      <!-- Job No -->
                      <td>
                        <div class="job-number"><?= h($r['job_no']) ?></div>
                      </td>

                      <!-- Project Information -->
                      <td>
                        <div class="project-name"><?= h($r['nama_proyek']) ?></div>
                        <div class="project-subject"><?= h($r['subject']) ?></div>
                      </td>

                      <!-- Client & Location -->
                      <td>
                        <div class="client-name"><?= h($r['client']) ?></div>
                        <div class="project-location">
                          <?= nl2br(h($r['alamat_kontrak'])) ?>
                        </div>
                        <div class="contract-info">
                          <?= h($r['no_contract']) ?>
                        </div>
                      </td>

                      <!-- Technical Specs -->
                      <td>
                        <div class="specs-grid">
                          <div class="spec-item">
                            <span class="spec-label">Material:</span>
                            <span class="spec-value"><?= h($r['material_type']) ?></span>
                          </div>
                          <div class="spec-item">
                            <span class="spec-label">QTY:</span>
                            <span class="spec-value"><?= h($r['qty']) ?> units</span>
                          </div>
                          <div class="spec-item">
                            <span class="spec-label">Weight:</span>
                            <span class="spec-value"><?= h(kg($r['berat_calculated'])) ?></span>
                          </div>
                        </div>
                      </td>

                      <!-- Progress -->
                      <td>
                        <div class="progress-container">
                          <div class="progress-header">
                            <span class="progress-percent"><?= (int)$r['progress'] ?>%</span>
                            <span class="progress-label">Complete</span>
                          </div>
                          <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= (int)$r['progress'] ?>%; 
                              background: <?= getProgressColor($r['progress']) ?>"></div>
                          </div>
                        </div>
                      </td>

                      <!-- Timeline -->
                      <td>
                        <div class="timeline-info">
                          <div class="date-item">
                            <i class="bi bi-calendar-check"></i>
                            <span>Start: <?= formatDate($r['project_start']) ?></span>
                          </div>
                          <div class="date-item">
                            <i class="bi bi-flag"></i>
                            <span>PON: <?= formatDate($r['date_pon']) ?></span>
                          </div>
                          <?php if ($r['date_finish']): ?>
                            <div class="date-item <?= $r['status'] === 'Selesai' ? 'completed' : '' ?>">
                              <i class="bi bi-check-circle"></i>
                              <span>Finish: <?= formatDate($r['date_finish']) ?></span>
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>

                      <!-- Status -->
                      <td>
                        <div class="status-container">
                          <span class="status-badge status-<?= $status ?>">
                            <?= h(ucfirst($r['status'])) ?>
                          </span>
                          <?php if ($isDelayed): ?>
                            <div class="delay-warning">
                              <i class="bi bi-exclamation-triangle"></i>
                              <span>Behind schedule</span>
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>

                      <!-- Actions -->
                      <td>
                        <div class="action-buttons">
                          <a href="pon_view.php?job_no=<?= urlencode($r['job_no']) ?>" class="btn-info" title="View Details">
                            <i class="bi bi-eye"></i>
                          </a>
                          <a href="pon_edit.php?job_no=<?= urlencode($r['job_no']) ?>" class="btn-warning" title="Edit">
                            <i class="bi bi-pencil"></i>
                          </a>
                          <a href="tasklist.php?pon=<?= urlencode($r['pon']) ?>" class="btn-primary" title="Tasks" style="padding: 6px 10px;">
                            <i class="bi bi-list-check"></i>
                          </a>
                          <a href="pon.php?delete=<?= urlencode($r['job_no']) ?>"
                            class="btn-danger"
                            title="Delete Project"
                            onclick="return confirm('Yakin ingin menghapus project <?= h($r['job_no']) ?>? Data ini tidak dapat dikembalikan.')">
                            <i class="bi bi-trash"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Project Management</footer>
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

    // Table filtering
    function filterTable() {
      const q = (document.getElementById('q').value || '').toLowerCase();
      const rows = document.querySelectorAll('.project-table tbody tr');
      rows.forEach(tr => {
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
      });
    }

    // Charts
    document.addEventListener('DOMContentLoaded', function() {
      // Status Distribution Chart
      const statusCtx = document.getElementById('statusChart').getContext('2d');
      const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: ['In Progress', 'Completed', 'Pending', 'Delayed'],
          datasets: [{
            data: [<?= $statusCounts['Progress'] ?>, <?= $statusCounts['Selesai'] ?>, <?= $statusCounts['Pending'] ?>, <?= $statusCounts['Delayed'] ?>],
            backgroundColor: ['#f59e0b', '#10b981', '#6b7280', '#ef4444'],
            borderWidth: 0,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: '#94a3b8',
                font: {
                  size: 11
                }
              }
            }
          },
          cutout: '60%'
        }
      });

      // Material Distribution Chart
      const materialCtx = document.getElementById('materialChart').getContext('2d');
      const materialChart = new Chart(materialCtx, {
        type: 'bar',
        data: {
          labels: ['AG25', 'AG32', 'AG50'],
          datasets: [{
            label: 'Projects',
            data: [
              <?= $materialData['AG25'] ?? 0 ?>,
              <?= $materialData['AG32'] ?? 0 ?>,
              <?= $materialData['AG50'] ?? 0 ?>
            ],
            backgroundColor: ['#3b82f6', '#8b5cf6', '#06b6d4'],
            borderWidth: 0,
            borderRadius: 4,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#94a3b8'
              }
            },
            x: {
              grid: {
                display: false
              },
              ticks: {
                color: '#94a3b8'
              }
            }
          }
        }
      });
    });
  </script>
</body>

</html>