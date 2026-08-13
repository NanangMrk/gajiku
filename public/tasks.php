<?php
// tasks.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Kelola Tugas / To-Do List';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_task') {
        if (!hasPermission('manage_tasks')) {
            setFlashMessage('danger', 'Anda tidak memiliki hak akses untuk membuat tugas.');
            header('Location: ' . BASE_URL . '/tasks.php');
            exit;
        }

        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $assignType = $_POST['assign_type'] ?? 'employee';
        $employeeId = !empty($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;
        $teamId = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : null;
        $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $dueDate = $_POST['due_date'] ?? date('Y-m-d');
        $priority = $_POST['priority'] ?? 'normal';
        $bonusTypeId = !empty($_POST['bonus_type_id']) ? (int)$_POST['bonus_type_id'] : null;
        $bonusQuantity = (int)($_POST['bonus_quantity'] ?? 1);

        if (!empty($title)) {
            $stmt = $db->prepare("
                INSERT INTO tasks (title, description, assign_type, division_id, team_id, employee_id, start_date, due_date, priority, status, bonus_type_id, bonus_quantity, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
            ");
            $stmt->execute([$title, $description, $assignType, $divisionId, $teamId, $employeeId, $startDate, $dueDate, $priority, $bonusTypeId, $bonusQuantity, $user['id']]);
            $taskId = (int)$db->lastInsertId();

            $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, NULL, 'pending', 'Task dibuat')");
            $stmtHist->execute([$taskId, $user['id']]);

            if ($employeeId) {
                $stmtUserEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                $stmtUserEmp->execute([$employeeId]);
                $empUserId = $stmtUserEmp->fetchColumn();
                if ($empUserId) {
                    sendNotification($empUserId, 'Tugas Baru Diterima', 'Anda ditugaskan pada: ' . $title, '/tasks.php?id=' . $taskId);
                }
            }

            logAudit($user['id'], 'ADD_TASK', 'Created task ID ' . $taskId . ' - ' . $title);
            setFlashMessage('success', 'Tugas baru berhasil dibuat.');
        }
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }

    if ($action === 'update_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['notes'] ?? '');

        $stmtT = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmtT->execute([$taskId]);
        $task = $stmtT->fetch();

        if ($task) {
            $oldStatus = $task['status'];
            if ($oldStatus !== $newStatus) {
                $stmtUp = $db->prepare("UPDATE tasks SET status = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
                $stmtUp->execute([$newStatus, $taskId]);

                $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, ?, ?, ?)");
                $stmtHist->execute([$taskId, $user['id'], $oldStatus, $newStatus, $notes]);

                if ($newStatus === 'completed' && $task['bonus_type_id'] > 0 && $task['employee_id'] > 0) {
                    $stmtBt = $db->prepare("SELECT rate FROM bonus_types WHERE id = ?");
                    $stmtBt->execute([$task['bonus_type_id']]);
                    $rate = (float)$stmtBt->fetchColumn();
                    $totalBonus = $rate * $task['bonus_quantity'];

                    $stmtBonus = $db->prepare("
                        INSERT INTO bonus_transactions (employee_id, bonus_type_id, task_id, transaction_date, quantity, rate_snapshot, total_amount, status, notes, created_by)
                        VALUES (?, ?, ?, date('now'), ?, ?, ?, 'pending', ?, ?)
                    ");
                    $stmtBonus->execute([
                        $task['employee_id'],
                        $task['bonus_type_id'],
                        $taskId,
                        $task['bonus_quantity'],
                        $rate,
                        $totalBonus,
                        'Bonus otomatis dari penyelesaian task: ' . $task['title'],
                        $user['id']
                    ]);

                    setFlashMessage('success', 'Status tugas diperbarui menjadi SELESAI dan pengajuan bonus telah dibuat (Menunggu Approval Admin).');
                } else {
                    setFlashMessage('success', 'Status tugas berhasil diperbarui.');
                }

                logAudit($user['id'], 'UPDATE_TASK_STATUS', "Task ID $taskId status changed from $oldStatus to $newStatus");
            }
        }
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }
}

// Fetch Filter parameters
$statusFilter = trim($_GET['status'] ?? '');
$priorityFilter = trim($_GET['priority'] ?? '');
$search = trim($_GET['search'] ?? '');
$selectedTaskId = (int)($_GET['id'] ?? 0);

// Build SQL Query
$sql = "
    SELECT t.*, e.full_name as pic_name, d.name as division_name, tm.name as team_name, bt.name as bonus_type_name, bt.rate as bonus_rate
    FROM tasks t
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN divisions d ON t.division_id = d.id
    LEFT JOIN teams tm ON t.team_id = tm.id
    LEFT JOIN bonus_types bt ON t.bonus_type_id = bt.id
    WHERE 1=1
";
$params = [];

if ($user['role_slug'] === 'employee') {
    $empId = $user['employee_id'] ?? 0;
    $sql .= " AND (t.employee_id = ? OR t.team_id = ? OR t.division_id = ?)";
    $params[] = $empId;
    $params[] = $user['team_id'] ?? 0;
    $params[] = $user['division_id'] ?? 0;
}

if (!empty($statusFilter)) {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}

if (!empty($priorityFilter)) {
    $sql .= " AND t.priority = ?";
    $params[] = $priorityFilter;
}

if (!empty($search)) {
    $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY t.due_date ASC, t.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

// Filter tasks into groups for tabs
$pendingTasks = array_filter($tasks, function($t) {
    return $t['status'] === 'pending' || $t['status'] === 'overdue';
});

$inProgressTasks = array_filter($tasks, function($t) {
    return $t['status'] === 'in_progress';
});

$completedTasks = array_filter($tasks, function($t) {
    return $t['status'] === 'completed';
});

// Detail view task & history
$selectedTask = null;
$taskHistories = [];
if ($selectedTaskId > 0) {
    $stmtDetail = $db->prepare("
        SELECT t.*, e.full_name as pic_name, d.name as division_name, tm.name as team_name, bt.name as bonus_type_name, bt.rate as bonus_rate, u.username as creator_name
        FROM tasks t
        LEFT JOIN employees e ON t.employee_id = e.id
        LEFT JOIN divisions d ON t.division_id = d.id
        LEFT JOIN teams tm ON t.team_id = tm.id
        LEFT JOIN bonus_types bt ON t.bonus_type_id = bt.id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.id = ?
    ");
    $stmtDetail->execute([$selectedTaskId]);
    $selectedTask = $stmtDetail->fetch();

    if ($selectedTask) {
        $stmtHist = $db->prepare("
            SELECT th.*, u.username
            FROM task_history th
            JOIN users u ON th.user_id = u.id
            WHERE th.task_id = ?
            ORDER BY th.created_at DESC
        ");
        $stmtHist->execute([$selectedTaskId]);
        $taskHistories = $stmtHist->fetchAll();
    }
}

// Fetch lists for modal
$allEmployees = $db->query("SELECT id, full_name, nik FROM employees WHERE status = 'active' ORDER BY full_name ASC")->fetchAll();
$allDivisions = $db->query("SELECT id, name FROM divisions WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$allTeams = $db->query("SELECT id, name FROM teams WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$allBonusTypes = $db->query("SELECT id, name, rate FROM bonus_types WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Determine active tab based on selected task status
$activeTab = 'pending';
if ($selectedTask) {
    if ($selectedTask['status'] === 'completed') {
        $activeTab = 'completed';
    } elseif ($selectedTask['status'] === 'in_progress') {
        $activeTab = 'inprogress';
    }
}

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';

// Helper function to render a task card
function renderTaskCard($t, $selectedTaskId) {
    $isOverdue = (strtotime($t['due_date']) < time() && $t['status'] !== 'completed');
    $priorityClass = 'priority-' . $t['priority'];
    ?>
    <div class="card todo-card border-0 shadow-sm <?= $priorityClass ?> <?= $selectedTaskId === (int)$t['id'] ? 'border border-primary' : '' ?> mb-2">
      <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
          <div class="d-flex align-items-center gap-3">
            <!-- Status Checkbox Icon -->
            <a href="<?= BASE_URL ?>/tasks.php?id=<?= $t['id'] ?>" class="text-decoration-none">
              <?php if ($t['status'] === 'completed'): ?>
                <i class="fa-solid fa-circle-check text-success fs-4"></i>
              <?php elseif ($t['status'] === 'in_progress'): ?>
                <i class="fa-solid fa-spinner fa-spin text-primary fs-4"></i>
              <?php else: ?>
                <i class="fa-regular fa-circle text-muted fs-4"></i>
              <?php endif; ?>
            </a>
            
            <div>
              <a href="<?= BASE_URL ?>/tasks.php?id=<?= $t['id'] ?>" class="h6 font-weight-bold text-dark text-decoration-none mb-1 d-block">
                <?= e($t['title']) ?>
              </a>
              <div class="d-flex align-items-center gap-2 flex-wrap text-muted small">
                <span class="<?= $isOverdue ? 'text-danger font-weight-bold' : '' ?>">
                  <i class="fa-regular fa-calendar-check me-1"></i> Due: <?= formatTanggalIndo($t['due_date']) ?>
                </span>
                <span>&bull;</span>
                <span>
                  <?php if ($t['assign_type'] === 'employee'): ?>
                    <i class="fa-regular fa-user me-1"></i> <?= e($t['pic_name'] ?: 'Karyawan') ?>
                  <?php elseif ($t['assign_type'] === 'team'): ?>
                    <i class="fa-solid fa-users me-1 text-info"></i> Tim: <?= e($t['team_name'] ?: '-') ?>
                  <?php else: ?>
                    <i class="fa-solid fa-sitemap me-1 text-secondary"></i> Divisi: <?= e($t['division_name'] ?: '-') ?>
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </div>

          <div class="d-flex align-items-center gap-2">
            <?php if ($t['bonus_type_name']): ?>
              <span class="badge text-dark font-weight-bold px-2 py-1.5" style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;">
                <i class="fa-solid fa-coins me-1 text-warning"></i> +<?= formatRupiah($t['bonus_rate'] * $t['bonus_quantity']) ?>
              </span>
            <?php endif; ?>

            <span class="badge bg-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : ($t['priority'] === 'normal' ? 'info' : 'secondary')) ?> text-white font-weight-bold" style="font-size: 0.7rem; border-radius: 6px;">
              <?= strtoupper($t['priority']) ?>
            </span>

            <a href="<?= BASE_URL ?>/tasks.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-primary rounded-pill px-3 font-weight-bold">
              Detail
            </a>
          </div>
        </div>
      </div>
    </div>
    <?php
}
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <style>
      .todo-card {
          border: 1px solid #ebedf2 !important;
          transition: all 0.25s ease;
          border-radius: 12px !important;
      }
      .todo-card:hover {
          transform: translateX(4px);
          box-shadow: 0 5px 15px rgba(0,0,0,0.04) !important;
          border-color: #d1d9e6 !important;
      }
      .priority-urgent { border-left: 4px solid #dc3545 !important; }
      .priority-high { border-left: 4px solid #ffc107 !important; }
      .priority-normal { border-left: 4px solid #0dcaf0 !important; }
      .priority-low { border-left: 4px solid #6c757d !important; }
      
      .timeline-item::before {
          content: '';
          position: absolute;
          left: -17px;
          top: 5px;
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #4B49AC;
      }

      /* Custom nav-pills active state style */
      .nav-pills .nav-link.active {
          background-color: #4B49AC !important;
          color: white !important;
          border-color: #4B49AC !important;
      }
      .nav-pills .nav-link {
          background-color: #ffffff;
          transition: all 0.2s ease;
      }
    </style>

    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4" role="alert">
          <i class="fa-solid fa-circle-info me-2"></i> <?= e($flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-0">Kelola Tugas / To-Do List</h3>
          <p class="text-muted mb-0">Kelola assignment tugas, catat histori progres, dan bagikan reward bonus pencapaian</p>
        </div>
        <?php if (hasPermission('manage_tasks')): ?>
          <!-- Tombol Desktop (Header) -->
          <button class="btn btn-primary rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="fa-solid fa-plus me-1"></i> Buat Tugas Baru
          </button>
          
          <!-- Tombol Mobile (FAB Melayang di Pojok Kanan Bawah) -->
          <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" data-bs-toggle="modal" data-bs-target="#addTaskModal" style="bottom: 80px; right: 20px; width: 52px; height: 52px; z-index: 1040; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;">
            <i class="fa-solid fa-plus fs-3 text-white"></i>
          </button>
        <?php endif; ?>
      </div>

      <!-- Filters & Search -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body py-3">
          <form action="<?= BASE_URL ?>/tasks.php" method="GET">
            <div class="row g-2 align-items-center">
              
              <!-- Search bar (Selalu Terlihat) -->
              <div class="col-8 col-md-4">
                <input type="text" name="search" class="form-control form-control-sm rounded-3" placeholder="Cari judul tugas..." value="<?= e($search) ?>">
              </div>
              
              <!-- Tombol Pemicu Collapse (Hanya Mobile) -->
              <div class="col-4 d-md-none text-end">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100 rounded-3 font-weight-bold" data-bs-toggle="collapse" data-bs-target="#advancedFiltersCollapse" aria-expanded="false" style="padding: 0.55rem 0.5rem !important;">
                  <i class="fa-solid fa-sliders me-1"></i> Filter
                </button>
              </div>

              <!-- Advanced Filters Panel (Collapsed di Mobile, Eksplisit di Desktop) -->
              <div class="col-12 col-md-8 collapse d-md-block" id="advancedFiltersCollapse">
                <div class="row g-2 mt-1 mt-md-0">
                  <div class="col-md-5">
                    <select name="status" class="form-select form-control form-control-sm rounded-3">
                      <option value="">-- Semua Status --</option>
                      <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Belum Dikerjakan</option>
                      <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                      <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Selesai</option>
                      <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Terlambat</option>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <select name="priority" class="form-select form-control form-control-sm rounded-3">
                      <option value="">-- Semua Prioritas --</option>
                      <option value="low" <?= $priorityFilter === 'low' ? 'selected' : '' ?>>Rendah</option>
                      <option value="normal" <?= $priorityFilter === 'normal' ? 'selected' : '' ?>>Normal</option>
                      <option value="high" <?= $priorityFilter === 'high' ? 'selected' : '' ?>>Tinggi</option>
                      <option value="urgent" <?= $priorityFilter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                  </div>
                  <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill font-weight-bold"><i class="fa-solid fa-filter me-1"></i> Cari</button>
                    <a href="<?= BASE_URL ?>/tasks.php" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;"><i class="fa-solid fa-rotate-right"></i></a>
                  </div>
                </div>
              </div>
              
            </div>
          </form>
        </div>
      </div>

      <div class="row">
        <!-- Left Column: Tasks List with Tabs -->
        <div class="<?= $selectedTask ? 'col-lg-7' : 'col-12' ?> mb-4">
          
          <!-- Tab Navigation for Statuses -->
          <ul class="nav nav-pills mb-3 d-flex gap-1 flex-wrap" id="taskTabs" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link <?= $activeTab === 'pending' ? 'active' : '' ?> font-weight-bold btn-sm py-1.5 px-3 rounded-pill text-dark border" id="tab-pending" data-bs-toggle="tab" data-bs-target="#pane-pending" type="button" role="tab" style="font-size: 0.85rem;">
                <i class="fa-regular fa-clock me-1 text-warning"></i> Belum Dikerjakan (<?= count($pendingTasks) ?>)
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?= $activeTab === 'inprogress' ? 'active' : '' ?> font-weight-bold btn-sm py-1.5 px-3 rounded-pill text-dark border" id="tab-inprogress" data-bs-toggle="tab" data-bs-target="#pane-inprogress" type="button" role="tab" style="font-size: 0.85rem;">
                <i class="fa-solid fa-spinner fa-spin me-1 text-primary" style="--bs-spinner-animation-speed: 1.5s;"></i> Sedang Dikerjakan (<?= count($inProgressTasks) ?>)
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?= $activeTab === 'completed' ? 'active' : '' ?> font-weight-bold btn-sm py-1.5 px-3 rounded-pill text-dark border" id="tab-completed" data-bs-toggle="tab" data-bs-target="#pane-completed" type="button" role="tab" style="font-size: 0.85rem;">
                <i class="fa-solid fa-circle-check me-1 text-success"></i> Selesai (<?= count($completedTasks) ?>)
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link font-weight-bold btn-sm py-1.5 px-3 rounded-pill text-dark border" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button" role="tab" style="font-size: 0.85rem;">
                <i class="fa-solid fa-history me-1 text-secondary"></i> Riwayat Semua (<?= count($tasks) ?>)
              </button>
            </li>
          </ul>

          <div class="tab-content p-0 border-0" id="taskTabsContent">
            <!-- Pane Pending -->
            <div class="tab-pane fade <?= $activeTab === 'pending' ? 'show active' : '' ?>" id="pane-pending" role="tabpanel">
              <?php if (empty($pendingTasks)): ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted">
                  <i class="fa-regular fa-calendar-check fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
                  <p class="mb-0 small">Tidak ada tugas yang belum dikerjakan.</p>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($pendingTasks as $t) { renderTaskCard($t, $selectedTaskId); } ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Pane In Progress -->
            <div class="tab-pane fade <?= $activeTab === 'inprogress' ? 'show active' : '' ?>" id="pane-inprogress" role="tabpanel">
              <?php if (empty($inProgressTasks)): ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted">
                  <i class="fa-solid fa-spinner fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
                  <p class="mb-0 small">Tidak ada tugas yang sedang dikerjakan.</p>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($inProgressTasks as $t) { renderTaskCard($t, $selectedTaskId); } ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Pane Completed -->
            <div class="tab-pane fade <?= $activeTab === 'completed' ? 'show active' : '' ?>" id="pane-completed" role="tabpanel">
              <?php if (empty($completedTasks)): ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted">
                  <i class="fa-solid fa-circle-check fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
                  <p class="mb-0 small">Belum ada tugas yang selesai.</p>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($completedTasks as $t) { renderTaskCard($t, $selectedTaskId); } ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Pane History (All) -->
            <div class="tab-pane fade" id="pane-history" role="tabpanel">
              <?php if (empty($tasks)): ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted">
                  <i class="fa-solid fa-history fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
                  <p class="mb-0 small">Tidak ada riwayat tugas.</p>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($tasks as $t) { renderTaskCard($t, $selectedTaskId); } ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

        </div>

        <!-- Right Column: Selected Task Details -->
        <?php if ($selectedTask): ?>
          <div class="col-lg-5 mb-4">
            <div class="card shadow-lg border-0 rounded-4" style="border: 1px solid #ebedf2 !important;">
              <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                <h5 class="mb-0 font-weight-bold text-dark"><i class="fa-solid fa-clipboard-list text-primary me-2"></i> Detail Tugas</h5>
                <a href="<?= BASE_URL ?>/tasks.php" class="btn btn-sm btn-light rounded-circle"><i class="fa-solid fa-xmark"></i></a>
              </div>
              <div class="card-body p-4">
                
                <h4 class="font-weight-bold text-dark mb-2"><?= e($selectedTask['title']) ?></h4>
                <div class="d-flex gap-2 mb-3">
                  <span class="badge bg-<?= $selectedTask['status'] === 'completed' ? 'success' : ($selectedTask['status'] === 'in_progress' ? 'primary' : 'warning') ?> text-white font-weight-bold">
                    <?= strtoupper(str_replace('_', ' ', $selectedTask['status'])) ?>
                  </span>
                  <span class="badge bg-light text-dark border font-weight-bold">
                    Assignee: <?= e($selectedTask['assign_type'] === 'employee' ? 'Perorangan' : ($selectedTask['assign_type'] === 'team' ? 'Tim' : 'Divisi')) ?>
                  </span>
                </div>

                <div class="mb-4">
                  <h6 class="text-muted small font-weight-bold mb-2">DESKRIPSI TUGAS</h6>
                  <div class="bg-light p-3 rounded-3 text-dark small" style="white-space: pre-wrap; line-height: 1.5;"><?= e($selectedTask['description'] ?: 'Tidak ada deskripsi rinci.') ?></div>
                </div>

                <div class="row mb-4">
                  <div class="col-6">
                    <small class="text-muted d-block mb-1">Due Date</small>
                    <strong class="text-dark font-weight-bold"><?= formatTanggalIndo($selectedTask['due_date']) ?></strong>
                  </div>
                  <div class="col-6">
                    <small class="text-muted d-block mb-1">Bonus</small>
                    <strong class="text-success font-weight-bold">
                      <?php if ($selectedTask['bonus_type_name']): ?>
                        <i class="fa-solid fa-coins text-warning me-1"></i> +<?= formatRupiah($selectedTask['bonus_rate'] * $selectedTask['bonus_quantity']) ?>
                      <?php else: ?>
                        Tanpa Bonus
                      <?php endif; ?>
                    </strong>
                  </div>
                </div>

                <!-- Update Status Form -->
                <form action="<?= BASE_URL ?>/tasks.php" method="POST" class="p-3 rounded-3 border mb-4 bg-light">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_status">
                  <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">

                  <h6 class="font-weight-bold text-dark mb-3 small"><i class="fa-solid fa-arrows-rotate me-1 text-primary"></i> Perbarui Progres Tugas</h6>
                  <div class="row g-2">
                    <div class="col-12">
                      <select name="status" class="form-select form-control" required>
                        <option value="pending" <?= $selectedTask['status'] === 'pending' ? 'selected' : '' ?>>Belum Dikerjakan</option>
                        <option value="in_progress" <?= $selectedTask['status'] === 'in_progress' ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                        <option value="completed" <?= $selectedTask['status'] === 'completed' ? 'selected' : '' ?>>Selesai &amp; Ajukan Bonus</option>
                      </select>
                    </div>
                    <div class="col-12">
                      <input type="text" name="notes" class="form-control" placeholder="Tulis catatan progres (opsional)...">
                    </div>
                    <div class="col-12">
                      <button type="submit" class="btn btn-primary w-100 font-weight-bold mt-1">Perbarui Status</button>
                    </div>
                  </div>
                </form>

                <!-- History Log -->
                <div>
                  <h6 class="font-weight-bold text-muted mb-3 small"><i class="fa-solid fa-clock-rotate-left me-1"></i> LOG AKTIVITAS &amp; PROGRES</h6>
                  <?php if (empty($taskHistories)): ?>
                    <p class="text-muted small italic">Belum ada catatan log aktivitas.</p>
                  <?php else: ?>
                    <div class="timeline border-start ps-3 ms-2 position-relative" style="border-color: #ebedf2 !important;">
                      <?php foreach ($taskHistories as $th): ?>
                        <div class="mb-3 position-relative timeline-item">
                          <small class="text-primary font-weight-bold d-block" style="font-size: 0.75rem;"><?= formatDateTimeIndo($th['created_at']) ?></small>
                          <div class="fw-bold text-dark small mb-1"><?= e($th['username']) ?></div>
                          <div class="small text-muted mb-1">
                            Status: <span class="badge bg-light border text-dark"><?= e($th['old_status'] ?? 'BARU') ?></span> &rarr; <span class="badge bg-primary text-white"><?= e($th['new_status']) ?></span>
                          </div>
                          <?php if (!empty($th['notes'])): ?>
                            <small class="text-dark d-block bg-light p-2 rounded-2 mt-1 fst-italic">"<?= e($th['notes']) ?>"</small>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>

              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- Modal Add Task -->
    <div class="modal fade" id="addTaskModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-list-check me-2 text-primary"></i> Buat Tugas Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/tasks.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_task">
            <div class="modal-body p-4">
              <!-- Wizard Tab Navigation Indicators -->
              <ul class="nav nav-pills nav-justified mb-4 border-bottom pb-3" id="addTaskFormTabs" role="tablist" style="font-size: 0.85rem;">
                <li class="nav-item" role="presentation">
                  <button class="nav-link active font-weight-bold py-2 rounded-3" id="task-step-1-tab" data-bs-toggle="pill" data-bs-target="#task-step-1" type="button" role="tab">
                    <i class="fa-solid fa-circle-info me-1.5"></i> 1. Informasi Tugas
                  </button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link font-weight-bold py-2 rounded-3" id="task-step-2-tab" data-bs-toggle="pill" data-bs-target="#task-step-2" type="button" role="tab">
                    <i class="fa-solid fa-gift me-1.5"></i> 2. Tanggal &amp; Bonus
                  </button>
                </li>
              </ul>

              <div class="tab-content" id="addTaskFormTabsContent">
                <!-- STEP 1: Core Task Info -->
                <div class="tab-pane fade show active" id="task-step-1" role="tabpanel">
                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Judul Tugas</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Pasang ODP Desa Harapan" required>
                  </div>
                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Deskripsi Tugas</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Rincian instruksi tugas..."></textarea>
                  </div>

                  <div class="row">
                    <div class="col-md-4 mb-3">
                      <label class="form-label font-weight-bold">Tipe Assignment</label>
                      <select name="assign_type" id="addTaskAssignType" class="form-select form-control" required>
                        <option value="employee">Perorangan Karyawan</option>
                        <option value="team">Seluruh Tim</option>
                        <option value="division">Seluruh Divisi</option>
                      </select>
                    </div>

                    <!-- Assignment targets (swapped dynamically) -->
                    <div class="col-md-8 mb-3" id="assignEmployeeContainer">
                      <label class="form-label font-weight-bold">Pilih Karyawan</label>
                      <select name="employee_id" class="form-select form-control">
                        <option value="">-- Pilih Karyawan --</option>
                        <?php foreach ($allEmployees as $emp): ?>
                          <option value="<?= $emp['id'] ?>"><?= e($emp['nik']) ?> - <?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-8 mb-3 d-none" id="assignTeamContainer">
                      <label class="form-label font-weight-bold">Pilih Tim</label>
                      <select name="team_id" class="form-select form-control">
                        <option value="">-- Pilih Tim --</option>
                        <?php foreach ($allTeams as $tm): ?>
                          <option value="<?= $tm['id'] ?>"><?= e($tm['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-8 mb-3 d-none" id="assignDivisionContainer">
                      <label class="form-label font-weight-bold">Pilih Divisi</label>
                      <select name="division_id" class="form-select form-control">
                        <option value="">-- Pilih Divisi --</option>
                        <?php foreach ($allDivisions as $div): ?>
                          <option value="<?= $div['id'] ?>"><?= e($div['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  </div>
                  
                  <div class="d-flex justify-content-end mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-primary rounded-pill font-weight-bold btn-sm px-4" onclick="var t = new bootstrap.Tab(document.getElementById('task-step-2-tab')); t.show();">
                      Lanjut <i class="fa-solid fa-arrow-right ms-1"></i>
                    </button>
                  </div>
                </div>

                <!-- STEP 2: Dates, Priorities & Bonuses -->
                <div class="tab-pane fade" id="task-step-2" role="tabpanel">
                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label font-weight-bold">Tanggal Mulai</label>
                      <input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label font-weight-bold">Deadline Selesai</label>
                      <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>

                    <div class="col-md-4 mb-3">
                      <label class="form-label font-weight-bold">Prioritas</label>
                      <select name="priority" class="form-select form-control">
                        <option value="low">Rendah</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">Tinggi</option>
                        <option value="urgent">Urgent</option>
                      </select>
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label font-weight-bold">Bonus Terkait (Opsional)</label>
                      <select name="bonus_type_id" class="form-select form-control">
                        <option value="">-- Tanpa Bonus --</option>
                        <?php foreach ($allBonusTypes as $bt): ?>
                          <option value="<?= $bt['id'] ?>"><?= e($bt['name']) ?> (<?= formatRupiah($bt['rate']) ?>)</option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-md-2 mb-3">
                      <label class="form-label font-weight-bold">Qty Bonus</label>
                      <input type="number" name="bonus_quantity" class="form-control" value="1" min="1">
                    </div>
                  </div>

                  <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4" onclick="var t = new bootstrap.Tab(document.getElementById('task-step-1-tab')); t.show();">
                      <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                    </button>
                    <button type="submit" class="btn btn-primary rounded-pill font-weight-bold btn-sm px-4">
                      <i class="fa-solid fa-floppy-disk me-1"></i> Simpan Tugas
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Script to toggle assignment inputs dynamically in modal -->
    <script>
      document.addEventListener('DOMContentLoaded', function() {
          const assignTypeSelect = document.getElementById('addTaskAssignType');
          if (assignTypeSelect) {
              assignTypeSelect.addEventListener('change', function() {
                  const val = this.value;
                  document.getElementById('assignEmployeeContainer').classList.toggle('d-none', val !== 'employee');
                  document.getElementById('assignTeamContainer').classList.toggle('d-none', val !== 'team');
                  document.getElementById('assignDivisionContainer').classList.toggle('d-none', val !== 'division');
              });
          }
      });
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
