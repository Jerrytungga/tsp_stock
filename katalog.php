<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$allowed = ['login.php', 'setup_users_table.php', 'logout.php', 'pic_stock_taking.php'];
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isLogged = !empty($_SESSION['user_id']) || !empty($_SESSION['pic_id']);
if (!in_array($script, $allowed, true) && !$isLogged) {
  header('Location: login.php');
  exit;
}

include 'db.php';

if (!$pdo) {
  die('Database connection failed.');
}

$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
  @mkdir($uploadsDir, 0755, true);
}

function katalog_flash_redirect(array $flash): void {
  $_SESSION['flash'] = $flash;
  header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'katalog.php'));
  exit;
}

function katalog_find_image_src(string $uploadsDir, string $partCode): string {
  $safePartCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode);
  foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
    $checkPath = $uploadsDir . '/part_' . $safePartCode . '.' . $ext;
    if (file_exists($checkPath)) {
      return 'uploads/part_' . $safePartCode . '.' . $ext;
    }
  }

  return 'https://via.placeholder.com/80x80?text=No+Image';
}

function katalog_find_image_file_path(string $uploadsDir, string $partCode): ?string {
  $safePartCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode);
  foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
    $checkPath = $uploadsDir . '/part_' . $safePartCode . '.' . $ext;
    if (file_exists($checkPath)) {
      return $checkPath;
    }
  }

  return null;
}

function katalog_delete_part_images(string $uploadsDir, string $partCode): void {
  $safePartCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode);
  foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
    $path = $uploadsDir . '/part_' . $safePartCode . '.' . $ext;
    if (file_exists($path)) {
      @unlink($path);
    }
  }
}

