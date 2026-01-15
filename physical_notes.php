<?php
include 'db.php';
include __DIR__ . '/layouts/auth.php';

// Create table if not exists
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS physical_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stock_taking_id INT NOT NULL,
    issue_type ENUM('physical','block_s') NOT NULL DEFAULT 'physical',
    note TEXT NOT NULL,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Backfill columns for older installs
  $col = $pdo->query("SHOW COLUMNS FROM physical_notes LIKE 'resolved_at'")->fetch();
  if (!$col) {
    $pdo->exec("ALTER TABLE physical_notes ADD COLUMN resolved_at TIMESTAMP NULL");
  }
  $col = $pdo->query("SHOW COLUMNS FROM physical_notes LIKE 'resolution_notes'")->fetch();
  if (!$col) {
    $pdo->exec("ALTER TABLE physical_notes ADD COLUMN resolution_notes TEXT NULL");
  }
} catch (Exception $e) {
  die('Error preparing table: ' . $e->getMessage());
}

// Handle add note
if (isset($_POST['add_note'])) {
    $stockId = isset($_POST['stock_id']) ? (int)$_POST['stock_id'] : 0;
    $issueType = isset($_POST['issue_type']) && in_array($_POST['issue_type'], ['physical','block_s']) ? $_POST['issue_type'] : 'physical';
    $note = isset($_POST['note']) ? trim($_POST['note']) : '';

    if ($stockId && $note !== '') {
        $stmt = $pdo->prepare("INSERT INTO physical_notes (stock_taking_id, issue_type, note) VALUES (?, ?, ?)");
        $stmt->execute([$stockId, $issueType, $note]);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1');
        exit;
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?error=1');
        exit;
    }
}

