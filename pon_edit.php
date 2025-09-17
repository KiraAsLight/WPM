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

// Load PON data dari database
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';
$ponRecord = null;

if ($ponCode) {
  try {
    $ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
  } catch (Exception $e) {
    error_log('Error fetching PON: ' . $e->getMessage());
  }
}

if (!$ponRecord) {
  header('Location: pon.php?error=notfound');
  exit;
}

$errors = [];
$old = [
  'pon' => (string)($ponRecord['pon'] ?? ''),
  'type' => (string)($ponRecord['type'] ?? ''),
  'client' => (string)($ponRecord['client'] ?? ''),
  'nama_proyek' => (string)($ponRecord['nama_proyek'] ?? ''),
  'job_type' => (string)($ponRecord['job_type'] ?? ''),
  'berat' => (string)($ponRecord['berat'] ?? ''),
  'qty' => (string)($ponRecord['qty'] ?? ''),
  'date_pon' => (string)($ponRecord['date_pon'] ?? ''),
  'date_finish' => (string)($ponRecord['date_finish'] ?? ''),
  'status' => (string)($ponRecord['status'] ?? 'Progres'),
  'alamat_kontrak' => (string)($ponRecord['alamat_kontrak'] ?? ''),
  'no_contract' => (string)($ponRecord['no_contract'] ?? ''),
  'pic' => (string)($ponRecord['pic'] ?? ''),
  'owner' => (string)($ponRecord['owner'] ?? ''),
];

