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
$successMsg = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['file'] = 'Error uploading file';
    } else {
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExt, ['csv', 'xlsx', 'xls'])) {
            $errors['file'] = 'Only CSV, XLS, or XLSX files are allowed';
        } else {
            try {
                $uploadDir = __DIR__ . '/uploads/logistik/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = time() . '_' . basename($file['name']);
                $uploadPath = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    // Process the file
                    $imported = processLogistikImport($uploadPath, $fileExt, $ponCode);

                    if ($imported > 0) {
                        // Update PON status
                        update('pon', ['logistik_imported' => 1], 'pon = :pon', ['pon' => $ponCode]);

                        header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&imported=' . $imported);
                        exit;
                    } else {
                        $errors['file'] = 'No valid data found in file';
                    }
                } else {
                    $errors['file'] = 'Failed to upload file';
                }
            } catch (Exception $e) {
                $errors['file'] = 'Error processing file: ' . $e->getMessage();
                error_log('Logistik Import Error: ' . $e->getMessage());
            }
        }
    }
}

/**
 * Process logistik import file
 */
function processLogistikImport($filePath, $fileExt, $ponCode)
{
    $data = [];

    if ($fileExt === 'csv') {
        // Process CSV
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Cannot open CSV file');
        }

        $headers = fgetcsv($handle); // Read header row

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 9 && !empty($row[1])) { // At least basic columns
                $itemData = [
                    'no' => (int)($row[0] ?? 0),
                    'part_names' => trim($row[1] ?? ''),
                    'marking' => trim($row[2] ?? ''),
                    'qty' => (int)($row[3] ?? 0),
                    'dimensions' => trim($row[4] ?? ''),
                    'length_mm' => (float)($row[5] ?? 0),
                    'unit_weight_kg' => (float)($row[6] ?? 0),
                    'total_weight_kg' => (float)($row[7] ?? 0),
                    'untek' => trim($row[9] ?? ''),
                    'ready_cgi' => trim($row[count($row) - 3] ?? ''),
                    'os_dhj' => trim($row[count($row) - 2] ?? ''),
                    'remarks' => trim($row[count($row) - 1] ?? '')
                ];

                // Parse delivery columns (columns 8 to n-4)
                $deliveries = [];
                for ($i = 8; $i < count($row) - 4; $i++) {
                    if (!empty($row[$i]) && !empty($headers[$i])) {
                        // Extract PO number from header (format: "02202508220031 - 1.522,56")
                        preg_match('/(\d+)\s*-\s*(.+)/', $headers[$i], $matches);
                        if (count($matches) >= 2) {
                            $deliveries[] = [
                                'po_number' => $matches[1],
                                'delivery_weight' => parseWeight($row[$i])
                            ];
                        }
                    }
                }

                $data[] = [
                    'item' => $itemData,
                    'deliveries' => $deliveries
                ];
            }
        }
        fclose($handle);
    } else {
        // Process Excel
        require_once __DIR__ . '/vendor/autoload.php';

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Get headers
        $headers = $rows[0];

        // Skip header row
        array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) >= 9 && !empty($row[1])) {
                $itemData = [
                    'no' => (int)($row[0] ?? 0),
                    'part_names' => trim($row[1] ?? ''),
                    'marking' => trim($row[2] ?? ''),
                    'qty' => (int)($row[3] ?? 0),
                    'dimensions' => trim($row[4] ?? ''),
                    'length_mm' => (float)($row[5] ?? 0),
                    'unit_weight_kg' => (float)($row[6] ?? 0),
                    'total_weight_kg' => (float)($row[7] ?? 0),
                    'untek' => trim($row[9] ?? ''),
                    'ready_cgi' => trim($row[count($row) - 3] ?? ''),
                    'os_dhj' => trim($row[count($row) - 2] ?? ''),
                    'remarks' => trim($row[count($row) - 1] ?? '')
                ];

                // Parse delivery columns
                $deliveries = [];
                for ($i = 8; $i < count($row) - 4; $i++) {
                    if (!empty($row[$i]) && !empty($headers[$i])) {
                        preg_match('/(\d+)\s*-\s*(.+)/', $headers[$i], $matches);
                        if (count($matches) >= 2) {
                            $deliveries[] = [
                                'po_number' => $matches[1],
                                'delivery_weight' => parseWeight($row[$i])
                            ];
                        }
                    }
                }

                $data[] = [
                    'item' => $itemData,
                    'deliveries' => $deliveries
                ];
            }
        }
    }

    // Insert data to database
    $imported = 0;
    foreach ($data as $item) {
        $itemData = $item['item'];
        $itemData['pon'] = $ponCode;

        try {
            $itemId = insert('logistik_items', $itemData);

            // Insert deliveries
            foreach ($item['deliveries'] as $delivery) {
                $delivery['logistik_item_id'] = $itemId;
                insert('logistik_deliveries', $delivery);
            }

            $imported++;
        } catch (Exception $e) {
            error_log('Error inserting logistik item: ' . $e->getMessage());
        }
    }

    return $imported;
}

/**
 * Parse weight string (handle formats like "1.522,56" or "1522.56")
 */
