<?php
include 'db.php';

// Ensure created_at column exists
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'created_at'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE stock_taking ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
} catch (Exception $e) {
    // Ignore error
}

// Ambil data yang memiliki perbedaan (new_available_stock tidak null)
try {
  $stmt = $pdo->query("SELECT * FROM stock_taking WHERE new_available_stock IS NOT NULL AND new_available_stock != '' ORDER BY created_at DESC, id DESC");
  $difference_data = $stmt->fetchAll();
} catch (PDOException $e) {
  die("Error fetching data: " . $e->getMessage());
}

// Hitung summary (abaikan jika selisih = 0)
$shortCount = 0;
$overCount = 0;
$shortParts = [];
$overParts = [];
$filteredDifferences = [];

foreach ($difference_data as $item) {
  $avail = $item['available_stock'];
  $new = $item['new_available_stock'];
  if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
    $diff = (int)$new - (int)$avail;
    if ($diff < 0) {
      $shortCount++;
      $shortParts[] = $item;
      $item['_diff'] = $diff;
      $filteredDifferences[] = $item;
    } elseif ($diff > 0) {
      $overCount++;
      $overParts[] = $item;
      $item['_diff'] = $diff;
      $filteredDifferences[] = $item;
    }
    // Jika diff == 0, tidak dimasukkan (tidak ada temuan)
  }
}
?>
<?php include 'layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include 'layouts/preloader.html'; ?>
  <?php include 'layouts/sidebar.html'; ?>
  <?php include 'layouts/header.html'; ?>

  <style>
    .diff-short { background-color: #f8d7da !important; color: #721c24 !important; }
    .diff-over  { background-color: #fff3cd !important; color: #856404 !important; }
    .badge-short { background-color: #dc3545; }
    .badge-over { background-color: #ffc107; color: #000; }
    .summary-card { border-left: 4px solid; }
    .summary-card.short { border-left-color: #dc3545; }
    .summary-card.over { border-left-color: #ffc107; }
  </style>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Parts Difference Tracking</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item" aria-current="page">Parts Difference Tracking</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Summary Cards -->
      <div class="row mb-3">
        <div class="col-md-6">
          <div class="card summary-card short">
            <div class="card-body">
              <h6 class="card-title">Short Parts</h6>
              <h3 class="text-danger"><?php echo $shortCount; ?></h3>
              <small class="text-muted">Parts with negative differences</small>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card summary-card over">
            <div class="card-body">
              <h6 class="card-title">Over Parts</h6>
              <h3 class="text-warning"><?php echo $overCount; ?></h3>
              <small class="text-muted">Parts with positive differences</small>
            </div>
          </div>
        </div>
      </div>

      <?php if ($shortCount === 0 && $overCount === 0): ?>
      <div class="alert alert-success" role="alert">
        <strong>Tidak ada temuan.</strong> Nominal stok aman.
      </div>
      <?php endif; ?>

      <div class="row">
        <!-- Short Parts Section -->
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header">
              <h5>Short Parts <span class="badge badge-short"><?php echo $shortCount; ?></span></h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                  <thead style="background-color: #f8d7da;">
                    <tr>
                      <th>Material</th>
                      <th>Inventory #</th>
                      <th>Original</th>
                      <th>New</th>
                      <th>Difference</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
<?php if ($shortCount > 0): ?>
<?php foreach ($shortParts as $item): ?>
<?php
  $avail = $item['available_stock'];
  $new = $item['new_available_stock'];
  $diff = (int)$new - (int)$avail;
?>
                    <tr>
                      <td><?php echo htmlspecialchars($item['material']); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number']); ?></td>
                      <td><?php echo htmlspecialchars($avail); ?></td>
                      <td><?php echo htmlspecialchars($new); ?></td>
                      <td class="diff-short text-center"><strong><?php echo $diff; ?></strong></td>
                      <td><small><?php echo !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '-'; ?></small></td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted">No short parts</td>
                    </tr>
<?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Over Parts Section -->
        <div class="col-lg-6">
          <div class="card">
            <div class="card-header">
              <h5>Over Parts <span class="badge badge-over"><?php echo $overCount; ?></span></h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                  <thead style="background-color: #fff3cd;">
                    <tr>
                      <th>Material</th>
                      <th>Inventory #</th>
                      <th>Original</th>
                      <th>New</th>
                      <th>Difference</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
<?php if ($overCount > 0): ?>
<?php foreach ($overParts as $item): ?>
<?php
  $avail = $item['available_stock'];
  $new = $item['new_available_stock'];
  $diff = (int)$new - (int)$avail;
?>
                    <tr>
                      <td><?php echo htmlspecialchars($item['material']); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number']); ?></td>
                      <td><?php echo htmlspecialchars($avail); ?></td>
                      <td><?php echo htmlspecialchars($new); ?></td>
                      <td class="diff-over text-center"><strong><?php echo $diff; ?></strong></td>
                      <td><small><?php echo !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '-'; ?></small></td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                      <td colspan="6" class="text-center text-muted">No over parts</td>
                    </tr>
<?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Detailed List -->
      <div class="row mt-3">
        <div class="col-sm-12">
          <div class="card">
            <div class="card-header">
              <h5>All Parts with Differences</h5>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table id="diff-table" class="table table-striped table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: orange;">
                    <tr>
                      <th>No</th>
                      <th>Area</th>
                      <th>Material</th>
                      <th>Inventory #</th>
                      <th>Description</th>
                      <th>Original Bin</th>
                      <th>New Bin</th>
                      <th>Original Stock</th>
                      <th>New Stock</th>
                      <th>Difference</th>
                      <th>Type</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
<?php if (!empty($filteredDifferences)): ?>
<?php $no = 1; foreach ($filteredDifferences as $item): ?>
<?php
  $avail = $item['available_stock'];
  $new = $item['new_available_stock'];
  $origBin = $item['storage_bin'] ?? '';
  $newBin = $item['new_storage_bin'] ?? '-';
  $diff = $item['_diff'] ?? ((int)$new - (int)$avail);
  $type = $diff < 0 ? 'SHORT' : 'OVER';
  $typeClass = $diff < 0 ? 'diff-short' : 'diff-over';
?>
                    <tr>
                      <td><?php echo $no++; ?></td>
                      <td><?php echo htmlspecialchars($item['area'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['material']); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number']); ?></td>
                      <td><?php echo htmlspecialchars($item['material_description'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($origBin); ?></td>
                      <td><?php echo htmlspecialchars($newBin); ?></td>
                      <td><?php echo htmlspecialchars($avail); ?></td>
                      <td><?php echo htmlspecialchars($new); ?></td>
                      <td class="<?php echo $typeClass; ?> text-center"><strong><?php echo $diff; ?></strong></td>
                      <td class="text-center"><span class="badge <?php echo $diff < 0 ? 'badge-short' : 'badge-over'; ?>"><?php echo $type; ?></span></td>
                      <td><small><?php echo !empty($item['created_at']) ? date('Y-m-d H:i', strtotime($item['created_at'])) : '-'; ?></small></td>
                    </tr>
<?php endforeach; ?>
<?php else: ?>
                    <tr>
                      <td colspan="12" class="text-center text-muted py-4">Tidak ada temuan perbedaan (difference = 0)</td>
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
    });
  </script>
</body>
</html>
