<?php
// File: export_pon.php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$exportType = $_GET['export'] ?? '';

// Load data PON
$pons = fetchAll("
    SELECT *,
           CASE 
               WHEN material_type = 'AG25' THEN qty * 25
               WHEN material_type = 'AG32' THEN qty * 32
               WHEN material_type = 'AG50' THEN qty * 50
               ELSE 0
           END as berat_calculated
    FROM pon 
    ORDER BY project_start DESC, created_at DESC
");

// Hitung berat untuk setiap PON
$ponCodes = array_column($pons, 'pon');
if (!empty($ponCodes)) {
    $placeholders = str_repeat('?,', count($ponCodes) - 1) . '?';

    $fabrikasiWeights = fetchAll("
        SELECT pon, COALESCE(SUM(total_weight_kg), 0) as total_weight 
        FROM fabrikasi_items 
        WHERE pon IN ($placeholders)
        GROUP BY pon
    ", $ponCodes);

    $logistikWeights = fetchAll("
        SELECT pon, COALESCE(SUM(total_weight_kg), 0) as total_weight 
        FROM logistik_workshop 
        WHERE pon IN ($placeholders)
        GROUP BY pon
    ", $ponCodes);

    $fabrikasiMap = array_column($fabrikasiWeights, 'total_weight', 'pon');
    $logistikMap = array_column($logistikWeights, 'total_weight', 'pon');

    foreach ($pons as &$pon) {
        $ponCode = $pon['pon'];
        $fabWeight = $fabrikasiMap[$ponCode] ?? 0;
        $logWeight = $logistikMap[$ponCode] ?? 0;
        $pon['berat_calculated'] = (float)$fabWeight + (float)$logWeight;
    }
    unset($pon);
}

function formatDateExport($date)
{
    if (!$date) return '-';
    return date('d/m/Y', strtotime($date));
}

switch ($exportType) {
    case 'excel':
        exportExcel($pons);
        break;
    case 'pdf':
        exportPDF($pons);
        break;
    case 'csv':
        exportCSV($pons);
        break;
    default:
        header('Location: pon.php?error=invalid_export');
        exit;
}

function exportExcel($data)
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="pon_data_' . date('Y-m-d') . '.xlsx"');

    // Simple Excel export using HTML table (for basic functionality)
    // For advanced Excel features, consider using PhpSpreadsheet library

    echo '<table border="1">';
    echo '<tr><th colspan="10" style="background: #3b82f6; color: white; padding: 10px; font-size: 16px;">PROJECT ORDER NOTIFICATION (PON) DATA</th></tr>';
    echo '<tr><th colspan="10" style="padding: 5px;">Generated: ' . date('d/m/Y H:i:s') . '</th></tr>';
    echo '<tr style="background: #f8f9fa;">';
    echo '<th>Job No</th><th>PON</th><th>Project Name</th><th>Client</th><th>Location</th>';
    echo '<th>Material</th><th>QTY</th><th>Weight (Kg)</th><th>Progress</th><th>Status</th>';
    echo '</tr>';

    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>' . h($row['job_no']) . '</td>';
        echo '<td>' . h($row['pon']) . '</td>';
        echo '<td>' . h($row['nama_proyek']) . '</td>';
        echo '<td>' . h($row['client']) . '</td>';
        echo '<td>' . h($row['alamat_kontrak']) . '</td>';
        echo '<td>' . h($row['material_type']) . '</td>';
        echo '<td>' . h($row['qty']) . '</td>';
        echo '<td>' . h($row['berat_calculated']) . '</td>';
        echo '<td>' . h($row['progress']) . '%</td>';
        echo '<td>' . h($row['status']) . '</td>';
        echo '</tr>';
    }

    echo '</table>';
    exit;
}

function exportPDF($data)
{
    // Simple PDF export using HTML (for basic functionality)
    // For advanced PDF features, consider using TCPDF or Dompdf library

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="pon_data_' . date('Y-m-d') . '.pdf"');

    $html = '
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #3b82f6; text-align: center; }
            .header { text-align: center; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #3b82f6; color: white; padding: 10px; text-align: left; }
            td { padding: 8px; border: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            .footer { margin-top: 30px; text-align: center; color: #666; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>PROJECT ORDER NOTIFICATION (PON) DATA</h1>
            <p>Generated: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        
        <table>
            <tr>
                <th>Job No</th>
                <th>PON</th>
                <th>Project Name</th>
                <th>Client</th>
                <th>Material</th>
                <th>QTY</th>
                <th>Weight (Kg)</th>
                <th>Progress</th>
                <th>Status</th>
            </tr>';

    foreach ($data as $row) {
        $html .= '
            <tr>
                <td>' . h($row['job_no']) . '</td>
                <td>' . h($row['pon']) . '</td>
                <td>' . h($row['nama_proyek']) . '</td>
                <td>' . h($row['client']) . '</td>
                <td>' . h($row['material_type']) . '</td>
                <td>' . h($row['qty']) . '</td>
                <td>' . h($row['berat_calculated']) . '</td>
                <td>' . h($row['progress']) . '%</td>
                <td>' . h($row['status']) . '</td>
            </tr>';
    }

    $html .= '
        </table>
        
        <div class="footer">
            <p>Total Projects: ' . count($data) . ' | Generated by ' . APP_NAME . '</p>
        </div>
    </body>
    </html>';

    echo $html;
    exit;
}

function exportCSV($data)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pon_data_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Headers
    fputcsv($output, [
        'Job No',
        'PON',
        'Project Name',
        'Client',
        'Location',
        'Material',
        'QTY',
        'Weight (Kg)',
        'Progress',
        'Status',
        'Project Start',
        'PON Date',
        'Finish Date',
        'Project Manager',
        'Contract No'
    ]);

    // Data
    foreach ($data as $row) {
        fputcsv($output, [
            $row['job_no'],
            $row['pon'],
            $row['nama_proyek'],
            $row['client'],
            $row['alamat_kontrak'],
            $row['material_type'],
            $row['qty'],
            $row['berat_calculated'],
            $row['progress'] . '%',
            $row['status'],
            formatDateExport($row['project_start']),
            formatDateExport($row['date_pon']),
            formatDateExport($row['date_finish']),
            $row['project_manager'],
            $row['no_contract']
        ]);
    }

    fclose($output);
    exit;
}
