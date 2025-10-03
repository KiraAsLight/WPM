<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
require_once 'config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

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

// Get all fabrikasi items
$items = fetchAll('SELECT * FROM fabrikasi_items WHERE pon = ? ORDER BY no ASC', [$ponCode]);

// Create new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Data Fabrikasi');

// Set headers
$headers = [
    'No',
    'AssyMarking',
    'Rv',
    'Name',
    'Qty',
    'Barang Jadi',
    'Barang Belum Jadi',
    'Progress Calculated (%)',
    'Dimensions',
    'Length (mm)',
    'Weight (kg)',
    'Total Weight (kg)',
    'Remarks'
];

// Write headers
$column = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($column . '1', $header);
    $column++;
}

// Style headers
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '3B82F6']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ]
];

$sheet->getStyle('A1:M1')->applyFromArray($headerStyle);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(8);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(12);
$sheet->getColumnDimension('D')->setWidth(30);
$sheet->getColumnDimension('E')->setWidth(10);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(18);
$sheet->getColumnDimension('I')->setWidth(20);
$sheet->getColumnDimension('J')->setWidth(15);
$sheet->getColumnDimension('K')->setWidth(15);
$sheet->getColumnDimension('L')->setWidth(18);
$sheet->getColumnDimension('M')->setWidth(25);

// Write data
$row = 2;
foreach ($items as $item) {
    $sheet->setCellValue('A' . $row, $item['no']);
    $sheet->setCellValue('B' . $row, $item['assy_marking']);
    $sheet->setCellValue('C' . $row, $item['rv']);
    $sheet->setCellValue('D' . $row, $item['name']);
    $sheet->setCellValue('E' . $row, $item['qty']);
    $sheet->setCellValue('F' . $row, $item['barang_jadi']);
    $sheet->setCellValue('G' . $row, $item['barang_belum_jadi']);
    $sheet->setCellValue('H' . $row, $item['progress_calculated']);
    $sheet->setCellValue('I' . $row, $item['dimensions']);
    $sheet->setCellValue('J' . $row, $item['length_mm']);
    $sheet->setCellValue('K' . $row, $item['weight_kg']);
    $sheet->setCellValue('L' . $row, $item['total_weight_kg']);
    $sheet->setCellValue('M' . $row, $item['remarks']);
    $row++;
}

// Style data
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];

if ($row > 2) {
    $sheet->getStyle('A2:M' . ($row - 1))->applyFromArray($dataStyle);
}

// Set row height for header
$sheet->getRowDimension(1)->setRowHeight(25);

// Set filename
$filename = 'Fabrikasi_Data_' . $ponCode . '_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>