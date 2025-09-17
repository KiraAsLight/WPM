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

// Muat tasks dari database
$tasks = fetchAll('SELECT * FROM tasks');

// Helper ambil tasks untuk PON
function tasks_for_pon(array $tasks, string $pon): array
{
  $out = [];
  foreach ($tasks as $i => $t) {
    if ((string) ($t['pon'] ?? '') === $pon) {
      $t['_idx'] = $i;
      $out[] = $t;
    }
  }
  return $out;
}
// Helper ambil tasks untuk PON+Divisi
function tasks_for_pon_div(array $tasks, string $pon, string $div): array
{
  $out = [];
  foreach ($tasks as $i => $t) {
    if ((string) ($t['pon'] ?? '') === $pon && (string) ($t['division'] ?? '') === $div) {
      $t['_idx'] = $i;
      $out[] = $t;
    }
  }

  return $out;
}
// Agregasi progres divisi untuk PON
function div_progress(array $tasksForPon): array
{
  $divisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
  $agg = [];
  foreach ($divisions as $d) {
    $rows = array_values(array_filter($tasksForPon, fn($t) => (string) ($t['division'] ?? '') === $d));
    $avg = $rows ? (int) round(array_sum(array_map(fn($r) => (int) ($r['progress'] ?? 0), $rows)) / max(1, count($rows))) : 0;
    $agg[$d] = $avg;
  }
  return $agg;
}

// Stage params
$selPon = isset($_GET['pon']) ? (string) $_GET['pon'] : '';
$selDiv = isset($_GET['div']) ? (string) $_GET['div'] : '';

// Update task (progres/status) lalu redirect ke stage yang sama
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $idx = isset($_POST['idx']) ? (int) $_POST['idx'] : -1;
  $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : 0;
  $status = trim($_POST['status'] ?? '');
  $ponParam = trim($_POST['pon'] ?? '');
  $divParam = trim($_POST['div'] ?? '');
  $valid = ['ToDo', 'On Proses', 'Hold', 'Done'];
  if ($idx >= 0 && $idx < count($tasks)) {
    $progress = max(0, min(100, $progress));
    if (!in_array($status, $valid, true))
      $status = (string) ($tasks[$idx]['status'] ?? 'ToDo');
    $taskId = $tasks[$idx]['id'] ?? null;
    if ($taskId !== null) {
      update('tasks', ['progress' => $progress, 'status' => $status, 'updated_at' => date('Y-m-d H:i')], 'id = :id', ['id' => $taskId]);

      // Auto-update PON progress when task changes
      if ($ponParam && function_exists('updatePonProgress')) {
        updatePonProgress($ponParam);
      }
    }
  }
  $to = 'tasklist.php';
  if ($ponParam && $divParam)
    $to .= '?pon=' . urlencode($ponParam) . '&div=' . urlencode($divParam);
  elseif ($ponParam)
    $to .= '?pon=' . urlencode($ponParam);
  header('Location: ' . $to);
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
  <link rel="stylesheet"
    href="assets/css/charts.css?v=<?= file_exists('assets/css/charts.css') ? filemtime('assets/css/charts.css') : time() ?>">
  <style>
    .filterbar {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      margin-bottom: 12px
    }

    .input,
    .select {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 8px 10px;
      border-radius: 8px
    }

    .crumb {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-bottom: 10px;
      color: var(--muted)
    }

    .crumb a {
      color: #93c5fd;
      text-decoration: none
    }

    .crumb .sep {
      opacity: .5
    }

    .grid-4 {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px
    }

    @media (max-width:1100px) {
      .grid-4 {
        grid-template-columns: repeat(2, 1fr)
      }
    }

    .tile {
      border: 1px dashed var(--border);
      border-radius: 12px;
      padding: 16px;
      background: rgba(255, 255, 255, .02);
      cursor: pointer
    }

    .tile:hover {
      background: rgba(255, 255, 255, .04)
    }

    .pon-table {
      width: 100%;
      border-collapse: collapse
    }

    .pon-table th,
    .pon-table td {
      padding: 12px;
      border-bottom: 1px solid var(--border);
      text-align: left
    }

    .btn {
      display: inline-block;
      background: #1d4ed8;
      border: 1px solid #3b82f6;
      color: #fff;
      text-decoration: none;
      padding: 6px 10px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 12px;
      transition: background 0.2s
    }

    .btn:hover {
      background: #1e40af
    }

    .btn-success {
      background: #059669;
      border-color: #10b981
    }

    .btn-success:hover {
      background: #047857
    }

    .btn-danger {
      background: #dc2626;
      border-color: #ef4444
    }

    .btn-danger:hover {
      background: #b91c1c
    }

    .btn.secondary {
      background: transparent;
      color: #cbd5e1
    }

    .inline-form {
      display: flex;
      gap: 8px;
      align-items: center
    }

    .input.sm,
    .select.sm {
      padding: 6px 8px
    }

    .input-num {
      width: 80px
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
    }
  </style>
