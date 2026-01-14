<?php
// ensure session is started early so handlers can access $_SESSION
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

// ensure columns for review exist (safe to attempt; ignore errors if already present)
try {
  $pdo->exec("ALTER TABLE stock_taking ADD COLUMN needs_review TINYINT(1) NOT NULL DEFAULT 0");
} catch (Exception $e) { /* ignore if exists */ }
try {
  $pdo->exec("ALTER TABLE stock_taking ADD COLUMN temp_description TEXT NULL");
} catch (Exception $e) { /* ignore if exists */ }

// Handle bulk save POST from this page
if (isset($_POST['save_all'])) {
  $new_stocks = $_POST['new_stock'] ?? [];
  $new_locs = $_POST['new_location'] ?? [];
  try {
        // ensure history table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_taking_history (
          id INT AUTO_INCREMENT PRIMARY KEY,
          stock_taking_id INT NOT NULL,
          changed_by INT NULL,
          old_available_stock TEXT,
          new_available_stock TEXT,
          old_storage_bin TEXT,
          new_storage_bin TEXT,
          note TEXT,
          ip VARCHAR(45) NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pdo->beginTransaction();
    foreach (array_unique(array_merge(array_keys($new_stocks), array_keys($new_locs))) as $id) {
      $id = (int)$id;
      $parts = [];
      $params = [];
          // fetch old values for history
          $oldAvailable = null; $oldStorage = null;
          try {
            $s = $pdo->prepare('SELECT available_stock, storage_bin FROM stock_taking WHERE id = ? LIMIT 1');
            $s->execute([$id]);
            $oldRow = $s->fetch(PDO::FETCH_ASSOC);
            if ($oldRow) {
              $oldAvailable = $oldRow['available_stock'];
              $oldStorage = $oldRow['storage_bin'];
            }
          } catch (Exception $e) {
            // ignore
          }

          if (isset($new_stocks[$id]) && $new_stocks[$id] !== '') {
        $parts[] = 'new_available_stock = ?';
        $params[] = $new_stocks[$id];
      }
      if (isset($new_locs[$id]) && $new_locs[$id] !== '') {
        $parts[] = 'new_storage_bin = ?';
        $params[] = $new_locs[$id];
      }
      if (!empty($parts)) {
        $params[] = $id;
        $sql = 'UPDATE stock_taking SET '.implode(', ', $parts).' WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

            // insert history row
            try {
              $hstmt = $pdo->prepare('INSERT INTO stock_taking_history (stock_taking_id, changed_by, old_available_stock, new_available_stock, old_storage_bin, new_storage_bin, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
              $hstmt->execute([
                $id,
                isset($_SESSION['pic_id']) ? $_SESSION['pic_id'] : null,
                $oldAvailable,
                isset($new_stocks[$id]) ? $new_stocks[$id] : null,
                $oldStorage,
                isset($new_locs[$id]) ? $new_locs[$id] : null,
                $_SERVER['REMOTE_ADDR'] ?? null
              ]);
            } catch (Exception $e) {
              // ignore history insertion errors
            }
      }
    }
    $pdo->commit();
    header('Location: '.$_SERVER['PHP_SELF'].'?saved=1');
    exit;
  } catch (Exception $e) {
    $pdo->rollBack();
    header('Location: '.$_SERVER['PHP_SELF'].'?error='.urlencode($e->getMessage()));
    exit;
  }
}

// Handle single-row update POST (backwards compatible)
if (isset($_POST['update'])) {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    try {
      $parts = [];
      $params = [];
            // fetch old values for history
            $oldAvailable = null; $oldStorage = null;
            try {
              $s = $pdo->prepare('SELECT available_stock, storage_bin FROM stock_taking WHERE id = ? LIMIT 1');
              $s->execute([$id]);
              $oldRow = $s->fetch(PDO::FETCH_ASSOC);
              if ($oldRow) {
                $oldAvailable = $oldRow['available_stock'];
                $oldStorage = $oldRow['storage_bin'];
              }
            } catch (Exception $e) {
              // ignore
            }
      if (!is_null($new_stock)) {
        $parts[] = 'new_available_stock = ?';
        $params[] = $new_stock;
      }
      if (!is_null($new_location)) {
        $parts[] = 'new_storage_bin = ?';
        $params[] = $new_location;
      }
      if (!empty($parts)) {
        $params[] = $id;
        $stmt = $pdo->prepare('UPDATE stock_taking SET '.implode(', ', $parts).' WHERE id = ?');
        $stmt->execute($params);
                // log history
                try {
                  $pdo->exec("CREATE TABLE IF NOT EXISTS stock_taking_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    stock_taking_id INT NOT NULL,
                    changed_by INT NULL,
                    old_available_stock TEXT,
                    new_available_stock TEXT,
                    old_storage_bin TEXT,
                    new_storage_bin TEXT,
                    note TEXT,
                    ip VARCHAR(45) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
                  $hstmt = $pdo->prepare('INSERT INTO stock_taking_history (stock_taking_id, changed_by, old_available_stock, new_available_stock, old_storage_bin, new_storage_bin, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
                  $hstmt->execute([
                    $id,
                    isset($_SESSION['pic_id']) ? $_SESSION['pic_id'] : null,
                    $oldAvailable,
                    $new_stock,
                    $oldStorage,
                    $new_location,
                    $_SERVER['REMOTE_ADDR'] ?? null
                  ]);
                } catch (Exception $e) {
                  // ignore history errors
                }
      }
      header('Location: '.$_SERVER['PHP_SELF'].'?updated=1');
      exit;
    } catch (Exception $e) {
      header('Location: '.$_SERVER['PHP_SELF'].'?error='.urlencode($e->getMessage()));
      exit;
    }
  }


// NRP-based PIC login: PIC must submit NRP to view their assigned items
$login_error = '';
if (isset($_POST['nrp_login'])) {
    $nrp = trim((string)($_POST['nrp_login'] ?? ''));
    if ($nrp === '') {
        $login_error = 'NRP tidak boleh kosong.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, name, nrp FROM pic WHERE nrp = ? LIMIT 1');
            $stmt->execute([$nrp]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p) {
              $_SESSION['pic_id'] = (int)$p['id'];
              $_SESSION['pic_name'] = $p['name'];
              // mark login success so we can show a SweetAlert after redirect
              $_SESSION['login_success'] = true;
              header('Location: '.$_SERVER['PHP_SELF']);
              exit;
            } else {
                $login_error = 'NRP tidak ditemukan.';
            }
        } catch (Exception $e) {
            $login_error = 'Terjadi kesalahan saat mencari NRP.';
        }
    }
}

// Handle mark-as-unknown POST from PIC
if (isset($_POST['mark_unknown'])) {
  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $note = isset($_POST['unknown_note']) ? trim((string)$_POST['unknown_note']) : null;
  $new_stock = isset($_POST['unknown_new_stock']) ? trim((string)$_POST['unknown_new_stock']) : null;
  $new_loc = isset($_POST['unknown_new_location']) ? trim((string)$_POST['unknown_new_location']) : null;
  if ($id) {
    try {
      // fetch old values
      $oldAvailable = null; $oldStorage = null;
      try {
        $s = $pdo->prepare('SELECT available_stock, storage_bin FROM stock_taking WHERE id = ? LIMIT 1');
        $s->execute([$id]);
        $oldRow = $s->fetch(PDO::FETCH_ASSOC);
        if ($oldRow) { $oldAvailable = $oldRow['available_stock']; $oldStorage = $oldRow['storage_bin']; }
      } catch (Exception $e) { }

      // update row: set provided new values, mark needs_review and save temp_description
      $stmt = $pdo->prepare('UPDATE stock_taking SET new_available_stock = ?, new_storage_bin = ?, temp_description = ?, needs_review = 1 WHERE id = ?');
      $stmt->execute([$new_stock, $new_loc, $note, $id]);

      // insert history
      try {
        $hstmt = $pdo->prepare('INSERT INTO stock_taking_history (stock_taking_id, changed_by, old_available_stock, new_available_stock, old_storage_bin, new_storage_bin, note, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $hstmt->execute([
          $id,
          isset($_SESSION['pic_id']) ? $_SESSION['pic_id'] : null,
          $oldAvailable,
          $new_stock,
          $oldStorage,
          $new_loc,
          $note,
          $_SERVER['REMOTE_ADDR'] ?? null
        ]);
      } catch (Exception $e) { }

    } catch (Exception $e) {
      // ignore
    }
  }
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['pic_id'], $_SESSION['pic_name']);
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

