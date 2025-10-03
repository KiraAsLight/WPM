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

// Get parameters
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';
$division = isset($_GET['div']) ? trim($_GET['div']) : '';

if (!$ponCode || !$division) {
    header('Location: tasklist.php');
    exit;
}

// Verify PON exists
$ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
if (!$ponRecord) {
    header('Location: tasklist.php?error=pon_not_found');
    exit;
}

$validDivisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
if (!in_array($division, $validDivisions)) {
    header('Location: tasklist.php?error=invalid_division');
    exit;
}

// Get existing PICs from database for dropdown
$existingPics = fetchAll('SELECT DISTINCT pic FROM tasks WHERE pic != "" ORDER BY pic');
$picOptions = array_column($existingPics, 'pic');

// Get existing vendors for Purchasing
$existingVendors = fetchAll('SELECT DISTINCT vendor FROM tasks WHERE vendor != "" ORDER BY vendor');
$vendorOptions = array_column($existingVendors, 'vendor');

$errors = [];
$old = [
    'title' => '',
    'pic' => '',
    'start_date' => '',
    'due_date' => '',
    'status' => 'ToDo',
    'progress' => 0,
    'keterangan' => '',
    'satuan' => '',
    'vendor' => '',
    'no_po' => '',
    'date_po' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle custom PIC input
    $customPic = trim($_POST['custom_pic'] ?? '');
    if (!empty($customPic)) {
        $old['pic'] = $customPic;
    } else {
        $old['pic'] = trim($_POST['pic'] ?? '');
    }

    // Handle custom task input
    $customTask = trim($_POST['custom_task'] ?? '');
    if (!empty($customTask)) {
        $old['title'] = $customTask;
    } else {
        $old['title'] = trim($_POST['title'] ?? '');
    }

    // Handle custom purchase input (Purchasing)
    $customPurchase = trim($_POST['custom_purchase'] ?? '');
    if (!empty($customPurchase)) {
        $old['satuan'] = $customPurchase;
        $old['title'] = $customPurchase; // Map ke title juga
    } else {
        $old['satuan'] = trim($_POST['satuan'] ?? '');
        if ($division === 'Purchasing') {
            $old['title'] = $old['satuan']; // Map satuan ke title untuk Purchasing
        }
    }

    // Handle custom vendor input (Purchasing)
    $customVendor = trim($_POST['custom_vendor'] ?? '');
    if (!empty($customVendor)) {
        $old['vendor'] = $customVendor;
    } else {
        $old['vendor'] = trim($_POST['vendor'] ?? '');
    }

    $old['start_date'] = trim($_POST['start_date'] ?? '');
    $old['due_date'] = trim($_POST['due_date'] ?? '');
    $old['status'] = trim($_POST['status'] ?? 'ToDo');
    $old['progress'] = (int)($_POST['progress'] ?? 0);
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');
    $old['no_po'] = trim($_POST['no_po'] ?? '');
    $old['date_po'] = trim($_POST['date_po'] ?? '');

    // Division-specific validation
    if ($division === 'Engineering' || $division === 'Logistik' || $division === 'Pabrikasi') {
        if ($old['title'] === '') {
            $errors['title'] = 'Task wajib diisi';
        }
        if ($old['pic'] === '') {
            $errors['pic'] = 'PIC wajib diisi';
        }
        if ($old['start_date'] === '') {
            $errors['start_date'] = 'Tanggal mulai wajib diisi';
        }
        if ($old['due_date'] === '') {
            $errors['due_date'] = 'Tanggal selesai wajib diisi';
        }
    } elseif ($division === 'Purchasing') {
        if ($old['satuan'] === '') {
            $errors['satuan'] = 'Purchase wajib diisi';
        }
        if ($old['vendor'] === '') {
            $errors['vendor'] = 'Vendor wajib diisi';
        }
        if ($old['start_date'] === '') {
            $errors['start_date'] = 'Tanggal mulai wajib diisi';
        }
        if ($old['due_date'] === '') {
            $errors['due_date'] = 'Tanggal selesai wajib diisi';
        }
    }

    $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'];
    if (!in_array($old['status'], $validStatuses)) {
        $errors['status'] = 'Status tidak valid';
    }

    $old['progress'] = max(0, min(100, $old['progress']));

    // Handle file uploads
    $uploadedFiles = '';
    $uploadedFoto = '';

    if (isset($_FILES['files']) && $_FILES['files']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadedFiles = handleFileUpload($_FILES['files'], 'files');
        if ($uploadedFiles === false) {
            $errors['files'] = 'Gagal mengupload file';
        }
    }

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploadedFoto = handleFileUpload($_FILES['foto'], 'foto');
        if ($uploadedFoto === false) {
            $errors['foto'] = 'Gagal mengupload foto';
        }
    }

    // Save to database if no errors
    if (empty($errors)) {
        try {
            $taskData = [
                'pon' => $ponCode,
                'division' => $division,
                'title' => $old['title'],
                'assignee' => '',
                'priority' => 'Medium',
                'progress' => $old['progress'],
                'status' => $old['status'],
                'start_date' => $old['start_date'],
                'due_date' => $old['due_date'],
                'pic' => $old['pic'],
                'files' => $uploadedFiles,
                'foto' => $uploadedFoto,
                'keterangan' => $old['keterangan'],
                'satuan' => $old['satuan'],
                'vendor' => $old['vendor'],
                'no_po' => $old['no_po'],
                'created_at' => $old['date_po'] ?: date('Y-m-d H:i:s')
            ];

            $taskId = insert('tasks', $taskData);

            // Update PON progress
            if (function_exists('updatePonProgress')) {
                updatePonProgress($ponCode);
            }

            header('Location: task_detail.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division) . '&added=1');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Gagal menyimpan task: ' . $e->getMessage();
            error_log('Task Insert Error: ' . $e->getMessage());
        }
    }
}

