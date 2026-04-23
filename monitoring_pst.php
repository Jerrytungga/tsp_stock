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

/* ─────────────────────────── helpers ─────────────────────────── */
$baseWhere   = "new_available_stock IS NOT NULL AND new_available_stock != ''
                AND available_stock IS NOT NULL AND available_stock != ''";
$numericDiff = "(TRIM(new_available_stock) REGEXP '^-?[0-9]+(\.[0-9]+)?\$'
                 AND TRIM(available_stock)     REGEXP '^-?[0-9]+(\.[0-9]+)?\$'
                 AND CAST(TRIM(new_available_stock) AS DECIMAL(18,4))
                  != CAST(TRIM(available_stock)     AS DECIMAL(18,4)))";
$stringDiff  = "(NOT($numericDiff) AND TRIM(new_available_stock) != TRIM(available_stock))";
$anyDiff     = "(($numericDiff) OR ($stringDiff))";
$hasCounted  = "((new_available_stock IS NOT NULL AND new_available_stock != '')
                  OR (new_storage_bin   IS NOT NULL AND new_storage_bin   != ''))";

/* ─────────────────────────── KPI cards ─────────────────────────── */
$kpi = [
    'total'      => 0, 'counted'    => 0,
    'diff'       => 0, 'unresolved' => 0, 'resolved'   => 0,
    'short'      => 0, 'over'       => 0,
];
try {
    $row = $pdo->query("
        SELECT
          COUNT(*)                                             AS total,
          SUM($hasCounted)                                     AS counted,
          SUM($baseWhere AND $anyDiff)                         AS diff,
          SUM($baseWhere AND $anyDiff AND resolved_at IS NULL) AS unresolved,
          SUM($baseWhere AND $anyDiff AND resolved_at IS NOT NULL) AS resolved
        FROM stock_taking
    ")->fetch(PDO::FETCH_ASSOC);
    $kpi['total']      = (int)($row['total']      ?? 0);
    $kpi['counted']    = (int)($row['counted']    ?? 0);
    $kpi['diff']       = (int)($row['diff']       ?? 0);
    $kpi['unresolved'] = (int)($row['unresolved'] ?? 0);
    $kpi['resolved']   = (int)($row['resolved']   ?? 0);

    /* short / over */
    $allRows = $pdo->query("
        SELECT available_stock, new_available_stock
        FROM stock_taking
        WHERE $baseWhere")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allRows as $r) {
        $a = trim((string)($r['available_stock']     ?? ''));
        $n = trim((string)($r['new_available_stock'] ?? ''));
        if (preg_match('/^-?\d+(\.\d+)?$/', $a) && preg_match('/^-?\d+(\.\d+)?$/', $n)) {
            $d = (float)$n - (float)$a;
            if ($d < 0) $kpi['short']++;
            elseif ($d > 0) $kpi['over']++;
        }
    }
} catch (Exception $e) {}

$countedPct = $kpi['total'] > 0 ? round($kpi['counted'] / $kpi['total'] * 100, 1) : 0;
$resolvedPct = $kpi['diff']  > 0 ? round($kpi['resolved'] / $kpi['diff']  * 100, 1) : 0;

/* ─────────────────────────── progress by PIC ─────────────────────────── */
$picRows = [];
try {
    $picRows = $pdo->query("
        SELECT
          p.id, p.name, p.nrp,
          COUNT(s.id)                                          AS total,
          SUM($hasCounted)                                     AS counted,
          SUM($baseWhere AND $anyDiff)                         AS diff_count,
          SUM($baseWhere AND $anyDiff AND s.resolved_at IS NULL)  AS unresolved,
          SUM($baseWhere AND $anyDiff AND s.resolved_at IS NOT NULL) AS resolved,
          MAX(s.created_at)                                    AS last_activity
        FROM pic p
        LEFT JOIN stock_taking s ON s.assigned_pic_id = p.id
        GROUP BY p.id, p.name, p.nrp
        ORDER BY p.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ─────────────────────────── progress by area ─────────────────────────── */
$areaRows = [];
try {
    $areaRows = $pdo->query("
        SELECT
          area,
          COUNT(*)                                             AS total,
          SUM($hasCounted)                                     AS counted,
          SUM($baseWhere AND $anyDiff)                         AS diff_count,
          SUM($baseWhere AND $anyDiff AND resolved_at IS NULL) AS unresolved
        FROM stock_taking
        GROUP BY area
        ORDER BY area
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ─────────────── daily activity (last 14 days) ─────────────── */
$dailyLabels = []; $dailyCounts = [];
try {
    $rows = $pdo->query("
        SELECT DATE(created_at) AS d, COUNT(*) AS c
        FROM stock_taking
        WHERE $hasCounted
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[$r['d']] = (int)$r['c']; }
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $dailyLabels[] = date('d M', strtotime($d));
        $dailyCounts[] = $map[$d] ?? 0;
    }
} catch (Exception $e) {}

/* ───────── top 10 materials with largest stockdiff ───────── */
$topDiff = [];
try {
    $topDiff = $pdo->query("
        SELECT material, material_description,
               available_stock, new_available_stock,
               (CAST(TRIM(new_available_stock) AS DECIMAL(18,4))
                - CAST(TRIM(available_stock)   AS DECIMAL(18,4))) AS diff_qty,
               area,
               resolved_at
        FROM stock_taking
        WHERE $baseWhere
          AND $anyDiff
          AND TRIM(new_available_stock) REGEXP '^-?[0-9]+(\.[0-9]+)?\$'
          AND TRIM(available_stock)     REGEXP '^-?[0-9]+(\.[0-9]+)?\$'
        ORDER BY ABS(CAST(TRIM(new_available_stock) AS DECIMAL(18,4))
                     - CAST(TRIM(available_stock)   AS DECIMAL(18,4))) DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ─────────────────── recent activity (last 20 rows) ─────────────────── */
$recentActivity = [];
try {
    $recentActivity = $pdo->query("
        SELECT s.material, s.material_description, s.area,
               s.available_stock, s.new_available_stock,
               s.created_at, p.name AS pic_name
        FROM stock_taking s
        LEFT JOIN pic p ON s.assigned_pic_id = p.id
        WHERE $hasCounted
        ORDER BY s.created_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="id">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<?php include __DIR__ . '/layouts/sidebar.html'; ?>
<?php include __DIR__ . '/layouts/header.html'; ?>

<style>
  .mon-kpi {
    border-radius: 12px;
    padding: 20px 22px;
    color: #fff;
    box-shadow: 0 10px 24px rgba(234,88,12,.12);
    transition: transform .15s;
  }
  .mon-kpi:hover { transform: translateY(-2px); }
  .mon-kpi .val  { font-size: 2rem; font-weight: 700; line-height: 1.1; }
  .mon-kpi .lbl  { font-size: .8rem; opacity: .88; margin-top: 4px; }
  .mon-kpi .sub  { font-size: .75rem; opacity: .75; margin-top: 2px; }
  .bg-indigo  { background: linear-gradient(135deg,#ea580c,#fb923c); }
  .bg-teal    { background: linear-gradient(135deg,#f97316,#fdba74); }
  .bg-amber   { background: linear-gradient(135deg,#d97706,#fbbf24); }
  .bg-rose    { background: linear-gradient(135deg,#c2410c,#f97316); }
  .bg-emerald { background: linear-gradient(135deg,#f59e0b,#fb923c); }
  .bg-sky     { background: linear-gradient(135deg,#fb923c,#fed7aa); }
  .progress-sm { height: 8px; border-radius: 4px; }
  .section-title {
    font-size: .7rem; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; color: #9a3412; margin-bottom: 14px;
  }
  .badge-pending { background:#fee2e2; color:#b91c1c; }
  .badge-done    { background:#ffedd5; color:#9a3412; }
  .pic-row:hover { background:#fff7ed; }
  .diff-neg { color:#c2410c; font-weight:600; }
  .diff-pos { color:#9a3412; font-weight:600; }
</style>

<div class="pc-container">
  <div class="pc-content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-block">
        <div class="row align-items-center">
          <div class="col-md-12">
            <div class="page-header-title">
              <h5 class="m-b-10"><i class="ti ti-chart-dots me-2"></i>Monitoring PST (Pengambilan Stok)</h5>
            </div>
            <ul class="breadcrumb">
              <li class="breadcrumb-item"><a href="dashboard.php">Dasbor</a></li>
              <li class="breadcrumb-item" aria-current="page">Monitoring PST</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- ── KPI Cards ── -->
    <div class="row g-3 mb-4">
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-indigo">
          <div class="val"><?= number_format($kpi['total']) ?></div>
          <div class="lbl">Total Parts</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-teal">
          <div class="val"><?= number_format($kpi['counted']) ?></div>
          <div class="lbl">Sudah Dihitung</div>
          <div class="sub"><?= $countedPct ?>% dari total</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-amber">
          <div class="val"><?= number_format($kpi['diff']) ?></div>
          <div class="lbl">Ada Selisih</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-rose">
          <div class="val"><?= number_format($kpi['unresolved']) ?></div>
          <div class="lbl">Belum Diselesaikan</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-emerald">
          <div class="val"><?= number_format($kpi['resolved']) ?></div>
          <div class="lbl">Sudah Diselesaikan</div>
          <div class="sub"><?= $resolvedPct ?>% dari selisih</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-xl-2">
        <div class="mon-kpi bg-sky">
          <div class="val"><?= number_format($kpi['total'] - $kpi['counted']) ?></div>
          <div class="lbl">Belum Dihitung</div>
        </div>
      </div>
    </div>

    <!-- ── Overall Progress Bar ── -->
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-600">Progress Keseluruhan</span>
          <span class="fw-600 text-primary"><?= $countedPct ?>%</span>
        </div>
        <div class="progress progress-sm mb-1">
          <div class="progress-bar bg-primary" style="width:<?= $countedPct ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:.78rem;color:#6b7280;">
          <span><?= number_format($kpi['counted']) ?> sudah dihitung</span>
          <span><?= number_format($kpi['total'] - $kpi['counted']) ?> belum dihitung dari <?= number_format($kpi['total']) ?></span>
        </div>

        <?php if ($kpi['diff'] > 0): ?>
        <hr class="my-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-600">Resolusi Selisih</span>
          <span class="fw-600 text-success"><?= $resolvedPct ?>%</span>
        </div>
        <div class="progress progress-sm mb-1">
          <div class="progress-bar bg-success" style="width:<?= $resolvedPct ?>%"></div>
        </div>
        <div class="d-flex justify-content-between mt-1" style="font-size:.78rem;color:#6b7280;">
          <span><?= number_format($kpi['resolved']) ?> diselesaikan</span>
          <span><?= number_format($kpi['unresolved']) ?> masih belum dari <?= number_format($kpi['diff']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Charts: Daily Activity + Area Breakdown ── -->
    <div class="row g-3 mb-4">
      <div class="col-md-7">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0"><i class="ti ti-chart-bar me-2"></i>Aktivitas Harian (14 Hari Terakhir)</h5>
          </div>
          <div class="card-body">
            <div id="chartDaily"></div>
          </div>
        </div>
      </div>
      <div class="col-md-5">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0"><i class="ti ti-chart-donut me-2"></i>Status per Area</h5>
          </div>
          <div class="card-body">
            <div id="chartArea"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Progress per PIC ── -->
    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="ti ti-users me-2"></i>Progress per PIC</h5>
        <span class="badge bg-primary"><?= count($picRows) ?> PIC</span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Nama PIC</th>
                <th>NRP</th>
                <th class="text-center">Total</th>
                <th class="text-center">Dihitung</th>
                <th style="min-width:130px;">Progress</th>
                <th class="text-center">Selisih</th>
                <th class="text-center">Belum Resolved</th>
                <th>Aktivitas Terakhir</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($picRows)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada data PIC</td></tr>
              <?php else: foreach ($picRows as $p):
                $pTotal   = (int)($p['total']      ?? 0);
                $pCounted = (int)($p['counted']    ?? 0);
                $pDiff    = (int)($p['diff_count'] ?? 0);
                $pUnres   = (int)($p['unresolved'] ?? 0);
                $pPct     = $pTotal > 0 ? round($pCounted / $pTotal * 100) : 0;
                $barClass = $pPct >= 100 ? 'bg-success' : ($pPct >= 50 ? 'bg-primary' : 'bg-warning');
              ?>
              <tr class="pic-row">
                <td class="fw-500"><?= htmlspecialchars($p['name']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars($p['nrp']) ?></td>
                <td class="text-center"><?= number_format($pTotal) ?></td>
                <td class="text-center"><?= number_format($pCounted) ?></td>
                <td>
                  <div class="progress progress-sm mb-1">
                    <div class="progress-bar <?= $barClass ?>" style="width:<?= $pPct ?>%"></div>
                  </div>
                  <span style="font-size:.7rem;color:#6b7280;"><?= $pPct ?>%</span>
                </td>
                <td class="text-center">
                  <?php if ($pDiff > 0): ?>
                    <span class="badge badge-pending"><?= number_format($pDiff) ?></span>
                  <?php else: ?>
                    <span class="badge badge-done">0</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($pUnres > 0): ?>
                    <span class="badge badge-pending"><?= number_format($pUnres) ?></span>
                  <?php else: ?>
                    <span class="badge badge-done">0</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted small">
                  <?= $p['last_activity'] ? htmlspecialchars(date('d M Y H:i', strtotime($p['last_activity']))) : '-' ?>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── Progress per Area ── -->
    <div class="card mb-4">
      <div class="card-header">
        <h5 class="card-title mb-0"><i class="ti ti-layout-grid me-2"></i>Progress per Area</h5>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <?php if (empty($areaRows)): ?>
          <div class="col-12 text-center text-muted py-4">Tidak ada data area</div>
          <?php else: foreach ($areaRows as $a):
            $aTotal   = (int)($a['total']      ?? 0);
            $aCounted = (int)($a['counted']    ?? 0);
            $aDiff    = (int)($a['diff_count'] ?? 0);
            $aUnres   = (int)($a['unresolved'] ?? 0);
            $aPct     = $aTotal > 0 ? round($aCounted / $aTotal * 100) : 0;
            $aBarClass = $aPct >= 100 ? 'bg-success' : ($aPct >= 50 ? 'bg-primary' : 'bg-warning');
          ?>
          <div class="col-sm-6 col-lg-4">
            <div class="card border shadow-none h-100">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <span class="fw-600 fs-5"><?= htmlspecialchars($a['area'] ?? '-') ?></span>
                  <span class="badge bg-light text-dark"><?= $aPct ?>%</span>
                </div>
                <div class="progress progress-sm mb-2">
                  <div class="progress-bar <?= $aBarClass ?>" style="width:<?= $aPct ?>%"></div>
                </div>
                <div class="row text-center" style="font-size:.78rem;">
                  <div class="col-4">
                    <div class="fw-600"><?= number_format($aTotal) ?></div>
                    <div class="text-muted">Total</div>
                  </div>
                  <div class="col-4">
                    <div class="fw-600 text-primary"><?= number_format($aCounted) ?></div>
                    <div class="text-muted">Dihitung</div>
                  </div>
                  <div class="col-4">
                    <div class="fw-600 <?= $aDiff > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($aDiff) ?></div>
                    <div class="text-muted">Selisih</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ── Top Differences + Recent Activity ── -->
    <div class="row g-3 mb-4">

      <!-- Top 10 selisih terbesar -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0"><i class="ti ti-arrow-autofit-height me-2"></i>Top 10 Selisih Terbesar</h5>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>#</th>
                    <th>Material</th>
                    <th>Area</th>
                    <th class="text-end">Sebelum</th>
                    <th class="text-end">Sesudah</th>
                    <th class="text-end">Selisih</th>
                    <th class="text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($topDiff)): ?>
                  <tr><td colspan="7" class="text-center text-muted py-3">Tidak ada selisih</td></tr>
                  <?php else: foreach ($topDiff as $i => $t):
                    $dq = (float)($t['diff_qty'] ?? 0);
                  ?>
                  <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>
                      <div class="fw-500 small"><?= htmlspecialchars($t['material']) ?></div>
                      <div class="text-muted" style="font-size:.7rem;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                           title="<?= htmlspecialchars($t['material_description'] ?? '') ?>">
                        <?= htmlspecialchars(substr($t['material_description'] ?? '', 0, 40)) ?>
                      </div>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($t['area']) ?></span></td>
                    <td class="text-end small"><?= number_format((float)($t['available_stock'] ?? 0), 2) ?></td>
                    <td class="text-end small"><?= number_format((float)($t['new_available_stock'] ?? 0), 2) ?></td>
                    <td class="text-end <?= $dq < 0 ? 'diff-neg' : 'diff-pos' ?>">
                      <?= ($dq > 0 ? '+' : '') . number_format($dq, 2) ?>
                    </td>
                    <td class="text-center">
                      <?php if ($t['resolved_at']): ?>
                        <span class="badge badge-done">Resolved</span>
                      <?php else: ?>
                        <span class="badge badge-pending">Pending</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- Aktivitas terbaru -->
      <div class="col-lg-6">
        <div class="card h-100">
          <div class="card-header">
            <h5 class="card-title mb-0"><i class="ti ti-clock me-2"></i>Aktivitas Terbaru</h5>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Material</th>
                    <th>Area</th>
                    <th>PIC</th>
                    <th class="text-end">Qty Baru</th>
                    <th>Waktu</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($recentActivity)): ?>
                  <tr><td colspan="5" class="text-center text-muted py-3">Tidak ada aktivitas</td></tr>
                  <?php else: foreach ($recentActivity as $r): ?>
                  <tr>
                    <td>
                      <div class="fw-500 small"><?= htmlspecialchars($r['material']) ?></div>
                      <div class="text-muted" style="font-size:.7rem;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                           title="<?= htmlspecialchars($r['material_description'] ?? '') ?>">
                        <?= htmlspecialchars(substr($r['material_description'] ?? '', 0, 35)) ?>
                      </div>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['area']) ?></span></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['pic_name'] ?? '-') ?></td>
                    <td class="text-end small fw-500">
                      <?= $r['new_available_stock'] !== null ? number_format((float)$r['new_available_stock'], 2) : '-' ?>
                    </td>
                    <td class="small text-muted" style="white-space:nowrap;">
                      <?= htmlspecialchars(date('d M H:i', strtotime($r['created_at']))) ?>
                    </td>
                  </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Short vs Over Summary ── -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="card border-danger">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-danger text-white" style="width:52px;height:52px;flex-shrink:0;">
              <i class="ti ti-trending-down" style="font-size:1.4rem;"></i>
            </div>
            <div>
              <div style="font-size:1.8rem;font-weight:700;color:#b91c1c;"><?= number_format($kpi['short']) ?></div>
              <div class="text-muted small">Parts Kurang (Short)</div>
              <div class="text-muted" style="font-size:.72rem;">Qty aktual lebih kecil dari catatan</div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="card border-success">
          <div class="card-body d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-success text-white" style="width:52px;height:52px;flex-shrink:0;">
              <i class="ti ti-trending-up" style="font-size:1.4rem;"></i>
            </div>
            <div>
              <div style="font-size:1.8rem;font-weight:700;color:#065f46;"><?= number_format($kpi['over']) ?></div>
              <div class="text-muted small">Parts Lebih (Over)</div>
              <div class="text-muted" style="font-size:.72rem;">Qty aktual lebih besar dari catatan</div>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /pc-content -->
</div><!-- /pc-container -->

<?php include __DIR__ . '/layouts/footer.html'; ?>
<?php include __DIR__ . '/layouts/scripts.html'; ?>

<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
/* ── Daily Activity Chart ── */
(function(){
  var labels = <?= json_encode($dailyLabels) ?>;
  var counts = <?= json_encode($dailyCounts) ?>;
  new ApexCharts(document.querySelector('#chartDaily'), {
    chart: { type: 'bar', height: 230, toolbar: { show: false }, fontFamily: 'Poppins, sans-serif' },
    series: [{ name: 'Parts Dihitung', data: counts }],
    xaxis: { categories: labels, labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { style: { fontSize: '11px' } } },
    colors: ['#f97316'],
    plotOptions: { bar: { borderRadius: 4, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f0f0f0' },
    tooltip: { theme: 'light' }
  }).render();
})();

/* ── Area Donut Chart ── */
(function(){
  var areaNames  = <?= json_encode(array_column($areaRows, 'area')) ?>;
  var areaCounts = <?= json_encode(array_map(fn($a) => (int)$a['counted'], $areaRows)) ?>;
  if (!areaNames.length) {
    document.querySelector('#chartArea').innerHTML =
      '<p class="text-center text-muted mt-4">Tidak ada data area</p>';
    return;
  }
  new ApexCharts(document.querySelector('#chartArea'), {
    chart: { type: 'donut', height: 230, fontFamily: 'Poppins, sans-serif' },
    series: areaCounts,
    labels: areaNames,
    colors: ['#ea580c','#fb923c','#f59e0b','#fdba74','#c2410c','#fed7aa'],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: true, style: { fontSize: '11px' } },
    tooltip: { theme: 'light' }
  }).render();
})();
</script>
</body>
</html>
