<?php
// Test script to check if vendor is properly installed
echo "<h1>Vendor Check</h1>";

if (!file_exists('vendor/autoload.php')) {
    echo "<p style='color:red;'>ERROR: vendor/autoload.php not found!</p>";
    exit;
}

echo "<p>vendor/autoload.php exists.</p>";

require 'vendor/autoload.php';

if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    echo "<p style='color:red;'>ERROR: PhpSpreadsheet class not found!</p>";
    exit;
}

echo "<p>PhpSpreadsheet class loaded successfully.</p>";

try {
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    echo "<p>Spreadsheet object created successfully.</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR creating Spreadsheet: " . $e->getMessage() . "</p>";
}

echo "<p>All checks passed. Vendor is ready.</p>";
?>