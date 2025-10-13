<?php
// File: pon_export.php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$exportType = $_GET['export'] ?? '';

// INCLUDE pon.php untuk menggunakan function yang sudah ada
require_once 'pon.php';

// Load data PON dengan progress terintegrasi
$pons = fetchAll("
    SELECT p.* 
    FROM pon p 
    ORDER BY p.project_start DESC, p.created_at DESC
");

// Hitung progress terintegrasi untuk setiap PON
foreach ($pons as &$pon) {
    $ponCode = $pon['pon'];
    $pon['integrated_progress'] = getIntegratedProgress($ponCode);
}
unset($pon);

// Hitung berat
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
    if (!$date || $date == '0000-00-00') return '-';
    return date('d/m/Y', strtotime($date));
}

function getStatusColor($status)
{
    $status = strtolower($status);
    switch ($status) {
        case 'selesai':
            return '#10b981';
        case 'progress':
            return '#3b82f6';
        case 'pending':
            return '#f59e0b';
        case 'delayed':
            return '#ef4444';
        default:
            return '#6b7280';
    }
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
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="PON_Data_' . date('Y-m-d_H-i') . '.xls"');

    $companyLogo = '/assets/img/Logo.jpg';

    echo "<!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>PON Data Export</title>
        <style>
            /* ... (same Excel styles as before) ... */
        </style>
    </head>
    <body>
        <div class='header'>
            <div class='company-info'>
                <div class='logo'>
                    <img src='" . $companyLogo . "' alt='Company Logo' style='max-width: 120px; max-height: 60px;'>
                </div>
                <div class='company-details'>
                    <div class='company-name'>" . APP_NAME . "</div>
                    <div class='company-address'>Project Management System</div>
                </div>
            </div>
            <div class='report-title'>PROJECT ORDER NOTIFICATION (PON) REPORT</div>
            <div class='report-info'>Generated on: " . date('d F Y H:i:s') . "</div>
            <div class='report-info'>Total Projects: " . count($data) . "</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width='80'>Job No</th>
                    <th width='100'>PON Code</th>
                    <th width='200'>Project Name</th>
                    <th width='120'>Client</th>
                    <th width='100'>Material</th>
                    <th width='60' class='text-center'>QTY</th>
                    <th width='80' class='text-center'>Weight (Kg)</th>
                    <th width='100' class='text-center'>Progress</th>
                    <th width='90' class='text-center'>Status</th>
                    <th width='80' class='text-center'>Start Date</th>
                    <th width='80' class='text-center'>PON Date</th>
                </tr>
            </thead>
            <tbody>";

    $totalWeight = 0;
    $statusCounts = ['Selesai' => 0, 'Progress' => 0, 'Pending' => 0, 'Delayed' => 0];

    foreach ($data as $row) {
        $status = $row['status'] ?: 'Progress';
        $statusColor = getStatusColor($status);
        $progressColor = getStatusColor($row['integrated_progress'] >= 90 ? 'Selesai' : ($row['integrated_progress'] >= 70 ? 'Progress' : ($row['integrated_progress'] >= 50 ? 'Pending' : 'Delayed')));

        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
        $totalWeight += (float)$row['berat_calculated'];

        echo "<tr>
            <td class='font-bold'>" . h($row['job_no']) . "</td>
            <td>" . h($row['pon']) . "</td>
            <td>" . h($row['nama_proyek']) . "</td>
            <td>" . h($row['client']) . "</td>
            <td>" . h($row['material_type']) . "</td>
            <td class='text-center'>" . h($row['qty']) . "</td>
            <td class='text-right'>" . number_format($row['berat_calculated'], 2) . "</td>
            <td>
                <div style='margin-bottom: 4px; font-size: 11px; font-weight: bold;'>" . $row['integrated_progress'] . "%</div>
                <div class='progress-bar'>
                    <div class='progress-fill' style='width: " . $row['integrated_progress'] . "%; background: " . $progressColor . ";'></div>
                </div>
            </td>
            <td class='text-center'>
                <span class='status-badge' style='background: " . $statusColor . "; color: white;'>" . h($status) . "</span>
            </td>
            <td class='text-center'>" . formatDateExport($row['project_start']) . "</td>
            <td class='text-center'>" . formatDateExport($row['date_pon']) . "</td>
        </tr>";
    }

    echo "</tbody>
        </table>

        <div class='summary'>
            <div class='summary-row'>
                <span class='font-bold'>TOTAL PROJECTS:</span>
                <span class='font-bold'>" . count($data) . "</span>
            </div>
            <div class='summary-row'>
                <span>TOTAL WEIGHT:</span>
                <span class='font-bold'>" . number_format($totalWeight, 2) . " kg</span>
            </div>
            <div class='summary-row'>
                <span>STATUS DISTRIBUTION:</span>
                <span>
                    Selesai: " . $statusCounts['Selesai'] . " | 
                    Progress: " . $statusCounts['Progress'] . " | 
                    Pending: " . $statusCounts['Pending'] . " | 
                    Delayed: " . $statusCounts['Delayed'] . "
                </span>
            </div>
            <div class='summary-row'>
                <span>AVERAGE PROGRESS:</span>
                <span class='font-bold'>" . (count($data) > 0 ? round(array_sum(array_column($data, 'integrated_progress')) / count($data)) : 0) . "%</span>
            </div>
        </div>

        <div class='footer'>
            <p>© " . date('Y') . " " . APP_NAME . " - Project Management System</p>
            <p>This report was generated automatically from the system</p>
        </div>
    </body>
    </html>";
    exit;
}

