<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  header('Location: index.php');
  exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'PON';

$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Handle export requests
if (isset($_GET['export'])) {
  $exportType = $_GET['export'];
  require_once 'export_pon.php';
  exit;
}

// Handle import form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
  $importResult = handleImportFile($_FILES['import_file']);
  if ($importResult['success']) {
    header('Location: pon.php?imported=1&count=' . $importResult['count']);
    exit;
  } else {
    header('Location: pon.php?error=import_failed&message=' . urlencode($importResult['message']));
    exit;
  }
}

// Handle delete request first
if (isset($_GET['delete'])) {
  $delJobNo = $_GET['delete'];
  try {
    delete('pon', 'job_no = :job_no', ['job_no' => $delJobNo]);
    header('Location: pon.php?deleted=1');
    exit;
  } catch (Exception $e) {
    header('Location: pon.php?error=delete_failed');
    exit;
  }
}

// Muat PON dari database
$pons = fetchAll("
    SELECT * 
    FROM pon 
    ORDER BY project_start DESC, created_at DESC
");

// OPTIMIZED: Hitung total weight dengan efficient queries
try {
  $ponCodes = array_column($pons, 'pon');
  if (!empty($ponCodes)) {
    $placeholders = str_repeat('?,', count($ponCodes) - 1) . '?';

    // Single query untuk fabrikasi weights
    $fabrikasiWeights = fetchAll("
            SELECT pon, COALESCE(SUM(total_weight_kg), 0) as total_weight 
            FROM fabrikasi_items 
            WHERE pon IN ($placeholders)
            GROUP BY pon
        ", $ponCodes);

    // Single query untuk logistik weights
    $logistikWeights = fetchAll("
            SELECT pon, COALESCE(SUM(total_weight_kg), 0) as total_weight 
            FROM logistik_workshop 
            WHERE pon IN ($placeholders)
            GROUP BY pon
        ", $ponCodes);

    // Convert to associative arrays untuk fast lookup
    $fabrikasiMap = array_column($fabrikasiWeights, 'total_weight', 'pon');
    $logistikMap = array_column($logistikWeights, 'total_weight', 'pon');

    // Hitung berat tanpa query dalam loop
    foreach ($pons as &$pon) {
      $ponCode = $pon['pon'];
      $fabWeight = $fabrikasiMap[$ponCode] ?? 0;
      $logWeight = $logistikMap[$ponCode] ?? 0;
      $pon['berat_calculated'] = (float)$fabWeight + (float)$logWeight;
    }
    unset($pon);
  }
} catch (Exception $e) {
  error_log("Weight calculation error: " . $e->getMessage());
  // Fallback
  foreach ($pons as &$pon) {
    $pon['berat_calculated'] = 0;
  }
  unset($pon);
}

// Hitung statistics
$totalBerat = array_sum(array_map(fn($r) => (float) $r['berat_calculated'], $pons));
$avgProgress = count($pons) > 0 ? (int) round(array_sum(array_map(fn($r) => (int) $r['progress'], $pons)) / count($pons)) : 0;

// Hitung status distribution
$statusCounts = [
  'Progress' => 0,
  'Selesai' => 0,
  'Pending' => 0,
  'Delayed' => 0
];

foreach ($pons as $pon) {
  $status = $pon['status'];
  if (isset($statusCounts[$status])) {
    $statusCounts[$status]++;
  }
}

// DYNAMIC Material distribution - TAMPILKAN SEMUA MATERIAL
$materialData = [];
$materialLabels = [];
$materialColors = [];

// Color palette yang lebih banyak untuk berbagai material
$colorPalette = [
  '#3b82f6',
  '#8b5cf6',
  '#06b6d4',
  '#f59e0b',
  '#10b981',
  '#ef4444',
  '#84cc16',
  '#f97316',
  '#a855f7',
  '#ec4899',
  '#14b8a6',
  '#f43f5e',
  '#eab308',
  '#22c55e',
  '#06b6d4'
];

// Kumpulkan semua material yang unik
foreach ($pons as $pon) {
  $material = $pon['material_type'] ?: 'Unknown';
  if (!isset($materialData[$material])) {
    $materialData[$material] = 0;
  }
  $materialData[$material]++;
}

// Urutkan berdasarkan jumlah project (descending) dan ambil top 10 untuk menghindari overcrowding
arsort($materialData);
$topMaterials = array_slice($materialData, 0, 10, true);

// Siapkan labels dan colors
foreach ($topMaterials as $material => $count) {
  $materialLabels[] = $material;
  $materialColors[] = $colorPalette[count($materialLabels) % count($colorPalette)];
}

// Hitung "Others" jika ada lebih dari 10 material
$otherCount = 0;
if (count($materialData) > 10) {
  $otherMaterials = array_slice($materialData, 10, null, true);
  $otherCount = array_sum($otherMaterials);

  if ($otherCount > 0) {
    $materialLabels[] = 'Lainnya';
    $materialColors[] = '#6b7280'; // Gray color for Others
    $topMaterials['Lainnya'] = $otherCount;
  }
}

// Helper function untuk progress color
function getProgressColor($progress)
{
  if ($progress >= 90) return '#10b981';
  if ($progress >= 70) return '#3b82f6';
  if ($progress >= 50) return '#f59e0b';
  if ($progress >= 30) return '#f97316';
  return '#ef4444';
}

// Format date untuk display
function formatDate($date)
{
  if (!$date) return '-';
  return date('d/m/Y', strtotime($date));
}

// Function untuk handle import file
function handleImportFile($file)
{
  if ($file['error'] !== UPLOAD_ERR_OK) {
    return ['success' => false, 'message' => 'Error uploading file'];
  }

  $allowedTypes = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
  if (!in_array($file['type'], $allowedTypes)) {
    return ['success' => false, 'message' => 'Invalid file type. Only CSV and Excel files are allowed.'];
  }

  $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
  if (!in_array(strtolower($fileExtension), ['csv', 'xlsx', 'xls'])) {
    return ['success' => false, 'message' => 'Invalid file extension. Only .csv, .xlsx, .xls files are allowed.'];
  }

  try {
    // Untuk simplicity, kita handle CSV dulu
    // Untuk Excel, butuh library seperti PhpSpreadsheet
    if (strtolower($fileExtension) === 'csv') {
      return importFromCSV($file['tmp_name']);
    } else {
      return ['success' => false, 'message' => 'Excel import feature coming soon. Please use CSV format.'];
    }
  } catch (Exception $e) {
    return ['success' => false, 'message' => 'Import error: ' . $e->getMessage()];
  }
}

function importFromCSV($filePath)
{
  $handle = fopen($filePath, 'r');
  if (!$handle) {
    return ['success' => false, 'message' => 'Cannot open file'];
  }

  $importedCount = 0;
  $errors = [];
  $firstRow = true;

  while (($data = fgetcsv($handle)) !== FALSE) {
    if ($firstRow) {
      $firstRow = false;
      continue; // Skip header row
    }

    if (count($data) < 5) continue; // Minimal required fields

    try {
      $ponData = [
        'job_no' => $data[0] ?? '',
        'pon' => $data[1] ?? '',
        'client' => $data[2] ?? '',
        'nama_proyek' => $data[3] ?? '',
        'project_manager' => $data[4] ?? '',
        'qty' => (int)($data[5] ?? 1),
        'progress' => (int)($data[6] ?? 0),
        'date_pon' => $data[7] ? date('Y-m-d', strtotime($data[7])) : null,
        'date_finish' => $data[8] ? date('Y-m-d', strtotime($data[8])) : null,
        'status' => $data[9] ?? 'Progress',
        'alamat_kontrak' => $data[10] ?? '',
        'no_contract' => $data[11] ?? '',
        'contract_date' => $data[12] ? date('Y-m-d', strtotime($data[12])) : null,
        'project_start' => $data[13] ? date('Y-m-d', strtotime($data[13])) : null,
        'subject' => $data[14] ?? '',
        'material_type' => $data[15] ?? '',
        'fabrikasi_imported' => 0,
        'logistik_imported' => 0,
      ];

      // Cek duplikasi job_no
      $existing = fetchOne('SELECT id FROM pon WHERE job_no = ?', [$ponData['job_no']]);
      if (!$existing) {
        insert('pon', $ponData);
        $importedCount++;
      }
    } catch (Exception $e) {
      $errors[] = 'Row ' . ($importedCount + 1) . ': ' . $e->getMessage();
    }
  }

  fclose($handle);

  if ($importedCount > 0) {
    return ['success' => true, 'count' => $importedCount, 'errors' => $errors];
  } else {
    return ['success' => false, 'message' => 'No data imported. ' . implode('; ', $errors)];
  }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PON - <?= h($appName) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
  <link rel="stylesheet"
    href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
  <link rel="stylesheet"
    href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .table-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
    }

    .search-input {
      background: #0d142a;
      border: 1px solid var(--border);
      color: var(--text);
      padding: 10px 12px;
      border-radius: 8px;
      min-width: 280px;
      font-size: 14px;
    }

    .search-input::placeholder {
      color: var(--muted);
    }

    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #1d4ed8;
      border: 1px solid #3b82f6;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-primary:hover {
      background: #1e40af;
      transform: translateY(-1px);
    }

    .btn-secondary {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: transparent;
      border: 1px solid var(--border);
      color: var(--text);
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 500;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-secondary:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .btn-success {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #059669;
      border: 1px solid #10b981;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-success:hover {
      background: #047857;
      transform: translateY(-1px);
    }

    .btn-warning {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #f59e0b;
      border: 1px solid #fbbf24;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.2s;
    }

    .btn-warning:hover {
      background: #d97706;
      transform: translateY(-1px);
    }

    .btn-danger {
      background: #dc2626;
      border-color: #ef4444;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .btn-danger:hover {
      background: #b91c1c;
    }

    .btn-warning-sm {
      background: #f59e0b;
      border-color: #fbbf24;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .btn-info {
      background: #0ea5e9;
      border-color: #38bdf8;
      padding: 6px 10px;
      border-radius: 6px;
      color: white;
      text-decoration: none;
      font-size: 12px;
    }

    .action-buttons {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    /* Export & Import Dropdown Styles */
    .action-dropdown {
      position: relative;
      display: inline-block;
    }

    .export-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #059669;
      border: 1px solid #10b981;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .export-btn:hover {
      background: #047857;
    }

    .import-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #7c3aed;
      border: 1px solid #8b5cf6;
      color: #fff;
      text-decoration: none;
      padding: 10px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .import-btn:hover {
      background: #6d28d9;
    }

    .action-dropdown-content {
      display: none;
      position: absolute;
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      min-width: 180px;
      box-shadow: 0 8px 16px rgba(0, 0, 0, 0.3);
      z-index: 1000;
      margin-top: 5px;
      overflow: hidden;
      right: 0;
    }

    .action-dropdown-content.show {
      display: block;
      animation: fadeIn 0.2s ease;
    }

    .action-option {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 12px 16px;
      color: var(--text);
      text-decoration: none;
      transition: background 0.2s;
      border-bottom: 1px solid var(--border);
      cursor: pointer;
    }

    .action-option:last-child {
      border-bottom: none;
    }

    .action-option:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .action-option i {
      font-size: 14px;
      width: 16px;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Import Modal Styles */
    .import-modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.7);
      z-index: 9999;
      justify-content: center;
      align-items: center;
    }

    .import-modal.show {
      display: flex;
    }

    .import-modal-content {
      background: var(--card-bg);
      border-radius: 12px;
      padding: 30px;
      width: 90%;
      max-width: 500px;
      border: 1px solid var(--border);
    }

    .import-modal-header {
      display: flex;
      justify-content: between;
      align-items: center;
      margin-bottom: 20px;
    }

    .import-modal-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text);
    }

    .import-modal-close {
      background: none;
      border: none;
      color: var(--muted);
      font-size: 20px;
      cursor: pointer;
      padding: 0;
    }

    .import-modal-close:hover {
      color: var(--text);
    }

    .file-upload-area {
      border: 2px dashed var(--border);
      border-radius: 8px;
      padding: 40px 20px;
      text-align: center;
      margin-bottom: 20px;
      transition: border-color 0.3s;
      cursor: pointer;
    }

    .file-upload-area:hover {
      border-color: #3b82f6;
    }

    .file-upload-area.dragover {
      border-color: #3b82f6;
      background: rgba(59, 130, 246, 0.05);
    }

    .file-upload-icon {
      font-size: 48px;
      color: var(--muted);
      margin-bottom: 16px;
    }

    .file-upload-text {
      color: var(--text);
      margin-bottom: 8px;
    }

    .file-upload-hint {
      color: var(--muted);
      font-size: 14px;
    }

    .file-input {
      display: none;
    }

    .import-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 20px;
    }

    /* Enhanced Table Styles */
    .project-table {
      width: 100%;
      border-collapse: collapse;
      background: var(--card-bg);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .project-table th {
      background: rgba(255, 255, 255, 0.03);
      padding: 16px 12px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--muted);
      border-bottom: 1px solid var(--border);
      text-align: left;
    }

    .project-table td {
      padding: 20px 12px;
      vertical-align: top;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    }

    .project-row:hover {
      background: rgba(255, 255, 255, 0.02);
    }

    /* Project Information Styles */
    .job-number {
      font-weight: 700;
      font-size: 14px;
      color: #3b82f6;
      margin-bottom: 4px;
    }

    .project-name {
      font-weight: 600;
      font-size: 15px;
      line-height: 1.3;
      margin-bottom: 6px;
      color: var(--text);
    }

    .project-subject {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.4;
    }

    /* Client & Location Styles */
    .client-name {
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 8px;
      color: var(--text);
    }

    .project-location {
      font-size: 13px;
      color: var(--muted);
      line-height: 1.5;
      margin-bottom: 6px;
    }

    .contract-info {
      font-size: 12px;
      color: var(--muted);
      font-family: 'Courier New', monospace;
    }

    /* Technical Specs Styles */
    .specs-grid {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .spec-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 13px;
    }

    .spec-label {
      color: var(--muted);
      font-weight: 500;
    }

    .spec-value {
      font-weight: 600;
      color: var(--text);
    }

    /* Progress Bar */
    .progress-container {
      min-width: 120px;
    }

    .progress-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 6px;
    }

    .progress-percent {
      font-weight: 700;
      font-size: 14px;
      color: var(--text);
    }

    .progress-label {
      font-size: 11px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .progress-bar {
      height: 8px;
      background: var(--border);
      border-radius: 4px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      border-radius: 4px;
      transition: width 0.3s ease;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    }

    /* Timeline Styles */
    .timeline-info {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .date-item {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      color: var(--text);
    }

    .date-item i {
      color: var(--muted);
      width: 14px;
    }

    .date-item.completed {
      color: #10b981;
    }

    .date-item.completed i {
      color: #10b981;
    }

    /* Status Badges */
    .status-badge {
      display: inline-block;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-progress {
      background: rgba(245, 158, 11, 0.15);
      color: #f59e0b;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .status-selesai {
      background: rgba(16, 185, 129, 0.15);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .status-pending {
      background: rgba(107, 114, 128, 0.15);
      color: #6b7280;
      border: 1px solid rgba(107, 114, 128, 0.3);
    }

    .status-delayed {
      background: rgba(239, 68, 68, 0.15);
      color: #ef4444;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    .delay-warning {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      color: #ef4444;
      margin-top: 6px;
      padding: 4px 8px;
      background: rgba(239, 68, 68, 0.1);
      border-radius: 4px;
    }

    /* Dashboard Cards */
    .grid-4 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .stat-card {
      transition: transform 0.2s;
      background: var(--card-bg);
      border-radius: 12px;
      padding: 20px;
      border: 1px solid var(--border);
    }

    .stat-card:hover {
      transform: translateY(-2px);
    }

    .stat-card .card-body {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .stat-icon {
      width: 54px;
      height: 54px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
    }

    .stat-content .value {
      font-size: 28px;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 4px;
    }

    .stat-content .label {
      font-size: 13px;
      color: var(--muted);
      font-weight: 500;
    }

    /* Charts Section */
    .charts-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
    }

    .chart-card {
      background: var(--card-bg);
      border-radius: 12px;
      padding: 20px;
      border: 1px solid var(--border);
    }

    .chart-header {
      display: flex;
      justify-content: between;
      align-items: center;
      margin-bottom: 16px;
    }

    .chart-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text);
    }

    .chart-container {
      position: relative;
      height: 250px;
    }

    /* Notifications */
    .notice {
      background: rgba(34, 197, 94, 0.12);
      color: #86efac;
      border: 1px solid rgba(34, 197, 94, 0.35);
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .notice i {
      font-size: 16px;
    }

    .error-notice {
      background: rgba(239, 68, 68, 0.1);
      border: 1px solid rgba(239, 68, 68, 0.3);
      color: #fecaca;
    }

    .warning-notice {
      background: rgba(245, 158, 11, 0.1);
      border: 1px solid rgba(245, 158, 11, 0.3);
      color: #fcd34d;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--muted);
    }

    .empty-state i {
      font-size: 64px;
      opacity: 0.3;
      margin-bottom: 16px;
    }

    .empty-state h3 {
      font-size: 18px;
      margin-bottom: 8px;
      color: var(--text);
    }

    .empty-state p {
      font-size: 14px;
      margin-bottom: 20px;
    }

    /* HAPUS atau COMMENT bagian ini jika ada: */
    .right-actions {
      display: flex;
      gap: 12px;
      align-items: center;
      justify-content: flex-end;
      /* HAPUS BARIS INI */
    }

    /* HAPUS atau COMMENT bagian ini jika ada: */
    .hd {
      display: flex;
      justify-content: between;
      /* INI SALAH, harusnya space-between */
      align-items: center;
    }

    /* Header Layout Styles */
    .header-content {
      display: flex;
      justify-content: space-between;
      align-items: center;
      width: 100%;
    }

    .title-section h2 {
      margin: 0;
      font-size: 24px;
      font-weight: 700;
      color: var(--text);
    }

    .actions-section {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    /* Pastikan chevron konsisten */
    .export-btn i.bi-chevron-down,
    .import-btn i.bi-chevron-down {
      font-size: 12px;
      margin-left: 4px;
    }

    /* Hapus atau update class right-actions yang lama */
    .right-actions {
      display: flex;
      gap: 12px;
      align-items: center;
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
          <span class="icon bi-bar-chart"></span> Progress Divisi
        </a>
        <a href="logout.php">
          <span class="icon bi-box-arrow-right"></span> Logout
        </a>
      </nav>
    </aside>

    <!-- Header -->
    <header class="header">
      <div class="title">Project Order Notification (PON)</div>
      <div class="meta">
        <div>Server: <?= h($server) ?></div>
        <div>PHP <?= PHP_VERSION ?></div>
        <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
      </div>
    </header>

    <!-- Content -->
    <main class="content">
      <!-- Dashboard Overview -->
      <div class="grid-4">
        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(59, 130, 246, 0.1);">
              <i class="bi bi-journal-text" style="color: #3b82f6;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= count($pons) ?></div>
              <div class="label">Total Projects</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(16, 185, 129, 0.1);">
              <i class="bi bi-check-circle" style="color: #10b981;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= $statusCounts['Selesai'] ?></div>
              <div class="label">Completed</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(245, 158, 11, 0.1);">
              <i class="bi bi-clock" style="color: #f59e0b;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= $statusCounts['Delayed'] ?></div>
              <div class="label">Delayed</div>
            </div>
          </div>
        </div>

        <div class="stat-card">
          <div class="card-body">
            <div class="stat-icon" style="background: rgba(139, 92, 246, 0.1);">
              <i class="bi bi-box-seam" style="color: #8b5cf6;"></i>
            </div>
            <div class="stat-content">
              <div class="value"><?= h(kg($totalBerat)) ?></div>
              <div class="label">Total Weight</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Charts Section -->
      <div class="charts-grid">
        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">Project Status Distribution</h3>
          </div>
          <div class="chart-container">
            <canvas id="statusChart"></canvas>
          </div>
        </div>

        <div class="chart-card">
          <div class="chart-header">
            <h3 class="chart-title">Material Types Distribution</h3>
          </div>
          <div class="chart-container">
            <canvas id="materialChart"></canvas>
          </div>
        </div>
      </div>

      <section class="section">
        <div class="hd">
          <div class="header-content">
            <div class="title-section">
              <h2>PROJECT LIST</h2>
            </div>
            <div class="actions-section">
              <!-- Export Dropdown -->
              <div class="action-dropdown">
                <button class="export-btn" onclick="toggleExportDropdown()">
                  <i class="bi bi-download"></i> Export
                  <i class="bi bi-chevron-down"></i>
                </button>
                <div class="action-dropdown-content" id="exportDropdown">
                  <a href="pon.php?export=excel" class="action-option" onclick="showExportLoading('Excel')">
                    <i class="bi bi-file-earmark-excel"></i> Export Excel
                  </a>
                  <a href="pon.php?export=pdf" class="action-option" onclick="showExportLoading('PDF')">
                    <i class="bi bi-file-earmark-pdf"></i> Export PDF
                  </a>
                  <a href="pon.php?export=csv" class="action-option" onclick="showExportLoading('CSV')">
                    <i class="bi bi-file-earmark-text"></i> Export CSV
                  </a>
                </div>
              </div>

              <!-- Import Dropdown -->
              <div class="action-dropdown">
                <button class="import-btn" onclick="toggleImportDropdown()">
                  <i class="bi bi-upload"></i> Import
                  <i class="bi bi-chevron-down"></i>
                </button>
                <div class="action-dropdown-content" id="importDropdown">
                  <div class="action-option" onclick="showImportModal()">
                    <i class="bi bi-file-earmark-plus"></i> Import Data
                  </div>
                  <a href="pon_template.php" class="action-option">
                    <i class="bi bi-download"></i> Download Template
                  </a>
                </div>
              </div>

              <a href="pon_new.php" class="btn-primary">
                <i class="bi bi-plus-circle"></i> New Project
              </a>
            </div>
          </div>
        </div>
        <div class="bd">
          <?php if (isset($_GET['added'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project baru berhasil ditambahkan.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['deleted'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project berhasil dihapus.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['updated'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              Project berhasil diupdate.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['imported'])): ?>
            <div class="notice">
              <i class="bi bi-check-circle"></i>
              <?= $_GET['count'] ?> project berhasil diimport.
            </div>
          <?php endif; ?>
          <?php if (isset($_GET['error'])): ?>
            <div class="error-notice">
              <i class="bi bi-exclamation-circle"></i>
              <?php
              if ($_GET['error'] === 'delete_failed') {
                echo 'Gagal menghapus project.';
              } elseif ($_GET['error'] === 'import_failed' && isset($_GET['message'])) {
                echo 'Gagal import: ' . h($_GET['message']);
              } else {
                echo 'Terjadi kesalahan.';
              }
              ?>
            </div>
          <?php endif; ?>

          <div class="table-actions">
            <input class="search-input" id="q" type="search" placeholder="Cari Job No/Project/Client..." oninput="filterTable()">
          </div>

          <div style="overflow: auto; border-radius: 12px;">
            <table class="project-table">
              <thead>
                <tr>
                  <th>JOB NO</th>
                  <th>PROJECT INFORMATION</th>
                  <th>CLIENT & LOCATION</th>
                  <th>TECHNICAL SPECS</th>
                  <th>PROGRESS</th>
                  <th>TIMELINE</th>
                  <th>STATUS</th>
                  <th style="text-align: right;">ACTIONS</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pons)): ?>
                  <tr>
                    <td colspan="8">
                      <div class="empty-state">
                        <i class="bi bi-journal-text"></i>
                        <h3>No Projects Found</h3>
                        <p>Get started by creating your first project or import existing data</p>
                        <div style="display: flex; gap: 10px; justify-content: center;">
                          <a href="pon_new.php" class="btn-primary">
                            <i class="bi bi-plus-circle"></i> Create Project
                          </a>
                          <button class="btn-warning" onclick="showImportModal()">
                            <i class="bi bi-upload"></i> Import Data
                          </button>
                        </div>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($pons as $r):
                    $status = strtolower($r['status']);
                    $isDelayed = $r['status'] === 'Delayed' || ($r['date_finish'] && strtotime($r['date_finish']) < time() && $r['status'] !== 'Selesai');
                  ?>
                    <tr class="project-row">
                      <!-- Job No -->
                      <td>
                        <div class="job-number"><?= h($r['job_no']) ?></div>
                      </td>

                      <!-- Project Information -->
                      <td>
                        <div class="project-name"><?= h($r['nama_proyek']) ?></div>
                        <div class="project-subject"><?= h($r['subject']) ?></div>
                      </td>

                      <!-- Client & Location -->
                      <td>
                        <div class="client-name"><?= h($r['client']) ?></div>
                        <div class="project-location">
                          <?= nl2br(h($r['alamat_kontrak'])) ?>
                        </div>
                        <div class="contract-info">
                          <?= h($r['no_contract']) ?>
                        </div>
                      </td>

                      <!-- Technical Specs -->
                      <td>
                        <div class="specs-grid">
                          <div class="spec-item">
                            <span class="spec-label">Material:</span>
                            <span class="spec-value"><?= h($r['material_type']) ?></span>
                          </div>
                          <div class="spec-item">
                            <span class="spec-label">QTY:</span>
                            <span class="spec-value"><?= h($r['qty']) ?> units</span>
                          </div>
                          <div class="spec-item">
                            <span class="spec-label">Weight:</span>
                            <span class="spec-value"><?= h(kg($r['berat_calculated'])) ?></span>
                          </div>
                        </div>
                      </td>

                      <!-- Progress -->
                      <td>
                        <div class="progress-container">
                          <div class="progress-header">
                            <span class="progress-percent"><?= (int)$r['progress'] ?>%</span>
                            <span class="progress-label">Complete</span>
                          </div>
                          <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= (int)$r['progress'] ?>%; 
                              background: <?= getProgressColor($r['progress']) ?>"></div>
                          </div>
                        </div>
                      </td>

                      <!-- Timeline -->
                      <td>
                        <div class="timeline-info">
                          <div class="date-item">
                            <i class="bi bi-calendar-check"></i>
                            <span>Start: <?= formatDate($r['project_start']) ?></span>
                          </div>
                          <div class="date-item">
                            <i class="bi bi-flag"></i>
                            <span>PON: <?= formatDate($r['date_pon']) ?></span>
                          </div>
                          <?php if ($r['date_finish']): ?>
                            <div class="date-item <?= $r['status'] === 'Selesai' ? 'completed' : '' ?>">
                              <i class="bi bi-check-circle"></i>
                              <span>Finish: <?= formatDate($r['date_finish']) ?></span>
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>

                      <!-- Status -->
                      <td>
                        <div class="status-container">
                          <span class="status-badge status-<?= $status ?>">
                            <?= h(ucfirst($r['status'])) ?>
                          </span>
                          <?php if ($isDelayed): ?>
                            <div class="delay-warning">
                              <i class="bi bi-exclamation-triangle"></i>
                              <span>Behind schedule</span>
                            </div>
                          <?php endif; ?>
                        </div>
                      </td>

                      <!-- Actions -->
                      <td>
                        <div class="action-buttons">
                          <a href="pon_view.php?job_no=<?= urlencode($r['job_no']) ?>" class="btn-info" title="View Details">
                            <i class="bi bi-eye"></i>
                          </a>
                          <a href="pon_edit.php?job_no=<?= urlencode($r['job_no']) ?>" class="btn-warning-sm" title="Edit">
                            <i class="bi bi-pencil"></i>
                          </a>
                          <a href="tasklist.php?pon=<?= urlencode($r['pon']) ?>" class="btn-primary" title="Tasks" style="padding: 6px 10px;">
                            <i class="bi bi-list-check"></i>
                          </a>
                          <a href="pon.php?delete=<?= urlencode($r['job_no']) ?>"
                            class="btn-danger"
                            title="Delete Project"
                            onclick="return confirm('Yakin ingin menghapus project <?= h($r['job_no']) ?>? Data ini tidak dapat dikembalikan.')">
                            <i class="bi bi-trash"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>

    <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Project Management</footer>
  </div>

  <!-- Import Modal -->
  <div class="import-modal" id="importModal">
    <div class="import-modal-content">
      <div class="import-modal-header">
        <h3 class="import-modal-title">Import Project Data</h3>
        <button class="import-modal-close" onclick="hideImportModal()">&times;</button>
      </div>

      <form id="importForm" method="post" enctype="multipart/form-data">
        <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('importFile').click()">
          <div class="file-upload-icon">
            <i class="bi bi-cloud-upload"></i>
          </div>
          <div class="file-upload-text">Click to upload or drag and drop</div>
          <div class="file-upload-hint">CSV, Excel (.xlsx, .xls) files only (Max 10MB)</div>
          <input type="file" id="importFile" name="import_file" class="file-input" accept=".csv,.xlsx,.xls" required onchange="handleFileSelect(this)">
        </div>

        <div id="selectedFile" style="display: none; margin-bottom: 20px;">
          <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: rgba(59, 130, 246, 0.1); border-radius: 6px;">
            <i class="bi bi-file-earmark-text" style="color: #3b82f6;"></i>
            <span id="fileName" style="flex: 1;"></span>
            <button type="button" onclick="clearFile()" style="background: none; border: none; color: var(--muted); cursor: pointer;">
              <i class="bi bi-x"></i>
            </button>
          </div>
        </div>

        <div class="import-actions">
          <button type="button" class="btn-secondary" onclick="hideImportModal()">Cancel</button>
          <button type="submit" class="btn-success" id="importSubmit" disabled>
            <i class="bi bi-upload"></i> Import Data
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Export Loading Modal -->
  <div id="exportLoading" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
    <div style="background: var(--card-bg); padding: 30px; border-radius: 12px; text-align: center; border: 1px solid var(--border);">
      <div class="spinner" style="border: 4px solid var(--border); border-top: 4px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px;"></div>
      <h3 style="color: var(--text); margin-bottom: 10px;">Preparing Export</h3>
      <p style="color: var(--muted);" id="exportMessage">Mempersiapkan file untuk diunduh...</p>
    </div>
  </div>

  <style>
    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }
  </style>

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

    // Table filtering
    function filterTable() {
      const q = (document.getElementById('q').value || '').toLowerCase();
      const rows = document.querySelectorAll('.project-table tbody tr');
      rows.forEach(tr => {
        const text = tr.innerText.toLowerCase();
        tr.style.display = text.includes(q) ? '' : 'none';
      });
    }

    // Export Dropdown functionality
    // Export Dropdown functionality
    function toggleExportDropdown() {
      const dropdown = document.getElementById('exportDropdown');
      dropdown.classList.toggle('show');
    }

    function toggleImportDropdown() {
      const dropdown = document.getElementById('importDropdown');
      dropdown.classList.toggle('show');
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(event) {
      const exportDropdown = document.getElementById('exportDropdown');
      const exportBtn = document.querySelector('.export-btn');
      const importDropdown = document.getElementById('importDropdown');
      const importBtn = document.querySelector('.import-btn');

      if (!exportBtn.contains(event.target) && !exportDropdown.contains(event.target)) {
        exportDropdown.classList.remove('show');
      }

      if (!importBtn.contains(event.target) && !importDropdown.contains(event.target)) {
        importDropdown.classList.remove('show');
      }
    });

    // Import Modal functionality
    function showImportModal() {
      document.getElementById('importModal').classList.add('show');
      document.getElementById('importDropdown').classList.remove('show');
    }

    function hideImportModal() {
      document.getElementById('importModal').classList.remove('show');
      clearFile();
    }

    function handleFileSelect(input) {
      const file = input.files[0];
      if (file) {
        const fileName = document.getElementById('fileName');
        const selectedFile = document.getElementById('selectedFile');
        const importSubmit = document.getElementById('importSubmit');

        fileName.textContent = file.name;
        selectedFile.style.display = 'block';
        importSubmit.disabled = false;

        // Validate file size (10MB max)
        if (file.size > 10 * 1024 * 1024) {
          alert('File size exceeds 10MB limit');
          clearFile();
          return;
        }
      }
    }

    function clearFile() {
      document.getElementById('importFile').value = '';
      document.getElementById('selectedFile').style.display = 'none';
      document.getElementById('importSubmit').disabled = true;
    }

    // Drag and drop functionality
    const fileUploadArea = document.getElementById('fileUploadArea');

    fileUploadArea.addEventListener('dragover', function(e) {
      e.preventDefault();
      this.classList.add('dragover');
    });

    fileUploadArea.addEventListener('dragleave', function(e) {
      e.preventDefault();
      this.classList.remove('dragover');
    });

    fileUploadArea.addEventListener('drop', function(e) {
      e.preventDefault();
      this.classList.remove('dragover');

      const files = e.dataTransfer.files;
      if (files.length > 0) {
        document.getElementById('importFile').files = files;
        handleFileSelect(document.getElementById('importFile'));
      }
    });

    // Show export loading
    function showExportLoading(format) {
      const modal = document.getElementById('exportLoading');
      const message = document.getElementById('exportMessage');
      message.textContent = `Mempersiapkan file ${format} untuk diunduh...`;
      modal.style.display = 'flex';

      // Auto hide after 5 seconds (in case something goes wrong)
      setTimeout(() => {
        modal.style.display = 'none';
      }, 5000);
    }

    // Hide loading when page is about to unload (export starting)
    window.addEventListener('beforeunload', function() {
      document.getElementById('exportLoading').style.display = 'none';
    });

    // Close modal when clicking outside
    document.getElementById('importModal').addEventListener('click', function(e) {
      if (e.target === this) {
        hideImportModal();
      }
    });

    // Charts
    document.addEventListener('DOMContentLoaded', function() {
      // Status Distribution Chart
      const statusCtx = document.getElementById('statusChart').getContext('2d');
      const statusChart = new Chart(statusCtx, {
        type: 'doughnut',
        data: {
          labels: ['In Progress', 'Completed', 'Pending', 'Delayed'],
          datasets: [{
            data: [<?= $statusCounts['Progress'] ?>, <?= $statusCounts['Selesai'] ?>, <?= $statusCounts['Pending'] ?>, <?= $statusCounts['Delayed'] ?>],
            backgroundColor: ['#f59e0b', '#10b981', '#6b7280', '#ef4444'],
            borderWidth: 0,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                color: '#94a3b8',
                font: {
                  size: 11
                }
              }
            }
          },
          cutout: '60%'
        }
      });

      // Material Distribution Chart - DYNAMIC
      const materialCtx = document.getElementById('materialChart').getContext('2d');
      const materialChart = new Chart(materialCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($materialLabels) ?>,
          datasets: [{
            label: 'Jumlah Project',
            data: <?= json_encode(array_values($topMaterials)) ?>,
            backgroundColor: <?= json_encode($materialColors) ?>,
            borderWidth: 0,
            borderRadius: 4,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  return `${context.dataset.label}: ${context.parsed.y}`;
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: {
                color: 'rgba(255, 255, 255, 0.1)'
              },
              ticks: {
                color: '#94a3b8',
                callback: function(value) {
                  return value; // Tampilkan angka asli
                }
              },
              title: {
                display: true,
                text: 'Jumlah Project',
                color: '#94a3b8'
              }
            },
            x: {
              grid: {
                display: false
              },
              ticks: {
                color: '#94a3b8',
                maxRotation: 45,
                minRotation: 45
              },
              title: {
                display: true,
                text: 'Jenis Material',
                color: '#94a3b8'
              }
            }
          }
        }
      });
    });
  </script>
</body>

</html>