<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$rows = [];
try {
    $stmt = $pdo->query('SELECT mn_ptfi, kode_part, nama_part, kategori, min_qty, max_qty, berat, jenis_part, jumlah_lokasi, dimensi, lokasi, harga, stok FROM inventory ORDER BY id DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

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

$sheet->getStyle('A1:M1')->getFont()->setBold(true);

$rowNumber = 2;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $rowNumber, (string)($row['mn_ptfi'] ?? ''));
    $sheet->setCellValue('B' . $rowNumber, (string)($row['kode_part'] ?? ''));
    $sheet->setCellValue('C' . $rowNumber, (string)($row['nama_part'] ?? ''));
    $sheet->setCellValue('D' . $rowNumber, (string)($row['kategori'] ?? ''));
    $sheet->setCellValue('E' . $rowNumber, (string)($row['min_qty'] ?? ''));
    $sheet->setCellValue('F' . $rowNumber, (string)($row['max_qty'] ?? ''));
    $sheet->setCellValue('G' . $rowNumber, (string)($row['berat'] ?? ''));
    $sheet->setCellValue('H' . $rowNumber, (string)($row['jenis_part'] ?? ''));
    $sheet->setCellValue('I' . $rowNumber, (string)($row['jumlah_lokasi'] ?? ''));
    $sheet->setCellValue('J' . $rowNumber, (string)($row['dimensi'] ?? ''));
    $sheet->setCellValue('K' . $rowNumber, (string)($row['lokasi'] ?? ''));
    $sheet->setCellValue('L' . $rowNumber, (string)($row['harga'] ?? ''));
    $sheet->setCellValue('M' . $rowNumber, (string)($row['stok'] ?? ''));
    $rowNumber++;
}

foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="katalog_part_data.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>