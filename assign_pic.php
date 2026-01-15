<?php
include 'db.php';
include __DIR__ . '/layouts/auth.php';

// Ensure pic table has required columns (name, nrp)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pic (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      nrp VARCHAR(50) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $col = $pdo->query("SHOW COLUMNS FROM pic LIKE 'nrp'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE pic ADD COLUMN nrp VARCHAR(50) NOT NULL AFTER name");
    }
} catch (Exception $e) {
    die('Error preparing pic table: ' . $e->getMessage());
}

// Ensure stock_taking has assigned_pic_id
try {
    $col = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'assigned_pic_id'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN assigned_pic_id INT NULL AFTER resolution_notes");
        $pdo->exec("CREATE INDEX idx_st_assigned_pic ON stock_taking (assigned_pic_id)");
    }
} catch (Exception $e) {
    // Continue even if index already exists
}

// Filters
$selectedArea = $_POST['area'] ?? 'all';
$onlyUnassigned = isset($_POST['only_unassigned']);
$onlyToday = isset($_POST['only_today']);
$inventoryFilterRaw = trim($_POST['inventory_filter'] ?? '');
$inventoryNumbers = [];
if ($inventoryFilterRaw !== '') {
  // Split by newline or comma, trim, unique, and cap to 500 items to protect the query
  $parts = preg_split('/[\r\n,]+/', $inventoryFilterRaw);
  foreach ($parts as $part) {
    $val = trim($part);
    if ($val !== '') {
      $inventoryNumbers[$val] = true;
    }
  }
  $inventoryNumbers = array_slice(array_keys($inventoryNumbers), 0, 500);
}

