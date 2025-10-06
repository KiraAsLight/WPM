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

$errors = [];
$old = [
  'job_no' => '',
  'project_name' => '',
  'client_name' => '',
  'contract_no' => '',
  'contract_date' => '',
  'location' => '',
  'project_start' => '',
  'project_manager' => '',
  'pon_number' => '',
  'pon_date' => '',
  'subject' => '',
  'material_type' => '',
  'quantity' => '',
  'status' => 'Progress',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $old['job_no'] = trim($_POST['job_no'] ?? '');
  $old['project_name'] = trim($_POST['project_name'] ?? '');
  $old['client_name'] = trim($_POST['client_name'] ?? '');
  $old['contract_no'] = trim($_POST['contract_no'] ?? '');
  $old['contract_date'] = trim($_POST['contract_date'] ?? '');
  $old['location'] = trim($_POST['location'] ?? '');
  $old['project_start'] = trim($_POST['project_start'] ?? '');
  $old['project_manager'] = trim($_POST['project_manager'] ?? '');
  $old['pon_number'] = trim($_POST['pon_number'] ?? '');
  $old['pon_date'] = trim($_POST['pon_date'] ?? '');
  $old['subject'] = trim($_POST['subject'] ?? '');
  $old['material_type'] = trim($_POST['material_type'] ?? '');
  $old['quantity'] = trim($_POST['quantity'] ?? '');
  $old['status'] = trim($_POST['status'] ?? 'Progress');

  // Validasi
  if ($old['job_no'] === '') {
    $errors['job_no'] = 'Job Number wajib diisi';
  } else {
    // Validasi format Job Number (W-XXX)
    if (!preg_match('/^W-\d+$/', $old['job_no'])) {
      $errors['job_no'] = 'Format Job Number harus W-XXX (contoh: W-713)';
    } else {
      // Cek duplikasi di database
      try {
        $existing = fetchOne('SELECT id FROM pon WHERE job_no = ?', [$old['job_no']]);
        if ($existing) {
          $errors['job_no'] = 'Job Number sudah ada';
        }
      } catch (Exception $e) {
        $errors['job_no'] = 'Error checking Job Number: ' . $e->getMessage();
      }
    }
  }

  if ($old['project_name'] === '') {
    $errors['project_name'] = 'Nama Project wajib diisi';
  }
  if ($old['client_name'] === '') {
    $errors['client_name'] = 'Nama Client wajib diisi';
  }
  if ($old['location'] === '') {
    $errors['location'] = 'Lokasi Project wajib diisi';
  }
  if ($old['project_start'] === '') {
    $errors['project_start'] = 'Project Start Date wajib diisi';
  }
  if ($old['subject'] === '') {
    $errors['subject'] = 'Subject wajib diisi';
  }

  // Validasi Material Type
  $materialTypes = ['AG25', 'AG32', 'AG50'];
  if ($old['material_type'] === '') {
    $errors['material_type'] = 'Jenis Material wajib diisi';
  } elseif (!in_array($old['material_type'], $materialTypes, true)) {
    $errors['material_type'] = 'Jenis Material tidak valid';
  }

  // Validasi Quantity
  if ($old['quantity'] === '' || !is_numeric($old['quantity']) || (int) $old['quantity'] <= 0) {
    $errors['quantity'] = 'Quantity harus angka > 0';
  }

  // Validasi Status
  $statuses = ['Selesai', 'Progress', 'Pending', 'Delayed'];
  if (!in_array($old['status'], $statuses, true)) {
    $errors['status'] = 'Status tidak valid';
  }

  // Validasi Tanggal
  $datePonOk = $old['pon_date'] === '' ? true : (bool) strtotime($old['pon_date']);
  $dateStartOk = $old['project_start'] === '' ? false : (bool) strtotime($old['project_start']);
  $dateContractOk = $old['contract_date'] === '' ? true : (bool) strtotime($old['contract_date']);

  if (!$datePonOk) {
    $errors['pon_date'] = 'Tanggal PON tidak valid';
  }
  if (!$dateStartOk) {
    $errors['project_start'] = 'Tanggal Project Start tidak valid atau wajib diisi';
  }
  if (!$dateContractOk) {
    $errors['contract_date'] = 'Tanggal Kontrak tidak valid';
  }

  // Simpan ke database jika valid
  if (!$errors) {
    try {
      // Data untuk insert - mapping ke struktur database
      $data = [
        'job_no' => $old['job_no'],
        'pon' => $old['pon_number'], // 'pon' di database = 'pon_number' di form
        'type' => 'Default', // Default value untuk kolom yang tidak ada di form baru
        'client' => $old['client_name'],
        'nama_proyek' => $old['project_name'],
        'project_manager' => $old['project_manager'],
        'job_type' => 'pengadaan', // Default value
        'berat' => 0.00, // Default value
        'qty' => (int) $old['quantity'],
        'progress' => 0,
        'fabrikasi_imported' => 0,
        'logistik_imported' => 0,
        'date_pon' => $old['pon_date'] ? date('Y-m-d', strtotime($old['pon_date'])) : null,
        'date_finish' => null, // Tidak ada di form baru
        'status' => $old['status'],
        'alamat_kontrak' => $old['location'], // Menggunakan location untuk alamat_kontrak
        'no_contract' => $old['contract_no'],
        'pic' => '', // Tidak ada di form baru
        'owner' => '', // Tidak ada di form baru
        'contract_date' => $old['contract_date'] ? date('Y-m-d', strtotime($old['contract_date'])) : null,
        'project_start' => date('Y-m-d', strtotime($old['project_start'])),
        'subject' => $old['subject'],
        'material_type' => $old['material_type'],
      ];

      $insertedId = insert('pon', $data);

      // Create default tasks for each division (tetap sama)
      $divisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
      foreach ($divisions as $division) {
        $taskData = [
          'pon' => $old['pon_number'],
          'division' => $division,
          'title' => 'Task awal - ' . $division,
          'assignee' => '',
          'priority' => 'Medium',
          'progress' => 0,
          'status' => 'To Do',
          'start_date' => date('Y-m-d', strtotime($old['project_start'])),
          'due_date' => null,
        ];
        insert('tasks', $taskData);
      }

      header('Location: pon.php?added=1');
      exit;
    } catch (Exception $e) {
      $errors['general'] = 'Gagal menyimpan data: ' . $e->getMessage();
      error_log('PON Insert Error: ' . $e->getMessage());
    }
  }
}
?>

