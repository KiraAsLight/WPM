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

// Get site items
$items = fetchAll('SELECT * FROM logistik_site WHERE pon = ? ORDER BY no ASC', [$ponCode]);

if (empty($items)) {
    header('Location: logistik_site.php?pon=' . urlencode($ponCode) . '&error=no_data');
    exit;
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Site Data');

// Set title
$sheet->setCellValue('A1', 'DATA SITE LOGISTIK');
$lastColumn = 'K'; // Based on number of columns
$sheet->mergeCells('A1:' . $lastColumn . '1');
$sheet->getStyle('A1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => '10B981']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
]);
$sheet->getRowDimension(1)->setRowHeight(30);

// Set PON info
$sheet->setCellValue('A2', 'PON: ' . $ponCode);
$sheet->setCellValue('F2', 'Tanggal: ' . date('d/m/Y'));
$sheet->mergeCells('A2:E2');
$sheet->mergeCells('F2:' . $lastColumn . '2');
$sheet->getStyle('A2:' . $lastColumn . '2')->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
]);

// Headers
$headers = [
    'No',
    'Nama Parts',
    'Marking',
    'QTY (Pcs)',
    'Sent to Site (kg)',
    'No. Truk',
    'Keterangan',
    'Remarks',
    'Progress (%)',
    'Status'
];

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
        'startColor' => ['rgb' => '10B981']
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

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(15);
$sheet->getColumnDimension('G')->setWidth(30);
$sheet->getColumnDimension('H')->setWidth(12);
$sheet->getColumnDimension('I')->setWidth(12);
$sheet->getColumnDimension('J')->setWidth(15);
$sheet->getColumnDimension('K')->setWidth(15);

// Write data
$row = 5;
$totalQty = 0;
$totalSent = 0;

foreach ($items as $item) {
    $col = 'A';

    $sheet->setCellValue($col++ . $row, $item['no']);
    $sheet->setCellValue($col++ . $row, $item['nama_parts']);
    $sheet->setCellValue($col++ . $row, $item['marking']);
    $sheet->setCellValue($col++ . $row, $item['qty']);
    $sheet->setCellValue($col++ . $row, $item['sent_to_site']);
    $sheet->setCellValue($col++ . $row, $item['no_truk']);
    $sheet->setCellValue($col++ . $row, $item['keterangan']);
    $sheet->setCellValue($col++ . $row, $item['remarks']);
    $sheet->setCellValue($col++ . $row, $item['progress']);
    $sheet->setCellValue($col++ . $row, $item['status']);

    $totalQty += $item['qty'];
    $totalSent += $item['sent_to_site'];

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

// Number formatting
$sheet->getStyle('D5:D' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
$sheet->getStyle('E5:E' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('I5:I' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);

// Alignment
$sheet->getStyle('A5:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('D5:D' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('E5:E' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('I5:I' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Add totals row
$totalRow = $row + 1;
$sheet->setCellValue('A' . $totalRow, 'TOTAL');
$sheet->mergeCells('A' . $totalRow . ':C' . $totalRow);
$sheet->setCellValue('D' . $totalRow, $totalQty);
$sheet->setCellValue('E' . $totalRow, $totalSent);

$totalStyle = [
    'font' => [
        'bold' => true,
        'size' => 11
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'D1FAE5']
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
$sheet->getStyle('E' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('E' . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');

// Set filename
$filename = 'Site_' . $ponCode . '_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
