<?php
declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header('Location: index.php');
  exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Progres Divisi';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

$statuses = ['To Do', 'In Progress', 'Review', 'Blocked', 'Done'];

// Muat tasks
$tasks = fetchAll('SELECT * FROM tasks');

// Handle update progres/status per task
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $idx = isset($_POST['idx']) ? (int) $_POST['idx'] : -1;
  $progress = isset($_POST['progress']) ? (int) $_POST['progress'] : 0;
  $status = trim($_POST['status'] ?? '');
  if ($idx >= 0 && $idx < count($tasks)) {
    if ($progress < 0)
      $progress = 0;
    if ($progress > 100)
      $progress = 100;
    if (!in_array($status, $statuses, true))
      $status = $tasks[$idx]['status'] ?? 'To Do';
    $taskId = $tasks[$idx]['id'] ?? null;
    if ($taskId !== null) {
      update('tasks', ['progress' => $progress, 'status' => $status, 'updated_at' => date('Y-m-d H:i')], 'id = :id', ['id' => $taskId]);
      $msg = 'Perubahan tersimpan.';
      // Reload tasks after update
      $tasks = fetchAll('SELECT * FROM tasks');
    } else {
      $msg = 'Task tidak ditemukan.';
    }
  } else {
    $msg = 'Task tidak ditemukan.';
  }
}

// Daftar PON dari tasks
$ponList = array_values(array_unique(array_map(fn($t) => (string) ($t['pon'] ?? ''), $tasks)));
sort($ponList);
$selPon = isset($_GET['pon']) && $_GET['pon'] !== '' ? (string) $_GET['pon'] : ($ponList[0] ?? '');

$divisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];

// Hitung agregasi progres per divisi untuk PON terpilih
$divAgg = [];
foreach ($divisions as $d) {
  $rows = array_values(array_filter($tasks, fn($t) => ($t['pon'] ?? '') === $selPon && ($t['division'] ?? '') === $d));
  if ($rows) {
    $avg = (int) round(array_sum(array_map(fn($r) => (int) ($r['progress'] ?? 0), $rows)) / max(1, count($rows)));
  } else {
    $avg = 0;
  }
  $divAgg[$d] = $avg;
}

