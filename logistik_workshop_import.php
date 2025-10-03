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
                $uploadDir = __DIR__ . '/uploads/import/workshop/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = time() . '_' . basename($file['name']);
                $uploadPath = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    // Process the file
                    $imported = processWorkshopImport($uploadPath, $fileExt, $ponCode);

                    if ($imported > 0) {
                        header('Location: logistik_workshop.php?pon=' . urlencode($ponCode) . '&imported=' . $imported);
                        exit;
                    } else {
                        $errors['file'] = 'No valid data found in file';
                    }
                } else {
                    $errors['file'] = 'Failed to upload file';
                }
            } catch (Exception $e) {
                $errors['file'] = 'Error processing file: ' . $e->getMessage();
                error_log('Workshop Import Error: ' . $e->getMessage());
            }
        }
    }
}

/**
 * Process workshop import file
 */
function processWorkshopImport($filePath, $fileExt, $ponCode)
{
    $data = [];

    // Get all vendors untuk mapping
    $vendors = fetchAll('SELECT id, name FROM vendors');
    $vendorMap = [];
    foreach ($vendors as $vendor) {
        $vendorMap[strtolower(trim($vendor['name']))] = $vendor['id'];
    }

    if ($fileExt === 'csv') {
        // Process CSV
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new Exception('Cannot open CSV file');
        }

        $headers = fgetcsv($handle); // Read header row

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) >= 10 && !empty($row[1])) { // At least basic columns
                $vendorName = trim($row[8] ?? '');
                $vendorId = 0;

                // Cari vendor_id berdasarkan nama vendor
                if (!empty($vendorName)) {
                    $vendorKey = strtolower($vendorName);
                    if (isset($vendorMap[$vendorKey])) {
                        $vendorId = $vendorMap[$vendorKey];
                    } else {
                        // Jika vendor tidak ditemukan, buat vendor baru
                        $vendorId = createNewVendor($vendorName);
                        if ($vendorId > 0) {
                            // Update vendor map
                            $vendorMap[$vendorKey] = $vendorId;
                        }
                    }
                }

                $itemData = [
                    'no' => (int)($row[0] ?? 0),
                    'nama_parts' => trim($row[1] ?? ''),
                    'marking' => trim($row[2] ?? ''),
                    'qty' => (int)($row[3] ?? 0),
                    'dimensions' => trim($row[4] ?? ''),
                    'length_mm' => (float)($row[5] ?? 0),
                    'unit_weight_kg' => (float)($row[6] ?? 0),
                    'total_weight_kg' => (float)($row[7] ?? 0),
                    'vendor_id' => $vendorId, // Ganti vendor menjadi vendor_id
                    'surat_jalan_tanggal' => !empty($row[9]) ? ymd(trim($row[9])) : null,
                    'surat_jalan_nomor' => trim($row[10] ?? ''),
                    'ready_cgi' => (int)($row[11] ?? 0),
                    'os_dhj' => (int)($row[12] ?? 0),
                    'remarks' => trim($row[13] ?? ''),
                    'progress' => (int)($row[14] ?? 0),
                    'status' => trim($row[15] ?? 'Belum Terkirim')
                ];

                // Auto-calculate total weight if not provided
                if ($itemData['total_weight_kg'] == 0 && $itemData['qty'] > 0 && $itemData['unit_weight_kg'] > 0) {
                    $itemData['total_weight_kg'] = $itemData['qty'] * $itemData['unit_weight_kg'];
                }

                // Auto-set progress based on status
                if ($itemData['status'] === 'Terkirim') {
                    $itemData['progress'] = 100;
                } elseif ($itemData['status'] === 'Belum Terkirim' && $itemData['progress'] == 100) {
                    $itemData['progress'] = 0;
                }

                // Validasi status
                if (!in_array($itemData['status'], ['Terkirim', 'Belum Terkirim'])) {
                    $itemData['status'] = 'Belum Terkirim';
                }

                $data[] = $itemData;
            }
        }
        fclose($handle);
    } else {
        // Process Excel
        require_once __DIR__ . '/vendor/autoload.php';

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Skip header row
        array_shift($rows);

        foreach ($rows as $row) {
            if (count($row) >= 10 && !empty($row[1])) {
                $vendorName = trim($row[8] ?? '');
                $vendorId = 0;

                // Cari vendor_id berdasarkan nama vendor
                if (!empty($vendorName)) {
                    $vendorKey = strtolower($vendorName);
                    if (isset($vendorMap[$vendorKey])) {
                        $vendorId = $vendorMap[$vendorKey];
                    } else {
                        // Jika vendor tidak ditemukan, buat vendor baru
                        $vendorId = createNewVendor($vendorName);
                        if ($vendorId > 0) {
                            // Update vendor map
                            $vendorMap[$vendorKey] = $vendorId;
                        }
                    }
                }

                $itemData = [
                    'no' => (int)($row[0] ?? 0),
                    'nama_parts' => trim($row[1] ?? ''),
                    'marking' => trim($row[2] ?? ''),
                    'qty' => (int)($row[3] ?? 0),
                    'dimensions' => trim($row[4] ?? ''),
                    'length_mm' => (float)($row[5] ?? 0),
                    'unit_weight_kg' => (float)($row[6] ?? 0),
                    'total_weight_kg' => (float)($row[7] ?? 0),
                    'vendor_id' => $vendorId, // Ganti vendor menjadi vendor_id
                    'surat_jalan_tanggal' => !empty($row[9]) ? ymd(trim($row[9])) : null,
                    'surat_jalan_nomor' => trim($row[10] ?? ''),
                    'ready_cgi' => (int)($row[11] ?? 0),
                    'os_dhj' => (int)($row[12] ?? 0),
                    'remarks' => trim($row[13] ?? ''),
                    'progress' => (int)($row[14] ?? 0),
                    'status' => trim($row[15] ?? 'Belum Terkirim')
                ];

                // Auto-calculate total weight if not provided
                if ($itemData['total_weight_kg'] == 0 && $itemData['qty'] > 0 && $itemData['unit_weight_kg'] > 0) {
                    $itemData['total_weight_kg'] = $itemData['qty'] * $itemData['unit_weight_kg'];
                }

                // Auto-set progress based on status
                if ($itemData['status'] === 'Terkirim') {
                    $itemData['progress'] = 100;
                } elseif ($itemData['status'] === 'Belum Terkirim' && $itemData['progress'] == 100) {
                    $itemData['progress'] = 0;
                }

                // Validasi status
                if (!in_array($itemData['status'], ['Terkirim', 'Belum Terkirim'])) {
                    $itemData['status'] = 'Belum Terkirim';
                }

                $data[] = $itemData;
            }
        }
    }

    // Insert data to database
    $imported = 0;
    foreach ($data as $item) {
        $item['pon'] = $ponCode;
        $item['created_at'] = date('Y-m-d H:i:s');
        $item['updated_at'] = date('Y-m-d H:i:s');

        try {
            // Check if item already exists
            $existing = fetchOne('SELECT id FROM logistik_workshop WHERE pon = ? AND no = ?', [$ponCode, $item['no']]);

            if ($existing) {
                // Update existing - hapus created_at untuk update
                unset($item['created_at']);
                update('logistik_workshop', $item, 'id = :id', ['id' => $existing['id']]);
            } else {
                // Insert new
                insert('logistik_workshop', $item);
            }

            $imported++;
        } catch (Exception $e) {
            error_log('Error importing workshop item: ' . $e->getMessage());
            // Continue with next item even if one fails
        }
    }

    // Clean up uploaded file
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    return $imported;
}

