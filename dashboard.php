<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// require admin login
if (empty($_SESSION['user_id'])) {
  header('Location: login.php'); exit;
}
include 'db.php';

$total_parts = 0; $parts_with_diff = 0; $stocked_count = 0; $total_sessions = 0;
$tm1_count = 0; $tm2_count = 0; $picProgress = []; $recentSessions = [];
try {
  $total_parts = (int)$pdo->query('SELECT COUNT(*) FROM stock_taking')->fetchColumn();
  $parts_with_diff = (int)$pdo->query("SELECT COUNT(*) FROM stock_taking WHERE (
    (new_available_stock IS NOT NULL AND new_available_stock != '' AND TRIM(new_available_stock) != TRIM(IFNULL(available_stock,'')))
    OR (new_storage_bin IS NOT NULL AND new_storage_bin != '' AND TRIM(new_storage_bin) != TRIM(IFNULL(storage_bin,'')))
  )")->fetchColumn();
  $stocked_count = (int)$pdo->query("SELECT COUNT(*) FROM stock_taking WHERE (new_available_stock IS NOT NULL AND new_available_stock != '') OR (new_storage_bin IS NOT NULL AND new_storage_bin != '')")->fetchColumn();
  $total_sessions = (int)$pdo->query("SELECT COUNT(DISTINCT DATE(created_at)) FROM stock_taking")->fetchColumn();
  $t = $pdo->query("SELECT SUM(area='TM1') AS tm1, SUM(area='TM2') AS tm2 FROM stock_taking")->fetch(PDO::FETCH_ASSOC);
  $tm1_count = (int)($t['tm1'] ?? 0); $tm2_count = (int)($t['tm2'] ?? 0);
  $picProgress = $pdo->query("SELECT p.id, p.name, COUNT(s.id) AS total, SUM((s.new_available_stock IS NOT NULL AND s.new_available_stock != '') OR (s.new_storage_bin IS NOT NULL AND s.new_storage_bin != '')) AS saved FROM pic p LEFT JOIN stock_taking s ON s.assigned_pic_id = p.id GROUP BY p.id ORDER BY p.name")->fetchAll(PDO::FETCH_ASSOC);
  $recentSessions = $pdo->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM stock_taking GROUP BY DATE(created_at) ORDER BY DATE(created_at) DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  // keep defaults on error
}

// Prepare monthly achievement data (last 12 months) - count of saved items per month
$monthly_labels = [];
$monthly_counts = [];
try {
  // Start chart from January 2026
  $start = new DateTime('first day of January 2026');
  $months = [];
  for ($i=0;$i<12;$i++){
    $ym = $start->format('Y-m');
    $months[] = $ym;
    $monthly_labels[] = $start->format('M Y');
    $start->modify('+1 month');
  }
  $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM stock_taking WHERE ((new_available_stock IS NOT NULL AND new_available_stock != '') OR (new_storage_bin IS NOT NULL AND new_storage_bin != '')) AND created_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH) GROUP BY ym ORDER BY ym ASC");
  $stmt->execute();
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $r) { $map[$r['ym']] = (int)$r['c']; }
  foreach ($months as $m) { $monthly_counts[] = isset($map[$m]) ? $map[$m] : 0; }
} catch (Exception $e) {
  // keep empty arrays on error
}

// Monthly target and this-month progress
$monthly_target = 3200;
$current_month_ym = date('Y-m');
try {
  $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM stock_taking WHERE ((new_available_stock IS NOT NULL AND new_available_stock != '') OR (new_storage_bin IS NOT NULL AND new_storage_bin != '')) AND DATE_FORMAT(created_at, '%Y-%m') = ?");
  $stmt2->execute([$current_month_ym]);
  $current_month_saved = (int)$stmt2->fetchColumn();
} catch (Exception $e) {
  $current_month_saved = 0;
}
$current_month_pct = $monthly_target > 0 ? round($current_month_saved / $monthly_target * 100, 1) : 0;
$target_series = array_fill(0, count($monthly_labels), $monthly_target);

// Daily target and today's progress
$daily_target = 150;
$today_ym = date('Y-m-d');
try {
  $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM stock_taking WHERE ((new_available_stock IS NOT NULL AND new_available_stock != '') OR (new_storage_bin IS NOT NULL AND new_storage_bin != '')) AND DATE(created_at) = ?");
  $stmt3->execute([$today_ym]);
  $today_saved = (int)$stmt3->fetchColumn();
} catch (Exception $e) {
  $today_saved = 0;
}
$today_pct = $daily_target > 0 ? round($today_saved / $daily_target * 100, 1) : 0;

$stocked_pct = $total_parts > 0 ? round($stocked_count / $total_parts * 100, 1) : 0;