function exportPDF($data)
{
    try {
        // Include DomPDF library
        require_once 'vendor/autoload.php';

        // Create DomPDF instance
        $dompdf = new Dompdf\Dompdf();
        $dompdf->setPaper('A4', 'landscape');

        // Generate HTML content
        $html = generatePDFHTML($data);

        // Load HTML content
        $dompdf->loadHtml($html);

        // Render PDF
        $dompdf->render();

        // Output PDF
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="PON_Report_' . date('Y-m-d_H-i') . '.pdf"');
        echo $dompdf->output();
        exit;
    } catch (Exception $e) {
        // Fallback ke Excel jika PDF gagal
        error_log('PDF Export Error: ' . $e->getMessage());
        header('Location: pon.php?error=pdf_failed&message=' . urlencode($e->getMessage()));
        exit;
    }
}

function generatePDFHTML($data)
{
    $companyLogo = 'https://via.placeholder.com/150x50/3b82f6/ffffff?text=PT.WIRATAMA';

    $totalWeight = 0;
    $statusCounts = ['Selesai' => 0, 'Progress' => 0, 'Pending' => 0, 'Delayed' => 0];

    foreach ($data as $row) {
        $status = $row['status'] ?: 'Progress';
        if (isset($statusCounts[$status])) {
            $statusCounts[$status]++;
        }
        $totalWeight += (float)$row['berat_calculated'];
    }

    $tableRows = '';
    foreach ($data as $row) {
        $status = $row['status'] ?: 'Progress';
        $statusColor = getStatusColor($status);

        $tableRows .= "
        <tr>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px;'><strong>" . h($row['job_no']) . "</strong></td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px;'>" . h($row['pon']) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px;'>" . h($row['nama_proyek']) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px;'>" . h($row['client']) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: center;'>" . h($row['material_type']) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: center;'>" . h($row['qty']) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: right;'>" . number_format($row['berat_calculated'], 2) . "</td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: center;'><strong>" . $row['integrated_progress'] . "%</strong></td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: center; background: " . $statusColor . "; color: white;'><strong>" . h($status) . "</strong></td>
            <td style='border: 1px solid #ddd; padding: 6px; font-size: 9px; text-align: center;'>" . formatDateExport($row['project_start']) . "</td>
        </tr>";
    }

    $avgProgress = count($data) > 0 ? round(array_sum(array_column($data, 'integrated_progress')) / count($data)) : 0;

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>PON Report</title>
        <style>
            body { 
                font-family: DejaVu Sans, Arial, sans-serif; 
                margin: 15px;
                color: #333;
                font-size: 12px;
            }
            .header { 
                text-align: center; 
                margin-bottom: 20px;
                border-bottom: 2px solid #3b82f6;
                padding-bottom: 15px;
            }
            .company-info {
                margin-bottom: 10px;
            }
            .company-name {
                font-size: 16px;
                font-weight: bold;
                color: #1e40af;
                margin-bottom: 5px;
            }
            .company-subtitle {
                font-size: 11px;
                color: #64748b;
            }
            .report-title {
                font-size: 14px;
                font-weight: bold;
                color: #1e293b;
                margin: 10px 0;
            }
            .report-info {
                font-size: 10px;
                color: #64748b;
                margin-bottom: 5px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
                font-size: 9px;
            }
            th {
                background-color: #3b82f6;
                color: white;
                padding: 8px 6px;
                text-align: left;
                font-weight: bold;
                border: 1px solid #1d4ed8;
            }
            td {
                padding: 6px;
                border: 1px solid #ddd;
            }
            .summary {
                margin-top: 20px;
                padding: 12px;
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 4px;
                font-size: 10px;
            }
            .summary-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 4px;
            }
            .footer {
                margin-top: 20px;
                text-align: center;
                font-size: 9px;
                color: #64748b;
                border-top: 1px solid #e2e8f0;
                padding-top: 10px;
            }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .font-bold { font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='header'>
            <div class='company-info'>
                <div class='company-name'>" . APP_NAME . "</div>
                <div class='company-subtitle'>Project Management System</div>
            </div>
            <div class='report-title'>PROJECT ORDER NOTIFICATION (PON) REPORT</div>
            <div class='report-info'>Generated on: " . date('d F Y H:i:s') . "</div>
            <div class='report-info'>Total Projects: " . count($data) . "</div>
        </div>

        <table>
            <thead>
                <tr>
                    <th width='12%'>Job No</th>
                    <th width='12%'>PON Code</th>
                    <th width='20%'>Project Name</th>
                    <th width='15%'>Client</th>
                    <th width='10%'>Material</th>
                    <th width='6%'>QTY</th>
                    <th width='10%'>Weight (Kg)</th>
                    <th width='8%'>Progress</th>
                    <th width='8%'>Status</th>
                    <th width='9%'>Start Date</th>
                </tr>
            </thead>
            <tbody>
                " . $tableRows . "
            </tbody>
        </table>

        <div class='summary'>
            <div class='summary-row'>
                <span class='font-bold'>TOTAL PROJECTS:</span>
                <span class='font-bold'>" . count($data) . "</span>
            </div>
            <div class='summary-row'>
                <span>TOTAL WEIGHT:</span>
                <span class='font-bold'>" . number_format($totalWeight, 2) . " kg</span>
            </div>
            <div class='summary-row'>
                <span>STATUS DISTRIBUTION:</span>
                <span>
                    Selesai: " . $statusCounts['Selesai'] . " | 
                    Progress: " . $statusCounts['Progress'] . " | 
                    Pending: " . $statusCounts['Pending'] . " | 
                    Delayed: " . $statusCounts['Delayed'] . "
                </span>
            </div>
            <div class='summary-row'>
                <span>AVERAGE PROGRESS:</span>
                <span class='font-bold'>" . $avgProgress . "%</span>
            </div>
        </div>

        <div class='footer'>
            <p>© " . date('Y') . " " . APP_NAME . " - Project Management System</p>
            <p>This report was generated automatically from the system</p>
        </div>
    </body>
    </html>";
}

