<?php
// File: download_template.php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="pon_import_template.csv"');

$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// Headers dengan contoh data
fputcsv($output, [
    'job_no',
    'pon',
    'client',
    'nama_proyek',
    'project_manager',
    'qty',
    'progress',
    'date_pon',
    'date_finish',
    'status',
    'alamat_kontrak',
    'no_contract',
    'contract_date',
    'project_start',
    'subject',
    'material_type'
]);

// Contoh data
fputcsv($output, [
    'W-701',
    'PON-001',
    'PT. Client Contoh',
    'Project Contoh 1',
    'John Doe',
    '2',
    '0',
    '2024-01-15',
    '2024-03-15',
    'Progress',
    'Jl. Contoh No. 123, Jakarta',
    'CONT-001',
    '2024-01-10',
    '2024-01-15',
    'Subject project contoh',
    'AG25'
]);

fputcsv($output, [
    'W-702',
    'PON-002',
    'PT. Client Lain',
    'Project Contoh 2',
    'Jane Smith',
    '1',
    '50',
    '2024-02-01',
    '2024-04-01',
    'Progress',
    'Jl. Lain No. 456, Bandung',
    'CONT-002',
    '2024-01-25',
    '2024-02-01',
    'Subject project lain',
    'Baja Ringan'
]);

fclose($output);
exit;
?>