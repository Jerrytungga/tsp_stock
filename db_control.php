<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// require admin
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
include 'db.php';

function download_sql_dump($pdo, $dbname) {
    $sql = "-- SQL Dump for database: {$dbname}\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    // Get tables
    $tables = [];
    $res = $pdo->query('SHOW TABLES');
    while ($r = $res->fetch(PDO::FETCH_NUM)) { $tables[] = $r[0]; }

    foreach ($tables as $table) {
        // CREATE TABLE
        $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        $create = $row['Create Table'] ?? $row['Create View'] ?? null;
        if ($create) {
            $sql .= "-- Table structure for `{$table}`\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create . ";\n\n";
        }
        // Data
        $stmt = $pdo->query("SELECT * FROM `{$table}`");
        $cols = [];
        $first = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) { $cols = array_keys($row); $first = false; }
            $vals = array_map(function($v) use ($pdo){ if (is_null($v)) return 'NULL'; return $pdo->quote($v); }, array_values($row));
            $sql .= "INSERT INTO `{$table}` (`".implode('`,`', $cols)."`) VALUES (".implode(',', $vals).");\n";
        }
        $sql .= "\n";
    }
    return $sql;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_all') {
    $dump = download_sql_dump($pdo, $pdo->query('select database()')->fetchColumn());
    $fname = 'pst_project_backup_'.date('Ymd_His').'.sql';
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    echo $dump; exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_table' && !empty($_GET['table'])) {
    $table = preg_replace('/[^a-zA-Z0-9_]/','', $_GET['table']);
    // CSV export
    $stmt = $pdo->query("SELECT * FROM `{$table}`");
    $fname = $table.'_'.date('Ymd_His').'.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    $out = fopen('php://output','w');
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) { fputcsv($out, array_keys($row)); $first = false; }
        fputcsv($out, array_values($row));
    }
    fclose($out); exit;
}

// List tables for UI
$tables = [];
try { $res = $pdo->query('SHOW TABLES'); while ($r = $res->fetch(PDO::FETCH_NUM)) $tables[] = $r[0]; } catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<?php include 'layouts/head.html'; ?>
<body>
<?php include 'layouts/preloader.html'; ?>
<?php include 'layouts/sidebar.html'; ?>
<?php include 'layouts/header.html'; ?>

<div class="pc-container"><div class="pc-content">
  <div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4>Database Control</h4>
      <div>
        <a href="admin_db.php" class="btn btn-outline-secondary btn-sm">User Admin</a>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-body d-flex justify-content-between align-items-center">
        <div>
          <h6 class="mb-0">Backup seluruh database</h6>
          <div class="small-muted">Download SQL dump berisi struktur dan data semua tabel.</div>
        </div>
        <div>
          <a href="?action=export_all" class="btn btn-primary">Export All (.sql)</a>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <h6>Tables</h6>
        <div class="table-responsive mt-2">
          <table class="table table-sm">
            <thead><tr><th>Table</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($tables as $t): ?>
                <tr>
                  <td><?php echo htmlspecialchars($t); ?></td>
                  <td>
                    <a class="btn btn-sm btn-outline-primary" href="?action=export_table&table=<?php echo urlencode($t); ?>">Export CSV</a>
                    <a class="btn btn-sm btn-outline-secondary" href="?action=export_all&table=<?php echo urlencode($t); ?>">Export SQL (full DB)</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div></div>

<?php include 'layouts/footer.html'; ?>
<?php include 'layouts/scripts.html'; ?>
</body>
</html>
