<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Create new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Logistik Template');

// Set headers - basic columns
$headers = [
    'No',
    'Part Names',
    'Marking',
    'Qty (Pcs)',
    'Dimensions (mm)',
    'Length (mm)',
    'Unit Weight (Kg/Pc)',
    'Total Weight (Kg)',
    '02202508220031 - 1.522,56', // Sample delivery column 1
    'UNTEK',
    '02202508220127 - 3.126,44', // Sample delivery column 2
    '02202508220124 - 3.893,10', // Sample delivery column 3
    '02202508220135 - 22,64',    // Sample delivery column 4
    'READY CGI',
    'O/S DHJ',
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
        'size' => 11
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

$lastColumn = 'P'; // Adjust based on number of columns
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

// Highlight delivery columns
$deliveryStyle = [
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '93C5FD']
    ]
];
$sheet->getStyle('I1:M1')->applyFromArray($deliveryStyle);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);
$sheet->getColumnDimension('I')->setWidth(18);
$sheet->getColumnDimension('J')->setWidth(12);
$sheet->getColumnDimension('K')->setWidth(18);
$sheet->getColumnDimension('L')->setWidth(18);
$sheet->getColumnDimension('M')->setWidth(18);
$sheet->getColumnDimension('N')->setWidth(12);
$sheet->getColumnDimension('O')->setWidth(12);
$sheet->getColumnDimension('P')->setWidth(20);

// Add sample data - Section A: STRUKTUR BACKSTAY
$sheet->setCellValue('A2', 'A');
$sheet->setCellValue('B2', 'STRUKTUR BACKSTAY');
$sheet->mergeCells('A2:P2');
$sheet->getStyle('A2:P2')->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDE68A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
]);

// Sample items
$sampleData = [
    [1, 'JUNCTION BLOCK SYSTEM', 'J/BG B/BS3', 4, 'WF.150*75*5/7*10', 6000, 194.14, 776.56, '', '', '', '', '', '', '', 'Sample item'],
    [2, 'TOP PLATE J/BC,BEAM', 'J/BG PL1', 4, 'PL250*250*20', 250, 98.13, 392.52, '1.522,56', '', '', '', '', '', '', ''],
];

$row = 3;
foreach ($sampleData as $data) {
    $col = 'A';
    foreach ($data as $value) {
        $sheet->setCellValue($col . $row, $value);
        $col++;
    }
    $row++;
}

// Style sample data
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];
$sheet->getStyle('A3:P4')->applyFromArray($dataStyle);

// Add Section B
$sheet->setCellValue('A5', 'B');
$sheet->setCellValue('B5', 'STRUKTUR PYLON');
$sheet->mergeCells('A5:P5');
$sheet->getStyle('A5:P5')->applyFromArray([
    'font' => ['bold' => true, 'size' => 12],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FDE68A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
]);

// Set row heights
$sheet->getRowDimension(1)->setRowHeight(35);
$sheet->getRowDimension(2)->setRowHeight(25);

// Set filename
$filename = 'Logistik_Template_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