$selected_pic = isset($_SESSION['pic_id']) ? (int)$_SESSION['pic_id'] : null;
$selected_mode = $selected_pic ? 'pic' : null;

// Fetch items depending on logged-in PIC
$items = [];
try {
  if ($selected_mode === 'pic' && $selected_pic) {
    $stmt = $pdo->prepare('SELECT st.* FROM stock_taking st WHERE st.assigned_pic_id = ? ORDER BY st.id');
    $stmt->execute([$selected_pic]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
} catch (Exception $e) {
  $items = [];
}

// handle history update (edit note)
if (isset($_POST['history_update'])) {
  $hid = isset($_POST['history_id']) ? (int)$_POST['history_id'] : 0;
  $note = isset($_POST['history_note']) ? $_POST['history_note'] : null;
  if ($hid) {
    try {
      $stmt = $pdo->prepare('UPDATE stock_taking_history SET note = ? WHERE id = ?');
      $stmt->execute([$note, $hid]);
    } catch (Exception $e) {
      // ignore
    }
  }
  header('Location: '.$_SERVER['PHP_SELF']);
  exit;
}
?>
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<style>
/* Friendly table styling */
.table-friendly thead th { background: linear-gradient(90deg,#42a5f5,#1e88e5); color: #fff; border-bottom: none; }
.table-friendly { border-radius: 6px; overflow: hidden; }
.table-friendly tbody tr.needs-review-row { background: #fff4e6 !important; border-left: 4px solid #ffb74d; }
.table-friendly tbody tr.changed-row { background: #e8f5e9 !important; border-left: 4px solid #66bb6a; }
.table-friendly tbody tr:hover { box-shadow: inset 0 0 0 9999px rgba(255,255,255,0.02); }
.note-text { color: #b71c1c; font-weight: 600; }
.small-note { font-size: 0.85em; color: #6b4b00; }
/* Hero header styles */
.page-hero { padding: 18px; }
.page-hero .hero-icon { width:64px; height:64px; display:flex; align-items:center; justify-content:center; font-size:28px; border-radius:50%; background:rgba(255,255,255,0.12); }
.page-hero .hero-meta { color: rgba(255,255,255,0.95); }
.page-hero .badge-light-alt { background: rgba(255,255,255,0.15); color: #fff; border-radius: 999px; padding: 6px 10px; font-weight:600; }
/* Mobile adjustments */
.page-hero .d-flex { flex-wrap:wrap; gap:8px; }
@media (max-width: 576px) {
  .page-hero { padding: 12px; }
  .page-hero .hero-icon { width:56px; height:56px; font-size:24px; margin-bottom:6px; }
  .page-hero .hero-meta { width:100%; }
  .page-hero .text-end { width:100%; text-align:left; margin-top:6px; }
  .page-hero .badge-light-alt { display:inline-block; margin-right:8px; margin-top:6px; }
  nav[aria-label="breadcrumb"], .breadcrumb { display: none !important; }
  .table-friendly thead th, .table-friendly td { font-size: 0.92rem; }
  .table-friendly td, .table-friendly th { white-space: normal; word-break: break-word; }
  .btn.btn-sm { padding: .45rem .6rem; font-size: .95rem; }
  /* On phone: hide inventory columns and emphasize description */
  #resultsTable th:nth-child(2), #resultsTable td:nth-child(2),
  #itemsTable th:nth-child(4), #itemsTable td:nth-child(4) { display: none !important; }
  /* make description column visually dominant */
  #resultsTable th:nth-child(3), #resultsTable td:nth-child(3),
  #itemsTable th:nth-child(3), #itemsTable td:nth-child(3) { width: 100%; }
}
</style>
<!-- Sidebar and navbar removed for simplified PIC view -->
<?php
  // compute simple counts for the hero header
  $pending_count = 0; $changes_count = 0;
  if (!empty($items) && is_array($items)) {
    foreach ($items as $it) {
      if (!isset($it['new_available_stock']) || $it['new_available_stock'] === '') $pending_count++;
      if ((isset($it['new_available_stock']) && $it['new_available_stock'] !== '') || (isset($it['new_storage_bin']) && $it['new_storage_bin'] !== '')) $changes_count++;
    }
  }
?>
<div class="container py-4">
  <div class="page-hero rounded-3 mb-3" style="background: linear-gradient(135deg,#1e88e5 0,#21cbf3 100%);">
    <div class="d-flex align-items-center">
      <div class="me-3">
        <div class="hero-icon" aria-hidden="true">📋</div>
      </div>
      <div class="flex-fill hero-meta">
        <h3 class="h5 mb-1" style="color:#fff; margin:0;">Stock Taking per PIC</h3>
        <div class="small" style="opacity:0.95;">Halaman input untuk PIC <strong><?php echo htmlspecialchars($_SESSION['pic_name'] ?? ''); ?></strong>. Masukkan stok dan lokasi terbaru, lalu tekan <strong>Save All</strong>.</div>
      </div>
      <div class="text-end">
        <div class="mb-1">
          <span class="badge-light-alt">Pending: <?php echo $pending_count; ?></span>
          <span class="badge-light-alt" style="margin-left:8px;">Saved: <?php echo $changes_count; ?></span>
        </div>
        <div class="small mt-1"><a href="stock_taking.php" style="color:rgba(255,255,255,0.9); text-decoration:underline;">Kembali ke daftar</a></div>
      </div>
    </div>
  </div>
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Home</a></li>
      <li class="breadcrumb-item"><a href="stock_taking.php" class="text-decoration-none">Stock Taking</a></li>
      <li class="breadcrumb-item active" aria-current="page">Per PIC</li>
    </ol>
  </nav>

    <div class="row">
      <div class="col-12 mb-3">
        <?php if (empty($selected_pic)): ?>
          <form method="post" class="row g-2 align-items-center">
            <div class="col-auto">
              <label class="form-label">Masukkan NRP</label>
              <input type="text" name="nrp_login" class="form-control form-control-sm" placeholder="NRP">
            </div>
            <div class="col-auto align-self-end">
              <button type="submit" class="btn btn-sm btn-primary">Masuk</button>
            </div>
            <?php if ($login_error): ?><div class="col-12 text-danger small"><?php echo htmlspecialchars($login_error); ?></div><?php endif; ?>
          </form>
        <?php else: ?>
          <div class="d-flex justify-content-between align-items-center">
            <div>Login sebagai <strong><?php echo htmlspecialchars($_SESSION['pic_name'] ?? ''); ?></strong></div>
            <div><a href="pic_stock_taking.php?logout=1" class="btn btn-sm btn-outline-secondary">Logout</a></div>
          </div>
        <?php endif; ?>
      </div>

              <?php if (!empty($selected_pic)): ?>
              <form method="post">
                <input type="hidden" name="save_all" value="1">
                <div class="table-responsive">
                  <table id="itemsTable" class="table table-sm table-striped table-friendly">
                    <thead>
                        <tr>
                        <th>#</th>
                        <th>Material</th>
                        <th>Part Description</th>
                        <th>Inventory Number</th>
                        <th>Batch</th>
                        <th>PIC</th>
                        <th>Location</th>
                        <th>New Stock</th>
                        <th>New Location</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php if (empty($items)): ?>
                        <tr><td colspan="10" class="text-center text-muted">Tidak ada tugas untuk PIC ini.</td></tr>
                      <?php else: ?>
                        <?php $no = 1; $has_pending = false; foreach ($items as $it):
                          // only show items that do not have a saved new stock yet
                          // treat string '0' as a valid saved value, so check explicitly for empty string
                          if (isset($it['new_available_stock']) && $it['new_available_stock'] !== '') continue;
                          $has_pending = true;
                          $saved_ns = $it['new_available_stock'] ?? ''; $saved_nl = $it['new_storage_bin'] ?? '';
                        ?>
                          <tr class="<?php echo !empty($it['needs_review']) ? 'needs-review-row' : ''; ?>">
                            <td><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($it['material'] ?? ''); ?></td>
                            <td>
                              <?php echo htmlspecialchars($it['material_description'] ?? ''); ?>
                              <?php if (!empty($it['needs_review'])): ?>
                                <div class="small text-warning mt-1">Catatan: <?php echo nl2br(htmlspecialchars($it['temp_description'] ?? '')); ?></div>
                              <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($it['inventory_number'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($it['batch'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($_SESSION['pic_name'] ?? ($it['pic_name'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars($it['storage_bin'] ?? ''); ?></td>
                            <td>
                              <input type="text" name="new_stock[<?php echo $it['id']; ?>]" class="form-control form-control-sm" placeholder="new stock" value="">
                              <?php if ($saved_ns !== ''): ?><div class="small text-muted">Saved: <?php echo htmlspecialchars($saved_ns); ?></div><?php endif; ?>
                            </td>
                            <td>
                              <input type="text" name="new_location[<?php echo $it['id']; ?>]" class="form-control form-control-sm" placeholder="new location" value="">
                              <?php if ($saved_nl !== ''): ?><div class="small text-muted">Saved: <?php echo htmlspecialchars($saved_nl); ?></div><?php endif; ?>
                            </td>
                            <td>
                              <?php if (empty($it['storage_bin'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning mark-unknown" 
                                  data-id="<?php echo $it['id']; ?>" data-inv="<?php echo htmlspecialchars($it['inventory_number'] ?? ''); ?>" data-material="<?php echo htmlspecialchars($it['material'] ?? ''); ?>"
                                >Tidak Dikenal</button>
                              <?php else: ?>
                                &nbsp;
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; if (!$has_pending): ?>
                          <tr><td colspan="10" class="text-center text-muted">Semua item sudah memiliki nilai stok baru.</td></tr>
                        <?php endif; ?>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="mt-2 d-flex justify-content-end">
                  <button type="submit" class="btn btn-sm btn-success">Save All</button>
                </div>
              </form>

              <div class="mt-4 card">
                <div class="card-body">
                  <h6 class="card-title">Hasil Penyimpanan</h6>
                  <div class="table-responsive">
                      <table id="resultsTable" class="table table-sm table-striped table-friendly">
                      <thead>
                        <tr>
                          <th>#</th>
                          <th>Inventory</th>
                          <th>Part Description</th>
                          <th>Material</th>
                          <th>New Stock</th>
                          <th>New Location</th>
                          <th>Keterangan</th>
                          <th>Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php $r=1; $has=false; foreach ($items as $it):
                            $ns = $it['new_available_stock'] ?? '';
                            $nl = $it['new_storage_bin'] ?? '';
                            $old = $it['available_stock'] ?? '';
                            if ($ns !== '' || $nl !== '') { $has=true; 
                              // compute keterangan
                              $keterangan = 'ok';
                              if ($ns !== '' && $ns !== $old) {
                                if (is_numeric($ns) && is_numeric($old)) {
                                  if ((float)$ns < (float)$old) $keterangan = 'short';
                                  elseif ((float)$ns > (float)$old) $keterangan = 'over';
                                  else $keterangan = 'ok';
                                } else {
                                  $keterangan = 'new';
                                }
                              } elseif ($nl !== '' && $nl !== ($it['storage_bin'] ?? '')) {
                                $keterangan = 'new';
                              }
                              $diff = (($ns !== '') && ($ns !== $old)) || (($nl !== '') && ($nl !== ($it['storage_bin'] ?? '')));
                              ?>
                            <tr data-changed="<?php echo $diff ? '1' : '0'; ?>" class="<?php echo ($diff ? 'changed-row' : ''); ?><?php echo !empty($it['needs_review']) ? ' needs-review-row' : ''; ?>">
                              <td><?php echo $r++; ?></td>
                              <td><?php echo htmlspecialchars($it['inventory_number'] ?? ''); ?></td>
                              <td>
                                <?php echo htmlspecialchars($it['material_description'] ?? $it['material'] ?? ''); ?>
                                <?php if (!empty($it['needs_review'])): ?>
                                  <div class="small text-warning mt-1">Catatan: <?php echo nl2br(htmlspecialchars($it['temp_description'] ?? '')); ?></div>
                                <?php endif; ?>
                              </td>
                              <td><?php echo htmlspecialchars($it['material'] ?? ''); ?></td>
                              <td><?php echo htmlspecialchars($ns); ?></td>
                              <td><?php echo htmlspecialchars($nl); ?></td>
                              <td>
                                <?php if (strpos($keterangan, 'short') === 0): ?>
                                  <span class="badge bg-danger"><?php echo htmlspecialchars($keterangan); ?></span>
                                <?php elseif (strpos($keterangan, 'over') === 0): ?>
                                  <span class="badge bg-success"><?php echo htmlspecialchars($keterangan); ?></span>
                                <?php elseif ($keterangan === 'ok'): ?>
                                  <span class="badge bg-secondary">ok</span>
                                <?php elseif ($keterangan === 'new'): ?>
                                  <span class="badge bg-primary">new</span>
                                <?php else: ?>
                                  <span class="badge bg-light text-dark"><?php echo htmlspecialchars($keterangan); ?></span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php if ($diff): ?>
                                  <button class="btn btn-sm btn-outline-primary edit-result" 
                                    data-id="<?php echo $it['id']; ?>"
                                    data-inv="<?php echo htmlspecialchars($it['inventory_number'] ?? ''); ?>"
                                    data-material="<?php echo htmlspecialchars($it['material'] ?? ''); ?>"
                                    data-available="<?php echo htmlspecialchars($it['available_stock'] ?? '', ENT_QUOTES); ?>"
                                    data-new-stock="<?php echo htmlspecialchars($ns, ENT_QUOTES); ?>"
                                    data-new-location="<?php echo htmlspecialchars($nl, ENT_QUOTES); ?>"
                                  >Edit</button>
                                <?php else: ?>
                                  &nbsp;
                                <?php endif; ?>
                              </td>
                            </tr>
                        <?php } endforeach; if (!$has): ?>
                          <tr><td colspan="8" class="text-center text-muted">Tidak ada perubahan yang terlihat.</td></tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              <!-- History section for logged-in PIC -->
              <hr>
              <div class="mt-3">
                <h6>Riwayat Perubahan Anda</h6>
                <?php
                  $hist = [];
                  try {
                    $hst = $pdo->prepare('SELECT h.*, s.inventory_number, s.material FROM stock_taking_history h LEFT JOIN stock_taking s ON s.id = h.stock_taking_id WHERE h.changed_by = ? ORDER BY h.created_at DESC LIMIT 200');
                    $hst->execute([ $selected_pic ]);
                    $hist = $hst->fetchAll(PDO::FETCH_ASSOC);
                  } catch (Exception $e) { $hist = []; }
                ?>
                <div class="table-responsive">
                  <table id="historyTable" class="table table-sm table-striped">
                    <thead>
                      <tr><th>#</th><th>Time</th><th>Inventory</th><th>Old / New Stock</th><th>Old / New Location</th><th>Note</th><th></th></tr>
                    </thead>
                    <tbody>
                      <?php if (empty($hist)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Belum ada riwayat.</td></tr>
                      <?php else: $i=1; foreach ($hist as $h): ?>
                        <tr>
                          <td><?php echo $i++; ?></td>
                          <td><?php echo htmlspecialchars($h['created_at']); ?></td>
                          <td><?php echo htmlspecialchars($h['inventory_number'] ?? $h['stock_taking_id']); ?><br><small><?php echo htmlspecialchars($h['material'] ?? ''); ?></small></td>
                          <td><?php echo htmlspecialchars($h['old_available_stock']); ?> → <strong><?php echo htmlspecialchars($h['new_available_stock']); ?></strong></td>
                          <td><?php echo htmlspecialchars($h['old_storage_bin']); ?> → <strong><?php echo htmlspecialchars($h['new_storage_bin']); ?></strong></td>
                          <td class="note-col"><?php echo nl2br(htmlspecialchars($h['note'] ?? '')); ?></td>
                          <td><button class="btn btn-sm btn-outline-primary edit-history" data-id="<?php echo $h['id']; ?>" data-note="<?php echo htmlspecialchars($h['note'] ?? '', ENT_QUOTES); ?>">Edit</button></td>
                        </tr>
                      <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <!-- Edit history modal -->
              <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-sm modal-dialog-centered">
                  <div class="modal-content">
                    <form id="historyForm" method="post">
                      <input type="hidden" name="history_update" value="1">
                      <input type="hidden" name="history_id" id="history_id" value="">
                      <div class="modal-header"><h5 class="modal-title">Edit Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                      <div class="modal-body">
                        <textarea name="history_note" id="history_note" class="form-control form-control-sm" rows="4"></textarea>
                      </div>
                      <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
              <script>
                (function(){
                  document.addEventListener('DOMContentLoaded', function(){
                    document.querySelectorAll('.edit-history').forEach(function(b){
                      b.addEventListener('click', function(){
                        var id = this.getAttribute('data-id');
                        var note = this.getAttribute('data-note');
                        document.getElementById('history_id').value = id;
                        document.getElementById('history_note').value = note || '';
                        var m = new bootstrap.Modal(document.getElementById('historyModal'));
                        m.show();
                      });
                    });
                  });
                })();
              </script>
            <?php endif; ?>
            <!-- Edit result modal -->
            <!-- Unknown item modal -->
            <div class="modal fade" id="unknownModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">
                  <form id="unknownForm" method="post">
                    <input type="hidden" name="mark_unknown" value="1">
                    <input type="hidden" name="id" id="unknown_id" value="">
                    <div class="modal-header"><h5 class="modal-title">Barang Tidak Dikenal</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                      <div class="mb-2"><strong id="unknown_inv"></strong><br><small id="unknown_material" class="text-muted"></small></div>
                      <div class="mb-2"><label class="form-label">Catatan singkat</label><textarea name="unknown_note" id="unknown_note" class="form-control form-control-sm" rows="2"></textarea></div>
                      <div class="mb-2"><label class="form-label">New Stock</label><input type="text" name="unknown_new_stock" id="unknown_new_stock" class="form-control form-control-sm" value="0"></div>
                      <div class="mb-2"><label class="form-label">New Location</label><input type="text" name="unknown_new_location" id="unknown_new_location" class="form-control form-control-sm" value=""></div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                      <button type="submit" class="btn btn-sm btn-warning">Tandai Tidak Dikenal</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <div class="modal fade" id="resultEditModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-sm modal-dialog-centered">
                <div class="modal-content">
                  <form id="resultEditForm" method="post">
                    <input type="hidden" name="update" value="1">
                    <input type="hidden" name="id" id="result_edit_id" value="">
                    <div class="modal-header"><h5 class="modal-title">Edit Hasil</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                      <div class="mb-2"><strong id="result_edit_inv"></strong><br><small id="result_edit_material" class="text-muted"></small></div>
                      <div class="mb-2"><small class="text-muted">Available: <span id="result_edit_available"></span></small></div>
                      <div class="mb-2"><label class="form-label">New Stock</label><input type="text" name="new_stock" id="result_new_stock" class="form-control form-control-sm"></div>
                      <div class="mb-2"><label class="form-label">New Location</label><input type="text" name="new_location" id="result_new_location" class="form-control form-control-sm"></div>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                      <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <script>
              (function(){
                document.addEventListener('DOMContentLoaded', function(){
                  document.querySelectorAll('.edit-result').forEach(function(b){
                    b.addEventListener('click', function(){
                      var id = this.getAttribute('data-id');
                      var inv = this.getAttribute('data-inv');
                      var mat = this.getAttribute('data-material');
                      var avail = this.getAttribute('data-available');
                      var ns = this.getAttribute('data-new-stock');
                      var nl = this.getAttribute('data-new-location');
                      document.getElementById('result_edit_id').value = id;
                      document.getElementById('result_edit_inv').textContent = inv;
                      document.getElementById('result_edit_material').textContent = mat;
                      document.getElementById('result_edit_available').textContent = avail || '';
                      document.getElementById('result_new_stock').value = ns || '';
                      document.getElementById('result_new_location').value = nl || '';
                      var m = new bootstrap.Modal(document.getElementById('resultEditModal'));
                      m.show();
                    });
                  });
                });
                // handler for marking unknown items
                document.querySelectorAll('.mark-unknown').forEach(function(b){
                  b.addEventListener('click', function(){
                    var id = this.getAttribute('data-id');
                    var inv = this.getAttribute('data-inv');
                    var mat = this.getAttribute('data-material');
                    document.getElementById('unknown_id').value = id;
                    document.getElementById('unknown_inv').textContent = inv;
                    document.getElementById('unknown_material').textContent = mat;
                    document.getElementById('unknown_note').value = '';
                    document.getElementById('unknown_new_stock').value = '0';
                    document.getElementById('unknown_new_location').value = '';
                    var mu = new bootstrap.Modal(document.getElementById('unknownModal'));
                    mu.show();
                  });
                });
              })();
            </script>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/layouts/footer.html'; ?>
<?php include __DIR__ . '/layouts/scripts.html'; ?>
<?php if (!empty($_SESSION['login_success'])): 
    // consume the flag so alert shows only once
    unset($_SESSION['login_success']);
?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Login berhasil',
      text: 'Selamat datang <?php echo htmlspecialchars($_SESSION['pic_name'] ?? ''); ?>',
      timer: 1800,
      showConfirmButton: false
    });
  }
  try {
    // bring input table into view and focus first input after the welcome alert
    setTimeout(function(){
      var t = document.getElementById('itemsTable');
      if (t) {
        t.scrollIntoView({ behavior: 'smooth', block: 'start' });
        var inp = t.querySelector('input[name^="new_stock"], input[name^="new_location"]');
        if (inp) inp.focus();
        if (window.jQuery && $.fn.DataTable) {
          try { $('#itemsTable').DataTable().draw(false); } catch (e) {}
        }
      }
    }, 600);
  } catch (e) { console.error(e); }
});
</script>
<?php endif; ?>
<?php if (isset($_GET['saved'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Perubahan tersimpan',
      text: 'Semua perubahan berhasil disimpan.',
      timer: 1400,
      showConfirmButton: false
    });
  }
  try {
    var el = document.getElementById('resultsTable');
    if (el) {
      // scroll into view
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      // highlight changed rows
      el.querySelectorAll('tbody tr[data-changed="1"]').forEach(function(r){
        r.classList.add('table-warning');
      });
      // remove highlight after a short delay
      setTimeout(function(){
        el.querySelectorAll('tbody tr.table-warning').forEach(function(r){ r.classList.remove('table-warning'); });
      }, 2500);
      // if DataTable exists, focus it
      if (window.jQuery && $.fn.DataTable) {
        try { $('#resultsTable').DataTable().draw(false); } catch (e) {}
      }
    }
  } catch (e) { console.error(e); }
});
</script>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (window.Swal) {
    Swal.fire({
      icon: 'success',
      title: 'Perubahan tersimpan',
      text: 'Perubahan baris berhasil disimpan.',
      timer: 1200,
      showConfirmButton: false
    });
  }
});
</script>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  try {
    var saveInput = document.querySelector('form input[name="save_all"]');
    if (!saveInput) return;
    var form = saveInput.closest('form');
    if (!form) return;
    form.addEventListener('submit', function(e){
      // allow programmatic submit (no event) to proceed
      if (e.submitter && e.submitter.dataset && e.submitter.dataset.bypass) return;
      e.preventDefault();
      if (!window.Swal) { form.submit(); return; }
      Swal.fire({
        title: 'Konfirmasi',
        text: 'Simpan semua perubahan sekarang?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Ya, simpan',
        cancelButtonText: 'Batal'
      }).then(function(result){
        if (result.isConfirmed) form.submit();
      });
    });
  } catch (err) {
    console.error(err);
  }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  try {
    if (window.jQuery && $.fn.DataTable) {
      if (document.getElementById('itemsTable')) {
        $('#itemsTable').DataTable({ responsive: true, pageLength: 25, lengthChange: false });
      }
      if (document.getElementById('resultsTable')) {
        $('#resultsTable').DataTable({ responsive: true, pageLength: 25, lengthChange: false, ordering: true });
      }
      if (document.getElementById('historyTable')) {
        $('#historyTable').DataTable({ responsive: true, pageLength: 25, lengthChange: false, order: [[1, 'desc']] });
      }
    }
  } catch (e) { console.error(e); }
});
</script>
</body>
</html>

