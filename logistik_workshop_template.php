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

// Create new spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Workshop Template');

// Set headers
$headers = [
    'No',
    'Nama Parts',
    'Marking',
    'QTY (Pcs)',
    'Dimensions (mm)',
    'Length (mm)',
    'Unit Weight (Kg/Pc)',
    'Total Weight (Kg)',
    'Vendor', // Tetap nama vendor, bukan ID (akan diproses saat import)
    'Surat Jalan Tanggal',
    'Surat Jalan Nomor',
    'Ready CGI',
    'O/S DHJ',
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

$lastColumn = 'P';
$sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray($headerStyle);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);
$sheet->getColumnDimension('I')->setWidth(20); // Vendor name
$sheet->getColumnDimension('J')->setWidth(15);
$sheet->getColumnDimension('K')->setWidth(18);
$sheet->getColumnDimension('L')->setWidth(12);
$sheet->getColumnDimension('M')->setWidth(12);
$sheet->getColumnDimension('N')->setWidth(20); // Remarks (text bebas)
$sheet->getColumnDimension('O')->setWidth(12);
$sheet->getColumnDimension('P')->setWidth(15); // Status

// Get existing vendors untuk sample data
$vendors = fetchAll('SELECT name FROM vendors ORDER BY name LIMIT 5');
$vendorNames = array_column($vendors, 'name');

// Jika tidak ada vendor, gunakan default
if (empty($vendorNames)) {
    $vendorNames = [
        'PT Duta Hija Jaya',
        'PT Maja Makmur',
        'PT Citra Baja',
        'PT Baja Utama',
        'CV Steel Indonesia'
    ];
}

// Add sample data dengan vendor names (bukan ID)
$sampleData = [
    [1, 'BEAM 450x200x9x14', 'B1', 4, '450x200x9x14', 12000, 194.14, 776.56, $vendorNames[0] ?? 'PT Duta Hija Jaya', '15/01/2024', 'SJ/001/WKG/I/2024', 4, 0, 'Sedang diproses di workshop', 0, 'Belum Terkirim'],
    [2, 'COLUMN 400x400x12x19', 'C1', 8, '400x400x12x19', 8500, 98.13, 785.04, $vendorNames[1] ?? 'PT Maja Makmur', '20/01/2024', 'SJ/002/WKG/I/2024', 8, 0, 'Sudah dikirim ke site tanggal 20/01/2024', 100, 'Terkirim'],
    [3, 'GIRDER 600x300x12x20', 'G1', 6, '600x300x12x20', 9500, 245.67, 1474.02, $vendorNames[2] ?? 'PT Citra Baja', '', '', 6, 0, 'Menunggu surat jalan', 75, 'Belum Terkirim']
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

// Add data validation for Status column
$statusValidation = $sheet->getCell('P5')->getDataValidation();
$statusValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$statusValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION);
$statusValidation->setAllowBlank(false);
$statusValidation->setShowInputMessage(true);
$statusValidation->setShowErrorMessage(true);
$statusValidation->setShowDropDown(true);
$statusValidation->setErrorTitle('Input error');
$statusValidation->setError('Value is not in list.');
$statusValidation->setPromptTitle('Pilih Status');
$statusValidation->setPrompt('Pilih status dari dropdown: Terkirim atau Belum Terkirim');
$statusValidation->setFormula1('"Terkirim,Belum Terkirim"');

// Apply validation to Status column (rows 2-100)
for ($i = 2; $i <= 100; $i++) {
    $sheet->getCell('P' . $i)->setDataValidation(clone $statusValidation);
}

// Add data validation for Progress column (0-100)
$progressValidation = $sheet->getCell('O5')->getDataValidation();
$progressValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_WHOLE);
$progressValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$progressValidation->setAllowBlank(true);
$progressValidation->setShowInputMessage(true);
$progressValidation->setShowErrorMessage(true);
$progressValidation->setErrorTitle('Input error');
$progressValidation->setError('Progress harus antara 0-100');
$progressValidation->setPromptTitle('Input Progress');
$progressValidation->setPrompt('Masukkan nilai progress antara 0-100');
$progressValidation->setFormula1('0');
$progressValidation->setFormula2('100');

// Apply validation to Progress column (rows 2-100)
for ($i = 2; $i <= 100; $i++) {
    $sheet->getCell('O' . $i)->setDataValidation(clone $progressValidation);
}

// Add instructions
$sheet->setCellValue('A6', 'PETUNJUK PENGISIAN:');
$sheet->mergeCells('A6:' . $lastColumn . '6');
$sheet->getStyle('A6')->getFont()->setBold(true)->setSize(12);

