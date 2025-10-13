<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Purchasing';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Get data untuk dropdown
$purchaseItems = fetchAll('SELECT * FROM purchase_items WHERE is_active = 1 ORDER BY name');
$vendors = fetchAll('SELECT * FROM vendors WHERE is_active = 1 ORDER BY name');
$pons = fetchAll('SELECT * FROM pon WHERE status != "Selesai" ORDER BY pon');

// Generate nomor PO otomatis
$currentMonth = date('n'); // 1-12
$currentYear = date('Y');
$romanMonths = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
$romanMonth = $romanMonths[$currentMonth];

// Cari nomor urut terakhir bulan ini
$lastPO = fetchOne(
    "SELECT po_number FROM purchase_orders WHERE po_number LIKE ? ORDER BY id DESC LIMIT 1",
    ["PO.%/{$romanMonth}/{$currentYear}"]
);

if ($lastPO) {
    preg_match('/PO\.\s*(\d+)/', $lastPO['po_number'], $matches);
    $lastNumber = intval($matches[1]);
    $nextNumber = str_pad((string)($lastNumber + 1), 3, '0', STR_PAD_LEFT);
} else {
    $nextNumber = '001';
}

$autoPONumber = "PO. {$nextNumber}/GB/LOG-PPI/{$romanMonth}/{$currentYear}";
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tambah Purchase Order - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        .page-header {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }

        .form-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--text);
            font-size: 14px;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--text);
            font-size: 14px;
            transition: all 0.2s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            background: #0d142a;
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .readonly-input {
            background: rgba(255, 255, 255, 0.02);
            color: var(--muted);
            cursor: not-allowed;
        }

        .calculation-section {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
        }

        .calculation-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .calculation-row:last-child {
            border-bottom: none;
            font-weight: 600;
            font-size: 16px;
            color: #3b82f6;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            border: 1px solid;
            transition: all 0.2s;
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
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .input-with-unit {
            display: flex;
            align-items: center;
        }

        .input-with-unit input {
            flex: 1;
            border-right: none;
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }

        .unit-display {
            padding: 0 12px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            border-left: none;
            border-top-right-radius: 6px;
            border-bottom-right-radius: 6px;
            color: var(--muted);
            font-size: 14px;
            min-width: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .required::after {
            content: " *";
            color: #ef4444;
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
                <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
            </nav>
        </aside>

        <header class="header">
            <div class="title">Tambah Purchase Order</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <a href="task_divisions.php" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali
                    </a>
                    <div class="page-title">Tambah Purchase Order Baru</div>
                </div>
            </div>

            <!-- Form -->
            <div class="form-container">
                <form id="purchaseForm" action="purchasing_save.php" method="POST">
                    <div class="form-grid">
                        <!-- Kolom Kiri -->
                        <div>
                            <div class="form-group">
                                <label class="form-label required">Project (PON)</label>
                                <select class="form-select" name="pon_id" id="pon_id" required>
                                    <option value="">Pilih Project</option>
                                    <?php foreach ($pons as $pon): ?>
                                        <option value="<?= $pon['id'] ?>">
                                            <?= h($pon['pon']) ?> - <?= h($pon['nama_proyek']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Date PO</label>
                                <input type="date" class="form-input" name="po_date" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Purchase Item</label>
                                <select class="form-select" name="purchase_item_id" id="purchase_item" required>
                                    <option value="">Pilih Item</option>
                                    <?php foreach ($purchaseItems as $item): ?>
                                        <option value="<?= $item['id'] ?>" data-unit="<?= h($item['unit'] ?? 'pcs') ?>">
                                            <?= h($item['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Quantity</label>
                                <div class="input-with-unit">
                                    <input type="number" class="form-input" name="quantity" id="quantity" min="1" value="1" required>
                                    <div class="unit-display" id="unitDisplay">pcs</div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Harga Satuan (Rp)</label>
                                <input type="number" class="form-input" name="unit_price" id="unit_price" min="0" step="0.01" value="0" required>
                            </div>
                        </div>

                        <!-- Kolom Kanan -->
                        <div>
                            <div class="form-group">
                                <label class="form-label required">No. PO</label>
                                <input type="text" class="form-input" name="po_number" value="<?= h($autoPONumber) ?>" required>
                                <small style="color: var(--muted); font-size: 12px;">Format: PO. 037/GB/LOG-PPI/VII/2025</small>
                            </div>

                            <div class="form-group">
                                <label class="form-label required">Vendor</label>
                                <select class="form-select" name="vendor_id" id="vendor" required>
                                    <option value="">Pilih Vendor</option>
                                    <?php foreach ($vendors as $vendor): ?>
                                        <option value="<?= $vendor['id'] ?>" data-address="<?= h($vendor['address'] ?? '') ?>">
                                            <?= h($vendor['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Alamat Vendor</label>
                                <textarea class="form-textarea readonly-input" id="vendor_address" readonly placeholder="Alamat akan muncul otomatis ketika memilih vendor"></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notes/Keterangan</label>
                                <textarea class="form-textarea" name="notes" rows="3" placeholder="Tambahkan catatan atau keterangan mengenai PO ini..."></textarea>
                            </div>

                            <!-- Calculation Section -->
                            <div class="calculation-section">
                                <div class="calculation-row">
                                    <span>Total Harga:</span>
                                    <span id="totalAmount">Rp 0</span>
                                </div>
                                <div class="calculation-row">
                                    <span>Tax 11%:</span>
                                    <span id="taxAmount">Rp 0</span>
                                </div>
                                <div class="calculation-row">
                                    <span>Grand Total:</span>
                                    <span id="grandTotal">Rp 0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Hidden fields untuk kalkulasi -->
                    <input type="hidden" name="total_amount" id="totalAmountInput" value="0">
                    <input type="hidden" name="ppn" id="ppnInput" value="0">
                    <input type="hidden" name="grand_total" id="grandTotalInput" value="0">

                    <div class="form-actions">
                        <a href="purchasing_task_detail.php" class="btn btn-secondary">
                            <i class="bi bi-x"></i>
                            Batal
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check"></i>
                            Simpan Purchase Order
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Dibangun cepat dengan PHP</footer>
    </div>

    <script>
        // Update unit berdasarkan item yang dipilih
        document.getElementById('purchase_item').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const unit = selectedOption.getAttribute('data-unit') || 'pcs';
            document.getElementById('unitDisplay').textContent = unit;
        });

        // Update alamat vendor
        document.getElementById('vendor').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const address = selectedOption.getAttribute('data-address') || 'Alamat tidak tersedia';
            document.getElementById('vendor_address').value = address;
        });

        // Kalkulasi harga
        function calculateTotals() {
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;

            const totalAmount = quantity * unitPrice;
            const taxAmount = totalAmount * 0.11;
            const grandTotal = totalAmount + taxAmount;

            // Update display
            document.getElementById('totalAmount').textContent = formatCurrency(totalAmount);
            document.getElementById('taxAmount').textContent = formatCurrency(taxAmount);
            document.getElementById('grandTotal').textContent = formatCurrency(grandTotal);

            // Update hidden fields
            document.getElementById('totalAmountInput').value = totalAmount.toFixed(2);
            document.getElementById('ppnInput').value = taxAmount.toFixed(2);
            document.getElementById('grandTotalInput').value = grandTotal.toFixed(2);
        }

        function formatCurrency(amount) {
            return 'Rp ' + amount.toLocaleString('id-ID', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // Event listeners untuk kalkulasi
        document.getElementById('quantity').addEventListener('input', calculateTotals);
        document.getElementById('unit_price').addEventListener('input', calculateTotals);

        // Form validation sebelum submit
        document.getElementById('purchaseForm').addEventListener('submit', function(e) {
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;

            if (quantity <= 0) {
                alert('Quantity harus lebih dari 0');
                e.preventDefault();
                return;
            }

            if (unitPrice <= 0) {
                alert('Harga satuan harus lebih dari 0');
                e.preventDefault();
                return;
            }

            // Confirm sebelum submit
            if (!confirm('Simpan Purchase Order ini?')) {
                e.preventDefault();
            }
        });

        // Initialize calculations
        calculateTotals();

        // Clock
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
    </script>
</body>

</html>