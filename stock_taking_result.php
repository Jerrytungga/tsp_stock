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

// Ambil daftar PIC untuk filter
try {
  $pics = $pdo->query("SELECT id, name FROM pic ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $pics = [];
}

// Ambil data stock_taking (opsional filter by pic)
$filterPic = isset($_GET['pic']) && $_GET['pic'] !== '' ? (int)$_GET['pic'] : null;
try {
  if ($filterPic) {
    $stmt = $pdo->prepare("SELECT * FROM stock_taking WHERE assigned_pic_id = ? ORDER BY created_at DESC, id DESC");
    $stmt->execute([$filterPic]);
  } else {
    $stmt = $pdo->query("SELECT * FROM stock_taking ORDER BY created_at DESC, id DESC");
  }
  $inventory_data = $stmt->fetchAll();
} catch (PDOException $e) {
  die("Error fetching data: " . $e->getMessage());
}
?>
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include __DIR__ . '/layouts/preloader.html'; ?>
  <?php include __DIR__ . '/layouts/sidebar.html'; ?>
  <?php include __DIR__ . '/layouts/header.html'; ?>

  <style>
    /* Warna highlight untuk different */
    .table .diff-short { background-color: #f8d7da !important; color: #721c24 !important; }
    .table .diff-over  { background-color: #fff3cd !important; color: #856404 !important; }
    .status-updated { color: #0a7c0a; font-weight: 600; }
    .status-pending { color: #6c757d; }
  </style>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Stock Taking Results</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item" aria-current="page">Stock Taking Results</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-sm-12">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h5 class="mb-0">Summary</h5>
              <small class="text-muted">Final Bin & Final Stock determined by new data if available.</small>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table id="result-table" class="table table-striped table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: orange;">
                    <tr>
                      <th>No</th>
                      <th>Area</th>
                      <th>Type</th>
                      <th>Material</th>
                      <th>Inventory Number</th>
                      <th>Material Description</th>
                      <th>Stock Taking Date</th>
                      <th>Original Bin</th>
                      <th>New Bin</th>
                      <th>Final Bin</th>
                      <th>Original Stock</th>
                      <th>New Stock</th>
                      <th>Final Stock</th>
                      <th>Different</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
<?php $no = 1; foreach($inventory_data as $item): ?>
<?php
  $origStock = $item['available_stock'] ?? '';
  $newStock = $item['new_available_stock'];
  $origBin = $item['storage_bin'] ?? '';
  $newBin = $item['new_storage_bin'];
  $finalStock = ($newStock !== null && $newStock !== '') ? $newStock : $origStock;
  $finalBin = ($newBin !== null && $newBin !== '') ? $newBin : $origBin;
  $diffClass = '';
  $diffDisplay = '-';
  if ($newStock !== null && $newStock !== '') {
    if (preg_match('/^-?\d+$/', trim((string)$newStock)) && preg_match('/^-?\d+$/', trim((string)$origStock))) {
      $diffDisplay = (int)$newStock - (int)$origStock;
      if ($diffDisplay < 0) {
        $diffClass = 'diff-short';
      } elseif ($diffDisplay > 0) {
        $diffClass = 'diff-over';
      }
    }
  }
  $status = ($newStock !== null && $newStock !== '') || ($newBin !== null && $newBin !== '') ? 'Updated' : 'Pending';
?>
                    <tr>
                      <td><?php echo $no++; ?></td>
                      <td><?php echo htmlspecialchars($item['area'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['type'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['material'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['material_description'] ?? ''); ?></td>
                      <td><?php echo !empty($item['created_at']) ? date('Y-m-d H:i:s', strtotime($item['created_at'])) : '-'; ?></td>
                      <td><?php echo htmlspecialchars($origBin); ?></td>
                      <td><?php echo htmlspecialchars($newBin ?? '-'); ?></td>
                      <td><?php echo htmlspecialchars($finalBin); ?></td>
                      <td><?php echo htmlspecialchars($origStock); ?></td>
                      <td><?php echo htmlspecialchars($newStock ?? '-'); ?></td>
                      <td><?php echo htmlspecialchars($finalStock); ?></td>
                      <td class="<?php echo $diffClass; ?> text-center"><?php echo htmlspecialchars($diffDisplay); ?></td>
                      <td class="<?php echo $status === 'Updated' ? 'status-updated' : 'status-pending'; ?>">
                        <?php echo $status; ?>
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
    </div>
  </div>

  <?php include __DIR__ . '/layouts/footer.html'; ?>
  <?php include __DIR__ . '/layouts/scripts.html'; ?>

  <script>
    $(document).ready(function() {
      $('#result-table').DataTable({
        scrollY: '500px',
        scrollCollapse: true,
        paging: false,
        fixedHeader: true
      });
    });
  </script>
</body>
</html>

