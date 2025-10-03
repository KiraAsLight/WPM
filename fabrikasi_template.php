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
$sheet->setTitle('Fabrikasi Template');

// Set headers
$headers = [
    'No',
    'AssyMarking',
    'Rv',
    'Name',
    'Qty',
    'Barang Jadi',
    'Barang Belum Jadi',
    'Progress Calculated',
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

$sheet->getStyle('A1:J1')->applyFromArray($headerStyle);

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

// Add sample data
// $sampleData = [
//     [1, 'BM-001', 'R0', 'Main Beam', 10, '300x200x10', 6000, 125.5, 1255.0, 'Sample remark'],
//     [2, 'SC-002', 'R1', 'Side Column', 8, '250x250x12', 4500, 98.3, 786.4, ''],
//     [3, 'BR-003', 'R0', 'Bracing', 15, '100x100x8', 3000, 45.2, 678.0, 'Check dimensions']
// ];

// $row = 2;
// foreach ($sampleData as $data) {
//     $column = 'A';
//     foreach ($data as $value) {
//         $sheet->setCellValue($column . $row, $value);
//         $column++;
//     }
//     $row++;
// }

// // Style sample data
// $dataStyle = [
//     'borders' => [
//         'allBorders' => [
//             'borderStyle' => Border::BORDER_THIN,
//             'color' => ['rgb' => 'CCCCCC']
//         ]
//     ]
// ];

// $sheet->getStyle('A2:J4')->applyFromArray($dataStyle);

// Set row height
$sheet->getRowDimension(1)->setRowHeight(25);

// Set filename
$filename = 'Fabrikasi_Template_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
