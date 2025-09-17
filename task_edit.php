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
$taskId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';
$division = isset($_GET['div']) ? trim($_GET['div']) : '';

if (!$taskId || !$ponCode || !$division) {
    header('Location: tasklist.php');
    exit;
}

// Get task data
$taskRecord = fetchOne('SELECT * FROM tasks WHERE id = ?', [$taskId]);
if (!$taskRecord) {
    header('Location: tasklist.php?error=task_not_found');
    exit;
}

$validDivisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
if (!in_array($division, $validDivisions)) {
    header('Location: tasklist.php?error=invalid_division');
    exit;
}

$errors = [];
$old = [
    'title' => (string)($taskRecord['title'] ?? ''),
    'pic' => (string)($taskRecord['pic'] ?? ''),
    'start_date' => (string)($taskRecord['start_date'] ?? ''),
    'due_date' => (string)($taskRecord['due_date'] ?? ''),
    'status' => (string)($taskRecord['status'] ?? 'ToDo'),
    'progress' => (int)($taskRecord['progress'] ?? 0),
    'keterangan' => (string)($taskRecord['keterangan'] ?? ''),
    'satuan' => (string)($taskRecord['satuan'] ?? ''),
    'vendor' => (string)($taskRecord['vendor'] ?? ''),
    'no_po' => (string)($taskRecord['no_po'] ?? '')
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['title'] = trim($_POST['title'] ?? '');
    $old['pic'] = trim($_POST['pic'] ?? '');
    $old['start_date'] = trim($_POST['start_date'] ?? '');
    $old['due_date'] = trim($_POST['due_date'] ?? '');
    $old['status'] = trim($_POST['status'] ?? 'ToDo');
    $old['progress'] = (int)($_POST['progress'] ?? 0);
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');
    $old['satuan'] = trim($_POST['satuan'] ?? '');
    $old['vendor'] = trim($_POST['vendor'] ?? '');
    $old['no_po'] = trim($_POST['no_po'] ?? '');

    // Validation
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

    $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done'];
    if (!in_array($old['status'], $validStatuses)) {
        $errors['status'] = 'Status tidak valid';
    }

    $old['progress'] = max(0, min(100, $old['progress']));

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
    $uploadedFiles = (string)($taskRecord['files'] ?? '');
    $uploadedFoto = (string)($taskRecord['foto'] ?? '');

    if (isset($_FILES['files']) && $_FILES['files']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newFiles = handleFileUpload($_FILES['files'], 'files');
        if ($newFiles !== false) {
            $uploadedFiles = $newFiles;
        } else {
            $errors['files'] = 'Gagal mengupload file';
        }
    }

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
        $newFoto = handleFileUpload($_FILES['foto'], 'foto');
        if ($newFoto !== false) {
            $uploadedFoto = $newFoto;
        } else {
            $errors['foto'] = 'Gagal mengupload foto';
        }
    }

    // Update database if no errors
    if (empty($errors)) {
        try {
            $updateData = [
                'title' => $old['title'],
                'pic' => $old['pic'],
                'start_date' => $old['start_date'],
                'due_date' => $old['due_date'],
                'status' => $old['status'],
                'progress' => $old['progress'],
                'keterangan' => $old['keterangan'],
                'files' => $uploadedFiles,
                'foto' => $uploadedFoto,
                'satuan' => $old['satuan'],
                'vendor' => $old['vendor'],
                'no_po' => $old['no_po'],
                'updated_at' => date('Y-m-d H:i')
            ];

            $rowsUpdated = update('tasks', $updateData, 'id = :id', ['id' => $taskId]);

            // Update PON progress
            if (function_exists('updatePonProgress')) {
                updatePonProgress($ponCode);
            }

            if ($rowsUpdated > 0) {
                header('Location: tasklist.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division) . '&updated=1');
                exit;
            } else {
                $errors['general'] = 'Tidak ada perubahan data yang disimpan';
            }
        } catch (Exception $e) {
            $errors['general'] = 'Gagal mengupdate task: ' . $e->getMessage();
            error_log('Task Update Error: ' . $e->getMessage());
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
        'Engineering' => ['task', 'start', 'finish', 'progress', 'status', 'pic', 'files', 'keterangan'],
        'Logistik' => ['task', 'start', 'finish', 'progress', 'status', 'pic', 'files', 'keterangan'],
        'Pabrikasi' => ['task', 'start', 'finish', 'progress', 'status', 'pic', 'foto', 'keterangan'],
        'Purchasing' => ['task', 'satuan', 'vendor', 'no_po', 'start', 'finish', 'status', 'files', 'keterangan']
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
    <title>Edit Task - <?= h($appName) ?></title>
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

        .current-file {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 4px;
            display: inline-block;
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
            <div class="title">Edit Task</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <section class="section">
                <div class="hd">Edit Task - Divisi <?= h($division) ?></div>
                <div class="bd">
                    <div class="crumb">
                        <a href="tasklist.php">PON</a><span class="sep">›</span>
                        <a href="tasklist.php?pon=<?= urlencode($ponCode) ?>"><?= strtoupper(h($ponCode)) ?></a><span class="sep">›</span>
                        <a href="tasklist.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>"><?= h($division) ?></a><span class="sep">›</span>
                        <strong>Edit Task</strong>
                    </div>

                    <div class="division-info">
                        <strong>Info Divisi <?= h($division) ?>:</strong><br>
                        Field yang tersedia: <?= implode(', ', array_map('ucfirst', $divisionFields)) ?>
                    </div>

                    <?php if (isset($errors['general'])): ?>
                        <div class="general-error"><?= h($errors['general']) ?></div>
                    <?php endif; ?>

                    <form method="post" class="form" enctype="multipart/form-data">
                        <!-- Task Title (All Divisions) -->
                        <div class="field">
                            <label class="label required" for="title">Task</label>
                            <input class="input" id="title" name="title" value="<?= h($old['title']) ?>" required
                                placeholder="Nama task/tugas">
                            <?php if (isset($errors['title'])): ?>
                                <div class="error"><?= h($errors['title']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- PIC (All Divisions) -->
                        <div class="field">
                            <label class="label required" for="pic">PIC</label>
                            <input class="input" id="pic" name="pic" value="<?= h($old['pic']) ?>" required
                                placeholder="Person In Charge">
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

                        <!-- Progress -->
                        <div class="field">
                            <label class="label" for="progress">Progress (%)</label>
                            <input class="input" id="progress" name="progress" type="number" min="0" max="100"
                                value="<?= h($old['progress']) ?>">
                            <?php if (isset($errors['progress'])): ?>
                                <div class="error"><?= h($errors['progress']) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Status -->
                        <div class="field">
                            <label class="label" for="status">Status</label>
                            <select class="select" id="status" name="status">
                                <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done'] as $st): ?>
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
                                <?php if ($taskRecord['files']): ?>
                                    <div class="current-file">
                                        <i class="bi bi-file-earmark"></i> File saat ini: <?= basename($taskRecord['files']) ?>
                                    </div>
                                <?php endif; ?>
                                <input class="input file-input" id="files" name="files" type="file"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                                <small style="color: var(--muted); font-size: 11px;">
                                    Format: PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR (Max: 10MB) - Kosongkan jika tidak ingin mengubah
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
                                <?php if ($taskRecord['foto']): ?>
                                    <div class="current-file">
                                        <i class="bi bi-image"></i> Foto saat ini: <?= basename($taskRecord['foto']) ?>
                                    </div>
                                <?php endif; ?>
                                <input class="input file-input" id="foto" name="foto" type="file"
                                    accept="image/*">
                                <small style="color: var(--muted); font-size: 11px;">
                                    Format: JPG, PNG, GIF (Max: 5MB) - Kosongkan jika tidak ingin mengubah
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
                                    <i class="bi bi-check-lg"></i> Update Task
                                </button>
                                <a class="btn secondary" href="tasklist.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>">
                                    <i class="bi bi-x-lg"></i> Batal
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Edit Task</footer>
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

        // Auto-update progress when status changes
        document.getElementById('status').addEventListener('change', function() {
            const progressInput = document.getElementById('progress');
            const status = this.value;

            if (status === 'Done' && progressInput.value < 100) {
                progressInput.value = 100;
            } else if (status === 'ToDo' && progressInput.value > 0) {
                progressInput.value = 0;
            }
        });
    </script>
</body>

</html>