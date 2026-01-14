<?php
// Early AJAX handler: must run before any HTML is output so JSON responses are valid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
  include 'db.php';
  $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
  $new_stock = isset($_POST['new_stock']) && $_POST['new_stock'] !== '' ? $_POST['new_stock'] : null;
  $new_location = isset($_POST['new_location']) && $_POST['new_location'] !== '' ? $_POST['new_location'] : null;
  $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
  if (!$id) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'invalid_id']); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error=invalid_id');
    exit;
  }
  if (is_null($new_stock) && is_null($new_location)) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'empty']); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error=empty');
    exit;
  }
  try {
    $setParts = [];
    $params = [];
    if (!is_null($new_stock)) { $setParts[] = "new_available_stock = ?"; $params[] = $new_stock; }
    if (!is_null($new_location)) { $setParts[] = "new_storage_bin = ?"; $params[] = $new_location; }
    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE stock_taking SET " . implode(', ', $setParts) . " WHERE id = ?");
    $stmt->execute($params);
    if ($isAjax) {
      $r = $pdo->prepare("SELECT available_stock, new_available_stock, new_storage_bin FROM stock_taking WHERE id = ?");
      $r->execute([$id]);
      $row = $r->fetch(PDO::FETCH_ASSOC);
      $diffClass = '';
      $diffDisplay = '-';
      if (!is_null($row['new_available_stock'])) {
        $avail = $row['available_stock'];
        $new = $row['new_available_stock'];
        if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
          $diffDisplay = (int)$new - (int)$avail;
          if ($diffDisplay < 0) { $diffClass = 'diff-short'; }
          elseif ($diffDisplay > 0) { $diffClass = 'diff-over'; }
        }
      }
      header('Content-Type: application/json');
      echo json_encode([
        'success' => true,
        'id' => $id,
        'new_stock' => $row['new_available_stock'],
        'new_location' => $row['new_storage_bin'],
        'diff' => $diffDisplay,
        'diffClass' => $diffClass
      ]);
      exit;
    }
    header('Location: '.$_SERVER['PHP_SELF'].'?updated=1');
  } catch (Exception $e) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error='.urlencode($e->getMessage()));
  }
  exit;
}


?>

<!DOCTYPE html>
<html lang="en">
<?php
include 'db.php'; // Koneksi ke database pst_project