// Proses submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $old['pon'] = trim($_POST['pon'] ?? '');
  $old['type'] = trim($_POST['type'] ?? '');
  $old['client'] = trim($_POST['client'] ?? '');
  $old['nama_proyek'] = trim($_POST['nama_proyek'] ?? '');
  $old['job_type'] = trim($_POST['job_type'] ?? '');
  $old['berat'] = trim($_POST['berat'] ?? '');
  $old['qty'] = trim($_POST['qty'] ?? '');
  $old['date_pon'] = trim($_POST['date_pon'] ?? '');
  $old['date_finish'] = trim($_POST['date_finish'] ?? '');
  $old['status'] = trim($_POST['status'] ?? 'Progres');
  $old['alamat_kontrak'] = trim($_POST['alamat_kontrak'] ?? '');
  $old['no_contract'] = trim($_POST['no_contract'] ?? '');
  $old['pic'] = trim($_POST['pic'] ?? '');
  $old['owner'] = trim($_POST['owner'] ?? '');

  // Validasi
  if ($old['pon'] === '') {
    $errors['pon'] = 'Kode PON wajib diisi';
  } else {
    // Cek duplikasi PON (kecuali PON yang sedang diedit)
    try {
      $existing = fetchOne('SELECT id FROM pon WHERE pon = ? AND id != ?', [$old['pon'], $ponRecord['id']]);
      if ($existing) {
        $errors['pon'] = 'Kode PON sudah ada';
      }
    } catch (Exception $e) {
      $errors['pon'] = 'Error checking PON: ' . $e->getMessage();
    }
  }

  if ($old['type'] === '') $errors['type'] = 'Type wajib diisi';
  if ($old['client'] === '') $errors['client'] = 'Client wajib diisi';
  if ($old['nama_proyek'] === '') $errors['nama_proyek'] = 'Nama Proyek wajib diisi';

  $jobTypes = ['pengadaan', 'pengiriman', 'pemasangan', 'konsultan'];
  if ($old['job_type'] === '') {
    $errors['job_type'] = 'Type pekerjaan wajib diisi';
  } elseif (!in_array($old['job_type'], $jobTypes, true)) {
    $errors['job_type'] = 'Type pekerjaan tidak valid';
  }

  if ($old['berat'] === '' || !is_numeric($old['berat']) || (float) $old['berat'] < 0) {
    $errors['berat'] = 'Berat harus angka >= 0';
  }

  if ($old['qty'] === '' || !is_numeric($old['qty']) || (int) $old['qty'] < 0) {
    $errors['qty'] = 'QTY harus angka >= 0';
  }

  $statuses = ['Selesai', 'Progres', 'Pending', 'Delayed'];
  if (!in_array($old['status'], $statuses, true)) {
    $errors['status'] = 'Status tidak valid';
  }

  $datePonOk = $old['date_pon'] === '' ? false : (bool) strtotime($old['date_pon']);
  $dateFinishOk = $old['date_finish'] === '' ? true : (bool) strtotime($old['date_finish']);
  if (!$datePonOk) $errors['date_pon'] = 'Tanggal PON tidak valid atau wajib diisi';
  if (!$dateFinishOk) $errors['date_finish'] = 'Tanggal Finish tidak valid';

  if ($old['alamat_kontrak'] === '') $errors['alamat_kontrak'] = 'Alamat Kontrak wajib diisi';
  if ($old['no_contract'] === '') $errors['no_contract'] = 'No Contract wajib diisi';
  if ($old['pic'] === '') $errors['pic'] = 'PIC wajib diisi';
  if ($old['owner'] === '') $errors['owner'] = 'Owner wajib diisi';

  // Simpan jika valid
  if (!$errors) {
    try {
      $updateData = [
        'pon' => $old['pon'],
        'type' => $old['type'],
        'client' => $old['client'],
        'nama_proyek' => $old['nama_proyek'],
        'job_type' => $old['job_type'],
        'berat' => (float) $old['berat'],
        'qty' => (int) $old['qty'],
        'date_pon' => date('Y-m-d', strtotime($old['date_pon'])),
        'date_finish' => $old['date_finish'] ? date('Y-m-d', strtotime($old['date_finish'])) : null,
        'status' => $old['status'],
        'alamat_kontrak' => $old['alamat_kontrak'],
        'no_contract' => $old['no_contract'],
        'pic' => $old['pic'],
        'owner' => $old['owner'],
      ];

      $rowsUpdated = update('pon', $updateData, 'id = :id', ['id' => $ponRecord['id']]);

      // Update tasks jika PON code berubah
      if ($old['pon'] !== $ponCode) {
        update('tasks', ['pon' => $old['pon']], 'pon = :old_pon', ['old_pon' => $ponCode]);
      }

      // Auto-update progress based on status
      if (function_exists('updateProgressByStatus')) {
        updateProgressByStatus($old['pon'], $old['status']);
      }

      if ($rowsUpdated > 0) {
        header('Location: pon.php?updated=1');
        exit;
      } else {
        $errors['general'] = 'Tidak ada perubahan data yang disimpan';
      }
    } catch (Exception $e) {
      $errors['general'] = 'Gagal mengupdate data: ' . $e->getMessage();
      error_log('PON Update Error: ' . $e->getMessage());
    }
  }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Edit PON - <?= h($appName) ?></title>
  <link rel="stylesheet"
    href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <style>
    .form {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px
    }

    @media (max-width:900px) {
      .form {
        grid-template-columns: 1fr
      }
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 6px
    }

    .label {
      color: var(--muted);
      font-size: 12px
    }

    .input,
    .select {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px;
      border-radius: 8px
    }

    .actions {
      display: flex;
      gap: 10px;
      margin-top: 10px
    }

    .btn {
      display: inline-block;
      background: #1d4ed8;
      border: 1px solid #3b82f6;
      color: #fff;
      text-decoration: none;
      padding: 10px 14px;
      border-radius: 10px;
      font-weight: 600;
      border: none;
      cursor: pointer;
    }

    .btn:hover {
      background: #1e40af;
    }

    .btn.secondary {
      background: transparent;
      color: #cbd5e1;
      border: 1px solid var(--border);
    }

    .btn.secondary:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .error {
      color: #fecaca;
      font-size: 12px
    }

    .general-error {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #fecaca;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 16px;
    }

    .success {
      background: rgba(34, 197, 94, .12);
      color: #86efac;
      border: 1px solid rgba(34, 197, 94, .35);
      padding: 10px;
      border-radius: 10px;
      margin-bottom: 10px
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
      <div class="title">Edit PON: <?= h($ponCode) ?></div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <section class="section">
        <div class="hd">Form Edit PON</div>
        <div class="bd">
          <?php if (isset($errors['general'])): ?>
            <div class="general-error"><?= h($errors['general']) ?></div>
          <?php endif; ?>

          <form method="post" class="form" autocomplete="off">
            <div class="field">
              <label class="label" for="pon">Kode PON</label>
              <input class="input" id="pon" name="pon" value="<?= h($old['pon']) ?>" required>
              <?php if (isset($errors['pon'])): ?>
                <div class="error"><?= h($errors['pon']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="type">Type</label>
              <input class="input" id="type" name="type" value="<?= h($old['type']) ?>" required>
              <?php if (isset($errors['type'])): ?>
                <div class="error"><?= h($errors['type']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="client">Client</label>
              <input class="input" id="client" name="client" value="<?= h($old['client']) ?>" required>
              <?php if (isset($errors['client'])): ?>
                <div class="error"><?= h($errors['client']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="nama_proyek">Nama Proyek</label>
              <input class="input" id="nama_proyek" name="nama_proyek" value="<?= h($old['nama_proyek']) ?>" required>
              <?php if (isset($errors['nama_proyek'])): ?>
                <div class="error"><?= h($errors['nama_proyek']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="job_type">Type Pekerjaan</label>
              <select class="select" id="job_type" name="job_type" required>
                <option value="">Pilih Type Pekerjaan</option>
                <option value="pengadaan" <?= $old['job_type'] === 'pengadaan' ? 'selected' : '' ?>>Pengadaan</option>
                <option value="pengiriman" <?= $old['job_type'] === 'pengiriman' ? 'selected' : '' ?>>Pengiriman</option>
                <option value="pemasangan" <?= $old['job_type'] === 'pemasangan' ? 'selected' : '' ?>>Pemasangan</option>
                <option value="konsultan" <?= $old['job_type'] === 'konsultan' ? 'selected' : '' ?>>Konsultan</option>
              </select>
              <?php if (isset($errors['job_type'])): ?>
                <div class="error"><?= h($errors['job_type']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="berat">Berat (Kg)</label>
              <input class="input" id="berat" name="berat" type="number" step="0.01" min="0"
                value="<?= h($old['berat']) ?>" required>
              <?php if (isset($errors['berat'])): ?>
                <div class="error"><?= h($errors['berat']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="qty">QTY</label>
              <input class="input" id="qty" name="qty" type="number" min="0" value="<?= h($old['qty']) ?>" required>
              <?php if (isset($errors['qty'])): ?>
                <div class="error"><?= h($errors['qty']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="date_pon">Date PON</label>
              <input class="input" id="date_pon" name="date_pon" type="date" value="<?= h($old['date_pon']) ?>"
                required>
              <?php if (isset($errors['date_pon'])): ?>
                <div class="error"><?= h($errors['date_pon']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="date_finish">Date Finish</label>
              <input class="input" id="date_finish" name="date_finish" type="date"
                value="<?= h($old['date_finish']) ?>">
              <?php if (isset($errors['date_finish'])): ?>
                <div class="error"><?= h($errors['date_finish']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="status">Status</label>
              <select class="select" id="status" name="status">
                <?php foreach (['Selesai', 'Progres', 'Pending', 'Delayed'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['status'])): ?>
                <div class="error"><?= h($errors['status']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="alamat_kontrak">Alamat Kontrak</label>
              <textarea class="input" id="alamat_kontrak" name="alamat_kontrak" rows="3" required><?= h($old['alamat_kontrak']) ?></textarea>
              <?php if (isset($errors['alamat_kontrak'])): ?>
                <div class="error"><?= h($errors['alamat_kontrak']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="no_contract">No Contract</label>
              <input class="input" id="no_contract" name="no_contract" type="text" value="<?= h($old['no_contract']) ?>" required>
              <?php if (isset($errors['no_contract'])): ?>
                <div class="error"><?= h($errors['no_contract']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="pic">PIC</label>
              <input class="input" id="pic" name="pic" type="text" value="<?= h($old['pic']) ?>" required>
              <?php if (isset($errors['pic'])): ?>
                <div class="error"><?= h($errors['pic']) ?></div><?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="owner">Owner</label>
              <input class="input" id="owner" name="owner" type="text" value="<?= h($old['owner']) ?>" required>
              <?php if (isset($errors['owner'])): ?>
                <div class="error"><?= h($errors['owner']) ?></div><?php endif; ?>
            </div>

            <div class="field" style="grid-column:1/-1">
              <div class="actions">
                <button type="submit" class="btn">Update</button>
                <a class="btn secondary" href="pon.php">Batal</a>
              </div>
            </div>
          </form>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Edit PON</footer>
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
  </script>
</body>

</html>