// Handle resolve note
if (isset($_POST['resolve_note'])) {
  $noteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $resNotes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : '';
  if ($noteId) {
    $stmt = $pdo->prepare("UPDATE physical_notes SET resolved_at = CURRENT_TIMESTAMP, resolution_notes = ? WHERE id = ?");
    $stmt->execute([$resNotes, $noteId]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?resolved=1');
    exit;
  }
}

// Handle unresolve note
if (isset($_POST['unresolve_note'])) {
  $noteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($noteId) {
    $stmt = $pdo->prepare("UPDATE physical_notes SET resolved_at = NULL, resolution_notes = NULL WHERE id = ?");
    $stmt->execute([$noteId]);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?unresolved=1');
    exit;
  }
}

// Fetch stock items for selection (latest 200)
try {
    $stockItems = $pdo->query("SELECT id, area, material, inventory_number, material_description, available_stock, new_available_stock FROM stock_taking ORDER BY created_at DESC, id DESC LIMIT 200")->fetchAll();
} catch (Exception $e) {
    $stockItems = [];
}

// Fetch notes list (unresolved first)
try {
  $notes = $pdo->query("SELECT pn.*, st.area, st.material, st.inventory_number, st.material_description FROM physical_notes pn LEFT JOIN stock_taking st ON pn.stock_taking_id = st.id ORDER BY pn.resolved_at IS NULL DESC, pn.created_at DESC")->fetchAll();
} catch (Exception $e) {
    $notes = [];
}

// Summary counts
$summary = ['physical' => 0, 'block_s' => 0, 'unresolved' => 0, 'resolved' => 0];
foreach ($notes as $n) {
    if ($n['issue_type'] === 'block_s') {
        $summary['block_s']++; }
    else { $summary['physical']++; }
  if (is_null($n['resolved_at'])) { $summary['unresolved']++; } else { $summary['resolved']++; }
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
                <h5 class="m-b-10">Catatan Barang Bermasalah</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item" aria-current="page">Catatan Fisik / Blok S</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-2">Tambah Catatan</h6>
              <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success py-2 mb-3">Catatan berhasil disimpan.</div>
              <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-danger py-2 mb-3">Gagal menyimpan catatan. Pastikan semua field diisi.</div>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="add_note" value="1">
                <div class="mb-2">
                  <label class="form-label">Pilih Barang</label>
                  <select name="stock_id" class="form-select" required>
                    <option value="">-- pilih barang --</option>
                    <?php foreach ($stockItems as $item): ?>
                      <option value="<?php echo $item['id']; ?>">
                        [<?php echo htmlspecialchars($item['area'] ?? ''); ?>] <?php echo htmlspecialchars($item['material']); ?> (<?php echo htmlspecialchars($item['inventory_number']); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Daftar menampilkan 200 data terakhir.</small>
                </div>
                <div class="mb-2">
                  <label class="form-label">Jenis Catatan</label>
                  <select name="issue_type" class="form-select" required>
                    <option value="physical">Salah Fisik</option>
                    <option value="block_s">Blok S</option>
                  </select>
                </div>
                <div class="mb-3">
                  <label class="form-label">Catatan</label>
                  <textarea name="note" class="form-control" rows="3" placeholder="Contoh: Kemasan rusak, label hilang, perlu pengecekan ulang..." required></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Simpan Catatan</button>
              </form>
            </div>
          </div>
        </div>

        <div class="col-md-6">
          <div class="row">
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title">Salah Fisik</h6>
                  <h3 class="text-danger mb-0"><?php echo $summary['physical']; ?></h3>
                  <small class="text-muted">Catatan terkait kondisi fisik barang.</small>
                </div>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title">Blok S</h6>
                  <h3 class="text-warning mb-0"><?php echo $summary['block_s']; ?></h3>
                  <small class="text-muted">Barang yang masuk kategori blok S.</small>
                </div>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title">Belum Diselesaikan</h6>
                  <h3 class="text-danger mb-0"><?php echo $summary['unresolved']; ?></h3>
                  <small class="text-muted">Catatan yang perlu tindakan.</small>
                </div>
              </div>
            </div>
            <div class="col-md-6 mb-3">
              <div class="card">
                <div class="card-body">
                  <h6 class="card-title">Sudah Selesai</h6>
                  <h3 class="text-success mb-0"><?php echo $summary['resolved']; ?></h3>
                  <small class="text-muted">Catatan yang sudah diselesaikan.</small>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h5>Daftar Catatan</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: orange;">
                    <tr>
                      <th>No</th>
                      <th>Jenis</th>
                      <th>Area</th>
                      <th>Material</th>
                      <th>Inventory #</th>
                      <th>Deskripsi</th>
                      <th>Catatan</th>
                      <th>Status</th>
                      <th>Catatan Penyelesaian</th>
                      <th>Created</th>
                      <th>Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($notes)): ?>
                      <?php $no = 1; foreach ($notes as $n): ?>
                        <?php $isResolved = !is_null($n['resolved_at']); ?>
                        <tr>
                          <td><?php echo $no++; ?></td>
                          <td><span class="badge <?php echo $n['issue_type']==='block_s' ? 'bg-warning text-dark' : 'bg-danger'; ?>"><?php echo $n['issue_type']==='block_s' ? 'Blok S' : 'Salah Fisik'; ?></span></td>
                          <td><?php echo htmlspecialchars($n['area'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($n['material'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($n['inventory_number'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($n['material_description'] ?? '-'); ?></td>
                          <td><?php echo nl2br(htmlspecialchars($n['note'])); ?></td>
                          <td>
                            <?php if ($isResolved): ?>
                              <span class="badge bg-success">Selesai</span><br>
                              <small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($n['resolved_at'])); ?></small>
                            <?php else: ?>
                              <span class="badge bg-danger">Belum</span>
                            <?php endif; ?>
                          </td>
                          <td><small><?php echo nl2br(htmlspecialchars($n['resolution_notes'] ?? '-')); ?></small></td>
                          <td><small><?php echo date('Y-m-d H:i', strtotime($n['created_at'])); ?></small></td>
                          <td>
                            <?php if ($isResolved): ?>
                              <form method="post" class="d-inline">
                                <input type="hidden" name="unresolve_note" value="1">
                                <input type="hidden" name="id" value="<?php echo $n['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-warning">Undo</button>
                              </form>
                            <?php else: ?>
                              <button type="button" class="btn btn-sm btn-success" 
                                      data-bs-toggle="modal" data-bs-target="#resolveModal"
                                      data-id="<?php echo $n['id']; ?>"
                                      data-label="<?php echo htmlspecialchars($n['material'] ?? '-'); ?> (<?php echo htmlspecialchars($n['inventory_number'] ?? '-'); ?>)">
                                Selesaikan
                              </button>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="11" class="text-center text-muted py-4">Belum ada catatan.</td>
                      </tr>
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

  <!-- Resolve Modal -->
  <div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="resolveModalLabel">Selesaikan Catatan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" id="resolveForm">
            <input type="hidden" name="resolve_note" value="1">
            <input type="hidden" name="id" id="resolveNoteId">
            <div class="mb-2">
              <label class="form-label">Barang</label>
              <input type="text" class="form-control" id="resolveLabel" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label">Catatan Penyelesaian</label>
              <textarea name="resolution_notes" class="form-control" rows="3" placeholder="Contoh: Dicek ulang, kondisi OK / sudah dipindahkan"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" form="resolveForm" class="btn btn-primary">Tandai Selesai</button>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/layouts/footer.html'; ?>
  <?php include __DIR__ . '/layouts/scripts.html'; ?>

  <script>
    const resolveModal = document.getElementById('resolveModal');
    if (resolveModal) {
      resolveModal.addEventListener('show.bs.modal', function (event) {
        const button = event.relatedTarget;
        const id = button.getAttribute('data-id');
        const label = button.getAttribute('data-label');
        document.getElementById('resolveNoteId').value = id;
        document.getElementById('resolveLabel').value = label;
      });
    }
  </script>
</body>
</html>