// Handle assignment
$message = null;
if (isset($_POST['assign']) && isset($_POST['pic_ids']) && is_array($_POST['pic_ids'])) {
  $picIds = array_filter(array_map('intval', $_POST['pic_ids']));
  if (count($picIds) === 0) {
    $message = ['type' => 'danger', 'text' => 'Please select at least one PIC.'];
  } else {
    try {
      // Build filter
      $where = [];
      $params = [];
      if ($selectedArea !== '' && $selectedArea !== 'all') {
        $where[] = 'area = ?';
        $params[] = $selectedArea;
      }
      if (!empty($inventoryNumbers)) {
        $placeholders = implode(',', array_fill(0, count($inventoryNumbers), '?'));
        $where[] = "inventory_number IN ($placeholders)";
        $params = array_merge($params, $inventoryNumbers);
      }
      if ($onlyToday) {
        $where[] = 'DATE(created_at) = CURDATE()';
      }
      if ($onlyUnassigned) {
        $where[] = 'assigned_pic_id IS NULL';
      }
      $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

      // Ambil satu entry per inventory_number agar assignment adalah 1 inventory -> 1 PIC
      $stmtFetch = $pdo->prepare("SELECT st.inventory_number AS inventory_number
          FROM stock_taking st
          $whereSql
          GROUP BY st.inventory_number
          ORDER BY MIN(st.created_at) ASC, MIN(st.id) ASC");
      $stmtFetch->execute($params);
      $items = $stmtFetch->fetchAll(PDO::FETCH_COLUMN, 0);

      if (empty($items)) {
        $message = ['type' => 'warning', 'text' => 'No stock_taking rows match the current filter.'];
      } else {
        $pdo->beginTransaction();
        // Update all rows that share the same inventory_number so one inventory -> one PIC
        $stmt = $pdo->prepare("UPDATE stock_taking SET assigned_pic_id = ? WHERE inventory_number = ?");
        $idx = 0;
        $countPics = count($picIds);
        foreach ($items as $inv) {
          $assigned = $picIds[$idx % $countPics];
          $stmt->execute([$assigned, $inv]);
          $idx++;
        }
        $pdo->commit();
        $message = ['type' => 'success', 'text' => 'Allocation completed (' . count($items) . ' inventory numbers) evenly distributed to ' . $countPics . ' PIC(s).'];
      }
    } catch (Exception $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      $message = ['type' => 'danger', 'text' => 'Failed to allocate: ' . $e->getMessage()];
    }
  }
}

// Fetch data for display
try {
  $areas = $pdo->query("SELECT DISTINCT area FROM stock_taking WHERE area IS NOT NULL AND area != '' ORDER BY area ASC")?->fetchAll(PDO::FETCH_COLUMN) ?? [];
} catch (Exception $e) {
  $areas = [];
}

try {
    $pics = $pdo->query("SELECT * FROM pic ORDER BY name ASC")?->fetchAll(PDO::FETCH_ASSOC) ?? [];
} catch (Exception $e) {
    $pics = [];
}

try {
    $summary = $pdo->query("SELECT p.id, p.name, p.nrp, COUNT(st.id) AS assigned
                             FROM pic p
                             LEFT JOIN stock_taking st ON st.assigned_pic_id = p.id
                             GROUP BY p.id, p.name, p.nrp
                             ORDER BY p.name ASC")?->fetchAll(PDO::FETCH_ASSOC) ?? [];
} catch (Exception $e) {
    $summary = [];
}

try {
  $where = [];
  $params = [];
  if ($selectedArea !== '' && $selectedArea !== 'all') {
    $where[] = 'st.area = ?';
    $params[] = $selectedArea;
  }
  if (!empty($inventoryNumbers)) {
    $placeholders = implode(',', array_fill(0, count($inventoryNumbers), '?'));
    $where[] = "st.inventory_number IN ($placeholders)";
    $params = array_merge($params, $inventoryNumbers);
  }
  if ($onlyToday) {
    $where[] = 'DATE(st.created_at) = CURDATE()';
  }
  if ($onlyUnassigned) {
    $where[] = 'st.assigned_pic_id IS NULL';
  }
  $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

  // Tampilkan satu baris per inventory_number (id terkecil) sekaligus hitung jumlah baris per inventory
  $subSql = "SELECT st.inventory_number, MIN(st.id) AS min_id, COUNT(*) AS item_count FROM stock_taking st $whereSql GROUP BY st.inventory_number";
  $stmtList = $pdo->prepare("SELECT st.inventory_number, st.assigned_pic_id,
                    p.name AS pic_name, p.nrp AS pic_nrp, t.item_count
                 FROM ($subSql) t
                 JOIN stock_taking st ON st.id = t.min_id
                 LEFT JOIN pic p ON p.id = st.assigned_pic_id
                 ORDER BY st.inventory_number ASC
                 LIMIT 300");
  $stmtList->execute($params);
  $list = $stmtList->fetchAll(PDO::FETCH_ASSOC) ?? [];

  // Ambil detail per inventory_number untuk kebutuhan tampilan collapsible
  $detailsByInventory = [];
  if (!empty($list)) {
    $invKeys = array_column($list, 'inventory_number');
    $detailWhere = $where;
    $detailParams = $params;
    $detailPlaceholders = implode(',', array_fill(0, count($invKeys), '?'));
    $detailWhere[] = "st.inventory_number IN ($detailPlaceholders)";
    $detailParams = array_merge($detailParams, $invKeys);
    $detailWhereSql = $detailWhere ? ('WHERE ' . implode(' AND ', $detailWhere)) : '';

    $stmtDetail = $pdo->prepare("SELECT st.*, p.name AS pic_name, p.nrp AS pic_nrp
                                 FROM stock_taking st
                                 LEFT JOIN pic p ON p.id = st.assigned_pic_id
                                 $detailWhereSql
                                 ORDER BY st.inventory_number ASC, st.id ASC");
    $stmtDetail->execute($detailParams);
    while ($row = $stmtDetail->fetch(PDO::FETCH_ASSOC)) {
      $detailsByInventory[$row['inventory_number']][] = $row;
    }
  }
} catch (Exception $e) {
  $list = [];
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
                <h5 class="m-b-10">Assign Stock Taking to PIC</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item" aria-current="page">Assign PIC</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-lg-4">
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-3">Choose PICs (multi-select allowed)</h6>
              <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message['type']); ?> py-2 mb-3"><?php echo htmlspecialchars($message['text']); ?></div>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="assign" value="1">
                <div class="mb-3">
                  <label class="form-label">Filter by Area</label>
                  <select name="area" class="form-select">
                    <option value="all" <?php echo ($selectedArea === 'all') ? 'selected' : ''; ?>>All areas</option>
                    <?php foreach ($areas as $a): ?>
                      <option value="<?php echo htmlspecialchars($a); ?>" <?php echo ($selectedArea === $a) ? 'selected' : ''; ?>><?php echo htmlspecialchars($a); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="only_unassigned" name="only_unassigned" <?php echo $onlyUnassigned ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="only_unassigned">Only unassigned</label>
                  </div>
                  <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="only_today" name="only_today" <?php echo $onlyToday ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="only_today">Only today</label>
                  </div>
                </div>
                <div class="mb-3">
                  <label class="form-label">Filter Inventory Number</label>
                  <textarea name="inventory_filter" class="form-control" rows="3" placeholder="One per line or separate with commas"><?php echo htmlspecialchars($inventoryFilterRaw); ?></textarea>
                  <small class="text-muted">Leave empty for all inventory. Max 500 values.</small>
                </div>
                <div class="mb-3">
                  <label class="form-label">Select PIC(s)</label>
                  <select name="pic_ids[]" class="form-select" multiple size="8" required>
                    <?php foreach ($pics as $p): ?>
                      <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['name'] . ' - ' . $p['nrp']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small class="text-muted">Use Ctrl/Shift to select multiple. Distribution is automatic round-robin.</small>
                </div>
                <button type="submit" class="btn btn-primary w-100">Assign Now</button>
              </form>
            </div>
          </div>
          <div class="card">
            <div class="card-body">
              <h6 class="card-title mb-3">Allocation Summary</h6>
              <ul class="list-group list-group-flush">
                <?php if (empty($summary)): ?>
                  <li class="list-group-item text-muted">No PICs or allocations yet.</li>
                <?php else: ?>
                  <?php foreach ($summary as $s): ?>
                    <li class="list-group-item d-flex justify-content-between">
                      <span><?php echo htmlspecialchars($s['name'] . ' (' . $s['nrp'] . ')'); ?></span>
                      <span class="badge bg-primary"><?php echo (int)$s['assigned']; ?></span>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>
            </div>
          </div>
        </div>

        <div class="col-lg-8">
          <div class="card">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title mb-0">List (latest 300)</h6>
                <span class="text-muted small">Order: unassigned first</span>
              </div>
              <div class="table-responsive">
                <table class="table table-striped align-middle">
                  <thead>
                    <tr>
                      <th>Inventory Number</th>
                      <th>Count</th>
                      <th>PIC</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($list)): ?>
                      <tr><td colspan="3" class="text-center text-muted">No data.</td></tr>
                    <?php else: ?>
                      <?php foreach ($list as $row): ?>
                        <?php $collapseId = 'inv_' . md5((string)($row['inventory_number'] ?? '')); ?>
                        <tr>
                          <td><?php echo htmlspecialchars($row['inventory_number'] ?? ''); ?></td>
                          <td>
                            <button type="button" class="btn btn-link p-0" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="false" aria-controls="<?php echo $collapseId; ?>">
                              <?php echo isset($row['item_count']) ? (int)$row['item_count'] : 0; ?>
                            </button>
                          </td>
                          <td>
                            <?php if ($row['pic_name']): ?>
                              <span class="badge bg-success"><?php echo htmlspecialchars($row['pic_name']); ?></span><br>
                              <small class="text-muted"><?php echo htmlspecialchars($row['pic_nrp']); ?></small>
                            <?php else: ?>
                              <span class="badge bg-secondary">Unassigned</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                        <tr class="collapse" id="<?php echo $collapseId; ?>">
                          <td colspan="3">
                            <div class="card card-body bg-light mb-0">
                              <div class="table-responsive mb-0">
                                <table class="table table-sm mb-0">
                                  <thead>
                                    <tr>
                                      <th>ID</th>
                                      <th>Area</th>
                                      <th>Material</th>
                                      <th>Inventory</th>
                                      <th>Storage Bin</th>
                                      <th>Available</th>
                                      <th>New Stock</th>
                                      <th>PIC</th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <?php if (!empty($detailsByInventory[$row['inventory_number']] ?? [])): ?>
                                      <?php foreach ($detailsByInventory[$row['inventory_number']] as $d): ?>
                                        <tr>
                                          <td><?php echo (int)$d['id']; ?></td>
                                          <td><?php echo htmlspecialchars($d['area'] ?? ''); ?></td>
                                          <td><?php echo htmlspecialchars($d['material'] ?? ''); ?></td>
                                          <td><?php echo htmlspecialchars($d['inventory_number'] ?? ''); ?></td>
                                          <td><?php echo htmlspecialchars($d['storage_bin'] ?? ''); ?></td>
                                          <td><?php echo htmlspecialchars($d['available_stock'] ?? ''); ?></td>
                                          <td><?php echo htmlspecialchars($d['new_available_stock'] ?? ''); ?></td>
                                          <td>
                                            <?php if (!empty($d['pic_name'])): ?>
                                              <span class="badge bg-success"><?php echo htmlspecialchars($d['pic_name']); ?></span><br>
                                              <small class="text-muted"><?php echo htmlspecialchars($d['pic_nrp']); ?></small>
                                            <?php else: ?>
                                              <span class="badge bg-secondary">Unassigned</span>
                                            <?php endif; ?>
                                          </td>
                                        </tr>
                                      <?php endforeach; ?>
                                    <?php else: ?>
                                      <tr><td colspan="8" class="text-muted text-center">No details.</td></tr>
                                    <?php endif; ?>
                                  </tbody>
                                </table>
                              </div>
                            </div>
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
  <script>
    // When a Count button is clicked, sort the corresponding collapse detail rows by ID (numeric)
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var target = btn.getAttribute('data-bs-target') || btn.getAttribute('data-target');
          if (!target) return;
          var id = target.replace(/^#/, '');
          var collapseEl = document.getElementById(id);
          if (!collapseEl) return;
          var tbody = collapseEl.querySelector('tbody');
          if (!tbody) return;
          // Collect rows and sort by first cell numeric value (ID)
          var rows = Array.from(tbody.querySelectorAll('tr'));
          rows.sort(function(a, b) {
            var av = parseInt(a.cells[0].textContent) || 0;
            var bv = parseInt(b.cells[0].textContent) || 0;
            return av - bv;
          });
          // Re-append rows in sorted order
          rows.forEach(function(r) { tbody.appendChild(r); });
          // Renumber first column starting from 1
          Array.from(tbody.querySelectorAll('tr')).forEach(function(r, idx) {
            if (r.cells && r.cells.length > 0) {
              r.cells[0].textContent = (idx + 1);
            }
          });
        });
      });
    });
  </script>
</body>
</html>