// Buat tabel jika belum ada
try {
    // Cek apakah tabel sudah ada
    $result = $pdo->query("SHOW TABLES LIKE 'stock_taking'");
    if ($result->rowCount() == 0) {
        // Tabel belum ada, buat baru (kolom dibuat fleksibel tanpa batasan panjang/NOT NULL)
        $sql = "CREATE TABLE stock_taking (
            id INT AUTO_INCREMENT PRIMARY KEY,
            area VARCHAR(50),
            type VARCHAR(50),
          material TEXT,
          inventory_number TEXT,
          batch TEXT,
            material_description TEXT,
            storage_bin TEXT,
            new_storage_bin TEXT,
            available_stock TEXT,
            new_available_stock TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL,
            resolution_notes TEXT NULL,
            assigned_pic_id INT NULL
        )";
        $pdo->exec($sql);
        $pdo->exec("CREATE INDEX idx_st_assigned_pic ON stock_taking (assigned_pic_id)");
    } else {
        // Tabel sudah ada, hapus foreign key jika ada dan hapus kolom inventory_id jika ada
        $stmt = $pdo->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'stock_taking' AND TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'inventory'");
        $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            $pdo->exec("ALTER TABLE stock_taking DROP FOREIGN KEY " . $constraint['CONSTRAINT_NAME']);
        }
        // Cek apakah kolom inventory_id ada
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'inventory_id'");
        if ($stmt->rowCount() > 0) {
            $pdo->exec("ALTER TABLE stock_taking DROP COLUMN inventory_id");
        }
        // Tambah kolom inventory_number jika belum ada
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'inventory_number'");
        if ($stmt->rowCount() == 0) {
          $pdo->exec("ALTER TABLE stock_taking ADD COLUMN inventory_number TEXT AFTER material");
        }

        // Tambah kolom batch jika belum ada
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'batch'");
        if ($stmt->rowCount() == 0) {
          $pdo->exec("ALTER TABLE stock_taking ADD COLUMN batch TEXT AFTER inventory_number");
        }

        // Ubah material menjadi tipe fleksibel (TEXT) dan biarkan NULL jika perlu
        try {
          $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN material TEXT NULL");
        } catch (Exception $e) {
          // ignore
        }

        // Pastikan inventory_number tersedia, tetapi gunakan tipe TEXT yang fleksibel
        $pdo->exec("UPDATE stock_taking SET inventory_number = COALESCE(NULLIF(inventory_number, ''), material) WHERE inventory_number IS NULL OR inventory_number = ''");
        try {
          $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN inventory_number TEXT NULL");
        } catch (Exception $e) {
          // ignore
        }

        // Pastikan kolom batch ada dan bertipe TEXT
        try {
          $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'batch'");
          $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($columnInfo && stripos($columnInfo['Type'], 'text') === false) {
            $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN batch TEXT");
          }
        } catch (Exception $e) {
          // Ignore error
        }

        // Pastikan storage_bin bertipe TEXT untuk menghindari notasi ilmiah dari Excel
        try {
          $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'storage_bin'");
          $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($columnInfo && stripos($columnInfo['Type'], 'text') === false) {
            $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN storage_bin TEXT");
          }
        } catch (Exception $e) {
          // Ignore error
        }

        // Ubah tipe kolom available_stock dan new_available_stock menjadi VARCHAR untuk menyimpan data exactly seperti Excel
        // Pastikan kolom stock bertipe TEXT untuk menghilangkan batasan panjang/format
        try {
          $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'available_stock'");
          $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($columnInfo && stripos($columnInfo['Type'], 'text') === false) {
            $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN available_stock TEXT");
          }
        } catch (Exception $e) {
          // Ignore error
        }

        try {
          $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'new_available_stock'");
          $columnInfo = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($columnInfo && stripos($columnInfo['Type'], 'text') === false) {
            $pdo->exec("ALTER TABLE stock_taking MODIFY COLUMN new_available_stock TEXT");
          }
        } catch (Exception $e) {
          // Ignore error
        }

        // Tambah created_at jika belum ada
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'created_at'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE stock_taking ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER new_available_stock");
        }

        // Tambah resolved_at dan resolution_notes jika belum ada
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'resolved_at'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolved_at TIMESTAMP NULL AFTER created_at");
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'resolution_notes'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE stock_taking ADD COLUMN resolution_notes TEXT NULL AFTER resolved_at");
        }

        // Tambah kolom assigned_pic_id untuk penugasan PIC
        $stmt = $pdo->query("SHOW COLUMNS FROM stock_taking LIKE 'assigned_pic_id'");
        if ($stmt->rowCount() == 0) {
          $pdo->exec("ALTER TABLE stock_taking ADD COLUMN assigned_pic_id INT NULL AFTER resolution_notes");
          $pdo->exec("CREATE INDEX idx_st_assigned_pic ON stock_taking (assigned_pic_id)");
        }

        // Tidak menambahkan index berbasis panjang kolom TEXT di sini untuk menghindari batasan
    }
} catch (PDOException $e) {
    die("Error creating or modifying table: " . $e->getMessage());
}

