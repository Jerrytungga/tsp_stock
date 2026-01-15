<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration
// Ganti dengan kredensial database hosting Anda
$host = getenv('DB_HOST') ?: 'localhost'; // Atau ganti langsung, misal 'your-host.com'
$dbname = getenv('DB_NAME') ?: 'pst_project'; // Nama database di hosting
$username = getenv('DB_USER') ?: 'root'; // Username database di hosting
$password = getenv('DB_PASS') ?: ''; // Password database di hosting

try {
    // Membuat koneksi PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    
    // Mengatur mode error PDO ke exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Mengatur mode fetch default ke associative array
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Jika koneksi berhasil, Anda bisa menambahkan pesan atau log di sini
    // echo "Koneksi database berhasil!";
    
} catch (PDOException $e) {
    // Jika koneksi gagal, tampilkan pesan error
    die("Koneksi database gagal: " . $e->getMessage());
}
?>
