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
$divNames = ['Engineering','Logistik','Pabrikasi','Purchasing'];
$tasks = fetchAll('SELECT * FROM tasks');
$divisions = [];
foreach ($divNames as $dn) {
  $rows = array_values(array_filter($tasks, fn($t) => (string)($t['division'] ?? '') === $dn));
  $avg = $rows ? (int)round(array_sum(array_map(fn($r) => (int)($r['progress'] ?? 0), $rows)) / max(1, count($rows))) : 0;
  $divisions[] = ['name' => $dn, 'progress' => $avg];
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
      <div class="title"><?= h($activeMenu) ?></div>
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

      <section class="section" style="display:flex; gap:24px; flex-wrap:wrap;">
        <div style="flex:2; min-width:320px;">
          <section class="section">
            <div class="hd">Daftar PON</div>
            <div class="bd">
              <table>
                <thead>
                  <tr>
                    <th>No</th>
                    <th>PON</th>
                    <th>Type</th>
                    <th>Type Pekerjaan</th>
                    <th>Berat Satuan</th>
                    <th>QTY</th>
                    <th>Total Berat</th>
                    <th>Progres</th>
                    <th>Status</th>
                    <th>Alamat Kontrak</th>
                    <th>No Contract</th>
                    <th>PIC</th>
                    <th>Owner</th>
                  </tr>
                </thead>
                <tbody>
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
                    <td><?= h($r['alamat_kontrak'] ?? '-') ?></td>
                    <td><?= h($r['no_contract'] ?? '-') ?></td>
                    <td><?= h($r['pic'] ?? '-') ?></td>
                    <td><?= h($r['owner'] ?? '-') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div style="flex:1; min-width:320px;">
          <div class="hd">Berat Proyek</div>
          <div class="bd">
            <?php
              // Ambil 6 PON dengan total berat terbesar
              $sorted = $ponRecords;
              usort($sorted, fn($a,$b) => ((int)($b['berat'] ?? 0) * (int)($b['qty'] ?? 1)) <=> ((int)($a['berat'] ?? 0) * (int)($a['qty'] ?? 1)));
              $top = array_slice($sorted, 0, 6);
              $weights = array_map(fn($r) => (int)($r['berat'] ?? 0) * (int)($r['qty'] ?? 1), $top);
              $max = max($weights ?: [1]);
            ?>
            <div class="bars">
              <?php foreach ($top as $i => $row): $totalBerat = (int)($row['berat'] ?? 0) * (int)($row['qty'] ?? 1); $w = (int)round(($totalBerat / $max)*100); ?>
                <div class="bar">
                  <div class="meta"><span>#<?= $i+1 ?> <?= h((string)($row['pon'] ?? '-')) ?><?php if(!empty($row['type'])): ?> (<?= h((string)$row['type']) ?>)<?php endif; ?></span><span><?= h(kg($totalBerat)) ?></span></div>
                  <div class="track"><div class="fill" style="width:<?= $w ?>%"></div></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Dibangun cepat dengan PHP</footer>
  </div>

  <script>
    // Jam server (berjalan) WIB
    (function(){
      const el = document.getElementById('clock');
      if(!el) return;
      const tz = 'Asia/Jakarta';
      let now = new Date(Number(el.dataset.epoch) * 1000);
      function tick(){
        now = new Date(now.getTime() + 1000);
        el.textContent = now.toLocaleString('id-ID', { timeZone: tz, hour12: false });
      }
      tick();
      setInterval(tick, 1000);
    })();

    // Animasi cincin progres
    (function(){
      const rings = document.querySelectorAll('.ring');
      rings.forEach(el => {
        const target = Number(el.style.getPropertyValue('--val')) || 0;
        let cur = 0;
        const step = Math.max(1, Math.round(target / 24));
        const timer = setInterval(() => {
          cur += step;
          if(cur >= target){ cur = target; clearInterval(timer); }
          el.style.setProperty('--val', String(cur));
          const label = el.querySelector('span');
          if(label) label.textContent = cur + '%';
        }, 24);
      });
    })();
  </script>
</body>
</html>
