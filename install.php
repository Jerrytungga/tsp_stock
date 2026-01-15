<?php
// Script to run composer install via web interface
// WARNING: Delete this file after use for security reasons

echo "<h1>Composer Install Script</h1>";
echo "<p>Running composer install...</p>";
echo "<pre>";

// Check if composer.phar exists
if (!file_exists('composer.phar')) {
    echo "Error: composer.phar not found. Please upload composer.phar to this directory first.\n";
    echo "Download from: https://getcomposer.org/download/\n";
    exit;
}

// Run composer install
$command = 'php composer.phar install --no-dev --optimize-autoloader 2>&1';
exec($command, $output, $returnCode);

echo "Command: $command\n";
echo "Return Code: $returnCode\n\n";
echo "Output:\n";
foreach ($output as $line) {
    echo htmlspecialchars($line) . "\n";
}

if ($returnCode === 0) {
    echo "\nSUCCESS: Composer install completed.\n";
    echo "Please delete this install.php file for security.\n";
} else {
    echo "\nERROR: Composer install failed. Check the output above.\n";
}

echo "</pre>";
?>