function exportCSV($data)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="PON_Data_' . date('Y-m-d_H-i') . '.csv"');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Header Information
    fputcsv($output, [APP_NAME . ' - PROJECT ORDER NOTIFICATION REPORT']);
    fputcsv($output, ['Generated on: ' . date('d F Y H:i:s')]);
    fputcsv($output, ['Total Projects: ' . count($data)]);
    fputcsv($output, []); // Empty line

    // Column Headers
    fputcsv($output, [
        'Job No',
        'PON Code',
        'Project Name',
        'Client',
        'Project Manager',
        'Material Type',
        'Quantity',
        'Total Weight (kg)',
        'Progress (%)',
        'Status',
        'Project Start',
        'PON Date',
        'Finish Date',
        'Contract No',
        'Subject',
        'Location'
    ]);

    // Data Rows
    foreach ($data as $row) {
        fputcsv($output, [
            $row['job_no'],
            $row['pon'],
            $row['nama_proyek'],
            $row['client'],
            $row['project_manager'],
            $row['material_type'],
            $row['qty'],
            number_format($row['berat_calculated'], 2),
            $row['integrated_progress'],
            $row['status'] ?: 'Progress',
            formatDateExport($row['project_start']),
            formatDateExport($row['date_pon']),
            formatDateExport($row['date_finish']),
            $row['no_contract'],
            $row['subject'],
            $row['alamat_kontrak']
        ]);
    }

    fputcsv($output, []); // Empty line

    // Summary
    $totalWeight = array_sum(array_map(fn($r) => (float) $r['berat_calculated'], $data));
    fputcsv($output, ['SUMMARY:']);
    fputcsv($output, ['Total Projects:', count($data)]);
    fputcsv($output, ['Total Weight:', number_format($totalWeight, 2) . ' kg']);

    fclose($output);
    exit;
}
?>