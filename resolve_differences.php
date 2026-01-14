<?php
include 'db.php';

// Ensure resolved_at and resolution_notes columns exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'resolved_at'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolved_at TIMESTAMP NULL");
    }
} catch (Exception $e) {
    // Ignore error
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'resolution_notes'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolution_notes TEXT NULL");
    }
} catch (Exception $e) {
    // Ignore error
}

// Handle resolve action
if (isset($_POST['resolve'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    $notes = isset($_POST['resolution_notes']) ? trim($_POST['resolution_notes']) : '';
    
    if ($id) {
        try {
            $stmt = $pdo->prepare("UPDATE stock_taking SET resolved_at = CURRENT_TIMESTAMP, resolution_notes = ? WHERE id = ?");
            $stmt->execute([$notes, $id]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?resolved=1');
            exit;
        } catch (Exception $e) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Handle unresolve action
if (isset($_POST['unresolve'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    if ($id) {
        try {
            $stmt = $pdo->prepare("UPDATE stock_taking SET resolved_at = NULL, resolution_notes = NULL WHERE id = ?");
            $stmt->execute([$id]);
            header('Location: ' . $_SERVER['PHP_SELF'] . '?unresolved=1');
            exit;
        } catch (Exception $e) {
            header('Location: ' . $_SERVER['PHP_SELF'] . '?error=' . urlencode($e->getMessage()));
            exit;
        }
    }
}

// Get filter parameter
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'unresolved';

// Fetch data based on filter; only include numeric differences that are non-zero
try {
  $baseWhere = "new_available_stock IS NOT NULL AND new_available_stock != '' AND available_stock IS NOT NULL AND available_stock != '' AND new_available_stock REGEXP '^-?[0-9]+$' AND available_stock REGEXP '^-?[0-9]+$' AND CAST(new_available_stock AS SIGNED) != CAST(available_stock AS SIGNED)";
  if ($filter === 'all') {
    $stmt = $pdo->query("SELECT * FROM stock_taking WHERE $baseWhere ORDER BY created_at DESC, id DESC");
  } elseif ($filter === 'resolved') {
    $stmt = $pdo->query("SELECT * FROM stock_taking WHERE $baseWhere AND resolved_at IS NOT NULL ORDER BY resolved_at DESC, id DESC");
  } else {
    $stmt = $pdo->query("SELECT * FROM stock_taking WHERE $baseWhere AND resolved_at IS NULL ORDER BY created_at DESC, id DESC");
  }
  $difference_data = $stmt->fetchAll();
} catch (PDOException $e) {
  die("Error fetching data: " . $e->getMessage());
}

// Count unresolved (non-zero numeric differences only)
try {
  $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM stock_taking WHERE $baseWhere AND resolved_at IS NULL");
  $unresolvedCount = $stmt->fetch()['cnt'];
} catch (PDOException $e) {
  $unresolvedCount = 0;
}

// Count resolved (non-zero numeric differences only)
try {
  $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM stock_taking WHERE $baseWhere AND resolved_at IS NOT NULL");
  $resolvedCount = $stmt->fetch()['cnt'];
} catch (PDOException $e) {
  $resolvedCount = 0;
}
?>
<?php include 'layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include 'layouts/preloader.html'; ?>
  <?php include 'layouts/sidebar.html'; ?>
  <?php include 'layouts/header.html'; ?>

  <style>
    .diff-short { background-color: #f8d7da !important; color: #721c24 !important; }
    .diff-over { background-color: #fff3cd !important; color: #856404 !important; }
    .status-resolved { color: #0a7c0a; font-weight: 600; }
    .status-unresolved { color: #dc3545; font-weight: 600; }
    .badge-unresolved { background-color: #dc3545; }
    .badge-resolved { background-color: #0a7c0a; }
  </style>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Resolve Differences</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item" aria-current="page">Resolve Differences</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary Cards -->
      <div class="row mb-3">
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <h6 class="card-title">Unresolved</h6>
              <h3 class="text-danger"><?php echo $unresolvedCount; ?></h3>
              <small class="text-muted">Parts awaiting resolution</small>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <h6 class="card-title">Resolved</h6>
              <h3 class="text-success"><?php echo $resolvedCount; ?></h3>
              <small class="text-muted">Parts already resolved</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter Tabs -->
      <div class="row mb-3">
        <div class="col-sm-12">
          <div class="btn-group" role="group">
            <a href="?filter=unresolved" class="btn btn-<?php echo $filter === 'unresolved' ? 'danger' : 'outline-danger'; ?>">
              Unresolved (<?php echo $unresolvedCount; ?>)
            </a>
            <a href="?filter=resolved" class="btn btn-<?php echo $filter === 'resolved' ? 'success' : 'outline-success'; ?>">
              Resolved (<?php echo $resolvedCount; ?>)
            </a>
            <a href="?filter=all" class="btn btn-<?php echo $filter === 'all' ? 'secondary' : 'outline-secondary'; ?>">
              All
            </a>
          </div>
        </div>
      </div>

      <!-- Data Table -->
      <div class="row">
        <div class="col-sm-12">
          <div class="card">
            <div class="card-header">
              <h5><?php echo ucfirst($filter); ?> Parts</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table id="diff-table" class="table table-striped table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: orange;">
                    <tr>
                      <th>No</th>
                      <th>Material</th>
                      <th>Inventory #</th>
                      <th>Original Stock</th>
                      <th>New Stock</th>
                       <th>Type</th>
                       <th>Material Description</th>
                      <th>Difference</th>
                      <th>Bin Location</th>
                      <th>Stock Taking Date</th>
                      <?php if ($filter === 'resolved'): ?>
                      <th>Resolved Date</th>
                      <th>Notes</th>
                      <?php endif; ?>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
<?php if (count($difference_data) > 0): ?>
<?php $no = 1; foreach ($difference_data as $item): ?>
<?php
  $avail = $item['available_stock'];
  $new = $item['new_available_stock'];
  $origBin = $item['storage_bin'] ?? '';
  $newBin = $item['new_storage_bin'] ?? '-';
  $diff = (int)$new - (int)$avail;
  $typeClass = $diff < 0 ? 'diff-short' : 'diff-over';
  $isResolved = !is_null($item['resolved_at']);
?>
                    <tr>
                      <td><?php echo $no++; ?></td>
                      <td><?php echo htmlspecialchars($item['material']); ?></td>
                        <td><?php echo htmlspecialchars($item['type'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($item['material_description'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number']); ?></td>
                      <td><?php echo htmlspecialchars($avail); ?></td>
                      <td><?php echo htmlspecialchars($new); ?></td>
                      <td class="<?php echo $typeClass; ?> text-center"><strong><?php echo $diff; ?></strong></td>
                      <td><?php echo htmlspecialchars($origBin); ?> → <?php echo htmlspecialchars($newBin); ?></td>
                      <td><small><?php echo !empty($item['created_at']) ? date('Y-m-d H:i', strtotime($item['created_at'])) : '-'; ?></small></td>
                      <?php if ($filter === 'resolved'): ?>
                      <td><small><?php echo !empty($item['resolved_at']) ? date('Y-m-d H:i', strtotime($item['resolved_at'])) : '-'; ?></small></td>
                      <td><small><?php echo htmlspecialchars($item['resolution_notes'] ?? '-'); ?></small></td>
                      <?php endif; ?>
                      <td>
                        <?php if ($isResolved): ?>
                          <button class="btn btn-sm btn-warning unresolve-btn" 
                                  data-id="<?php echo $item['id']; ?>"
                                  title="Mark as unresolved">Undo</button>
                        <?php else: ?>
                          <button class="btn btn-sm btn-success resolve-btn" 
                                  data-id="<?php echo $item['id']; ?>"
                                  data-material="<?php echo htmlspecialchars($item['material']); ?>"
                                  data-inventory="<?php echo htmlspecialchars($item['inventory_number']); ?>"
                                  data-type="<?php echo htmlspecialchars($item['type'] ?? ''); ?>"
                                  data-desc="<?php echo htmlspecialchars($item['material_description'] ?? ''); ?>"
                                  title="Mark as resolved">Resolve</button>
                        <?php endif; ?>
                      </td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                      <td colspan="<?php echo $filter === 'resolved' ? '14' : '12'; ?>" class="text-center text-muted">Tidak ada temuan perbedaan (difference = 0), nominal stok aman.</td>
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
          <h5 class="modal-title" id="resolveModalLabel">Resolve Difference</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="resolveForm" method="post" action="">
            <input type="hidden" name="resolve" value="1">
            <input type="hidden" id="resolveId" name="id">
            <div class="mb-3">
              <label class="form-label">Material & Inventory</label>
              <input type="text" class="form-control" id="resolveMaterial" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label">Type</label>
              <input type="text" class="form-control" id="resolveType" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label">Material Description</label>
              <textarea class="form-control" id="resolveDescription" rows="2" disabled></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Resolution Notes</label>
              <textarea class="form-control" id="resolutionNotes" name="resolution_notes" rows="4" placeholder="e.g., Recount completed, adjusted inventory, pending verification..."></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" form="resolveForm">Mark as Resolved</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Unresolve Modal -->
  <div class="modal fade" id="unresolveModal" tabindex="-1" aria-labelledby="unresolveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="unresolveModalLabel">Undo Resolution</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="unresolveForm" method="post" action="">
            <input type="hidden" name="unresolve" value="1">
            <input type="hidden" id="unresolveId" name="id">
            <p>Are you sure you want to mark this part as unresolved? This will clear the resolution notes.</p>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-warning" form="unresolveForm">Confirm Undo</button>
        </div>
      </div>
    </div>
  </div>

  <?php include 'layouts/footer.html'; ?>
  <?php include 'layouts/scripts.html'; ?>

  <script>
    $(document).ready(function() {
      $('#diff-table').DataTable({
        scrollY: '400px',
        scrollCollapse: true,
        paging: false,
        fixedHeader: true
      });

      // Resolve button
      $(document).on('click', '.resolve-btn', function() {
        var id = $(this).data('id');
        var material = $(this).data('material');
        var inventory = $(this).data('inventory');
        var type = $(this).data('type') || '';
        var desc = $(this).data('desc') || '';
        
        $('#resolveId').val(id);
        $('#resolveMaterial').val(material + ' (' + inventory + ')');
        $('#resolveType').val(type);
        $('#resolveDescription').val(desc);
        $('#resolutionNotes').val('');
        $('#resolveModal').modal('show');
      });

      // Unresolve button
      $(document).on('click', '.unresolve-btn', function() {
        var id = $(this).data('id');
        $('#unresolveId').val(id);
        $('#unresolveModal').modal('show');
      });

      // Show alerts
      var params = new URLSearchParams(window.location.search);
      if (params.get('resolved') === '1') {
        Swal.fire({
          icon: 'success',
          title: 'Marked as Resolved',
          timer: 1500,
          showConfirmButton: false
        });
      }
      if (params.get('unresolved') === '1') {
        Swal.fire({
          icon: 'info',
          title: 'Marked as Unresolved',
          timer: 1500,
          showConfirmButton: false
        });
      }
      if (params.get('error')) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: params.get('error')
        });
      }
    });
  </script>
</body>
</html>
