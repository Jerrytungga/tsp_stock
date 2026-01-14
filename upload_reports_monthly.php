<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
include 'db.php';
// require login
if (empty($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}

$error = '';
$success = '';

// handle flash messages
if (isset($_SESSION['success'])) {
  $success = $_SESSION['success'];
  unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
  $error = $_SESSION['error'];
  unset($_SESSION['error']);
}

// base upload directory (monthly)
$baseUploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . 'monthly';
if (!is_dir($baseUploadDir)) mkdir($baseUploadDir, 0755, true);

// handle POST actions (delete_file, upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? 'upload');

  if ($action === 'delete_file') {
    $file = trim((string)($_POST['file'] ?? ''));
    $fileSan = str_replace(['..', "\\"], '', $file);
    $fileSan = preg_replace('/[^0-9A-Za-z_\-\.\/]/', '', $fileSan);
    $fullBase = realpath($baseUploadDir);
    $targetPath = $fullBase ? realpath($baseUploadDir . DIRECTORY_SEPARATOR . $fileSan) : false;
    if (!$targetPath || strpos($targetPath, $fullBase) !== 0) {
      $error = 'File not found or access denied.';
    } else {
      if (is_file($targetPath) && unlink($targetPath)) {
        try {
          $del = $pdo->prepare('DELETE FROM report_files WHERE filename = ? LIMIT 1');
          $del->execute([$fileSan]);
        } catch (Exception $e) {}
        $_SESSION['success'] = 'File deleted successfully.';
      } else {
        $_SESSION['error'] = 'Failed to delete file.';
      }
    }

  } elseif ($action === 'upload') {
    $report_month = trim((string)($_POST['report_month'] ?? ''));
    if ($report_month === '') {
      $error = 'Select report month.';
    } elseif (!isset($_FILES['report_file'])) {
      $error = 'No file uploaded.';
    } else {
      $f = $_FILES['report_file'];
      if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed (code: ' . $f['error'] . ').';
      } elseif ($f['size'] > 20 * 1024 * 1024) {
        $error = 'File too large (max 20MB).';
      } else {
        $tmp = $f['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        // allow PDF and Excel/CSV by extension (mime detection for Excel can vary)
        $allowedExts = ['pdf','xlsx','xls','csv'];
        if (!in_array($ext, $allowedExts, true)) {
          $error = 'Only PDF or Excel files (xls, xlsx, csv) are allowed.';
        } else {
          $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($f['name']));
          $newName = $report_month . '_' . time() . '_' . bin2hex(random_bytes(5)) . '_' . $safe;

          // auto-create folder based on report_month
          $monthDir = $baseUploadDir . DIRECTORY_SEPARATOR . $report_month;
          if (!is_dir($monthDir)) {
            mkdir($monthDir, 0755, true);
          }
          if (move_uploaded_file($tmp, $dest = $monthDir . DIRECTORY_SEPARATOR . $newName)) {
            $relUrl = 'uploads/reports/monthly/' . $report_month . '/' . $newName;
            $desc = trim((string)($_POST['description'] ?? '')) ?: null;
            try {
              $pdo->exec("CREATE TABLE IF NOT EXISTS report_files (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                report_month VARCHAR(7) NOT NULL,
                description TEXT DEFAULT NULL,
                uploaded_by INT DEFAULT NULL,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
              $storedFilename = $report_month . '/' . $newName;
              $ins = $pdo->prepare('INSERT INTO report_files (filename, original_name, report_month, description, uploaded_by) VALUES (?, ?, ?, ?, ?)');
              $ins->execute([$storedFilename, $f['name'], $report_month, $desc, (int)$_SESSION['user_id']]);
              $_SESSION['success'] = 'Report uploaded successfully.';
              header('Location: upload_reports_monthly.php');
              exit;
            } catch (Exception $e) {
              $error = 'Upload successful but metadata failed to save.';
            }
          } else {
            $error = 'Failed to move file.';
          }
        }
      }
    }
  }
}

