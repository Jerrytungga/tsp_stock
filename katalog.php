<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session early to prevent header issues
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Global auth guard: redirect to login.php if not authenticated.
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

// Ensure uploads directory exists
$uploadsDir = __DIR__ . '/uploads';
if (!is_dir($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
}

// Handle image upload via AJAX
if (isset($_POST['upload_image'])) {
    $partCode = isset($_POST['part_code']) ? trim($_POST['part_code']) : '';
    if ($partCode && isset($_FILES['image_file'])) {
        $file = $_FILES['image_file']['tmp_name'];
        $fileName = $_FILES['image_file']['name'];
        
        if ($file && is_uploaded_file($file)) {
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $safeExt = strtolower($ext);
            
            if (in_array($safeExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                // Save with part code as filename
                $newFileName = 'part_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode) . '.' . $safeExt;
                $newFilePath = $uploadsDir . '/' . $newFileName;
                
                if (move_uploaded_file($file, $newFilePath)) {
                    echo json_encode(['success' => true, 'message' => 'Gambar berhasil diupload', 'image_path' => 'uploads/' . $newFileName]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan gambar']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Format gambar tidak didukung']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'File tidak ditemukan']);
        }
    }
    exit;
}

// Handle Excel upload
if(isset($_POST['upload'])){
    $file = $_FILES['excel_file']['tmp_name'];
    if($file){
        require 'vendor/autoload.php';
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray();

        // Skip header row
        array_shift($data);

        // Generate table rows
        $new_tbody = '';
        $no = 1;
        foreach($data as $row){
          $dataCells = array_slice($row, 0, 13);
          $partCode = isset($dataCells[1]) ? trim((string)$dataCells[1]) : '';
          
          // Check if image exists for this part
          $imagePath = '';
          $safePartCode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $partCode);
          foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
              $checkPath = $uploadsDir . '/part_' . $safePartCode . '.' . $ext;
              if (file_exists($checkPath)) {
                  $imagePath = 'uploads/part_' . $safePartCode . '.' . $ext;
                  break;
              }
          }
          
          $fallbackImg = 'https://via.placeholder.com/80x80?text=No+Image';
          $imgSrc = $imagePath !== '' ? $imagePath : $fallbackImg;
          $imgTag = '<img src="' . htmlspecialchars($imgSrc) . '" alt="Gambar part" class="part-thumbnail" data-part-code="' . htmlspecialchars($partCode) . '" style="max-width:80px; max-height:80px; object-fit:cover; cursor:pointer;">';

          $new_tbody .= '<tr>';
          $new_tbody .= '<td>' . $no++ . '</td>';
          foreach($dataCells as $cell){
            $new_tbody .= '<td>' . htmlspecialchars($cell) . '</td>';
          }
          $new_tbody .= '<td>' . $imgTag . '</td>';
          $new_tbody .= '<td><button class="btn btn-sm btn-primary edit-btn" data-part-code="' . htmlspecialchars($partCode) . '">Edit</button><button class="btn btn-sm btn-danger">Hapus</button></td>';
          $new_tbody .= '</tr>';
        }

        $tbody_content = $new_tbody;
    } else {
        echo '<div class="alert alert-danger">Silakan pilih file.</div>';
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

  <!-- [ Main Content ] start -->
  <div class="pc-container">
    <div class="pc-content">
      <!-- [ breadcrumb ] start -->
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
      <!-- [ breadcrumb ] end -->

      <!-- [ Main Content ] start -->
      <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-sm-12">
          <div class="card">
            <div class="card-header" style="background-color: orange;">
              <h5>Daftar Katalog Part</h5>
            </div>
            <div class="card-body">
              <div class="mb-3  col-3">
                <form method="post" enctype="multipart/form-data">
                  <div class="input-group input-group-sm  col-3">
                    <input type="file" name="excel_file" class="form-control form-control-sm" accept=".xlsx,.xls" required>
                    <button type="submit" name="upload" class="btn btn-success btn-sm">Unggah Excel</button>
                  </div>
                </form>
              </div>
              <div class="mb-3">
                <a href="download_template.php" class="btn btn-info btn-sm">Unduh Template Excel</a>
                <a href="export_excel.php" class="btn btn-warning btn-sm">Unduh Data Excel</a>
              </div>
              <div class="table-responsive">
                <table id="katalog-table" class="table table-striped table-hover table-bordered" style="border-color: black;">
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
<?php if(isset($tbody_content)): ?>
<?php echo $tbody_content; ?>
<?php else: ?>
                    <tr>
                      <td colspan="16" class="text-center text-muted">Belum ada data. Unggah file Excel untuk mengisi katalog.</td>
                    </tr>
<?php endif; ?>
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
  
  <!-- Image Upload Modal -->
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

  <!-- DataTable Initialization -->
  <script>
    $(document).ready(function() {
      var table = $('#katalog-table').DataTable({
        scrollY: '400px',
        scrollCollapse: true,
        paging: false,
        fixedHeader: true
      });

      // Column search functionality
      $('.column_search').on('keyup change', function() {
        var columnIndex = $(this).data('column');
        table.column(columnIndex).search(this.value).draw();
      });

      // Open image upload modal on thumbnail click or edit button
      $(document).on('click', '.part-thumbnail, .edit-btn', function() {
        var partCode = $(this).data('part-code');
        $('#partCode').val(partCode);
        $('#imageUploadForm')[0].reset();
        $('#uploadMessage').html('');
        $('#imageUploadModal').modal('show');
      });

      // Handle image upload
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
                }, 1500);
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
