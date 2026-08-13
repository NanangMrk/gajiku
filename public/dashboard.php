<?php
// dashboard.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Dashboard';
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

      <!-- Welcome Banner -->
      <div class="row mb-4">
        <div class="col-md-12 grid-margin">
          <div class="row">
            <div class="col-12 col-xl-8 mb-4 mb-xl-0">
              <h3 class="font-weight-bold">Selamat Datang, <?= e($user['employee_name'] ?? $user['username']) ?>!</h3>
              <h6 class="font-weight-normal mb-0 text-muted">Berikut ringkasan aktivitas dan status terkini sistem <span class="text-primary font-weight-bold"><?= e(APP_NAME) ?></span>.</h6>
            </div>
            <div class="col-12 col-xl-4 text-xl-end">
              <span class="badge bg-primary px-3 py-2 fs-7 font-weight-bold rounded-pill">
                <i class="fa-solid fa-calendar-day me-1"></i> <?= formatTanggalIndo(date('Y-m-d')) ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <?php if ($user['role_slug'] === 'super_admin' || $user['role_slug'] === 'admin_hr'): ?>
        <!-- SUPER ADMIN / ADMIN HR DASHBOARD -->
        <?php
          $totalEmployees = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'active'")->fetchColumn();
          $totalActiveTasks = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status IN ('pending', 'in_progress')")->fetchColumn();
          $totalOverdueTasks = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status = 'overdue' OR (status != 'completed' AND due_date < date('now'))")->fetchColumn();
          $pendingBonuses = (int)$db->query("SELECT COUNT(*) FROM bonus_transactions WHERE status = 'pending'")->fetchColumn();
          $totalBonusThisMonth = (float)$db->query("SELECT SUM(total_amount) FROM bonus_transactions WHERE status = 'approved' AND strftime('%Y-%m', transaction_date) = strftime('%Y-%m', 'now')")->fetchColumn();
        ?>

        <!-- Stat Cards Row -->
        <div class="row">
          <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-primary text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Total Karyawan Aktif</p>
                  <i class="fa-solid fa-users fs-3 opacity-75"></i>
                </div>
                <h2 class="font-weight-bold mb-0"><?= $totalEmployees ?></h2>
                <small class="text-white-50 mt-2 d-block">Struktur Organisasi</small>
              </div>
            </div>
          </div>

          <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-success text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Total Bonus (Bulan Ini)</p>
                  <i class="fa-solid fa-gift fs-3 opacity-75"></i>
                </div>
                <h3 class="font-weight-bold mb-0"><?= formatRupiah($totalBonusThisMonth) ?></h3>
                <small class="text-white-50 mt-2 d-block">Bonus disetujui</small>
              </div>
            </div>
          </div>

          <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-warning text-dark shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Bonus Pending Approval</p>
                  <i class="fa-solid fa-hourglass-half fs-3 opacity-75"></i>
                </div>
                <h2 class="font-weight-bold mb-0"><?= $pendingBonuses ?></h2>
                <small class="text-dark-50 mt-2 d-block">Membutuhkan persetujuan</small>
              </div>
            </div>
          </div>

          <div class="col-md-3 grid-margin stretch-card">
            <div class="card bg-danger text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Tugas Terlambat</p>
                  <i class="fa-solid fa-exclamation-triangle fs-3 opacity-75"></i>
                </div>
                <h2 class="font-weight-bold mb-0"><?= $totalOverdueTasks ?></h2>
                <small class="text-white-50 mt-2 d-block">Dari total <?= $totalActiveTasks ?> tugas aktif</small>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <!-- Pending Bonus Approval Table -->
          <div class="col-md-7 grid-margin stretch-card">
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h4 class="card-title mb-0 font-weight-bold"><i class="fa-solid fa-clock-rotate-left me-2 text-warning"></i> Pengajuan Bonus Menunggu Approval</h4>
                  <a href="<?= BASE_URL ?>/bonuses.php" class="btn btn-sm btn-outline-primary rounded-pill">Lihat Semua</a>
                </div>
                
                <?php
                  $stmtPendingList = $db->query("
                    SELECT bt.*, e.full_name as employee_name, b.name as bonus_name
                    FROM bonus_transactions bt
                    JOIN employees e ON bt.employee_id = e.id
                    LEFT JOIN bonus_types b ON bt.bonus_type_id = b.id
                    WHERE bt.status = 'pending'
                    ORDER BY bt.created_at DESC LIMIT 5
                  ");
                  $pendingList = $stmtPendingList->fetchAll();
                ?>

                <?php if (empty($pendingList)): ?>
                  <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-circle-check fs-1 text-success mb-2 d-block"></i>
                    Semua pengajuan bonus telah diproses.
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle">
                      <thead>
                        <tr>
                          <th>Karyawan</th>
                          <th>Bonus</th>
                          <th>Nominal</th>
                          <th>Tanggal</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($pendingList as $p): ?>
                          <tr>
                            <td class="font-weight-bold"><?= e($p['employee_name']) ?></td>
                            <td><?= e($p['bonus_name'] ?? 'Manual') ?></td>
                            <td class="text-success font-weight-bold"><?= formatRupiah($p['total_amount']) ?></td>
                            <td><small><?= formatTanggalIndo($p['transaction_date']) ?></small></td>
                            <td>
                              <a href="<?= BASE_URL ?>/bonuses.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-primary rounded-pill px-3 py-1">Review</a>
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

          <!-- Active Tasks Overview -->
          <div class="col-md-5 grid-margin stretch-card">
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body">
                <h4 class="card-title font-weight-bold mb-3"><i class="fa-solid fa-list-check me-2 text-primary"></i> Deadlines Tugas Terdekat</h4>
                <?php
                  $stmtUpcomingTasks = $db->query("
                    SELECT t.*, e.full_name as pic_name
                    FROM tasks t
                    LEFT JOIN employees e ON t.employee_id = e.id
                    WHERE t.status IN ('pending', 'in_progress')
                    ORDER BY t.due_date ASC LIMIT 5
                  ");
                  $upcomingTasks = $stmtUpcomingTasks->fetchAll();
                ?>
                <?php if (empty($upcomingTasks)): ?>
                  <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-folder-open fs-1 text-muted mb-2 d-block"></i>
                    Belum ada tugas aktif.
                  </div>
                <?php else: ?>
                  <div class="list-group list-group-flush">
                    <?php foreach ($upcomingTasks as $t): ?>
                      <div class="list-group-item px-0 py-2 border-bottom">
                        <div class="d-flex justify-content-between align-items-center">
                          <h6 class="mb-1 font-weight-bold text-dark"><?= e($t['title']) ?></h6>
                          <span class="badge bg-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : 'info') ?>">
                            <?= strtoupper($t['priority']) ?>
                          </span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                          <small class="text-muted"><i class="fa-solid fa-user me-1"></i> <?= e($t['pic_name'] ?? 'Tim/Divisi') ?></small>
                          <small class="text-danger font-weight-bold"><i class="fa-solid fa-clock me-1"></i> <?= formatTanggalIndo($t['due_date']) ?></small>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

      <?php else: ?>
        <!-- EMPLOYEE DASHBOARD -->
        <?php
          $employeeId = $user['employee_id'] ?? 0;

          $stmtMyTasksCount = $db->prepare("
            SELECT 
              SUM(CASE WHEN status IN ('pending', 'in_progress') THEN 1 ELSE 0 END) as active_tasks,
              SUM(CASE WHEN status = 'overdue' OR (status != 'completed' AND due_date < date('now')) THEN 1 ELSE 0 END) as overdue_tasks,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
            FROM tasks t
            LEFT JOIN task_assignments ta ON t.id = ta.task_id
            WHERE t.employee_id = ? OR ta.employee_id = ? OR t.team_id = ? OR t.division_id = ?
          ");
          $stmtMyTasksCount->execute([$employeeId, $employeeId, $user['team_id'] ?? 0, $user['division_id'] ?? 0]);
          $myTasksStat = $stmtMyTasksCount->fetch() ?: ['active_tasks' => 0, 'overdue_tasks' => 0, 'completed_tasks' => 0];

          $stmtMyBonus = $db->prepare("
            SELECT SUM(total_amount) FROM bonus_transactions 
            WHERE employee_id = ? AND status = 'approved' 
            AND strftime('%Y-%m', transaction_date) = strftime('%Y-%m', 'now')
          ");
          $stmtMyBonus->execute([$employeeId]);
          $myEstBonus = (float)$stmtMyBonus->fetchColumn();

          $stmtLastSlip = $db->prepare("
            SELECT p.*, pp.name as period_name 
            FROM payrolls p
            JOIN payroll_periods pp ON p.payroll_period_id = pp.id
            WHERE p.employee_id = ?
            ORDER BY p.id DESC LIMIT 1
          ");
          $stmtLastSlip->execute([$employeeId]);
          $lastSlip = $stmtLastSlip->fetch();
        ?>

        <div class="row">
          <div class="col-md-4 grid-margin stretch-card">
            <div class="card bg-primary text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Tugas Belum Selesai</p>
                  <i class="fa-solid fa-list-check fs-3 opacity-75"></i>
                </div>
                <h2 class="font-weight-bold mb-0"><?= (int)$myTasksStat['active_tasks'] ?></h2>
                <small class="text-white-50 mt-2 d-block">Tugas aktif Anda</small>
              </div>
            </div>
          </div>

          <div class="col-md-4 grid-margin stretch-card">
            <div class="card bg-success text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Estimasi Bonus Bulan Ini</p>
                  <i class="fa-solid fa-hand-holding-dollar fs-3 opacity-75"></i>
                </div>
                <h3 class="font-weight-bold mb-0"><?= formatRupiah($myEstBonus) ?></h3>
                <small class="text-white-50 mt-2 d-block">Bonus disetujui periode ini</small>
              </div>
            </div>
          </div>

          <div class="col-md-4 grid-margin stretch-card">
            <div class="card bg-danger text-white shadow-sm border-0 rounded-4">
              <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                  <p class="fs-6 font-weight-bold mb-0">Tugas Terlambat</p>
                  <i class="fa-solid fa-clock fs-3 opacity-75"></i>
                </div>
                <h2 class="font-weight-bold mb-0"><?= (int)$myTasksStat['overdue_tasks'] ?></h2>
                <small class="text-white-50 mt-2 d-block">Perlu perhatian segera</small>
              </div>
            </div>
          </div>
        </div>

        <div class="row">
          <!-- My Tasks -->
          <div class="col-md-8 grid-margin stretch-card">
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                  <h4 class="card-title mb-0 font-weight-bold"><i class="fa-solid fa-tasks me-2 text-primary"></i> Tugas Saya Hari Ini</h4>
                  <a href="<?= BASE_URL ?>/tasks.php" class="btn btn-sm btn-primary rounded-pill">Buka Semua Tugas</a>
                </div>

                <?php
                  $stmtMyTasksList = $db->prepare("
                    SELECT DISTINCT t.*
                    FROM tasks t
                    LEFT JOIN task_assignments ta ON t.id = ta.task_id
                    WHERE (t.employee_id = ? OR ta.employee_id = ? OR t.team_id = ? OR t.division_id = ?)
                    AND t.status IN ('pending', 'in_progress')
                    ORDER BY t.due_date ASC LIMIT 5
                  ");
                  $stmtMyTasksList->execute([$employeeId, $employeeId, $user['team_id'] ?? 0, $user['division_id'] ?? 0]);
                  $myTasksList = $stmtMyTasksList->fetchAll();
                ?>

                <?php if (empty($myTasksList)): ?>
                  <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-mug-hot fs-1 text-success mb-2 d-block"></i>
                    Tidak ada tugas aktif untuk Anda saat ini.
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle">
                      <thead>
                        <tr>
                          <th>Judul Tugas</th>
                          <th>Deadline</th>
                          <th>Prioritas</th>
                          <th>Status</th>
                          <th>Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($myTasksList as $mt): ?>
                          <tr>
                            <td class="font-weight-bold"><?= e($mt['title']) ?></td>
                            <td><?= formatTanggalIndo($mt['due_date']) ?></td>
                            <td>
                              <span class="badge bg-<?= $mt['priority'] === 'urgent' ? 'danger' : ($mt['priority'] === 'high' ? 'warning' : 'info') ?>">
                                <?= strtoupper($mt['priority']) ?>
                              </span>
                            </td>
                            <td>
                              <span class="badge bg-secondary"><?= str_replace('_', ' ', strtoupper($mt['status'])) ?></span>
                            </td>
                            <td>
                              <a href="<?= BASE_URL ?>/tasks.php?id=<?= $mt['id'] ?>" class="btn btn-xs btn-outline-primary rounded-pill">Detail</a>
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

          <!-- Recent Payslip Card -->
          <div class="col-md-4 grid-margin stretch-card">
            <div class="card shadow-sm border-0 rounded-4">
              <div class="card-body">
                <h4 class="card-title font-weight-bold mb-3"><i class="fa-solid fa-file-invoice-dollar me-2 text-success"></i> Slip Gaji Terakhir</h4>
                <?php if ($lastSlip): ?>
                  <div class="p-3 bg-light rounded-3 mb-3 border">
                    <small class="text-muted d-block mb-1">Periode</small>
                    <h6 class="font-weight-bold text-primary mb-2"><?= e($lastSlip['period_name']) ?></h6>
                    <small class="text-muted d-block mb-1">Take Home Pay</small>
                    <h3 class="font-weight-bold text-success mb-0"><?= formatRupiah($lastSlip['take_home_pay']) ?></h3>
                  </div>
                  <a href="<?= BASE_URL ?>/payslips.php?id=<?= $lastSlip['id'] ?>" class="btn btn-success w-100 rounded-pill font-weight-bold">
                    <i class="fa-solid fa-eye me-1"></i> Lihat Slip Gaji
                  </a>
                <?php else: ?>
                  <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-folder-minus fs-1 mb-2 d-block"></i>
                    Belum ada slip gaji yang difinalisasi.
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
