<?php
// Suppress warnings to prevent corrupting the Excel download
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

if (!file_exists('vendor/autoload.php')) {
    header('Content-Type: text/plain');
    echo 'Error: Dependencies not installed. Please run "composer install" on the server.';
    exit;
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Template headers exactly as requested.
$headers = [
    'Material',
    'Material Description',
    'Type',
    'Storage',
    'Stok System',
    'Inventory Record',
    'Date Created',
];

foreach ($headers as $idx => $header) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 1);
    $sheet->setCellValue($col . '1', $header);
    $sheet->getColumnDimension($col)->setWidth(20);
}

$sheet->getColumnDimension('B')->setWidth(36);
$sheet->getColumnDimension('D')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(22);
$sheet->getColumnDimension('G')->setWidth(20);

// Keep Storage column text formatted to prevent scientific notation conversion.
$sheet->getStyle('D')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

// Sample row
$sheet->setCellValue('A2', '2313833210');
$sheet->setCellValue('B2', 'LOCKNUT, W/O INSERT, 5/8"-18 NF,');
$sheet->setCellValue('C2', 'TM1');
$sheet->setCellValue('D2', '103A0101');
$sheet->setCellValue('E2', '450');
$sheet->setCellValue('F2', 'INV-2313833210');
$sheet->setCellValue('G2', date('Y-m-d H:i'));

// Output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="stock_taking_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
