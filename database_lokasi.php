<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

$allowed = ['login.php','setup_users_table.php','logout.php','pic_stock_taking.php'];
$script  = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isLogged = !empty($_SESSION['user_id']) || !empty($_SESSION['pic_id']);
if (!in_array($script, $allowed) && !$isLogged) {
    header('Location: login.php'); exit;
}

/* ── Buat tabel jika belum ada ── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS lokasi (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        kode_lokasi   VARCHAR(100) NOT NULL UNIQUE,
        nama_lokasi   VARCHAR(200) NOT NULL,
        area          VARCHAR(50)  NULL,
    tipe          ENUM('TM1','TM2') NOT NULL DEFAULT 'TM1',
        kapasitas     INT          NULL COMMENT 'Kapasitas maksimal (opsional)',
        keterangan    TEXT         NULL,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // table may already exist with partial schema – ignore
}

/* ── Samakan enum tipe ke TM1/TM2 pada tabel lama ── */
try {
  $pdo->exec("ALTER TABLE lokasi MODIFY tipe ENUM('rak','lantai','gudang','lainnya','TM1','TM2') NOT NULL DEFAULT 'TM1'");
  $pdo->exec("UPDATE lokasi SET tipe='TM1' WHERE tipe NOT IN ('TM1','TM2') OR tipe IS NULL OR tipe=''");
  $pdo->exec("ALTER TABLE lokasi MODIFY tipe ENUM('TM1','TM2') NOT NULL DEFAULT 'TM1'");
} catch (Exception $e) {
  // ignore migration error to keep page usable
}

/* ── Opsi area tetap ── */
$areaOptions = ['001', '002', '003', '008'];

/* ── Helper: generate range kode berdasarkan angka di belakang ── */
function generateKodeRange(string $startCode, string $endCode): array
{
  $startCode = trim($startCode);
  $endCode   = trim($endCode);

  if ($startCode === '') return [];
  if ($endCode === '' || $endCode === $startCode) return [$startCode];

  if (!preg_match('/^(.*?)(\d+)$/', $startCode, $mStart)) return [];
  if (!preg_match('/^(.*?)(\d+)$/', $endCode, $mEnd)) return [];

  $prefixStart = $mStart[1];
  $prefixEnd   = $mEnd[1];
  $numStartStr = $mStart[2];
  $numEndStr   = $mEnd[2];

  if ($prefixStart !== $prefixEnd) return [];
  if (strlen($numStartStr) !== strlen($numEndStr)) return [];

  $numStart = (int)$numStartStr;
  $numEnd   = (int)$numEndStr;
  if ($numEnd < $numStart) return [];

  $maxBatch = 1000;
  if (($numEnd - $numStart + 1) > $maxBatch) return [];

  $codes = [];
  $pad = strlen($numStartStr);
  for ($i = $numStart; $i <= $numEnd; $i++) {
    $codes[] = $prefixStart . str_pad((string)$i, $pad, '0', STR_PAD_LEFT);
  }

  return $codes;
}

/* ══════════════════════ POST HANDLERS ══════════════════════ */

