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
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Create spreadsheet
$spreadsheet = new Spreadsheet();

// Get all data
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_parts,
            COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' THEN 1 END) as parts_with_diff,
            COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' AND resolved_at IS NULL THEN 1 END) as unresolved,
            COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' AND resolved_at IS NOT NULL THEN 1 END) as resolved,
            COUNT(DISTINCT area) as total_areas,
            MIN(created_at) as first_taking,
            MAX(created_at) as last_taking
        FROM stock_taking
    ");
    $stats = $stmt->fetch();
} catch (Exception $e) {
    $stats = [];
}

// Get all parts data
$stmt = $pdo->query("SELECT * FROM stock_taking ORDER BY created_at DESC, id DESC");
$allParts = $stmt->fetchAll();

// Get physical/block S notes summary and list
$pnSummary = ['physical' => 0, 'block_s' => 0, 'unresolved' => 0, 'resolved' => 0];
$pnList = [];
try {
    $rows = $pdo->query("SELECT issue_type, COUNT(*) total, SUM(resolved_at IS NULL) unresolved, SUM(resolved_at IS NOT NULL) resolved FROM physical_notes GROUP BY issue_type")?->fetchAll();
    foreach ($rows as $r) {
        $type = $r['issue_type'];
        if ($type === 'block_s') { $pnSummary['block_s'] += (int)$r['total']; }
        else { $pnSummary['physical'] += (int)$r['total']; }
        $pnSummary['unresolved'] += (int)$r['unresolved'];
        $pnSummary['resolved'] += (int)$r['resolved'];
    }
    $pnList = $pdo->query("SELECT pn.*, st.area, st.material, st.inventory_number, st.material_description FROM physical_notes pn LEFT JOIN stock_taking st ON pn.stock_taking_id = st.id ORDER BY pn.resolved_at IS NULL DESC, pn.created_at DESC")?->fetchAll();
} catch (Exception $e) {
    $pnSummary = ['physical' => 0, 'block_s' => 0, 'unresolved' => 0, 'resolved' => 0];
    $pnList = [];
}

// Calculate short and over
$shortParts = [];
$overParts = [];
foreach ($allParts as $item) {
    if (!is_null($item['new_available_stock']) && $item['new_available_stock'] != '') {
        $avail = $item['available_stock'];
        $new = $item['new_available_stock'];
        if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
            $diff = (int)$new - (int)$avail;
            if ($diff < 0) {
                $shortParts[] = $item;
            } elseif ($diff > 0) {
                $overParts[] = $item;
            }
        }
    }
}

// Helper function to style cells
function styleHeader(&$sheet, $range, $bgColor, $textColor = 'FFFFFFFF') {
    $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($bgColor);
    $sheet->getStyle($range)->getFont()->setBold(true);
    $sheet->getStyle($range)->getFont()->getColor()->setARGB($textColor);
    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
}

function styleBorders(&$sheet, $range) {
    $sheet->getStyle($range)->getBorders()->applyFromArray([
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['argb' => 'FF000000'],
        ]
    ]);
}

// ========== SHEET 1: EXECUTIVE SUMMARY ==========
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Executive Summary');

$sheet1->setCellValue('A1', 'STOCK TAKING REPORT');
$sheet1->mergeCells('A1:E1');
styleHeader($sheet1, 'A1:E1', 'FF1F4E78', 'FFFFFFFF');
$sheet1->getStyle('A1')->getFont()->setSize(16);
$sheet1->getRowDimension(1)->setRowHeight(30);

$sheet1->setCellValue('A3', 'Report Date: ' . date('Y-m-d H:i:s'));
$sheet1->setCellValue('A4', 'Generated: ' . date('Y-m-d H:i:s'));

$sheet1->setCellValue('A6', 'SUMMARY');
styleHeader($sheet1, 'A6:B6', 'FF4472C4');
$sheet1->getRowDimension(6)->setRowHeight(20);

$row = 7;
$summaryData = [
    'Total Parts' => $stats['total_parts'] ?? 0,
    'Total Areas' => $stats['total_areas'] ?? 0,
    'Parts with Differences' => $stats['parts_with_diff'] ?? 0,
    'Short Parts' => count($shortParts),
    'Over Parts' => count($overParts),
    'Resolved Differences' => $stats['resolved'] ?? 0,
    'Unresolved Differences' => $stats['unresolved'] ?? 0,
];

