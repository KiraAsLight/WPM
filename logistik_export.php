<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';

if (!$ponCode) {
    header('Location: tasklist.php');
    exit;
}

// Get PON data
$ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
if (!$ponRecord) {
    header('Location: tasklist.php?error=pon_not_found');
    exit;
}

// Get logistik items
$items = fetchAll('SELECT * FROM logistik_items WHERE pon = ? ORDER BY no ASC', [$ponCode]);

if (empty($items)) {
    header('Location: logistik_list.php?pon=' . urlencode($ponCode) . '&error=no_data');
    exit;
}

// Get all unique PO numbers
$allDeliveries = fetchAll('SELECT DISTINCT po_number FROM logistik_deliveries 
    WHERE logistik_item_id IN (SELECT id FROM logistik_items WHERE pon = ?) 
    ORDER BY po_number ASC', [$ponCode]);
$poNumbers = array_column($allDeliveries, 'po_number');

// Get deliveries for each item
$itemsWithDeliveries = [];
foreach ($items as $item) {
    $deliveries = fetchAll('SELECT * FROM logistik_deliveries WHERE logistik_item_id = ?', [$item['id']]);
    $deliveryMap = [];
    foreach ($deliveries as $delivery) {
        $deliveryMap[$delivery['po_number']] = $delivery['delivery_weight'];
    }
    $item['deliveries'] = $deliveryMap;
    $itemsWithDeliveries[] = $item;
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Logistik Data');

// Set title
$sheet->setCellValue('A1', 'DATA LOGISTIK');
$lastColumn = chr(65 + 13 + count($poNumbers)); // Dynamic based on PO count
$sheet->mergeCells('A1:' . $lastColumn . '1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => 'F59E0B']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
]);
$sheet->getRowDimension(1)->setRowHeight(30);

// Set PON info
$sheet->setCellValue('A2', 'PON: ' . $ponCode);
$sheet->setCellValue('E2', 'Tanggal: ' . date('d/m/Y'));
$sheet->mergeCells('A2:D2');
$sheet->mergeCells('E2:' . $lastColumn . '2');
$sheet->getStyle('A2:' . $lastColumn . '2')->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
]);

// Headers
$headers = [
    'No',
    'Part Names',
    'Marking',
    'Qty (Pcs)',
    'Dimensions (mm)',
    'Length (mm)',
    'Unit Weight (Kg/Pc)',
    'Total Weight (Kg)'
];

// Add PO number columns
foreach ($poNumbers as $po) {
    $headers[] = $po;
}

// Add final columns
$headers[] = 'UNTEK';
$headers[] = 'READY CGI';
$headers[] = 'O/S DHJ';
$headers[] = 'Remarks';

$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . '4', $header);
    $col++;
}

// Style headers
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 10
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F59E0B']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A4:' . $lastColumn . '4')->applyFromArray($headerStyle);
$sheet->getRowDimension(4)->setRowHeight(30);

// Highlight delivery columns (columns I to I+count(poNumbers))
$deliveryStartCol = 'I';
$deliveryEndCol = chr(ord($deliveryStartCol) + count($poNumbers) - 1);
$deliveryStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'DBEAFE']
    ]
];
$sheet->getStyle($deliveryStartCol . '4:' . $deliveryEndCol . '4')->applyFromArray($deliveryStyle);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(30);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);

// Set delivery column widths
for ($i = 0; $i < count($poNumbers); $i++) {
    $colLetter = chr(ord('I') + $i);
    $sheet->getColumnDimension($colLetter)->setWidth(18);
}

// Set final columns widths
$untekCol = chr(ord('I') + count($poNumbers));
$readyCol = chr(ord($untekCol) + 1);
$osCol = chr(ord($readyCol) + 1);
$remarksCol = chr(ord($osCol) + 1);

$sheet->getColumnDimension($untekCol)->setWidth(12);
$sheet->getColumnDimension($readyCol)->setWidth(12);
$sheet->getColumnDimension($osCol)->setWidth(12);
$sheet->getColumnDimension($remarksCol)->setWidth(25);

// Write data
$row = 5;
$totalQty = 0;
$totalWeight = 0;

foreach ($itemsWithDeliveries as $item) {
    $col = 'A';

    // Basic columns
    $sheet->setCellValue($col++ . $row, $item['no']);
    $sheet->setCellValue($col++ . $row, $item['part_names']);
    $sheet->setCellValue($col++ . $row, $item['marking']);
    $sheet->setCellValue($col++ . $row, $item['qty']);
    $sheet->setCellValue($col++ . $row, $item['dimensions']);
    $sheet->setCellValue($col++ . $row, $item['length_mm']);
    $sheet->setCellValue($col++ . $row, $item['unit_weight_kg']);
    $sheet->setCellValue($col++ . $row, $item['total_weight_kg']);

    // Delivery columns
    foreach ($poNumbers as $po) {
        $value = isset($item['deliveries'][$po]) ? $item['deliveries'][$po] : '';
        $sheet->setCellValue($col++ . $row, $value);
    }

    // Final columns
    $sheet->setCellValue($col++ . $row, $item['untek']);
    $sheet->setCellValue($col++ . $row, $item['ready_cgi']);
    $sheet->setCellValue($col++ . $row, $item['os_dhj']);
    $sheet->setCellValue($col++ . $row, $item['remarks']);

    $totalQty += $item['qty'];
    $totalWeight += $item['total_weight_kg'];

    $row++;
}

// Style data rows
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];

$lastRow = $row - 1;
$sheet->getStyle('A5:' . $lastColumn . $lastRow)->applyFromArray($dataStyle);

// Highlight delivery data columns
$sheet->getStyle($deliveryStartCol . '5:' . $deliveryEndCol . $lastRow)->applyFromArray([
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'EFF6FF']
    ]
]);

// Number formatting
$sheet->getStyle('D5:D' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
$sheet->getStyle('F5:H' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle($deliveryStartCol . '5:' . $deliveryEndCol . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');

// Alignment
$sheet->getStyle('A5:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('D5:D' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('F5:H' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle($deliveryStartCol . '5:' . $deliveryEndCol . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Add totals row
$totalRow = $row + 1;
$sheet->setCellValue('A' . $totalRow, 'TOTAL');
$sheet->mergeCells('A' . $totalRow . ':C' . $totalRow);
$sheet->setCellValue('D' . $totalRow, $totalQty);
$sheet->setCellValue('H' . $totalRow, $totalWeight);

$totalStyle = [
    'font' => [
        'bold' => true,
        'size' => 11
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FEF3C7']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_MEDIUM,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A' . $totalRow . ':' . $lastColumn . $totalRow)->applyFromArray($totalStyle);
$sheet->getStyle('D' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('H' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('H' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');

// Set filename
$filename = 'Logistik_' . $ponCode . '_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
