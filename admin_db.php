<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// Only allow logged-in admin users
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'db.php';

$msg = '';
$err = '';

// Handle create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = $_POST['password'] ?? '';
  $name = trim((string)($_POST['name'] ?? ''));
  if ($username === '' || $password === '') {
    $err = 'Username dan password harus diisi.';
  } else {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare('INSERT INTO users (username,password_hash,name) VALUES (?, ?, ?)');
      $stmt->execute([$username, $hash, $name]);
      $msg = 'User berhasil dibuat.';
    } catch (Exception $e) {
      $err = 'Gagal membuat user: ' . $e->getMessage();
    }
  }
}

// Handle reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
  $id = (int)($_POST['id'] ?? 0);
  $password = $_POST['new_password'] ?? '';
  if (!$id || $password === '') { $err = 'ID atau password tidak valid.'; }
  else {
    try {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
      $stmt->execute([$hash, $id]);
      $msg = 'Password berhasil diubah.';
    } catch (Exception $e) { $err = 'Gagal mereset password: ' . $e->getMessage(); }
  }
}

// Handle delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if (!$id) { $err = 'ID tidak valid.'; }
  elseif ($id === (int)$_SESSION['user_id']) { $err = 'Tidak boleh menghapus akun yang sedang digunakan.'; }
  else {
    try {
      $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
      $stmt->execute([$id]);
      $msg = 'User dihapus.';
    } catch (Exception $e) { $err = 'Gagal menghapus user: ' . $e->getMessage(); }
  }
}

// Fetch users
$users = [];
try { $users = $pdo->query('SELECT id, username, name, created_at FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) { $err = 'Gagal mengambil data users.'; }
?>
<!DOCTYPE html>
<html lang="en">
<?php include 'layouts/head.html'; ?>
<body>
<?php include 'layouts/preloader.html'; ?>
<?php include 'layouts/sidebar.html'; ?>
<?php include 'layouts/header.html'; ?>

<div class="pc-container"><div class="pc-content">
  <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4>Admin — Kontrol Database</h4>
      <div>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Back</a>
      </div>
    </div>

    <?php if ($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-6">
        <div class="card">
          <div class="card-body">
            <h6>Buat User Baru</h6>
            <form method="post" class="mt-2">
              <input type="hidden" name="action" value="create">
              <div class="mb-2"><label class="form-label">Username</label><input name="username" class="form-control"></div>
              <div class="mb-2"><label class="form-label">Password</label><input name="password" type="password" class="form-control"></div>
              <div class="mb-2"><label class="form-label">Nama (opsional)</label><input name="name" class="form-control"></div>
              <button class="btn btn-primary">Buat User</button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="card">
          <div class="card-body">
            <h6>Jalankan Setup</h6>
            <p class="small-muted">Jika tabel users belum ada, jalankan setup untuk membuat tabel dan menanam admin default.</p>
            <a href="setup_users_table.php" target="_blank" class="btn btn-outline-primary">Run setup_users_table.php</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body">
        <h6>Daftar User</h6>
        <div class="table-responsive mt-2">
          <table class="table table-sm">
            <thead><tr><th>ID</th><th>Username</th><th>Nama</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td><?php echo (int)$u['id']; ?></td>
                  <td><?php echo htmlspecialchars($u['username']); ?></td>
                  <td><?php echo htmlspecialchars($u['name']); ?></td>
                  <td><?php echo htmlspecialchars($u['created_at']); ?></td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary" onclick="showReset(<?php echo (int)$u['id']; ?>,'<?php echo htmlspecialchars(addslashes($u['username'])); ?>')">Reset PW</button>
                    <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                      <form method="post" style="display:inline" onsubmit="return confirm('Hapus user ini?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                        <button class="btn btn-sm btn-outline-danger">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div></div>

<!-- Reset modal -->
<div id="resetModal" class="modal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="id" id="resetId">
        <div class="modal-header"><h5 class="modal-title">Reset Password</h5><button type="button" class="btn-close" onclick="hideReset()"></button></div>
        <div class="modal-body">
          <p id="resetUser"></p>
          <div class="mb-2"><label>New Password</label><input name="new_password" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="hideReset()">Batal</button><button class="btn btn-primary">Simpan</button></div>
      </form>
    </div>
  </div>
</div>

<?php include 'layouts/footer.html'; ?>
<?php include 'layouts/scripts.html'; ?>
<script>
  function showReset(id, username){ document.getElementById('resetId').value = id; document.getElementById('resetUser').innerText = 'Reset password untuk: '+username; document.getElementById('resetModal').style.display = 'block'; }
  function hideReset(){ document.getElementById('resetModal').style.display = 'none'; }
</script>
</body>
</html>
