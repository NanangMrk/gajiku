<?php
// settings.php
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('manage_settings');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Pengaturan Sistem & Backup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_company') {
        $companyName = trim($_POST['company_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $pic = trim($_POST['person_in_charge'] ?? '');
        $paymentDay = (int)($_POST['payroll_payment_day'] ?? 25);
        if ($paymentDay < 1) $paymentDay = 1;
        if ($paymentDay > 31) $paymentDay = 31;

        if (!empty($companyName)) {
            $stmt = $db->prepare("
                UPDATE company_settings 
                SET company_name = ?, address = ?, phone = ?, email = ?, website = ?, person_in_charge = ?, payroll_payment_day = ?, updated_at = datetime('now', 'localtime')
                WHERE id = 1
            ");
            $stmt->execute([$companyName, $address, $phone, $email, $website, $pic, $paymentDay]);
            logAudit($user['id'], 'UPDATE_COMPANY_SETTINGS', 'Updated company profile details & payroll payment day');
            setFlashMessage('success', 'Pengaturan informasi perusahaan berhasil disimpan.');
        }
        header('Location: ' . BASE_URL . '/settings.php');
        exit;
    }

    if ($action === 'create_backup') {
        requirePermission('backup_system');
        $backupDir = STORAGE_PATH . '/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = 'payroll_backup_' . date('Y-m-d_Hi') . '.sqlite';
        $destPath = $backupDir . '/' . $filename;

        if (copy(DATABASE_PATH, $destPath)) {
            $fileSize = filesize($destPath);
            $stmtIns = $db->prepare("INSERT INTO database_backups (filename, file_path, file_size, created_by) VALUES (?, ?, ?, ?)");
            $stmtIns->execute([$filename, $destPath, $fileSize, $user['id']]);

            logAudit($user['id'], 'CREATE_DB_BACKUP', 'Created database backup: ' . $filename);
            setFlashMessage('success', 'Backup database berhasil dibuat: ' . e($filename));
        } else {
            setFlashMessage('danger', 'Gagal membuat file backup database.');
        }
        header('Location: ' . BASE_URL . '/settings.php#backup-pane');
        exit;
    }

    if ($action === 'restore_backup') {
        requirePermission('backup_system');
        if ($user['role_slug'] !== 'super_admin') {
            setFlashMessage('danger', 'Hanya Super Admin yang diizinkan melakukan Restore Database.');
            header('Location: ' . BASE_URL . '/settings.php');
            exit;
        }

        $backupId = (int)($_POST['backup_id'] ?? 0);
        $stmtB = $db->prepare("SELECT * FROM database_backups WHERE id = ?");
        $stmtB->execute([$backupId]);
        $backup = $stmtB->fetch();

        if ($backup && file_exists($backup['file_path'])) {
            $safetyFilename = 'safety_backup_before_restore_' . date('Y-m-d_Hi') . '.sqlite';
            copy(DATABASE_PATH, STORAGE_PATH . '/backups/' . $safetyFilename);

            if (copy($backup['file_path'], DATABASE_PATH)) {
                logAudit($user['id'], 'RESTORE_DB_BACKUP', 'Restored database from file: ' . $backup['filename']);
                setFlashMessage('success', 'Database berhasil di-restore dari backup ' . e($backup['filename']));
            } else {
                setFlashMessage('danger', 'Gagal memulihkan database dari file backup.');
            }
        }
        header('Location: ' . BASE_URL . '/settings.php');
        exit;
    }
}

if (isset($_GET['download']) && hasPermission('backup_system')) {
    $bId = (int)$_GET['download'];
    $stmtB = $db->prepare("SELECT * FROM database_backups WHERE id = ?");
    $stmtB->execute([$bId]);
    $bFile = $stmtB->fetch();

    if ($bFile && file_exists($bFile['file_path'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $bFile['filename'] . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($bFile['file_path']));
        readfile($bFile['file_path']);
        exit;
    }
}

$company = $db->query("SELECT * FROM company_settings WHERE id = 1")->fetch();
$backups = $db->query("
    SELECT db.*, u.username as creator_name
    FROM database_backups db
    LEFT JOIN users u ON db.created_by = u.id
    ORDER BY db.id DESC
")->fetchAll();

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4" role="alert">
          <i class="fa-solid fa-circle-info me-2"></i> <?= e($flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-0">Pengaturan Sistem</h3>
          <p class="text-muted mb-0">Pengaturan identitas perusahaan dan pemeliharaan backup database SQLite</p>
        </div>
      </div>

      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
          <ul class="nav nav-tabs border-bottom mb-4" id="settingsTab" role="tablist">
            <li class="nav-item">
              <button class="nav-link active font-weight-bold" id="company-tab" data-bs-toggle="tab" data-bs-target="#company-pane">
                <i class="fa-solid fa-building me-2"></i> Perusahaan &amp; Slip
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link font-weight-bold" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup-pane">
                <i class="fa-solid fa-database me-2"></i> Backup &amp; Restore SQLite
              </button>
            </li>
          </ul>

          <div class="tab-content">
            <div class="tab-pane fade show active" id="company-pane">
              <form action="<?= BASE_URL ?>/settings.php" method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_company">

                <div class="row">
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Nama Perusahaan</label>
                    <input type="text" name="company_name" class="form-control" value="<?= e($company['company_name'] ?? '') ?>" required>
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Penanggung Jawab (TTD Slip)</label>
                    <input type="text" name="person_in_charge" class="form-control" value="<?= e($company['person_in_charge'] ?? '') ?>" placeholder="Manajer HRD / Direktur">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Nomor Telepon / Fax</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($company['phone'] ?? '') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Email Perusahaan</label>
                    <input type="email" name="email" class="form-control" value="<?= e($company['email'] ?? '') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Website</label>
                    <input type="url" name="website" class="form-control" value="<?= e($company['website'] ?? '') ?>">
                  </div>
                  <div class="col-md-6 mb-3">
                    <label class="form-label font-weight-bold">Tanggal Penggajian Bulanan</label>
                    <select name="payroll_payment_day" class="form-select form-control" required>
                      <?php for ($i = 1; $i <= 31; $i++): ?>
                        <option value="<?= $i ?>" <?= (isset($company['payroll_payment_day']) && (int)$company['payroll_payment_day'] === $i) ? 'selected' : ($i === 25 ? 'selected' : '') ?>>Tanggal <?= $i ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                  <div class="col-md-12 mb-3">
                    <label class="form-label font-weight-bold">Alamat Perusahaan (Untuk Slip Gaji)</label>
                    <textarea name="address" class="form-control" rows="3"><?= e($company['address'] ?? '') ?></textarea>
                  </div>
                </div>

                <button type="submit" class="btn btn-primary rounded-pill px-4 font-weight-bold mt-2">
                  <i class="fa-solid fa-floppy-disk me-2"></i> Simpan Pengaturan
                </button>
              </form>
            </div>

            <div class="tab-pane fade" id="backup-pane">
              <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                  <h5 class="font-weight-bold text-dark mb-1">Manajemen Backup SQLite Database</h5>
                  <p class="text-muted small mb-0">Lokasi file database utama: <code><?= DATABASE_PATH ?></code></p>
                </div>
                <form action="<?= BASE_URL ?>/settings.php" method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="create_backup">
                  <button type="submit" class="btn btn-success rounded-pill font-weight-bold">
                    <i class="fa-solid fa-cloud-arrow-down me-1"></i> Buat Backup Baru Now
                  </button>
                </form>
              </div>

              <?php if (empty($backups)): ?>
                <div class="text-center py-4 text-muted">Belum ada file backup tersimpan.</div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Nama File Backup</th>
                        <th>Ukuran File</th>
                        <th>Waktu Pembuatan</th>
                        <th>Dibuat Oleh</th>
                        <th>Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($backups as $b): ?>
                        <tr>
                          <td class="font-weight-bold"><code><?= e($b['filename']) ?></code></td>
                          <td><?= number_format($b['file_size'] / 1024, 2) ?> KB</td>
                          <td><?= formatDateTimeIndo($b['created_at']) ?></td>
                          <td><small><?= e($b['creator_name']) ?></small></td>
                          <td>
                            <a href="<?= BASE_URL ?>/settings.php?download=<?= $b['id'] ?>" class="btn btn-xs btn-primary rounded-pill me-1">
                              <i class="fa-solid fa-download me-1"></i> Download
                            </a>

                            <?php if ($user['role_slug'] === 'super_admin'): ?>
                              <form action="<?= BASE_URL ?>/settings.php" method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore_backup">
                                <input type="hidden" name="backup_id" value="<?= $b['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="return confirm('PERHATIAN SANGAT PENTING: Restore akan menimpa seluruh database aktif saat ini. Sistem akan otomatis membuat safety backup terlebih dahulu. Lanjutkan restore?')">
                                  <i class="fa-solid fa-rotate-left me-1"></i> Restore DB
                                </button>
                              </form>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

        </div>
      </div>

    </div>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
