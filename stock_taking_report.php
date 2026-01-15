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

// Ensure columns exist
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'resolved_at'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolved_at TIMESTAMP NULL");
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolution_notes TEXT NULL");
    }
} catch (Exception $e) {
    // Ignore
}

// Filters for differences
$baseWhere = "new_available_stock IS NOT NULL AND new_available_stock != '' AND available_stock IS NOT NULL AND available_stock != ''";
$numericDiff = "(TRIM(new_available_stock) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' AND TRIM(available_stock) REGEXP '^-?[0-9]+(\\.[0-9]+)?$' AND CAST(TRIM(new_available_stock) AS DECIMAL(18,4)) != CAST(TRIM(available_stock) AS DECIMAL(18,4)))";
$stringDiff  = "(NOT($numericDiff) AND TRIM(new_available_stock) != TRIM(available_stock))";
$anyDiff     = "(($numericDiff) OR ($stringDiff))";

// Get overall statistics
try {
  $stmt = $pdo->query("
    SELECT 
      (SELECT COUNT(*) FROM stock_taking) as total_parts,
      (SELECT COUNT(DISTINCT area) FROM stock_taking) as total_areas,
      (SELECT COUNT(*) FROM stock_taking WHERE $baseWhere AND $anyDiff) as parts_with_diff,
      (SELECT COUNT(*) FROM stock_taking WHERE $baseWhere AND $anyDiff AND resolved_at IS NULL) as unresolved,
      (SELECT COUNT(*) FROM stock_taking WHERE $baseWhere AND $anyDiff AND resolved_at IS NOT NULL) as resolved
  ");
  $stats = $stmt->fetch();
} catch (PDOException $e) {
  die("Error: " . $e->getMessage());
}

// Get short and over parts count
$shortCount = 0;
$overCount = 0;
$shortTotal = 0;
$overTotal = 0;
try {
    $stmt = $pdo->query("SELECT * FROM stock_taking WHERE $baseWhere");
    $allDiffs = $stmt->fetchAll();
    
    foreach ($allDiffs as $item) {
        $avail = $item['available_stock'];
        $new = $item['new_available_stock'];
        if (preg_match('/^-?\d+(\.\d+)?$/', trim((string)$new)) && preg_match('/^-?\d+(\.\d+)?$/', trim((string)$avail))) {
          $diff = (float)$new - (float)$avail;
            if ($diff < 0) {
                $shortCount++;
                $shortTotal += abs($diff);
            } elseif ($diff > 0) {
                $overCount++;
                $overTotal += $diff;
            }
        }
    }
} catch (PDOException $e) {
    // Ignore
}

// Get data by area
try {
    $stmt = $pdo->query("
        SELECT 
          area,
          COUNT(*) as area_count,
          COUNT(CASE WHEN $baseWhere AND $anyDiff THEN 1 END) as area_diff_count
        FROM stock_taking
        GROUP BY area
        ORDER BY area
    ");
    $areaStats = $stmt->fetchAll();
} catch (PDOException $e) {
    $areaStats = [];
}

// Get recent stock takings (last 5)
try {
    $stmt = $pdo->query("
        SELECT created_at, COUNT(*) as count
        FROM stock_taking
        GROUP BY DATE(created_at)
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentTakings = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentTakings = [];
}

// Physical / Blok S notes summary and list
$pnSummary = ['physical' => 0, 'block_s' => 0, 'unresolved' => 0, 'resolved' => 0];
$pnList = [];
try {
  // summary by type
  $result = $pdo->query("SELECT issue_type, COUNT(*) total, SUM(resolved_at IS NULL) unresolved, SUM(resolved_at IS NOT NULL) resolved FROM physical_notes GROUP BY issue_type");
  $rows = $result ? $result->fetchAll() : [];
  foreach ($rows as $r) {
    $type = $r['issue_type'];
    if ($type === 'block_s') {
      $pnSummary['block_s'] += (int)$r['total'];
    } else {
      $pnSummary['physical'] += (int)$r['total'];
    }
    $pnSummary['unresolved'] += (int)$r['unresolved'];
    $pnSummary['resolved'] += (int)$r['resolved'];
  }
  // latest notes
  $result = $pdo->query("SELECT pn.*, st.area, st.material, st.inventory_number, st.material_description FROM physical_notes pn LEFT JOIN stock_taking st ON pn.stock_taking_id = st.id ORDER BY pn.resolved_at IS NULL DESC, pn.created_at DESC LIMIT 20");
  $pnList = $result ? $result->fetchAll() : [];
} catch (Exception $e) {
  $pnSummary = ['physical' => 0, 'block_s' => 0, 'unresolved' => 0, 'resolved' => 0];
  $pnList = [];
}
?>
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include __DIR__ . '/layouts/preloader.html'; ?>
  <?php include __DIR__ . '/layouts/sidebar.html'; ?>
  <?php include __DIR__ . '/layouts/header.html'; ?>

  <style>
    .stat-card { border-left: 4px solid; padding: 20px; border-radius: 4px; }
    .stat-card.primary { border-left-color: #007bff; background-color: #f0f7ff; }
    .stat-card.danger { border-left-color: #dc3545; background-color: #fff5f5; }
    .stat-card.warning { border-left-color: #ffc107; background-color: #fffbf0; }
    .stat-card.success { border-left-color: #0a7c0a; background-color: #f0fff4; }
    .stat-number { font-size: 32px; font-weight: 700; margin: 10px 0; }
    .stat-label { font-size: 14px; color: #666; }
    .progress-bar-striped { animation: progress-bar-stripes 1s linear infinite; }
    @keyframes progress-bar-stripes {
      0% { background-position: 0 0; }
      100% { background-position: 40px 0; }
    }
  </style>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Stock Taking Report</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item" aria-current="page">Stock Taking Report</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Statistics -->
      <div class="row mb-3">
        <div class="col-md-8">
          <h6>Key Metrics</h6>
        </div>
        <div class="col-md-4 text-end">
          <a href="export_stock_report.php?export=excel" class="btn btn-success btn-sm me-2">
            <i class="ti ti-download"></i> Basic Report
          </a>
          <a href="export_detailed_report.php?export=excel" class="btn btn-success btn-sm">
            <i class="ti ti-download"></i> Detailed Report
          </a>
        </div>
      </div>
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="card stat-card primary">
            <div class="stat-label">Total Parts</div>
            <div class="stat-number"><?php echo $stats['total_parts']; ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card warning">
            <div class="stat-label">Parts with Differences</div>
            <div class="stat-number"><?php echo $stats['parts_with_diff']; ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card danger">
            <div class="stat-label">Unresolved</div>
            <div class="stat-number"><?php echo $stats['unresolved']; ?></div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="card stat-card success">
            <div class="stat-label">Resolved</div>
            <div class="stat-number"><?php echo $stats['resolved']; ?></div>
          </div>
        </div>
      </div>

      <!-- Differences Summary -->
      <div class="row mb-4">
        <div class="col-md-6">
          <div class="card">
            <div class="card-header">
              <h5>Short Parts Summary</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <div class="stat-card danger">
                    <div class="stat-label">Count</div>
                    <div class="stat-number"><?php echo $shortCount; ?></div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="stat-card danger">
                    <div class="stat-label">Total Shortage</div>
                    <div class="stat-number"><?php echo $shortTotal; ?></div>
                    <small class="text-muted">units short</small>
                  </div>
                </div>
              </div>
              <div class="progress mt-3">
                <div class="progress-bar bg-danger" style="width: <?php echo $stats['parts_with_diff'] > 0 ? ($shortCount / $stats['parts_with_diff'] * 100) : 0; ?>%"></div>
              </div>
              <small class="text-muted">
                <?php echo $stats['parts_with_diff'] > 0 ? number_format(($shortCount / $stats['parts_with_diff'] * 100), 1) : 0; ?>% of parts with differences
              </small>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card">
            <div class="card-header">
              <h5>Over Parts Summary</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <div class="stat-card warning">
                    <div class="stat-label">Count</div>
                    <div class="stat-number"><?php echo $overCount; ?></div>
                  </div>
                </div>
                <div class="col-md-6">
                  <div class="stat-card warning">
                    <div class="stat-label">Total Overage</div>
                    <div class="stat-number"><?php echo $overTotal; ?></div>
                    <small class="text-muted">units over</small>
                  </div>
                </div>
              </div>
              <div class="progress mt-3">
                <div class="progress-bar bg-warning" style="width: <?php echo $stats['parts_with_diff'] > 0 ? ($overCount / $stats['parts_with_diff'] * 100) : 0; ?>%"></div>
              </div>
              <small class="text-muted">
                <?php echo $stats['parts_with_diff'] > 0 ? number_format(($overCount / $stats['parts_with_diff'] * 100), 1) : 0; ?>% of parts with differences
              </small>
            </div>
          </div>
        </div>
      </div>

      <!-- Physical / Blok S Notes -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5>Catatan Fisik / Blok S</h5>
              <a href="physical_notes.php" class="btn btn-sm btn-outline-secondary">Kelola Catatan</a>
            </div>
            <div class="card-body">
              <div class="row mb-3">
                <div class="col-md-3 col-sm-6 mb-3">
                  <div class="card stat-card danger">
                    <div class="stat-label">Salah Fisik</div>
                    <div class="stat-number"><?php echo $pnSummary['physical']; ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                  <div class="card stat-card warning">
                    <div class="stat-label">Blok S</div>
                    <div class="stat-number"><?php echo $pnSummary['block_s']; ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                  <div class="card stat-card danger">
                    <div class="stat-label">Belum Selesai</div>
                    <div class="stat-number"><?php echo $pnSummary['unresolved']; ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3">
                  <div class="card stat-card success">
                    <div class="stat-label">Selesai</div>
                    <div class="stat-number"><?php echo $pnSummary['resolved']; ?></div>
                  </div>
                </div>
              </div>

              <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: #f8f9fa;">
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
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!empty($pnList)): ?>
                      <?php $no = 1; foreach ($pnList as $n): ?>
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
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="10" class="text-center text-muted py-4">Belum ada catatan fisik / blok S.</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <small class="text-muted">Menampilkan 20 catatan terbaru.</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Resolution Status -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h5>Resolution Status</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <h6>Progress</h6>
                  <div class="progress" style="height: 25px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $stats['parts_with_diff'] > 0 ? ($stats['resolved'] / $stats['parts_with_diff'] * 100) : 0; ?>%">
                      <?php echo number_format(($stats['parts_with_diff'] > 0 ? ($stats['resolved'] / $stats['parts_with_diff'] * 100) : 0), 1); ?>%
                    </div>
                  </div>
                  <small class="text-muted mt-2 d-block">
                    <?php echo $stats['resolved']; ?> resolved out of <?php echo $stats['parts_with_diff']; ?> parts with differences
                  </small>
                </div>
                <div class="col-md-6">
                  <h6>Details</h6>
                  <table class="table table-sm">
                    <tr>
                      <td>Total Parts:</td>
                      <td><strong><?php echo $stats['total_parts']; ?></strong></td>
                    </tr>
                    <tr>
                      <td>Parts with Differences:</td>
                      <td><strong><?php echo $stats['parts_with_diff']; ?></strong></td>
                    </tr>
                    <tr>
                      <td>Resolved:</td>
                      <td><span class="badge bg-success"><?php echo $stats['resolved']; ?></span></td>
                    </tr>
                    <tr>
                      <td>Unresolved:</td>
                      <td><span class="badge bg-danger"><?php echo $stats['unresolved']; ?></span></td>
                    </tr>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- By Area -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h5>Parts by Area</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Area</th>
                      <th>Total Parts</th>
                      <th>Parts with Differences</th>
                      <th>Percentage</th>
                    </tr>
                  </thead>
                  <tbody>
<?php foreach ($areaStats as $area): ?>
                    <tr>
                      <td><?php echo htmlspecialchars($area['area'] ?? 'Unknown'); ?></td>
                      <td><?php echo $area['area_count']; ?></td>
                      <td><span class="badge bg-warning"><?php echo $area['area_diff_count']; ?></span></td>
                      <td>
                        <div class="progress" style="height: 20px;">
                          <div class="progress-bar bg-warning" style="width: <?php echo $area['area_count'] > 0 ? ($area['area_diff_count'] / $area['area_count'] * 100) : 0; ?>%">
                            <?php echo number_format(($area['area_count'] > 0 ? ($area['area_diff_count'] / $area['area_count'] * 100) : 0), 1); ?>%
                          </div>
                        </div>
                      </td>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Stock Takings -->
      <div class="row mb-4">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h5>Recent Stock Takings</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Parts Recorded</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
<?php if (count($recentTakings) > 0): ?>
<?php foreach ($recentTakings as $taking): ?>
                    <tr>
                      <td><?php echo date('Y-m-d H:i', strtotime($taking['created_at'])); ?></td>
                      <td><span class="badge bg-primary"><?php echo $taking['count']; ?></span></td>
                      <td>
                        <a href="stock_taking_result.php" class="btn btn-sm btn-info">View Results</a>
                      </td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                      <td colspan="3" class="text-center text-muted">No stock taking data yet</td>
                    </tr>
<?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Links -->
      <div class="row">
        <div class="col-md-12">
          <div class="card">
            <div class="card-header">
              <h5>Quick Links</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-3">
                  <a href="stock_taking.php" class="btn btn-primary btn-block mb-2 w-100">
                    <i class="ti ti-package"></i> Stock Taking
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="stock_taking_result.php" class="btn btn-info btn-block mb-2 w-100">
                    <i class="ti ti-list"></i> Results
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="parts_difference_tracking.php" class="btn btn-warning btn-block mb-2 w-100">
                    <i class="ti ti-alert-circle"></i> Track Differences
                  </a>
                </div>
                <div class="col-md-3">
                  <a href="resolve_differences.php" class="btn btn-success btn-block mb-2 w-100">
                    <i class="ti ti-check-circle"></i> Resolve
                  </a>
                </div>
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
    $(document).ready(function() {
      // Optional: Add refresh functionality
    });
  </script>
</body>
</html>

