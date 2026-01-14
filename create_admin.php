<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

// One-off script to create a default admin user.
// Usage: open this file in the browser then delete it.
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(200) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $username = 'admin';
  $password = 'admin123';
  $name = 'Administrator';

  $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
  $stmt->execute([$username]);
  if ($stmt->fetch()) {
    echo 'Admin user already exists.';
    echo '<p><a href="login.php">Kembali ke login</a></p>';
    exit;
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);
  $ins = $pdo->prepare('INSERT INTO users (username, password_hash, name) VALUES (?, ?, ?)');
  $ins->execute([$username, $hash, $name]);

  echo 'Admin user created successfully.';
  echo <<<'HTML'
<p>Username: <strong>admin</strong> - Password: <strong>admin123</strong></p>
<p><a href="login.php">Go to login</a></p>
<p><strong>Important:</strong> Delete this file (create_admin.php) after use.</p>
HTML;
} catch (Exception $e) {
  echo 'Error: ' . htmlspecialchars($e->getMessage());
}

?>
