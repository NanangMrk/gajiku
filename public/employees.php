<?php
// employees.php
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('manage_employees');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Kelola Karyawan';

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_employee') {
        $nik = trim($_POST['nik'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $teamId = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
        $position = '';
        $joinDate = $_POST['join_date'] ?? date('Y-m-d');
        $basicSalary = (float)($_POST['basic_salary'] ?? 0);
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? 'emp123';

        if (!empty($nik) && !empty($fullName) && !empty($email)) {
            try {
                $db->beginTransaction();

                $passHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtUser = $db->prepare("INSERT INTO users (username, email, password_hash, role_id, status) VALUES (?, ?, ?, 3, 'active')");
                $stmtUser->execute([$username ?: strtolower(str_replace(' ', '', $fullName)), $email, $passHash]);
                $newUserId = (int)$db->lastInsertId();

                $stmtEmp = $db->prepare("
                    INSERT INTO employees (user_id, nik, full_name, email, phone, division_id, team_id, position, join_date, status, bank_name, account_number)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
                ");
                $stmtEmp->execute([$newUserId, $nik, $fullName, $email, $phone, $divisionId ?: null, $teamId, $position, $joinDate, $bankName, $accountNumber]);
                $newEmpId = (int)$db->lastInsertId();

                if ($basicSalary > 0) {
                    $stmtSal = $db->prepare("INSERT INTO salary_history (employee_id, basic_salary, effective_date, notes, created_by) VALUES (?, ?, ?, 'Gaji Awal', ?)");
                    $stmtSal->execute([$newEmpId, $basicSalary, $joinDate, $user['id']]);
                }

                if ($teamId) {
                    $stmtTm = $db->prepare("INSERT OR IGNORE INTO team_members (team_id, employee_id) VALUES (?, ?)");
                    $stmtTm->execute([$teamId, $newEmpId]);
                }

                $db->commit();
                logAudit($user['id'], 'ADD_EMPLOYEE', 'Created employee NIK ' . $nik . ' - ' . $fullName);
                setFlashMessage('success', 'Karyawan ' . e($fullName) . ' berhasil ditambahkan.');
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal membuat karyawan: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/employees.php');
        exit;
    }

    if ($action === 'edit_employee') {
        $id = (int)($_POST['id'] ?? 0);
        $nik = trim($_POST['nik'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $joinDate = $_POST['join_date'] ?? date('Y-m-d');
        $bankName = trim($_POST['bank_name'] ?? '');
        $accountNumber = trim($_POST['account_number'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if ($id > 0 && !empty($nik) && !empty($fullName) && !empty($email)) {
            try {
                $db->beginTransaction();

                // Update employee details
                $stmt = $db->prepare("
                    UPDATE employees 
                    SET nik = ?, full_name = ?, email = ?, phone = ?, division_id = ?, join_date = ?, bank_name = ?, account_number = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nik, $fullName, $email, $phone, $divisionId ?: null, $joinDate, $bankName, $accountNumber, $id]);

                // Update associated user username, email & password if specified
                $stmtEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                $stmtEmp->execute([$id]);
                $userId = (int)$stmtEmp->fetchColumn();

                if ($userId > 0) {
                    if (!empty($username)) {
                        $stmtUpUser = $db->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                        $stmtUpUser->execute([$username, $email, $userId]);
                    } else {
                        $stmtUpUser = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $stmtUpUser->execute([$email, $userId]);
                    }

                    if (!empty($newPassword)) {
                        if (strlen($newPassword) < 6) {
                            throw new Exception("Password baru minimal 6 karakter.");
                        }
                        $passHash = password_hash($newPassword, PASSWORD_BCRYPT);
                        $stmtUpPass = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                        $stmtUpPass->execute([$passHash, $userId]);
                    }
                }

                $db->commit();
                logAudit($user['id'], 'EDIT_EMPLOYEE', 'Updated employee & credentials: NIK ' . $nik . ' - ' . $fullName);
                setFlashMessage('success', 'Data & kredensial ' . e($fullName) . ' berhasil diperbarui.');
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal memperbarui data karyawan: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/employees.php?detail=' . $id);
        exit;
    }

    if ($action === 'delete_employee') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $db->beginTransaction();

                $stmtName = $db->prepare("SELECT full_name, user_id FROM employees WHERE id = ?");
                $stmtName->execute([$id]);
                $emp = $stmtName->fetch();

                if ($emp) {
                    $userId = (int)($emp['user_id'] ?? 0);

                    if ($userId > 0) {
                        // Putuskan relasi foreign key user agar tidak gagal akibat constraint
                        $db->prepare("UPDATE tasks SET created_by = NULL WHERE created_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE task_history SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
                        $db->prepare("UPDATE task_attachments SET uploaded_by = NULL WHERE uploaded_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE bonus_transactions SET created_by = NULL WHERE created_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE bonus_transactions SET approved_by = NULL WHERE approved_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE team_bonus_transactions SET created_by = NULL WHERE created_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE team_bonus_transactions SET approved_by = NULL WHERE approved_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE deduction_transactions SET created_by = NULL WHERE created_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE payroll_periods SET created_by = NULL WHERE created_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE payroll_periods SET finalized_by = NULL WHERE finalized_by = ?")->execute([$userId]);
                        $db->prepare("UPDATE audit_logs SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
                        $db->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$userId]);
                    }

                    // Putuskan relasi employee pada tabel tasks
                    $db->prepare("UPDATE tasks SET employee_id = NULL WHERE employee_id = ?")->execute([$id]);
                    $db->prepare("UPDATE employees SET user_id = NULL WHERE id = ?")->execute([$id]);

                    // Hapus data karyawan (cascade akan menghapus salary_history, task_assignments, team_members, dsb)
                    $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);

                    // Hapus data user login terkait jika ada
                    if ($userId > 0) {
                        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                    }

                    $db->commit();
                    logAudit($user['id'], 'DELETE_EMPLOYEE', 'Deleted employee: ' . $emp['full_name']);
                    setFlashMessage('success', 'Karyawan ' . e($emp['full_name']) . ' berhasil dihapus dari sistem.');
                } else {
                    $db->rollBack();
                }
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal menghapus karyawan: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/employees.php');
        exit;
    }

    if ($action === 'update_salary') {
        $empId = (int)($_POST['employee_id'] ?? 0);
        $newSalary = (float)($_POST['basic_salary'] ?? 0);
        $effectiveDate = $_POST['effective_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        if ($empId > 0 && $newSalary > 0) {
            $stmt = $db->prepare("INSERT INTO salary_history (employee_id, basic_salary, effective_date, notes, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$empId, $newSalary, $effectiveDate, $notes, $user['id']]);
            logAudit($user['id'], 'UPDATE_SALARY', 'Updated salary for employee ID ' . $empId . ' to ' . formatRupiah($newSalary));
            setFlashMessage('success', 'Histori gaji pokok berhasil ditambahkan.');
        }
        header('Location: ' . BASE_URL . '/employees.php?detail=' . $empId);
        exit;
    }
}

// Fetch Filter parameters
$search = trim($_GET['search'] ?? '');
$divisionFilter = (int)($_GET['division_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$detailId = (int)($_GET['detail'] ?? 0);

// Build SQL Query
$sql = "
    SELECT e.*, d.name as division_name, t.name as team_name, u.username, r.slug as role_slug, r.name as role_name,
           (SELECT basic_salary FROM salary_history WHERE employee_id = e.id ORDER BY effective_date DESC, id DESC LIMIT 1) as current_salary
    FROM employees e
    LEFT JOIN divisions d ON e.division_id = d.id
    LEFT JOIN teams t ON e.team_id = t.id
    LEFT JOIN users u ON e.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (e.full_name LIKE ? OR e.nik LIKE ? OR e.email LIKE ? OR u.username LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($divisionFilter > 0) {
    $sql .= " AND e.division_id = ?";
    $params[] = $divisionFilter;
}

if (!empty($statusFilter)) {
    $sql .= " AND e.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY e.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$divisions = $db->query("SELECT * FROM divisions WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$teams = $db->query("SELECT * FROM teams WHERE status = 'active' ORDER BY name ASC")->fetchAll();

$selectedEmployee = null;
$salaryHistories = [];
if ($detailId > 0) {
    $stmtDetail = $db->prepare("
        SELECT e.*, d.name as division_name, t.name as team_name, u.username, r.slug as role_slug, r.name as role_name
        FROM employees e
        LEFT JOIN divisions d ON e.division_id = d.id
        LEFT JOIN teams t ON e.team_id = t.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE e.id = ?
    ");
    $stmtDetail->execute([$detailId]);
    $selectedEmployee = $stmtDetail->fetch();

    if ($selectedEmployee) {
        $stmtSalHist = $db->prepare("SELECT sh.*, u.username as creator_name FROM salary_history sh LEFT JOIN users u ON sh.created_by = u.id WHERE sh.employee_id = ? ORDER BY sh.effective_date DESC, sh.id DESC");
        $stmtSalHist->execute([$detailId]);
        $salaryHistories = $stmtSalHist->fetchAll();
    }
}

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <style>
      .employee-table th {
          border-top: none !important;
          border-bottom: 2px solid #f3f4f6 !important;
          font-weight: 700;
          color: #4b5563;
          font-size: 0.85rem;
          text-transform: uppercase;
          letter-spacing: 0.5px;
      }
      .employee-table td {
          border-bottom: 1px solid #f3f4f6 !important;
          padding: 14px 12px !important;
          font-size: 0.9rem;
      }
      .employee-table tr:hover td {
          background-color: #f8fafc;
      }
      .avatar-circle {
          width: 38px;
          height: 38px;
          border-radius: 50%;
          background: linear-gradient(135deg, #4B49AC, #6c63ff);
          color: #ffffff;
          font-weight: 600;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.85rem;
          box-shadow: 0 2px 5px rgba(75,73,172,0.25);
      }
      .detail-info-row {
          display: flex;
          justify-content: space-between;
          padding: 10px 0;
          border-bottom: 1px dashed #ebedf2;
          font-size: 0.9rem;
      }
      .detail-info-row:last-child {
          border-bottom: none;
      }
      .detail-info-label {
          color: #6b7280;
          font-weight: 500;
      }
      .detail-info-value {
          color: #1f2937;
          font-weight: 600;
          text-align: right;
      }
    </style>

    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4" role="alert">
          <i class="fa-solid fa-circle-info me-2"></i> <?= e($flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Header Title -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-0">Kelola Karyawan</h3>
          <p class="text-muted mb-0">Manajemen data SDM, pengaturan divisi, dan riwayat gaji pokok</p>
        </div>
        <!-- Desktop Add Button -->
        <button class="btn btn-primary rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#addEmployeeModal">
          <i class="fa-solid fa-user-plus me-1"></i> Tambah Karyawan Baru
        </button>
        
        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" data-bs-toggle="modal" data-bs-target="#addEmployeeModal" style="bottom: 80px; right: 20px; width: 52px; height: 52px; z-index: 1040; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;">
          <i class="fa-solid fa-plus fs-3 text-white"></i>
        </button>
      </div>

      <!-- Filters & Search -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body py-3">
          <form action="<?= BASE_URL ?>/employees.php" method="GET" class="row g-2 align-items-center">
            <div class="col-md-4">
              <input type="text" name="search" class="form-control form-control-sm rounded-3" placeholder="Cari NIK, Nama, Email..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-5">
              <select name="division_id" class="form-select form-control form-control-sm rounded-3">
                <option value="">-- Semua Divisi --</option>
                <?php foreach ($divisions as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= $divisionFilter === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
              <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill font-weight-bold"><i class="fa-solid fa-filter me-1"></i> Filter</button>
              <a href="<?= BASE_URL ?>/employees.php" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;"><i class="fa-solid fa-rotate-right"></i></a>
            </div>
          </form>
        </div>
      </div>

      <div class="row">
        <!-- Left Column: Employees List Table -->
        <div class="col-lg-7 mb-4">
          <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-body p-4">
              <h5 class="font-weight-bold text-dark mb-4"><i class="fa-solid fa-address-book text-primary me-2"></i> Direktori Karyawan</h5>
              
              <?php if (empty($employees)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="fa-solid fa-users-slash fs-1 mb-2 d-block text-secondary" style="opacity: 0.4;"></i>
                  Tidak ada karyawan ditemukan.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table employee-table align-middle">
                    <thead>
                      <tr>
                        <th>Nama Lengkap</th>
                        <th>Divisi</th>
                        <th>Gaji Pokok</th>
                        <th class="text-end">Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($employees as $emp): ?>
                        <?php
                          // Generate initials
                          $words = explode(" ", $emp['full_name']);
                          $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                        ?>
                        <tr class="<?= $detailId === (int)$emp['id'] ? 'table-primary' : '' ?>" onclick="window.location='<?= BASE_URL ?>/employees.php?detail=<?= $emp['id'] ?>'" style="cursor: pointer;">
                          <td>
                            <div class="d-flex align-items-center gap-3">
                              <div class="avatar-circle"><?= $initials ?></div>
                              <div>
                                <div class="fw-bold text-dark"><?= e($emp['full_name']) ?></div>
                                <small class="text-muted"><?= e($emp['nik']) ?> &bull; <?= e($emp['email']) ?></small>
                              </div>
                            </div>
                          </td>
                          <td>
                            <span class="badge bg-light text-dark border font-weight-bold px-2.5 py-1.5" style="border-radius: 6px; font-size: 0.75rem;">
                              <?= e($emp['division_name'] ?? 'Belum Diatur') ?>
                            </span>
                          </td>
                          <td>
                            <?php if ($emp['role_slug'] === 'super_admin'): ?>
                              <span class="badge bg-light text-muted border font-weight-bold px-2 py-1" style="font-size: 0.75rem;">Tanpa Gaji (Super Admin)</span>
                            <?php else: ?>
                              <span class="font-weight-bold text-success" style="font-size: 0.85rem;"><?= formatRupiah($emp['current_salary']) ?></span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <div class="d-flex gap-1 justify-content-end" onclick="event.stopPropagation();">
                              <button type="button" class="btn btn-xs btn-outline-warning rounded-pill" onclick="event.stopPropagation(); openEditEmployee(<?= $emp['id'] ?>, '<?= e(addslashes($emp['nik'])) ?>', '<?= e(addslashes($emp['full_name'])) ?>', '<?= e(addslashes($emp['email'])) ?>', '<?= e(addslashes($emp['phone'])) ?>', <?= (int)$emp['division_id'] ?>, '<?= $emp['join_date'] ?>', '<?= e(addslashes($emp['bank_name'])) ?>', '<?= e(addslashes($emp['account_number'])) ?>', '<?= e(addslashes($emp['username'] ?? '')) ?>')">
                                <i class="fa-solid fa-pen-to-square"></i>
                              </button>
                              <form action="<?= BASE_URL ?>/employees.php" method="POST" class="d-inline" onclick="event.stopPropagation();">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_employee">
                                <input type="hidden" name="id" value="<?= $emp['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="event.stopPropagation(); return confirm('Yakin ingin menghapus karyawan <?= e(addslashes($emp['full_name'])) ?>? Akun login dan riwayat terkait akan ikut terhapus.')">
                                  <i class="fa-solid fa-trash-can"></i>
                                </button>
                              </form>
                            </div>
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

        <!-- Right Column: Detail Card / Placeholder -->
        <div class="col-lg-5 mb-4">
          <?php if ($selectedEmployee): ?>
            <?php
              $wordsS = explode(" ", $selectedEmployee['full_name']);
              $initialsS = strtoupper(substr($wordsS[0], 0, 1) . (isset($wordsS[1]) ? substr($wordsS[1], 0, 1) : ''));
            ?>
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                <h5 class="mb-0 font-weight-bold text-dark"><i class="fa-solid fa-id-card text-primary me-2"></i> Profil Karyawan</h5>
                <a href="<?= BASE_URL ?>/employees.php" class="btn btn-sm btn-light rounded-circle"><i class="fa-solid fa-xmark"></i></a>
              </div>
              <div class="card-body p-4">
                
                <!-- Avatar and Name Title -->
                <div class="text-center mb-4 border-bottom pb-4">
                  <div class="rounded-circle d-flex align-items-center justify-content-center text-white font-weight-bold mx-auto mb-3" style="width: 72px; height: 72px; background: linear-gradient(135deg, #4B49AC, #6c63ff); font-size: 1.5rem; box-shadow: 0 4px 12px rgba(75,73,172,0.25);">
                    <?= $initialsS ?>
                  </div>
                  <h4 class="font-weight-bold text-dark mb-1"><?= e($selectedEmployee['full_name']) ?></h4>
                  <span class="badge bg-light text-dark border px-3 py-1.5 font-weight-bold" style="border-radius: 6px; font-size: 0.8rem;"><?= e($selectedEmployee['nik']) ?></span>
                </div>

                <!-- General Information -->
                <div class="mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="font-weight-bold text-muted mb-0 small uppercase">INFORMASI UMUM</h6>
                    <button type="button" class="btn btn-xs btn-outline-warning rounded-pill font-weight-bold px-2.5" onclick="openEditEmployee(<?= $selectedEmployee['id'] ?>, '<?= e(addslashes($selectedEmployee['nik'])) ?>', '<?= e(addslashes($selectedEmployee['full_name'])) ?>', '<?= e(addslashes($selectedEmployee['email'])) ?>', '<?= e(addslashes($selectedEmployee['phone'])) ?>', <?= (int)$selectedEmployee['division_id'] ?>, '<?= $selectedEmployee['join_date'] ?>', '<?= e(addslashes($selectedEmployee['bank_name'])) ?>', '<?= e(addslashes($selectedEmployee['account_number'])) ?>', '<?= e(addslashes($selectedEmployee['username'] ?? '')) ?>')">
                      <i class="fa-solid fa-pen-to-square me-1"></i> Edit Data
                    </button>
                  </div>
                  
                  <div class="detail-info-row">
                    <span class="detail-info-label">Username Akun</span>
                    <span class="detail-info-value"><?= e($selectedEmployee['username'] ?? '-') ?></span>
                  </div>
                  <div class="detail-info-row">
                    <span class="detail-info-label">Email Kontak</span>
                    <span class="detail-info-value"><?= e($selectedEmployee['email']) ?></span>
                  </div>
                  <div class="detail-info-row">
                    <span class="detail-info-label">Nomor Telepon</span>
                    <span class="detail-info-value"><?= e($selectedEmployee['phone'] ?? '-') ?></span>
                  </div>
                  <div class="detail-info-row">
                    <span class="detail-info-label">Divisi</span>
                    <span class="detail-info-value text-primary"><?= e($selectedEmployee['division_name'] ?? '-') ?></span>
                  </div>
                  <div class="detail-info-row">
                    <span class="detail-info-label">Tanggal Bergabung</span>
                    <span class="detail-info-value"><?= formatTanggalIndo($selectedEmployee['join_date']) ?></span>
                  </div>
                  <div class="detail-info-row">
                    <span class="detail-info-label">Rekening Bank</span>
                    <span class="detail-info-value">
                      <?php if ($selectedEmployee['bank_name']): ?>
                        <?= e($selectedEmployee['bank_name']) ?> - <?= e($selectedEmployee['account_number'] ?? '') ?>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </span>
                  </div>
                </div>

                <!-- Salary History Section -->
                <div>
                  <div class="d-flex justify-content-between align-items-center mb-3 border-top pt-4">
                    <h6 class="font-weight-bold text-muted mb-0 small uppercase">HISTORI GAJI POKOK</h6>
                    <?php if ($selectedEmployee['role_slug'] !== 'super_admin'): ?>
                      <button class="btn btn-xs btn-success rounded-pill font-weight-bold px-2.5" data-bs-toggle="modal" data-bs-target="#updateSalaryModal">
                        <i class="fa-solid fa-plus me-1"></i> Update Gaji
                      </button>
                    <?php endif; ?>
                  </div>

                  <?php if ($selectedEmployee['role_slug'] === 'super_admin'): ?>
                    <div class="alert alert-light border rounded-3 p-3 text-muted small mb-0">
                      <i class="fa-solid fa-user-shield me-1 text-primary"></i> Super Admin adalah akun administrator sistem (tidak memiliki gaji pokok dan tidak diikutsertakan dalam payroll).
                    </div>
                  <?php elseif (empty($salaryHistories)): ?>
                    <p class="text-muted small italic text-center py-3">Belum ada riwayat gaji pokok.</p>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered" style="font-size: 0.85rem;">
                        <thead class="bg-light">
                          <tr>
                            <th>Berlaku Mulai</th>
                            <th>Gaji Pokok</th>
                            <th>Catatan</th>
                            <th>Oleh</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($salaryHistories as $sh): ?>
                            <tr>
                              <td class="font-weight-bold text-dark"><?= formatTanggalIndo($sh['effective_date']) ?></td>
                              <td class="font-weight-bold text-success"><?= formatRupiah($sh['basic_salary']) ?></td>
                              <td class="text-muted"><?= e($sh['notes'] ?? '-') ?></td>
                              <td><small class="text-dark"><?= e($sh['creator_name'] ?? 'System') ?></small></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>

              </div>
            </div>
          <?php else: ?>
            <!-- Detail Card Placeholder -->
            <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted h-100 d-flex flex-column align-items-center justify-content-center" style="border: 2px dashed #e2e8f0 !important; background-color: #fafafa;">
              <i class="fa-regular fa-address-card fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
              <h6 class="font-weight-bold text-dark">Tidak Ada Karyawan Terpilih</h6>
              <p class="mb-0 small" style="max-width: 320px; line-height: 1.5;">Pilih salah satu karyawan di direktori samping dengan mengklik tombol <strong>Detail</strong> untuk menampilkan profil lengkap dan histori gajinya.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Modal Add Employee -->
    <div class="modal fade" id="addEmployeeModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #4B49AC08, #6c63ff08);">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-user-plus me-2 text-primary"></i> Tambah Karyawan Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/employees.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_employee">
            <div class="modal-body p-4">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">NIK Internal</label>
                  <input type="text" name="nik" class="form-control" placeholder="EMP-005" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Nama Lengkap</label>
                  <input type="text" name="full_name" class="form-control" placeholder="Contoh: Hermawan" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Email</label>
                  <input type="email" name="email" class="form-control" placeholder="contoh@gajiku.com" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Nomor Telepon</label>
                  <input type="text" name="phone" class="form-control" placeholder="0812xxxxxxxx">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Divisi</label>
                  <select name="division_id" class="form-select form-control" required>
                    <option value="">-- Pilih Divisi --</option>
                    <?php foreach ($divisions as $d): ?>
                      <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Tanggal Bergabung</label>
                  <input type="date" name="join_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Gaji Pokok Awal (Rp)</label>
                  <input type="number" name="basic_salary" class="form-control" placeholder="3000000" required>
                </div>
                <div class="col-md-3 mb-3">
                  <label class="form-label font-weight-bold">Nama Bank</label>
                  <input type="text" name="bank_name" class="form-control" placeholder="BCA">
                </div>
                <div class="col-md-3 mb-3">
                  <label class="form-label font-weight-bold">No. Rekening</label>
                  <input type="text" name="account_number" class="form-control" placeholder="123456789">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Username Akun</label>
                  <input type="text" name="username" class="form-control" placeholder="Boleh dikosongkan (otomatis)">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Password Login</label>
                  <input type="password" name="password" class="form-control" placeholder="Default: emp123" value="emp123" required>
                </div>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary rounded-pill font-weight-bold px-4">Simpan Karyawan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Edit Employee -->
    <div class="modal fade" id="editEmployeeModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #ffc10708, #6c63ff08);">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-user-pen me-2 text-warning"></i> Edit Data Karyawan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/employees.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_employee">
            <input type="hidden" name="id" id="editEmpId">
            <div class="modal-body p-4">
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">NIK Internal</label>
                  <input type="text" name="nik" id="editEmpNik" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Nama Lengkap</label>
                  <input type="text" name="full_name" id="editEmpFullName" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Email</label>
                  <input type="email" name="email" id="editEmpEmail" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Nomor Telepon</label>
                  <input type="text" name="phone" id="editEmpPhone" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Divisi</label>
                  <select name="division_id" id="editEmpDivisionId" class="form-select form-control" required>
                    <option value="">-- Pilih Divisi --</option>
                    <?php foreach ($divisions as $d): ?>
                      <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Tanggal Bergabung</label>
                  <input type="date" name="join_date" id="editEmpJoinDate" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Nama Bank</label>
                  <input type="text" name="bank_name" id="editEmpBankName" class="form-control" placeholder="BCA">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">No. Rekening</label>
                  <input type="text" name="account_number" id="editEmpAccountNumber" class="form-control">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Username Login</label>
                  <input type="text" name="username" id="editEmpUsername" class="form-control" placeholder="Biarkan kosong jika tidak diubah">
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Password Baru</label>
                  <input type="password" name="new_password" id="editEmpNewPassword" class="form-control" placeholder="Biarkan kosong jika tidak diubah">
                </div>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-warning text-dark rounded-pill font-weight-bold px-4">Simpan Perubahan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Update Salary -->
    <div class="modal fade" id="updateSalaryModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #10B98108, #4B49AC08);">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-coins me-2 text-success"></i> Perbarui Gaji Pokok Karyawan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/employees.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_salary">
            <input type="hidden" name="employee_id" id="updateSalaryEmpId">
            <div class="modal-body p-4">
              <p class="text-muted small">Aturan: Perubahan gaji pokok baru akan dicatat sebagai histori baru dan <strong>TIDAK MERUBAH</strong> payroll lama yang sudah final.</p>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nominal Gaji Pokok Baru (Rp)</label>
                <input type="number" name="basic_salary" class="form-control" required placeholder="Contoh: 3500000">
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Tanggal Efektif Berlaku</label>
                <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Catatan Penyesuaian Gaji</label>
                <input type="text" name="notes" class="form-control" placeholder="Contoh: Kenaikan Gaji Berkala 2026">
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-success rounded-pill font-weight-bold px-4">Simpan Histori Gaji</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Script to manage Edit Modal -->
    <script>
      function openEditEmployee(id, nik, fullName, email, phone, divisionId, joinDate, bankName, accountNumber, username) {
        document.getElementById('editEmpId').value = id;
        document.getElementById('editEmpNik').value = nik;
        document.getElementById('editEmpFullName').value = fullName;
        document.getElementById('editEmpEmail').value = email;
        document.getElementById('editEmpPhone').value = phone;
        document.getElementById('editEmpDivisionId').value = divisionId;
        document.getElementById('editEmpJoinDate').value = joinDate;
        document.getElementById('editEmpBankName').value = bankName;
        document.getElementById('editEmpAccountNumber').value = accountNumber;
        if (document.getElementById('editEmpUsername')) {
          document.getElementById('editEmpUsername').value = username || '';
        }
        if (document.getElementById('editEmpNewPassword')) {
          document.getElementById('editEmpNewPassword').value = '';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('editEmployeeModal'));
        modal.show();
      }

      // Populate employee ID dynamically for salary updates
      <?php if ($selectedEmployee): ?>
      document.addEventListener('DOMContentLoaded', function() {
          const updateBtn = document.querySelector('[data-bs-target="#updateSalaryModal"]');
          if (updateBtn) {
              updateBtn.addEventListener('click', function() {
                  document.getElementById('updateSalaryEmpId').value = "<?= $selectedEmployee['id'] ?>";
              });
          }
      });
      <?php endif; ?>
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