foreach ($summaryData as $label => $value) {
    $sheet1->setCellValue('A' . $row, $label);
    $sheet1->setCellValue('B' . $row, $value);
    $sheet1->getStyle('B' . $row)->getFont()->setBold(true)->setSize(12);
    $row++;
}

$sheet1->getColumnDimension('A')->setWidth(30);
$sheet1->getColumnDimension('B')->setWidth(20);

// Calculate totals
$shortTotal = 0;
$overTotal = 0;
foreach ($shortParts as $item) {
    $diff = (int)$item['new_available_stock'] - (int)$item['available_stock'];
    $shortTotal += abs($diff);
}
foreach ($overParts as $item) {
    $diff = (int)$item['new_available_stock'] - (int)$item['available_stock'];
    $overTotal += $diff;
}

$sheet1->setCellValue('A16', 'DIFFERENCE SUMMARY');
styleHeader($sheet1, 'A16:B16', 'FFFF6B6B');

$sheet1->setCellValue('A17', 'Total Shortage');
$sheet1->setCellValue('B17', $shortTotal);
$sheet1->getStyle('B17')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8D7DA');

$sheet1->setCellValue('A18', 'Total Overage');
$sheet1->setCellValue('B18', $overTotal);
$sheet1->getStyle('B18')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');

// ========== SHEET 2: ALL PARTS ==========
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('All Parts');

$sheet2->setCellValue('A1', 'ALL PARTS INVENTORY');
$sheet2->mergeCells('A1:M1');
styleHeader($sheet2, 'A1:M1', 'FF1F4E78', 'FFFFFFFF');
$sheet2->getRowDimension(1)->setRowHeight(25);
$sheet2->getStyle('A1')->getFont()->setSize(14);

$headers = ['No', 'Area', 'Type', 'Material', 'Inventory #', 'Description', 'Original Bin', 'New Bin', 'Original Stock', 'New Stock', 'Difference', 'Status', 'Date'];
$col = 'A';
for ($i = 2; $i <= count($headers) + 1; $i++) {
    $sheet2->setCellValue($col . '2', $headers[$i-2]);
    $col++;
}
styleHeader($sheet2, 'A2:M2', 'FF4472C4');

$row = 3;
$no = 1;
foreach ($allParts as $item) {
    $sheet2->setCellValue('A' . $row, $no++);
    $sheet2->setCellValue('B' . $row, htmlspecialchars($item['area'] ?? ''));
    $sheet2->setCellValue('C' . $row, htmlspecialchars($item['type'] ?? ''));
    $sheet2->setCellValue('D' . $row, htmlspecialchars($item['material']));
    $sheet2->setCellValue('E' . $row, htmlspecialchars($item['inventory_number']));
    $sheet2->setCellValue('F' . $row, htmlspecialchars($item['material_description'] ?? ''));
    $storageBinValue = htmlspecialchars($item['storage_bin'] ?? '');
    $sheet2->setCellValue('G' . $row, $storageBinValue);
    $sheet2->getCell('G' . $row)->setDataType(DataType::TYPE_STRING);
    $sheet2->setCellValue('H' . $row, htmlspecialchars($item['new_storage_bin'] ?? '-'));
    $sheet2->setCellValue('I' . $row, htmlspecialchars($item['available_stock'] ?? ''));
    $sheet2->setCellValue('J' . $row, htmlspecialchars($item['new_available_stock'] ?? '-'));
    
    if (!is_null($item['new_available_stock']) && $item['new_available_stock'] != '') {
        $avail = $item['available_stock'];
        $new = $item['new_available_stock'];
        if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
            $diff = (int)$new - (int)$avail;
            $sheet2->setCellValue('K' . $row, $diff);
            if ($diff < 0) {
                $sheet2->getStyle('K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8D7DA');
            } elseif ($diff > 0) {
                $sheet2->getStyle('K' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
            }
        } else {
            $sheet2->setCellValue('K' . $row, '-');
        }
    } else {
        $sheet2->setCellValue('K' . $row, '-');
    }
    
    $status = !is_null($item['resolved_at']) ? 'Resolved' : 'Unresolved';
    $sheet2->setCellValue('L' . $row, $status);
    $sheet2->setCellValue('M' . $row, !empty($item['created_at']) ? date('Y-m-d H:i', strtotime($item['created_at'])) : '-');
    
    $row++;
}

for ($col = 'A'; $col <= 'M'; $col++) {
    $sheet2->getColumnDimension($col)->setWidth(12);
}
$sheet2->getColumnDimension('F')->setWidth(25);

