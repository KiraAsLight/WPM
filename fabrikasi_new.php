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
    'assy_marking' => '',
    'rv' => '',
    'name' => '',
    'qty' => 0,
    'barang_jadi' => 0,
    'barang_belum_jadi' => 0,
    'progress_calculated' => 0,
    'dimensions' => '',
    'length_mm' => 0,
    'weight_kg' => 0,
    'total_weight_kg' => 0,
    'remarks' => ''
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['no'] = (int)($_POST['no'] ?? 0);
    $old['assy_marking'] = trim($_POST['assy_marking'] ?? '');
    $old['rv'] = trim($_POST['rv'] ?? '');
    $old['name'] = trim($_POST['name'] ?? '');
    $old['qty'] = (int)($_POST['qty'] ?? 0);
    $old['barang_jadi'] = (int)($_POST['barang_jadi'] ?? 0);
    $old['barang_belum_jadi'] = (int)($_POST['barang_belum_jadi'] ?? 0);
    $old['progress_calculated'] = (int)($_POST['progress_calculated'] ?? 0);
    $old['dimensions'] = trim($_POST['dimensions'] ?? '');
    $old['length_mm'] = (float)($_POST['length_mm'] ?? 0);
    $old['weight_kg'] = (float)($_POST['weight_kg'] ?? 0);
    $old['total_weight_kg'] = (float)($_POST['total_weight_kg'] ?? 0);
    $old['remarks'] = trim($_POST['remarks'] ?? '');

    // Validation
    if ($old['no'] <= 0) {
        $errors['no'] = 'No harus lebih dari 0';
    }
    if ($old['assy_marking'] === '') {
        $errors['assy_marking'] = 'AssyMarking wajib diisi';
    }
    if ($old['name'] === '') {
        $errors['name'] = 'Name wajib diisi';
    }

    // Auto-calculate total weight if not provided
    if ($old['total_weight_kg'] == 0 && $old['qty'] > 0 && $old['weight_kg'] > 0) {
        $old['total_weight_kg'] = $old['qty'] * $old['weight_kg'];
    }

    // Auto-calculate progress
    if ($old['barang_jadi'] > $old['qty']) {
        $old['barang_jadi'] = $old['qty'];
    }
    $old['barang_belum_jadi'] = $old['qty'] - $old['barang_jadi'];
    $old['progress_calculated'] = $old['qty'] > 0 ? round(($old['barang_jadi'] / $old['qty']) * 100) : 0;

    if (empty($errors)) {
        try {
            $old['pon'] = $ponCode;
            insert('fabrikasi_items', $old);

            header('Location: fabrikasi_list.php?pon=' . urlencode($ponCode) . '&added=1');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Gagal menambah data: ' . $e->getMessage();
            error_log('Fabrikasi Add Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Data Fabrikasi - <?= h($appName) ?></title>
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
            max-width: 900px;
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

        .input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 10px 12px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .input:focus {
            outline: none;
            border-color: #3b82f6;
            background: rgba(255, 255, 255, 0.08);
        }

        .textarea {
            min-height: 80px;
            resize: vertical;
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

        .progress-display {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            flex: 1;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #059669);
            border-radius: 4px;
            transition: width 0.3s ease;
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
            <div class="title">Tambah Data Fabrikasi</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Tambah Item Fabrikasi Baru</div>
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
                            <label class="label required" for="assy_marking">AssyMarking</label>
                            <input class="input" type="text" id="assy_marking" name="assy_marking" value="<?= h($old['assy_marking']) ?>" required>
                            <?php if (isset($errors['assy_marking'])): ?>
                                <div class="error"><?= h($errors['assy_marking']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="rv">Rv</label>
                            <input class="input" type="text" id="rv" name="rv" value="<?= h($old['rv']) ?>">
                        </div>

                        <div class="field">
                            <label class="label required" for="name">Name</label>
                            <input class="input" type="text" id="name" name="name" value="<?= h($old['name']) ?>" required>
                            <?php if (isset($errors['name'])): ?>
                                <div class="error"><?= h($errors['name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="qty">Qty</label>
                            <input class="input" type="number" id="qty" name="qty" value="<?= h($old['qty']) ?>" min="0" step="1" onchange="calculateProgress()">
                        </div>

                        <div class="field">
                            <label class="label" for="dimensions">Dimensions</label>
                            <input class="input" type="text" id="dimensions" name="dimensions" value="<?= h($old['dimensions']) ?>" placeholder="e.g., 300x200x10">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="barang_jadi">Barang Jadi</label>
                            <input class="input" type="number" id="barang_jadi" name="barang_jadi"
                                value="<?= h($old['barang_jadi']) ?>" min="0" max="<?= h($old['qty']) ?>"
                                onchange="calculateProgress()">
                        </div>

                        <div class="field">
                            <label class="label" for="barang_belum_jadi">Barang Belum Jadi</label>
                            <input class="input" type="number" id="barang_belum_jadi" name="barang_belum_jadi"
                                value="<?= h($old['barang_belum_jadi']) ?>" readonly style="background: #2d3748;">
                        </div>
                    </div>

                    <div class="field">
                        <label class="label">Progress Calculated</label>
                        <div class="progress-display">
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" id="progress-bar" style="width: <?= h($old['progress_calculated']) ?>%"></div>
                            </div>
                            <span id="progress-text" style="margin-left: 10px; font-size: 14px; color: var(--text);">
                                <?= h($old['progress_calculated']) ?>%
                            </span>
                        </div>
                        <input type="hidden" name="progress_calculated" id="progress_calculated" value="<?= h($old['progress_calculated']) ?>">
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="length_mm">Length (mm)</label>
                            <input class="input" type="number" id="length_mm" name="length_mm" value="<?= h($old['length_mm']) ?>" min="0" step="0.01">
                        </div>

                        <div class="field">
                            <label class="label" for="weight_kg">Weight (kg)</label>
                            <input class="input" type="number" id="weight_kg" name="weight_kg" value="<?= h($old['weight_kg']) ?>" min="0" step="0.001">
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="total_weight_kg">Total Weight (kg)</label>
                        <input class="input" type="number" id="total_weight_kg" name="total_weight_kg" value="<?= h($old['total_weight_kg']) ?>" min="0" step="0.001">
                        <small style="color: var(--muted); font-size: 11px;">Akan dihitung otomatis jika kosong (Qty × Weight)</small>
                    </div>

                    <div class="field full-width">
                        <label class="label" for="remarks">Remarks</label>
                        <textarea class="input textarea" id="remarks" name="remarks"><?= h($old['remarks']) ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Tambah Data
                        </button>
                        <a href="fabrikasi_list.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i>
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Tambah Fabrikasi</footer>
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

        function calculateProgress() {
            const qty = parseInt(document.getElementById('qty').value) || 0;
            const barangJadi = parseInt(document.getElementById('barang_jadi').value) || 0;

            // Validasi max value
            if (barangJadi > qty) {
                document.getElementById('barang_jadi').value = qty;
                return calculateProgress();
            }

            const barangBelumJadi = qty - barangJadi;
            const progress = qty > 0 ? Math.round((barangJadi / qty) * 100) : 0;

            // Update UI
            document.getElementById('barang_belum_jadi').value = barangBelumJadi;
            document.getElementById('progress-bar').style.width = progress + '%';
            document.getElementById('progress-text').textContent = progress + '%';
            document.getElementById('progress_calculated').value = progress;

            // Auto-calculate total weight
            const weight = parseFloat(document.getElementById('weight_kg').value) || 0;
            const totalWeightInput = document.getElementById('total_weight_kg');
            if (qty > 0 && weight > 0 && (!totalWeightInput.value || totalWeightInput.value == 0)) {
                totalWeightInput.value = (qty * weight).toFixed(3);
            }
        }

        // Event listeners
        document.getElementById('qty').addEventListener('input', calculateProgress);
        document.getElementById('barang_jadi').addEventListener('input', calculateProgress);
        document.getElementById('weight_kg').addEventListener('input', calculateProgress);

        // Panggil sekali saat load
        document.addEventListener('DOMContentLoaded', calculateProgress);
    </script>
</body>

</html>