/**
 * Create new vendor if not exists
 */
function createNewVendor($vendorName)
{
    try {
        // Cek dulu apakah vendor sudah ada (case insensitive)
        $existing = fetchOne('SELECT id FROM vendors WHERE LOWER(name) = LOWER(?)', [$vendorName]);

        if ($existing) {
            return $existing['id'];
        }

        // Insert vendor baru
        $vendorData = [
            'name' => $vendorName,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        insert('vendors', $vendorData);

        // Get the last inserted ID
        $result = fetchOne('SELECT LAST_INSERT_ID() as id');
        return $result ? (int)$result['id'] : 0;
    } catch (Exception $e) {
        error_log('Error creating vendor: ' . $e->getMessage());
        return 0;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import Workshop - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        /* CSS styles tetap sama seperti sebelumnya */
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

        .upload-area.dragover {
            border-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
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

        .vendor-info {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 8px;
            font-size: 12px;
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
            <div class="title">Import Data Workshop - <?= strtoupper(h($ponCode)) ?></div>
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
                        <div class="import-icon"><i class="bi bi-building-gear"></i></div>
                        <div class="import-title">Import Data Workshop</div>
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
                            1. No | 2. Nama Parts | 3. Marking | 4. QTY (Pcs) | 5. Dimensions (mm)<br>
                            6. Length (mm) | 7. Unit Weight (Kg/Pc) | 8. Total Weight (Kg)<br>
                            9. Vendor | 10. Surat Jalan Tanggal (DD/MM/YYYY) | 11. Surat Jalan Nomor<br>
                            12. Ready CGI | 13. O/S DHJ | 14. Remarks | 15. Progress (%) | 16. Status
                        </div>
                        <div class="vendor-info">
                            <i class="bi bi-lightbulb"></i>
                            <strong>Vendor:</strong> Nama vendor (akan otomatis dibuat jika belum ada) |
                            <strong>Status:</strong> "Terkirim" atau "Belum Terkirim"
                        </div>
                        <div style="margin-top: 12px;">
                            <a href="logistik_workshop_template.php" class="btn btn-secondary">
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
                            <a href="logistik_workshop.php?pon=<?= urlencode($ponCode) ?>" class="btn btn-secondary">
                                <i class="bi bi-x-lg"></i>
                                Batal
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Import Workshop</footer>
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

        // Drag and drop functionality
        const uploadArea = document.getElementById('upload-area');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => uploadArea.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => uploadArea.classList.remove('dragover'), false);
        });

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