// Tasks untuk PON terpilih
$tasksForPon = array_values(array_filter($tasks, fn($t) => ($t['pon'] ?? '') === $selPon));
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Progres Divisi - <?= h($appName) ?></title>
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
      margin-bottom: 12px;
      align-items: center
    }

    .select,
    .input {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 8px 10px;
      border-radius: 8px
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

    .prog-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 10px;
      background: rgba(255, 255, 255, .02);
      border: 1px dashed var(--border);
      border-radius: 12px;
      padding: 16px
    }

    .notice {
      background: rgba(34, 197, 94, .12);
      color: #86efac;
      border: 1px solid rgba(34, 197, 94, .35);
      padding: 10px;
      border-radius: 10px;
      margin-bottom: 10px
    }

    .muted {
      color: var(--muted)
    }

    .inline-form {
      display: flex;
      gap: 8px;
      align-items: center
    }

    .btn {
      display: inline-block;
      background: #1d4ed8;
      border: 1px solid #3b82f6;
      color: #fff;
      text-decoration: none;
      padding: 8px 12px;
      border-radius: 8px;
      font-weight: 600
    }

    .btn.secondary {
      background: transparent;
      color: #cbd5e1
    }

    .input-num {
      width: 80px
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
        <a class="<?= $activeMenu==='Dashboard'?'active':'' ?>" href="dashboard.php"><span class="icon bi-house"></span> Dashboard</a>
        <a class="<?= $activeMenu==='PON'?'active':'' ?>" href="pon.php"><span class="icon bi-journal-text"></span> PON</a>
        <a class="<?= $activeMenu==='Task List'?'active':'' ?>" href="tasklist.php"><span class="icon bi-list-check"></span> Task List</a>
        <a class="<?= $activeMenu==='Progres Divisi'?'active':'' ?>" href="progres_divisi.php"><span class="icon bi-bar-chart"></span> Progres Divisi</a>
      </nav>
    </aside>

    <!-- Header -->
    <header class="header">
      <div class="title">Progres Divisi</div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <section class="section">
        <div class="hd">Ringkasan Progres</div>
        <div class="bd">
          <div class="filterbar">
            <label class="muted">Pilih PON</label>
            <select class="select" id="selPon"
              onchange="location.href='progres_divisi.php?pon='+encodeURIComponent(this.value)">
              <?php if (!$ponList): ?>
                <option value="">(Belum ada PON)</option>
              <?php else:
                foreach ($ponList as $p): ?>
                  <option value="<?= h($p) ?>" <?= $p === $selPon ? 'selected' : '' ?>><?= strtoupper(h($p)) ?></option>
                <?php endforeach; endif; ?>
            </select>
            <?php if ($msg): ?><span class="notice"><?= h($msg) ?></span><?php endif; ?>
          </div>

          <?php if ($selPon): ?>
            <div class="grid-4">
              <?php foreach ($divisions as $d):
                $v = (int) ($divAgg[$d] ?? 0); ?>
                <div class="prog-item">
                <div class="ring" style="--val: <?= $v ?>">
                  <span style="position:absolute;top:50%;left:50%;transform:translate(-50%, -50%);font-weight:bold;color:#fff;"><?= $v ?>%</span>
                </div>
                  <div><?= h($d) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="muted">Belum ada data PON. Tambahkan PON dan task terlebih dahulu.</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="section" style="margin-top:16px">
        <div class="hd">Detail Tugas PON <?= $selPon ? strtoupper(h($selPon)) : '' ?></div>
        <div class="bd">
          <?php if (!$tasksForPon): ?>
            <div class="muted">Tidak ada task untuk PON ini.</div>
          <?php else: ?>
            <div style="overflow:auto">
              <table>
                <thead>
                  <tr>
                    <th>Task</th>
                    <th>Divisi</th>
                    <th>Progres</th>
                    <th>Status</th>
                    <th>Update</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($tasksForPon as $i => $t):
                    $idx = array_search($t, $tasks, true); ?>
                    <tr>
                      <td><?= h((string) ($t['title'] ?? '')) ?></td>
                      <td><?= h((string) ($t['division'] ?? '')) ?></td>
                      <td>
                        <form method="post" class="inline-form" action="progres_divisi.php?pon=<?= urlencode($selPon) ?>">
                          <input type="hidden" name="idx" value="<?= (int) $idx ?>">
                          <input class="input input-num" name="progress" type="number" min="0" max="100"
                            value="<?= (int) ($t['progress'] ?? 0) ?>">
                          <select class="select" name="status">
                            <?php foreach ($statuses as $st): ?>
                              <option value="<?= h($st) ?>" <?= ((string) ($t['status'] ?? '')) === $st ? 'selected' : '' ?>>
                                <?= h($st) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn" type="submit">Simpan</button>
                        </form>
                      </td>
                      <td><span class="muted"><?= h((string) ($t['status'] ?? '')) ?></span></td>
                      <td class="muted">Terakhir: <?= h((string) ($t['updated_at'] ?? '-')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Progres Divisi</footer>
  </div>

  <script>
    // Jam server (berjalan) WIB
    (function () {
      const el = document.getElementById('clock');
      if (!el) return;
      const tz = 'Asia/Jakarta';
      let now = new Date(Number(el.dataset.epoch) * 1000);
      function tick() { now = new Date(now.getTime() + 1000); el.textContent = now.toLocaleString('id-ID', { timeZone: tz, hour12: false }); }
      tick(); setInterval(tick, 1000);
    })();

    // Animasi cincin progres sederhana
    (function () {
      const rings = document.querySelectorAll('.ring');
      rings.forEach(el => {
        const target = Number(getComputedStyle(el).getPropertyValue('--val')) || 0;
        let cur = 0; const step = Math.max(1, Math.round(target / 24));
        const tm = setInterval(() => { cur += step; if (cur >= target) { cur = target; clearInterval(tm); } el.style.setProperty('--val', String(cur)); el.querySelector('span').textContent = cur + '%'; }, 24);
      });
    })();
  </script>
</body>

</html>