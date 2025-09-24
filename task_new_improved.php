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
$activeTab = isset($_GET['tab']) ? trim($_GET['tab']) : '';

if (!$ponCode || !$division || !$activeTab) {
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

// Get existing PICs for dropdown
$existingPics = fetchAll('SELECT DISTINCT pic FROM tasks WHERE pic IS NOT NULL AND pic != "" ORDER BY pic ASC');

$errors = [];
$old = [
    'pic' => '',
    'start_date' => '',
    'due_date' => '',
    'status' => 'ToDo',
    'keterangan' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['pic'] = trim($_POST['pic'] ?? '');
    $old['start_date'] = trim($_POST['start_date'] ?? '');
    $old['due_date'] = trim($_POST['due_date'] ?? '');
    $old['status'] = trim($_POST['status'] ?? 'ToDo');
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');

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
                'title' => $activeTab, // Use tab name as task title
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
                'satuan' => '',
                'vendor' => '',
                'no_po' => ''
            ];

            $taskId = insert('tasks', $taskData);

            // Update PON progress
            if (function_exists('updatePonProgress')) {
                updatePonProgress($ponCode);
            }

            header('Location: task_detail.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division) . '&tab=' . urlencode($activeTab) . '&added=1');
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
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Task - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/sidebar.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 30px;
        }

        .form-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .form-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .form-subtitle {
            color: var(--muted);
            font-size: 14px;
        }

        .task-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            text-align: center;
        }

        .task-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form {
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
            font-size: 14px;
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
            padding: 12px 15px;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .input:focus,
        .select:focus,
        .textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .textarea {
            min-height: 100px;
            resize: vertical;
        }

        .file-input {
            padding: 10px;
        }

        .pic-dropdown {
            position: relative;
        }

        .pic-input-container {
            position: relative;
        }

        .pic-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }

        .pic-suggestion {
            padding: 10px 15px;
            cursor: pointer;
            color: var(--text);
            border-bottom: 1px solid var(--border);
            transition: background-color 0.2s;
        }

        .pic-suggestion:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .pic-suggestion:last-child {
            border-bottom: none;
        }

        .actions {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            justify-content: center;
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
            border: none;
            cursor: pointer;
            font-size: 14px;
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
            color: #fecaca;
            font-size: 12px;
            margin-top: 5px;
        }

        .general-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fecaca;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .breadcrumb {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 25px;
            color: var(--muted);
            font-size: 14px;
        }

        .breadcrumb a {
            color: #93c5fd;
            text-decoration: none;
        }

        .breadcrumb .sep {
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
                <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php">
                    <span class="icon bi-house"></span> Dashboard
                </a>
                <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php">
                    <span class="icon bi-journal-text"></span> PON
                </a>
                <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php">
                    <span class="icon bi-list-check"></span> Task List
                </a>
                <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php">
                    <span class="icon bi-bar-chart"></span> Progres Divisi
                </a>
                <a href="logout.php">
                    <span class="icon bi-box-arrow-right"></span> Logout
                </a>
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
            <div class="breadcrumb">
                <a href="tasklist.php">Task List</a>
                <span class="sep">›</span>
                <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>"><?= strtoupper(h($ponCode)) ?></a>
                <span class="sep">›</span>
                <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&tab=<?= urlencode($activeTab) ?>"><?= h($division) ?></a>
                <span class="sep">›</span>
                <strong>Tambah Task</strong>
            </div>

            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Tambah Task Baru</div>
                    <div class="form-subtitle">Divisi <?= h($division) ?> - PON <?= strtoupper(h($ponCode)) ?></div>
                </div>

                <!-- Task Info -->
                <div class="task-info">
                    <div class="task-name"><?= h($activeTab) ?></div>
                    <div>Task akan otomatis dibuat untuk kategori ini</div>
                </div>

                <?php if (isset($errors['general'])): ?>
                    <div class="general-error"><?= h($errors['general']) ?></div>
                <?php endif; ?>

                <form method="post" class="form" enctype="multipart/form-data">
                    <!-- PIC Dropdown -->
                    <div class="field">
                        <label class="label required" for="pic">PIC (Person In Charge)</label>
                        <div class="pic-input-container">
                            <input class="input"
                                id="pic"
                                name="pic"
                                value="<?= h($old['pic']) ?>"
                                required
                                placeholder="Ketik nama PIC atau pilih dari daftar"
                                autocomplete="off">
                            <div class="pic-suggestions" id="picSuggestions">
                                <?php foreach ($existingPics as $picData): ?>
                                    <div class="pic-suggestion" onclick="selectPic('<?= h($picData['pic']) ?>')">
                                        <?= h($picData['pic']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if (isset($errors['pic'])): ?>
                            <div class="error"><?= h($errors['pic']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Status -->
                    <div class="field">
                        <label class="label required" for="status">Status</label>
                        <select class="select" id="status" name="status" required>
                            <option value="ToDo" <?= $old['status'] === 'ToDo' ? 'selected' : '' ?>>ToDo</option>
                            <option value="On Proses" <?= $old['status'] === 'On Proses' ? 'selected' : '' ?>>On Proses</option>
                            <option value="Hold" <?= $old['status'] === 'Hold' ? 'selected' : '' ?>>Hold</option>
                            <option value="Done" <?= $old['status'] === 'Done' ? 'selected' : '' ?>>Done</option>
                            <option value="Waiting Approve" <?= $old['status'] === 'Waiting Approve' ? 'selected' : '' ?>>Waiting Approve</option>
                        </select>
                        <?php if (isset($errors['status'])): ?>
                            <div class="error"><?= h($errors['status']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Start Date -->
                    <div class="field">
                        <label class="label required" for="start_date">Tanggal Mulai</label>
                        <input class="input"
                            id="start_date"
                            name="start_date"
                            type="date"
                            value="<?= h($old['start_date']) ?>"
                            required>
                        <?php if (isset($errors['start_date'])): ?>
                            <div class="error"><?= h($errors['start_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Due Date -->
                    <div class="field">
                        <label class="label required" for="due_date">Tanggal Selesai</label>
                        <input class="input"
                            id="due_date"
                            name="due_date"
                            type="date"
                            value="<?= h($old['due_date']) ?>"
                            required>
                        <?php if (isset($errors['due_date'])): ?>
                            <div class="error"><?= h($errors['due_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Files Upload -->
                    <?php if ($division !== 'Pabrikasi'): ?>
                        <div class="field">
                            <label class="label" for="files">Upload File</label>
                            <input class="input file-input"
                                id="files"
                                name="files"
                                type="file"
                                accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar">
                            <small style="color: var(--muted); font-size: 12px; margin-top: 5px;">
                                Format: PDF, DOC, DOCX, XLS, XLSX, ZIP, RAR (Max: 10MB)
                            </small>
                            <?php if (isset($errors['files'])): ?>
                                <div class="error"><?= h($errors['files']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Foto Upload (Pabrikasi) -->
                    <?php if ($division === 'Pabrikasi'): ?>
                        <div class="field">
                            <label class="label" for="foto">Upload Foto</label>
                            <input class="input file-input"
                                id="foto"
                                name="foto"
                                type="file"
                                accept="image/*">
                            <small style="color: var(--muted); font-size: 12px; margin-top: 5px;">
                                Format: JPG, PNG, GIF (Max: 5MB)
                            </small>
                            <?php if (isset($errors['foto'])): ?>
                                <div class="error"><?= h($errors['foto']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Keterangan -->
                    <div class="field full-width">
                        <label class="label" for="keterangan">Keterangan</label>
                        <textarea class="textarea"
                            id="keterangan"
                            name="keterangan"
                            rows="4"
                            placeholder="Deskripsi detail task, catatan khusus, atau instruksi tambahan"><?= h($old['keterangan']) ?></textarea>
                        <?php if (isset($errors['keterangan'])): ?>
                            <div class="error"><?= h($errors['keterangan']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="field full-width">
                        <div class="actions">
                            <button type="submit" class="btn">
                                <i class="bi bi-check-lg"></i>
                                Simpan Task
                            </button>
                            <a class="btn secondary"
                                href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&tab=<?= urlencode($activeTab) ?>">
                                <i class="bi bi-x-lg"></i>
                                Batal
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah Task</footer>
    </div>

    <script>
        // Clock functionality
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

        // PIC Dropdown functionality
        const picInput = document.getElementById('pic');
        const picSuggestions = document.getElementById('picSuggestions');

        // Show suggestions on focus
        picInput.addEventListener('focus', function() {
            picSuggestions.style.display = 'block';
        });

        // Filter suggestions on input
        picInput.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            const suggestions = picSuggestions.querySelectorAll('.pic-suggestion');

            let hasVisible = false;
            suggestions.forEach(function(suggestion) {
                const text = suggestion.textContent.toLowerCase();
                if (text.includes(filter)) {
                    suggestion.style.display = 'block';
                    hasVisible = true;
                } else {
                    suggestion.style.display = 'none';
                }
            });

            picSuggestions.style.display = hasVisible ? 'block' : 'none';
        });

        // Hide suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.pic-input-container')) {
                picSuggestions.style.display = 'none';
            }
        });

        // Select PIC function
        function selectPic(picName) {
            picInput.value = picName;
            picSuggestions.style.display = 'none';
        }

        // Set default start date to today if empty
        document.addEventListener('DOMContentLoaded', function() {
            const startDateInput = document.getElementById('start_date');
            if (startDateInput && !startDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                startDateInput.value = today;
            }

            // Set due date to 7 days from start date if empty
            const dueDateInput = document.getElementById('due_date');
            startDateInput.addEventListener('change', function() {
                if (!dueDateInput.value && this.value) {
                    const startDate = new Date(this.value);
                    startDate.setDate(startDate.getDate() + 7);
                    dueDateInput.value = startDate.toISOString().split('T')[0];
                }
            });
        });
    </script>
</body>

</html>