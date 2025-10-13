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

// Get PON parameter
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';

if (!$ponCode) {
    header('Location: tasklist.php');
    exit;
}

// Verify PON exists
$ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
if (!$ponRecord) {
    header('Location: tasklist.php?error=pon_not_found');
    exit;
}

$errors = [];
$old = [
    'no' => '',
    'nama_parts' => '',
    'marking' => '',
    'qty' => '',
    'sent_to_site_qty' => '',
    'sent_to_site_weight' => '',
    'no_truk' => '',
    'foto' => '',
    'keterangan' => '',
    'remarks' => '',
    'progress' => 0,
    'status' => 'Menunggu'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['no'] = (int)($_POST['no'] ?? 0);
    $old['nama_parts'] = trim($_POST['nama_parts'] ?? '');
    $old['marking'] = trim($_POST['marking'] ?? '');
    $old['qty'] = (int)($_POST['qty'] ?? 0);
    $old['sent_to_site_qty'] = (int)($_POST['sent_to_site_qty'] ?? 0);
    $old['sent_to_site_weight'] = (float)($_POST['sent_to_site_weight'] ?? 0);
    $old['no_truk'] = trim($_POST['no_truk'] ?? '');
    $old['keterangan'] = trim($_POST['keterangan'] ?? '');
    $old['remarks'] = trim($_POST['remarks'] ?? '');
    $old['progress'] = (int)($_POST['progress'] ?? 0);
    $old['status'] = $_POST['status'] ?? 'Menunggu';

    // Handle file upload
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/site/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileExt = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($fileExt, $allowedExt)) {
            $fileName = time() . '_' . uniqid() . '.' . $fileExt;
            $uploadPath = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['foto']['tmp_name'], $uploadPath)) {
                $old['foto'] = 'uploads/site/' . $fileName;
            } else {
                $errors['foto'] = 'Gagal mengupload foto';
            }
        } else {
            $errors['foto'] = 'Format file tidak didukung. Gunakan JPG, PNG, atau GIF';
        }
    }

    // Validation
    if ($old['no'] <= 0) {
        $errors['no'] = 'No harus lebih dari 0';
    }
    if ($old['nama_parts'] === '') {
        $errors['nama_parts'] = 'Nama Parts wajib diisi';
    }
    if ($old['sent_to_site_qty'] < 0) {
        $errors['sent_to_site_qty'] = 'Sent to Site tidak boleh negatif';
    }

    // Check if no already exists
    $existing = fetchOne('SELECT id FROM logistik_site WHERE pon = ? AND no = ?', [$ponCode, $old['no']]);
    if ($existing) {
        $errors['no'] = 'No sudah ada untuk PON ini';
    }

    if (empty($errors)) {
        try {
            $old['pon'] = $ponCode;
            insert('logistik_site', $old);

            header('Location: logistik_site.php?pon=' . urlencode($ponCode) . '&added=1');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Gagal menambahkan data: ' . $e->getMessage();
            error_log('Site New Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Item Site - <?= h($appName) ?></title>
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
            max-width: 1000px;
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
        .textarea,
        .file-input {
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
        .textarea:focus,
        .file-input:focus {
            outline: none;
            border-color: #3b82f6;
            background: #0d142a;
        }

        .textarea {
            min-height: 80px;
            resize: vertical;
        }

        .file-input {
            cursor: pointer;
        }

        .file-preview {
            margin-top: 8px;
            text-align: center;
        }

        .file-preview img {
            max-width: 200px;
            max-height: 150px;
            border-radius: 6px;
            border: 1px solid var(--border);
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-bar-preview {
            width: 100%;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 3px;
            transition: width 0.3s ease;
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
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            border: 1px solid;
        }

        .btn-primary {
            background: #1d4ed8;
            border-color: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #1e40af;
        }

        .btn-secondary {
            background: transparent;
            color: #cbd5e1;
            border-color: var(--border);
        }

        .btn-secondary:hover {
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

        .help-text {
            color: var(--muted);
            font-size: 11px;
            margin-top: 4px;
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
            <div class="title">Tambah Item Site</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Tambah Item Site Baru</div>
                </div>

                <?php if (isset($errors['general'])): ?>
                    <div class="general-error">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= h($errors['general']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="form" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="field">
                            <label class="label required" for="no">No</label>
                            <input class="input" type="number" id="no" name="no" value="<?= h($old['no']) ?>" required min="1">
                            <?php if (isset($errors['no'])): ?>
                                <div class="error"><?= h($errors['no']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label required" for="nama_parts">Nama Parts</label>
                            <input class="input" type="text" id="nama_parts" name="nama_parts" value="<?= h($old['nama_parts']) ?>" required>
                            <?php if (isset($errors['nama_parts'])): ?>
                                <div class="error"><?= h($errors['nama_parts']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="marking">Marking</label>
                            <input class="input" type="text" id="marking" name="marking" value="<?= h($old['marking']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="qty">QTY (Pcs)</label>
                            <input class="input" type="number" id="qty" name="qty" value="<?= h($old['qty']) ?>" min="0" step="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="sent_to_site_qty">Sent to Site</label>
                            <div style="display: flex; gap: 20px;">
                                <input class="input" type="number" id="sent_to_site_qty" name="sent_to_site_qty"
                                    placeholder="pcs" style="width: 50%;"
                                    value="<?= h($old['sent_to_site_qty']) ?>" min="0" step="1">
                                <input class="input" type="number" id="sent_to_site_weight" name="sent_to_site_weight"
                                    placeholder="kg" style="width: 50%;"
                                    value="<?= h($old['sent_to_site_weight']) ?>" min="0" step="0.01">
                            </div>
                            <?php if (isset($errors['sent_to_site_qty'])): ?>
                                <div class="error"><?= h($errors['sent_to_site_qty']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="field">
                            <label class="label" for="no_truk">No. Truk</label>
                            <input class="input" type="text" id="no_truk" name="no_truk" value="<?= h($old['no_truk']) ?>">
                        </div>
                    </div>

                    <div class="field full-width">
                        <label class="label" for="foto">Foto</label>
                        <input class="file-input" type="file" id="foto" name="foto" accept="image/*" onchange="previewImage(this)">
                        <?php if (isset($errors['foto'])): ?>
                            <div class="error"><?= h($errors['foto']) ?></div>
                        <?php endif; ?>
                        <div class="help-text">Format: JPG, PNG, GIF (Max: 5MB)</div>
                        <div class="file-preview" id="foto-preview"></div>
                    </div>

                    <div class="field full-width">
                        <label class="label" for="keterangan">Keterangan</label>
                        <textarea class="textarea" id="keterangan" name="keterangan"><?= h($old['keterangan']) ?></textarea>
                    </div>

                    <div class="field full-width">
                        <label class="label" for="remarks">Remarks</label>
                        <textarea class="textarea" id="remarks" name="remarks"><?= h($old['remarks']) ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label">Progress (%)</label>
                            <div class="progress-container">
                                <div class="progress-bar-preview">
                                    <div class="progress-fill" id="progress-preview" style="width: <?= h($old['progress']) ?>%"></div>
                                </div>
                                <span id="progress-value" style="font-size: 12px; color: var(--muted); min-width: 30px;">
                                    <?= h($old['progress']) ?>%
                                </span>
                            </div>
                            <!-- Hidden field untuk simpan progress (otomatis) -->
                            <input type="hidden" id="progress" name="progress" value="<?= h($old['progress']) ?>">
                            <div class="help-text">Progress dihitung otomatis: (Sent to Site / QTY) × 100%</div>
                            <div class="help-text">Status "Diterima" + Sent to Site = QTY → Progress 100%</div>
                        </div>

                        <div class="field">
                            <label class="label" for="status">Status</label>
                            <select class="select" id="status" name="status">
                                <option value="Menunggu" <?= $old['status'] === 'Menunggu' ? 'selected' : '' ?>>Menunggu</option>
                                <option value="Diterima" <?= $old['status'] === 'Diterima' ? 'selected' : '' ?>>Diterima</option>
                            </select>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Simpan Item
                        </button>
                        <a href="logistik_site.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i>
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah Site</footer>
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

        // ✅ FUNGSI BARU: Hitung progress otomatis berdasarkan input
        function calculateAutoProgress() {
            const sentQty = parseInt(document.getElementById('sent_to_site_qty').value) || 0;
            const qty = parseInt(document.getElementById('qty').value) || 0;
            const status = document.getElementById('status').value;

            let progress = 0;

            // ✅ SAMA PERSIS dengan logic di logistik_site.php
            if (status === 'Diterima' && sentQty == qty) {
                progress = 100;
            } else {
                progress = qty > 0 ? Math.round((sentQty / qty) * 100) : 0;
            }

            // Update hidden field dan display
            document.getElementById('progress').value = progress;
            document.getElementById('progress-preview').style.width = progress + '%';
            document.getElementById('progress-value').textContent = progress + '%';
        }

        function previewImage(input) {
            const preview = document.getElementById('foto-preview');
            preview.innerHTML = '';

            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    preview.appendChild(img);
                }

                reader.readAsDataURL(input.files[0]);
            }
        }

        // ✅ Event listeners untuk field yang mempengaruhi progress
        document.addEventListener('DOMContentLoaded', function() {
            // Tambahkan event listeners
            document.getElementById('sent_to_site_qty').addEventListener('input', calculateAutoProgress);
            document.getElementById('qty').addEventListener('input', calculateAutoProgress);
            document.getElementById('status').addEventListener('change', calculateAutoProgress);

            // Hitung progress awal
            calculateAutoProgress();
        });
    </script>
</body>

</html>