$instructions = [
    'A7' => '• No: Nomor urut (harus unik untuk setiap PON)',
    'A8' => '• Nama Parts: Nama bagian/material',
    'A9' => '• Marking: Kode marking material',
    'A10' => '• QTY: Jumlah dalam pieces (angka bulat)',
    'A11' => '• Dimensions: Dimensi material (contoh: 450x200x9x14)',
    'A12' => '• Length: Panjang dalam mm (desimal diperbolehkan)',
    'A13' => '• Unit Weight: Berat per piece dalam kg (desimal 3 digit)',
    'A14' => '• Total Weight: Berat total dalam kg (akan dihitung otomatis jika kosong: QTY × Unit Weight)',
    'A15' => '• Vendor: Nama vendor (gunakan nama vendor yang sudah terdaftar atau vendor baru akan dibuat otomatis)',
    'A16' => '• Surat Jalan Tanggal: Format DD/MM/YYYY (contoh: 15/01/2024)',
    'A17' => '• Surat Jalan Nomor: Nomor surat jalan',
    'A18' => '• Ready CGI: Jumlah yang ready di CGI',
    'A19' => '• O/S DHJ: Jumlah yang outstanding di DHJ',
    'A20' => '• Remarks: Keterangan bebas (contoh: "Sedang diproses", "Sudah dikirim", dll)',
    'A21' => '• Progress: 0-100% (akan otomatis 100% jika status "Terkirim")',
    'A22' => '• Status: Pilih "Terkirim" atau "Belum Terkirim" (gunakan dropdown)'
];

foreach ($instructions as $cell => $instruction) {
    $sheet->setCellValue($cell, $instruction);
}

// Add important notes
$sheet->setCellValue('A24', 'CATATAN PENTING:');
$sheet->mergeCells('A24:' . $lastColumn . '24');
$sheet->getStyle('A24')->getFont()->setBold(true)->setSize(12);

$notes = [
    'A25' => '• Status "Terkirim" akan otomatis mengatur Progress menjadi 100%',
    'A26' => '• Status "Belum Terkirim" akan otomatis mengatur Progress menjadi 0%',
    'A27' => '• Total Weight akan dihitung otomatis jika kolom kosong',
    'A28' => '• Pastikan No tidak duplikat untuk PON yang sama',
    'A29' => '• Kolom Remarks bisa diisi keterangan apapun sesuai kebutuhan',
    'A30' => '• Vendor: Gunakan nama vendor yang konsisten. Vendor baru akan dibuat otomatis jika belum ada'
];

foreach ($notes as $cell => $note) {
    $sheet->setCellValue($cell, $note);
}

// Style instructions and notes
$instructionStyle = [
    'font' => [
        'size' => 10
    ]
];
$sheet->getStyle('A7:A30')->applyFromArray($instructionStyle);

// Get all vendors untuk daftar referensi
$allVendors = fetchAll('SELECT name FROM vendors ORDER BY name');

$sheet->setCellValue('A32', 'DAFTAR VENDOR YANG SUDAH TERDAFTAR:');
$sheet->mergeCells('A32:' . $lastColumn . '32');
$sheet->getStyle('A32')->getFont()->setBold(true)->setSize(11);

if (!empty($allVendors)) {
    $vendorRow = 33;
    $vendorList = array_column($allVendors, 'name');
    $chunkedVendors = array_chunk($vendorList, 4); // Tampilkan 4 vendor per baris

    foreach ($chunkedVendors as $chunk) {
        $vendorText = '• ' . implode(' • ', $chunk);
        $sheet->setCellValue('A' . $vendorRow, $vendorText);
        $vendorRow++;
    }
} else {
    $sheet->setCellValue('A33', '• Belum ada vendor terdaftar. Vendor baru akan dibuat otomatis saat import.');
}

// Style vendor list
$vendorStyle = [
    'font' => [
        'size' => 9,
        'italic' => true
    ]
];
$sheet->getStyle('A33:A' . ($vendorRow + 2))->applyFromArray($vendorStyle);

// Set row heights
$sheet->getRowDimension(1)->setRowHeight(35);
$sheet->getRowDimension(6)->setRowHeight(20);
$sheet->getRowDimension(24)->setRowHeight(20);
$sheet->getRowDimension(32)->setRowHeight(20);

// Add auto-calculation note
$sheet->setCellValue('A' . ($vendorRow + 4), 'RUMUS OTOMATIS:');
$sheet->mergeCells('A' . ($vendorRow + 4) . ':' . $lastColumn . ($vendorRow + 4));
$sheet->getStyle('A' . ($vendorRow + 4))->getFont()->setBold(true)->setSize(11);

$formulas = [
    'A' . ($vendorRow + 5) => '• Total Weight = QTY × Unit Weight (jika kolom H kosong)',
    'A' . ($vendorRow + 6) => '• Progress = 100% jika Status = "Terkirim"',
    'A' . ($vendorRow + 7) => '• Progress = 0% jika Status = "Belum Terkirim"',
    'A' . ($vendorRow + 8) => '• Vendor = Akan dicari di database, atau dibuat baru jika belum ada'
];

foreach ($formulas as $cell => $formula) {
    $sheet->setCellValue($cell, $formula);
    $sheet->getStyle($cell)->getFont()->setItalic(true)->setSize(9);
}

// Set filename
$filename = 'Workshop_Template_' . date('YmdHis') . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
header('Cache-Control: must-revalidate');
header('Expires: 0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>