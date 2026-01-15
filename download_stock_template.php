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

// Set headers
$sheet->setCellValue('A1', 'Area');
$sheet->setCellValue('B1', 'Type');
$sheet->setCellValue('C1', 'Material');
$sheet->setCellValue('D1', 'Inventory Number');
$sheet->setCellValue('E1', 'Batch');
$sheet->setCellValue('F1', 'Material Description');
$sheet->setCellValue('G1', 'Storage Bin');
$sheet->setCellValue('H1', 'Available stock');

// Set column widths
$sheet->getColumnDimension('A')->setWidth(10);
$sheet->getColumnDimension('B')->setWidth(10);
$sheet->getColumnDimension('C')->setWidth(15);
$sheet->getColumnDimension('D')->setWidth(20);
$sheet->getColumnDimension('E')->setWidth(15);
$sheet->getColumnDimension('F')->setWidth(30);
$sheet->getColumnDimension('G')->setWidth(15);
$sheet->getColumnDimension('H')->setWidth(15);

// Ensure storage_bin column (G) is formatted as text to prevent Excel converting values to scientific notation
$sheet->getStyle('G')->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);

// Add sample data (optional)
$sheet->setCellValue('A2', '001');
$sheet->setCellValue('B2', 'Part');
$sheet->setCellValue('C2', '2313833210');
$sheet->setCellValue('D2', 'INV-2313833210');
$sheet->setCellValue('E2', 'BATCH-001');
$sheet->setCellValue('F2', 'LOCKNUT, W/O INSERT, 5/8"-18 NF,');
$sheet->setCellValue('G2', '103A0101');
$sheet->setCellValue('H2', '450');

// Output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="stock_taking_template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
