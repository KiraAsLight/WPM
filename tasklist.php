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

// Muat PON dari database
$ponRecords = fetchAll('SELECT * FROM pon ORDER BY date_pon DESC');

// Muat semua tasks dari database
$allTasks = fetchAll('SELECT * FROM tasks');

// Hitung statistik task
$totalTasks = count($allTasks);
$completedTasks = count(array_filter($allTasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));
$avgProgress = $totalTasks > 0 ? (int)round(array_sum(array_map(fn($t) => (int)($t['progress'] ?? 0), $allTasks)) / $totalTasks) : 0;

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

// Handle search/filter
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
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

// Redirect ke divisi jika ada PON yang dipilih
$selPon = isset($_GET['pon']) ? (string) $_GET['pon'] : '';
$selDiv = isset($_GET['div']) ? (string) $_GET['div'] : '';

if ($selPon) {
  // Jika sudah pilih PON tapi belum pilih divisi, redirect ke halaman divisi
  if (!$selDiv) {
    header('Location: task_divisions.php?pon=' . urlencode($selPon));
    exit;
  }
  // Jika sudah pilih PON dan divisi, redirect ke halaman task detail
  header('Location: task_detail.php?pon=' . urlencode($selPon) . '&div=' . urlencode($selDiv));
  exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Task List - <?= h($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet"
    href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
  <style>
    /* Task List New Layout Styles */

    /* Statistics Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 20px;
      margin-bottom: 30px;
    }

    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }

    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    .stat-value {
      font-size: 32px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 8px;
    }

    .stat-label {
      font-size: 14px;
      color: var(--muted);
      font-weight: 500;
    }

    /* Pie Chart for Progress Distribution */
    .progress-chart {
      position: relative;
      width: 120px;
      height: 120px;
      margin: 0 auto 15px;
    }

    .pie-chart {
      width: 120px;
      height: 120px;
    }

    .chart-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: center;
      margin-top: 10px;
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 11px;
      color: var(--muted);
    }

    .legend-color {
      width: 12px;
      height: 12px;
      border-radius: 2px;
    }

    /* PON Table Section */
    .pon-section {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      height: 500px;
      display: flex;
      flex-direction: column;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
    }

    .section-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .search-container {
      position: relative;
      width: 300px;
    }

    .search-input {
      width: 100%;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 15px 10px 40px;
      color: var(--text);
      font-size: 14px;
    }

    .search-input::placeholder {
      color: var(--muted);
    }

    .search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--muted);
      font-size: 16px;
    }

    .pon-table-container {
      flex: 1;
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 8px;
    }

    .pon-table {
      width: 100%;
      min-width: 800px;
      border-collapse: collapse;
    }

    .pon-table thead {
      background: rgba(255, 255, 255, 0.05);
      position: sticky;
      top: 0;
      z-index: 1;
    }

    .pon-table th,
    .pon-table td {
      padding: 12px 15px;
      text-align: left;
      border-bottom: 1px solid var(--border);
      font-size: 13px;
    }

    .pon-table th {
      color: var(--muted);
      font-weight: 600;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .pon-table td {
      color: var(--text);
    }

    .pon-table tbody tr {
      cursor: pointer;
      transition: background-color 0.2s;
    }

    .pon-table tbody tr:hover {
      background: rgba(255, 255, 255, 0.03);
    }

    .status-badge {
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-progres {
      background: rgba(59, 130, 246, 0.2);
      color: #93c5fd;
    }

    .status-selesai {
      background: rgba(34, 197, 94, 0.2);
      color: #86efac;
    }

    .status-pending {
      background: rgba(239, 68, 68, 0.2);
      color: #fca5a5;
    }

    .status-delayed {
      background: rgba(245, 101, 101, 0.2);
      color: #f87171;
    }

    /* Custom Scrollbar */
    .pon-table-container::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    .pon-table-container::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
    }

    .pon-table-container::-webkit-scrollbar-thumb {
      background: rgba(147, 197, 253, 0.5);
      border-radius: 3px;
    }

    .pon-table-container::-webkit-scrollbar-thumb:hover {
      background: rgba(147, 197, 253, 0.7);
    }

    /* Progress Bar in Table */
    .progress-container {
      width: 60px;
      height: 6px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
      overflow: hidden;
    }

    .progress-bar {
      height: 100%;
      background: linear-gradient(90deg, #3b82f6, #06b6d4);
      border-radius: 3px;
      transition: width 0.3s ease;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      color: var(--muted);
      padding: 40px 20px;
      font-size: 14px;
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
      <!-- Statistics Cards -->
      <div class="stats-grid">
        <!-- Total Task -->
        <div class="stat-card">
          <div class="stat-value"><?= $totalTasks ?></div>
          <div class="stat-label">Total Task</div>
        </div>

        <!-- Task Selesai -->
        <div class="stat-card">
          <div class="stat-value"><?= $completedTasks ?></div>
          <div class="stat-label">Task Selesai</div>
        </div>

        <!-- Rata-rata Progress dengan Pie Chart -->
        <div class="stat-card">
          <div class="progress-chart">
            <svg class="pie-chart" viewBox="0 0 42 42">
              <circle cx="21" cy="21" r="15.915" fill="transparent" stroke="rgba(255,255,255,0.1)" stroke-width="3"></circle>
              <circle cx="21" cy="21" r="15.915" fill="transparent"
                stroke="#3b82f6" stroke-width="3"
                stroke-dasharray="<?= $avgProgress ?> 100"
                stroke-dashoffset="25"
                stroke-linecap="round"></circle>
              <text x="21" y="25" text-anchor="middle" fill="var(--text)" font-size="8" font-weight="600">
                <?= $avgProgress ?>%
              </text>
            </svg>
          </div>
          <div class="stat-label">Rata-rata Progress</div>

          <!-- Legend for Bridge Types -->
          <div class="chart-legend">
            <?php
            $colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
            $i = 0;
            foreach ($typeDistribution as $type => $count):
            ?>
              <div class="legend-item">
                <div class="legend-color" style="background: <?= $colors[$i % count($colors)] ?>"></div>
                <span><?= h($type) ?></span>
              </div>
            <?php
              $i++;
            endforeach;
            ?>
          </div>
        </div>
      </div>

      <!-- PON Table Section -->
      <div class="pon-section">
        <div class="section-header">
          <div class="section-title">
            <i class="bi bi-table"></i>
            Daftar PON
          </div>
          <div class="search-container">
            <form method="GET" action="">
              <div style="position: relative;">
                <i class="bi bi-search search-icon"></i>
                <input type="text" name="search" class="search-input"
                  placeholder="Cari PON/Type/Status..."
                  value="<?= h($searchQuery) ?>">
              </div>
            </form>
          </div>
        </div>

        <div class="pon-table-container">
          <table class="pon-table">
            <thead>
              <tr>
                <th>PON</th>
                <th>Client</th>
                <th>Tipe Jembatan</th>
                <th>Tipe Pekerjaan</th>
                <th>Start</th>
                <th>Finish</th>
                <th>Progress</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($filteredPonRecords)): ?>
                <tr>
                  <td colspan="8">
                    <div class="empty-state">
                      <?php if ($searchQuery): ?>
                        Tidak ada PON yang cocok dengan pencarian "<?= h($searchQuery) ?>"
                      <?php else: ?>
                        Belum ada data PON
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($filteredPonRecords as $pon):
                  $progress = (int)($pon['progress'] ?? 0);
                  $status = strtolower($pon['status'] ?? 'progres');
                  $statusClass = 'status-' . str_replace(' ', '-', $status);
                ?>
                  <tr onclick="window.location.href='task_divisions.php?pon=<?= urlencode($pon['pon']) ?>'">
                    <td><strong><?= h($pon['pon']) ?></strong></td>
                    <td><?= h($pon['client'] ?? '-') ?></td>
                    <td><?= h($pon['type'] ?? '-') ?></td>
                    <td><?= h($pon['job_type'] ?? '-') ?></td>
                    <td><?= h(dmy($pon['date_pon'] ?? null)) ?></td>
                    <td><?= h(dmy($pon['date_finish'] ?? null)) ?></td>
                    <td>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="progress-container">
                          <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                        </div>
                        <span style="font-size: 12px; color: var(--muted);"><?= $progress ?>%</span>
                      </div>
                    </td>
                    <td>
                      <span class="status-badge <?= $statusClass ?>"><?= h(ucfirst($status)) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
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

    // Auto-submit search on typing (with debounce)
    let searchTimeout;
    const searchInput = document.querySelector('.search-input');
    if (searchInput) {
      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
          this.form.submit();
        }, 500);
      });
    }

    // Clear search when clicking clear button
    function clearSearch() {
      window.location.href = 'tasklist.php';
    }
  </script>
</body>

</html>