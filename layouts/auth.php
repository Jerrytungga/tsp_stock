<?php
// Global auth guard: redirect to login.php if not authenticated.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Allow access to these scripts even when not logged in (setup/login/logout, PIC login page)
$allowed = ['login.php','setup_users_table.php','logout.php','pic_stock_taking.php'];
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
// Permit access when admin user or PIC is logged in
$isLogged = !empty($_SESSION['user_id']) || !empty($_SESSION['pic_id']);
if (!in_array($script, $allowed) && !$isLogged) {
  header('Location: login.php');
  exit;
}
?>