function parseWeight($value)
{
    if (empty($value)) return 0;

    $value = trim($value);
    // Replace comma with dot for decimal
    $value = str_replace(',', '.', $value);
    // Remove thousands separator
    $value = str_replace('.', '', $value);
    // Add decimal point back
    if (strpos($value, '.') === false && strlen($value) > 2) {
        $value = substr($value, 0, -2) . '.' . substr($value, -2);
    }

    return (float)$value;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Data Logistik - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        /* Same styles as fabrikasi_import.php */
        .import-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .import-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
        }

        .import-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .import-icon {
            font-size: 64px;
            color: #3b82f6;
            margin-bottom: 16px;
        }

        .import-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 8px;
        }

        .import-subtitle {
            font-size: 14px;
            color: var(--muted);
        }

        .upload-area {
            border: 2px dashed var(--border);
            border-radius: 12px;
            padding: 48px 24px;
            text-align: center;
            background: rgba(255, 255, 255, 0.02);
            transition: all 0.3s;
            cursor: pointer;
            margin-bottom: 24px;
        }

        .upload-area:hover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.05);
        }

        .upload-icon {
            font-size: 48px;
            color: var(--muted);
            margin-bottom: 16px;
        }

        .upload-text {
            font-size: 16px;
            color: var(--text);
            margin-bottom: 8px;
        }

        .upload-hint {
            font-size: 13px;
            color: var(--muted);
        }

        .file-input {
            display: none;
        }

        .template-section {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }

        .template-title {
            font-size: 14px;
            font-weight: 600;
            color: #93c5fd;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .template-list {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.8;
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

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 24px;
        }

        .error-msg {
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

        .selected-file {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 16px;
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
            <div class="title">Import Data Logistik</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>
        <main class="content">
            <div class="import-container">
                <div class="import-card">
                    <div class="import-header">
                        <div class="import-icon"><i class="bi bi-truck"></i></div>
                        <div class="import-title">Import Data Logistik</div>
                        <div class="import-subtitle">PON: <strong><?= strtoupper(h($ponCode)) ?></strong></div>
                    </div>
                    <?php if (isset($errors['file'])): ?>
                        <div class="error-msg">
                            <i class="bi bi-exclamation-circle"></i>
                            <?= h($errors['file']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="template-section">
                        <div class="template-title">
                            <i class="bi bi-info-circle"></i>
                            Format File yang Dibutuhkan
                        </div>
                        <div class="template-list">
                            Kolom yang harus ada:<br>
                            1. No | 2. Part Names | 3. Marking | 4. Qty (Pcs) | 5. Dimensions (mm)<br>
                            6. Length (mm) | 7. Unit Weight (Kg/Pc) | 8. Total Weight (Kg)<br>
                            9-N. Kolom Delivery (format: "PO_NUMBER - WEIGHT")<br>
                            N-3. UNTEK | N-2. READY CGI | N-1. O/S DHJ | N. Remarks
                        </div>
                        <div style="margin-top: 12px;">
                            <a href="logistik_template.php" class="btn btn-secondary">
                                <i class="bi bi-download"></i>
                                Download Template Excel
                            </a>
                        </div>
                    </div>
                    <form method="post" enctype="multipart/form-data" id="import-form">
                        <div class="upload-area" id="upload-area" onclick="document.getElementById('file-input').click()">
                            <div class="upload-icon"><i class="bi bi-cloud-upload"></i></div>
                            <div class="upload-text">Klik untuk pilih file atau drag & drop</div>
                            <div class="upload-hint">Format: CSV, XLS, atau XLSX (Max: 10MB)</div>
                        </div>
                        <input type="file" name="import_file" id="file-input" class="file-input" accept=".csv,.xls,.xlsx" onchange="handleFileSelect(this)">
                        <div id="selected-file" style="display: none;" class="selected-file">
                            <i class="bi bi-file-earmark-check"></i>
                            <span id="file-name"></span>
                        </div>
                        <div class="actions">
                            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
                                <i class="bi bi-upload"></i>
                                Import Data
                            </button>
                            <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=Logistik" class="btn btn-secondary">
                                <i class="bi bi-x-lg"></i>
                                Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>
        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Import Data Logistik</footer>
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

        function handleFileSelect(input) {
            const file = input.files[0];
            if (file) {
                document.getElementById('file-name').textContent = file.name;
                document.getElementById('selected-file').style.display = 'flex';
                document.getElementById('submit-btn').disabled = false;
            }
        }
        const uploadArea = document.getElementById('upload-area');
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(e => uploadArea.addEventListener(e, ev => {
            ev.preventDefault();
            ev.stopPropagation();
        }, false));
        ['dragenter', 'dragover'].forEach(e => uploadArea.addEventListener(e, () => uploadArea.classList.add('dragover'), false));
        ['dragleave', 'drop'].forEach(e => uploadArea.addEventListener(e, () => uploadArea.classList.remove('dragover'), false));
        uploadArea.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('file-input').files = files;
                handleFileSelect(document.getElementById('file-input'));
            }
        }, false);
    </script>
</body>

</html>