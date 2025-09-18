<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header('Location: index.php');
  exit;
}

// Konfigurasi dasar
require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Dashboard';

// Muat PON dari database
$ponRecords = fetchAll('SELECT * FROM pon ORDER BY date_pon DESC');

// Hitung statistik
$stats = [
  'jumlah_proyek' => count($ponRecords),
  'total_berat_kg' => array_sum(array_map(fn($r) => (float)($r['berat'] ?? 0) * (int)($r['qty'] ?? 1), $ponRecords)),
  'proyek_selesai' => array_sum(array_map(fn($r) => strcasecmp((string)($r['status'] ?? ''), 'Selesai') === 0 ? 1 : 0, $ponRecords)),
];

// Muat tasks dan hitung progres divisi (rata-rata)
$divNames = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
$tasks = fetchAll('SELECT * FROM tasks');
$divisions = [];
foreach ($divNames as $dn) {
  $rows = array_values(array_filter($tasks, fn($t) => (string)($t['division'] ?? '') === $dn));
  $avg = $rows ? (int)round(array_sum(array_map(fn($r) => (int)($r['progress'] ?? 0), $rows)) / max(1, count($rows))) : 0;
  $divisions[] = ['name' => $dn, 'progress' => $avg];
}

// Ambil 15 PON dengan total berat terbesar untuk chart
$sorted = $ponRecords;
usort($sorted, fn($a, $b) => ((int)($b['berat'] ?? 0) * (int)($b['qty'] ?? 1)) <=> ((int)($a['berat'] ?? 0) * (int)($a['qty'] ?? 1)));
$topWeightPon = array_slice($sorted, 0, 15);
$weights = array_map(fn($r) => (int)($r['berat'] ?? 0) * (int)($r['qty'] ?? 1), $topWeightPon);
$maxWeight = max($weights ?: [1]);