// Build projection series: continue trend from current month towards monthly target
try {
  $months_list = [];
  $start2 = new DateTime('first day of January 2026');
  for ($i=0;$i<12;$i++){
    $months_list[] = $start2->format('Y-m');
    $start2->modify('+1 month');
  }
  $trend_series = [];
  $current_index = array_search($current_month_ym, $months_list);
  if ($current_index === false) $current_index = count($months_list)-1;
  $current_val = isset($monthly_counts[$current_index]) ? (float)$monthly_counts[$current_index] : 0;
  for ($i=0;$i<count($months_list);$i++){
    if ($i <= $current_index) {
      $trend_series[$i] = isset($monthly_counts[$i]) ? (int)$monthly_counts[$i] : 0;
    } else {
      $remaining = (count($months_list)-1) - $current_index;
      $step = $i - $current_index;
      if ($remaining > 0) {
        $trend_series[$i] = round($current_val + (($monthly_target - $current_val) * ($step / $remaining)), 1);
      } else {
        $trend_series[$i] = $monthly_target;
      }
    }
  }
} catch (Exception $e) {
  $trend_series = array_fill(0, count($monthly_labels), null);
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
        .hero { background: linear-gradient(90deg,#4f46e5,#06b6d4); color:#fff; padding:20px; border-radius:8px; margin-bottom:18px; }
        .stat { padding:18px; border-radius:8px; color:#fff; }
        .stat .value { font-size:1.6rem; font-weight:700; }
        .stat .label { opacity:0.9; }
        .card-compact { border-radius:8px; }
        .pic-table td, .pic-table th { vertical-align:middle; }
        .small-muted { color:#6b7280; }
        @media (max-width:576px){ .hero { text-align:center } }
      </style>

      <div class="pc-container"><div class="pc-content">
        <div class="hero d-flex justify-content-between align-items-center">
          <div>
            <h4 style="margin:0">Dasbor Inventaris</h4>
            <div class="small-muted">Ringkasan cepat stok dan progres PIC</div>
          </div>
          <div class="d-none d-sm-block text-end">
            <div class="small-muted">Diperbarui</div>
            <strong><?php echo date('Y-m-d H:i'); ?></strong>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-3 col-sm-6">
            <div class="stat bg-gradient-primary stat card-compact d-flex align-items-center" style="gap:12px;">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7h18M3 12h18M3 17h18" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <div>
                <div class="label">Total Bagian</div>
                <div class="value"><?php echo number_format($total_parts); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6">
            <div class="stat bg-gradient-danger stat card-compact d-flex align-items-center" style="gap:12px; background:linear-gradient(90deg,#ef4444,#f97316);">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <div>
                <div class="label">Bagian dengan Perbedaan</div>
                <div class="value"><?php echo number_format($parts_with_diff); ?></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6">
            <div class="stat bg-gradient-success stat card-compact d-flex align-items-center" style="gap:12px; background:linear-gradient(90deg,#10b981,#06b6d4);">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12l2 2 4-4" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <div>
                <div class="label">Distok</div>
                <div class="value"><?php echo $stocked_pct; ?>% <small class="small-muted">(<?php echo number_format($stocked_count); ?>)</small></div>
              </div>
            </div>
          </div>
          <div class="col-md-3 col-sm-6">
            <div class="stat bg-gradient-dark stat card-compact d-flex align-items-center" style="gap:12px; background:linear-gradient(90deg,#6d28d9,#0ea5a4);">
              <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 7V3m8 4V3M3 11h18M5 21h14a2 2 0 002-2V7H3v12a2 2 0 002 2z" stroke="#fff" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <div>
                <div class="label">Sesi</div>
                <div class="value"><?php echo number_format($total_sessions); ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3 mt-3">
          <div class="col-12">
            <div class="card card-compact mb-3">
              <div class="card-body">
                <h6 class="mb-2">Pencapaian 12 Bulan</h6>
                <div class="d-flex justify-content-between align-items-center mb-2" style="gap:12px;flex-wrap:wrap;">
                  <div class="small-muted">Target per bulan: <strong><?php echo number_format($monthly_target); ?></strong></div>
                  <div class="small-muted">Bulan ini: <strong><?php echo number_format($current_month_saved); ?></strong> (<strong><?php echo $current_month_pct; ?>%</strong>)</div>
                  <div class="small-muted">Hari ini: <strong><?php echo number_format($today_saved); ?></strong> / <?php echo number_format($daily_target); ?> (<strong><?php echo $today_pct; ?>%</strong>)</div>
                </div>
                <div style="height:220px;"><canvas id="achievementChart" style="width:100%;height:100%;"></canvas></div>
              </div>
            </div>
          </div>
        </div>

        <div class="row mt-3 g-3">
          <div class="col-lg-8">
            <div class="card card-compact">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h6 class="mb-0">Progres per PIC</h6>
                  <div class="d-flex" style="gap:8px;">
                    <input id="picSearch" class="form-control form-control-sm" placeholder="Cari PIC..." style="width:180px;" />
                  </div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm pic-table" id="picTable">
                    <thead><tr><th>PIC</th><th class="text-end">Total</th><th class="text-end">Disimpan</th><th class="text-end">Tertunda</th><th>Progres</th><th></th></tr></thead>
                    <tbody>
                      <?php if (!empty($picProgress)): foreach ($picProgress as $pp):
                        $total = (int)($pp['total'] ?? 0); $saved = (int)($pp['saved'] ?? 0); $pending = $total - $saved; $pct = $total>0?round($saved/$total*100,1):0; ?>
                        <tr>
                          <td><a href="pic_stock_taking.php?pic=<?php echo (int)$pp['id']; ?>"><?php echo htmlspecialchars($pp['name']); ?></a></td>
                          <td class="text-end"><?php echo number_format($total); ?></td>
                          <td class="text-end"><?php echo number_format($saved); ?></td>
                          <td class="text-end"><?php echo number_format($pending); ?></td>
                          <td style="width:160px;">
                            <div class="progress" style="height:8px;"><div class="progress-bar bg-info" role="progressbar" style="width:<?php echo $pct; ?>%"></div></div>
                            <small class="small-muted"><?php echo $pct; ?>%</small>
                          </td>
                          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="pic_stock_taking.php?pic=<?php echo (int)$pp['id']; ?>">Buka</a></td>
                        </tr>
                      <?php endforeach; else: ?>
                        <tr><td colspan="6" class="text-muted">Tidak ada PIC yang dikonfigurasi.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-compact mb-3">
              <div class="card-body">
                <h6>Jumlah TM</h6>
                <div class="d-flex justify-content-between align-items-center mt-2">
                  <div>
                    <div class="small-muted">TM1</div>
                    <div class="value"><?php echo number_format($tm1_count); ?></div>
                  </div>
                  <div>
                    <div class="small-muted">TM2</div>
                    <div class="value"><?php echo number_format($tm2_count); ?></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card card-compact">
              <div class="card-body">
                <h6>Sesi Terbaru</h6>
                <ul class="list-unstyled mt-2">
                  <?php if (!empty($recentSessions)): foreach ($recentSessions as $rs): ?>
                    <li class="d-flex justify-content-between small-muted"><span><?php echo htmlspecialchars($rs['d']); ?></span><strong><?php echo number_format($rs['c']); ?></strong></li>
                  <?php endforeach; else: ?><li class="text-muted">Belum ada sesi</li><?php endif; ?>
                </ul>
              </div>
            </div>
          </div>
        </div>

      </div></div>

      <?php include __DIR__ . '/layouts/footer.html'; ?>
      <?php include __DIR__ . '/layouts/scripts.html'; ?>
      <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
      <script>
        (function(){
          var labels = <?php echo json_encode($monthly_labels); ?>;
          var data = <?php echo json_encode($monthly_counts); ?>;
          var target = <?php echo json_encode($target_series); ?>;
          var projection = <?php echo json_encode($trend_series); ?>;
          var ctx = document.getElementById('achievementChart');
          if (!ctx) return;
          new Chart(ctx, {
            type: 'line',
            data: {
              labels: labels,
              datasets: [{
                label: 'Item tersimpan',
                data: data,
                fill: true,
                backgroundColor: 'rgba(59,130,246,0.12)',
                borderColor: '#3b82f6',
                tension: 0.35,
                pointRadius: 4,
                pointBackgroundColor: '#3b82f6'
              }, {
                label: 'Target Bulanan',
                data: target,
                type: 'line',
                borderColor: '#ef4444',
                borderDash: [6,4],
                fill: false,
                tension: 0
              }, {
                label: 'Proyeksi ke Target',
                data: projection,
                type: 'line',
                borderColor: '#10b981',
                borderDash: [4,4],
                backgroundColor: 'rgba(16,185,129,0.08)',
                fill: false,
                tension: 0.25,
                pointRadius: 3
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision:0 } } },
              plugins: { legend: { display: true } }
            }
          });
        })();
      </script>
      <script>
        (function(){
          var input = document.getElementById('picSearch');
          if (!input) return;
          input.addEventListener('input', function(){
            var q = this.value.trim().toLowerCase();
            var rows = document.querySelectorAll('#picTable tbody tr');
            rows.forEach(function(r){
              var first = r.querySelector('td');
              if (!first) return;
              var txt = first.innerText.trim().toLowerCase();
              r.style.display = (q === '' || txt.indexOf(q) !== -1) ? '' : 'none';
            });
          });
        })();
      </script>
      </body>
      </html>