// fetch reports
try {
  $reports = $pdo->query('SELECT rf.id, rf.filename, rf.original_name, rf.report_month, rf.description, rf.uploaded_at, u.name AS uploader FROM report_files rf LEFT JOIN users u ON u.id = rf.uploaded_by ORDER BY rf.report_month DESC, rf.uploaded_at DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $reports = [];
}
?>
<!doctype html>
<html lang="en">
<?php include 'layouts/head.html'; ?>
<body data-pc-preset="preset-1" data-pc-direction="ltr" data-pc-theme="light">
  <style>
    .page-header h4{font-weight:600}
    .table thead th{background:linear-gradient(#f8f9fa,#f1f3f5)}
    .table tbody tr:hover{background:#fbfbfd}
    .actions .btn{min-width:86px}
    .card{box-shadow:0 1px 4px rgba(15,15,15,.06)}
    .btn-outline-primary{border-radius:.35rem}
  </style>
  <?php include 'layouts/preloader.html'; ?>
  <?php include 'layouts/sidebar.html'; ?>
  <?php include 'layouts/header.html'; ?>

  <div class="pc-container">
    <div class="pc-content">
      <div class="page-header mb-3">
        <h4 class="mb-1">Upload Monthly Reports</h4>
          <div class="small-muted">Upload monthly report files (PDF / Excel)</div>
      </div>

      <div class="card">
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="action" value="upload">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Report Month</label>
                <input type="month" name="report_month" class="form-control" required>
              </div>
              <div class="col-md-5">
                <label class="form-label">File (PDF / Excel)</label>
                <input type="file" name="report_file" accept=".pdf,.xls,.xlsx,.csv,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Description</label>
                <input type="text" name="description" class="form-control" placeholder="Summary">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button class="btn btn-primary" type="submit">Upload Report</button>
            </div>
          </form>

          <h5 class="mb-3">Monthly Reports List</h5>
          <div class="table-responsive">
            <table class="table table-striped table-bordered">
              <thead>
                <tr>
                  <th style="width:60px">#</th>
                  <th>Month</th>
                  <th>File Name</th>
                  <th>Description</th>
                  <th>Uploaded By</th>
                  <th style="width:160px">Date</th>
                  <th style="width:120px">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($reports)): foreach ($reports as $r): ?>
                <tr>
                  <td><?php echo (int)$r['id']; ?></td>
                  <td><?php echo htmlspecialchars($r['report_month']); ?></td>
                  <td><a href="uploads/reports/monthly/<?php echo htmlspecialchars($r['filename']); ?>" target="_blank"><?php echo htmlspecialchars($r['original_name']); ?></a></td>
                  <td><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($r['uploader'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($r['uploaded_at']); ?></td>
                  <td class="actions">
                    <form method="post" class="delete-file-form" data-file="<?php echo htmlspecialchars($r['filename'], ENT_QUOTES); ?>" style="display:inline">
                      <input type="hidden" name="action" value="delete_file">
                      <input type="hidden" name="file" value="<?php echo htmlspecialchars($r['filename'], ENT_QUOTES); ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center text-muted">No reports yet.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php include 'layouts/footer.html'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // centralized confirmation for delete-file forms using SweetAlert
    document.querySelectorAll('.delete-file-form').forEach(function(frm){
      frm.addEventListener('submit', function(ev){
        ev.preventDefault();
        var fname = frm.dataset.file || '';
        Swal.fire({
          title: 'Delete file?',
          text: 'Are you sure you want to delete the file "' + fname + '"?',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          cancelButtonText: 'Cancel'
        }).then(function(res){ if (res.isConfirmed) frm.submit(); });
      });
    });

    // Show SweetAlert for success or error messages
    <?php if ($success): ?>
    Swal.fire({
      title: 'Success',
      text: '<?php echo addslashes($success); ?>',
      icon: 'success',
      confirmButtonText: 'OK'
    });
    <?php endif; ?>
    <?php if ($error): ?>
    Swal.fire({
      title: 'Error',
      text: '<?php echo addslashes($error); ?>',
      icon: 'error',
      confirmButtonText: 'OK'
    });
    <?php endif; ?>
  </script>
  <?php include 'layouts/scripts.html'; ?>
</body>
</html>