// Ambil data stock_taking untuk tanggal hari ini (filter di tabel DataTables tetap tersedia)
try {
  $stmt = $pdo->prepare("SELECT st.*, p.name AS pic_name, p.nrp AS pic_nrp
               FROM stock_taking st
               LEFT JOIN pic p ON p.id = st.assigned_pic_id
               WHERE DATE(st.created_at) = CURDATE()
               ORDER BY st.id");
  $stmt->execute();
  $inventory_data = $stmt->fetchAll();
} catch (PDOException $e) {
  die("Error fetching data: " . $e->getMessage());
}

if(isset($_POST['update'])){
  $id = isset($_POST['id']) ? (int)$_POST['id'] : null;
  $new_stock = isset($_POST['new_stock']) && $_POST['new_stock'] !== '' ? $_POST['new_stock'] : null;
  $new_location = isset($_POST['new_location']) && $_POST['new_location'] !== '' ? $_POST['new_location'] : null;
  $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
  if (!$id) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'invalid_id']); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error=invalid_id');
    exit;
  }
  if (is_null($new_stock) && is_null($new_location)) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'empty']); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error=empty');
    exit;
  }
  try {
    $setParts = [];
    $params = [];
    if (!is_null($new_stock)) {
      $setParts[] = "new_available_stock = ?";
      $params[] = $new_stock;
    }
    if (!is_null($new_location)) {
      $setParts[] = "new_storage_bin = ?";
      $params[] = $new_location;
    }
    $params[] = $id;
    $stmt = $pdo->prepare("UPDATE stock_taking SET " . implode(', ', $setParts) . " WHERE id = ?");
    $stmt->execute($params);

    if ($isAjax) {
      // Fetch updated values to compute diff and return for JS
      $r = $pdo->prepare("SELECT available_stock, new_available_stock, new_storage_bin FROM stock_taking WHERE id = ?");
      $r->execute([$id]);
      $row = $r->fetch(PDO::FETCH_ASSOC);
      $diffClass = '';
      $diffDisplay = '-';
      if (!is_null($row['new_available_stock'])) {
        $avail = $row['available_stock'];
        $new = $row['new_available_stock'];
        if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
          $diffDisplay = (int)$new - (int)$avail;
          if ($diffDisplay < 0) {
            $diffClass = 'diff-short';
          } elseif ($diffDisplay > 0) {
            $diffClass = 'diff-over';
          }
        }
      }
      header('Content-Type: application/json');
      echo json_encode([
        'success' => true,
        'id' => $id,
        'new_stock' => $row['new_available_stock'],
        'new_location' => $row['new_storage_bin'],
        'diff' => $diffDisplay,
        'diffClass' => $diffClass
      ]);
      exit;
    }

    header('Location: '.$_SERVER['PHP_SELF'].'?updated=1');
  } catch (Exception $e) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); exit; }
    header('Location: '.$_SERVER['PHP_SELF'].'?error='.urlencode($e->getMessage()));
  }
  exit;
}

