<?php
// Run this script once (via browser or CLI) to ensure the `users` table exists and an admin user is seeded.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(200) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "users table ensured.\n";

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, name) VALUES (?, ?, ?)');
        $stmt->execute(['admin', $hash, 'Administrator']);
        echo "Seeded admin user (username: admin, password: admin123). Please change the password after first login.\n";
    } else {
        echo "Users exist (count={$count}). No seed performed.\n";
    }
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}

