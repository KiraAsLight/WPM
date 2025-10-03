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
$sheet->setTitle('Site Template');

// Set headers
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

$lastColumn = 'J';
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

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

// Add sample data
$sampleData = [
    [1, 'BEAM 450x200x9x14', 'B1', 4, 776.56, 'B 1234 XYZ', 'Delivery ke site project A', 'Menunggu', 0, 'ToDo'],
    [2, 'COLUMN 400x400x12x19', 'C1', 8, 785.04, 'B 5678 ABC', 'Sudah sampai di site', 'Diterima', 100, 'Done'],
    [3, 'GIRDER 600x300x12x20', 'G1', 6, 500.00, 'B 9012 DEF', 'Dalam perjalanan ke site', 'Menunggu', 50, 'On Proses']
];

$row = 2;
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
$sheet->getStyle('A2:' . $lastColumn . '4')->applyFromArray($dataStyle);

// Add instructions
$sheet->setCellValue('A6', 'Petunjuk Pengisian:');
$sheet->mergeCells('A6:' . $lastColumn . '6');
$sheet->getStyle('A6')->getFont()->setBold(true);

$instructions = [
    'A7' => '• No: Nomor urut (harus unik)',
    'A8' => '• Nama Parts: Nama bagian/material',
    'A9' => '• Marking: Kode marking',
    'A10' => '• QTY: Jumlah dalam pieces',
    'A11' => '• Sent to Site: Berat yang dikirim ke site dalam kg',
    'A12' => '• No. Truk: Nomor plat kendaraan pengiriman',
    'A13' => '• Keterangan: Informasi tambahan tentang pengiriman',
    'A14' => '• Remarks: Pilih "Diterima" atau "Menunggu"',
    'A15' => '• Progress: 0-100%',
    'A16' => '• Status: ToDo, On Proses, Hold, Waiting Approve, Done',
    'A17' => '• CATATAN: Foto tidak bisa diimport melalui Excel, harus diupload manual di form edit'
];

foreach ($instructions as $cell => $instruction) {
    $sheet->setCellValue($cell, $instruction);
}

// Set row heights
$sheet->getRowDimension(1)->setRowHeight(35);

// Set filename
$filename = 'Site_Template_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
