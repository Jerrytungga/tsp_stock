<?php
// Include file koneksi database
include 'db.php';

// Tes koneksi dengan query sederhana
try {
    // Query untuk mendapatkan versi MySQL
    $stmt = $pdo->query("SELECT VERSION() as version");
    $result = $stmt->fetch();
    
    echo "Koneksi database berhasil! Versi MySQL: " . $result['version'];
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
