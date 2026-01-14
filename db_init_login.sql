-- DB initialization for PST Project login
-- Run this in MySQL client if you need to create the DB and users table manually.

CREATE DATABASE IF NOT EXISTS `pst_project` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pst_project`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(200) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: insert an admin user (password: admin123)
-- Replace the hash below with a hash you generate via PHP's password_hash if you prefer
-- INSERT INTO users (username, password_hash, name) VALUES ('admin', '<PASTE_HASH_HERE>', 'Administrator');