function katalog_store_uploaded_image(array $fileInfo, string $partCode, string $uploadsDir): ?string {
  $file = $fileInfo['tmp_name'] ?? '';
  $fileName = $fileInfo['name'] ?? '';
  if (!$file || !is_uploaded_file($file)) {
    return 'File gambar tidak ditemukan.';
  }

  $safeExt = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
  if (!in_array($safeExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
    return 'Format gambar harus JPG, JPEG, PNG, GIF, atau WebP.';
  }

  katalog_delete_part_images($uploadsDir, $partCode);

  $newFileName = 'part_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode) . '.' . $safeExt;
  $newFilePath = $uploadsDir . '/' . $newFileName;
  if (!move_uploaded_file($file, $newFilePath)) {
    return 'Gagal menyimpan file gambar.';
  }

  return null;
}

function katalog_rename_part_image(string $uploadsDir, string $oldPartCode, string $newPartCode): void {
  if ($oldPartCode === '' || $newPartCode === '' || $oldPartCode === $newPartCode) {
    return;
  }

  $oldPath = katalog_find_image_file_path($uploadsDir, $oldPartCode);
  if ($oldPath === null) {
    return;
  }

  $extension = strtolower((string)pathinfo($oldPath, PATHINFO_EXTENSION));
  katalog_delete_part_images($uploadsDir, $newPartCode);
  $newFileName = 'part_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $newPartCode) . '.' . $extension;
  $newPath = $uploadsDir . '/' . $newFileName;
  @rename($oldPath, $newPath);
}

function katalog_validate_lokasi(string $lokasi, array $lokasiMap, bool $requiredWhenAvailable = false): ?string {
  if ($requiredWhenAvailable && !empty($lokasiMap) && $lokasi === '') {
    return 'Lokasi wajib dipilih dari database lokasi.';
  }

  if ($lokasi !== '' && !isset($lokasiMap[$lokasi])) {
    return 'Lokasi yang dipilih tidak valid.';
  }

  return null;
}

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mn_ptfi VARCHAR(100) DEFAULT NULL,
    kode_part VARCHAR(100) DEFAULT NULL,
    nama_part VARCHAR(255) DEFAULT NULL,
    kategori VARCHAR(100) DEFAULT NULL,
    min_qty INT DEFAULT NULL,
    max_qty INT DEFAULT NULL,
    berat VARCHAR(100) DEFAULT NULL,
    jenis_part VARCHAR(100) DEFAULT NULL,
    jumlah_lokasi INT DEFAULT NULL,
    dimensi VARCHAR(100) DEFAULT NULL,
    lokasi VARCHAR(100) DEFAULT NULL,
    harga VARCHAR(100) DEFAULT NULL,
    stok INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
  die('Gagal memastikan tabel inventory tersedia.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$lokasiOptions = [];
try {
  $stmtLokasi = $pdo->query("SELECT kode_lokasi, nama_lokasi, area, tipe FROM lokasi ORDER BY tipe ASC, area ASC, kode_lokasi ASC");
  $lokasiOptions = $stmtLokasi->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $lokasiOptions = [];
}

$lokasiMap = [];
$lokasiMetaMap = [];
$lokasiAreaOptions = [];
$lokasiTipeOptions = [];
foreach ($lokasiOptions as $lokasiRow) {
  $kodeLokasi = (string)$lokasiRow['kode_lokasi'];
  $namaLokasi = (string)$lokasiRow['nama_lokasi'];
  $areaLokasi = trim((string)($lokasiRow['area'] ?? ''));
  $tipeLokasi = trim((string)($lokasiRow['tipe'] ?? ''));
  $lokasiMap[$kodeLokasi] = $namaLokasi;
  $lokasiMetaMap[$kodeLokasi] = [
    'kode_lokasi' => $kodeLokasi,
    'nama_lokasi' => $namaLokasi,
    'area' => $areaLokasi,
    'tipe' => $tipeLokasi,
  ];
  if ($areaLokasi !== '') {
    $lokasiAreaOptions[$areaLokasi] = $areaLokasi;
  }
  if ($tipeLokasi !== '') {
    $lokasiTipeOptions[$tipeLokasi] = $tipeLokasi;
  }
}

ksort($lokasiAreaOptions);
ksort($lokasiTipeOptions);

if (isset($_POST['upload_image'])) {
  header('Content-Type: application/json');

  $partCode = trim((string)($_POST['part_code'] ?? ''));
  if ($partCode === '' || !isset($_FILES['image_file'])) {
    echo json_encode(['success' => false, 'message' => 'Data upload tidak lengkap']);
    exit;
  }

  $file = $_FILES['image_file']['tmp_name'] ?? '';
  $fileName = $_FILES['image_file']['name'] ?? '';
  if (!$file || !is_uploaded_file($file)) {
    echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
    exit;
  }

  $safeExt = strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION));
  if (!in_array($safeExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
    echo json_encode(['success' => false, 'message' => 'Format gambar tidak didukung']);
    exit;
  }

  $newFileName = 'part_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode) . '.' . $safeExt;
  $newFilePath = $uploadsDir . '/' . $newFileName;

  if (!move_uploaded_file($file, $newFilePath)) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan gambar']);
    exit;
  }

  echo json_encode([
    'success' => true,
    'message' => 'Gambar berhasil diupload',
    'image_path' => 'uploads/' . $newFileName,
  ]);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
  $mnPtfi = trim((string)($_POST['mn_ptfi'] ?? ''));
  $kodePart = trim((string)($_POST['kode_part'] ?? ''));
  $namaPart = trim((string)($_POST['nama_part'] ?? ''));
  $kategori = trim((string)($_POST['kategori'] ?? ''));
  $minQty = trim((string)($_POST['min_qty'] ?? ''));
  $maxQty = trim((string)($_POST['max_qty'] ?? ''));
  $berat = trim((string)($_POST['berat'] ?? ''));
  $jenisPart = trim((string)($_POST['jenis_part'] ?? ''));
  $jumlahLokasi = trim((string)($_POST['jumlah_lokasi'] ?? ''));
  $dimensi = trim((string)($_POST['dimensi'] ?? ''));
  $lokasi = trim((string)($_POST['lokasi'] ?? ''));
  $harga = trim((string)($_POST['harga'] ?? ''));
  $stok = trim((string)($_POST['stok'] ?? ''));

  if ($mnPtfi === '' || $kodePart === '' || $namaPart === '') {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'MN PTFI, kode part, dan nama part wajib diisi.']);
  }

  $lokasiError = katalog_validate_lokasi($lokasi, $lokasiMap, true);
  if ($lokasiError !== null) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => $lokasiError]);
  }

  $jumlahLokasiValue = $jumlahLokasi === '' ? ($lokasi !== '' ? 1 : null) : (int)$jumlahLokasi;
  $minQtyValue = $minQty === '' ? null : (int)$minQty;
  $maxQtyValue = $maxQty === '' ? null : (int)$maxQty;
  $stokValue = $stok === '' ? null : (int)$stok;

  try {
    $checkStmt = $pdo->prepare('SELECT id FROM inventory WHERE kode_part = ? LIMIT 1');
    $checkStmt->execute([$kodePart]);
    if ($checkStmt->fetchColumn()) {
      katalog_flash_redirect(['type' => 'warning', 'msg' => 'Kode part sudah ada di katalog.']);
    }

    $insertStmt = $pdo->prepare('INSERT INTO inventory (mn_ptfi, kode_part, nama_part, kategori, min_qty, max_qty, berat, jenis_part, jumlah_lokasi, dimensi, lokasi, harga, stok) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $insertStmt->execute([
      $mnPtfi,
      $kodePart,
      $namaPart,
      $kategori !== '' ? $kategori : null,
      $minQtyValue,
      $maxQtyValue,
      $berat !== '' ? $berat : null,
      $jenisPart !== '' ? $jenisPart : null,
      $jumlahLokasiValue,
      $dimensi !== '' ? $dimensi : null,
      $lokasi !== '' ? $lokasi : null,
      $harga !== '' ? $harga : null,
      $stokValue,
    ]);

    if (!empty($_FILES['item_image']['tmp_name'])) {
      $imageError = katalog_store_uploaded_image($_FILES['item_image'], $kodePart, $uploadsDir);
      if ($imageError !== null) {
        katalog_flash_redirect(['type' => 'warning', 'msg' => 'Barang berhasil ditambahkan, tetapi gambar gagal diupload. ' . $imageError]);
      }
    }

    katalog_flash_redirect(['type' => 'success', 'msg' => 'Barang berhasil ditambahkan ke katalog.']);
  } catch (Exception $e) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'Gagal menambahkan barang.']);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_item') {
  $id = (int)($_POST['id'] ?? 0);
  $mnPtfi = trim((string)($_POST['mn_ptfi'] ?? ''));
  $kodePart = trim((string)($_POST['kode_part'] ?? ''));
  $namaPart = trim((string)($_POST['nama_part'] ?? ''));
  $kategori = trim((string)($_POST['kategori'] ?? ''));
  $minQty = trim((string)($_POST['min_qty'] ?? ''));
  $maxQty = trim((string)($_POST['max_qty'] ?? ''));
  $berat = trim((string)($_POST['berat'] ?? ''));
  $jenisPart = trim((string)($_POST['jenis_part'] ?? ''));
  $jumlahLokasi = trim((string)($_POST['jumlah_lokasi'] ?? ''));
  $dimensi = trim((string)($_POST['dimensi'] ?? ''));
  $lokasi = trim((string)($_POST['lokasi'] ?? ''));
  $harga = trim((string)($_POST['harga'] ?? ''));
  $stok = trim((string)($_POST['stok'] ?? ''));

  if ($id <= 0) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'ID barang tidak valid.']);
  }

  if ($mnPtfi === '' || $kodePart === '' || $namaPart === '') {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'MN PTFI, kode part, dan nama part wajib diisi.']);
  }

  $lokasiError = katalog_validate_lokasi($lokasi, $lokasiMap, true);
  if ($lokasiError !== null) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => $lokasiError]);
  }

  $jumlahLokasiValue = $jumlahLokasi === '' ? ($lokasi !== '' ? 1 : null) : (int)$jumlahLokasi;
  $minQtyValue = $minQty === '' ? null : (int)$minQty;
  $maxQtyValue = $maxQty === '' ? null : (int)$maxQty;
  $stokValue = $stok === '' ? null : (int)$stok;

  try {
    $currentStmt = $pdo->prepare('SELECT kode_part FROM inventory WHERE id = ? LIMIT 1');
    $currentStmt->execute([$id]);
    $currentRow = $currentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentRow) {
      katalog_flash_redirect(['type' => 'danger', 'msg' => 'Barang tidak ditemukan.']);
    }

    $oldKodePart = trim((string)($currentRow['kode_part'] ?? ''));

    $checkStmt = $pdo->prepare('SELECT id FROM inventory WHERE kode_part = ? AND id != ? LIMIT 1');
    $checkStmt->execute([$kodePart, $id]);
    if ($checkStmt->fetchColumn()) {
      katalog_flash_redirect(['type' => 'warning', 'msg' => 'Kode part sudah dipakai barang lain di katalog.']);
    }

    $updateStmt = $pdo->prepare('UPDATE inventory SET mn_ptfi = ?, kode_part = ?, nama_part = ?, kategori = ?, min_qty = ?, max_qty = ?, berat = ?, jenis_part = ?, jumlah_lokasi = ?, dimensi = ?, lokasi = ?, harga = ?, stok = ? WHERE id = ?');
    $updateStmt->execute([
      $mnPtfi,
      $kodePart,
      $namaPart,
      $kategori !== '' ? $kategori : null,
      $minQtyValue,
      $maxQtyValue,
      $berat !== '' ? $berat : null,
      $jenisPart !== '' ? $jenisPart : null,
      $jumlahLokasiValue,
      $dimensi !== '' ? $dimensi : null,
      $lokasi !== '' ? $lokasi : null,
      $harga !== '' ? $harga : null,
      $stokValue,
      $id,
    ]);

    if (!empty($_FILES['edit_item_image']['tmp_name'])) {
      $imageError = katalog_store_uploaded_image($_FILES['edit_item_image'], $kodePart, $uploadsDir);
      if ($oldKodePart !== '' && $oldKodePart !== $kodePart) {
        katalog_delete_part_images($uploadsDir, $oldKodePart);
      }
      if ($imageError !== null) {
        katalog_flash_redirect(['type' => 'warning', 'msg' => 'Data barang berhasil diperbarui, tetapi gambar gagal diupload. ' . $imageError]);
      }
    } elseif ($oldKodePart !== '' && $oldKodePart !== $kodePart) {
      katalog_rename_part_image($uploadsDir, $oldKodePart, $kodePart);
    }

    katalog_flash_redirect(['type' => 'success', 'msg' => 'Barang berhasil diperbarui.']);
  } catch (Exception $e) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'Gagal memperbarui barang.']);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id <= 0) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'ID barang tidak valid.']);
  }

  try {
    $stmt = $pdo->prepare('DELETE FROM inventory WHERE id = ?');
    $stmt->execute([$id]);
    katalog_flash_redirect(['type' => 'warning', 'msg' => 'Barang berhasil dihapus dari katalog.']);
  } catch (Exception $e) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'Gagal menghapus barang.']);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload'])) {
  $file = $_FILES['excel_file']['tmp_name'] ?? '';
  if (!$file) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'Silakan pilih file Excel terlebih dahulu.']);
  }

  try {
    require 'vendor/autoload.php';

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $worksheet = $spreadsheet->getActiveSheet();
    $data = $worksheet->toArray();
    array_shift($data);

    $insertStmt = $pdo->prepare('INSERT INTO inventory (mn_ptfi, kode_part, nama_part, kategori, min_qty, max_qty, berat, jenis_part, jumlah_lokasi, dimensi, lokasi, harga, stok) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $added = 0;
    $invalidLokasi = [];
    foreach ($data as $row) {
      $dataCells = array_slice($row, 0, 13);
      $mnPtfi = trim((string)($dataCells[0] ?? ''));
      $kodePart = trim((string)($dataCells[1] ?? ''));
      $namaPart = trim((string)($dataCells[2] ?? ''));
      if ($mnPtfi === '' && $kodePart === '' && $namaPart === '') {
        continue;
      }

      $lokasiValue = trim((string)($dataCells[10] ?? ''));
      $lokasiError = katalog_validate_lokasi($lokasiValue, $lokasiMap, false);
      if ($lokasiError !== null) {
        $invalidLokasi[] = $kodePart !== '' ? $kodePart . ' -> ' . $lokasiValue : $lokasiValue;
        continue;
      }

      $insertStmt->execute([
        $mnPtfi !== '' ? $mnPtfi : null,
        $kodePart !== '' ? $kodePart : null,
        $namaPart !== '' ? $namaPart : null,
        trim((string)($dataCells[3] ?? '')) ?: null,
        trim((string)($dataCells[4] ?? '')) !== '' ? (int)$dataCells[4] : null,
        trim((string)($dataCells[5] ?? '')) !== '' ? (int)$dataCells[5] : null,
        trim((string)($dataCells[6] ?? '')) ?: null,
        trim((string)($dataCells[7] ?? '')) ?: null,
        trim((string)($dataCells[8] ?? '')) !== '' ? (int)$dataCells[8] : null,
        trim((string)($dataCells[9] ?? '')) ?: null,
        $lokasiValue !== '' ? $lokasiValue : null,
        trim((string)($dataCells[11] ?? '')) ?: null,
        trim((string)($dataCells[12] ?? '')) !== '' ? (int)$dataCells[12] : null,
      ]);
      $added++;
    }

    if (!empty($invalidLokasi)) {
      $preview = implode(', ', array_slice($invalidLokasi, 0, 5));
      $extra = count($invalidLokasi) > 5 ? ' dan lainnya.' : '.';
      $baseMsg = $added > 0
        ? "Upload parsial selesai. $added baris masuk, beberapa baris dilewati karena lokasi tidak ada di database lokasi: $preview$extra"
        : "Upload dibatalkan. Lokasi pada file Excel tidak valid terhadap database lokasi: $preview$extra";
      katalog_flash_redirect(['type' => $added > 0 ? 'warning' : 'danger', 'msg' => $baseMsg]);
    }

    katalog_flash_redirect(['type' => 'success', 'msg' => "Upload selesai. $added baris berhasil dimasukkan ke katalog."]);
  } catch (Exception $e) {
    katalog_flash_redirect(['type' => 'danger', 'msg' => 'Gagal memproses file Excel.']);
  }
}