/* Tambah */
if (isset($_POST['action']) && $_POST['action'] === 'tambah') {
  $kodeAwal  = trim($_POST['kode_lokasi'] ?? '');
  $kodeAkhir = trim($_POST['kode_lokasi_akhir'] ?? '');
  $nama  = trim($_POST['nama_lokasi'] ?? '');
    $area  = trim($_POST['area']        ?? '');
    $tipe  = trim($_POST['tipe']        ?? 'TM1');
    $kap   = $_POST['kapasitas'] !== '' ? (int)$_POST['kapasitas'] : null;
    $ket   = trim($_POST['keterangan']  ?? '');

    $validTipe = ['TM1','TM2'];
    if (!in_array($tipe, $validTipe, true)) $tipe = 'TM1';
    if ($area !== '' && !in_array($area, $areaOptions, true)) $area = '';

    if ($kodeAwal !== '') {
      $kodeList = generateKodeRange($kodeAwal, $kodeAkhir);
      if (empty($kodeList)) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Format rentang kode tidak valid. Gunakan prefix sama dan angka akhir berurutan, mis. 102A0101 s/d 102A0110.'];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
      }

      $namaBase = $nama;
        try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO lokasi (kode_lokasi,nama_lokasi,area,tipe,kapasitas,keterangan)
                                   VALUES (?,?,?,?,?,?)");

        $added = 0;
        foreach ($kodeList as $kode) {
          $namaLokasi = ($namaBase !== '') ? $namaBase : $kode;
          $stmt->execute([$kode, $namaLokasi, $area ?: null, $tipe, $kap, $ket ?: null]);
          if ($stmt->rowCount() > 0) $added++;
        }

        $total = count($kodeList);
        $skip  = $total - $added;
        if ($total === 1) {
          $_SESSION['flash'] = $added > 0
            ? ['type'=>'success','msg'=>'Lokasi berhasil ditambahkan.']
            : ['type'=>'warning','msg'=>'Kode lokasi sudah ada, tidak ada data baru ditambahkan.'];
        } else {
          $_SESSION['flash'] = ['type'=>'success','msg'=>"Tambah rentang selesai. $added lokasi ditambahkan" . ($skip > 0 ? ", $skip dilewati (sudah ada)." : '.')];
        }
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Kode lokasi sudah ada atau terjadi kesalahan.'];
        }
    } else {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Kode lokasi awal wajib diisi.'];
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

/* Edit */
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id   = (int)($_POST['id'] ?? 0);
    $kode = trim($_POST['kode_lokasi'] ?? '');
    $nama = trim($_POST['nama_lokasi'] ?? '');
    $area = trim($_POST['area']        ?? '');
    $tipe = trim($_POST['tipe']        ?? 'TM1');
    $kap  = ($_POST['kapasitas'] !== '') ? (int)$_POST['kapasitas'] : null;
    $ket  = trim($_POST['keterangan']  ?? '');

    $validTipe = ['TM1','TM2'];
    if (!in_array($tipe, $validTipe, true)) $tipe = 'TM1';
    if ($area !== '' && !in_array($area, $areaOptions, true)) $area = '';

    if ($id > 0 && $kode !== '' && $nama !== '') {
        try {
            $stmt = $pdo->prepare("UPDATE lokasi SET kode_lokasi=?,nama_lokasi=?,area=?,tipe=?,kapasitas=?,keterangan=? WHERE id=?");
            $stmt->execute([$kode, $nama, $area ?: null, $tipe, $kap, $ket ?: null, $id]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Lokasi berhasil diperbarui.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Kode lokasi sudah ada atau terjadi kesalahan.'];
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

/* Hapus */
if (isset($_POST['action']) && $_POST['action'] === 'hapus') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $pdo->prepare("DELETE FROM lokasi WHERE id=?")->execute([$id]);
            $_SESSION['flash'] = ['type'=>'warning','msg'=>'Lokasi berhasil dihapus.'];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Gagal menghapus (mungkin sedang digunakan).'];
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

  /* Hapus semua */
  if (isset($_POST['action']) && $_POST['action'] === 'hapus_semua') {
    try {
      $deleted = $pdo->exec("DELETE FROM lokasi");
      $_SESSION['flash'] = ['type'=>'warning','msg'=>"Semua lokasi berhasil dihapus ($deleted data)."];
    } catch (Exception $e) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Gagal menghapus semua lokasi.'];
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
  }

  /* Duplikat prefix kode lokasi */
  if (isset($_POST['action']) && $_POST['action'] === 'duplikat_prefix') {
    $prefixAsal   = trim($_POST['prefix_asal'] ?? '');
    $prefixTujuan = trim($_POST['prefix_tujuan'] ?? '');

    if ($prefixAsal === '' || $prefixTujuan === '') {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Prefix asal dan tujuan wajib diisi.'];
      header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if ($prefixAsal === $prefixTujuan) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Prefix asal dan tujuan tidak boleh sama.'];
      header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }

    try {
      $stmtSrc = $pdo->prepare("SELECT kode_lokasi,nama_lokasi,area,tipe,kapasitas,keterangan FROM lokasi WHERE kode_lokasi LIKE ? ORDER BY kode_lokasi ASC");
      $stmtSrc->execute([$prefixAsal . '%']);
      $rows = $stmtSrc->fetchAll(PDO::FETCH_ASSOC);

      if (empty($rows)) {
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'Tidak ada lokasi dengan prefix asal tersebut.'];
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
      }

      $stmtIns = $pdo->prepare("INSERT IGNORE INTO lokasi (kode_lokasi,nama_lokasi,area,tipe,kapasitas,keterangan) VALUES (?,?,?,?,?,?)");
      $added = 0;
      foreach ($rows as $r) {
        $suffix = substr($r['kode_lokasi'], strlen($prefixAsal));
        $kodeBaru = $prefixTujuan . $suffix;

        $namaBaru = $r['nama_lokasi'];
        if (substr($namaBaru, 0, strlen($prefixAsal)) === $prefixAsal) {
          $namaBaru = $prefixTujuan . substr($namaBaru, strlen($prefixAsal));
        }

        $stmtIns->execute([
          $kodeBaru,
          $namaBaru,
          $r['area'],
          $r['tipe'],
          $r['kapasitas'],
          $r['keterangan'],
        ]);
        if ($stmtIns->rowCount() > 0) $added++;
      }

      $total = count($rows);
      $skip = $total - $added;
      $_SESSION['flash'] = ['type'=>'success','msg'=>"Duplikat selesai. $added lokasi baru dibuat" . ($skip > 0 ? ", $skip dilewati (sudah ada)." : '.')];
    } catch (Exception $e) {
      $_SESSION['flash'] = ['type'=>'danger','msg'=>'Gagal duplikat lokasi: ' . $e->getMessage()];
    }

    header('Location: ' . $_SERVER['PHP_SELF']); exit;
  }

/* Sinkronisasi dari storage_bin & new_storage_bin stock_taking */
if (isset($_POST['action']) && $_POST['action'] === 'sync') {
    try {
        $rows = $pdo->query("
            SELECT DISTINCT val, area FROM (
              SELECT TRIM(storage_bin)     AS val, area FROM stock_taking WHERE storage_bin     IS NOT NULL AND TRIM(storage_bin)     != ''
              UNION
              SELECT TRIM(new_storage_bin) AS val, area FROM stock_taking WHERE new_storage_bin IS NOT NULL AND TRIM(new_storage_bin) != ''
            ) t WHERE val != ''
            ORDER BY val
        ")->fetchAll(PDO::FETCH_ASSOC);

        $added = 0;
        $stmt = $pdo->prepare("INSERT IGNORE INTO lokasi (kode_lokasi,nama_lokasi,area,tipe) VALUES (?,?,?,'TM1')");
        foreach ($rows as $r) {
            $kode = $r['val'];
            $stmt->execute([$kode, $kode, $r['area'] ?: null]);
            if ($stmt->rowCount() > 0) $added++;
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>"Sinkronisasi selesai. $added lokasi baru ditambahkan."];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Gagal sinkronisasi: ' . $e->getMessage()];
    }
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

/* ── Ambil flash message ── */
$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

/* ── Filter ── */
$filterArea = trim($_GET['filter_area'] ?? '');
$filterTipe = trim($_GET['filter_tipe'] ?? '');
$filterKw   = trim($_GET['q']           ?? '');
$exportLokasiUrl = 'export_lokasi_excel.php?' . http_build_query([
  'filter_area' => $filterArea,
  'filter_tipe' => $filterTipe,
  'q' => $filterKw,
]);

/* ── Fetch data utama + stats stok ── */
$lokList = [];
try {
    $where = ['1=1'];
    $params = [];
    if ($filterArea !== '') { $where[] = 'l.area = ?';              $params[] = $filterArea; }
    if ($filterTipe !== '') { $where[] = 'l.tipe = ?';              $params[] = $filterTipe; }
    if ($filterKw   !== '') { $where[] = '(l.kode_lokasi LIKE ? OR l.nama_lokasi LIKE ?)';
                              $params[] = "%$filterKw%"; $params[] = "%$filterKw%"; }

    $sql = "SELECT l.*,
              COUNT(DISTINCT st_cur.id)  AS parts_current,
              COUNT(DISTINCT st_new.id)  AS parts_new
            FROM lokasi l
            LEFT JOIN stock_taking st_cur ON TRIM(st_cur.storage_bin)     = l.kode_lokasi
            LEFT JOIN stock_taking st_new ON TRIM(st_new.new_storage_bin) = l.kode_lokasi
            WHERE " . implode(' AND ', $where) . "
            GROUP BY l.id
            ORDER BY l.area, l.kode_lokasi ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lokList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ── Summary stats ── */
$statTotal = count($lokList);
$statDenganParts = 0;
$statKosong      = 0;
foreach ($lokList as $l) {
    if (((int)$l['parts_current'] + (int)$l['parts_new']) > 0) $statDenganParts++;
    else $statKosong++;
}

/* ── Fetch single lokasi for edit modal ── */
$editRow = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    try {
        $editRow = $pdo->prepare("SELECT * FROM lokasi WHERE id=?")->execute([$eid]) ? null : null;
        $s = $pdo->prepare("SELECT * FROM lokasi WHERE id=?");
        $s->execute([$eid]);
        $editRow = $s->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="id">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<?php include __DIR__ . '/layouts/sidebar.html'; ?>
<?php include __DIR__ . '/layouts/header.html'; ?>

<style>
  .lokasi-kpi { border-radius:12px; padding:18px 20px; color:#fff; box-shadow:0 8px 24px rgba(234,88,12,.12); }
  .lokasi-kpi .val { font-size:1.9rem; font-weight:700; line-height:1.1; }
  .lokasi-kpi .lbl { font-size:.78rem; opacity:.88; margin-top:3px; }
  .bg-indigo  { background:linear-gradient(135deg,#ea580c,#fb923c); }
  .bg-teal    { background:linear-gradient(135deg,#f97316,#fdba74); }
  .bg-rose    { background:linear-gradient(135deg,#c2410c,#fb923c); }
  .bg-amber   { background:linear-gradient(135deg,#f59e0b,#fbbf24); }
  .tipe-badge-tm1 { background:#ffedd5; color:#9a3412; }
  .tipe-badge-tm2 { background:#fff7ed; color:#c2410c; }
  .parts-badge  { background:#ffedd5; color:#9a3412; font-size:.7rem; }
  .empty-badge  { background:#f3f4f6; color:#9ca3af; font-size:.7rem; }
</style>

<div class="pc-container">
  <div class="pc-content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-block">
        <div class="row align-items-center">
          <div class="col-md-12">
            <div class="page-header-title">
              <h5 class="m-b-10"><i class="ti ti-map-pin me-2"></i>Database Lokasi</h5>
            </div>
            <ul class="breadcrumb">
              <li class="breadcrumb-item"><a href="dashboard.php">Dasbor</a></li>
              <li class="breadcrumb-item" aria-current="page">Database Lokasi</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Flash -->
    <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash['msg']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
        <div class="lokasi-kpi bg-indigo">
          <div class="val"><?= number_format($statTotal) ?></div>
          <div class="lbl">Total Lokasi</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lokasi-kpi bg-teal">
          <div class="val"><?= number_format($statDenganParts) ?></div>
          <div class="lbl">Lokasi Terisi</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lokasi-kpi bg-rose">
          <div class="val"><?= number_format($statKosong) ?></div>
          <div class="lbl">Lokasi Kosong</div>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="lokasi-kpi bg-amber">
          <div class="val"><?= count($areaOptions) ?></div>
          <div class="lbl">Jumlah Area</div>
        </div>
      </div>
    </div>

    <div class="row g-3">

      <!-- ── Form Tambah / Panel kiri ── -->
      <div class="col-lg-3">
        <div class="card">
          <div class="card-header">
            <h5 class="card-title mb-0"><i class="ti ti-plus me-2"></i>Tambah Lokasi</h5>
          </div>
          <div class="card-body">
            <form method="post">
              <input type="hidden" name="action" value="tambah">
              <div class="mb-2">
                <label class="form-label fw-500">Kode Lokasi <span class="text-danger">*</span></label>
                <input type="text" name="kode_lokasi" class="form-control" placeholder="Cth: A-01-01" required maxlength="100">
              </div>
              <div class="mb-2">
                <label class="form-label fw-500">Kode Lokasi Akhir</label>
                <input type="text" name="kode_lokasi_akhir" class="form-control" placeholder="Opsional, cth: 102A0110" maxlength="100">
                <small class="text-muted">Jika diisi, sistem membuat urutan dari kode awal sampai kode akhir berdasarkan angka belakang.</small>
              </div>
              <div class="mb-2">
                <label class="form-label fw-500">Nama Lokasi</label>
                <input type="text" name="nama_lokasi" class="form-control" placeholder="Kosongkan untuk otomatis sama dengan kode" maxlength="200">
              </div>
              <div class="mb-2">
                <label class="form-label fw-500">Area</label>
                <select name="area" class="form-select">
                  <option value="">— Pilih Area —</option>
                  <?php foreach ($areaOptions as $a): ?>
                  <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                  <?php endforeach; ?>
                  <option value="">Lainnya</option>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label fw-500">Tipe</label>
                <select name="tipe" class="form-select">
                  <option value="TM1">TM1</option>
                  <option value="TM2">TM2</option>
                </select>
              </div>
              <div class="mb-2">
                <label class="form-label fw-500">Kapasitas</label>
                <input type="number" name="kapasitas" class="form-control" placeholder="Opsional" min="0" value="">
              </div>
              <div class="mb-3">
                <label class="form-label fw-500">Keterangan</label>
                <textarea name="keterangan" class="form-control" rows="2" placeholder="Catatan opsional"></textarea>
              </div>
              <button type="submit" class="btn btn-primary w-100"><i class="ti ti-device-floppy me-1"></i>Simpan</button>
            </form>
          </div>
        </div>

        <!-- Sinkronisasi -->
        <div class="card mt-3">
          <div class="card-body">
            <h6 class="card-title"><i class="ti ti-copy me-2"></i>Duplikat Prefix Lokasi</h6>
            <p class="text-muted small mb-3">Contoh: dari <b>102A</b> ke <b>102B</b> akan menyalin 102Axxxx menjadi 102Bxxxx.</p>
            <form method="post" class="mb-2" onsubmit="return confirm('Duplikat prefix lokasi sekarang?');">
              <input type="hidden" name="action" value="duplikat_prefix">
              <div class="mb-2">
                <label class="form-label fw-500 mb-1">Prefix Asal</label>
                <input type="text" name="prefix_asal" class="form-control" placeholder="Contoh: 102A" required maxlength="50">
              </div>
              <div class="mb-3">
                <label class="form-label fw-500 mb-1">Prefix Tujuan</label>
                <input type="text" name="prefix_tujuan" class="form-control" placeholder="Contoh: 102B" required maxlength="50">
              </div>
              <button type="submit" class="btn btn-outline-primary w-100"><i class="ti ti-copy me-1"></i>Duplikat Prefix</button>
            </form>
          </div>
        </div>

        <div class="card mt-3">
          <div class="card-body">
            <h6 class="card-title"><i class="ti ti-refresh me-2"></i>Sinkronisasi dari Stock Taking</h6>
            <p class="text-muted small mb-3">Import otomatis lokasi unik dari kolom <code>storage_bin</code> dan <code>new_storage_bin</code> yang belum ada di database ini.</p>
            <form method="post" onsubmit="return confirm('Lakukan sinkronisasi?');">
              <input type="hidden" name="action" value="sync">
              <button type="submit" class="btn btn-outline-secondary w-100"><i class="ti ti-cloud-download me-1"></i>Sync Sekarang</button>
            </form>
          </div>
        </div>

        <div class="card mt-3 border-danger">
          <div class="card-body">
            <h6 class="card-title text-danger"><i class="ti ti-trash me-2"></i>Hapus Semua Lokasi</h6>
            <p class="text-muted small mb-3">Menghapus seluruh data pada database lokasi. Aksi ini tidak bisa dibatalkan.</p>
            <form method="post" onsubmit="return confirm('Yakin hapus SEMUA lokasi? Tindakan ini tidak bisa dibatalkan.');">
              <input type="hidden" name="action" value="hapus_semua">
              <button type="submit" class="btn btn-danger w-100"><i class="ti ti-alert-triangle me-1"></i>Hapus Semua</button>
            </form>
          </div>
        </div>
      </div>

      <!-- ── Tabel Data Lokasi ── -->
      <div class="col-lg-9">
        <div class="card">
          <div class="card-header">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
              <h5 class="card-title mb-0"><i class="ti ti-list me-2"></i>Daftar Lokasi
                <span class="badge bg-primary ms-1"><?= number_format($statTotal) ?></span>
              </h5>
              <!-- Filter -->
              <form method="get" class="d-flex gap-2 flex-wrap">
                <input type="text" name="q" class="form-control form-control-sm" style="width:150px;"
                       placeholder="Cari kode/nama…" value="<?= htmlspecialchars($filterKw) ?>">
                <select name="filter_area" class="form-select form-select-sm" style="width:110px;">
                  <option value="">Semua Area</option>
                  <?php foreach ($areaOptions as $a): ?>
                  <option value="<?= htmlspecialchars($a) ?>" <?= $filterArea === $a ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="filter_tipe" class="form-select form-select-sm" style="width:110px;">
                  <option value="">Semua Tipe</option>
                  <option value="TM1" <?= $filterTipe==='TM1' ?'selected':'' ?>>TM1</option>
                  <option value="TM2" <?= $filterTipe==='TM2' ?'selected':'' ?>>TM2</option>
                </select>
                <button type="submit" class="btn btn-outline-primary btn-sm"><i class="ti ti-search"></i></button>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary btn-sm"><i class="ti ti-x"></i></a>
                <a href="<?= htmlspecialchars($exportLokasiUrl) ?>" class="btn btn-success btn-sm"><i class="ti ti-file-spreadsheet me-1"></i>Excel</a>
              </form>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table id="tblLokasi" class="table table-hover align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Kode Lokasi</th>
                    <th>Nama Lokasi</th>
                    <th class="text-center">Area</th>
                    <th class="text-center">Tipe</th>
                    <th class="text-center">Kapasitas</th>
                    <th class="text-center">Parts Terkait</th>
                    <th>Keterangan</th>
                    <th class="text-center" style="width:110px;">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($lokList)): ?>
                  <tr><td colspan="8" class="text-center text-muted py-5">
                    <i class="ti ti-map-off" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>
                    Belum ada data lokasi.
                    <?php if ($statTotal === 0): ?> Gunakan tombol <b>Sync</b> untuk import dari data stock taking.<?php endif; ?>
                  </td></tr>
                  <?php else: foreach ($lokList as $lok):
                    $tipeLabel = ['TM1'=>'TM1','TM2'=>'TM2'];
                    $tipeClass = ['TM1'=>'tipe-badge-tm1','TM2'=>'tipe-badge-tm2'];
                    $tipeName  = $tipeLabel[$lok['tipe']] ?? $lok['tipe'];
                    $tipeColor = $tipeClass[$lok['tipe']] ?? 'tipe-badge-tm1';
                    $partsTotal = (int)$lok['parts_current'] + (int)$lok['parts_new'];
                  ?>
                  <tr>
                    <td class="fw-600 text-primary"><?= htmlspecialchars($lok['kode_lokasi']) ?></td>
                    <td><?= htmlspecialchars($lok['nama_lokasi']) ?></td>
                    <td class="text-center">
                      <?php if ($lok['area']): ?>
                        <span class="badge bg-secondary"><?= htmlspecialchars($lok['area']) ?></span>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <span class="badge <?= $tipeColor ?>"><?= $tipeName ?></span>
                    </td>
                    <td class="text-center">
                      <?= $lok['kapasitas'] !== null ? number_format((int)$lok['kapasitas']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center">
                      <?php if ($partsTotal > 0): ?>
                        <span class="badge parts-badge"><?= number_format($partsTotal) ?> parts</span>
                      <?php else: ?>
                        <span class="badge empty-badge">Kosong</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-muted small" style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                        title="<?= htmlspecialchars($lok['keterangan'] ?? '') ?>">
                      <?= htmlspecialchars($lok['keterangan'] ?? '—') ?>
                    </td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-primary me-1"
                              onclick="openEdit(<?= htmlspecialchars(json_encode($lok)) ?>)"
                              title="Edit">
                        <i class="ti ti-pencil"></i>
                      </button>
                      <form method="post" class="d-inline" onsubmit="return confirm('Hapus lokasi ini?');">
                        <input type="hidden" name="action" value="hapus">
                        <input type="hidden" name="id" value="<?= (int)$lok['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus">
                          <i class="ti ti-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /row -->
  </div>
</div>

<!-- ══════════ Modal Edit ══════════ -->
<div class="modal fade" id="modalEdit" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="ti ti-pencil me-2"></i>Edit Lokasi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="formEdit">
        <div class="modal-body">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" id="edit_id">
          <div class="mb-3">
            <label class="form-label fw-500">Kode Lokasi <span class="text-danger">*</span></label>
            <input type="text" name="kode_lokasi" id="edit_kode" class="form-control" required maxlength="100">
          </div>
          <div class="mb-3">
            <label class="form-label fw-500">Nama Lokasi <span class="text-danger">*</span></label>
            <input type="text" name="nama_lokasi" id="edit_nama" class="form-control" required maxlength="200">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label fw-500">Area</label>
              <select name="area" id="edit_area" class="form-select">
                <option value="">— Pilih Area —</option>
                <?php foreach ($areaOptions as $a): ?>
                <option value="<?= htmlspecialchars($a) ?>"><?= htmlspecialchars($a) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label fw-500">Tipe</label>
              <select name="tipe" id="edit_tipe" class="form-select">
                <option value="TM1">TM1</option>
                <option value="TM2">TM2</option>
              </select>
            </div>
          </div>
          <div class="mt-3 mb-2">
            <label class="form-label fw-500">Kapasitas</label>
            <input type="number" name="kapasitas" id="edit_kapasitas" class="form-control" min="0" placeholder="Opsional">
          </div>
          <div class="mb-1">
            <label class="form-label fw-500">Keterangan</label>
            <textarea name="keterangan" id="edit_keterangan" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/layouts/footer.html'; ?>
<?php include __DIR__ . '/layouts/scripts.html'; ?>

<script>
/* ── Init DataTable ── */
$(document).ready(function() {
  if ($.fn.DataTable) {
    $('#tblLokasi').DataTable({
      responsive: true,
      language: {
        search:       "Cari:",
        lengthMenu:   "Tampilkan _MENU_ data",
        info:         "Menampilkan _START_–_END_ dari _TOTAL_ lokasi",
        infoEmpty:    "Tidak ada lokasi",
        paginate:     { previous:"‹", next:"›" },
        zeroRecords:  "Tidak ada hasil"
      },
      pageLength: 25,
      order: [[0,'asc']]
    });
  }
});

/* ── Isi modal edit ── */
function openEdit(data) {
  document.getElementById('edit_id').value          = data.id;
  document.getElementById('edit_kode').value        = data.kode_lokasi;
  document.getElementById('edit_nama').value        = data.nama_lokasi;
  document.getElementById('edit_keterangan').value  = data.keterangan || '';
  document.getElementById('edit_kapasitas').value   = data.kapasitas || '';

  var selArea = document.getElementById('edit_area');
  selArea.value = data.area || '';

  var selTipe = document.getElementById('edit_tipe');
  selTipe.value = data.tipe || 'TM1';

  var modal = new bootstrap.Modal(document.getElementById('modalEdit'));
  modal.show();
}
</script>
</body>
</html>
