<?php
include 'db.php';

// Check if export is requested
if (!isset($_GET['export']) || $_GET['export'] !== 'excel') {
    die('Invalid request');
}

require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

// Create spreadsheet
$spreadsheet = new Spreadsheet();

// Get overall statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_parts,
        COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' THEN 1 END) as parts_with_diff,
        COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' AND resolved_at IS NULL THEN 1 END) as unresolved,
        COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' AND resolved_at IS NOT NULL THEN 1 END) as resolved
    FROM stock_taking
");
$stats = $stmt->fetch();

// Sheet 1: Summary
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Summary');

$sheet1->setCellValue('A1', 'Stock Taking Report');
$sheet1->mergeCells('A1:D1');
$sheet1->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet1->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFC7CE');

$sheet1->setCellValue('A3', 'Metric');
$sheet1->setCellValue('B3', 'Count');
$sheet1->getStyle('A3:B3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD966');
$sheet1->getStyle('A3:B3')->getFont()->setBold(true);

$row = 4;
$metrics = [
    'Total Parts' => $stats['total_parts'],
    'Parts with Differences' => $stats['parts_with_diff'],
    'Unresolved' => $stats['unresolved'],
    'Resolved' => $stats['resolved']
];

foreach ($metrics as $label => $value) {
    $sheet1->setCellValue('A' . $row, $label);
    $sheet1->setCellValue('B' . $row, $value);
    $row++;
}

$sheet1->getColumnDimension('A')->setWidth(25);
$sheet1->getColumnDimension('B')->setWidth(15);

// Sheet 2: Parts with Differences
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Differences');

$sheet2->setCellValue('A1', 'Parts with Differences');
$sheet2->mergeCells('A1:I1');
$sheet2->getStyle('A1')->getFont()->setSize(14)->setBold(true);
$sheet2->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEB9C');

