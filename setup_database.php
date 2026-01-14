<?php
// Script untuk membuat database dan tabel inventory

$host = 'localhost';
$username = 'root';
$password = '';

try {
    // Koneksi tanpa database untuk membuat database
    $pdo = new PDO("mysql:host=$host;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Membuat database jika belum ada
    $pdo->exec("CREATE DATABASE IF NOT EXISTS pst_project");
    echo "Database 'pst_project' berhasil dibuat atau sudah ada.<br>";

    // Koneksi ke database pst_project
    $pdo->exec("USE pst_project");

    // Membuat tabel inventory
    $sql = "CREATE TABLE IF NOT EXISTS inventory (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mn_ptfi VARCHAR(20),
        kode_part VARCHAR(20),
        nama_part VARCHAR(255),
        kategori VARCHAR(50),
        min_qty INT,
        max_qty INT,
        berat VARCHAR(50),
        jenis_part VARCHAR(50),
        jumlah_lokasi INT,
        dimensi VARCHAR(50),
        lokasi VARCHAR(50),
        harga VARCHAR(50),
        stok INT
    )";
    $pdo->exec($sql);
    echo "Tabel 'inventory' berhasil dibuat atau sudah ada.<br>";

    // Insert data sampel jika tabel kosong
    $stmt = $pdo->query("SELECT COUNT(*) FROM inventory");
    $count = $stmt->fetchColumn();
    if ($count == 0) {
        $data = [
            ['40002644', '2313833210', 'LOCKNUT, W/O INSERT, 5/8"-18 NF,', 'Part', 1720, 2580, '2 kilo', 'Nut', 1, 'kecil', '103A0101', 'Rp. 20.000', 450],
            ['40002645', '2313833211', 'BOLT, HEX, 1/2"-13 UNC, 2"', 'Part', 1500, 2250, '1.5 kilo', 'Bolt', 2, 'sedang', '103A0102', 'Rp. 15.000', 320],
            ['40002646', '2313833212', 'WASHER, FLAT, 1/2"', 'Part', 2000, 3000, '0.5 kilo', 'Washer', 3, 'kecil', '103A0103', 'Rp. 5.000', 800],
            ['40002647', '2313833213', 'NUT, HEX, 3/8"-16 UNC', 'Part', 1800, 2700, '0.8 kilo', 'Nut', 1, 'kecil', '103A0104', 'Rp. 8.000', 600],
            ['40002648', '2313833214', 'SCREW, MACHINE, 1/4"-20 UNC', 'Part', 2200, 3300, '0.3 kilo', 'Screw', 2, 'kecil', '103A0105', 'Rp. 3.000', 1000],
            ['40002649', '2313833215', 'BEARING, BALL, 6205', 'Part', 100, 150, '0.2 kilo', 'Bearing', 1, 'kecil', '103A0106', 'Rp. 50.000', 50],
            ['40002650', '2313833216', 'CHAIN, ROLLER, 40-1', 'Part', 50, 75, '5 kilo', 'Chain', 1, 'besar', '103A0107', 'Rp. 200.000', 20],
            ['40002651', '2313833217', 'GEAR, SPUR, 20T', 'Part', 30, 45, '3 kilo', 'Gear', 1, 'sedang', '103A0108', 'Rp. 150.000', 15],
            ['40002652', '2313833218', 'SEAL, OIL, 50x70x10', 'Part', 200, 300, '0.1 kilo', 'Seal', 2, 'kecil', '103A0109', 'Rp. 25.000', 100],
            ['40002653', '2313833219', 'BELT, TIMING, 100XL', 'Part', 80, 120, '1 kilo', 'Belt', 1, 'sedang', '103A0110', 'Rp. 75.000', 40]
        ];

        $stmt = $pdo->prepare("INSERT INTO inventory (mn_ptfi, kode_part, nama_part, kategori, min_qty, max_qty, berat, jenis_part, jumlah_lokasi, dimensi, lokasi, harga, stok) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($data as $row) {
            $stmt->execute($row);
        }
        echo "Data sampel berhasil dimasukkan ke tabel 'inventory'.<br>";
    } else {
        echo "Tabel 'inventory' sudah berisi data.<br>";
    }

    echo "Setup database selesai!";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
