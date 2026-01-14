<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers (hanya 13 kolom data tanpa gambar URL)
$headers = [
    'MN PTFI',
    'Kode Part',
    'Nama Part',
    'Kategori',
    'Min qtty',
    'Max qtty',
    'Berat',
    'jenis Part',
    'Jumlah lokasi',
    'Dimensi',
    'Lokasi',
    'Harga',
    'Stok (pc)'
];

foreach ($headers as $col => $header) {
    $sheet->setCellValue(chr(65 + $col) . '1', $header);
}

// Set headers bold
$sheet->getStyle('A1:M1')->getFont()->setBold(true);

// Set column widths
foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="katalog_part_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>