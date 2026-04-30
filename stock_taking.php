<?php
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
include 'db.php';
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
<?php
// ── Second PHP block: DB setup + data fetch ────────────────────────────────

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

$totalRows = count($inventory_data);
$assignedRows = 0;
$updatedRows = 0;
$differenceRows = 0;

foreach ($inventory_data as $record) {
  if (!empty($record['assigned_pic_id'])) {
    $assignedRows++;
  }

  $hasNewStock = !is_null($record['new_available_stock']) && $record['new_available_stock'] !== '';
  $hasNewLocation = !is_null($record['new_storage_bin']) && $record['new_storage_bin'] !== '';
  if ($hasNewStock || $hasNewLocation) {
    $updatedRows++;
  }

  if ($hasNewStock) {
    $available = trim((string) ($record['available_stock'] ?? ''));
    $newValue = trim((string) $record['new_available_stock']);
    if ($available !== $newValue) {
      $differenceRows++;
    }
  }
}

$pendingRows = max($totalRows - $updatedRows, 0);

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
    if (!class_exists('ZipArchive')) {
      header('Location: '.$_SERVER['PHP_SELF'].'?error=zip_missing');
      exit;
    }
        require 'vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        // Use formatted strings to avoid scientific notation (e.g., 207E0402) becoming INF/float.
        $data = $worksheet->toArray(null, false, true, false);
        $headerRow = array_shift($data);
        if (!is_array($headerRow)) $headerRow = [];

        $normalizeHeader = function ($value) {
          $v = strtolower(trim((string)$value));
          $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
          return trim((string)$v);
        };

        $headerMap = [];
        foreach ($headerRow as $i => $h) {
          $headerMap[$normalizeHeader($h)] = $i;
        }

        $findColumn = function (array $aliases) use ($headerMap, $normalizeHeader) {
          foreach ($aliases as $alias) {
            $key = $normalizeHeader($alias);
            if (array_key_exists($key, $headerMap)) {
              return $headerMap[$key];
            }
          }
          return null;
        };

        $colMaterial   = $findColumn(['material']);
        $colDesc       = $findColumn(['material description', 'description', 'deskripsi material']);
        $colType       = $findColumn(['type', 'tipe']);
        $colStorage    = $findColumn(['storage', 'storage bin', 'bin penyimpanan']);
        $colSystem     = $findColumn(['stok system', 'system stock', 'available stock', 'stok tersedia']);
        $colActual     = $findColumn(['stok actual', 'actual stock', 'new stock', 'stok baru']);
        $colInventory  = $findColumn(['inventory record', 'inventory number', 'nomor inventaris']);
        $colCreated    = $findColumn(['date created', 'created date', 'created at']);
        $colDifferent  = $findColumn(['different', 'difference']);
        $colPicName    = $findColumn(['pic pst', 'pic']);
        $colRelocation = $findColumn(['relokasi', 'relocation', 'new location', 'lokasi baru']);
        $colTrackDate  = $findColumn(['date tracking', 'tracking date']);
        $colTrackDesc  = $findColumn(['tracking description', 'tracking note', 'catatan tracking']);
        $colTrackStat  = $findColumn(['status tracking', 'tracking status']);
        $colArea       = $findColumn(['area']);
        $colBatch      = $findColumn(['batch']);

        // Backward compatibility fallback for very old templates without clear headers.
        if ($colMaterial === null && count($headerRow) >= 6) {
          $colArea      = 0;
          $colType      = 1;
          $colMaterial  = 2;
          $colInventory = 3;
          $colBatch     = count($headerRow) >= 8 ? 4 : null;
          $colDesc      = count($headerRow) >= 8 ? 5 : 4;
          $colStorage   = count($headerRow) >= 8 ? 6 : 5;
          $colSystem    = count($headerRow) >= 8 ? 7 : 6;
        }

        $extractCell = function ($row, $colIdx) {
          if ($colIdx === null) return '';
          return trim((string)($row[$colIdx] ?? ''));
        };

        $normalizeDateTime = function ($rawValue) {
          if ($rawValue === null || $rawValue === '') return null;
          if (is_numeric($rawValue)) {
            try {
              return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$rawValue)->format('Y-m-d H:i:s');
            } catch (Exception $e) {
              return null;
            }
          }
          $ts = strtotime((string)$rawValue);
          if ($ts === false) return null;
          return date('Y-m-d H:i:s', $ts);
        };

        $pdo->beginTransaction();
        try {
              // Insert with broader field mapping so monitoring-style templates can be uploaded directly.
              $insertStmt = $pdo->prepare("INSERT IGNORE INTO stock_taking (area, type, material, inventory_number, batch, material_description, storage_bin, available_stock, new_available_stock, new_storage_bin, resolution_notes, resolved_at, assigned_pic_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))");
              $picLookupStmt = $pdo->prepare("SELECT id FROM pic WHERE LOWER(name) = LOWER(?) LIMIT 1");
              $picCache = [];
            $conversions = [];
            foreach($data as $idx => $row){
              $excelRow = $idx + 2; // header di baris 1

              $material = $extractCell($row, $colMaterial);
              $desc = $extractCell($row, $colDesc);
              $type = $extractCell($row, $colType);
              $area = $extractCell($row, $colArea);
              $inventory = $extractCell($row, $colInventory);
              $batch = $extractCell($row, $colBatch);
              $systemStock = $extractCell($row, $colSystem);
              $actualStock = $extractCell($row, $colActual);
              $newLocation = $extractCell($row, $colRelocation);
              $trackingDesc = $extractCell($row, $colTrackDesc);
              $trackingStatus = strtolower($extractCell($row, $colTrackStat));
              $picName = $extractCell($row, $colPicName);

              if ($material === '' && $desc === '' && $inventory === '') {
                continue;
              }

              // Read storage from displayed value to preserve text representation in Excel.
              $storageBin = '';
              if ($colStorage !== null) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colStorage + 1);
                $cellObj = $worksheet->getCell($colLetter . $excelRow);
                $formatted = $cellObj->getFormattedValue();
                $storageBin = trim((string)(isset($formatted) ? $formatted : $cellObj->getValue()));
              }

              if (preg_match('/^([0-9]+(?:\.[0-9]+)?)[eE]([+\-]?\d+)$/', $storageBin, $m)) {
                $mantissaDigits = str_replace('.', '', $m[1]);
                $expPadded = str_pad((string)intval($m[2]), 4, '0', STR_PAD_LEFT);
                $converted = strtoupper($mantissaDigits . 'E' . $expPadded);
                $conversions[] = 'row '.$excelRow.' : '.$storageBin.' -> '.$converted;
                $storageBin = $converted;
              }

              if ($inventory === '') $inventory = $material;

              $createdAt = $normalizeDateTime($colCreated !== null ? ($row[$colCreated] ?? null) : null);
              $trackingDate = $normalizeDateTime($colTrackDate !== null ? ($row[$colTrackDate] ?? null) : null);

              // Optional fallback: if actual and system are present but difference is empty, keep it computed in DB/UI only.
              if ($trackingDesc === '') {
                $trackingDesc = $extractCell($row, $colDifferent);
              }

              $resolvedAt = null;
              if ($trackingDate !== null && (strpos($trackingStatus, 'resolve') !== false || strpos($trackingStatus, 'done') !== false || strpos($trackingStatus, 'close') !== false)) {
                $resolvedAt = $trackingDate;
              }

              $assignedPicId = null;
              if ($picName !== '') {
                $picKey = strtolower($picName);
                if (!array_key_exists($picKey, $picCache)) {
                  $picLookupStmt->execute([$picName]);
                  $picCache[$picKey] = $picLookupStmt->fetchColumn() ?: null;
                }
                $assignedPicId = $picCache[$picKey];
              }

              $insertStmt->execute([
                $area,
                $type,
                $material,
                $inventory,
                $batch,
                $desc,
                $storageBin,
                $systemStock,
                $actualStock === '' ? null : $actualStock,
                $newLocation === '' ? null : $newLocation,
                $trackingDesc === '' ? null : $trackingDesc,
                $resolvedAt,
                $assignedPicId,
                $createdAt
              ]);
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
<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<?php include __DIR__ . '/layouts/sidebar.html'; ?>
<?php include __DIR__ . '/layouts/header.html'; ?>