<!-- BAGIAN HTML FORM - Diubah sesuai field baru -->
<!DOCTYPE html>
<html lang="id">

<head>
  <!-- Head section tetap sama -->
</head>

<body>
  <div class="layout">
    <!-- Sidebar dan Header tetap sama -->

    <!-- Content -->
    <main class="content">
      <section class="section">
        <div class="hd">Form PON Baru</div>
        <div class="bd">
          <?php if (isset($errors['general'])): ?>
            <div class="general-error"><?= h($errors['general']) ?></div>
          <?php endif; ?>

          <form method="post" class="form" autocomplete="off">
            <!-- Informasi Project -->
            <h4 style="grid-column:1/-1; margin:20px 0 10px 0; color:var(--text)">Informasi Project</h4>

            <div class="field">
              <label class="label" for="job_no">Job Number *</label>
              <input class="input" id="job_no" name="job_no" value="<?= h($old['job_no']) ?>"
                placeholder="W-713" pattern="W-\d+" required>
              <?php if (isset($errors['job_no'])): ?>
                <div class="error"><?= h($errors['job_no']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="project_name">Nama Project *</label>
              <input class="input" id="project_name" name="project_name" value="<?= h($old['project_name']) ?>"
                placeholder="Penggantian Jembatan Paramasan Bawah I Cs." required>
              <?php if (isset($errors['project_name'])): ?>
                <div class="error"><?= h($errors['project_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="client_name">Nama Client *</label>
              <input class="input" id="client_name" name="client_name" value="<?= h($old['client_name']) ?>"
                placeholder="PT. PANDJI PRATAMA INDONESIA" required>
              <?php if (isset($errors['client_name'])): ?>
                <div class="error"><?= h($errors['client_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="contract_no">Nomor Kontrak</label>
              <input class="input" id="contract_no" name="contract_no" value="<?= h($old['contract_no']) ?>"
                placeholder="PO.037/GB/LOG-PPI/VII/2025">
              <?php if (isset($errors['contract_no'])): ?>
                <div class="error"><?= h($errors['contract_no']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="contract_date">Tanggal Kontrak</label>
              <input class="input" id="contract_date" name="contract_date" type="date"
                value="<?= h($old['contract_date']) ?>">
              <?php if (isset($errors['contract_date'])): ?>
                <div class="error"><?= h($errors['contract_date']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="location">Lokasi Project *</label>
              <input class="input" id="location" name="location" value="<?= h($old['location']) ?>"
                placeholder="Paramasan, KALSEL" required>
              <?php if (isset($errors['location'])): ?>
                <div class="error"><?= h($errors['location']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="project_start">Project Start Date *</label>
              <input class="input" id="project_start" name="project_start" type="date"
                value="<?= h($old['project_start']) ?>" required>
              <?php if (isset($errors['project_start'])): ?>
                <div class="error"><?= h($errors['project_start']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="project_manager">Project Manager</label>
              <input class="input" id="project_manager" name="project_manager" value="<?= h($old['project_manager']) ?>"
                placeholder="M. YUSUF">
              <?php if (isset($errors['project_manager'])): ?>
                <div class="error"><?= h($errors['project_manager']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="pon_number">Nomor PON</label>
              <input class="input" id="pon_number" name="pon_number" value="<?= h($old['pon_number']) ?>"
                placeholder="Auto-generate atau input manual">
              <?php if (isset($errors['pon_number'])): ?>
                <div class="error"><?= h($errors['pon_number']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="pon_date">Tanggal PON</label>
              <input class="input" id="pon_date" name="pon_date" type="date"
                value="<?= h($old['pon_date']) ?>">
              <?php if (isset($errors['pon_date'])): ?>
                <div class="error"><?= h($errors['pon_date']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field" style="grid-column:1/-1">
              <label class="label" for="subject">Subject *</label>
              <input class="input" id="subject" name="subject" value="<?= h($old['subject']) ?>"
                placeholder="2xAG25 Paramasan, KALSEL - TERMASUK BONDEK" required>
              <?php if (isset($errors['subject'])): ?>
                <div class="error"><?= h($errors['subject']) ?></div>
              <?php endif; ?>
            </div>

            <!-- Technical Specifications -->
            <h4 style="grid-column:1/-1; margin:20px 0 10px 0; color:var(--text)">Technical Specifications</h4>

            <div class="field">
              <label class="label" for="material_type">Jenis Material *</label>
              <select class="select" id="material_type" name="material_type" required>
                <option value="">Pilih Material</option>
                <option value="AG25" <?= $old['material_type'] === 'AG25' ? 'selected' : '' ?>>AG25</option>
                <option value="AG32" <?= $old['material_type'] === 'AG32' ? 'selected' : '' ?>>AG32</option>
                <option value="AG50" <?= $old['material_type'] === 'AG50' ? 'selected' : '' ?>>AG50</option>
              </select>
              <?php if (isset($errors['material_type'])): ?>
                <div class="error"><?= h($errors['material_type']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="quantity">Quantity *</label>
              <input class="input" id="quantity" name="quantity" type="number" min="1"
                value="<?= h($old['quantity']) ?>" required>
              <?php if (isset($errors['quantity'])): ?>
                <div class="error"><?= h($errors['quantity']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="status">Status</label>
              <select class="select" id="status" name="status">
                <?php foreach (['Selesai', 'Progress', 'Pending', 'Delayed'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>><?= h($st) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['status'])): ?>
                <div class="error"><?= h($errors['status']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field" style="grid-column:1/-1">
              <div class="actions">
                <button type="submit" class="btn">Simpan</button>
                <a class="btn secondary" href="pon.php">Batal</a>
              </div>
            </div>
          </form>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah PON</footer>
  </div>

  <script>
    // Auto-set today's date for PON Date
    document.getElementById('pon_date').valueAsDate = new Date();
  </script>
</body>

</html>