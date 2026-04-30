<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';

$allowed = ['login.php','setup_users_table.php','logout.php','pic_stock_taking.php'];
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$isLogged = !empty($_SESSION['user_id']) || !empty($_SESSION['pic_id']);
if (!in_array($script, $allowed, true) && !$isLogged) {
    header('Location: login.php');
    exit;
}

function format_diff_value($systemStock, $actualStock) {
    $a = trim((string)$systemStock);
    $b = trim((string)$actualStock);
    if ($a === '' && $b === '') return '-';
    if ($a === $b) return '0';

    $isNumA = preg_match('/^-?\d+(\.\d+)?$/', $a);
    $isNumB = preg_match('/^-?\d+(\.\d+)?$/', $b);
    if ($isNumA && $isNumB) {
        $d = (float)$b - (float)$a;
        return ($d > 0 ? '+' : '') . rtrim(rtrim(number_format($d, 4, '.', ''), '0'), '.');
    }
    return 'Changed';
}

function fetch_monitoring_rows(PDO $pdo, $whereClause) {
    $sql = "
        SELECT
            s.id,
            s.material,
            s.material_description,
            s.type,
            s.storage_bin,
            s.available_stock,
            s.new_available_stock,
            s.inventory_number,
            s.created_at,
            s.new_storage_bin,
            s.resolved_at AS stock_resolved_at,
            s.resolution_notes,
            p.name AS pic_name,
            pn.note AS tracking_note,
            pn.created_at AS tracking_created_at,
            pn.resolved_at AS tracking_resolved_at,
            pn.resolution_notes AS tracking_resolution_notes
        FROM stock_taking s
        LEFT JOIN pic p ON p.id = s.assigned_pic_id
        LEFT JOIN (
            SELECT n1.*
            FROM physical_notes n1
            INNER JOIN (
                SELECT stock_taking_id, MAX(id) AS max_id
                FROM physical_notes
                GROUP BY stock_taking_id
            ) latest ON latest.max_id = n1.id
        ) pn ON pn.stock_taking_id = s.id
        WHERE $whereClause
        ORDER BY s.created_at DESC, s.id DESC
    ";

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// Klasifikasi sesuai arahan: TSP (TM1/TM2 dengan area tertentu), UTPE (001/002/007 non-TSP).
$tspWhere = "((s.type = 'TM1' AND s.area IN ('001','002','003')) OR (s.type = 'TM2' AND s.area IN ('001','002','003','008')))";
$utpeWhere = "(s.area IN ('001','002','007') AND NOT ((s.type = 'TM1' AND s.area IN ('001','002','003')) OR (s.type = 'TM2' AND s.area IN ('001','002','003','008'))))";

$tspRows = fetch_monitoring_rows($pdo, $tspWhere);
$utpeRows = fetch_monitoring_rows($pdo, $utpeWhere);

$monitoringRows = [];
foreach ($tspRows as $row) {
  $row['_category'] = 'tsp';
  $monitoringRows[] = $row;
}
foreach ($utpeRows as $row) {
  $row['_category'] = 'utpe';
  $monitoringRows[] = $row;
}

usort($monitoringRows, function ($a, $b) {
  $ta = strtotime((string)($a['created_at'] ?? ''));
  $tb = strtotime((string)($b['created_at'] ?? ''));
  return $tb <=> $ta;
});
?>
<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
<?php include __DIR__ . '/layouts/preloader.html'; ?>
<?php include __DIR__ . '/layouts/sidebar.html'; ?>
<?php include __DIR__ . '/layouts/header.html'; ?>

<style>
  .section-card {
    border-radius: 14px;
    border: 1px solid rgba(251, 146, 60, .25);
    box-shadow: 0 10px 22px rgba(234, 88, 12, .10);
  }
  .section-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    padding: 14px 16px;
    border-bottom: 1px solid rgba(251, 146, 60, .22);
    background: linear-gradient(120deg, #fff7ed 0%, #ffedd5 100%);
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
  .table-wrap {
    overflow: auto;
    max-height: 70vh;
  }
  .monitoring-table { min-width: 1600px; }
  .monitoring-table th {
    white-space: nowrap;
    font-size: .74rem;
    text-transform: uppercase;
    letter-spacing: .35px;
    position: sticky;
    top: 0;
    z-index: 3;
    background: #f8fafc;
    box-shadow: inset 0 -1px 0 rgba(148, 163, 184, 0.35);
  }
  .monitoring-table td { font-size: .82rem; vertical-align: middle; }
  .desc-cell {
    max-width: 280px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .tracking-desc {
    max-width: 240px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .status-pill {
    border-radius: 999px;
    padding: 4px 10px;
    font-size: .72rem;
    font-weight: 700;
  }
  .status-open { background: #ffedd5; color: #9a3412; }
  .status-done { background: #dcfce7; color: #166534; }
  .status-none { background: #f1f5f9; color: #475569; }
  .diff-plus { color: #166534; font-weight: 700; }
  .diff-minus { color: #b91c1c; font-weight: 700; }
  .filter-card {
    border-radius: 12px;
    border: 1px solid rgba(251, 146, 60, .24);
    background: #fff;
    box-shadow: 0 8px 18px rgba(234, 88, 12, .08);
  }
  .filter-select {
    max-width: 220px;
  }
  .filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }
  .filter-bar .form-control,
  .filter-bar .form-select {
    font-size: .83rem;
    min-width: 140px;
    max-width: 220px;
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

  @media (max-width: 768px) {
    .section-head h5 { font-size: .95rem; }
  }
</style>

<div class="pc-container">
  <div class="pc-content">
    <div class="page-header">
      <div class="page-block">
        <div class="row align-items-center">
          <div class="col-md-12">
            <div class="page-header-title">
              <h5 class="m-b-10"><i class="ti ti-layout-grid me-2"></i>PST Monitoring</h5>
            </div>
            <ul class="breadcrumb">
              <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
              <li class="breadcrumb-item" aria-current="page">PST Monitoring</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <?php
      // Build unique PIC and Type lists for filter dropdowns
      $filterPics = [];
      $filterTypes = [];
      foreach ($monitoringRows as $_fr) {
        $p = trim((string)($_fr['pic_name'] ?? ''));
        $t = trim((string)($_fr['type'] ?? ''));
        if ($p !== '' && $p !== '-') $filterPics[$p] = true;
        if ($t !== '' && $t !== '-') $filterTypes[$t] = true;
      }
      ksort($filterPics);
      ksort($filterTypes);
    ?>
    <div class="card filter-card mb-3">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap" style="gap:8px;">
          <div>
            <strong>Monitoring Filters</strong>
            <div class="text-muted" style="font-size:.82rem;">Filter rows by multiple criteria simultaneously.</div>
          </div>
          <button id="btnClearFilters" class="btn btn-sm btn-outline-secondary"><i class="ti ti-x me-1"></i>Clear Filters</button>
        </div>
        <div class="filter-bar">
          <div>
            <div class="filter-label">Category</div>
            <select id="filterCategory" class="form-select">
              <option value="all" selected>All (TSP + UTPE)</option>
              <option value="tsp">TSP</option>
              <option value="utpe">UTPE</option>
            </select>
          </div>
          <div>
            <div class="filter-label">Search</div>
            <input id="filterSearch" type="text" class="form-control search-wide" placeholder="Material / Description...">
          </div>
          <div>
            <div class="filter-label">Type</div>
            <select id="filterType" class="form-select">
              <option value="">All Types</option>
              <?php foreach ($filterTypes as $ft => $_): ?>
                <option value="<?= htmlspecialchars($ft) ?>"><?= htmlspecialchars($ft) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <div class="filter-label">Tracking Status</div>
            <select id="filterStatus" class="form-select">
              <option value="">All Statuses</option>
              <option value="resolved">Resolved</option>
              <option value="open">Open</option>
              <option value="no tracking">No Tracking</option>
            </select>
          </div>
          <div>
            <div class="filter-label">PST PIC</div>
            <select id="filterPic" class="form-select">
              <option value="">All PICs</option>
              <?php foreach ($filterPics as $fp => $_): ?>
                <option value="<?= htmlspecialchars($fp) ?>"><?= htmlspecialchars($fp) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="card section-card mb-4" id="monitoringTableSection">
      <div class="section-head">
        <h5 class="mb-0">Monitoring Data</h5>
        <span class="section-pill" id="visibleCountBadge"><?= number_format(count($monitoringRows)) ?> rows</span>
      </div>
      <div class="table-wrap">
        <table class="table table-striped table-hover mb-0 monitoring-table">
          <thead class="table-light">
            <tr>
              <th>Material</th>
              <th>Material Description</th>
              <th>Type</th>
              <th>Storage</th>
              <th class="text-end">System Stock</th>
              <th class="text-end">Actual Stock</th>
              <th>Inventory Record</th>
              <th>Created Date</th>
              <th class="text-end">Difference</th>
              <th>PST PIC</th>
              <th>Relocation</th>
              <th>Tracking Date</th>
              <th>Tracking Description</th>
              <th>Tracking Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($monitoringRows)): ?>
              <tr><td colspan="14" class="text-center text-muted py-4">No monitoring data found.</td></tr>
            <?php else: foreach ($monitoringRows as $r):
              $category = $r['_category'] ?? 'tsp';
              $systemStock = $r['available_stock'] ?? '';
              $actualStock = $r['new_available_stock'] ?? '';
              $diffVal = format_diff_value($systemStock, $actualStock);
              $relocation = '-';
              if (!empty($r['new_storage_bin']) && trim((string)$r['new_storage_bin']) !== trim((string)($r['storage_bin'] ?? ''))) {
                $relocation = $r['new_storage_bin'];
              }

              $trackingDate = $r['tracking_resolved_at'] ?? $r['tracking_created_at'] ?? $r['stock_resolved_at'] ?? null;
              $trackingDesc = $r['tracking_resolution_notes'] ?? $r['tracking_note'] ?? $r['resolution_notes'] ?? '-';

              if (!empty($r['tracking_resolved_at']) || !empty($r['stock_resolved_at'])) {
                $statusClass = 'status-done';
                $statusText = 'Resolved';
              } elseif (!empty($r['tracking_note']) || !empty($r['resolution_notes'])) {
                $statusClass = 'status-open';
                $statusText = 'Open';
              } else {
                $statusClass = 'status-none';
                $statusText = 'No Tracking';
              }

              $diffClass = '';
              if ($diffVal !== '-' && $diffVal !== '0' && $diffVal !== 'Changed') {
                $diffClass = ((float)$diffVal < 0) ? 'diff-minus' : 'diff-plus';
              }
            ?>
              <tr data-category="<?= htmlspecialchars((string)$category) ?>"
                  data-type="<?= htmlspecialchars(strtolower((string)($r['type'] ?? ''))) ?>"
                  data-status="<?= htmlspecialchars(strtolower($statusText)) ?>"
                  data-pic="<?= htmlspecialchars(strtolower((string)($r['pic_name'] ?? ''))) ?>"
                  data-search="<?= htmlspecialchars(strtolower((string)($r['material'] ?? '')) . ' ' . strtolower((string)($r['material_description'] ?? ''))) ?>">
                <td><?= htmlspecialchars((string)($r['material'] ?? '-')) ?></td>
                <td class="desc-cell" title="<?= htmlspecialchars((string)($r['material_description'] ?? '-')) ?>"><?= htmlspecialchars((string)($r['material_description'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)($r['type'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)($r['storage_bin'] ?? '-')) ?></td>
                <td class="text-end"><?= htmlspecialchars((string)$systemStock) ?></td>
                <td class="text-end"><?= htmlspecialchars((string)($actualStock === '' ? '-' : $actualStock)) ?></td>
                <td><?= htmlspecialchars((string)($r['inventory_number'] ?? '-')) ?></td>
                <td><?= !empty($r['created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))) : '-' ?></td>
                <td class="text-end <?= $diffClass ?>"><?= htmlspecialchars((string)$diffVal) ?></td>
                <td><?= htmlspecialchars((string)($r['pic_name'] ?? '-')) ?></td>
                <td><?= htmlspecialchars((string)$relocation) ?></td>
                <td><?= $trackingDate ? htmlspecialchars(date('Y-m-d H:i', strtotime($trackingDate))) : '-' ?></td>
                <td class="tracking-desc" title="<?= htmlspecialchars((string)$trackingDesc) ?>"><?= htmlspecialchars((string)$trackingDesc) ?></td>
                <td><span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/layouts/footer.html'; ?>
<?php include __DIR__ . '/layouts/scripts.html'; ?>
<script>
  (function () {
    var tableSection = document.getElementById('monitoringTableSection');
    var countBadge  = document.getElementById('visibleCountBadge');
    if (!tableSection) return;

    var rows = Array.from(tableSection.querySelectorAll('tbody tr[data-category]'));

    var elCategory = document.getElementById('filterCategory');
    var elSearch   = document.getElementById('filterSearch');
    var elType     = document.getElementById('filterType');
    var elStatus   = document.getElementById('filterStatus');
    var elPic      = document.getElementById('filterPic');
    var elClear    = document.getElementById('btnClearFilters');

    function applyAll() {
      var cat    = elCategory ? elCategory.value : 'all';
      var search = elSearch   ? elSearch.value.trim().toLowerCase() : '';
      var type   = elType     ? elType.value.toLowerCase() : '';
      var status = elStatus   ? elStatus.value.toLowerCase() : '';
      var pic    = elPic      ? elPic.value.toLowerCase() : '';

      var visible = 0;
      rows.forEach(function (row) {
        var show = true;
        if (cat && cat !== 'all' && row.getAttribute('data-category') !== cat) show = false;
        if (show && search && !(row.getAttribute('data-search') || '').includes(search)) show = false;
        if (show && type   && (row.getAttribute('data-type')   || '') !== type)   show = false;
        if (show && status && (row.getAttribute('data-status') || '') !== status) show = false;
        if (show && pic    && !(row.getAttribute('data-pic')   || '').includes(pic)) show = false;
        row.style.display = show ? '' : 'none';
        if (show) visible += 1;
      });

      if (countBadge) countBadge.textContent = visible.toLocaleString('en-US') + ' rows';
    }

    [elCategory, elType, elStatus, elPic].forEach(function (el) {
      if (el) el.addEventListener('change', applyAll);
    });
    if (elSearch) {
      elSearch.addEventListener('input', applyAll);
    }
    if (elClear) {
      elClear.addEventListener('click', function () {
        if (elCategory) elCategory.value = 'all';
        if (elSearch)   elSearch.value   = '';
        if (elType)     elType.value     = '';
        if (elStatus)   elStatus.value   = '';
        if (elPic)      elPic.value      = '';
        applyAll();
      });
    }

    applyAll();
  })();
</script>
</body>
</html>
