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
use PhpOffice\PhpSpreadsheet\Style\Color;

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

// Get workshop items
$items = fetchAll('
    SELECT lw.*, v.name as vendor_name 
    FROM logistik_workshop lw 
    LEFT JOIN vendors v ON lw.vendor_id = v.id 
    WHERE lw.pon = ? 
    ORDER BY lw.no ASC
', [$ponCode]);

if (empty($items)) {
    header('Location: logistik_workshop.php?pon=' . urlencode($ponCode) . '&error=no_data');
    exit;
}

// Create spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Workshop Data');

// Set title
$sheet->setCellValue('A1', 'DATA WORKSHOP LOGISTIK');
$lastColumn = 'P'; // Updated based on number of columns (removed one column)
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
$sheet->setCellValue('H2', 'Tanggal: ' . date('d/m/Y'));
$sheet->mergeCells('A2:G2');
$sheet->mergeCells('H2:' . $lastColumn . '2');
$sheet->getStyle('A2:' . $lastColumn . '2')->applyFromArray([
    'font' => ['bold' => true],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]
]);

// Headers - sesuai dengan struktur baru
$headers = [
    'No',
    'Nama Parts',
    'Marking',
    'QTY (Pcs)',
    'Dimensions (mm)',
    'Length (mm)',
    'Unit Weight (Kg/Pc)',
    'Total Weight (Kg)',
    'Vendor',
    'Surat Jalan Tanggal',
    'Surat Jalan Nomor',
    'Ready CGI',
    'O/S DHJ',
    'Remarks',
    'Progress (%)',
    'Status' // Status baru: Terkirim/Belum Terkirim
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

// Set column widths
$sheet->getColumnDimension('A')->setWidth(6);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);
$sheet->getColumnDimension('I')->setWidth(20);
$sheet->getColumnDimension('J')->setWidth(15);
$sheet->getColumnDimension('K')->setWidth(18);
$sheet->getColumnDimension('L')->setWidth(12);
$sheet->getColumnDimension('M')->setWidth(12);
$sheet->getColumnDimension('N')->setWidth(20); // Lebar untuk remarks (text bebas)
$sheet->getColumnDimension('O')->setWidth(12);
$sheet->getColumnDimension('P')->setWidth(15);

// Write data
$row = 5;
$totalQty = 0;
$totalWeight = 0;
$terkirimCount = 0;
$belumTerkirimCount = 0;

foreach ($items as $item) {
    $col = 'A';

    $sheet->setCellValue($col++ . $row, $item['no']);
    $sheet->setCellValue($col++ . $row, $item['nama_parts']);
    $sheet->setCellValue($col++ . $row, $item['marking']);
    $sheet->setCellValue($col++ . $row, $item['qty']);
    $sheet->setCellValue($col++ . $row, $item['dimensions']);
    $sheet->setCellValue($col++ . $row, $item['length_mm']);
    $sheet->setCellValue($col++ . $row, $item['unit_weight_kg']);
    $sheet->setCellValue($col++ . $row, $item['total_weight_kg']);
    $sheet->setCellValue($col++ . $row, $item['vendor_id']);
    $sheet->setCellValue($col++ . $row, $item['surat_jalan_tanggal'] ? dmy($item['surat_jalan_tanggal']) : '');
    $sheet->setCellValue($col++ . $row, $item['surat_jalan_nomor']);
    $sheet->setCellValue($col++ . $row, $item['ready_cgi']);
    $sheet->setCellValue($col++ . $row, $item['os_dhj']);
    $sheet->setCellValue($col++ . $row, $item['remarks']); // Remarks sekarang text bebas
    $sheet->setCellValue($col++ . $row, $item['progress']);
    $sheet->setCellValue($col . $row, $item['status']); // Status baru

    // Count status
    if ($item['status'] === 'Terkirim') {
        $terkirimCount++;
    } else {
        $belumTerkirimCount++;
    }

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

// Number formatting
$sheet->getStyle('D5:D' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
$sheet->getStyle('F5:H' . $lastRow)->getNumberFormat()->setFormatCode('#,##0.00');
$sheet->getStyle('L5:M' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
$sheet->getStyle('O5:O' . $lastRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);

// Alignment
$sheet->getStyle('A5:A' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('D5:D' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('F5:H' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet->getStyle('L5:M' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('O5:O' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('N5:N' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
$sheet->getStyle('P5:P' . $lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Conditional formatting for Status column
$statusColumn = 'P';
for ($i = 5; $i <= $lastRow; $i++) {
    $statusCell = $statusColumn . $i;
    $statusValue = $sheet->getCell($statusCell)->getValue();

    if ($statusValue === 'Terkirim') {
        $sheet->getStyle($statusCell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '10B981'] // Green
            ]
        ]);
    } elseif ($statusValue === 'Belum Terkirim') {
        $sheet->getStyle($statusCell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EF4444'] // Red
            ]
        ]);
    }
}

// Conditional formatting for Progress column
$progressColumn = 'O';
for ($i = 5; $i <= $lastRow; $i++) {
    $progressCell = $progressColumn . $i;
    $progressValue = $sheet->getCell($progressCell)->getValue();

    if ($progressValue == 100) {
        $sheet->getStyle($progressCell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '10B981'] // Green
            ]
        ]);
    } elseif ($progressValue >= 50) {
        $sheet->getStyle($progressCell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F59E0B'] // Yellow
            ]
        ]);
    } else {
        $sheet->getStyle($progressCell)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EF4444'] // Red
            ]
        ]);
    }
}

// Add summary statistics
$summaryRow = $row + 2;
$sheet->setCellValue('A' . $summaryRow, 'STATISTIK:');
$sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true)->setSize(12);

$sheet->setCellValue('A' . ($summaryRow + 1), 'Total Items:');
$sheet->setCellValue('B' . ($summaryRow + 1), count($items));
$sheet->setCellValue('A' . ($summaryRow + 2), 'Status Terkirim:');
$sheet->setCellValue('B' . ($summaryRow + 2), $terkirimCount);
$sheet->setCellValue('A' . ($summaryRow + 3), 'Status Belum Terkirim:');
$sheet->setCellValue('B' . ($summaryRow + 3), $belumTerkirimCount);
$sheet->setCellValue('A' . ($summaryRow + 4), 'Persentase Terkirim:');
$sheet->setCellValue('B' . ($summaryRow + 4), count($items) > 0 ? round(($terkirimCount / count($items)) * 100, 2) . '%' : '0%');

// Style summary
$summaryStyle = [
    'font' => [
        'size' => 11
    ],
    'borders' => [
        'bottom' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];
$sheet->getStyle('A' . $summaryRow . ':B' . ($summaryRow + 4))->applyFromArray($summaryStyle);

// Add totals row
$totalRow = $row;
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

// Add footer note
$footerRow = $summaryRow + 6;
$sheet->setCellValue('A' . $footerRow, 'Catatan:');
$sheet->getStyle('A' . $footerRow)->getFont()->setBold(true);
$sheet->setCellValue('A' . ($footerRow + 1), '- Status: "Terkirim" = Item sudah dikirim ke site');
$sheet->setCellValue('A' . ($footerRow + 2), '- Status: "Belum Terkirim" = Item masih di workshop');
$sheet->setCellValue('A' . ($footerRow + 3), '- Remarks: Keterangan bebas tentang status pengiriman');

// Set filename
$filename = 'Workshop_' . $ponCode . '_' . date('YmdHis') . '.xlsx';

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