/**
 * Handle file upload
 */
function handleFileUpload($file, $type = 'files')
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $uploadDir = __DIR__ . '/uploads/' . $type . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $fileName = time() . '_' . basename($file['name']);
    $uploadPath = $uploadDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return 'uploads/' . $type . '/' . $fileName;
    }

    return false;
}

// Get dropdown options based on division
$taskOptions = [
    'Engineering' => ['List Material', 'Shop Drawing', 'Erection', 'Manual Book', 'QC Dossier'],
    'Logistik' => ['Pengiriman Material', 'Koordinasi Transportasi', 'Dokumen Pengiriman', 'Tracking', 'Delivery'],
    'Pabrikasi' => ['Cutting', 'Welding', 'Assembly', 'Painting', 'QC Check'],
    'Purchasing' => ['Fabrikasi', 'Bondek', 'Aksesoris', 'Baut', 'Angkur', 'Bearing', 'Pipa']
];

$defaultVendors = ['PT Duta Hita Jaya', 'PT Maja Makmur', 'PT Citra Baja'];
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Task - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        .form-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            max-width: 800px;
        }

        .form-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }

        .form-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-subtitle {
            font-size: 13px;
            color: var(--muted);
        }

        .form {
            display: grid;
            gap: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field.full-width {
            grid-column: 1 / -1;
        }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }

        .label.required::after {
            content: ' *';
            color: #ef4444;
        }

        .input,
        .select,
        .textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .input:focus,
        .select:focus,
        .textarea:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(255, 255, 255, 0.08);
        }

        .textarea {
            min-height: 100px;
            resize: vertical;
        }

        .file-input {
            padding: 8px;
            font-size: 13px;
        }

        .file-hint {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
        }

        .actions {
            display: flex;
            gap: 12px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #1d4ed8;
            border: 1px solid #3b82f6;
            color: #fff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
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
            color: #fca5a5;
            font-size: 12px;
            margin-top: 4px;
        }

        .general-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .division-badge {
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .custom-input-container {
            display: none;
            margin-top: 8px;
        }

        .custom-input-container.show {
            display: block;
        }

        input[type="date"] {
            color-scheme: dark;
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
            <div class="title">Tambah Task</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Tambah Task Baru</div>
                    <div class="form-subtitle">
                        PON: <strong><?= strtoupper(h($ponCode)) ?></strong> •
                        Divisi: <span class="division-badge"><?= h($division) ?></span>
                    </div>
                </div>

                <?php if (isset($errors['general'])): ?>
                    <div class="general-error">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= h($errors['general']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="form" enctype="multipart/form-data">
                    <?php if ($division === 'Engineering' || $division === 'Logistik' || $division === 'Pabrikasi'): ?>
                        <!-- Engineering, Logistik, Pabrikasi Form -->
                        <div class="form-row">
                            <!-- Task Dropdown -->
                            <div class="field">
                                <label class="label required" for="title"><?= h($division) === 'Engineering' ? 'Engineering' : 'Task' ?></label>
                                <select class="select" id="title" name="title" onchange="toggleCustomInput(this, 'task')">
                                    <option value="">Pilih Task</option>
                                    <?php foreach ($taskOptions[$division] as $task): ?>
                                        <option value="<?= h($task) ?>" <?= $old['title'] === $task ? 'selected' : '' ?>>
                                            <?= h($task) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="">+ Tambah List</option>
                                </select>
                                <div class="custom-input-container" id="custom-task-container">
                                    <input type="text" class="input" id="custom-task" name="custom_task" placeholder="Masukkan nama task baru">
                                </div>
                                <?php if (isset($errors['title'])): ?>
                                    <div class="error"><?= h($errors['title']) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- PIC Dropdown -->
                            <div class="field">
                                <label class="label required" for="pic">PIC</label>
                                <select class="select" id="pic" name="pic" onchange="toggleCustomInput(this, 'pic')">
                                    <option value="">Pilih PIC</option>
                                    <?php foreach ($picOptions as $pic): ?>
                                        <option value="<?= h($pic) ?>" <?= $old['pic'] === $pic ? 'selected' : '' ?>>
                                            <?= h($pic) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="">+ Tambah PIC</option>
                                </select>
                                <div class="custom-input-container" id="custom-pic-container">
                                    <input type="text" class="input" id="custom-pic" name="custom_pic" placeholder="Masukkan nama PIC baru">
                                </div>
                                <?php if (isset($errors['pic'])): ?>
                                    <div class="error"><?= h($errors['pic']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <!-- Start Date -->
                            <div class="field">
                                <label class="label required" for="start_date">Start</label>
                                <input class="input" id="start_date" name="start_date" type="date" value="<?= h($old['start_date']) ?>" required>
                                <?php if (isset($errors['start_date'])): ?>
                                    <div class="error"><?= h($errors['start_date']) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Due Date -->
                            <div class="field">
                                <label class="label required" for="due_date">Finish</label>
                                <input class="input" id="due_date" name="due_date" type="date" value="<?= h($old['due_date']) ?>" required>
                                <?php if (isset($errors['due_date'])): ?>
                                    <div class="error"><?= h($errors['due_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-row">
                            <!-- Progress -->
                            <div class="field">
                                <label class="label" for="progress">Progres (%)</label>
                                <input class="input" id="progress" name="progress" type="number" min="0" max="100" value="<?= h($old['progress']) ?>">
                            </div>

                            <!-- Status -->
                            <div class="field">
                                <label class="label required" for="status">Status</label>
                                <select class="select" id="status" name="status" required>
                                    <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'] as $st): ?>
                                        <option value="<?= h($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>>
                                            <?= h($st) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Files/Foto Upload -->
                        <?php if ($division === 'Pabrikasi'): ?>
                            <div class="field">
                                <label class="label" for="foto">Foto</label>
                                <input class="input file-input" id="foto" name="foto" type="file" accept="image/*">
                                <div class="file-hint">Format: JPG, PNG, GIF (Max: 5MB)</div>
                                <?php if (isset($errors['foto'])): ?>
                                    <div class="error"><?= h($errors['foto']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="field">
                                <label class="label" for="files">Files</label>
                                <input class="input file-input" id="files" name="files" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                                <div class="file-hint">Format: PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR (Max: 10MB)</div>
                                <?php if (isset($errors['files'])): ?>
                                    <div class="error"><?= h($errors['files']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($division === 'Purchasing'): ?>
                        <!-- Purchasing Form -->
                        <div class="field">
                            <label class="label" for="date_po">Date PO</label>
                            <input class="input" id="date_po" name="date_po" type="date" value="<?= h($old['date_po']) ?>">
                            <div class="file-hint">Opsional - Kosongkan untuk menggunakan tanggal hari ini</div>
                        </div>

                        <div class="form-row">
                            <!-- Purchase Dropdown -->
                            <div class="field">
                                <label class="label required" for="satuan">Purchase</label>
                                <select class="select" id="satuan" name="satuan" onchange="toggleCustomInput(this, 'purchase')">
                                    <option value="">Pilih Purchase</option>
                                    <?php foreach ($taskOptions['Purchasing'] as $item): ?>
                                        <option value="<?= h($item) ?>" <?= $old['satuan'] === $item ? 'selected' : '' ?>>
                                            <?= h($item) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="">+ Tambah Purchase</option>
                                </select>
                                <div class="custom-input-container" id="custom-purchase-container">
                                    <input type="text" class="input" id="custom-purchase" name="custom_purchase" placeholder="Masukkan nama purchase baru">
                                </div>
                                <?php if (isset($errors['satuan'])): ?>
                                    <div class="error"><?= h($errors['satuan']) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Vendor Dropdown -->
                            <div class="field">
                                <label class="label required" for="vendor">Vendor</label>
                                <select class="select" id="vendor" name="vendor" onchange="toggleCustomInput(this, 'vendor')">
                                    <option value="">Pilih Vendor</option>
                                    <?php
                                    $allVendors = array_unique(array_merge($defaultVendors, $vendorOptions));
                                    foreach ($allVendors as $v):
                                    ?>
                                        <option value="<?= h($v) ?>" <?= $old['vendor'] === $v ? 'selected' : '' ?>>
                                            <?= h($v) ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="">+ Tambah Vendor</option>
                                </select>
                                <div class="custom-input-container" id="custom-vendor-container">
                                    <input type="text" class="input" id="custom-vendor" name="custom_vendor" placeholder="Masukkan nama vendor baru">
                                </div>
                                <?php if (isset($errors['vendor'])): ?>
                                    <div class="error"><?= h($errors['vendor']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="no_po">No. PO</label>
                            <input class="input" id="no_po" name="no_po" type="text" value="<?= h($old['no_po']) ?>" placeholder="Nomor Purchase Order">
                            <?php if (isset($errors['no_po'])): ?>
                                <div class="error"><?= h($errors['no_po']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <!-- Start Date -->
                            <div class="field">
                                <label class="label required" for="start_date">Start</label>
                                <input class="input" id="start_date" name="start_date" type="date" value="<?= h($old['start_date']) ?>" required>
                                <?php if (isset($errors['start_date'])): ?>
                                    <div class="error"><?= h($errors['start_date']) ?></div>
                                <?php endif; ?>
                            </div>

                            <!-- Due Date -->
                            <div class="field">
                                <label class="label required" for="due_date">Finish</label>
                                <input class="input" id="due_date" name="due_date" type="date" value="<?= h($old['due_date']) ?>" required>
                                <?php if (isset($errors['due_date'])): ?>
                                    <div class="error"><?= h($errors['due_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="field">
                            <label class="label required" for="status">Status</label>
                            <select class="select" id="status" name="status" required>
                                <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'] as $st): ?>
                                    <option value="<?= h($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>>
                                        <?= h($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="label" for="files">Files / Foto</label>
                            <input class="input file-input" id="files" name="files" type="file">
                            <div class="file-hint">Format: PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR, JPG, PNG (Max: 10MB)</div>
                            <?php if (isset($errors['files'])): ?>
                                <div class="error"><?= h($errors['files']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Keterangan (All Divisions) -->
                    <div class="field">
                        <label class="label" for="keterangan">Keterangan</label>
                        <textarea class="textarea" id="keterangan" name="keterangan" placeholder="Catatan atau deskripsi tambahan"><?= h($old['keterangan']) ?></textarea>
                        <?php if (isset($errors['keterangan'])): ?>
                            <div class="error"><?= h($errors['keterangan']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="actions">
                        <button type="submit" class="btn">
                            <i class="bi bi-check-lg"></i>
                            Simpan Task
                        </button>
                        <a class="btn secondary" href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>">
                            <i class="bi bi-x-lg"></i>
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah Task</footer>
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

        // Set default start date to today
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.getElementById('start_date');
            if (startDateInput && !startDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                startDateInput.value = today;
            }
        });

        // Toggle custom input based on type
        function toggleCustomInput(select, type) {
            const container = document.getElementById(`custom-${type}-container`);
            const customInput = document.getElementById(`custom-${type}`);

            if (select.value === '') {
                container.classList.add('show');
                customInput.focus();
                customInput.value = '';
            } else {
                container.classList.remove('show');
                customInput.value = '';
            }
        }

        // Auto-update progress based on status
        const statusSelect = document.getElementById('status');
        const progressInput = document.getElementById('progress');

        if (statusSelect && progressInput) {
            statusSelect.addEventListener('change', function() {
                if (this.value === 'Done') {
                    progressInput.value = 100;
                } else if (this.value === 'ToDo') {
                    progressInput.value = 0;
                } else if (this.value === 'On Proses' && progressInput.value === '0') {
                    progressInput.value = 25;
                }
            });
        }
    </script>
</body>

</html>