$inventoryRows = [];
try {
  $stmt = $pdo->query('SELECT * FROM inventory ORDER BY id DESC');
  $inventoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $inventoryRows = [];
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
    :root {
      --sheet-bg: #fff8f1;
      --sheet-panel: #ffffff;
      --sheet-grid: #f1d2b8;
      --sheet-grid-strong: #e7b88d;
      --sheet-header: #fff1e2;
      --sheet-toolbar: #ffe4c7;
      --sheet-text: #3f2a18;
      --sheet-muted: #8a6648;
      --sheet-accent: #dd6b20;
      --sheet-accent-soft: #ffe7d1;
      --sheet-success: #b45309;
      --sheet-warning: #c2410c;
    }

    .sheet-card {
      border: 0;
      overflow: hidden;
      border-radius: 18px;
      box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
      background: linear-gradient(180deg, #fff 0%, #fff7ef 100%);
    }

    .sheet-card .card-header {
      background: linear-gradient(135deg, #ea580c, #fb923c) !important;
      color: #fff;
      border-bottom: 0;
      padding: 18px 24px;
    }

    .sheet-card .card-header h5 {
      color: #fff;
      margin: 0;
      font-size: 1.05rem;
      letter-spacing: 0.01em;
    }

    .sheet-card .card-body {
      padding: 24px;
      background:
        linear-gradient(180deg, rgba(255,255,255,0.96), rgba(255,247,239,0.98)),
        repeating-linear-gradient(0deg, transparent 0, transparent 35px, rgba(251, 146, 60, 0.05) 35px, rgba(251, 146, 60, 0.05) 36px),
        repeating-linear-gradient(90deg, transparent 0, transparent 119px, rgba(251, 146, 60, 0.05) 119px, rgba(251, 146, 60, 0.05) 120px);
    }

    .sheet-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 18px;
    }

    .sheet-actions .input-group {
      min-width: 320px;
      max-width: 520px;
    }

    .sheet-downloads {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    .spreadsheet-shell {
      border: 1px solid var(--sheet-grid-strong);
      border-radius: 16px;
      overflow: hidden;
      background: var(--sheet-panel);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.6);
    }

    .spreadsheet-toolbar {
      display: grid;
      grid-template-columns: 88px minmax(220px, 1fr) auto;
      gap: 12px;
      align-items: center;
      padding: 12px 14px;
      background: linear-gradient(180deg, #fffaf5 0%, var(--sheet-toolbar) 100%);
      border-bottom: 1px solid var(--sheet-grid-strong);
    }

    .name-box,
    .formula-bar {
      height: 38px;
      border: 1px solid var(--sheet-grid-strong);
      border-radius: 10px;
      background: #fff;
      color: var(--sheet-text);
      font-size: 0.85rem;
    }

    .name-box {
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      letter-spacing: 0.06em;
    }

    .formula-wrap {
      display: grid;
      grid-template-columns: 42px minmax(0, 1fr);
      align-items: center;
      border: 1px solid var(--sheet-grid-strong);
      border-radius: 10px;
      background: #fff;
      overflow: hidden;
    }

    .formula-label {
      display: flex;
      align-items: center;
      justify-content: center;
      height: 38px;
      color: var(--sheet-muted);
      background: #fff7ef;
      border-right: 1px solid var(--sheet-grid);
      font-weight: 700;
      font-family: Georgia, serif;
    }

    .formula-bar {
      border: 0;
      border-radius: 0;
      width: 100%;
      padding: 0 12px;
      outline: none;
      box-shadow: none;
    }

    .sheet-status {
      display: flex;
      align-items: center;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    .sheet-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.03em;
      border: 1px solid transparent;
    }

    .sheet-pill.ready {
      color: #9a3412;
      background: #ffedd5;
      border-color: #fdba74;
    }

    .sheet-pill.filter {
      color: var(--sheet-accent);
      background: var(--sheet-accent-soft);
      border-color: #fdba74;
    }

    .spreadsheet-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--sheet-grid);
      background: #fff8f1;
      color: var(--sheet-muted);
      font-size: 0.82rem;
    }

    .spreadsheet-table-wrap {
      max-height: 70vh;
      overflow: auto;
      background: var(--sheet-bg);
    }

    #katalog-table {
      margin: 0 !important;
      min-width: 1500px;
      border-collapse: separate;
      border-spacing: 0;
      color: var(--sheet-text);
      background: #fff;
    }

    #katalog-table thead th,
    #katalog-table tbody td {
      border-right: 1px solid var(--sheet-grid);
      border-bottom: 1px solid var(--sheet-grid);
      vertical-align: middle;
    }

    #katalog-table thead tr:first-child th {
      position: sticky;
      top: 0;
      z-index: 4;
      background: var(--sheet-header);
      color: #7c2d12;
      text-transform: uppercase;
      font-size: 0.74rem;
      letter-spacing: 0.05em;
      font-weight: 700;
      white-space: nowrap;
      padding: 12px 10px;
      box-shadow: inset 0 -1px 0 var(--sheet-grid-strong);
    }

    #katalog-table thead tr:nth-child(2) th {
      position: sticky;
      top: 45px;
      z-index: 3;
      background: #fffdf9;
      padding: 8px;
      box-shadow: inset 0 -1px 0 var(--sheet-grid);
    }

    #katalog-table thead th:first-child,
    #katalog-table tbody td:first-child {
      position: sticky;
      left: 0;
      z-index: 2;
      background: #fff8f1;
      box-shadow: inset -1px 0 0 var(--sheet-grid-strong);
    }

    #katalog-table thead tr:first-child th:first-child {
      z-index: 6;
      background: linear-gradient(180deg, #ffe9d5 0%, #ffd6b0 100%);
    }

    #katalog-table thead tr:nth-child(2) th:first-child {
      z-index: 5;
      background: #fff1e2;
    }

    #katalog-table tbody td {
      padding: 10px 12px;
      font-size: 0.85rem;
      background: #fff;
      white-space: nowrap;
      max-width: 260px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    #katalog-table tbody tr:nth-child(even) td {
      background: #fffaf5;
    }

    #katalog-table tbody tr:hover td {
      background: #fff1e2;
    }

    #katalog-table tbody tr:hover td:first-child {
      background: #ffd9b3;
    }

    #katalog-table td.sheet-cell-active {
      background: #ffe4c7 !important;
      box-shadow: inset 0 0 0 2px #f97316;
    }

    #katalog-table .column_search {
      min-width: 110px;
      border: 1px solid #efc29d;
      border-radius: 8px;
      background: #fff;
      font-size: 0.76rem;
      padding: 7px 9px;
    }

    #katalog-table .column_search:focus,
    .formula-bar:focus,
    .form-select:focus,
    .form-control:focus {
      border-color: #fb923c;
      box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.16);
    }

    .part-thumbnail {
      width: 68px;
      height: 68px;
      border-radius: 10px;
      border: 1px solid #cbd5e1;
      background: #fff;
      box-shadow: 0 6px 16px rgba(15, 23, 42, 0.08);
    }

    .btn-primary,
    .btn.btn-primary {
      background: linear-gradient(135deg, #ea580c, #fb923c);
      border-color: #ea580c;
    }

    .btn-primary:hover,
    .btn.btn-primary:hover {
      background: linear-gradient(135deg, #c2410c, #f97316);
      border-color: #c2410c;
    }

    .btn-outline-primary,
    .btn.btn-outline-primary {
      color: #c2410c;
      border-color: #f97316;
    }

    .btn-outline-primary:hover,
    .btn.btn-outline-primary:hover {
      color: #fff;
      background: #f97316;
      border-color: #f97316;
    }

    .sheet-actions-cell {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .sheet-actions-cell .btn {
      border-radius: 8px;
      font-size: 0.76rem;
      padding: 6px 10px;
      white-space: nowrap;
    }

    .toolbar-form {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
    }

    .modal-sheet .modal-content {
      border: 0;
      border-radius: 18px;
      overflow: hidden;
    }

    .modal-sheet .modal-header {
      background: linear-gradient(135deg, #ea580c, #fb923c);
      color: #fff;
      border-bottom: 0;
    }

    .modal-sheet .modal-title,
    .modal-sheet .btn-close {
      color: #fff;
    }

    .sheet-actions-cell form {
      margin: 0;
    }

    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate,
    .dataTables_wrapper .dataTables_length {
      display: none !important;
    }

    @media (max-width: 991.98px) {
      .spreadsheet-toolbar {
        grid-template-columns: 1fr;
      }

      .sheet-status {
        justify-content: flex-start;
      }

      .sheet-actions {
        flex-direction: column;
        align-items: stretch;
      }

      .sheet-actions .input-group,
      .toolbar-form {
        min-width: 0;
        max-width: none;
        width: 100%;
      }

      .spreadsheet-meta {
        flex-direction: column;
        align-items: flex-start;
      }

      .spreadsheet-table-wrap {
        max-height: 62vh;
      }
    }
  </style>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header">
        <div class="page-block">
          <div class="row align-items-center">
            <div class="col-md-12">
              <div class="page-header-title">
                <h5 class="m-b-10">Katalog Part</h5>
              </div>
              <ul class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                <li class="breadcrumb-item" aria-current="page">Katalog Part</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <div class="col-sm-12">
          <div class="card sheet-card">
            <div class="card-header">
              <h5>Daftar Katalog Part</h5>
            </div>
            <div class="card-body">
              <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
                  <?php echo htmlspecialchars($flash['msg']); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <div class="sheet-actions">
                <div class="toolbar-form">
                  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addItemModal">Tambah Barang</button>
                  <form method="post" enctype="multipart/form-data">
                    <div class="input-group input-group-sm">
                      <input type="file" name="excel_file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                      <button type="submit" name="upload" class="btn btn-success btn-sm">Unggah Excel</button>
                    </div>
                  </form>
                </div>
                <div class="sheet-downloads">
                  <a href="download_template.php" class="btn btn-info btn-sm">Unduh Template Excel</a>
                  <a href="export_excel.php" class="btn btn-warning btn-sm">Unduh Data Excel</a>
                </div>
              </div>

              <div class="spreadsheet-shell">
                <div class="spreadsheet-toolbar">
                  <div class="name-box" id="active-cell-label">A1</div>
                  <div class="formula-wrap">
                    <div class="formula-label">fx</div>
                    <input type="text" id="sheet-global-search" class="formula-bar" placeholder="Cari cepat seluruh sheet: kode part, nama, lokasi, harga, stok...">
                  </div>
                  <div class="sheet-status">
                    <span class="sheet-pill ready">Sheet View</span>
                    <span class="sheet-pill filter" id="sheet-filter-status">Filter off</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="sheet-clear-filters">Reset Filter</button>
                  </div>
                </div>
                <div class="spreadsheet-meta">
                  <div>Tambah barang manual sekarang memakai database inventory, dan pilihan lokasi mengambil data dari database lokasi.</div>
                  <div id="sheet-row-count">0 baris tampil</div>
                </div>
                <div class="spreadsheet-table-wrap">
                  <table id="katalog-table" class="table table-bordered align-middle">
                    <thead>
                      <tr>
                        <th>No</th>
                        <th>MN PTFI</th>
                        <th>Kode Part</th>
                        <th>Nama Part</th>
                        <th>Kategori</th>
                        <th>Min qtty</th>
                        <th>Max qtty</th>
                        <th>Berat</th>
                        <th>jenis Part</th>
                        <th>Jumlah lokasi</th>
                        <th>Dimensi</th>
                        <th>Lokasi</th>
                        <th>Harga</th>
                        <th>Stok (pc)</th>
                        <th>Gambar</th>
                        <th>Aksi</th>
                      </tr>
                      <tr>
                        <th></th>
                        <th><input type="text" placeholder="Cari MN PTFI" class="form-control form-control-sm column_search" data-column="1"></th>
                        <th><input type="text" placeholder="Cari Kode Part" class="form-control form-control-sm column_search" data-column="2"></th>
                        <th><input type="text" placeholder="Cari Nama Part" class="form-control form-control-sm column_search" data-column="3"></th>
                        <th><input type="text" placeholder="Search Kategori" class="form-control form-control-sm column_search" data-column="4"></th>
                        <th><input type="text" placeholder="Search Min qtty" class="form-control form-control-sm column_search" data-column="5"></th>
                        <th><input type="text" placeholder="Search Max qtty" class="form-control form-control-sm column_search" data-column="6"></th>
                        <th><input type="text" placeholder="Search Berat" class="form-control form-control-sm column_search" data-column="7"></th>
                        <th><input type="text" placeholder="Search jenis Part" class="form-control form-control-sm column_search" data-column="8"></th>
                        <th><input type="text" placeholder="Search Jumlah lokasi" class="form-control form-control-sm column_search" data-column="9"></th>
                        <th><input type="text" placeholder="Search Dimensi" class="form-control form-control-sm column_search" data-column="10"></th>
                        <th><input type="text" placeholder="Search Lokasi" class="form-control form-control-sm column_search" data-column="11"></th>
                        <th><input type="text" placeholder="Search Harga" class="form-control form-control-sm column_search" data-column="12"></th>
                        <th><input type="text" placeholder="Search Stok" class="form-control form-control-sm column_search" data-column="13"></th>
                        <th><input type="text" placeholder="Search Gambar" class="form-control form-control-sm column_search" data-column="14"></th>
                        <th></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($inventoryRows)): ?>
                      <?php foreach ($inventoryRows as $index => $row): ?>
                        <?php
                          $partCode = trim((string)($row['kode_part'] ?? ''));
                          $imgSrc = katalog_find_image_src($uploadsDir, $partCode);
                          $lokasiMeta = $lokasiMetaMap[(string)($row['lokasi'] ?? '')] ?? ['area' => '', 'tipe' => ''];
                        ?>
                        <tr>
                          <td><?php echo $index + 1; ?></td>
                          <td><?php echo htmlspecialchars((string)($row['mn_ptfi'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars($partCode); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['nama_part'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['kategori'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['min_qty'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['max_qty'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['berat'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['jenis_part'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['jumlah_lokasi'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['dimensi'] ?? '')); ?></td>
                          <td title="<?php echo htmlspecialchars((string)($lokasiMap[(string)($row['lokasi'] ?? '')] ?? '')); ?>"><?php echo htmlspecialchars((string)($row['lokasi'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['harga'] ?? '')); ?></td>
                          <td><?php echo htmlspecialchars((string)($row['stok'] ?? '')); ?></td>
                          <td>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="Gambar part" class="part-thumbnail" data-part-code="<?php echo htmlspecialchars($partCode); ?>" style="max-width:80px; max-height:80px; object-fit:cover; cursor:pointer;">
                          </td>
                          <td>
                            <div class="sheet-actions-cell">
                              <button
                                type="button"
                                class="btn btn-sm btn-outline-primary edit-item-btn"
                                data-id="<?php echo (int)$row['id']; ?>"
                                data-mn-ptfi="<?php echo htmlspecialchars((string)($row['mn_ptfi'] ?? ''), ENT_QUOTES); ?>"
                                data-kode-part="<?php echo htmlspecialchars($partCode, ENT_QUOTES); ?>"
                                data-nama-part="<?php echo htmlspecialchars((string)($row['nama_part'] ?? ''), ENT_QUOTES); ?>"
                                data-kategori="<?php echo htmlspecialchars((string)($row['kategori'] ?? ''), ENT_QUOTES); ?>"
                                data-min-qty="<?php echo htmlspecialchars((string)($row['min_qty'] ?? ''), ENT_QUOTES); ?>"
                                data-max-qty="<?php echo htmlspecialchars((string)($row['max_qty'] ?? ''), ENT_QUOTES); ?>"
                                data-berat="<?php echo htmlspecialchars((string)($row['berat'] ?? ''), ENT_QUOTES); ?>"
                                data-jenis-part="<?php echo htmlspecialchars((string)($row['jenis_part'] ?? ''), ENT_QUOTES); ?>"
                                data-jumlah-lokasi="<?php echo htmlspecialchars((string)($row['jumlah_lokasi'] ?? ''), ENT_QUOTES); ?>"
                                data-dimensi="<?php echo htmlspecialchars((string)($row['dimensi'] ?? ''), ENT_QUOTES); ?>"
                                data-lokasi="<?php echo htmlspecialchars((string)($row['lokasi'] ?? ''), ENT_QUOTES); ?>"
                                data-lokasi-area="<?php echo htmlspecialchars((string)($lokasiMeta['area'] ?? ''), ENT_QUOTES); ?>"
                                data-lokasi-tipe="<?php echo htmlspecialchars((string)($lokasiMeta['tipe'] ?? ''), ENT_QUOTES); ?>"
                                data-harga="<?php echo htmlspecialchars((string)($row['harga'] ?? ''), ENT_QUOTES); ?>"
                                data-stok="<?php echo htmlspecialchars((string)($row['stok'] ?? ''), ENT_QUOTES); ?>"
                                data-image-src="<?php echo htmlspecialchars($imgSrc, ENT_QUOTES); ?>"
                              >Edit</button>
                              <button type="button" class="btn btn-sm btn-primary image-btn" data-part-code="<?php echo htmlspecialchars($partCode); ?>">Gambar</button>
                              <form method="post" onsubmit="return confirm('Hapus barang ini dari katalog?');">
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="16" class="text-center text-muted">Belum ada data. Tambah barang manual atau unggah file Excel untuk mengisi katalog.</td>
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
  </div>

  <div class="modal fade modal-sheet" id="addItemModal" tabindex="-1" aria-labelledby="addItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="addItemModalLabel">Tambah Barang</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_item">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">MN PTFI</label>
                <input type="text" name="mn_ptfi" class="form-control" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Kode Part</label>
                <input type="text" name="kode_part" class="form-control" required>
              </div>
              <div class="col-md-12">
                <label class="form-label">Nama Part</label>
                <input type="text" name="nama_part" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Kategori</label>
                <input type="text" name="kategori" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Min qtty</label>
                <input type="number" name="min_qty" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Max qtty</label>
                <input type="number" name="max_qty" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Berat</label>
                <input type="text" name="berat" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Jenis Part</label>
                <input type="text" name="jenis_part" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Jumlah Lokasi</label>
                <input type="number" name="jumlah_lokasi" class="form-control" min="1" value="1">
              </div>
              <div class="col-md-4">
                <label class="form-label">Dimensi</label>
                <input type="text" name="dimensi" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Harga</label>
                <input type="text" name="harga" class="form-control" placeholder="Contoh: Rp. 20.000">
              </div>
              <div class="col-md-4">
                <label class="form-label">Stok (pc)</label>
                <input type="number" name="stok" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Tipe Lokasi</label>
                <select id="addTipeLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value="">Pilih TM1 / TM2</option>
                  <?php foreach ($lokasiTipeOptions as $tipeLokasi): ?>
                    <option value="<?php echo htmlspecialchars($tipeLokasi); ?>"><?php echo htmlspecialchars($tipeLokasi); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Area</label>
                <select id="addAreaLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value="">Pilih 001 / 002 / ...</option>
                  <?php foreach ($lokasiAreaOptions as $areaLokasi): ?>
                    <option value="<?php echo htmlspecialchars($areaLokasi); ?>"><?php echo htmlspecialchars($areaLokasi); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Lokasi</label>
                <select name="lokasi" id="addLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value=""><?php echo empty($lokasiOptions) ? 'Belum ada data lokasi. Tambahkan di Database Lokasi.' : 'Pilih tipe dan area dulu'; ?></option>
                </select>
                <?php if (empty($lokasiOptions)): ?>
                  <small class="text-muted">Belum ada lokasi tersimpan. Isi dulu melalui menu Database Lokasi.</small>
                <?php else: ?>
                  <small class="text-muted">Pilih dulu TM1/TM2 dan area seperti 001 atau 002, baru lokasi muncul.</small>
                <?php endif; ?>
              </div>
              <div class="col-md-12">
                <label class="form-label">Gambar Part</label>
                <input type="file" name="item_image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <small class="text-muted">Opsional. Jika diisi saat simpan barang, gambar langsung tersimpan ke part ini.</small>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Barang</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade modal-sheet" id="editItemModal" tabindex="-1" aria-labelledby="editItemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editItemModalLabel">Edit Barang</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <form method="post" enctype="multipart/form-data">
          <div class="modal-body">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="id" id="editItemId">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">MN PTFI</label>
                <input type="text" name="mn_ptfi" id="editMnPtfi" class="form-control" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Kode Part</label>
                <input type="text" name="kode_part" id="editKodePart" class="form-control" required>
              </div>
              <div class="col-md-12">
                <label class="form-label">Nama Part</label>
                <input type="text" name="nama_part" id="editNamaPart" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label">Kategori</label>
                <input type="text" name="kategori" id="editKategori" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Min qtty</label>
                <input type="number" name="min_qty" id="editMinQty" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Max qtty</label>
                <input type="number" name="max_qty" id="editMaxQty" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Berat</label>
                <input type="text" name="berat" id="editBerat" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Jenis Part</label>
                <input type="text" name="jenis_part" id="editJenisPart" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Jumlah Lokasi</label>
                <input type="number" name="jumlah_lokasi" id="editJumlahLokasi" class="form-control" min="1">
              </div>
              <div class="col-md-4">
                <label class="form-label">Dimensi</label>
                <input type="text" name="dimensi" id="editDimensi" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Harga</label>
                <input type="text" name="harga" id="editHarga" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label">Stok (pc)</label>
                <input type="number" name="stok" id="editStok" class="form-control" min="0">
              </div>
              <div class="col-md-4">
                <label class="form-label">Tipe Lokasi</label>
                <select id="editTipeLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value="">Pilih TM1 / TM2</option>
                  <?php foreach ($lokasiTipeOptions as $tipeLokasi): ?>
                    <option value="<?php echo htmlspecialchars($tipeLokasi); ?>"><?php echo htmlspecialchars($tipeLokasi); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Area</label>
                <select id="editAreaLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value="">Pilih 001 / 002 / ...</option>
                  <?php foreach ($lokasiAreaOptions as $areaLokasi): ?>
                    <option value="<?php echo htmlspecialchars($areaLokasi); ?>"><?php echo htmlspecialchars($areaLokasi); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Lokasi</label>
                <select name="lokasi" id="editLokasi" class="form-select" <?php echo !empty($lokasiOptions) ? 'required' : 'disabled'; ?>>
                  <option value=""><?php echo empty($lokasiOptions) ? 'Belum ada data lokasi. Tambahkan di Database Lokasi.' : 'Pilih tipe dan area dulu'; ?></option>
                </select>
              </div>
              <div class="col-md-12">
                <label class="form-label">Gambar Saat Ini</label>
                <div class="mb-2">
                  <img id="editItemImagePreview" src="https://via.placeholder.com/80x80?text=No+Image" alt="Preview gambar part" class="part-thumbnail">
                </div>
                <label class="form-label">Ganti Gambar Part</label>
                <input type="file" name="edit_item_image" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp">
                <small class="text-muted">Opsional. Jika tidak diubah dan kode part diganti, gambar lama akan mengikuti kode part baru.</small>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="imageUploadModal" tabindex="-1" aria-labelledby="imageUploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="imageUploadModalLabel">Upload Gambar Part</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="imageUploadForm" enctype="multipart/form-data">
            <input type="hidden" id="partCode" name="part_code">
            <div class="mb-3">
              <label class="form-label">Pilih Gambar</label>
              <input type="file" name="image_file" id="imageFile" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp" required>
              <small class="text-muted">Format: JPG, PNG, GIF, WebP. Max 5MB.</small>
            </div>
            <div id="uploadMessage"></div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="btn btn-primary" id="uploadImageBtn">Upload</button>
        </div>
      </div>
    </div>
  </div>

  <?php include __DIR__ . '/layouts/footer.html'; ?>
  <?php include __DIR__ . '/layouts/scripts.html'; ?>

  <script>
    $(document).ready(function() {
      var lokasiDataset = <?php echo json_encode(array_values($lokasiMetaMap), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

      function renderLokasiOptions(selectSelector, tipeSelector, areaSelector, selectedKode) {
        var $select = $(selectSelector);
        var tipeValue = $(tipeSelector).val() || '';
        var areaValue = $(areaSelector).val() || '';
        var placeholder = lokasiDataset.length === 0 ? 'Belum ada data lokasi. Tambahkan di Database Lokasi.' : 'Pilih lokasi';

        $select.empty();
        $select.append($('<option>', { value: '', text: placeholder }));

        var filtered = lokasiDataset.filter(function(item) {
          var tipeMatch = tipeValue === '' || item.tipe === tipeValue;
          var areaMatch = areaValue === '' || item.area === areaValue;
          return tipeMatch && areaMatch;
        });

        filtered.forEach(function(item) {
          $select.append($('<option>', {
            value: item.kode_lokasi,
            text: item.kode_lokasi + ' - ' + item.nama_lokasi
          }));
        });

        if (selectedKode && filtered.some(function(item) { return item.kode_lokasi === selectedKode; })) {
          $select.val(selectedKode);
        } else {
          $select.val('');
        }
      }

      $('#addTipeLokasi, #addAreaLokasi').on('change', function() {
        renderLokasiOptions('#addLokasi', '#addTipeLokasi', '#addAreaLokasi', '');
      });

      $('#editTipeLokasi, #editAreaLokasi').on('change', function() {
        renderLokasiOptions('#editLokasi', '#editTipeLokasi', '#editAreaLokasi', $('#editLokasi').data('selected-kode') || '');
      });

      renderLokasiOptions('#addLokasi', '#addTipeLokasi', '#addAreaLokasi', '');
      renderLokasiOptions('#editLokasi', '#editTipeLokasi', '#editAreaLokasi', '');

      $.fn.dataTable.ext.errMode = 'none';
      var table = $('#katalog-table').DataTable({
        dom: 'rt',
        paging: false,
        autoWidth: false,
        orderCellsTop: true,
        responsive: false,
        columnDefs: [
          { targets: [14, 15], orderable: false }
        ]
      });

      function updateSheetMeta() {
        var visibleRows = table.rows({ filter: 'applied' }).count();
        var hasFilter = $('#sheet-global-search').val().trim() !== '';
        $('.column_search').each(function() {
          if ($(this).val().trim() !== '') {
            hasFilter = true;
          }
        });

        $('#sheet-row-count').text(visibleRows + ' baris tampil');
        $('#sheet-filter-status').text(hasFilter ? 'Filter aktif' : 'Filter off');
      }

      $('.column_search').on('keyup change', function() {
        var columnIndex = $(this).data('column');
        table.column(columnIndex).search(this.value).draw();
      });

      $('#sheet-global-search').on('keyup change', function() {
        table.search(this.value).draw();
      });

      $('#sheet-clear-filters').on('click', function() {
        $('#sheet-global-search').val('');
        $('.column_search').val('');
        table.search('');
        table.columns().search('');
        table.draw();
      });

      $('#katalog-table').on('mouseenter focusin', 'tbody td, thead th', function() {
        var cellIndex = this.cellIndex;
        var rowIndex = $(this).parent().index() + 1;
        if (typeof cellIndex === 'number' && cellIndex >= 0) {
          var colLabel = cellIndex < 26 ? String.fromCharCode(65 + cellIndex) : 'A' + String.fromCharCode(65 + cellIndex - 26);
          $('#active-cell-label').text(colLabel + rowIndex);
        }
      });

      $('#katalog-table tbody').on('click', 'td', function() {
        $('#katalog-table td').removeClass('sheet-cell-active');
        $(this).addClass('sheet-cell-active');
      });

      table.on('draw', updateSheetMeta);
      updateSheetMeta();

      $(document).on('click', '.part-thumbnail, .image-btn', function() {
        var partCode = $(this).data('part-code');
        $('#partCode').val(partCode);
        $('#imageUploadForm')[0].reset();
        $('#uploadMessage').html('');
        $('#imageUploadModal').modal('show');
      });

      $(document).on('click', '.edit-item-btn', function() {
        var button = $(this);
        $('#editItemId').val(button.data('id'));
        $('#editMnPtfi').val(button.data('mn-ptfi'));
        $('#editKodePart').val(button.data('kode-part'));
        $('#editNamaPart').val(button.data('nama-part'));
        $('#editKategori').val(button.data('kategori'));
        $('#editMinQty').val(button.data('min-qty'));
        $('#editMaxQty').val(button.data('max-qty'));
        $('#editBerat').val(button.data('berat'));
        $('#editJenisPart').val(button.data('jenis-part'));
        $('#editJumlahLokasi').val(button.data('jumlah-lokasi'));
        $('#editDimensi').val(button.data('dimensi'));
        $('#editTipeLokasi').val(button.data('lokasi-tipe'));
        $('#editAreaLokasi').val(button.data('lokasi-area'));
        $('#editLokasi').data('selected-kode', button.data('lokasi'));
        renderLokasiOptions('#editLokasi', '#editTipeLokasi', '#editAreaLokasi', button.data('lokasi'));
        $('#editHarga').val(button.data('harga'));
        $('#editStok').val(button.data('stok'));
        $('#editItemImagePreview').attr('src', button.data('image-src'));
        $('#editItemModal').modal('show');
      });

      $('#uploadImageBtn').on('click', function() {
        var formData = new FormData($('#imageUploadForm')[0]);
        formData.append('upload_image', '1');

        $.ajax({
          url: window.location.href,
          type: 'POST',
          data: formData,
          processData: false,
          contentType: false,
          success: function(response) {
            try {
              var result = JSON.parse(response);
              if (result.success) {
                $('#uploadMessage').html('<div class="alert alert-success">' + result.message + '</div>');
                setTimeout(function() {
                  location.reload();
                }, 1200);
              } else {
                $('#uploadMessage').html('<div class="alert alert-danger">' + result.message + '</div>');
              }
            } catch (e) {
              $('#uploadMessage').html('<div class="alert alert-danger">Terjadi kesalahan</div>');
            }
          },
          error: function() {
            $('#uploadMessage').html('<div class="alert alert-danger">Gagal mengunggah gambar</div>');
          }
        });
      });
    });
  </script>
</body>
</html>