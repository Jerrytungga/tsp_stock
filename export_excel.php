<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Sample data (replace with actual data from database or table)
$data = [
    ['40002644', '2313833210', 'LOCKNUT, W/O INSERT, 5/8"-18 NF,', 'Part', '1720', '2580', '2 kilo', 'Nut', '1', 'kecil', '103A0101', 'Rp. 20.000', '450'],
    ['40002645', '2313833211', 'BOLT, HEX, 1/2"-13 UNC, 2"', 'Part', '1500', '2250', '1.5 kilo', 'Bolt', '2', 'sedang', '103A0102', 'Rp. 15.000', '320'],
    ['40002646', '2313833212', 'WASHER, FLAT, 1/2"', 'Part', '2000', '3000', '0.5 kilo', 'Washer', '3', 'kecil', '103A0103', 'Rp. 5.000', '800'],
    ['40002647', '2313833213', 'NUT, HEX, 3/8"-16 UNC', 'Part', '1800', '2700', '0.8 kilo', 'Nut', '1', 'kecil', '103A0104', 'Rp. 8.000', '600'],
    ['40002648', '2313833214', 'SCREW, MACHINE, 1/4"-20 UNC', 'Part', '2200', '3300', '0.3 kilo', 'Screw', '2', 'kecil', '103A0105', 'Rp. 3.000', '1000'],
    ['40002649', '2313833215', 'BEARING, BALL, 6205', 'Part', '100', '150', '0.2 kilo', 'Bearing', '1', 'kecil', '103A0106', 'Rp. 50.000', '50'],
    ['40002650', '2313833216', 'CHAIN, ROLLER, 40-1', 'Part', '50', '75', '5 kilo', 'Chain', '1', 'besar', '103A0107', 'Rp. 200.000', '20'],
    ['40002651', '2313833217', 'GEAR, SPUR, 20T', 'Part', '30', '45', '3 kilo', 'Gear', '1', 'sedang', '103A0108', 'Rp. 150.000', '15'],
    ['40002652', '2313833218', 'SEAL, OIL, 50x70x10', 'Part', '200', '300', '0.1 kilo', 'Seal', '2', 'kecil', '103A0109', 'Rp. 25.000', '100'],
    ['40002653', '2313833219', 'BELT, TIMING, 100XL', 'Part', '80', '120', '1 kilo', 'Belt', '1', 'sedang', '103A0110', 'Rp. 75.000', '40'],
    ['40002654', '2313833220', 'PULLEY, V-BELT, 2"', 'Part', '60', '90', '2 kilo', 'Pulley', '1', 'sedang', '103A0111', 'Rp. 100.000', '30'],
    ['40002655', '2313833221', 'SPRING, COMPRESSION, 1"', 'Part', '300', '450', '0.5 kilo', 'Spring', '2', 'kecil', '103A0112', 'Rp. 10.000', '150'],
    ['40002656', '2313833222', 'CLAMP, HOSE, 1/2"', 'Part', '500', '750', '0.2 kilo', 'Clamp', '3', 'kecil', '103A0113', 'Rp. 2.000', '250'],
    ['40002657', '2313833223', 'VALVE, BALL, 1/2"', 'Part', '40', '60', '1.5 kilo', 'Valve', '1', 'sedang', '103A0114', 'Rp. 120.000', '25'],
    ['40002658', '2313833224', 'FILTER, OIL, 5"', 'Part', '100', '150', '0.8 kilo', 'Filter', '1', 'sedang', '103A0115', 'Rp. 30.000', '80'],
    ['40002659', '2313833225', 'HOSE, RUBBER, 1/2" x 10\'', 'Part', '70', '105', '2 kilo', 'Hose', '1', 'panjang', '103A0116', 'Rp. 40.000', '35'],
    ['40002660', '2313833226', 'CONNECTOR, ELECTRICAL, 2PIN', 'Part', '400', '600', '0.1 kilo', 'Connector', '2', 'kecil', '103A0117', 'Rp. 5.000', '200'],
    ['40002661', '2313833227', 'SENSOR, TEMPERATURE, PT100', 'Part', '20', '30', '0.3 kilo', 'Sensor', '1', 'kecil', '103A0118', 'Rp. 80.000', '10'],
    ['40002662', '2313833228', 'MOTOR, DC, 12V', 'Part', '10', '15', '4 kilo', 'Motor', '1', 'sedang', '103A0119', 'Rp. 250.000', '5'],
    ['40002663', '2313833229', 'PUMP, CENTRIFUGAL, 1HP', 'Part', '5', '8', '10 kilo', 'Pump', '1', 'besar', '103A0120', 'Rp. 500.000', '2']
];

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
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

// Add data
$row = 2;
foreach ($data as $rowData) {
    $col = 0;
    foreach ($rowData as $cell) {
        $sheet->setCellValue(chr(65 + $col) . $row, $cell);
        $col++;
    }
    $row++;
}

// Set column widths
foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Output file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="katalog_part_data.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>
