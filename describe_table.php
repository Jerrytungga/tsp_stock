<?php
include 'db.php';

try {
    $stmt = $pdo->query("DESCRIBE stock_taking");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Struktur tabel stock_taking:\n";
    foreach ($columns as $col) {
        echo $col['Field'] . " - " . $col['Type'] . " - " . ($col['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . " - " . ($col['Key'] ? $col['Key'] : '') . " - " . ($col['Default'] ? $col['Default'] : 'NO DEFAULT') . " - " . ($col['Extra'] ? $col['Extra'] : '') . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>