// ========== SHEET 3: SHORT PARTS DETAIL ==========
$sheet3 = $spreadsheet->createSheet();
$sheet3->setTitle('Short Parts Detail');

$sheet3->setCellValue('A1', 'SHORT PARTS DETAIL (SHORTAGE)');
$sheet3->mergeCells('A1:J1');
styleHeader($sheet3, 'A1:J1', 'FFC5504A', 'FFFFFFFF');
$sheet3->getRowDimension(1)->setRowHeight(25);

$headers = ['No', 'Area', 'Material', 'Inventory #', 'Description', 'Original Bin', 'New Bin', 'Original Stock', 'New Stock', 'Shortage'];
$col = 'A';
for ($i = 2; $i <= count($headers) + 1; $i++) {
    $sheet3->setCellValue($col . '2', $headers[$i-2]);
    $col++;
}
styleHeader($sheet3, 'A2:J2', 'FFF8D7DA');

$row = 3;
$no = 1;
foreach ($shortParts as $item) {
    $diff = (int)$item['new_available_stock'] - (int)$item['available_stock'];
    
    $sheet3->setCellValue('A' . $row, $no++);
    $sheet3->setCellValue('B' . $row, htmlspecialchars($item['area'] ?? ''));
    $sheet3->setCellValue('C' . $row, htmlspecialchars($item['material']));
    $sheet3->setCellValue('D' . $row, htmlspecialchars($item['inventory_number']));
    $sheet3->setCellValue('E' . $row, htmlspecialchars($item['material_description'] ?? ''));
    $storageBinValue = htmlspecialchars($item['storage_bin'] ?? '');
    $sheet3->setCellValue('F' . $row, $storageBinValue);
    $sheet3->getCell('F' . $row)->setDataType(DataType::TYPE_STRING);
    $sheet3->setCellValue('G' . $row, htmlspecialchars($item['new_storage_bin'] ?? '-'));
    $sheet3->setCellValue('H' . $row, htmlspecialchars($item['available_stock']));
    $sheet3->setCellValue('I' . $row, htmlspecialchars($item['new_available_stock']));
    $sheet3->setCellValue('J' . $row, abs($diff));
    $sheet3->getStyle('J' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF8D7DA');
    
    $row++;
}

$sheet3->setCellValue('A' . $row, 'TOTAL');
$sheet3->setCellValue('J' . $row, $shortTotal);
$sheet3->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
$sheet3->getStyle('A' . $row . ':J' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEDACAC');

for ($col = 'A'; $col <= 'J'; $col++) {
    $sheet3->getColumnDimension($col)->setWidth(12);
}
$sheet3->getColumnDimension('E')->setWidth(25);

// ========== SHEET 4: OVER PARTS DETAIL ==========
$sheet4 = $spreadsheet->createSheet();
$sheet4->setTitle('Over Parts Detail');

$sheet4->setCellValue('A1', 'OVER PARTS DETAIL (OVERAGE)');
$sheet4->mergeCells('A1:J1');
styleHeader($sheet4, 'A1:J1', 'FFFFB84D', 'FFFFFFFF');
$sheet4->getRowDimension(1)->setRowHeight(25);

$headers = ['No', 'Area', 'Material', 'Inventory #', 'Description', 'Original Bin', 'New Bin', 'Original Stock', 'New Stock', 'Overage'];
$col = 'A';
for ($i = 2; $i <= count($headers) + 1; $i++) {
    $sheet4->setCellValue($col . '2', $headers[$i-2]);
    $col++;
}
styleHeader($sheet4, 'A2:J2', 'FFFFF3CD');

$row = 3;
$no = 1;
foreach ($overParts as $item) {
    $diff = (int)$item['new_available_stock'] - (int)$item['available_stock'];
    
    $sheet4->setCellValue('A' . $row, $no++);
    $sheet4->setCellValue('B' . $row, htmlspecialchars($item['area'] ?? ''));
    $sheet4->setCellValue('C' . $row, htmlspecialchars($item['material']));
    $sheet4->setCellValue('D' . $row, htmlspecialchars($item['inventory_number']));
    $sheet4->setCellValue('E' . $row, htmlspecialchars($item['material_description'] ?? ''));
    $storageBinValue = htmlspecialchars($item['storage_bin'] ?? '');
    $sheet4->setCellValue('F' . $row, $storageBinValue);
    $sheet4->getCell('F' . $row)->setDataType(DataType::TYPE_STRING);
    $sheet4->setCellValue('G' . $row, htmlspecialchars($item['new_storage_bin'] ?? '-'));
    $sheet4->setCellValue('H' . $row, htmlspecialchars($item['available_stock']));
    $sheet4->setCellValue('I' . $row, htmlspecialchars($item['new_available_stock']));
    $sheet4->setCellValue('J' . $row, $diff);
    $sheet4->getStyle('J' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
    
    $row++;
}

$sheet4->setCellValue('A' . $row, 'TOTAL');
$sheet4->setCellValue('J' . $row, $overTotal);
$sheet4->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
$sheet4->getStyle('A' . $row . ':J' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF0D9');

for ($col = 'A'; $col <= 'J'; $col++) {
    $sheet4->getColumnDimension($col)->setWidth(12);
}
$sheet4->getColumnDimension('E')->setWidth(25);

// ========== SHEET 5: RESOLUTION HISTORY ==========
$sheet5 = $spreadsheet->createSheet();
$sheet5->setTitle('Resolution History');

$sheet5->setCellValue('A1', 'RESOLUTION HISTORY');
$sheet5->mergeCells('A1:J1');
styleHeader($sheet5, 'A1:J1', 'FF70AD47', 'FFFFFFFF');
$sheet5->getRowDimension(1)->setRowHeight(25);

$headers = ['No', 'Area', 'Material', 'Inventory #', 'Original Stock', 'New Stock', 'Difference', 'Resolved Date', 'Resolution Notes', 'Days to Resolve'];
$col = 'A';
for ($i = 2; $i <= count($headers) + 1; $i++) {
    $sheet5->setCellValue($col . '2', $headers[$i-2]);
    $col++;
}
styleHeader($sheet5, 'A2:J2', 'FFDEEBF7');

$row = 3;
$no = 1;
foreach ($allParts as $item) {
    if (!is_null($item['resolved_at'])) {
        $diff = (int)$item['new_available_stock'] - (int)$item['available_stock'];
        $createdDate = new DateTime($item['created_at']);
        $resolvedDate = new DateTime($item['resolved_at']);
        $daysToResolve = $resolvedDate->diff($createdDate)->days;
        
        $sheet5->setCellValue('A' . $row, $no++);
        $sheet5->setCellValue('B' . $row, htmlspecialchars($item['area'] ?? ''));
        $sheet5->setCellValue('C' . $row, htmlspecialchars($item['material']));
        $sheet5->setCellValue('D' . $row, htmlspecialchars($item['inventory_number']));
        $sheet5->setCellValue('E' . $row, htmlspecialchars($item['available_stock']));
        $sheet5->setCellValue('F' . $row, htmlspecialchars($item['new_available_stock']));
        $sheet5->setCellValue('G' . $row, $diff);
        $sheet5->setCellValue('H' . $row, date('Y-m-d H:i', strtotime($item['resolved_at'])));
        $sheet5->setCellValue('I' . $row, htmlspecialchars($item['resolution_notes'] ?? '-'));
        $sheet5->setCellValue('J' . $row, $daysToResolve);
        
        $row++;
    }
}

for ($col = 'A'; $col <= 'J'; $col++) {
    $sheet5->getColumnDimension($col)->setWidth(12);
}
$sheet5->getColumnDimension('I')->setWidth(25);

// ========== SHEET 6: AREA SUMMARY ==========
$stmt = $pdo->query("
    SELECT 
        area,
        COUNT(*) as total_parts,
        COUNT(CASE WHEN new_available_stock IS NOT NULL AND new_available_stock != '' THEN 1 END) as parts_with_diff
    FROM stock_taking
    GROUP BY area
    ORDER BY area
");
$areaData = $stmt->fetchAll();

$sheet6 = $spreadsheet->createSheet();
$sheet6->setTitle('By Area');

$sheet6->setCellValue('A1', 'PARTS SUMMARY BY AREA');
$sheet6->mergeCells('A1:D1');
styleHeader($sheet6, 'A1:D1', 'FF1F4E78', 'FFFFFFFF');
$sheet6->getRowDimension(1)->setRowHeight(25);

$headers = ['Area', 'Total Parts', 'Parts with Difference', 'Percentage'];
$col = 'A';
for ($i = 2; $i <= count($headers) + 1; $i++) {
    $sheet6->setCellValue($col . '2', $headers[$i-2]);
    $col++;
}
styleHeader($sheet6, 'A2:D2', 'FF4472C4');

$row = 3;
foreach ($areaData as $area) {
    $sheet6->setCellValue('A' . $row, htmlspecialchars($area['area'] ?? 'Unknown'));
    $sheet6->setCellValue('B' . $row, $area['total_parts']);
    $sheet6->setCellValue('C' . $row, $area['parts_with_diff']);
    $percentage = $area['total_parts'] > 0 ? ($area['parts_with_diff'] / $area['total_parts'] * 100) : 0;
    $sheet6->setCellValue('D' . $row, number_format($percentage, 2) . '%');
    $row++;
}

for ($col = 'A'; $col <= 'D'; $col++) {
    $sheet6->getColumnDimension($col)->setWidth(18);
}

// ========== SHEET 7: PHYSICAL / BLOK S NOTES ==========
$sheet7 = $spreadsheet->createSheet();
$sheet7->setTitle('Physical Notes');

$sheet7->setCellValue('A1', 'PHYSICAL / BLOK S NOTES');
$sheet7->mergeCells('A1:J1');
styleHeader($sheet7, 'A1:J1', 'FF1F4E78', 'FFFFFFFF');
$sheet7->getRowDimension(1)->setRowHeight(25);

// Summary block
$sheet7->setCellValue('A3', 'Summary');
styleHeader($sheet7, 'A3:B3', 'FF4472C4', 'FFFFFFFF');
$sheet7->setCellValue('A4', 'Salah Fisik');
$sheet7->setCellValue('B4', $pnSummary['physical']);
$sheet7->setCellValue('A5', 'Blok S');
$sheet7->setCellValue('B5', $pnSummary['block_s']);
$sheet7->setCellValue('A6', 'Belum Selesai');
$sheet7->setCellValue('B6', $pnSummary['unresolved']);
$sheet7->setCellValue('A7', 'Selesai');
$sheet7->setCellValue('B7', $pnSummary['resolved']);
$sheet7->getColumnDimension('A')->setWidth(22);
$sheet7->getColumnDimension('B')->setWidth(14);

// Table headers
$sheet7->setCellValue('A9', 'No');
$sheet7->setCellValue('B9', 'Jenis');
$sheet7->setCellValue('C9', 'Area');
$sheet7->setCellValue('D9', 'Material');
$sheet7->setCellValue('E9', 'Inventory #');
$sheet7->setCellValue('F9', 'Deskripsi');
$sheet7->setCellValue('G9', 'Catatan');
$sheet7->setCellValue('H9', 'Status');
$sheet7->setCellValue('I9', 'Catatan Penyelesaian');
$sheet7->setCellValue('J9', 'Tanggal');
styleHeader($sheet7, 'A9:J9', 'FFDEEBF7');

// Fill data
$row = 10;
$no = 1;
foreach ($pnList as $n) {
    $isResolved = !is_null($n['resolved_at']);
    $sheet7->setCellValue('A' . $row, $no++);
    $sheet7->setCellValue('B' . $row, $n['issue_type'] === 'block_s' ? 'Blok S' : 'Salah Fisik');
    $sheet7->setCellValue('C' . $row, htmlspecialchars($n['area'] ?? '-'));
    $sheet7->setCellValue('D' . $row, htmlspecialchars($n['material'] ?? '-'));
    $sheet7->setCellValue('E' . $row, htmlspecialchars($n['inventory_number'] ?? '-'));
    $sheet7->getCell('E' . $row)->setDataType(DataType::TYPE_STRING);
    $sheet7->setCellValue('F' . $row, htmlspecialchars($n['material_description'] ?? '-'));
    $sheet7->setCellValue('G' . $row, htmlspecialchars($n['note'] ?? '-'));
    $sheet7->setCellValue('H' . $row, $isResolved ? 'Selesai' : 'Belum');
    $sheet7->setCellValue('I' . $row, htmlspecialchars($n['resolution_notes'] ?? '-'));
    $sheet7->setCellValue('J' . $row, $isResolved ? date('Y-m-d H:i', strtotime($n['resolved_at'])) : date('Y-m-d H:i', strtotime($n['created_at'])));
    $row++;
}

// Column widths and borders
foreach (['A'=>6,'B'=>12,'C'=>14,'D'=>16,'E'=>16,'F'=>24,'G'=>28,'H'=>12,'I'=>24,'J'=>18] as $colKey => $w) {
    $sheet7->getColumnDimension($colKey)->setWidth($w);
}
styleBorders($sheet7, 'A9:J' . max($row-1, 9));

// Download
$filename = 'Stock_Taking_Detailed_Report_' . date('Y-m-d_H-i-s') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;