</head>

<body>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand">
        <div class="logo" aria-hidden="true">
        </div>
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
      <?php if (!$selPon): ?>
        <!-- Stage 1: Daftar PON -->
        <section class="section">
          <div class="hd">Pilih PON</div>
          <div class="bd">
            <div class="filterbar">
              <input id="qPon" class="input" type="search" placeholder="Cari Number PON atau Type" oninput="filterPon()">
              <a class="btn" href="pon_new.php">+ Tambah PON</a>
            </div>
            <div style="overflow:auto">
              <table class="pon-table" id="ponTable">
                <thead>
                  <tr>
                    <th style="width:220px">Number PON</th>
                    <th>Type</th>
                    <th>Type Pekerjaan</th>
                    <th>Date PON</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$ponRecords): ?>
                    <tr>
                      <td colspan="4" class="muted">Belum ada PON.</td>
                    </tr>
                    <?php else:
                    foreach ($ponRecords as $pr):
                      $pon = (string) ($pr['pon'] ?? '-'); ?>
                      <tr>
                        <td><a href="tasklist.php?pon=<?= urlencode($pon) ?>" class="btn"
                            style="padding:6px 10px"><?= strtoupper(h($pon)) ?></a></td>
                        <td><?= h((string) ($pr['type'] ?? '-')) ?></td>
                        <td><?= h((string) ($pr['job_type'] ?? '-')) ?></td>
                        <td><?= h(dmy($pr['date_pon'] ?? null)) ?></td>
                      </tr>
                  <?php endforeach;
                  endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      <?php elseif ($selPon && !$selDiv): ?>
        <!-- Stage 2: Pilih Divisi -->
        <?php $tp = tasks_for_pon($tasks, $selPon);
        $agg = div_progress($tp); ?>
        <section class="section">
          <div class="hd">Pilih Divisi</div>
          <div class="bd">
            <div class="crumb">
              <a href="tasklist.php">PON</a><span class="sep">›</span><strong><?= strtoupper(h($selPon)) ?></strong>
            </div>
            <div class="grid-4" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; position: relative;">
              <?php foreach ($agg as $div => $val): ?>
                <div style="display: flex; flex-direction: column; align-items: center; gap: 8px;">
                  <a class="btn" href="tasklist.php?pon=<?= urlencode($selPon) ?>&div=<?= urlencode($div) ?>"
                    title="Lihat task <?= h($div) ?>" style="display:flex;flex-direction:column;align-items:center;gap:8px;padding:14px 20px;width:140px;text-align:center;background:#1e293b;border-radius:12px;box-shadow:0 4px 8px rgba(0,0,0,0.2);transition:background 0.3s ease;justify-content:center;">
                    <div class="ring" style="--val: <?= (int) $val ?>;width:72px;height:72px;box-shadow: 0 0 8px rgba(59, 130, 246, 0.7);"><span><?= (int) $val ?>%</span>
                    </div>
                    <div style="font-weight:700;letter-spacing:.3px;color:#93c5fd;font-size:16px;"><?= h($div) ?></div>
                  </a>
                  <a class="btn" href="tasklist.php?pon=<?= urlencode($selPon) ?>&div=<?= urlencode($div) ?>"
                    style="padding: 8px 16px; font-size: 14px; font-weight: 600; border-radius: 8px; background-color: #2563eb; color: white; text-decoration: none; box-shadow: 0 2px 6px rgba(37, 99, 235, 0.5); transition: background-color 0.3s ease; width: 140px; text-align: center;"
                    onmouseover="this.style.backgroundColor='#1e40af';"
                    onmouseout="this.style.backgroundColor='#2563eb';">
                    Masuk ke <?= h($div) ?>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>
      <?php else: ?>
        <!-- Stage 3: Task untuk PON + Divisi -->
        <?php $rows = tasks_for_pon_div($tasks, $selPon, $selDiv); ?>
        <section class="section">
          <div class="section-header">
            <div class="hd">Task PON <?= strtoupper(h($selPon)) ?> • <?= h($selDiv) ?></div>
            <a href="task_new.php?pon=<?= urlencode($selPon) ?>&div=<?= urlencode($selDiv) ?>" class="btn btn-success">
              <i class="bi bi-plus"></i> Tambah Task
            </a>
          </div>
          <div class="bd">
            <div class="crumb">
              <a href="tasklist.php">PON</a><span class="sep">›</span>
              <a href="tasklist.php?pon=<?= urlencode($selPon) ?>"><?= strtoupper(h($selPon)) ?></a><span
                class="sep">›</span>
              <strong><?= h($selDiv) ?></strong>
            </div>
            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>PIC</th>
                    <th>Progress</th>
                    <th>Status</th>
                    <th>Start</th>
                    <th>Due</th>
                    <?php if ($selDiv === 'Purchasing'): ?>
                      <th>Vendor</th>
                      <th>No. PO</th>
                    <?php endif; ?>
                    <th>Update</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$rows): ?>
                    <tr>
                      <td colspan="<?= $selDiv === 'Purchasing' ? '10' : '8' ?>" class="muted">Belum ada task untuk divisi ini.</td>
                    </tr>
                    <?php else:
                    foreach ($rows as $r):
                      $idx = (int) $r['_idx']; ?>
                      <tr>
                        <td><?= h((string) ($r['title'] ?? '')) ?></td>
                        <td><?= h((string) ($r['pic'] ?? '-')) ?></td>
                        <td><?= (int) ($r['progress'] ?? 0) ?>%</td>
                        <td><?= h((string) ($r['status'] ?? '')) ?></td>
                        <td><?= h(dmy($r['start_date'] ?? null)) ?></td>
                        <td><?= h(dmy($r['due_date'] ?? null)) ?></td>
                        <?php if ($selDiv === 'Purchasing'): ?>
                          <td><?= h((string) ($r['vendor'] ?? '-')) ?></td>
                          <td><?= h((string) ($r['no_po'] ?? '-')) ?></td>
                        <?php endif; ?>
                        <td>
                          <form method="post" class="inline-form"
                            action="tasklist.php?pon=<?= urlencode($selPon) ?>&div=<?= urlencode($selDiv) ?>">
                            <input type="hidden" name="idx" value="<?= $idx ?>">
                            <input type="hidden" name="pon" value="<?= h($selPon) ?>">
                            <input type="hidden" name="div" value="<?= h($selDiv) ?>">
                            <input class="input input-num sm" name="progress" type="number" min="0" max="100"
                              value="<?= (int) ($r['progress'] ?? 0) ?>">
                            <select class="select sm" name="status">
                              <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done'] as $st): ?>
                                <option value="<?= h($st) ?>" <?= ((string) ($r['status'] ?? '')) === $st ? 'selected' : '' ?>>
                                  <?= h($st) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn" type="submit">Simpan</button>
                          </form>
                        </td>
                        <td>
                          <a href="task_edit.php?id=<?= $r['id'] ?? 0 ?>&pon=<?= urlencode($selPon) ?>&div=<?= urlencode($selDiv) ?>"
                            class="btn" title="Edit Task">
                            <i class="bi bi-pencil"></i>
                          </a>
                        </td>
                      </tr>
                  <?php endforeach;
                  endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Task List</footer>
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

    function filterPon() {
      const q = (document.getElementById('qPon').value || '').toLowerCase();
      const rows = document.querySelectorAll('#ponTable tbody tr');
      rows.forEach(r => {
        const text = r.innerText.toLowerCase();
        r.style.display = (!q || text.includes(q)) ? '' : 'none';
      });
    }
  </script>
</body>

</html>