// Riwayat aktivitas
$recentActivities = [];
try {
  $recentTasks = fetchAll('SELECT t.title, t.pon, t.division, t.updated_at, t.status, p.client 
                          FROM tasks t 
                          LEFT JOIN pon p ON t.pon = p.pon 
                          WHERE t.updated_at IS NOT NULL 
                          ORDER BY t.updated_at DESC 
                          LIMIT 8');

  foreach ($recentTasks as $task) {
    $recentActivities[] = [
      'type' => 'task_update',
      'message' => "Task '{$task['title']}' di divisi {$task['division']} diupdate ke status '{$task['status']}'",
      'pon' => $task['pon'],
      'client' => $task['client'] ?? 'N/A',
      'time' => $task['updated_at']
    ];
  }

  $recentPons = fetchAll('SELECT pon, client, status, created_at 
                         FROM pon 
                         ORDER BY created_at DESC 
                         LIMIT 5');

  foreach ($recentPons as $pon) {
    $recentActivities[] = [
      'type' => 'pon_created',
      'message' => "PON '{$pon['pon']}' untuk client {$pon['client']} dibuat",
      'pon' => $pon['pon'],
      'client' => $pon['client'],
      'time' => $pon['created_at']
    ];
  }

  usort($recentActivities, fn($a, $b) => strtotime($b['time']) <=> strtotime($a['time']));
  $recentActivities = array_slice($recentActivities, 0, 12);
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

$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();
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
  <link rel="stylesheet" href="assets/css/charts.css?v=<?= file_exists('assets/css/charts.css') ? filemtime('assets/css/charts.css') : time() ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    /* New Dashboard Layout as per Mockup */
    .dashboard-layout {
      display: grid;
      grid-template-areas:
        "pon-table berat-proyek"
        "aktivitas berat-proyek";
      grid-template-columns: 2fr 1fr;
      grid-template-rows: 1fr 1fr;
      gap: 20px;
      height: 600px;
      /* Fixed total height */
      margin-top: 20px;
    }

    @media (max-width: 1200px) {
      .dashboard-layout {
        grid-template-areas:
          "pon-table"
          "aktivitas"
          "berat-proyek";
        grid-template-columns: 1fr;
        grid-template-rows: 250px 250px 300px;
        height: auto;
      }
    }

    /* PON Table Section */
    .pon-section {
      grid-area: pon-table;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .pon-table-scroll {
      flex: 1;
      overflow: auto;
      margin: -8px;
      padding: 8px;
    }

    .pon-table {
      width: 100%;
      min-width: 800px;
      border-collapse: collapse;
      font-size: 12px;
    }

    .pon-table th,
    .pon-table td {
      padding: 8px 10px;
      border-bottom: 1px solid var(--border);
      text-align: left;
      white-space: nowrap;
    }

    .pon-table th {
      background: rgba(255, 255, 255, 0.05);
      font-weight: 600;
      color: var(--muted);
      position: sticky;
      top: 0;
      z-index: 1;
    }

    /* Activity Section */
    .aktivitas-section {
      grid-area: aktivitas;
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
      padding: 6px 0;
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
      grid-area: berat-proyek;
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
      gap: 10px;
    }

    .bar {
      flex-shrink: 0;
      min-height: 35px;
    }

    .bar .meta {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
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
      gap: 6px;
      margin-bottom: 12px;
      color: var(--text);
      font-weight: 600;
      font-size: 14px;
    }

    .section-header .icon {
      color: #93c5fd;
      font-size: 16px;
    }

    /* Enhanced Scrollbars */
    .pon-table-scroll::-webkit-scrollbar,
    .aktivitas-scroll::-webkit-scrollbar,
    .berat-scroll::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    .pon-table-scroll::-webkit-scrollbar-track,
    .aktivitas-scroll::-webkit-scrollbar-track,
    .berat-scroll::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
    }

    .pon-table-scroll::-webkit-scrollbar-thumb,
    .aktivitas-scroll::-webkit-scrollbar-thumb,
    .berat-scroll::-webkit-scrollbar-thumb {
      background: rgba(147, 197, 253, 0.5);
      border-radius: 3px;
    }

    .pon-table-scroll::-webkit-scrollbar-thumb:hover,
    .aktivitas-scroll::-webkit-scrollbar-thumb:hover,
    .berat-scroll::-webkit-scrollbar-thumb:hover {
      background: rgba(147, 197, 253, 0.7);
    }

    .pon-table-scroll::-webkit-scrollbar-corner {
      background: rgba(255, 255, 255, 0.1);
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
        <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php"><span class="icon bi-house"></span> Dashboard</a>
        <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php"><span class="icon bi-journal-text"></span> PON</a>
        <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php"><span class="icon bi-list-check"></span> Task List</a>
        <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php"><span class="icon bi-bar-chart"></span> Progres Divisi</a>
        <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
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
      <div class="grid-3">
        <div class="card">
          <div class="card-body">
            <div class="stat">
              <div>
                <div class="label">Jumlah Proyek</div>
                <div class="value" style="font-size:20px;line-height:1.2"><?= $stats['jumlah_proyek'] ?></div>
              </div>
              <div class="badge">Aktif</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="stat">
              <div>
                <div class="label">Total Berat</div>
                <div class="value" style="font-size:20px;line-height:1.2"><?= h(kg($stats['total_berat_kg'])) ?></div>
              </div>
              <div class="badge b-warn">Kg</div>
            </div>
          </div>
        </div>
        <div class="card">
          <div class="card-body">
            <div class="stat">
              <div>
                <div class="label">Proyek Selesai</div>
                <div class="value" style="font-size:20px;line-height:1.2"><?= $stats['proyek_selesai'] ?></div>
              </div>
              <div class="badge b-ok">Selesai</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Progress Divisi Section -->
      <section class="section">
        <div class="hd">Progres Divisi</div>
        <div class="bd">
          <div class="prog-grid">
            <?php foreach ($divisions as $div): ?>
              <div class="prog-item">
                <div class="ring" style="--val: <?= (int)$div['progress'] ?>;">
                  <span><?= (int)$div['progress'] ?>%</span>
                </div>
                <div style="text-align:center; font-weight:700; color:#93c5fd; margin-top:8px;"><?= h($div['name']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <!-- New Layout: PON Table, Activity, Weight Chart -->
      <div class="dashboard-layout">
        <!-- PON Table Section -->
        <div class="pon-section">
          <div class="section-header">
            <i class="bi bi-table icon"></i>
            <span>Daftar PON</span>
          </div>
          <div class="pon-table-scroll">
            <table class="pon-table">
              <thead>
                <tr>
                  <th>No</th>
                  <th>PON</th>
                  <th>Type</th>
                  <th>Type Pekerjaan</th>
                  <th>Berat</th>
                  <th>QTY</th>
                  <th>Total</th>
                  <th>Progres</th>
                  <th>Status</th>
                  <th>PIC</th>
                  <th>Owner</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($ponRecords)): ?>
                  <tr>
                    <td colspan="11" style="text-align: center; color: var(--muted); padding: 20px;">
                      Belum ada data PON
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($ponRecords as $i => $r):
                    $status = strtolower($r['status']);
                    $cls = $status === 'selesai' ? 'b-ok' : ($status === 'pending' ? 'b-danger' : 'b-warn');
                  ?>
                    <tr>
                      <td><?= $i + 1 ?></td>
                      <td><?= h($r['pon']) ?></td>
                      <td><?= h($r['type']) ?></td>
                      <td><?= h($r['job_type'] ?? '-') ?></td>
                      <td><?= h(kg((int)($r['berat'] ?? 0))) ?></td>
                      <td><?= (int)($r['qty'] ?? 1) ?></td>
                      <td><?= h(kg((int)($r['berat'] ?? 0) * (int)($r['qty'] ?? 1))) ?></td>
                      <td><?= h(pct((int)($r['progress'] ?? 0))) ?></td>
                      <td><span class="badge <?= $cls ?>"><?= h(ucfirst($status)) ?></span></td>
                      <td><?= h($r['pic'] ?? '-') ?></td>
                      <td><?= h($r['owner'] ?? '-') ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
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
              <div style="text-align: center; color: var(--muted); padding: 20px;">
                Belum ada aktivitas terbaru
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

        <!-- Weight Chart Section -->
        <div class="berat-section">
          <div class="section-header">
            <i class="bi bi-bar-chart icon"></i>
            <span>Berat Proyek</span>
          </div>
          <div class="berat-scroll">
            <div class="bars">
              <?php foreach ($topWeightPon as $i => $row):
                $totalBerat = (int)($row['berat'] ?? 0) * (int)($row['qty'] ?? 1);
                $w = $maxWeight > 0 ? (int)round(($totalBerat / $maxWeight) * 100) : 0;
              ?>
                <div class="bar">
                  <div class="meta">
                    <span>#<?= $i + 1 ?> <?= h((string)($row['pon'] ?? '-')) ?></span>
                    <span><?= h(kg($totalBerat)) ?></span>
                  </div>
                  <div class="track">
                    <div class="fill" style="width:<?= $w ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>

              <?php if (empty($topWeightPon)): ?>
                <div style="text-align: center; color: var(--muted); padding: 40px 0;">
                  Belum ada data berat proyek
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Dibangun cepat dengan PHP</footer>
  </div>

  <script>
    // Jam server (berjalan) WIB
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

    // Animasi cincin progres
    (function() {
      const rings = document.querySelectorAll('.ring');
      rings.forEach(el => {
        const target = Number(el.style.getPropertyValue('--val')) || 0;
        let cur = 0;
        const step = Math.max(1, Math.round(target / 24));
        const timer = setInterval(() => {
          cur += step;
          if (cur >= target) {
            cur = target;
            clearInterval(timer);
          }
          el.style.setProperty('--val', String(cur));
          const label = el.querySelector('span');
          if (label) label.textContent = cur + '%';
        }, 24);
      });
    })();
  </script>
</body>

</html>