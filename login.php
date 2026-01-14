<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

// Ensure users table exists (do not auto-seed admin here for safety)
$no_users = false;
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(200) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  $c = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  if ($c === 0) {
    $no_users = true;
    $error = 'Belum ada akun terdaftar. Jalankan <a href="setup_users_table.php">setup_users_table.php</a> untuk membuat akun admin.';
  }
} catch (Exception $e) {
  $error = 'Gagal memeriksa tabel users.';
}

$error = '';
if (isset($_GET['logout'])) {
  unset($_SESSION['user_id'], $_SESSION['user_name']);
  header('Location: login.php'); exit;
}

if (!$no_users && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = $_POST['password'] ?? '';
  if ($username === '' || $password === '') {
    $error = 'Username dan password wajib diisi.';
  } else {
    try {
      $stmt = $pdo->prepare('SELECT id, username, password_hash, name FROM users WHERE username = ? LIMIT 1');
      $stmt->execute([$username]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($u && password_verify($password, $u['password_hash'])) {
        $_SESSION['user_id'] = (int)$u['id'];
        $_SESSION['user_name'] = $u['name'] ?: $u['username'];
        header('Location: dashboard.php'); exit;
      } else {
        $error = 'Kredensial salah.';
      }
    } catch (Exception $e) { $error = 'Terjadi kesalahan saat login.'; }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body>
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<style>
  body.login-page{ background: radial-gradient(1200px 600px at 10% 20%, rgba(255,255,255,0.04), transparent), linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); min-height:100vh; display:flex; align-items:center; }
  .login-card{ max-width:980px; margin:40px auto; box-shadow:0 20px 50px rgba(2,6,23,0.18); border-radius:14px; overflow:hidden; display:flex; }
  .login-left{ background:linear-gradient(180deg,#6366f1,#06b6d4); color:#fff; padding:42px; min-height:420px; display:flex; flex-direction:column; justify-content:center; align-items:flex-start; gap:12px }
  .login-left h2{ margin-bottom:6px; font-weight:800; color:#fff; font-size:28px }
  .login-left p{ opacity:0.95; color:rgba(255,255,255,0.9) }
  .brand-badge{ display:inline-block; background:rgba(255,255,255,0.12); padding:8px 14px; border-radius:10px; margin-bottom:18px; font-weight:600 }
  .illustration { margin-top:18px; width:100%; max-width:260px; opacity:0.95 }
  .login-right{ background:#fff; padding:44px; display:flex; align-items:center; }
  .login-panel{ width:100%; max-width:420px }
  .field-with-icon{ position:relative }
  .field-with-icon .icon{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#94a3b8 }
  .field-with-icon input{ padding-left:44px; border-radius:10px; }
  .form-control{ height:44px; border:1px solid #e6eef9 }
  .form-control:focus{ box-shadow:0 6px 18px rgba(59,130,246,0.12); border-color:#3b82f6 }
  .toggle-pass{ cursor:pointer; position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#94a3b8 }
  .btn-primary{ background: linear-gradient(90deg,#4f46e5,#06b6d4); border:none; padding:10px 20px; border-radius:10px }
  .btn-primary:hover{ filter:brightness(1.03) }
  .small-muted{ color:#64748b }
  @media (max-width:900px){ .login-left{ display:none } .login-card{ max-width:420px } }
</style>

<div class="pc-container login-page">
  <div class="login-card d-flex">
    <div class="login-left col-md-6">
      <div>
          <div class="brand-badge" style="display:flex;align-items:center;gap:10px;">
            <span style="font-weight:700;color:#fff;font-size:16px;">Triatra Timika Papua</span>
          </div>
          <h2>Selamat Datang</h2>
          <p class="small-muted">Masuk untuk mengelola dan melihat progres stock taking. Pastikan kredensial Anda aman.</p>
          <img class="illustration" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 600 400'><defs><linearGradient id='g' x1='0' x2='1'><stop offset='0' stop-color='%23ffffff' stop-opacity='0.18'/><stop offset='1' stop-color='%23ffffff' stop-opacity='0.06'/></linearGradient></defs><rect rx='20' width='600' height='400' fill='url(%23g)'/><g fill='none' stroke='%23ffffff' stroke-opacity='0.9' stroke-linecap='round' stroke-linejoin='round' stroke-width='2'><path d='M100 280c40-80 120-120 200-120s160 40 200 120'/><circle cx='200' cy='160' r='36'/><rect x='320' y='120' width='140' height='90' rx='14'/></g></svg>" alt="illustration">
      </div>
      <div style="margin-top:20px;">
        <small class="small-muted">Tips: ubah password default setelah login.</small>
      </div>
    </div>
    <div class="login-right col-md-6">
      <div style="max-width:400px;margin:0 auto;">
        <h4 class="mb-1">Masuk ke Sistem</h4>
        <p class="small-muted mb-3">Gunakan akun yang terdaftar untuk mengakses dashboard.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <div class="mb-3 field-with-icon">
            <span class="icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12c2.761 0 5-2.239 5-5s-2.239-5-5-5-5 2.239-5 5 2.239 5 5 5zM3 21c0-3.866 3.582-7 9-7s9 3.134 9 7" stroke="#6b7280" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <input name="username" class="form-control" placeholder="Nama Pengguna" autofocus>
          </div>
          <div class="mb-3 field-with-icon" style="position:relative;">
            <span class="icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="11" width="18" height="11" rx="2" stroke="#6b7280" stroke-width="1.2"/><path d="M7 11V8a5 5 0 0110 0v3" stroke="#6b7280" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <input id="password" name="password" type="password" class="form-control" placeholder="Kata Sandi">
            <span class="toggle-pass" id="togglePass"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke="#6b7280" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M2.05 12.55C3.78 7.46 7.8 4 12 4c4.2 0 8.22 3.46 9.95 8.55a1 1 0 010 .9C20.22 18.54 16.2 22 12 22c-4.2 0-8.22-3.46-9.95-8.55a1 1 0 010-.9z" stroke="#6b7280" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <label class="form-check-label small-muted"><input type="checkbox" class="form-check-input me-1"> Ingat saya</label>
            </div>
            <div><a href="#" class="small-muted">Lupa password?</a></div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div class="small-muted">Default: <strong>admin</strong> / <strong>admin123</strong></div>
            <button name="login" class="btn btn-primary">Masuk</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/layouts/scripts.html'; ?>
<script>
  (function(){
    var t = document.getElementById('togglePass');
    if (!t) return;
    t.addEventListener('click', function(){
      var p = document.getElementById('password');
      if (!p) return;
      p.type = p.type === 'password' ? 'text' : 'password';
    });
  })();
</script>
</body>
</html>

