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

$errors = [];
$old = [
    'pic' => '',
    'start_date' => '',
    'due_date' => '',
    'status' => 'ToDo',
    'keterangan' => '',
    'satuan' => '',
    'vendor' => '',
    'no_po' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['pic'] = trim($_POST['pic'] ?? '');
    $old['start_date'] = trim($_POST['start_date'] ?? '');
    $old['due_date'] = trim($_POST['due_date'] ?? '');
    $old['status'] = trim($_POST['status'] ?? 'ToDo');
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');
    $old['satuan'] = trim($_POST['satuan'] ?? '');
    $old['vendor'] = trim($_POST['vendor'] ?? '');
    $old['no_po'] = trim($_POST['no_po'] ?? '');

    // Validation
    if ($old['pic'] === '') {
        $errors['pic'] = 'PIC wajib diisi';
    }
    if ($old['start_date'] === '') {
        $errors['start_date'] = 'Tanggal mulai wajib diisi';
    }
    if ($old['due_date'] === '') {
        $errors['due_date'] = 'Tanggal selesai wajib diisi';
    }

    $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'];
    if (!in_array($old['status'], $validStatuses)) {
        $errors['status'] = 'Status tidak valid';
    }

    // Division-specific validation
    if ($division === 'Purchasing') {
        if ($old['satuan'] === '') {
            $errors['satuan'] = 'Satuan wajib diisi untuk divisi Purchasing';
        }
        if ($old['vendor'] === '') {
            $errors['vendor'] = 'Vendor wajib diisi untuk divisi Purchasing';
        }
    }

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

    // Generate task title based on division and category
    $taskCategories = [
        'Engineering' => ['List Material', 'Shop Drawing', 'Erection', 'Manual Book', 'QC Dossier'],
        'Logistik' => ['Pengiriman Material', 'Koordinasi Transportasi', 'Dokumen Pengiriman', 'Tracking', 'Delivery'],
        'Pabrikasi' => ['Cutting', 'Welding', 'Assembly', 'Painting', 'QC Check'],
        'Purchasing' => ['RFQ', 'PO Processing', 'Vendor Management', 'Material Receipt', 'Invoice']
    ];

    $defaultTitle = $taskCategories[$division][0] ?? 'Task Default';

    // Save to database if no errors
    if (empty($errors)) {
        try {
            $taskData = [
                'pon' => $ponCode,
                'division' => $division,
                'title' => $defaultTitle, // Use default title
                'assignee' => '',
                'priority' => 'Medium',
                'progress' => 0,
                'status' => $old['status'],
                'start_date' => $old['start_date'],
                'due_date' => $old['due_date'],
                'pic' => $old['pic'],
                'files' => $uploadedFiles,
                'foto' => $uploadedFoto,
                'keterangan' => $old['keterangan'],
                'satuan' => $old['satuan'],
                'vendor' => $old['vendor'],
                'no_po' => $old['no_po']
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

// Get field configuration for division
function getDivisionFields($division)
{
    $fields = [
        'Engineering' => ['pic', 'start', 'finish', 'status', 'files', 'keterangan'],
        'Logistik' => ['pic', 'start', 'finish', 'status', 'files', 'keterangan'],
        'Pabrikasi' => ['pic', 'start', 'finish', 'status', 'foto', 'keterangan'],
        'Purchasing' => ['satuan', 'vendor', 'no_po', 'start', 'finish', 'status', 'files', 'keterangan']
    ];

    return $fields[$division] ?? [];
}

$divisionFields = getDivisionFields($division);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Task - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet"
        href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
    <style>
        .form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            max-width: 1200px;
        }

        @media (max-width: 900px) {
            .form {
                grid-template-columns: 1fr;
            }
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
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
            background: #0d142a;
            border: 1px solid var(--border);
            color: var(--text);
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
        }

        .textarea {
            min-height: 80px;
            resize: vertical;
        }

        .file-input {
            padding: 8px;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn {
            display: inline-block;
            background: #1d4ed8;
            border: 1px solid #3b82f6;
            color: #fff;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 14px;
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
            font-size: 12px;
            margin-top: 4px;
        }

        .general-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fecaca;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .division-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .crumb {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 16px;
            color: var(--muted);
        }

        .crumb a {
            color: #93c5fd;
            text-decoration: none;
        }

        .crumb .sep {
            opacity: 0.5;
        }

        .custom-pic-input {
            margin-top: 8px;
            display: none;
        }

        .custom-pic-input.show {
            display: block;
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
            <div class="title">Tambah Task</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <section class="section">
                <div class="hd">Form Task Baru - Divisi <?= h($division) ?></div>
                <div class="bd">
                    <div class="crumb">
                        <a href="tasklist.php">PON</a><span class="sep">›</span>
                        <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>"><?= strtoupper(h($ponCode)) ?></a><span class="sep">›</span>
                        <a href="task_detail_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>"><?= h($division) ?></a><span class="sep">›</span>
                        <strong>Tambah Task</strong>
                    </div>

                    <div class="division-info">
                        <strong>Info Divisi <?= h($division) ?>:</strong><br>
                        Field yang tersedia: <?= implode(', ', array_map('ucfirst', $divisionFields)) ?>
                    </div>

                    <?php if (isset($errors['general'])): ?>
                        <div class="general-error"><?= h($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="post" class="form" enctype="multipart/form-data">
                        <!-- PIC Dropdown (All Divisions) -->
                        <div class="field">
                            <label class="label required" for="pic">PIC</label>
                            <select class="select" id="pic" name="pic" required onchange="toggleCustomPic(this)">
                                <option value="">Pilih PIC</option>
                                <?php foreach ($picOptions as $pic): ?>
                                    <option value="<?= h($pic) ?>" <?= $old['pic'] === $pic ? 'selected' : '' ?>>
                                        <?= h($pic) ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="custom">+ Tambah PIC Baru</option>
                            </select>
                            <input type="text" class="input custom-pic-input" id="custom-pic" placeholder="Masukkan nama PIC baru">
                            <?php if (isset($errors['pic'])): ?>
                                <div class="error"><?= h($errors['pic']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Satuan (Purchasing Only) -->
                        <?php if ($division === 'Purchasing'): ?>
                            <div class="field">
                                <label class="label required" for="satuan">Satuan</label>
                                <input class="input" id="satuan" name="satuan" value="<?= h($old['satuan']) ?>" required
                                    placeholder="Unit/Satuan barang">
                                <?php if (isset($errors['satuan'])): ?>
                                    <div class="error"><?= h($errors['satuan']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="field">
                                <label class="label required" for="vendor">Vendor</label>
                                <input class="input" id="vendor" name="vendor" value="<?= h($old['vendor']) ?>" required
                                    placeholder="Nama vendor/supplier">
                                <?php if (isset($errors['vendor'])): ?>
                                    <div class="error"><?= h($errors['vendor']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="field">
                                <label class="label" for="no_po">No. PO</label>
                                <input class="input" id="no_po" name="no_po" value="<?= h($old['no_po']) ?>"
                                    placeholder="Nomor Purchase Order">
                                <?php if (isset($errors['no_po'])): ?>
                                    <div class="error"><?= h($errors['no_po']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <!-- Empty field for other divisions -->
                            <div class="field"></div>
                        <?php endif; ?>

                        <!-- Start Date -->
                        <div class="field">
                            <label class="label required" for="start_date">Tanggal Mulai</label>
                            <input class="input" id="start_date" name="start_date" type="date" value="<?= h($old['start_date']) ?>" required>
                            <?php if (isset($errors['start_date'])): ?>
                                <div class="error"><?= h($errors['start_date']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Due Date -->
                        <div class="field">
                            <label class="label required" for="due_date">Tanggal Selesai</label>
                            <input class="input" id="due_date" name="due_date" type="date" value="<?= h($old['due_date']) ?>" required>
                            <?php if (isset($errors['due_date'])): ?>
                                <div class="error"><?= h($errors['due_date']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Status -->
                        <div class="field">
                            <label class="label" for="status">Status</label>
                            <select class="select" id="status" name="status">
                                <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'] as $st): ?>
                                    <option value="<?= h($st) ?>" <?= $old['status'] === $st ? 'selected' : '' ?>>
                                        <?= h($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Files Upload (Engineering, Logistik, Purchasing) -->
                        <?php if (in_array('files', $divisionFields)): ?>
                            <div class="field">
                                <label class="label" for="files">Files</label>
                                <input class="input file-input" id="files" name="files" type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                                <small style="color: var(--muted); font-size: 11px;">
                                    Format: PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR (Max: 10MB)
                                </small>
                                <?php if (isset($errors['files'])): ?>
                                    <div class="error"><?= h($errors['files']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Foto Upload (Pabrikasi) -->
                        <?php if (in_array('foto', $divisionFields)): ?>
                            <div class="field">
                                <label class="label" for="foto">Foto</label>
                                <input class="input file-input" id="foto" name="foto" type="file"
                                    accept="image/*">
                                <small style="color: var(--muted); font-size: 11px;">
                                    Format: JPG, PNG, GIF (Max: 5MB)
                                </small>
                                <?php if (isset($errors['foto'])): ?>
                                    <div class="error"><?= h($errors['foto']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Keterangan (All Divisions) -->
                        <div class="field full-width">
                            <label class="label" for="keterangan">Keterangan</label>
                            <textarea class="textarea" id="keterangan" name="keterangan" rows="4"
                                placeholder="Deskripsi tambahan atau catatan task"><?= h($old['keterangan']) ?></textarea>
                            <?php if (isset($errors['keterangan'])): ?>
                                <div class="error"><?= h($errors['keterangan']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="field full-width">
                            <div class="actions">
                                <button type="submit" class="btn">
                                    <i class="bi bi-check-lg"></i> Simpan Task
                                </button>
                                <a class="btn secondary" href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>">
                                    <i class="bi bi-x-lg"></i> Batal
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
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

        // Set default start date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.getElementById('start_date');
            if (startDateInput && !startDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                startDateInput.value = today;
            }
        });

        // Toggle custom PIC input
        function toggleCustomPic(select) {
            const customInput = document.getElementById('custom-pic');
            const picNameInput = document.querySelector('input[name="pic"]');

            if (select.value === 'custom') {
                customInput.classList.add('show');
                customInput.required = true;
                customInput.focus();

                // Create hidden input for custom PIC value
                if (!picNameInput) {
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'pic';
                    select.parentNode.appendChild(hiddenInput);
                }

                customInput.addEventListener('input', function() {
                    document.querySelector('input[name="pic"]').value = this.value;
                });
            } else {
                customInput.classList.remove('show');
                customInput.required = false;

                // Remove hidden input
                if (picNameInput) {
                    picNameInput.remove();
                }
            }
        }
    </script>
</body>

</html>