$headers = ['Area', 'Material', 'Inventory #', 'Original Stock', 'New Stock', 'Difference', 'Type', 'Stock Taking Date', 'Status'];
$col = 'A';
foreach ($headers as $header) {
    $sheet2->setCellValue($col . '2', $header);
    $sheet2->getStyle($col . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD966');
    $sheet2->getStyle($col . '2')->getFont()->setBold(true);
    $col++;
}

$stmt = $pdo->query("
    SELECT * FROM stock_taking 
    WHERE new_available_stock IS NOT NULL AND new_available_stock != ''
    ORDER BY created_at DESC
");
$differences = $stmt->fetchAll();

$row = 3;
foreach ($differences as $item) {
    $avail = $item['available_stock'];
    $new = $item['new_available_stock'];
    $diff = (int)$new - (int)$avail;
    $type = $diff < 0 ? 'SHORT' : 'OVER';
    $status = !is_null($item['resolved_at']) ? 'Resolved' : 'Unresolved';
    
    $sheet2->setCellValue('A' . $row, htmlspecialchars($item['area'] ?? ''));
    $sheet2->setCellValue('B' . $row, htmlspecialchars($item['material']));
    $sheet2->setCellValue('C' . $row, htmlspecialchars($item['inventory_number']));
    $sheet2->setCellValue('D' . $row, htmlspecialchars($avail));
    $sheet2->setCellValue('E' . $row, htmlspecialchars($new));
    $sheet2->setCellValue('F' . $row, $diff);
    $sheet2->setCellValue('G' . $row, $type);
    $sheet2->setCellValue('H' . $row, !empty($item['created_at']) ? date('Y-m-d H:i', strtotime($item['created_at'])) : '-');
    $sheet2->setCellValue('I' . $row, $status);
    
    // Color difference column
    if ($diff < 0) {
        $sheet2->getStyle('F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8D7DA');
    } elseif ($diff > 0) {
        $sheet2->getStyle('F' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
    }
    
    $row++;
}

// Set column widths
for ($col = 'A'; $col <= 'I'; $col++) {
    $sheet2->getColumnDimension($col)->setWidth(15);
}

// Sheet 3: Short Parts
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Short Parts');

$sheet3->setCellValue('A1', 'Short Parts Detail');
$sheet3->mergeCells('A1:H1');
$sheet3->getStyle('A1')->getFont()->setSize(14)->setBold(true);
$sheet3->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8D7DA');

$headers = ['Area', 'Material', 'Inventory #', 'Description', 'Original Stock', 'New Stock', 'Shortage', 'Date'];
$col = 'A';
foreach ($headers as $header) {
    $sheet3->setCellValue($col . '2', $header);
    $sheet3->getStyle($col . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('F8D7DA');
    $sheet3->getStyle($col . '2')->getFont()->setBold(true);
    $col++;
}

$row = 3;
$shortCount = 0;
$shortTotal = 0;
foreach ($differences as $item) {
    $avail = $item['available_stock'];
    $new = $item['new_available_stock'];
    if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
        $diff = (int)$new - (int)$avail;
        if ($diff < 0) {
            $sheet3->setCellValue('A' . $row, htmlspecialchars($item['area'] ?? ''));
            $sheet3->setCellValue('B' . $row, htmlspecialchars($item['material']));
            $sheet3->setCellValue('C' . $row, htmlspecialchars($item['inventory_number']));
            $sheet3->setCellValue('D' . $row, htmlspecialchars($item['material_description'] ?? ''));
            $sheet3->setCellValue('E' . $row, htmlspecialchars($avail));
            $sheet3->setCellValue('F' . $row, htmlspecialchars($new));
            $sheet3->setCellValue('G' . $row, abs($diff));
            $sheet3->setCellValue('H' . $row, !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '-');
            $row++;
            $shortCount++;
            $shortTotal += abs($diff);
        }
    }
}

$sheet3->setCellValue('A' . $row, 'TOTAL');
$sheet3->setCellValue('G' . $row, $shortTotal);
$sheet3->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
$sheet3->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE6E6');

for ($col = 'A'; $col <= 'H'; $col++) {
    $sheet3->getColumnDimension($col)->setWidth(15);
}

// Sheet 4: Over Parts
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Over Parts');

$sheet4->setCellValue('A1', 'Over Parts Detail');
$sheet4->mergeCells('A1:H1');
$sheet4->getStyle('A1')->getFont()->setSize(14)->setBold(true);
$sheet4->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');

$headers = ['Area', 'Material', 'Inventory #', 'Description', 'Original Stock', 'New Stock', 'Overage', 'Date'];
$col = 'A';
foreach ($headers as $header) {
    $sheet4->setCellValue($col . '2', $header);
    $sheet4->getStyle($col . '2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3CD');
    $sheet4->getStyle($col . '2')->getFont()->setBold(true);
    $col++;
}

$row = 3;
$overCount = 0;
$overTotal = 0;
foreach ($differences as $item) {
    $avail = $item['available_stock'];
    $new = $item['new_available_stock'];
    if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
        $diff = (int)$new - (int)$avail;
        if ($diff > 0) {
            $sheet4->setCellValue('A' . $row, htmlspecialchars($item['area'] ?? ''));
            $sheet4->setCellValue('B' . $row, htmlspecialchars($item['material']));
            $sheet4->setCellValue('C' . $row, htmlspecialchars($item['inventory_number']));
            $sheet4->setCellValue('D' . $row, htmlspecialchars($item['material_description'] ?? ''));
            $sheet4->setCellValue('E' . $row, htmlspecialchars($avail));
            $sheet4->setCellValue('F' . $row, htmlspecialchars($new));
            $sheet4->setCellValue('G' . $row, $diff);
            $sheet4->setCellValue('H' . $row, !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '-');
            $row++;
            $overCount++;
            $overTotal += $diff;
        }
    }
}

$sheet4->setCellValue('A' . $row, 'TOTAL');
$sheet4->setCellValue('G' . $row, $overTotal);
$sheet4->getStyle('A' . $row . ':G' . $row)->getFont()->setBold(true);
$sheet4->getStyle('A' . $row . ':G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFACD');

for ($col = 'A'; $col <= 'H'; $col++) {
    $sheet4->getColumnDimension($col)->setWidth(15);
}

// Download file
$filename = 'Stock_Taking_Report_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
