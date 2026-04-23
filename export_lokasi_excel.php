<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

$allowed = ['login.php','setup_users_table.php','logout.php','pic_stock_taking.php'];
$script  = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isLogged = !empty($_SESSION['user_id']) || !empty($_SESSION['pic_id']);
if (!in_array($script, $allowed) && !$isLogged) {
    header('Location: login.php');
    exit;
}

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    'vendor/autoload.php',
];

$autoloadPath = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloadPath = $candidate;
        break;
    }
}

if ($autoloadPath === null) {
    http_response_code(500);
    die('Dependency PhpSpreadsheet belum terpasang. Jalankan composer install di folder proyek atau ekstrak vendor.zip menjadi folder vendor/.');
}

require $autoloadPath;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$filterArea = trim($_GET['filter_area'] ?? '');
$filterTipe = trim($_GET['filter_tipe'] ?? '');
$filterKw   = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($filterArea !== '') {
    $where[] = 'l.area = ?';
    $params[] = $filterArea;
}
if ($filterTipe !== '') {
    $where[] = 'l.tipe = ?';
    $params[] = $filterTipe;
}
if ($filterKw !== '') {
    $where[] = '(l.kode_lokasi LIKE ? OR l.nama_lokasi LIKE ?)';
    $params[] = "%$filterKw%";
    $params[] = "%$filterKw%";
}

$sql = "SELECT l.*,\n          COUNT(DISTINCT st_cur.id) AS parts_current,\n          COUNT(DISTINCT st_new.id) AS parts_new\n        FROM lokasi l\n        LEFT JOIN stock_taking st_cur ON TRIM(st_cur.storage_bin) = l.kode_lokasi\n        LEFT JOIN stock_taking st_new ON TRIM(st_new.new_storage_bin) = l.kode_lokasi\n        WHERE " . implode(' AND ', $where) . "\n        GROUP BY l.id\n        ORDER BY l.area, l.kode_lokasi ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Database Lokasi');

$sheet->setCellValue('A1', 'DATABASE LOKASI');
$sheet->mergeCells('A1:H1');
$sheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1:H1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE2E8F0');

$sheet->setCellValue('A2', 'Tanggal Export');
$sheet->setCellValue('B2', date('Y-m-d H:i:s'));
$sheet->setCellValue('A3', 'Filter Area');
$sheet->setCellValue('B3', $filterArea !== '' ? $filterArea : 'Semua');
$sheet->setCellValue('C3', 'Filter Tipe');
$sheet->setCellValue('D3', $filterTipe !== '' ? $filterTipe : 'Semua');
$sheet->setCellValue('E3', 'Keyword');
$sheet->setCellValue('F3', $filterKw !== '' ? $filterKw : '-');

$headers = ['Kode Lokasi', 'Nama Lokasi', 'Area', 'Tipe', 'Kapasitas', 'Parts Current', 'Parts New', 'Total Parts'];
$headerRow = 5;
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $headerRow, $header);
    $col++;
}

$sheet->getStyle('A5:H5')->getFont()->setBold(true);
$sheet->getStyle('A5:H5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFBFDBFE');
$sheet->getStyle('A5:H5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$rowNum = 6;
foreach ($rows as $r) {
    $partsCurrent = (int)($r['parts_current'] ?? 0);
    $partsNew = (int)($r['parts_new'] ?? 0);

    // Paksa kolom identitas sebagai teks agar kode seperti 102E0103 tidak diubah ke scientific notation.
    $sheet->setCellValueExplicit('A' . $rowNum, (string)$r['kode_lokasi'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B' . $rowNum, (string)$r['nama_lokasi'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('C' . $rowNum, (string)($r['area'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $rowNum, (string)($r['tipe'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('E' . $rowNum, $r['kapasitas'] !== null ? (int)$r['kapasitas'] : '');
    $sheet->setCellValue('F' . $rowNum, $partsCurrent);
    $sheet->setCellValue('G' . $rowNum, $partsNew);
    $sheet->setCellValue('H' . $rowNum, $partsCurrent + $partsNew);
    $rowNum++;
}

$lastRow = max(6, $rowNum - 1);
$sheet->getStyle('A6:D' . $lastRow)->getNumberFormat()->setFormatCode('@');
$sheet->getStyle('A5:H' . $lastRow)->getBorders()->applyFromArray([
    'allBorders' => [
        'borderStyle' => Border::BORDER_THIN,
        'color' => ['argb' => 'FFCBD5E1'],
    ],
]);

foreach (range('A', 'H') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'Database_Lokasi_' . date('Ymd_His') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