<style>
  /* ── Diff row highlights ───────────────────────────────────────── */
  .diff-short { background-color: #fef2f2 !important; color: #b91c1c !important; font-weight: 700; }
  .diff-over  { background-color: #fefce8 !important; color: #854d0e !important; font-weight: 700; }

  /* ── Section card (matches monitoring_pst) ─────────────────────── */
  .section-card {
    border-radius: 14px;
    border: 1px solid rgba(251,146,60,.25);
    box-shadow: 0 10px 22px rgba(234,88,12,.10);
  }
  .section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(251,146,60,.22);
    background: linear-gradient(120deg,#fff7ed 0%,#ffedd5 100%);
    border-top-left-radius: 14px;
    border-top-right-radius: 14px;
  }
  .section-pill {
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .2px;
    background: #f97316;
    color: #fff;
  }

  /* ── Stat cards ────────────────────────────────────────────────── */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 1rem;
  }
  .stat-card {
    border: 1px solid rgba(249,115,22,.15);
    border-radius: 16px;
    padding: .95rem 1rem;
    background: rgba(255,255,255,.92);
    box-shadow: 0 8px 20px rgba(234,88,12,.07);
  }
  .stat-card__label {
    display: block;
    font-size: .73rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #9a3412;
    margin-bottom: .3rem;
  }
  .stat-card__value {
    font-size: 1.7rem;
    font-weight: 700;
    line-height: 1;
    color: #111827;
  }
  .stat-card__hint {
    display: block;
    margin-top: .35rem;
    font-size: .82rem;
    color: #7c6a5b;
  }

  /* ── Upload card ───────────────────────────────────────────────── */
  .upload-card {
    border-radius: 12px;
    border: 1px dashed rgba(249,115,22,.3);
    background: rgba(255,255,255,.85);
    padding: 1rem;
  }
  .upload-card .form-control { min-height: 44px; }

  /* ── Filter bar (matches monitoring_pst) ──────────────────────── */
  .filter-card {
    border-radius: 12px;
    border: 1px solid rgba(251,146,60,.24);
    background: #fff;
    box-shadow: 0 8px 18px rgba(234,88,12,.08);
  }
  .filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
  }
  .filter-bar .form-control,
  .filter-bar .form-select {
    font-size: .83rem;
    min-width: 130px;
    max-width: 210px;
  }
  .filter-bar .form-control.search-wide {
    min-width: 200px;
    max-width: 300px;
  }
  .filter-label {
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
    color: #64748b;
    margin-bottom: 2px;
  }

  /* ── Input table ───────────────────────────────────────────────── */
  .table-wrap { overflow: auto; max-height: 68vh; }
  .input-table { min-width: 1550px; }
  .input-table th {
    white-space: nowrap;
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .35px;
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f8fafc;
    box-shadow: inset 0 -1px 0 rgba(148,163,184,.35);
  }
  .input-table td { font-size: .82rem; vertical-align: middle; }
  .desc-cell {
    max-width: 260px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* ── Status pills (matches monitoring_pst) ─────────────────────── */
  .status-pill {
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .72rem;
    font-weight: 700;
  }
  .status-done  { background: #dcfce7; color: #166534; }
  .status-open  { background: #ffedd5; color: #9a3412; }
  .status-none  { background: #f1f5f9; color: #475569; }
  .diff-plus { color: #166534; font-weight: 700; }
  .diff-minus { color: #b91c1c; font-weight: 700; }

  /* ── Modal ─────────────────────────────────────────────────────── */
  .modal-record-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0,1fr));
    gap: .9rem;
  }
  .modal-readonly {
    padding: .85rem 1rem;
    border-radius: 14px;
    border: 1px solid rgba(249,115,22,.14);
    background: #fffaf5;
  }
  .modal-readonly__label {
    display: block;
    margin-bottom: .3rem;
    font-size: .76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #9a3412;
  }
  .modal-readonly__value { color: #111827; word-break: break-word; }
  .modal-diff-box {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem .8rem;
    border-radius: 999px;
    background: #fff7ed;
    color: #9a3412;
    font-weight: 700;
  }

  @media (max-width: 991px) {
    .stat-grid { grid-template-columns: repeat(2, 1fr); }
    .modal-record-grid { grid-template-columns: 1fr; }
  }
  @media (max-width: 575px) {
    .stat-grid { grid-template-columns: 1fr; }
  }

    .stock-shell {
      display: grid;
      gap: 1.5rem;
    }

    .stock-hero {
      position: relative;
      overflow: hidden;
      border: 1px solid rgba(249, 115, 22, 0.15);
      border-radius: 24px;
      padding: 1.75rem;
      background:
        radial-gradient(circle at top right, rgba(251, 146, 60, 0.24), transparent 32%),
        linear-gradient(135deg, rgba(255, 247, 237, 0.98), rgba(255, 255, 255, 0.96));
      box-shadow: 0 20px 44px rgba(234, 88, 12, 0.08);
    }

    .stock-hero::after {
      content: '';
      position: absolute;
      inset: auto -40px -60px auto;
      width: 220px;
      height: 220px;
      border-radius: 50%;
      background: rgba(249, 115, 22, 0.10);
      filter: blur(10px);
      pointer-events: none;
    }

    .stock-hero__content,
    .stock-hero__meta {
      position: relative;
      z-index: 1;
    }

    .stock-kicker {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.4rem 0.8rem;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.72);
      color: #9a3412;
      font-size: 0.82rem;
      font-weight: 600;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .stock-hero h4 {
      margin: 1rem 0 0.55rem;
      font-size: 1.85rem;
      line-height: 1.2;
      color: #7c2d12;
    }

    .stock-hero p {
      max-width: 720px;
      margin-bottom: 0;
      color: #7c6a5b;
    }

    .stock-meta-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 1rem;
      margin-top: 1.5rem;
    }

    .stock-stat {
      border: 1px solid rgba(249, 115, 22, 0.14);
      border-radius: 18px;
      padding: 1rem 1.05rem;
      background: rgba(255, 255, 255, 0.84);
      box-shadow: 0 14px 30px rgba(234, 88, 12, 0.06);
    }

    .stock-stat__label {
      display: block;
      margin-bottom: 0.4rem;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #9a3412;
    }

    .stock-stat__value {
      font-size: 1.75rem;
      font-weight: 700;
      line-height: 1;
      color: #111827;
    }

    .stock-stat__hint {
      display: block;
      margin-top: 0.45rem;
      color: #7c6a5b;
      font-size: 0.85rem;
    }

    .stock-panel {
      border: 1px solid rgba(249, 115, 22, 0.14);
      border-radius: 22px;
      background: linear-gradient(180deg, rgba(255, 253, 249, 0.98), rgba(255, 247, 239, 0.98));
      box-shadow: 0 18px 36px rgba(234, 88, 12, 0.08);
    }

    .stock-toolbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 1rem;
      flex-wrap: wrap;
      padding: 1.35rem 1.35rem 0;
    }

    .stock-toolbar__title h5 {
      margin-bottom: 0.35rem;
      color: #7c2d12;
    }

    .stock-toolbar__title p {
      margin-bottom: 0;
      color: #7c6a5b;
    }

    .stock-actions {
      display: flex;
      gap: 0.75rem;
      flex-wrap: wrap;
      align-items: center;
    }

    .stock-upload {
      padding: 1.35rem;
    }

    .stock-upload-card {
      border: 1px dashed rgba(249, 115, 22, 0.28);
      border-radius: 18px;
      padding: 1rem;
      background: rgba(255, 255, 255, 0.76);
    }

    .stock-upload-card .input-group {
      align-items: stretch;
    }

    .stock-upload-card .form-control {
      min-height: 46px;
    }

    .stock-upload-card .btn {
      min-width: 145px;
    }

    .stock-table-wrap {
      padding: 0 1.35rem 1.35rem;
    }

    .stock-table {
      min-width: 1380px;
    }

    .stock-table td,
    .stock-table th {
      white-space: nowrap;
    }

    .stock-table td:nth-child(7) {
      white-space: normal;
      min-width: 260px;
    }

    .stock-table .filter-row th {
      background: #fffaf5;
      padding-top: 0.7rem;
      padding-bottom: 0.7rem;
    }

    .stock-table .filter-row .form-control {
      min-width: 120px;
      border-radius: 10px;
      font-size: 0.82rem;
    }

    .row-number {
      font-weight: 700;
      color: #9a3412;
    }

    .cell-stack {
      display: flex;
      flex-direction: column;
      gap: 0.15rem;
      white-space: normal;
    }

    .cell-stack strong {
      font-size: 0.94rem;
      color: #111827;
    }

    .pill-soft {
      display: inline-flex;
      align-items: center;
      padding: 0.28rem 0.65rem;
      border-radius: 999px;
      background: #ffedd5;
      color: #9a3412;
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .location-chip {
      display: inline-flex;
      max-width: 180px;
      padding: 0.3rem 0.65rem;
      border-radius: 999px;
      background: #fff7ed;
      color: #9a3412;
      font-weight: 600;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .difference {
      font-weight: 700;
      text-align: center;
      min-width: 96px;
    }

    .difference.is-neutral {
      color: #7c6a5b;
      background: rgba(255, 247, 237, 0.7);
    }

    .status-done {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      padding: 0.34rem 0.72rem;
      border-radius: 999px;
      background: #dcfce7;
      color: #166534;
      font-weight: 700;
    }

    .btn-update-row {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      border-radius: 999px;
      padding-inline: 0.9rem;
    }

    .stock-empty {
      padding: 2.5rem 1.5rem;
      text-align: center;
      color: #7c6a5b;
    }

    .modal-record-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.9rem;
    }

    .modal-readonly {
      padding: 0.85rem 1rem;
      border-radius: 14px;
      border: 1px solid rgba(249, 115, 22, 0.14);
      background: #fffaf5;
      min-height: 100%;
    }

    .modal-readonly__label {
      display: block;
      margin-bottom: 0.35rem;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #9a3412;
    }

    .modal-readonly__value {
      color: #111827;
      word-break: break-word;
    }

    .modal-diff-box {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.55rem 0.8rem;
      border-radius: 999px;
      background: #fff7ed;
      color: #9a3412;
      font-weight: 700;
    }

    @media (max-width: 991.98px) {
      .stock-meta-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .modal-record-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 767.98px) {
      .stock-hero,
      .stock-upload,
      .stock-table-wrap {
        padding-left: 1rem;
        padding-right: 1rem;
      }

      .stock-toolbar {
        padding: 1rem 1rem 0;
      }

      .stock-meta-grid {
        grid-template-columns: 1fr;
      }

      .stock-hero h4 {
        font-size: 1.45rem;
      }

      .stock-actions,
      .stock-upload-card .input-group {
        width: 100%;
      }

      .stock-upload-card .btn,
      .stock-actions .btn {
        width: 100%;
    }
  </style>

  <!-- [ Main Content ] start -->
  <div class="pc-container">
    <div class="pc-content">
      <!-- breadcrumb -->
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10"><i class="ti ti-clipboard-data me-2"></i>PST Stock Input</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item" aria-current="page">PST Stock Input</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Stat cards -->
      <div class="stat-grid mb-3">
        <article class="stat-card">
          <span class="stat-card__label">Total Rows Today</span>
          <span class="stat-card__value"><?= number_format($totalRows) ?></span>
          <span class="stat-card__hint">Stock taking records uploaded today.</span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label">Assigned</span>
          <span class="stat-card__value"><?= number_format($assignedRows) ?></span>
          <span class="stat-card__hint">Rows with a PIC assigned.</span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label">Updated</span>
          <span class="stat-card__value"><?= number_format($updatedRows) ?></span>
          <span class="stat-card__hint">Rows with actual stock or new location filled.</span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label">Differences</span>
          <span class="stat-card__value"><?= number_format($differenceRows) ?></span>
          <span class="stat-card__hint"><?= $pendingRows ?> rows still pending update.</span>
        </article>
      </div>

      <!-- Upload card -->
      <div class="card filter-card mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap" style="gap:8px;">
            <div>
              <strong>Upload Stock Data</strong>
              <div class="text-muted" style="font-size:.82rem;">Upload an Excel file to populate today's stock taking table.</div>
            </div>
            <a href="download_stock_template.php" class="btn btn-sm btn-outline-primary">
              <i class="ti ti-download me-1"></i>Download Template
            </a>
          </div>
          <div class="upload-card">
            <form method="post" enctype="multipart/form-data">
              <div class="input-group">
                <input type="file" name="excel_file" class="form-control" accept=".xlsx,.xls" required>
                <button type="submit" name="upload" class="btn btn-primary">
                  <i class="ti ti-upload me-1"></i>Upload Excel
                </button>
              </div>
              <div class="form-text mt-2">Use this template format: Material, Material Description, Type, Storage, Stok System, Inventory Record, Date Created.</div>
            </form>
          </div>
        </div>
      </div>

      <!-- Filter bar -->
      <?php
        $filterAreas  = [];
        $filterTypes2 = [];
        $filterPics2  = [];
        foreach ($inventory_data as $_fd) {
          $a = trim((string)($_fd['area']  ?? ''));
          $t = trim((string)($_fd['type']  ?? ''));
          $p = trim((string)($_fd['pic_name'] ?? ''));
          if ($a !== '') $filterAreas[$a]  = true;
          if ($t !== '') $filterTypes2[$t] = true;
          if ($p !== '' && $p !== '-') $filterPics2[$p] = true;
        }
        ksort($filterAreas);
        ksort($filterTypes2);
        ksort($filterPics2);
      ?>
      <div class="card filter-card mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap" style="gap:8px;">
            <div>
              <strong>Filters</strong>
              <div class="text-muted" style="font-size:.82rem;">Filter rows by multiple criteria simultaneously.</div>
            </div>
            <button id="btnClearFilters" class="btn btn-sm btn-outline-secondary"><i class="ti ti-x me-1"></i>Clear Filters</button>
          </div>
          <div class="filter-bar">
            <div>
              <div class="filter-label">Search</div>
              <input id="fSearch" type="text" class="form-control search-wide" placeholder="Material / Description...">
            </div>
            <div>
              <div class="filter-label">Area</div>
              <select id="fArea" class="form-select">
                <option value="">All Areas</option>
                <?php foreach ($filterAreas as $fa => $_): ?>
                  <option value="<?= htmlspecialchars($fa) ?>"><?= htmlspecialchars($fa) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="filter-label">Type</div>
              <select id="fType" class="form-select">
                <option value="">All Types</option>
                <?php foreach ($filterTypes2 as $ft => $_): ?>
                  <option value="<?= htmlspecialchars($ft) ?>"><?= htmlspecialchars($ft) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <div class="filter-label">Update Status</div>
              <select id="fStatus" class="form-select">
                <option value="">All</option>
                <option value="updated">Updated</option>
                <option value="pending">Pending</option>
                <option value="diff">Has Difference</option>
              </select>
            </div>
            <div>
              <div class="filter-label">PST PIC</div>
              <select id="fPic" class="form-select">
                <option value="">All PICs</option>
                <?php foreach ($filterPics2 as $fp => $_): ?>
                  <option value="<?= htmlspecialchars($fp) ?>"><?= htmlspecialchars($fp) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- Main data table -->
      <div class="card section-card mb-4">
        <div class="section-head">
          <h5 class="mb-0">Stock Taking Data — Today</h5>
          <span class="section-pill" id="visibleCountBadge"><?= number_format($totalRows) ?> rows</span>
        </div>
        <div class="table-wrap">
          <table class="table table-striped table-hover mb-0 input-table" id="inputTable">
            <thead class="table-light">
              <tr>
                <th style="width:42px">#</th>
                <th>Area</th>
                <th>Type</th>
                <th>Material</th>
                <th>Description</th>
                <th>Inventory No.</th>
                <th>Batch</th>
                <th>Storage Bin</th>
                <th class="text-end">System Stock</th>
                <th class="text-end">Actual Stock</th>
                <th>New Location</th>
                <th>PST PIC</th>
                <th class="text-end">Difference</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($inventory_data)): ?>
                <tr><td colspan="15" class="text-center text-muted py-5">
                  <i class="ti ti-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                  No data for today. Upload an Excel file to get started.
                </td></tr>
              <?php else: $no = 1; foreach ($inventory_data as $item):
                $hasActual   = isset($item['new_available_stock']) && $item['new_available_stock'] !== '' && $item['new_available_stock'] !== null;
                $hasLocation = isset($item['new_storage_bin'])     && $item['new_storage_bin']     !== '' && $item['new_storage_bin']     !== null;
                $isUpdated   = $hasActual || $hasLocation;

                $diffVal   = '-';
                $diffClass = '';
                $diffData  = 'none';
                if ($hasActual) {
                  $sysS = trim((string)($item['available_stock'] ?? ''));
                  $actS = trim((string)$item['new_available_stock']);
                  if (preg_match('/^-?\d+(\.\d+)?$/', $sysS) && preg_match('/^-?\d+(\.\d+)?$/', $actS)) {
                    $d = (float)$actS - (float)$sysS;
                    $diffVal = ($d > 0 ? '+' : '') . rtrim(rtrim(number_format($d, 4, '.', ''), '0'), '.');
                    $diffClass = $d < 0 ? 'diff-minus' : ($d > 0 ? 'diff-plus' : '');
                    $diffData  = $d != 0 ? 'diff' : 'nodiff';
                  }
                }

                $statusClass = $isUpdated ? 'status-done' : 'status-none';
                $statusText  = $isUpdated ? 'Updated' : 'Pending';
                $statusData  = $isUpdated ? 'updated' : 'pending';
                if ($isUpdated && $diffData === 'diff') $statusData = 'diff';

                $picName = trim((string)($item['pic_name'] ?? ''));
              ?>
              <tr data-area="<?= htmlspecialchars(strtolower((string)($item['area'] ?? ''))) ?>"
                  data-type="<?= htmlspecialchars(strtolower((string)($item['type'] ?? ''))) ?>"
                  data-status="<?= $statusData ?>"
                  data-pic="<?= htmlspecialchars(strtolower($picName)) ?>"
                  data-search="<?= htmlspecialchars(strtolower((string)($item['material'] ?? '')) . ' ' . strtolower((string)($item['material_description'] ?? ''))) ?>">
                <td class="text-muted" style="font-size:.75rem;"><?= $no++ ?></td>
                <td><span class="status-pill status-open" style="font-size:.7rem;"><?= htmlspecialchars((string)($item['area'] ?? '-')) ?></span></td>
                <td><?= htmlspecialchars((string)($item['type'] ?? '-')) ?></td>
                <td><strong style="font-size:.82rem;"><?= htmlspecialchars((string)($item['material'] ?? '-')) ?></strong></td>
                <td class="desc-cell" title="<?= htmlspecialchars((string)($item['material_description'] ?? '-')) ?>"><?= htmlspecialchars((string)($item['material_description'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)($item['inventory_number'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)($item['batch'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)($item['storage_bin'] ?? '-')) ?></td>
                <td class="text-end"><?= htmlspecialchars((string)($item['available_stock'] ?? '-')) ?></td>
                <td class="text-end actual-stock-cell"><?= $hasActual ? htmlspecialchars((string)$item['new_available_stock']) : '<span class="text-muted">-</span>' ?></td>
                <td class="new-loc-cell"><?= $hasLocation ? htmlspecialchars((string)$item['new_storage_bin']) : '<span class="text-muted">-</span>' ?></td>
                <td>
                  <?php if ($picName !== ''): ?>
                    <span style="font-size:.8rem;"><?= htmlspecialchars($picName) ?></span>
                    <?php if (!empty($item['pic_nrp'])): ?>
                      <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars((string)$item['pic_nrp']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td class="text-end diff-cell <?= $diffClass ?>"><?= htmlspecialchars($diffVal) ?></td>
                <td><span class="status-pill <?= $statusClass ?>"><?= $statusText ?></span></td>
                <td>
                  <button class="btn btn-sm btn-outline-primary update-btn"
                          style="border-radius:999px;padding:3px 12px;font-size:.78rem;"
                          data-id="<?= (int)$item['id'] ?>"
                          data-available-stock="<?= htmlspecialchars((string)($item['available_stock'] ?? '')) ?>"
                          data-new-stock="<?= htmlspecialchars((string)($item['new_available_stock'] ?? '')) ?>"
                          data-new-location="<?= htmlspecialchars((string)($item['new_storage_bin'] ?? '')) ?>"
                          data-area="<?= htmlspecialchars((string)($item['area'] ?? '')) ?>"
                          data-material="<?= htmlspecialchars((string)($item['material'] ?? '')) ?>"
                          data-inventory-number="<?= htmlspecialchars((string)($item['inventory_number'] ?? '')) ?>"
                          data-batch="<?= htmlspecialchars((string)($item['batch'] ?? '')) ?>"
                          data-storage-bin="<?= htmlspecialchars((string)($item['storage_bin'] ?? '')) ?>"
                          data-material-description="<?= htmlspecialchars((string)($item['material_description'] ?? '')) ?>">
                    <i class="ti ti-edit me-1"></i>Edit
                  </button>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

  <!-- Update Modal -->
  <div class="modal fade" id="updateModal" tabindex="-1" aria-labelledby="updateModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header" style="background:linear-gradient(120deg,#fff7ed,#ffedd5);border-bottom:1px solid rgba(251,146,60,.22);">
          <h5 class="modal-title" id="updateModalLabel" style="color:#7c2d12;"><i class="ti ti-edit me-2"></i>Update Actual Stock</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="updateForm" method="post" action="">
            <input type="hidden" name="update" value="1">
            <input type="hidden" id="modalRecordId" name="id">
            <div class="modal-record-grid mb-3">
              <div class="modal-readonly">
                <span class="modal-readonly__label">Area</span>
                <div class="modal-readonly__value" id="modalArea"></div>
              </div>
              <div class="modal-readonly">
                <span class="modal-readonly__label">Material</span>
                <div class="modal-readonly__value" id="modalMaterial"></div>
              </div>
              <div class="modal-readonly">
                <span class="modal-readonly__label">Material Description</span>
                <div class="modal-readonly__value" id="modalPartDescription"></div>
              </div>
              <div class="modal-readonly">
                <span class="modal-readonly__label">Storage Bin</span>
                <div class="modal-readonly__value" id="modalCurrentLocation"></div>
              </div>
              <div class="modal-readonly">
                <span class="modal-readonly__label">Inventory No.</span>
                <div class="modal-readonly__value" id="modalInventoryNumber"></div>
              </div>
              <div class="modal-readonly">
                <span class="modal-readonly__label">System Stock</span>
                <div class="modal-readonly__value" id="modalSystemStock"></div>
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">Actual Stock</label>
              <input type="text" class="form-control" id="modalNewStock" name="new_stock" placeholder="Enter actual stock count">
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold">New Location <span class="text-muted fw-normal">(optional)</span></label>
              <input type="text" class="form-control" id="modalNewLocation" name="new_location" placeholder="Enter new storage bin if relocated">
            </div>
            <div class="mb-2">
              <span class="modal-diff-box">Difference: <strong id="modalDifference">—</strong></span>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" form="updateForm" id="modalSaveBtn"><i class="ti ti-device-floppy me-1"></i>Save</button>
        </div>
      </div>
    </div>
  </div>

<?php include __DIR__ . '/layouts/footer.html'; ?>
<?php include __DIR__ . '/layouts/scripts.html'; ?>
<script>
(function () {
  /* ── Filter logic ───────────────────────────────────────────────── */
  var rows        = Array.from(document.querySelectorAll('#inputTable tbody tr[data-area]'));
  var countBadge  = document.getElementById('visibleCountBadge');
  var fSearch     = document.getElementById('fSearch');
  var fArea       = document.getElementById('fArea');
  var fType       = document.getElementById('fType');
  var fStatus     = document.getElementById('fStatus');
  var fPic        = document.getElementById('fPic');
  var btnClear    = document.getElementById('btnClearFilters');

  function applyFilters() {
    var search = fSearch ? fSearch.value.trim().toLowerCase() : '';
    var area   = fArea   ? fArea.value.toLowerCase()   : '';
    var type   = fType   ? fType.value.toLowerCase()   : '';
    var status = fStatus ? fStatus.value.toLowerCase() : '';
    var pic    = fPic    ? fPic.value.toLowerCase()    : '';

    var visible = 0;
    rows.forEach(function (row) {
      var show = true;
      if (search && !(row.getAttribute('data-search') || '').includes(search)) show = false;
      if (show && area   && (row.getAttribute('data-area')   || '') !== area)   show = false;
      if (show && type   && (row.getAttribute('data-type')   || '') !== type)   show = false;
      if (show && status && (row.getAttribute('data-status') || '') !== status) show = false;
      if (show && pic    && !(row.getAttribute('data-pic')   || '').includes(pic)) show = false;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (countBadge) countBadge.textContent = visible.toLocaleString('en-US') + ' rows';
  }

  [fArea, fType, fStatus, fPic].forEach(function (el) {
    if (el) el.addEventListener('change', applyFilters);
  });
  if (fSearch) fSearch.addEventListener('input', applyFilters);
  if (btnClear) {
    btnClear.addEventListener('click', function () {
      if (fSearch) fSearch.value = '';
      if (fArea)   fArea.value   = '';
      if (fType)   fType.value   = '';
      if (fStatus) fStatus.value = '';
      if (fPic)    fPic.value    = '';
      applyFilters();
    });
  }
  applyFilters();

  /* ── Update modal ───────────────────────────────────────────────── */
  var modalAvailableStock = '';

  document.getElementById('inputTable').addEventListener('click', function (e) {
    var btn = e.target.closest('.update-btn');
    if (!btn) return;

    var id        = btn.dataset.id;
    var avail     = btn.dataset.availableStock || '';
    var newStock  = btn.dataset.newStock       || '';
    var newLoc    = btn.dataset.newLocation    || '';
    var area      = btn.dataset.area           || '-';
    var material  = btn.dataset.material       || '-';
    var desc      = btn.dataset.materialDescription || '-';
    var bin       = btn.dataset.storageBin     || '-';
    var invNo     = btn.dataset.inventoryNumber|| '-';

    modalAvailableStock = avail;

    document.getElementById('modalRecordId').value           = id;
    document.getElementById('modalArea').textContent          = area;
    document.getElementById('modalMaterial').textContent      = material;
    document.getElementById('modalPartDescription').textContent = desc;
    document.getElementById('modalCurrentLocation').textContent = bin;
    document.getElementById('modalInventoryNumber').textContent = invNo;
    document.getElementById('modalSystemStock').textContent    = avail || '-';

    var nsField = document.getElementById('modalNewStock');
    var nlField = document.getElementById('modalNewLocation');
    nsField.value    = newStock;
    nsField.disabled = false;
    nlField.value    = newLoc;
    nlField.disabled = false;
    document.getElementById('modalDifference').textContent = '—';

    var modal = bootstrap.Modal.getOrCreate(document.getElementById('updateModal'));
    modal.show();
  });

  document.getElementById('modalNewStock').addEventListener('input', function () {
    var val   = this.value.trim();
    var disp  = '—';
    if (val !== '' && /^-?\d+(\.\d+)?$/.test(val) && /^-?\d+(\.\d+)?$/.test(modalAvailableStock)) {
      var d = parseFloat(val) - parseFloat(modalAvailableStock);
      disp = (d > 0 ? '+' : '') + d;
    }
    document.getElementById('modalDifference').textContent = disp;
  });

  /* ── AJAX submit ─────────────────────────────────────────────────── */
  document.getElementById('updateForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var nsField = document.getElementById('modalNewStock');
    var nlField = document.getElementById('modalNewLocation');
    var hasStock = !nsField.disabled && nsField.value.trim() !== '';
    var hasLoc   = !nlField.disabled && nlField.value.trim() !== '';
    if (!hasStock && !hasLoc) return;

    var saveBtn = document.getElementById('modalSaveBtn');
    saveBtn.disabled = true;

    var formData = new FormData(document.getElementById('updateForm'));
    formData.append('ajax', '1');

    fetch('', { method: 'POST', body: new URLSearchParams(formData) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success) {
          var row = document.querySelector('#inputTable .update-btn[data-id="' + res.id + '"]');
          if (row) {
            var tr = row.closest('tr');
            var tds = tr.querySelectorAll('td');
            // col 9 = actual stock, 10 = new location, 12 = diff, 13 = status
            tds[9].textContent  = res.new_stock  || '-';
            tds[10].textContent = res.new_location || '-';
            var diffTd = tds[12];
            diffTd.className = 'text-end diff-cell';
            var dNum = parseFloat(res.diff);
            if (!isNaN(dNum)) {
              if (dNum < 0) diffTd.classList.add('diff-minus');
              else if (dNum > 0) diffTd.classList.add('diff-plus');
            }
            diffTd.textContent = res.diff;
            tds[13].innerHTML = '<span class="status-pill status-done">Updated</span>';
            tr.setAttribute('data-status', (isNaN(dNum) || dNum === 0) ? 'updated' : 'diff');
            // update btn dataset
            row.dataset.newStock    = res.new_stock || '';
            row.dataset.newLocation = res.new_location || '';
          }
          bootstrap.Modal.getInstance(document.getElementById('updateModal')).hide();
          if (typeof Swal !== 'undefined') Swal.fire({ icon:'success', title:'Saved', timer:1000, showConfirmButton:false });
        } else {
          var msg = (res && res.error) ? res.error : 'Failed to update';
          if (typeof Swal !== 'undefined') Swal.fire({ icon:'error', title:'Error', text: msg });
        }
      })
      .catch(function (err) {
        console.error(err);
        if (typeof Swal !== 'undefined') Swal.fire({ icon:'error', title:'Network Error', text: String(err) });
      })
      .finally(function () { saveBtn.disabled = false; });
  });

  /* ── Upload feedback ─────────────────────────────────────────────── */
  try {
    var params = new URLSearchParams(window.location.search);
    if (params.get('upload') === '1') {
      Swal.fire({ icon:'success', title:'Upload successful', timer:1500, showConfirmButton:false });
    }
    if (params.get('error') === 'no_file') {
      Swal.fire({ icon:'error', title:'No file selected', text:'Please choose an Excel file first.' });
    }
    if (params.get('error') === 'duplicate') {
      Swal.fire({ icon:'warning', title:'Duplicate data', text:'Some rows already exist and were skipped.' });
    }
    if (params.get('error') === 'zip_missing') {
      Swal.fire({
        icon:'error',
        title:'PHP ZIP extension is missing',
        text:'Please enable extension=zip in php.ini (Laragon), then restart Apache/PHP and try upload again.'
      });
    }
    if (params.get('converted') === '1' && params.get('conv')) {
      try {
        var decoded = JSON.parse(decodeURIComponent(escape(window.atob(params.get('conv')))));
        var list = decoded.slice(0,5).join('\n');
        Swal.fire({ icon:'info', title:'Storage Bin auto-converted', text: list + (decoded.length>5 ? '\n...and ' + (decoded.length-5) + ' more' : '') });
      } catch(e) {}
    }
    if (params.get('error') === 'scientific') {
      var info = params.get('info') || '';
      Swal.fire({ icon:'error', title:'Scientific notation detected', text: 'Storage Bin column has scientific notation values: ' + info + '. Format the column as Text and retry.' });
    }
  } catch(e) {}
})();
</script>
</body>
</html>
