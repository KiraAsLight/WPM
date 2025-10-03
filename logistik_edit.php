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

$itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';

if (!$itemId || !$ponCode) {
    header('Location: tasklist.php');
    exit;
}

// Get item data
$item = fetchOne('SELECT * FROM logistik_items WHERE id = ? AND pon = ?', [$itemId, $ponCode]);
if (!$item) {
    header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&error=not_found');
    exit;
}

// Get deliveries
$deliveries = fetchAll('SELECT * FROM logistik_deliveries WHERE logistik_item_id = ? ORDER BY po_number', [$itemId]);

$errors = [];
$old = [
    'no' => $item['no'],
    'part_names' => $item['part_names'],
    'marking' => $item['marking'],
    'qty' => $item['qty'],
    'dimensions' => $item['dimensions'],
    'length_mm' => $item['length_mm'],
    'unit_weight_kg' => $item['unit_weight_kg'],
    'total_weight_kg' => $item['total_weight_kg'],
    'untek' => $item['untek'],
    'ready_cgi' => $item['ready_cgi'],
    'os_dhj' => $item['os_dhj'],
    'remarks' => $item['remarks']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['no'] = (int)($_POST['no'] ?? 0);
    $old['part_names'] = trim($_POST['part_names'] ?? '');
    $old['marking'] = trim($_POST['marking'] ?? '');
    $old['qty'] = (int)($_POST['qty'] ?? 0);
    $old['dimensions'] = trim($_POST['dimensions'] ?? '');
    $old['length_mm'] = (float)($_POST['length_mm'] ?? 0);
    $old['unit_weight_kg'] = (float)($_POST['unit_weight_kg'] ?? 0);
    $old['total_weight_kg'] = (float)($_POST['total_weight_kg'] ?? 0);
    $old['untek'] = trim($_POST['untek'] ?? '');
    $old['ready_cgi'] = trim($_POST['ready_cgi'] ?? '');
    $old['os_dhj'] = trim($_POST['os_dhj'] ?? '');
    $old['remarks'] = trim($_POST['remarks'] ?? '');

    // Validation
    if ($old['no'] <= 0) {
        $errors['no'] = 'No harus lebih dari 0';
    }
    if ($old['part_names'] === '') {
        $errors['part_names'] = 'Part Names wajib diisi';
    }

    // Auto-calculate total weight if not provided
    if ($old['total_weight_kg'] == 0 && $old['qty'] > 0 && $old['unit_weight_kg'] > 0) {
        $old['total_weight_kg'] = $old['qty'] * $old['unit_weight_kg'];
    }

    // Handle deliveries update
    $deliveryUpdates = [];
    foreach ($deliveries as $delivery) {
        $deliveryKey = 'delivery_' . $delivery['id'];
        if (isset($_POST[$deliveryKey])) {
            $deliveryUpdates[$delivery['id']] = (float)$_POST[$deliveryKey];
        }
    }

    if (empty($errors)) {
        try {
            // Update item
            update('logistik_items', $old, 'id = :id', ['id' => $itemId]);

            // Update deliveries
            foreach ($deliveryUpdates as $deliveryId => $weight) {
                update(
                    'logistik_deliveries',
                    ['delivery_weight' => $weight],
                    'id = :id',
                    ['id' => $deliveryId]
                );
            }

            header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&updated=1');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Gagal mengupdate data: ' . $e->getMessage();
            error_log('Logistik Update Error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Logistik - <?= h($appName) ?></title>
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

        .delivery-section {
            background: rgba(59, 130, 246, 0.05);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }

        .delivery-title {
            font-size: 14px;
            font-weight: 600;
            color: #93c5fd;
            margin-bottom: 16px;
        }

        .delivery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
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
            <div class="title">Edit Data Logistik</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <div class="form-container">
                <div class="form-header">
                    <div class="form-title">Edit Item Logistik</div>
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
                            <label class="label required" for="part_names">Part Names</label>
                            <input class="input" type="text" id="part_names" name="part_names" value="<?= h($old['part_names']) ?>" required>
                            <?php if (isset($errors['part_names'])): ?>
                                <div class="error"><?= h($errors['part_names']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="marking">Marking</label>
                            <input class="input" type="text" id="marking" name="marking" value="<?= h($old['marking']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="qty">Qty (Pcs)</label>
                            <input class="input" type="number" id="qty" name="qty" value="<?= h($old['qty']) ?>" min="0" step="1">
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
                            <small style="color: var(--muted); font-size: 11px;">Akan dihitung otomatis jika kosong (Qty × Weight)</small>
                        </div>
                    </div>

                    <?php if (!empty($deliveries)): ?>
                        <div class="delivery-section">
                            <div class="delivery-title">
                                <i class="bi bi-truck"></i> Data Pengiriman (Delivery)
                            </div>
                            <div class="delivery-grid">
                                <?php foreach ($deliveries as $delivery): ?>
                                    <div class="field">
                                        <label class="label" for="delivery_<?= $delivery['id'] ?>">
                                            <?= h($delivery['po_number']) ?>
                                        </label>
                                        <input class="input"
                                            type="number"
                                            id="delivery_<?= $delivery['id'] ?>"
                                            name="delivery_<?= $delivery['id'] ?>"
                                            value="<?= h($delivery['delivery_weight']) ?>"
                                            min="0"
                                            step="0.01"
                                            placeholder="Weight (Kg)">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="field">
                            <label class="label" for="untek">UNTEK</label>
                            <input class="input" type="text" id="untek" name="untek" value="<?= h($old['untek']) ?>">
                        </div>

                        <div class="field">
                            <label class="label" for="ready_cgi">READY CGI</label>
                            <input class="input" type="text" id="ready_cgi" name="ready_cgi" value="<?= h($old['ready_cgi']) ?>">
                        </div>
                    </div>

                    <div class="field">
                        <label class="label" for="os_dhj">O/S DHJ</label>
                        <input class="input" type="text" id="os_dhj" name="os_dhj" value="<?= h($old['os_dhj']) ?>">
                    </div>

                    <div class="field full-width">
                        <label class="label" for="remarks">Remarks</label>
                        <textarea class="input textarea" id="remarks" name="remarks"><?= h($old['remarks']) ?></textarea>
                    </div>

                    <div class="actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Update Data
                        </button>
                        <a href="logistik_list.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-secondary">
                            <i class="bi bi-x-lg"></i>
                            Batal
                        </a>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Edit Logistik</footer>
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

        // Auto-calculate total weight
        const qtyInput = document.getElementById('qty');
        const weightInput = document.getElementById('unit_weight_kg');
        const totalWeightInput = document.getElementById('total_weight_kg');

        function calculateTotal() {
            const qty = parseFloat(qtyInput.value) || 0;
            const weight = parseFloat(weightInput.value) || 0;

            if (qty > 0 && weight > 0) {
                totalWeightInput.value = (qty * weight).toFixed(3);
            }
        }

        qtyInput.addEventListener('input', calculateTotal);
        weightInput.addEventListener('input', calculateTotal);
    </script>
</body>

</html>