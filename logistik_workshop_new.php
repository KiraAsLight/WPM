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

// Get all vendors for dropdown
$vendors = fetchAll('SELECT * FROM vendors ORDER BY name');

$errors = [];
$old = [
    'no' => '',
    'nama_parts' => '',
    'marking' => '',
    'qty' => '',
    'dimensions' => '',
    'length_mm' => '',
    'unit_weight_kg' => '',
    'total_weight_kg' => '',
    'vendor_id' => 0,
    'surat_jalan_tanggal' => '',
    'surat_jalan_nomor' => '',
    'ready_cgi' => 0, // ✅ Nilai Default 0
    'os_dhj' => '', // ✅ Akan dihitung otomatis
    'remarks' => '',
    'progress' => 0, // ✅ Progress default 0 (Belum Terkirim)
    'status' => 'Belum Terkirim' // ✅ Status default
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['no'] = (int)($_POST['no'] ?? 0);
    $old['nama_parts'] = trim($_POST['nama_parts'] ?? '');
    $old['marking'] = trim($_POST['marking'] ?? '');
    $old['qty'] = (int)($_POST['qty'] ?? 0);
    $old['dimensions'] = trim($_POST['dimensions'] ?? '');
    $old['length_mm'] = (float)($_POST['length_mm'] ?? 0);
    $old['unit_weight_kg'] = (float)($_POST['unit_weight_kg'] ?? 0);
    $old['total_weight_kg'] = (float)($_POST['total_weight_kg'] ?? 0);
    $old['vendor_id'] = (int)($_POST['vendor_id'] ?? 0);
    $old['surat_jalan_tanggal'] = $_POST['surat_jalan_tanggal'] ?? '';
    $old['surat_jalan_nomor'] = trim($_POST['surat_jalan_nomor'] ?? '');
    $old['ready_cgi'] = (int)($_POST['ready_cgi'] ?? 0);
    $old['remarks'] = trim($_POST['remarks'] ?? '');
    $old['status'] = $_POST['status'] ?? 'Belum Terkirim';

    // ✅ LOGIC: O/S DHJ = QTY - Ready CGI
    $old['os_dhj'] = $old['qty'] - $old['ready_cgi'];

    // ✅ LOGIC: Progress = Ready CGI / QTY * 100%
    if ($old['qty'] > 0) {
        $old['progress'] = (int)round(($old['ready_cgi'] / $old['qty']) * 100);
    } else {
        $old['progress'] = 0;
    }

    // ✅ Auto-set progress based on status
    if ($old['status'] === 'Terkirim') {
        $old['progress'] = 100;
        $old['ready_cgi'] = $old['qty']; // ✅ Jika Terkirim, Ready CGI = QTY
        $old['os_dhj'] = 0; // ✅ Jika Terkirim, O/S DHJ = 0
    }

    // Validation
    if ($old['no'] <= 0) {
        $errors['no'] = 'No harus lebih dari 0';
    }
    if ($old['nama_parts'] === '') {
        $errors['nama_parts'] = 'Nama Parts wajib diisi';
    }
    if ($old['qty'] < 0) {
        $errors['qty'] = 'Quantity tidak boleh negatif';
    }
    if ($old['vendor_id'] <= 0) {
        $errors['vendor_id'] = 'Vendor harus dipilih';
    }
    if ($old['ready_cgi'] < 0) {
        $errors['ready_cgi'] = 'Ready CGI tidak boleh negatif';
    }
    if ($old['ready_cgi'] > $old['qty']) {
        $errors['ready_cgi'] = 'Ready CGI tidak boleh lebih besar dari QTY';
    }

    // Validasi vendor_id exists
    if ($old['vendor_id'] > 0) {
        $vendorExists = fetchOne('SELECT id FROM vendors WHERE id = ?', [$old['vendor_id']]);
        if (!$vendorExists) {
            $errors['vendor_id'] = 'Vendor tidak valid';
        }
    }

    // Auto-calculate total weight if not provided
    if ($old['total_weight_kg'] == 0 && $old['qty'] > 0 && $old['unit_weight_kg'] > 0) {
        $old['total_weight_kg'] = $old['qty'] * $old['unit_weight_kg'];
    }

    // Check if no already exists
    $existing = fetchOne('SELECT id FROM logistik_workshop WHERE pon = ? AND no = ?', [$ponCode, $old['no']]);
    if ($existing) {
        $errors['no'] = 'No sudah ada untuk PON ini';
    }

    if (empty($errors)) {
        try {
            $data = [
                'pon' => $ponCode,
                'no' => $old['no'],
                'nama_parts' => $old['nama_parts'],
                'marking' => $old['marking'],
                'qty' => $old['qty'],
                'dimensions' => $old['dimensions'],
                'length_mm' => $old['length_mm'],
                'unit_weight_kg' => $old['unit_weight_kg'],
                'total_weight_kg' => $old['total_weight_kg'],
                'vendor_id' => $old['vendor_id'],
                'surat_jalan_tanggal' => $old['surat_jalan_tanggal'] ?: null,
                'surat_jalan_nomor' => $old['surat_jalan_nomor'],
                'ready_cgi' => $old['ready_cgi'],
                'os_dhj' => $old['os_dhj'], // ✅ Simpan O/S DHJ yang sudah dihitung
                'remarks' => $old['remarks'],
                'progress' => $old['progress'], // ✅ Simpan progress yang sudah dihitung
                'status' => $old['status'],
                'created_at' => date('Y-m-d H:i:s')
            ];

            insert('logistik_workshop', $data);

            header('Location: logistik_workshop.php?pon=' . urlencode($ponCode) . '&added=1');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Gagal menambahkan data: ' . $e->getMessage();
            error_log('Workshop New Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Item Workshop - <?= h($appName) ?></title>
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
            background: #0d142a;
        }

        .textarea {
            min-height: 80px;
            resize: vertical;
        }

        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-input {
            flex: 1;
        }

        .progress-bar-preview {
            width: 100px;
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

        .status-info {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 4px;
        }

        .vendor-link {
            font-size: 11px;
            color: #93c5fd;
            text-decoration: none;
            margin-top: 4px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .vendor-link:hover {
            text-decoration: underline;
        }

        .calculation-info {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 4px;
        }

        .readonly-input {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            color: var(--muted);
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
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
            <div class="title">Tambah Item Workshop - <?= strtoupper(h($ponCode)) ?></div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Tambah Item Workshop Baru</div>
                </div>

                <?php if (isset($errors['general'])): ?>
                    <div class="general-error">
                        <i class="bi bi-exclamation-circle"></i>
                        <?= h($errors['general']) ?>
                    </div>
                <?php endif; ?>

                <form method="post" class="form">
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
                            <label class="label required" for="qty">QTY (Pcs)</label>
                            <input class="input" type="number" id="qty" name="qty" value="<?= h($old['qty']) ?>" required min="0" step="1" oninput="calculateProgress()">
                            <?php if (isset($errors['qty'])): ?>
                                <div class="error"><?= h($errors['qty']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="dimensions">Dimensions (mm)</label>
                            <input class="input" type="text" id="dimensions" name="dimensions" value="<?= h($old['dimensions']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="length_mm">Length (mm)</label>
                            <input class="input" type="number" id="length_mm" name="length_mm" value="<?= h($old['length_mm']) ?>" min="0" step="0.01">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="unit_weight_kg">Unit Weight (Kg/Pc)</label>
                            <input class="input" type="number" id="unit_weight_kg" name="unit_weight_kg" value="<?= h($old['unit_weight_kg']) ?>" min="0" step="0.001">
                        </div>

                        <div class="field">
                            <label class="label" for="total_weight_kg">Total Weight (Kg)</label>
                            <input class="input" type="number" id="total_weight_kg" name="total_weight_kg" value="<?= h($old['total_weight_kg']) ?>" min="0" step="0.001">
                            <div class="help-text">Akan dihitung otomatis jika kosong (QTY × Unit Weight)</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label required" for="vendor_id">Vendor</label>
                            <select class="select" id="vendor_id" name="vendor_id" required>
                                <option value="">Pilih Vendor</option>
                                <?php foreach ($vendors as $vendor): ?>
                                    <option value="<?= $vendor['id'] ?>" <?= $old['vendor_id'] == $vendor['id'] ? 'selected' : '' ?>>
                                        <?= h($vendor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['vendor_id'])): ?>
                                <div class="error"><?= h($errors['vendor_id']) ?></div>
                            <?php endif; ?>
                            <a href="vendor_management.php" class="vendor-link" target="_blank">
                                <i class="bi bi-plus-circle"></i>
                                Tambah Vendor Baru
                            </a>
                        </div>

                        <div class="field">
                            <label class="label" for="surat_jalan_tanggal">Surat Jalan Tanggal</label>
                            <input class="input" type="date" id="surat_jalan_tanggal" name="surat_jalan_tanggal" value="<?= h($old['surat_jalan_tanggal']) ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="surat_jalan_nomor">Surat Jalan Nomor</label>
                            <input class="input" type="text" id="surat_jalan_nomor" name="surat_jalan_nomor" value="<?= h($old['surat_jalan_nomor']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="ready_cgi">Ready CGI</label>
                            <input class="input" type="number" id="ready_cgi" name="ready_cgi" value="<?= h($old['ready_cgi']) ?>" min="0" step="1" oninput="calculateProgress()">
                            <?php if (isset($errors['ready_cgi'])): ?>
                                <div class="error"><?= h($errors['ready_cgi']) ?></div>
                            <?php endif; ?>
                            <div class="help-text">Nilai default: 0</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="os_dhj">O/S DHJ</label>
                            <input class="readonly-input" type="text" id="os_dhj" name="os_dhj" readonly value="<?= h($old['os_dhj']) ?>">
                            <div class="calculation-info">
                                <i class="bi bi-calculator"></i>
                                O/S DHJ = QTY - Ready CGI
                            </div>
                        </div>

                        <div class="field">
                            <label class="label" for="remarks">Remarks</label>
                            <textarea class="textarea" id="remarks" name="remarks" placeholder="Keterangan tambahan..."><?= h($old['remarks']) ?></textarea>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="progress">Progress (%)</label>
                            <div class="progress-container">
                                <input class="input progress-input" type="range" id="progress" name="progress"
                                    min="0" max="100" value="<?= h($old['progress']) ?>" readonly
                                    oninput="updateProgressPreview(this.value)">
                                <div class="progress-bar-preview">
                                    <div class="progress-fill" id="progress-preview" style="width: <?= h($old['progress']) ?>%"></div>
                                </div>
                                <span id="progress-value" style="font-size: 12px; color: var(--muted); min-width: 30px;"><?= h($old['progress']) ?>%</span>
                            </div>
                            <div class="calculation-info">
                                <i class="bi bi-calculator"></i>
                                Progress = (Ready CGI / QTY) × 100%
                            </div>
                        </div>

                        <div class="field">
                            <label class="label required" for="status">Status</label>
                            <select class="select" id="status" name="status" required onchange="handleStatusChange(this.value)">
                                <option value="Belum Terkirim" <?= $old['status'] === 'Belum Terkirim' ? 'selected' : '' ?>>Belum Terkirim</option>
                                <option value="Terkirim" <?= $old['status'] === 'Terkirim' ? 'selected' : '' ?>>Terkirim</option>
                            </select>
                            <div class="status-info">
                                <i class="bi bi-info-circle"></i>
                                Status menentukan progress otomatis
                            </div>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Simpan Item
                        </button>
                        <a href="logistik_workshop.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i>
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah Workshop</footer>
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

        function updateProgressPreview(value) {
            document.getElementById('progress-preview').style.width = value + '%';
            document.getElementById('progress-value').textContent = value + '%';
        }

        function calculateProgress() {
            const qty = parseInt(document.getElementById('qty').value) || 0;
            const readyCGI = parseInt(document.getElementById('ready_cgi').value) || 0;

            // ✅ LOGIC: O/S DHJ = QTY - Ready CGI
            const osDHJ = qty - readyCGI;
            document.getElementById('os_dhj').value = osDHJ;

            // ✅ LOGIC: Progress = Ready CGI / QTY * 100%
            let progress = 0;
            if (qty > 0) {
                progress = Math.round((readyCGI / qty) * 100);
            }

            document.getElementById('progress').value = progress;
            updateProgressPreview(progress);
        }

        function handleStatusChange(status) {
            const qty = parseInt(document.getElementById('qty').value) || 0;

            if (status === 'Terkirim') {
                // ✅ Auto-set progress to 100% when status is Terkirim
                document.getElementById('progress').value = 100;
                updateProgressPreview(100);

                // ✅ Set Ready CGI = QTY dan O/S DHJ = 0
                document.getElementById('ready_cgi').value = qty;
                document.getElementById('os_dhj').value = 0;
            } else if (status === 'Belum Terkirim') {
                // Recalculate progress based on current values
                calculateProgress();
            }
        }

        // Auto-calculate total weight
        const qtyInput = document.getElementById('qty');
        const unitWeightInput = document.getElementById('unit_weight_kg');
        const totalWeightInput = document.getElementById('total_weight_kg');

        function calculateTotalWeight() {
            const qty = parseFloat(qtyInput.value) || 0;
            const unitWeight = parseFloat(unitWeightInput.value) || 0;

            if (qty > 0 && unitWeight > 0) {
                totalWeightInput.value = (qty * unitWeight).toFixed(3);
            }
        }

        qtyInput.addEventListener('input', calculateTotalWeight);
        unitWeightInput.addEventListener('input', calculateTotalWeight);

        // Initialize calculations on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateProgress();
        });
    </script>
</body>

</html>