if(isset($_POST['upload'])){
    $file = $_FILES['excel_file']['tmp_name'];
    if($file){
        require 'vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        // Use formatted strings to avoid scientific notation (e.g., 207E0402) becoming INF/float
        $data = $worksheet->toArray(null, false, true, false);
        array_shift($data); // skip header
        $pdo->beginTransaction();
        try {
              // Gunakan INSERT IGNORE agar baris duplikat tidak menyebabkan error/rollback
              $insertStmt = $pdo->prepare("INSERT IGNORE INTO stock_taking (area, type, material, inventory_number, batch, material_description, storage_bin, available_stock) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $conversions = [];
            foreach($data as $idx => $row){
              // Ambil storage_bin sebagai string terformat untuk menghindari notasi ilmiah/INF
              $excelRow = $idx + 2; // header di baris 1
              $cellCount = count($row);
              if ($cellCount >= 8) {
                // New template with batch: storage_bin is column G (7)
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(7); // kolom G (storage_bin)
                $cellObj = $worksheet->getCell($colLetter.$excelRow);
                // Prefer the formatted/displayed value so textual formatting (e.g. text or leading apostrophe)
                // is preserved and Excel's scientific-notation conversions are avoided.
                $formatted = $cellObj->getFormattedValue();
                $storageBin = isset($formatted) ? (string)$formatted : (string)$cellObj->getValue();
                // Detect scientific notation like 1.02E+103 or 1.02E103 and try to auto-convert
                if (preg_match('/^([0-9]+(?:\.[0-9]+)?)[eE]([+\-]?\d+)$/', trim($storageBin), $m)) {
                  $mantissa = $m[1];
                  $exp = intval($m[2]);
                  // Remove decimal point from mantissa
                  $mantissaDigits = str_replace('.', '', $mantissa);
                  // Pad exponent to 4 digits (domain-specific heuristic)
                  $expPadded = str_pad((string)$exp, 4, '0', STR_PAD_LEFT);
                  $converted = strtoupper($mantissaDigits . 'E' . $expPadded);
                  $conversions[] = 'row '.$excelRow.' : '.$storageBin.' -> '.$converted;
                  $storageBin = $converted;
                }
                $row[6] = $storageBin ?? '';
              } elseif ($cellCount >= 7) {
                // Template without batch: storage_bin is column F (6)
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(6); // kolom F (storage_bin)
                $cellObj = $worksheet->getCell($colLetter.$excelRow);
                $formatted = $cellObj->getFormattedValue();
                $storageBin = isset($formatted) ? (string)$formatted : (string)$cellObj->getValue();
                if (preg_match('/^([0-9]+(?:\.[0-9]+)?)[eE]([+\-]?\d+)$/', trim($storageBin), $m)) {
                  $mantissa = $m[1];
                  $exp = intval($m[2]);
                  $mantissaDigits = str_replace('.', '', $mantissa);
                  $expPadded = str_pad((string)$exp, 4, '0', STR_PAD_LEFT);
                  $converted = strtoupper($mantissaDigits . 'E' . $expPadded);
                  $conversions[] = 'row '.$excelRow.' : '.$storageBin.' -> '.$converted;
                  $storageBin = $converted;
                }
                $row[5] = $storageBin ?? '';
              } elseif ($cellCount >= 6) {
                // Legacy template: storage_bin is column E (5)
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5); // kolom E (storage_bin)
                $cellObj = $worksheet->getCell($colLetter.$excelRow);
                $formatted = $cellObj->getFormattedValue();
                $storageBin = isset($formatted) ? (string)$formatted : (string)$cellObj->getValue();
                if (preg_match('/^([0-9]+(?:\.[0-9]+)?)[eE]([+\-]?\d+)$/', trim($storageBin), $m)) {
                  $mantissa = $m[1];
                  $exp = intval($m[2]);
                  $mantissaDigits = str_replace('.', '', $mantissa);
                  $expPadded = str_pad((string)$exp, 4, '0', STR_PAD_LEFT);
                  $converted = strtoupper($mantissaDigits . 'E' . $expPadded);
                  $conversions[] = 'row '.$excelRow.' : '.$storageBin.' -> '.$converted;
                  $storageBin = $converted;
                }
                $row[4] = $storageBin ?? '';
              }

              // Normalize row length by trimming trailing null/empty cells
              // Ensure backward compatibility: if no inventory_number column, use material as inventory_number
              if ($cellCount >= 8) {
                // New template with batch: Area, Type, Material, Inventory Number, Batch, Material Description, Storage Bin, Available stock
                $insertStmt->execute([
                  $row[0] ?? '',
                  $row[1] ?? '',
                  $row[2] ?? '',
                  $row[3] ?? '',
                  $row[4] ?? '',
                  $row[5] ?? '',
                  $row[6] ?? '',
                  $row[7] ?? ''
                ]);
              } elseif ($cellCount >= 7) {
                // Expected order (no batch): Area, Type, Material, Inventory Number, Material Description, Storage Bin, Available stock
                $insertStmt->execute([
                  $row[0] ?? '',
                  $row[1] ?? '',
                  $row[2] ?? '',
                  $row[3] ?? '',
                  '', // batch empty
                  $row[4] ?? '',
                  $row[5] ?? '',
                  $row[6] ?? ''
                ]);
              } elseif ($cellCount >= 6) {
                // Legacy template: Area, Type, Material, Material Description, Storage Bin, Available stock
                $insertStmt->execute([
                  $row[0] ?? '',
                  $row[1] ?? '',
                  $row[2] ?? '',
                  $row[2] ?? '', // inventory_number fallback to Material
                  '', // batch empty
                  $row[3] ?? '',
                  $row[4] ?? '',
                  $row[5] ?? ''
                ]);
              }
            }
            $pdo->commit();
            $convParam = '';
            if (!empty($conversions)) {
              $convParam = '&converted=1&conv=' . urlencode(base64_encode(json_encode($conversions)));
            }
            header('Location: '.$_SERVER['PHP_SELF'].'?upload=1'.$convParam);
            exit;
        } catch (Exception $e) {
          $pdo->rollBack();
          $errorMsg = $e->getMessage();
          if (strpos($errorMsg, 'scientific_notation') !== false) {
            // Extract info after the marker
            $parts = explode(':', $errorMsg, 2);
            $info = isset($parts[1]) ? trim($parts[1]) : '';
            header('Location: '.$_SERVER['PHP_SELF'].'?error=scientific&info='.urlencode($info));
          } else {
            header('Location: '.$_SERVER['PHP_SELF'].'?error='.urlencode($errorMsg));
          }
          exit;
        }
    } else {
        header('Location: '.$_SERVER['PHP_SELF'].'?error=no_file');
        exit;
    }
}
?>
<?php include 'layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <?php include 'layouts/preloader.html'; ?>
  <?php include 'layouts/sidebar.html'; ?>
  <?php include 'layouts/header.html'; ?>

  <style>
    /* Highlight differences regardless of striped row colors */
    .table .diff-short { background-color: #f8d7da !important; color: #721c24 !important; }
    .table .diff-over  { background-color: #fff3cd !important; color: #856404 !important; }
    /* Ensure horizontal scrolling on small screens */
    .table-responsive { overflow-x: auto; }
  </style>

  <!-- [ Main Content ] start -->
  <div class="pc-container">
    <div class="pc-content">
      <!-- [ breadcrumb ] start -->
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Pengambilan Stok</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Beranda</a></li>
                <li class="breadcrumb-item"><a href="javascript: void(0)">Halaman</a></li>
                <li class="breadcrumb-item" aria-current="page">Pengambilan Stok</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <!-- [ breadcrumb ] end -->

      <!-- [ Main Content ] start -->
      <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-sm-12">
          <div class="card">
            <div class="card-header">
              <h5>Form Pengambilan Stok</h5>
            </div>
            <div class="card-body">

              <div class="mb-3">
                <a href="download_stock_template.php" class="btn btn-info btn-sm">Unduh Template Excel</a>
              </div>
              <div class="mb-3 col-3">
                <form method="post" enctype="multipart/form-data">
                  <div class="input-group input-group-sm">
                    <input type="file" name="excel_file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                    <button type="submit" action="" name="upload" class="btn btn-success btn-sm">Unggah Excel</button>
                  </div>
                </form>
              </div>
             
              <div class="table-responsive">
                <table id="stock-table" class="table table-striped table-hover table-bordered" style="border-color: black;">
                  <thead style="background-color: orange;">
                    <tr>
                      <th>No</th>
                      <th>Area</th>
                      <th>Tipe</th>
                      <th>Material</th>
                      <th>Nomor Inventaris</th>
                      <th>Batch</th>
                      <th>Deskripsi Material</th>
                      <th>Bin Penyimpanan</th>
                      <th>Stok Tersedia</th>
                      <th>Stok Baru</th>
                      <th>Lokasi Baru</th>
                      <th>PIC</th>
                      <th>Action</th>
                      <th>Different</th>
                    </tr>
                    <tr>
                      <th></th>
                      <th><input type="text" placeholder="Search Area" class="form-control form-control-sm column_search" data-column="1"></th>
                      <th><input type="text" placeholder="Search Type" class="form-control form-control-sm column_search" data-column="2"></th>
                      <th><input type="text" placeholder="Search Material" class="form-control form-control-sm column_search" data-column="3"></th>
                      <th><input type="text" placeholder="Search Inventory Number" class="form-control form-control-sm column_search" data-column="4"></th>
                      <th><input type="text" placeholder="Search Batch" class="form-control form-control-sm column_search" data-column="5"></th>
                      <th><input type="text" placeholder="Search Material Description" class="form-control form-control-sm column_search" data-column="6"></th>
                      <th><input type="text" placeholder="Search Storage Bin" class="form-control form-control-sm column_search" data-column="7"></th>
                      <th><input type="text" placeholder="Search Available stock" class="form-control form-control-sm column_search" data-column="8"></th>
                      <th></th>
                      <th></th>
                      <th><input type="text" placeholder="Search PIC" class="form-control form-control-sm column_search" data-column="11"></th>
                      <th></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
<?php $no = 1; foreach($inventory_data as $item): ?>
                    <tr>
                      <td><?php echo $no++; ?></td>
                      <td><?php echo htmlspecialchars($item['area'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['type'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['material'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['inventory_number'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['batch'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['material_description'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['storage_bin'] ?? ''); ?></td>
                      <td><?php echo htmlspecialchars($item['available_stock'] ?? ''); ?></td>
                      <td>
                        <?php if (!is_null($item['new_available_stock'])): ?>
                          <?php echo htmlspecialchars($item['new_available_stock']); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!is_null($item['new_storage_bin'])): ?>
                          <?php echo htmlspecialchars($item['new_storage_bin']); ?>
                        <?php else: ?>
                          -
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (!empty($item['pic_name'])): ?>
                          <span class="badge bg-info text-dark"><?php echo htmlspecialchars($item['pic_name']); ?></span><br>
                          <small class="text-muted"><?php echo htmlspecialchars($item['pic_nrp']); ?></small>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?php if (is_null($item['new_available_stock']) || is_null($item['new_storage_bin'])): ?>
                          <button class="btn btn-sm btn-success update-btn"
                                  data-id="<?php echo $item['id']; ?>"
                                  data-available-stock="<?php echo htmlspecialchars($item['available_stock']); ?>"
                                  data-new-stock="<?php echo htmlspecialchars($item['new_available_stock'] ?? ''); ?>"
                                  data-new-location="<?php echo htmlspecialchars($item['new_storage_bin'] ?? ''); ?>"
                                  data-area="<?php echo htmlspecialchars($item['area']); ?>"
                                  data-material="<?php echo htmlspecialchars($item['material']); ?>"
                                  data-inventory-number="<?php echo htmlspecialchars($item['inventory_number']); ?>"
                                  data-batch="<?php echo htmlspecialchars($item['batch'] ?? ''); ?>"
                                  data-storage-bin="<?php echo htmlspecialchars($item['storage_bin'] ?? ''); ?>"
                                  data-material-description="<?php echo htmlspecialchars($item['material_description'] ?? ''); ?>">
                            Update
                          </button>
                        <?php else: ?>
                          <span class="text-success">Updated</span>
                        <?php endif; ?>
                      </td>
                      <?php
                        $diffClass = '';
                        $diffDisplay = '-';
                        if (!is_null($item['new_available_stock'])) {
                          $avail = $item['available_stock'];
                          $new = $item['new_available_stock'];
                          if (preg_match('/^-?\d+$/', trim((string)$new)) && preg_match('/^-?\d+$/', trim((string)$avail))) {
                            $diffDisplay = (int)$new - (int)$avail;
                            if ($diffDisplay < 0) {
                              $diffClass = 'diff-short'; // short: merah
                            } elseif ($diffDisplay > 0) {
                              $diffClass = 'diff-over'; // over: kuning
                            }
                          }
                        }
                      ?>
                      <td class="difference <?php echo $diffClass; ?>">
                        <?php echo htmlspecialchars($diffDisplay); ?>
                      </td>
                    </tr>
<?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <!-- [ sample-page ] end -->
      </div>
      <!-- [ Main Content ] end -->
    </div>
  </div>
  <!-- [ Main Content ] end -->
  <!-- Update Modal -->
  <div class="modal fade" id="updateModal" tabindex="-1" aria-labelledby="updateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="updateModalLabel">Update Stock</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="updateForm" method="post" action="">
            <input type="hidden" name="update" value="1">
            <input type="hidden" id="modalRecordId" name="id">
            <div class="mb-2">
              <label class="form-label">Area</label>
              <input type="text" class="form-control" id="modalArea" disabled>
            </div>
            <div class="mb-2">
              <label class="form-label">Material</label>
              <input type="text" class="form-control" id="modalMaterial" disabled>
            </div>
            <div class="mb-2">
              <label class="form-label">Part Description</label>
              <textarea class="form-control" id="modalPartDescription" rows="2" disabled></textarea>
            </div>
            <div class="mb-2">
              <label class="form-label">Current Location</label>
              <input type="text" class="form-control" id="modalCurrentLocation" disabled>
            </div>
            <div class="mb-3">
              <label class="form-label">New Stock</label>
              <input type="text" class="form-control" id="modalNewStock" name="new_stock" placeholder="Enter new stock">
            </div>
            <div class="mb-3">
              <label class="form-label">Lokasi Baru</label>
              <input type="text" class="form-control" id="modalNewLocation" name="new_location" placeholder="Enter new location (optional)">
            </div>
            <div class="mb-2">
              <small class="text-muted">Difference: <span id="modalDifference">0</span></small>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" form="updateForm" id="modalSaveBtn">Save</button>
        </div>
      </div>
    </div>
  </div>
  <?php include 'layouts/footer.html'; ?>
  <?php include 'layouts/scripts.html'; ?>

  <!-- DataTable Initialization -->
  <script>
    $(document).ready(function() {
      var table = $('#stock-table').DataTable({
        responsive: true,
        paging: true,
        pageLength: 25,
        autoWidth: false,
        columnDefs: [
          { responsivePriority: 1, targets: 3 }, // Material
          { responsivePriority: 2, targets: 4 }, // Inventory Number
          { responsivePriority: 3, targets: 5 }  // Batch
        ]
      });

      // Column search functionality
      $('.column_search').on('keyup change', function() {
        var columnIndex = $(this).data('column');
        table.column(columnIndex).search(this.value).draw();
      });

      var modalAvailableStock = 0;

      // Open modal on update button click
      $('#stock-table').on('click', '.update-btn', function() {
        var btn = $(this);
        var id = btn.data('id');
        var availableStock = btn.data('available-stock') || '0';
        var newStock = btn.data('new-stock');
        var newLocation = btn.data('new-location');
        var area = btn.data('area');
        var material = btn.data('material');
        var storageBin = btn.data('storage-bin') || '';
        var materialDesc = btn.data('material-description') || '';

        modalAvailableStock = availableStock;
        $('#modalRecordId').val(id);
        $('#modalArea').val(area);
        $('#modalMaterial').val(material);
        $('#modalPartDescription').val(materialDesc);
        $('#modalCurrentLocation').val(storageBin);

        if (newStock) {
          $('#modalNewStock').val(newStock).prop('disabled', true);
          $('#modalDifference').text('');
        } else {
          $('#modalNewStock').val('').prop('disabled', false);
          $('#modalDifference').text('');
        }

        if (newLocation) {
          $('#modalNewLocation').val(newLocation).prop('disabled', true);
        } else {
          $('#modalNewLocation').val('').prop('disabled', false);
        }

        $('#updateModal').modal('show');
      });

      // Recalculate difference in modal
      $('#modalNewStock').on('input', function() {
        var val = $(this).val();
        var display = '';
        if (val !== '') {
          // Only show difference if input is a valid integer
          if (/^-?\d+$/.test(val.trim())) {
            var numVal = parseInt(val, 10);
            // Try to parse available stock as well
            var availVal = 0;
            if (/^-?\d+$/.test(String(modalAvailableStock).trim())) {
              availVal = parseInt(String(modalAvailableStock), 10);
            }
            display = numVal - availVal;
          } else {
            display = '-'; // Show dash for non-numeric input
          }
        }
        $('#modalDifference').text(display);
      });

      // AJAX submit: update row without reloading the page
      $('#updateForm').on('submit', function(e) {
        e.preventDefault();
        var newStockField = $('#modalNewStock');
        var newLocationField = $('#modalNewLocation');
        var hasStock = !newStockField.prop('disabled') && newStockField.val() !== '';
        var hasLocation = !newLocationField.prop('disabled') && newLocationField.val() !== '';
        if (!hasStock && !hasLocation) {
          return; // nothing to send
        }
        var form = $(this);
        var data = form.serialize() + '&ajax=1';
        $('#modalSaveBtn').prop('disabled', true);
        $.ajax({
          url: '',
          method: 'POST',
          data: data,
          dataType: 'json'
        }).done(function(res){
          if (res && res.success) {
            var id = res.id;
            var row = $('#stock-table').find('.update-btn[data-id="'+id+'"]').closest('tr');
            if (row.length) {
              // update New Stock cell (index 9) and New Location (10)
              var newStockText = res.new_stock !== null && res.new_stock !== '' ? res.new_stock : '-';
              var newLocText = res.new_location !== null && res.new_location !== '' ? res.new_location : '-';
              row.find('td').eq(9).text(newStockText);
              row.find('td').eq(10).text(newLocText);
              // replace action cell with 'Updated'
              row.find('td').eq(12).html('<span class="text-success">Updated</span>');
              // update diff cell and classes
              var diffCell = row.find('td.difference');
              diffCell.removeClass('diff-short diff-over');
              if (res.diffClass) diffCell.addClass(res.diffClass);
              diffCell.text(res.diff);
              // redraw datatable row
              if (typeof table !== 'undefined') {
                table.row(row).invalidate().draw(false);
              }
            }
            $('#updateModal').modal('hide');
            Swal.fire({icon:'success', title:'Tersimpan', timer:1000, showConfirmButton:false});
          } else {
            var msg = (res && res.error) ? res.error : 'Gagal memperbarui';
            Swal.fire({icon:'error', title:'Error', text: msg});
          }
        }).fail(function(jqXHR, textStatus, errorThrown){
          console.error('AJAX update failed', textStatus, errorThrown, jqXHR.responseText);
          var serverMsg = '';
          try {
            // try parse JSON error
            var parsed = JSON.parse(jqXHR.responseText || '{}');
            serverMsg = parsed.error || parsed.message || '';
          } catch(e) {
            serverMsg = jqXHR.responseText || '';
          }
          var display = 'Gagal memperbarui (network)';
          if (serverMsg) display += ': ' + (serverMsg.length>200?serverMsg.substring(0,200)+'...':serverMsg);
          Swal.fire({icon:'error', title:'Error', text: display});
        }).always(function(){
          $('#modalSaveBtn').prop('disabled', false);
        });
      });

      // Show SweetAlert only for Excel upload operations
      try {
        var params = new URLSearchParams(window.location.search);
        // Upload success popup
        if (params.get('upload') === '1') {
          Swal.fire({
            icon: 'success',
            title: 'Upload berhasil',
            timer: 1500,
            showConfirmButton: false
          });
        }
        // Upload-related errors only (no_file, duplicate)
        if (params.get('error') === 'no_file') {
          Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: 'Silakan pilih file terlebih dahulu.'
          });
        }
        if (params.get('error') === 'duplicate') {
          Swal.fire({
            icon: 'error',
            title: 'Duplikat data',
            text: 'Data dengan kombinasi Material dan Inventory Number yang sama sudah ada. Silakan periksa kembali file Excel Anda.'
          });
        }
        if (params.get('converted') === '1' && params.get('conv')) {
          try {
            var decoded = JSON.parse(decodeURIComponent(escape(window.atob(params.get('conv')))));
            var list = decoded.slice(0,5).join('\n');
            Swal.fire({
              icon: 'info',
              title: 'Beberapa nilai Storage Bin otomatis dikonversi',
              text: 'Contoh konversi:\n' + list + (decoded.length>5 ? '\n... dan ' + (decoded.length-5) + ' lainnya' : '')
            });
          } catch(e) {
            // ignore
          }
        }
        if (params.get('error') === 'scientific') {
          var info = params.get('info') || '';
          Swal.fire({
            icon: 'error',
            title: 'Format Storage Bin Salah',
            text: 'Ditemukan nilai dalam notasi ilmiah pada upload: ' + info + '. Silakan ubah kolom Storage Bin menjadi format Text dan coba lagi.'
          });
        }
      } catch (e) {
        // ignore URL parsing errors
      }

      // Form submission is handled by standard POST; no AJAX
    });
  </script>
</body>
</html>