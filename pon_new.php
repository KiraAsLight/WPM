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
  'pon' => '',
  'client' => '',
  'nama_proyek' => '',
  'project_manager' => '',
  'qty' => '',
  'date_pon' => '',
  'date_finish' => '',
  'alamat_kontrak' => '',
  'no_contract' => '',
  'contract_date' => '',
  'project_start' => '',
  'subject' => '',
  'material_type' => '',
];

// Ambil material types yang sudah ada di database untuk suggestions
$existingMaterials = fetchAll("SELECT DISTINCT material_type FROM pon WHERE material_type IS NOT NULL AND material_type != ''");
$materialSuggestions = array_map(fn($m) => $m['material_type'], $existingMaterials);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $old['job_no'] = trim($_POST['job_no'] ?? '');
  $old['pon'] = trim($_POST['pon'] ?? '');
  $old['client'] = trim($_POST['client'] ?? '');
  $old['nama_proyek'] = trim($_POST['nama_proyek'] ?? '');
  $old['project_manager'] = trim($_POST['project_manager'] ?? '');
  $old['qty'] = trim($_POST['qty'] ?? '');
  $old['date_pon'] = trim($_POST['date_pon'] ?? '');
  $old['date_finish'] = trim($_POST['date_finish'] ?? '');
  $old['alamat_kontrak'] = trim($_POST['alamat_kontrak'] ?? '');
  $old['no_contract'] = trim($_POST['no_contract'] ?? '');
  $old['contract_date'] = trim($_POST['contract_date'] ?? '');
  $old['project_start'] = trim($_POST['project_start'] ?? '');
  $old['subject'] = trim($_POST['subject'] ?? '');
  $old['material_type'] = trim($_POST['material_type'] ?? '');

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

  if ($old['pon'] === '') {
    $errors['pon'] = 'Nomor PON wajib diisi';
  }
  if ($old['client'] === '') {
    $errors['client'] = 'Client wajib diisi';
  }
  if ($old['nama_proyek'] === '') {
    $errors['nama_proyek'] = 'Nama Proyek wajib diisi';
  }
  if ($old['project_manager'] === '') {
    $errors['project_manager'] = 'Project Manager wajib diisi';
  }

  // Validasi Quantity
  if ($old['qty'] === '' || !is_numeric($old['qty']) || (int) $old['qty'] <= 0) {
    $errors['qty'] = 'Quantity harus angka > 0';
  }

  // Validasi Material Type
  if ($old['material_type'] === '') {
    $errors['material_type'] = 'Jenis Material wajib diisi';
  }

  // Validasi Tanggal
  $datePonOk = $old['date_pon'] === '' ? false : (bool) strtotime($old['date_pon']);
  $dateStartOk = $old['project_start'] === '' ? false : (bool) strtotime($old['project_start']);
  $dateFinishOk = $old['date_finish'] === '' ? true : (bool) strtotime($old['date_finish']);
  $dateContractOk = $old['contract_date'] === '' ? true : (bool) strtotime($old['contract_date']);

  if (!$datePonOk) {
    $errors['date_pon'] = 'Tanggal PON tidak valid atau wajib diisi';
  }
  if (!$dateStartOk) {
    $errors['project_start'] = 'Tanggal Project Start tidak valid atau wajib diisi';
  }
  if (!$dateFinishOk) {
    $errors['date_finish'] = 'Tanggal Finish tidak valid';
  }
  if (!$dateContractOk) {
    $errors['contract_date'] = 'Tanggal Kontrak tidak valid';
  }

  if ($old['alamat_kontrak'] === '') {
    $errors['alamat_kontrak'] = 'Alamat Kontrak wajib diisi';
  }
  if ($old['no_contract'] === '') {
    $errors['no_contract'] = 'No Contract wajib diisi';
  }
  if ($old['subject'] === '') {
    $errors['subject'] = 'Subject wajib diisi';
  }

  // Simpan ke database jika valid
  if (!$errors) {
    try {
      // Data untuk insert - progress default 0%, status default 'Progress'
      $data = [
        'job_no' => $old['job_no'],
        'pon' => $old['pon'],
        'client' => $old['client'],
        'nama_proyek' => $old['nama_proyek'],
        'project_manager' => $old['project_manager'],
        'qty' => (int) $old['qty'],
        'progress' => 0, // Default 0%
        'fabrikasi_imported' => 0,
        'logistik_imported' => 0,
        'date_pon' => date('Y-m-d', strtotime($old['date_pon'])),
        'date_finish' => $old['date_finish'] ? date('Y-m-d', strtotime($old['date_finish'])) : null,
        'status' => 'Progress', // Default 'Progress'
        'alamat_kontrak' => $old['alamat_kontrak'],
        'no_contract' => $old['no_contract'],
        'contract_date' => $old['contract_date'] ? date('Y-m-d', strtotime($old['contract_date'])) : null,
        'project_start' => date('Y-m-d', strtotime($old['project_start'])),
        'subject' => $old['subject'],
        'material_type' => $old['material_type'],
      ];

      $insertedId = insert('pon', $data);

      // Create default tasks for each division
      $divisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
      foreach ($divisions as $division) {
        $taskData = [
          'pon' => $old['pon'],
          'division' => $division,
          'title' => 'Task awal - ' . $division,
          'assignee' => '',
          'priority' => 'Medium',
          'progress' => 0,
          'status' => 'To Do',
          'start_date' => date('Y-m-d', strtotime($old['project_start'])),
          'due_date' => $old['date_finish'] ? date('Y-m-d', strtotime($old['date_finish'])) : null,
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
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tambah PON - <?= h($appName) ?></title>
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
    .select,
    .textarea {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px;
      border-radius: 8px;
      font-family: inherit;
    }

    .textarea {
      resize: vertical;
      min-height: 80px;
    }

    .row {
      display: flex;
      gap: 10px;
      align-items: center
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
      color: #cbd5e1
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

    .section-header {
      grid-column: 1 / -1;
      margin: 20px 0 10px 0;
      color: var(--text);
      font-size: 16px;
      font-weight: 600;
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
    }

    /* Material Select Styles */
    .material-select-wrapper {
      position: relative;
    }

    .material-select {
      width: 100%;
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px;
      border-radius: 8px;
      font-family: inherit;
      cursor: pointer;
    }

    .material-select:focus {
      outline: none;
      border-color: #3b82f6;
    }

    .material-options {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: #0d142a;
      border: 1px solid var(--border);
      border-radius: 8px;
      margin-top: 4px;
      max-height: 200px;
      overflow-y: auto;
      z-index: 1000;
      display: none;
    }

    .material-options.show {
      display: block;
    }

    .material-option {
      padding: 10px 12px;
      cursor: pointer;
      transition: background 0.2s;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .material-option:last-child {
      border-bottom: none;
    }

    .material-option:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .material-option.add-new {
      color: #3b82f6;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .material-option.add-new i {
      font-size: 14px;
    }

    .custom-material-input {
      margin-top: 8px;
      display: none;
    }

    .custom-material-input.show {
      display: block;
      animation: fadeIn 0.3s ease;
    }

    .material-suggestions {
      margin-top: 8px;
      padding: 8px;
      background: rgba(255, 255, 255, 0.02);
      border-radius: 6px;
      border: 1px solid var(--border);
    }

    .suggestion-title {
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .suggestion-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .suggestion-tag {
      background: rgba(59, 130, 246, 0.1);
      color: #3b82f6;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .suggestion-tag:hover {
      background: rgba(59, 130, 246, 0.2);
      transform: translateY(-1px);
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(-5px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>
</head>

<body>
  <div class="layout">
    <!-- Sidebar -->
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

    <!-- Header -->
    <header class="header">
      <div class="title">Tambah PON Baru</div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <section class="section">
        <div class="hd">Form PON Baru</div>
        <div class="bd">
          <?php if (isset($errors['general'])): ?>
            <div class="general-error"><?= h($errors['general']) ?></div>
          <?php endif; ?>

          <form method="post" class="form" autocomplete="off">
            <div class="section-header">Informasi Project</div>

            <div class="field">
              <label class="label" for="job_no">Job Number *</label>
              <input class="input" id="job_no" name="job_no" value="<?= h($old['job_no']) ?>"
                placeholder="W-713" pattern="W-\d+" required>
              <?php if (isset($errors['job_no'])): ?>
                <div class="error"><?= h($errors['job_no']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="pon">Nomor PON *</label>
              <input class="input" id="pon" name="pon" value="<?= h($old['pon']) ?>" required>
              <?php if (isset($errors['pon'])): ?>
                <div class="error"><?= h($errors['pon']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="client">Client *</label>
              <input class="input" id="client" name="client" value="<?= h($old['client']) ?>" required>
              <?php if (isset($errors['client'])): ?>
                <div class="error"><?= h($errors['client']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="nama_proyek">Nama Proyek *</label>
              <input class="input" id="nama_proyek" name="nama_proyek" value="<?= h($old['nama_proyek']) ?>" required>
              <?php if (isset($errors['nama_proyek'])): ?>
                <div class="error"><?= h($errors['nama_proyek']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="project_manager">Project Manager *</label>
              <input class="input" id="project_manager" name="project_manager" value="<?= h($old['project_manager']) ?>" required>
              <?php if (isset($errors['project_manager'])): ?>
                <div class="error"><?= h($errors['project_manager']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="subject">Subject *</label>
              <input class="input" id="subject" name="subject" value="<?= h($old['subject']) ?>" required>
              <?php if (isset($errors['subject'])): ?>
                <div class="error"><?= h($errors['subject']) ?></div>
              <?php endif; ?>
            </div>

            <div class="section-header">Informasi Kontrak</div>

            <div class="field">
              <label class="label" for="no_contract">No Contract *</label>
              <input class="input" id="no_contract" name="no_contract" value="<?= h($old['no_contract']) ?>" required>
              <?php if (isset($errors['no_contract'])): ?>
                <div class="error"><?= h($errors['no_contract']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="contract_date">Tanggal Kontrak</label>
              <input class="input" id="contract_date" name="contract_date" type="date" value="<?= h($old['contract_date']) ?>">
              <?php if (isset($errors['contract_date'])): ?>
                <div class="error"><?= h($errors['contract_date']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field" style="grid-column:1/-1">
              <label class="label" for="alamat_kontrak">Alamat Kontrak *</label>
              <textarea class="textarea" id="alamat_kontrak" name="alamat_kontrak" required><?= h($old['alamat_kontrak']) ?></textarea>
              <?php if (isset($errors['alamat_kontrak'])): ?>
                <div class="error"><?= h($errors['alamat_kontrak']) ?></div>
              <?php endif; ?>
            </div>

            <div class="section-header">Spesifikasi Teknis</div>

            <div class="field" style="grid-column:1/-1">
              <label class="label">Jenis Material *</label>

              <!-- Custom Material Select -->
              <div class="material-select-wrapper">
                <input type="text"
                  class="material-select"
                  id="material_select"
                  placeholder="+ Tambah material..."
                  readonly
                  onclick="toggleMaterialOptions()">
                <input type="hidden" name="material_type" id="material_type" value="<?= h($old['material_type']) ?>">

                <div class="material-options" id="material_options">
                  <!-- Option untuk tambah material baru -->
                  <div class="material-option add-new" onclick="showCustomMaterialInput()">
                    <i class="bi bi-plus-circle"></i>
                    <span>+ Tambah material baru</span>
                  </div>

                  <!-- Material suggestions -->
                  <?php if (!empty($materialSuggestions)): ?>
                    <?php foreach ($materialSuggestions as $suggestion): ?>
                      <div class="material-option" onclick="selectMaterial('<?= h($suggestion) ?>')">
                        <?= h($suggestion) ?>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Custom Material Input -->
              <div class="custom-material-input" id="custom_material_container">
                <input type="text"
                  class="input"
                  id="custom_material_input"
                  placeholder="Masukkan nama material (contoh: Baja Ringan, Besi Beton, dll.)">
                <div style="display: flex; gap: 8px; margin-top: 8px;">
                  <button type="button" class="btn" onclick="saveCustomMaterial()" style="padding: 8px 12px; font-size: 12px;">
                    Simpan Material
                  </button>
                  <button type="button" class="btn secondary" onclick="cancelCustomMaterial()" style="padding: 8px 12px; font-size: 12px;">
                    Batal
                  </button>
                </div>
              </div>

              <?php if (isset($errors['material_type'])): ?>
                <div class="error"><?= h($errors['material_type']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="qty">Quantity *</label>
              <input class="input" id="qty" name="qty" type="number" min="1" value="<?= h($old['qty']) ?>" required>
              <?php if (isset($errors['qty'])): ?>
                <div class="error"><?= h($errors['qty']) ?></div>
              <?php endif; ?>
            </div>

            <div class="section-header">Timeline</div>

            <div class="field">
              <label class="label" for="project_start">Project Start Date *</label>
              <input class="input" id="project_start" name="project_start" type="date" value="<?= h($old['project_start']) ?>" required>
              <?php if (isset($errors['project_start'])): ?>
                <div class="error"><?= h($errors['project_start']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="date_pon">Date PON *</label>
              <input class="input" id="date_pon" name="date_pon" type="date" value="<?= h($old['date_pon']) ?>" required>
              <?php if (isset($errors['date_pon'])): ?>
                <div class="error"><?= h($errors['date_pon']) ?></div>
              <?php endif; ?>
            </div>

            <div class="field">
              <label class="label" for="date_finish">Date Finish</label>
              <input class="input" id="date_finish" name="date_finish" type="date" value="<?= h($old['date_finish']) ?>">
              <?php if (isset($errors['date_finish'])): ?>
                <div class="error"><?= h($errors['date_finish']) ?></div>
              <?php endif; ?>
            </div>

            <!-- PROGRESS & STATUS DIHAPUS -->

            <div class="field" style="grid-column:1/-1">
              <div class="actions">
                <button type="submit" class="btn">Simpan PON</button>
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
    document.getElementById('date_pon').valueAsDate = new Date();

    // Set project start date to today if empty
    const projectStart = document.getElementById('project_start');
    if (!projectStart.value) {
      projectStart.valueAsDate = new Date();
    }

    // Material selection functions
    function toggleMaterialOptions() {
      const options = document.getElementById('material_options');
      options.classList.toggle('show');
    }

    function showCustomMaterialInput() {
      document.getElementById('material_options').classList.remove('show');
      document.getElementById('custom_material_container').classList.add('show');
      document.getElementById('custom_material_input').focus();
    }

    function selectMaterial(materialName) {
      document.getElementById('material_select').value = materialName;
      document.getElementById('material_type').value = materialName;
      document.getElementById('material_options').classList.remove('show');
    }

    function saveCustomMaterial() {
      const customInput = document.getElementById('custom_material_input');
      const materialName = customInput.value.trim();

      if (materialName) {
        document.getElementById('material_select').value = materialName;
        document.getElementById('material_type').value = materialName;
        document.getElementById('custom_material_container').classList.remove('show');
        customInput.value = '';
      }
    }

    function cancelCustomMaterial() {
      document.getElementById('custom_material_container').classList.remove('show');
      document.getElementById('custom_material_input').value = '';
    }

    // Close material options when clicking outside
    document.addEventListener('click', function(event) {
      const materialWrapper = document.querySelector('.material-select-wrapper');
      const materialOptions = document.getElementById('material_options');

      if (!materialWrapper.contains(event.target)) {
        materialOptions.classList.remove('show');
      }
    });

    // Initialize material select dengan nilai existing
    document.addEventListener('DOMContentLoaded', function() {
      const currentMaterial = '<?= h($old['material_type']) ?>';
      if (currentMaterial) {
        document.getElementById('material_select').value = currentMaterial;
      }
    });
  </script>
</body>

</html>