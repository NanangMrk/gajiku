<?php
// profile.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Profil Saya';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');

        if (empty($username) || empty($email)) {
            setFlashMessage('danger', 'Username dan Email wajib diisi.');
            header('Location: ' . BASE_URL . '/profile.php');
            exit;
        }

        try {
            // Check username uniqueness
            $stmtUniq = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
            $stmtUniq->execute([$username, $user['id']]);
            if ((int)$stmtUniq->fetchColumn() > 0) {
                throw new Exception('Username sudah digunakan oleh akun lain.');
            }

            $db->beginTransaction();

            $stmtUser = $db->prepare("UPDATE users SET username = ?, email = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
            $stmtUser->execute([$username, $email, $user['id']]);

            $_SESSION['auth_user']['username'] = $username;
            $_SESSION['auth_user']['email'] = $email;

            if ($user['employee_id']) {
                $stmtEmp = $db->prepare("UPDATE employees SET email = ?, phone = ?, address = ?, bank_name = ?, account_number = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                $stmtEmp->execute([$email, $phone, $address, $bankName, $accountNumber, $user['employee_id']]);
            }

            $db->commit();
            logAudit($user['id'], 'UPDATE_PROFILE', 'Updated profile & credentials');
            setFlashMessage('success', 'Informasi profil & akun berhasil diperbarui.');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlashMessage('danger', 'Gagal memperbarui profil: ' . $e->getMessage());
        }
        header('Location: ' . BASE_URL . '/profile.php');
        exit;
    }

    if ($action === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (empty($oldPass) || empty($newPass) || empty($confirmPass)) {
            setFlashMessage('danger', 'Seluruh kolom password wajib diisi.');
        } elseif ($newPass !== $confirmPass) {
            setFlashMessage('danger', 'Konfirmasi password baru tidak cocok.');
        } elseif (strlen($newPass) < 6) {
            setFlashMessage('danger', 'Password baru minimal 6 karakter.');
        } else {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $currentHash = $stmt->fetchColumn();

            if (password_verify($oldPass, $currentHash)) {
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmtUp = $db->prepare("UPDATE users SET password_hash = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                $stmtUp->execute([$newHash, $user['id']]);

                logAudit($user['id'], 'CHANGE_PASSWORD', 'Changed password');
                setFlashMessage('success', 'Password berhasil diubah.');
            } else {
                setFlashMessage('danger', 'Password lama tidak sesuai.');
            }
        }
        header('Location: ' . BASE_URL . '/profile.php');
        exit;
    }
}

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

      <div class="row">
        <!-- User Summary Card -->
        <div class="col-md-4 grid-margin stretch-card">
          <div class="card shadow-sm border-0 rounded-4 text-center">
            <div class="card-body p-4">
              <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3 shadow" style="width: 100px; height: 100px; font-size: 2.5rem; font-weight: bold;">
                <?= strtoupper(substr($user['username'], 0, 1)) ?>
              </div>
              <h4 class="font-weight-bold mb-1"><?= e($user['employee_name'] ?? $user['username']) ?></h4>
              <p class="text-primary font-weight-bold mb-2"><?= e($user['role_name']) ?></p>
              
              <div class="text-start border-top pt-3 mt-3">
                <div class="mb-2">
                  <small class="text-muted d-block">NIK Internal</small>
                  <strong><?= e($user['nik'] ?? '-') ?></strong>
                </div>
                <div class="mb-2">
                  <small class="text-muted d-block">Divisi &amp; Tim</small>
                  <strong><?= e($user['division_name'] ?? '-') ?> / <?= e($user['team_name'] ?? '-') ?></strong>
                </div>
                <div class="mb-2">
                  <small class="text-muted d-block">Jabatan</small>
                  <strong><?= e($user['position'] ?? '-') ?></strong>
                </div>
                <div class="mb-2">
                  <small class="text-muted d-block">Email</small>
                  <strong><?= e($user['email']) ?></strong>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Edit Profile & Security Forms -->
        <div class="col-md-8 grid-margin stretch-card">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
              
              <ul class="nav nav-tabs border-bottom mb-4" id="profileTab" role="tablist">
                <li class="nav-item">
                  <button class="nav-link active font-weight-bold" id="info-tab" data-bs-toggle="tab" data-bs-target="#info-pane">
                    <i class="fa-solid fa-user me-2"></i> Informasi Kontak
                  </button>
                </li>
                <li class="nav-item">
                  <button class="nav-link font-weight-bold text-danger" id="pass-tab" data-bs-toggle="tab" data-bs-target="#pass-pane">
                    <i class="fa-solid fa-key me-2"></i> Ubah Password
                  </button>
                </li>
              </ul>

              <div class="tab-content">
                <!-- Info Tab -->
                <div class="tab-pane fade show active" id="info-pane">
                  <form action="<?= BASE_URL ?>/profile.php" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                      <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" value="<?= e($user['username'] ?? '') ?>" required>
                      </div>
                      <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">Alamat Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
                      </div>
                      <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">Nomor Telepon</label>
                        <input type="text" name="phone" class="form-control" value="<?= e($user['phone'] ?? '') ?>">
                      </div>
                      <div class="col-md-6 mb-3">
                        <label class="form-label font-weight-bold">Nama Bank</label>
                        <input type="text" name="bank_name" class="form-control" value="<?= e($user['bank_name'] ?? '') ?>">
                      </div>
                      <div class="col-md-12 mb-3">
                        <label class="form-label font-weight-bold">Nomor Rekening</label>
                        <input type="text" name="account_number" class="form-control" value="<?= e($user['account_number'] ?? '') ?>">
                      </div>
                      <div class="col-md-12 mb-3">
                        <label class="form-label font-weight-bold">Alamat Lengkap</label>
                        <textarea name="address" class="form-control" rows="3"><?= e($user['address'] ?? '') ?></textarea>
                      </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary rounded-pill px-4 font-weight-bold mt-2">
                      <i class="fa-solid fa-floppy-disk me-2"></i> Simpan Perubahan
                    </button>
                  </form>
                </div>

                <!-- Password Tab -->
                <div class="tab-pane fade" id="pass-pane">
                  <form action="<?= BASE_URL ?>/profile.php" method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                      <label class="form-label font-weight-bold">Password Saat Ini</label>
                      <input type="password" name="old_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                      <label class="form-label font-weight-bold">Password Baru</label>
                      <input type="password" name="new_password" class="form-control" required placeholder="Minimal 6 karakter">
                    </div>

                    <div class="mb-4">
                      <label class="form-label font-weight-bold">Konfirmasi Password Baru</label>
                      <input type="password" name="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-danger rounded-pill px-4 font-weight-bold">
                      <i class="fa-solid fa-shield-halved me-2"></i> Ubah Password
                    </button>
                  </form>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

    </div>
<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
