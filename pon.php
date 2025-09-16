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

// Muat PON dari database
$pon = fetchAll('SELECT * FROM pon ORDER BY date_pon DESC');

// Hitung ringkas
$totalBerat = array_sum(array_map(fn($r) => (float) $r['berat'] * (int) ($r['qty'] ?? 1), $pon));
$avgProgress = (int) round(array_sum(array_map(fn($r) => (int) $r['progress'], $pon)) / max(1, count($pon)));
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
  <link rel="stylesheet"
    href="assets/css/charts.css?v=<?= file_exists('assets/css/charts.css') ? filemtime('assets/css/charts.css') : time() ?>">
  <style>
    .table-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px
    }

    .input {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 8px 10px;
      border-radius: 8px;
      min-width: 200px
    }

    .pill {
      display: inline-block;
      padding: 6px 10px;
      border: 1px solid var(--border);
      border-radius: 999px;
      color: var(--muted);
      text-decoration: none
    }

    .pill:hover {
      background: rgba(255, 255, 255, .04)
    }

    .notice {
      background: rgba(34, 197, 94, .12);
      color: #86efac;
      border: 1px solid rgba(34, 197, 94, .35);
      padding: 10px;
      border-radius: 10px;
      margin-bottom: 10px
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

    .btn-danger {
      background: #dc2626;
      border-color: #ef4444
    }

    .btn-danger:hover {
      background: #b91c1c
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
        <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
      </nav>
    </aside>

    <!-- Header -->
    <header class="header">
      <div class="title">PON</div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <div class="grid-3">
        <div class="card">
          <div class="card-body stat">
            <div>
              <div class="label">Total PON</div>
              <div class="value"><?= count($pon) ?></div>
            </div><span class="badge">Aktif</span>
          </div>
        </div>
        <div class="card">
          <div class="card-body stat">
            <div>
              <div class="label">Total Berat</div>
              <div class="value"><?= h(kg($totalBerat)) ?></div>
            </div><span class="badge">Aggregate</span>
          </div>
        </div>
        <div class="card">
          <div class="card-body stat">
            <div>
              <div class="label">Rata-rata Progres</div>
              <div class="value"><?= $avgProgress ?>%</div>
            </div><span class="badge b-ok">OK</span>
          </div>
        </div>
      </div>

      <section class="section" style="margin-top:16px">
        <div class="hd">DAFTAR PROYEK</div>
        <div class="bd">
          <?php if (isset($_GET['added'])): ?>
            <div class="notice">PON baru berhasil ditambahkan.</div>
          <?php endif; ?>
          <div class="table-actions">
            <input class="input" id="q" type="search" placeholder="Cari PON/Type/Status..." oninput="filterTable()">
            <div style="display:flex;gap:8px;align-items:center">
              <a class="pill" href="#">Export CSV</a>
              <a class="pill" href="pon_new.php">+ Tambah PON</a>
            </div>
          </div>
          <div style="overflow:auto">
  <table id="ponTable">
    <thead>
      <tr>
        <th>No</th>
        <th>PON</th>
        <th>Client</th>
        <th>Type</th>
        <th>Type Pekerjaan</th>
        <th>QTY</th>
        <th>Berat Satuan</th>
        <th>Total Berat</th>
        <th>Progres</th>
        <th>PIC</th>
        <th>Owner</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($pon as $i => $r):
        $status = strtolower($r['status']);
        $cls = $status === 'selesai' ? 'b-ok' : ($status === 'pending' ? 'b-danger' : 'b-warn');
        ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= h($r['pon']) ?></td>
          <td><?= h($r['client'] ?? '-') ?></td>
          <td><?= h($r['type']) ?></td>
          <td><?= h($r['job_type'] ?? '-') ?></td>
          <td><?= h((int) $r['qty']) ?></td>
          <td><?= h(kg((int) $r['berat'])) ?></td>
          <td><?= h(kg((int) $r['berat'] * (int) $r['qty'])) ?></td>
          <td><?= h(pct((int) $r['progress'])) ?></td>
      <?php endforeach; ?>
    </tbody>
  </table>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • PON</footer>
  </div>

  <script>
    (function () {
      const el = document.getElementById('clock');
      if (!el) return;
      const tz = 'Asia/Jakarta';
      let now = new Date(Number(el.dataset.epoch) * 1000);
      function tick() {
        now = new Date(now.getTime() + 1000);
        el.textContent = now.toLocaleString('id-ID', { timeZone: tz, hour12: false });
      }
      tick();
      setInterval(tick, 1000);
    })();

    function filterTable() {
      const q = (document.getElementById('q').value || '').toLowerCase();
      const rows = document.querySelectorAll('#ponTable tbody tr');
      rows.forEach(tr => {
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
      });
    }
  </script>
<?php
// Handle delete request
if (isset($_GET['delete'])) {
  $delPon = $_GET['delete'];
  delete('pon', 'pon = :pon', ['pon' => $delPon]);
  header('Location: pon.php');
  exit;
}
?>

</body>
</html>
