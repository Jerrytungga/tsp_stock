<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'db.php';
if (!$pdo) { die("Database connection failed. Please check your database credentials."); }
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

// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pic (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      nrp VARCHAR(50) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backward compatibility: add nrp column if table existed without it
    $col = $pdo->query("SHOW COLUMNS FROM pic LIKE 'nrp'")->fetch();
    if (!$col) {
      $pdo->exec("ALTER TABLE pic ADD COLUMN nrp VARCHAR(50) NOT NULL AFTER name");
    }
} catch (Exception $e) {
    die('Error preparing table: ' . $e->getMessage());
}

// Handle add
if (isset($_POST['add_pic'])) {
    $name = trim($_POST['name'] ?? '');
    $nrp = trim($_POST['nrp'] ?? '');

    if ($name !== '' && $nrp !== '') {
      $stmt = $pdo->prepare("INSERT INTO pic (name, nrp) VALUES (?, ?)");
      $stmt->execute([$name, $nrp]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}

// Handle delete
if (isset($_POST['delete_pic'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM pic WHERE id = ?");
        $stmt->execute([$id]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1');
        exit;
    }
}

// Fetch list
try {
    $result = $pdo->query("SELECT * FROM pic ORDER BY created_at DESC, id DESC");
    $pics = $result ? $result->fetchAll() : [];
} catch (Exception $e) {
    $pics = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include __DIR__ . '/layouts/preloader.html'; ?>
  <?php include __DIR__ . '/layouts/sidebar.html'; ?>
  <?php include __DIR__ . '/layouts/header.html'; ?>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">PIC (Person in Charge)</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item" aria-current="page">PIC</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-lg-4 mb-3">
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-3">Tambah PIC</h6>
              <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success py-2">Data PIC disimpan.</div>
              <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-danger py-2">Nama wajib diisi.</div>
              <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-warning py-2">Data dihapus.</div>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="add_pic" value="1">
                <div class="mb-2">
                  <label class="form-label">Nama*</label>
                  <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">NRP*</label>
                  <input type="text" name="nrp" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Simpan</button>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-8 mb-3">
          <div class="card">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">Daftar PIC</h6>
                <span class="text-muted small">Total: <?php echo count($pics); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                  <thead>
                    <tr>
                      <th>Nama</th>
                      <th>NRP</th>
                      <th>Created</th>
                      <th style="width:60px;">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($pics)): ?>
                      <tr><td colspan="6" class="text-center text-muted">Belum ada data.</td></tr>
                    <?php else: ?>
                      <?php foreach ($pics as $p): ?>
                        <tr>
                          <td><?php echo htmlspecialchars($p['name']); ?></td>
                          <td><?php echo htmlspecialchars($p['nrp'] ?? ''); ?></td>
                          <td><?php echo htmlspecialchars($p['created_at']); ?></td>
                          <td>
                            <form method="post" onsubmit="return confirm('Hapus data ini?');">
                              <input type="hidden" name="delete_pic" value="1">
                              <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                              <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <?php include __DIR__ . '/layouts/footer.html'; ?>
  <?php include __DIR__ . '/layouts/scripts.html'; ?>
</body>
</html>

