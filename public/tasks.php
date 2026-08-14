<?php
// tasks.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Kelola Tugas / To-Do List';

// Auto upgrade schema if needed
$requiredColumns = [
    'admin_notes' => 'TEXT',
    'example_url' => 'TEXT',
    'example_file' => 'TEXT',
    'submission_notes' => 'TEXT',
    'submission_url' => 'TEXT',
    'submission_file' => 'TEXT',
    'audit_notes' => 'TEXT',
    'audited_by' => 'INTEGER',
    'audited_at' => 'DATETIME'
];
foreach ($requiredColumns as $col => $type) {
    try {
        $db->exec("ALTER TABLE tasks ADD COLUMN $col $type");
    } catch (Exception $e) {
        // Column already exists
    }
}

// Helper upload function
function handleTaskUpload($fileKey, $subDir) {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$fileKey];
    $uploadBase = ROOT_PATH . '/public/uploads/tasks/' . $subDir . '/';
    if (!is_dir($uploadBase)) {
        mkdir($uploadBase, 0777, true);
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'txt', 'csv', 'mp4'];
    if (!in_array($ext, $allowed)) {
        return null;
    }
    $filename = time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $uploadBase . $filename;
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return '/uploads/tasks/' . $subDir . '/' . $filename;
    }
    return null;
}

// Helper to render formatted notes with modern interactive items
function renderFormattedNotes($notes, $type = 'primary') {
    if (empty(trim($notes ?? ''))) return '';
    $lines = explode("\n", trim($notes));
    $html = '<div class="d-flex flex-column gap-2.5">';
    $idx = 1;
    
    $styles = [
        'primary' => [
            'card_class' => 'instruction-card-primary',
            'badge_bg' => 'linear-gradient(135deg, #4f46e5 0%, #6366f1 100%)',
            'badge_color' => '#ffffff',
            'icon' => 'fa-solid fa-clipboard-check'
        ],
        'success' => [
            'card_class' => 'instruction-card-success',
            'badge_bg' => 'linear-gradient(135deg, #059669 0%, #10b981 100%)',
            'badge_color' => '#ffffff',
            'icon' => 'fa-solid fa-check-double'
        ],
        'warning' => [
            'card_class' => 'instruction-card-warning',
            'badge_bg' => 'linear-gradient(135deg, #d97706 0%, #f59e0b 100%)',
            'badge_color' => '#ffffff',
            'icon' => 'fa-solid fa-comment-dots'
        ]
    ];
    $s = $styles[$type] ?? $styles['primary'];

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        $isBullet = preg_match('/^[-*•]\s*(.*)$/u', $line, $matches);
        $content = $isBullet ? $matches[1] : $line;
        
        $html .= '<div class="instruction-item-card ' . $s['card_class'] . '">';
        $html .= '  <div class="instruction-item-badge" style="background: ' . $s['badge_bg'] . '; color: ' . $s['badge_color'] . ';">';
        if ($isBullet) {
            $html .= '    <span>' . $idx++ . '</span>';
        } else {
            $html .= '    <i class="' . $s['icon'] . '" style="font-size: 0.75rem;"></i>';
        }
        $html .= '  </div>';
        $html .= '  <div class="instruction-item-text flex-grow-1">';
        $html .= '    ' . nl2br(e($content));
        $html .= '  </div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    // 1. ADD TASK (Admin only)
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
        
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        $exampleUrl = trim($_POST['example_url'] ?? '');
        $exampleFile = handleTaskUpload('example_file', 'examples');

        if (!empty($title)) {
            $stmt = $db->prepare("
                INSERT INTO tasks (title, description, assign_type, division_id, team_id, employee_id, start_date, due_date, priority, status, bonus_type_id, bonus_quantity, admin_notes, example_url, example_file, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $description, $assignType, $divisionId, $teamId, $employeeId, $startDate, $dueDate, $priority, $bonusTypeId, $bonusQuantity, $adminNotes, $exampleUrl, $exampleFile, $user['id']]);
            $taskId = (int)$db->lastInsertId();

            $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, NULL, 'pending', 'Tugas dibuat oleh Admin')");
            $stmtHist->execute([$taskId, $user['id']]);

            // Notify Assignee
            if ($assignType === 'employee' && $employeeId) {
                $stmtUserEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                $stmtUserEmp->execute([$employeeId]);
                $empUserId = $stmtUserEmp->fetchColumn();
                if ($empUserId) {
                    sendNotification($empUserId, 'Tugas Baru Diterima', 'Anda ditugaskan pada: ' . $title, '/tasks.php?id=' . $taskId);
                }
            } elseif ($assignType === 'team' && $teamId) {
                $stmtTeamUsers = $db->prepare("SELECT e.user_id FROM team_members tm JOIN employees e ON tm.employee_id = e.id WHERE tm.team_id = ? AND e.user_id IS NOT NULL");
                $stmtTeamUsers->execute([$teamId]);
                while ($uId = $stmtTeamUsers->fetchColumn()) {
                    sendNotification($uId, 'Tugas Tim Baru Diterima', 'Tim Anda ditugaskan pada: ' . $title, '/tasks.php?id=' . $taskId);
                }
            } elseif ($assignType === 'division' && $divisionId) {
                $stmtDivUsers = $db->prepare("SELECT user_id FROM employees WHERE division_id = ? AND user_id IS NOT NULL");
                $stmtDivUsers->execute([$divisionId]);
                while ($uId = $stmtDivUsers->fetchColumn()) {
                    sendNotification($uId, 'Tugas Divisi Baru Diterima', 'Divisi Anda ditugaskan pada: ' . $title, '/tasks.php?id=' . $taskId);
                }
            }

            logAudit($user['id'], 'ADD_TASK', 'Created task ID ' . $taskId . ' - ' . $title);
            setFlashMessage('success', 'Tugas baru berhasil dibuat lengkap dengan instruksi & referensi.');
            header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
            exit;
        }
    }

    // 2. UPDATE ADMIN GUIDELINES / EXAMPLES (Admin only)
    if ($action === 'update_guidelines') {
        if (!hasPermission('manage_tasks')) {
            setFlashMessage('danger', 'Anda tidak memiliki hak akses untuk mengedit instruksi tugas.');
            header('Location: ' . BASE_URL . '/tasks.php');
            exit;
        }

        $taskId = (int)($_POST['task_id'] ?? 0);
        $adminNotes = trim($_POST['admin_notes'] ?? '');
        $exampleUrl = trim($_POST['example_url'] ?? '');
        
        $newExampleFile = handleTaskUpload('example_file', 'examples');

        $stmtGet = $db->prepare("SELECT example_file FROM tasks WHERE id = ?");
        $stmtGet->execute([$taskId]);
        $currFile = $stmtGet->fetchColumn();

        $fileToSave = $newExampleFile ?: $currFile;

        $stmtUp = $db->prepare("UPDATE tasks SET admin_notes = ?, example_url = ?, example_file = ?, updated_at = datetime('now', 'localtime') WHERE id = ?");
        $stmtUp->execute([$adminNotes, $exampleUrl, $fileToSave, $taskId]);

        $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, NULL, NULL, 'Admin memperbarui catatan instruksi & referensi contoh')");
        $stmtHist->execute([$taskId, $user['id']]);

        logAudit($user['id'], 'UPDATE_TASK_GUIDELINES', "Updated guidelines for Task ID $taskId");
        setFlashMessage('success', 'Catatan dan contoh referensi tugas berhasil diperbarui.');
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }

    // 3. START TASK (Employee / Admin)
    if ($action === 'start_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $stmtT = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmtT->execute([$taskId]);
        $task = $stmtT->fetch();

        if ($task && ($task['status'] === 'pending' || $task['status'] === 'overdue')) {
            $stmtUp = $db->prepare("UPDATE tasks SET status = 'in_progress', updated_at = datetime('now', 'localtime') WHERE id = ?");
            $stmtUp->execute([$taskId]);

            $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, ?, 'in_progress', 'Mulai pengerjaan tugas')");
            $stmtHist->execute([$taskId, $user['id'], $task['status']]);

            logAudit($user['id'], 'START_TASK', "Started Task ID $taskId");
            setFlashMessage('success', 'Tugas telah dimulai (Sedang Dikerjakan). Selamat bekerja!');
        }
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }

    // 4. SUBMIT FOR AUDIT (Employee submits work)
    if ($action === 'submit_audit') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $submissionNotes = trim($_POST['submission_notes'] ?? '');
        $submissionUrl = trim($_POST['submission_url'] ?? '');
        $submissionFile = handleTaskUpload('submission_file', 'submissions');

        $stmtT = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmtT->execute([$taskId]);
        $task = $stmtT->fetch();

        if ($task) {
            $oldFile = $task['submission_file'];
            $fileToSave = $submissionFile ?: $oldFile;

            $stmtUp = $db->prepare("
                UPDATE tasks 
                SET status = 'in_review', 
                    submission_notes = ?, 
                    submission_url = ?, 
                    submission_file = ?, 
                    updated_at = datetime('now', 'localtime') 
                WHERE id = ?
            ");
            $stmtUp->execute([$submissionNotes, $submissionUrl, $fileToSave, $taskId]);

            $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, ?, 'in_review', ?)");
            $noteText = 'Karyawan mengajukan hasil pengerjaan untuk diaudit admin.' . ($submissionNotes ? ' Catatan: ' . $submissionNotes : '');
            $stmtHist->execute([$taskId, $user['id'], $task['status'], $noteText]);

            // Notify Admins
            $stmtAdmins = $db->query("SELECT id FROM users WHERE role_id IN (1, 2) AND status = 'active'");
            while ($adminId = $stmtAdmins->fetchColumn()) {
                sendNotification($adminId, 'Tugas Menunggu Audit', 'Karyawan ' . ($user['employee_name'] ?? $user['username']) . ' telah mengajukan tugas: ' . $task['title'] . ' untuk diaudit.', '/tasks.php?id=' . $taskId);
            }

            logAudit($user['id'], 'SUBMIT_TASK_AUDIT', "Submitted Task ID $taskId for audit");
            setFlashMessage('success', 'Hasil pekerjaan berhasil dikirim. Tugas saat ini sedang MENUNGGU AUDIT oleh Admin.');
        }
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }

    // 5. AUDIT TASK (Admin Approves or Requests Revision)
    if ($action === 'audit_task') {
        if (!hasPermission('manage_tasks')) {
            setFlashMessage('danger', 'Hanya Super Admin / HR yang memiliki hak untuk melakukan audit tugas.');
            header('Location: ' . BASE_URL . '/tasks.php');
            exit;
        }

        $taskId = (int)($_POST['task_id'] ?? 0);
        $decision = $_POST['audit_decision'] ?? ''; // 'approve' or 'revise'
        $auditNotes = trim($_POST['audit_notes'] ?? '');

        $stmtT = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmtT->execute([$taskId]);
        $task = $stmtT->fetch();

        if ($task) {
            if ($decision === 'approve') {
                // Task Approved & Completed
                $stmtUp = $db->prepare("
                    UPDATE tasks 
                    SET status = 'completed', 
                        audit_notes = ?, 
                        audited_by = ?, 
                        audited_at = datetime('now', 'localtime'), 
                        updated_at = datetime('now', 'localtime') 
                    WHERE id = ?
                ");
                $stmtUp->execute([$auditNotes, $user['id'], $taskId]);

                $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, ?, 'completed', ?)");
                $stmtHist->execute([$taskId, $user['id'], $task['status'], 'AUDIT LOLOS: Tugas dinyatakan selesai oleh Admin.' . ($auditNotes ? ' Catatan: ' . $auditNotes : '')]);

                // Create Bonus Transaction if applicable
                if ($task['bonus_type_id'] > 0 && $task['employee_id'] > 0) {
                    $stmtBt = $db->prepare("SELECT rate FROM bonus_types WHERE id = ?");
                    $stmtBt->execute([$task['bonus_type_id']]);
                    $rate = (float)$stmtBt->fetchColumn();
                    $totalBonus = $rate * $task['bonus_quantity'];

                    $stmtBonus = $db->prepare("
                        INSERT INTO bonus_transactions (employee_id, bonus_type_id, task_id, transaction_date, quantity, rate_snapshot, total_amount, status, notes, approved_by, approved_at, created_by)
                        VALUES (?, ?, ?, date('now'), ?, ?, ?, 'approved', ?, ?, datetime('now', 'localtime'), ?)
                    ");
                    $stmtBonus->execute([
                        $task['employee_id'],
                        $task['bonus_type_id'],
                        $taskId,
                        $task['bonus_quantity'],
                        $rate,
                        $totalBonus,
                        'Bonus otomatis dari kelolosan audit task: ' . $task['title'],
                        $user['id'],
                        $user['id']
                    ]);
                }

                // Notify Assigned Employee
                if ($task['employee_id']) {
                    $stmtUserEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                    $stmtUserEmp->execute([$task['employee_id']]);
                    $empUserId = $stmtUserEmp->fetchColumn();
                    if ($empUserId) {
                        sendNotification($empUserId, 'Audit Tugas Lolos & Selesai! 🎉', 'Selamat, tugas Anda "' . $task['title'] . '" telah disetujui & selesai oleh Admin.', '/tasks.php?id=' . $taskId);
                    }
                }

                logAudit($user['id'], 'AUDIT_APPROVE_TASK', "Approved & completed Task ID $taskId");
                setFlashMessage('success', 'Tugas berhasil DISETUJUI & LOLOS AUDIT. Status tugas telah SELESAI.');
            } elseif ($decision === 'revise') {
                // Task Revision Required -> Return to in_progress
                $stmtUp = $db->prepare("
                    UPDATE tasks 
                    SET status = 'in_progress', 
                        audit_notes = ?, 
                        audited_by = ?, 
                        audited_at = datetime('now', 'localtime'), 
                        updated_at = datetime('now', 'localtime') 
                    WHERE id = ?
                ");
                $stmtUp->execute([$auditNotes, $user['id'], $taskId]);

                $stmtHist = $db->prepare("INSERT INTO task_history (task_id, user_id, old_status, new_status, notes) VALUES (?, ?, ?, 'in_progress', ?)");
                $stmtHist->execute([$taskId, $user['id'], $task['status'], 'AUDIT BUTUH REVISI: ' . $auditNotes]);

                // Notify Assigned Employee
                if ($task['employee_id']) {
                    $stmtUserEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                    $stmtUserEmp->execute([$task['employee_id']]);
                    $empUserId = $stmtUserEmp->fetchColumn();
                    if ($empUserId) {
                        sendNotification($empUserId, 'Tugas Perlu Revisi ⚠️', 'Tugas "' . $task['title'] . '" perlu direvisi: ' . ($auditNotes ?: 'Silakan periksa catatan audit.'), '/tasks.php?id=' . $taskId);
                    }
                }

                logAudit($user['id'], 'AUDIT_REVISE_TASK', "Requested revision for Task ID $taskId");
                setFlashMessage('warning', 'Tugas dikembalikan ke Karyawan untuk DIREVISI sesuai catatan audit.');
            }
        }
        header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
        exit;
    }

    // 6. UPDATE STATUS (General - Restricted for non-admins)
    if ($action === 'update_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = $_POST['status'] ?? 'pending';
        $notes = trim($_POST['notes'] ?? '');

        $stmtT = $db->prepare("SELECT * FROM tasks WHERE id = ?");
        $stmtT->execute([$taskId]);
        $task = $stmtT->fetch();

        if ($task) {
            // Guard: Non-admins cannot mark tasks as completed directly
            if ($newStatus === 'completed' && !hasPermission('manage_tasks')) {
                setFlashMessage('danger', 'Karyawan tidak dapat menyelesaikan tugas secara langsung. Silakan ajukan tugas untuk Diaudit oleh Admin.');
                header('Location: ' . BASE_URL . '/tasks.php?id=' . $taskId);
                exit;
            }

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
                        INSERT INTO bonus_transactions (employee_id, bonus_type_id, task_id, transaction_date, quantity, rate_snapshot, total_amount, status, notes, approved_by, approved_at, created_by)
                        VALUES (?, ?, ?, date('now'), ?, ?, ?, 'approved', ?, ?, datetime('now', 'localtime'), ?)
                    ");
                    $stmtBonus->execute([
                        $task['employee_id'],
                        $task['bonus_type_id'],
                        $taskId,
                        $task['bonus_quantity'],
                        $rate,
                        $totalBonus,
                        'Bonus otomatis dari penyelesaian task: ' . $task['title'],
                        $user['id'],
                        $user['id']
                    ]);

                    setFlashMessage('success', 'Status tugas diperbarui menjadi SELESAI dan bonus telah dicatat.');
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
    SELECT t.*, e.full_name as pic_name, d.name as division_name, tm.name as team_name, bt.name as bonus_type_name, bt.rate as bonus_rate,
           u_aud.username as auditor_name
    FROM tasks t
    LEFT JOIN employees e ON t.employee_id = e.id
    LEFT JOIN divisions d ON t.division_id = d.id
    LEFT JOIN teams tm ON t.team_id = tm.id
    LEFT JOIN bonus_types bt ON t.bonus_type_id = bt.id
    LEFT JOIN users u_aud ON t.audited_by = u_aud.id
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
    $sql .= " AND (t.title LIKE ? OR t.description LIKE ? OR t.admin_notes LIKE ?)";
    $params[] = "%$search%";
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

$inReviewTasks = array_filter($tasks, function($t) {
    return $t['status'] === 'in_review';
});

$completedTasks = array_filter($tasks, function($t) {
    return $t['status'] === 'completed';
});

// Detail view task & history
$selectedTask = null;
$taskHistories = [];
if ($selectedTaskId > 0) {
    $stmtDetail = $db->prepare("
        SELECT t.*, e.full_name as pic_name, e.nik as pic_nik, d.name as division_name, tm.name as team_name, 
               bt.name as bonus_type_name, bt.rate as bonus_rate, 
               u_cr.username as creator_name,
               u_aud.username as auditor_name,
               u_aud_emp.full_name as auditor_fullname
        FROM tasks t
        LEFT JOIN employees e ON t.employee_id = e.id
        LEFT JOIN divisions d ON t.division_id = d.id
        LEFT JOIN teams tm ON t.team_id = tm.id
        LEFT JOIN bonus_types bt ON t.bonus_type_id = bt.id
        LEFT JOIN users u_cr ON t.created_by = u_cr.id
        LEFT JOIN users u_aud ON t.audited_by = u_aud.id
        LEFT JOIN employees u_aud_emp ON u_aud.id = u_aud_emp.user_id
        WHERE t.id = ?
    ");
    $stmtDetail->execute([$selectedTaskId]);
    $selectedTask = $stmtDetail->fetch();

    if ($selectedTask) {
        $stmtHist = $db->prepare("
            SELECT th.*, u.username, e.full_name as user_fullname
            FROM task_history th
            JOIN users u ON th.user_id = u.id
            LEFT JOIN employees e ON u.id = e.user_id
            WHERE th.task_id = ?
            ORDER BY th.created_at DESC
        ");
        $stmtHist->execute([$selectedTaskId]);
        $taskHistories = $stmtHist->fetchAll();
    }
}

// Fetch lists for modal
$allEmployees = $db->query("SELECT e.id, e.full_name, e.nik FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1) ORDER BY e.full_name ASC")->fetchAll();
$allDivisions = $db->query("SELECT id, name FROM divisions WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$allTeams = $db->query("SELECT id, name FROM teams WHERE status = 'active' ORDER BY name ASC")->fetchAll();
$allBonusTypes = $db->query("SELECT id, name, rate FROM bonus_types WHERE status = 'active' ORDER BY name ASC")->fetchAll();

// Determine active tab based on selected task status
$activeTab = 'pending';
if ($selectedTask) {
    if ($selectedTask['status'] === 'completed') {
        $activeTab = 'completed';
    } elseif ($selectedTask['status'] === 'in_review') {
        $activeTab = 'inreview';
    } elseif ($selectedTask['status'] === 'in_progress') {
        $activeTab = 'inprogress';
    }
} elseif (count($inReviewTasks) > 0 && hasPermission('manage_tasks')) {
    $activeTab = 'inreview';
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
                <i class="fa-solid fa-circle-check text-success fs-4" title="Tugas Selesai & Lolos Audit"></i>
              <?php elseif ($t['status'] === 'in_review'): ?>
                <i class="fa-solid fa-clipboard-check text-warning fs-4" title="Menunggu Audit Admin"></i>
              <?php elseif ($t['status'] === 'in_progress'): ?>
                <i class="fa-solid fa-spinner fa-spin text-primary fs-4" title="Sedang Dikerjakan"></i>
              <?php else: ?>
                <i class="fa-regular fa-circle text-muted fs-4" title="Belum Dikerjakan"></i>
              <?php endif; ?>
            </a>
            
            <div>
              <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/tasks.php?id=<?= $t['id'] ?>" class="h6 font-weight-bold text-dark text-decoration-none mb-1 d-block">
                  <?= e($t['title']) ?>
                </a>
                <?php if (!empty($t['admin_notes']) || !empty($t['example_url']) || !empty($t['example_file'])): ?>
                  <span class="badge bg-light text-primary border rounded-pill px-2 py-0.5" title="Tersedia Catatan / Contoh dari Admin" style="font-size: 0.68rem;">
                    <i class="fa-solid fa-lightbulb text-warning me-1"></i> Ada Contoh
                  </span>
                <?php endif; ?>
              </div>
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
            <?php if ($t['status'] === 'in_review'): ?>
              <span class="badge bg-warning text-dark font-weight-bold px-2 py-1.5" style="border-radius: 6px;">
                <i class="fa-solid fa-hourglass-half me-1"></i> Menunggu Audit
              </span>
            <?php endif; ?>

            <?php if ($t['bonus_type_name']): ?>
              <span class="badge text-dark font-weight-bold px-2 py-1.5" style="background-color: #fffbeb; border: 1px solid #fde68a; border-radius: 6px;">
                <i class="fa-solid fa-coins me-1 text-warning"></i> +<?= formatRupiah($t['bonus_rate'] * $t['bonus_quantity']) ?>
              </span>
            <?php endif; ?>

            <span class="badge bg-<?= $t['priority'] === 'urgent' ? 'danger' : ($t['priority'] === 'high' ? 'warning' : ($t['priority'] === 'normal' ? 'info' : 'secondary')) ?> text-white font-weight-bold" style="font-size: 0.7rem; border-radius: 6px;">
              <?= strtoupper($t['priority']) ?>
            </span>

            <button type="button" class="btn btn-xs btn-outline-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;" onclick="event.preventDefault(); event.stopPropagation(); TaskChatEngine.openChat(<?= (int)$t['id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>);" title="Chat / Diskusi Tugas">
              <i class="fa-solid fa-comments"></i>
            </button>

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
          border-radius: 14px !important;
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

      /* ULTRA-PREMIUM GUIDANCE BOX STYLING */
      .guidance-container {
          border-radius: 16px;
          box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.1), 0 4px 10px -2px rgba(79, 70, 229, 0.04);
          overflow: hidden;
          border: 1px solid #e0e7ff;
          margin-bottom: 1.5rem;
          background: #ffffff;
      }
      .guidance-banner-header {
          background: linear-gradient(135deg, #1e1b4b 0%, #312e81 55%, #4338ca 100%);
          padding: 14px 18px;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: space-between;
      }
      .guidance-icon-circle {
          width: 36px;
          height: 36px;
          border-radius: 10px;
          background: rgba(255, 255, 255, 0.15);
          backdrop-filter: blur(8px);
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: inset 0 0 10px rgba(255, 255, 255, 0.2);
      }
      .guidance-edit-btn {
          background: rgba(255, 255, 255, 0.18);
          backdrop-filter: blur(8px);
          color: #ffffff;
          border: 1px solid rgba(255, 255, 255, 0.3);
          border-radius: 30px;
          padding: 5px 14px;
          font-size: 0.78rem;
          font-weight: 700;
          transition: all 0.2s ease;
          text-decoration: none;
      }
      .guidance-edit-btn:hover {
          background: #ffffff;
          color: #312e81 !important;
          transform: translateY(-1px);
          box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      }
      .guidance-body {
          padding: 18px;
          background: #fcfdfe;
      }
      .guidance-subcard {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 12px;
          padding: 14px 16px;
          box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
      }

      .ref-interactive-link {
          background: #ffffff;
          border: 1px solid #cbd5e1;
          border-radius: 12px;
          padding: 12px 16px;
          transition: all 0.25s ease;
          text-decoration: none !important;
          display: flex;
          align-items: center;
          gap: 12px;
      }
      .ref-interactive-link:hover {
          transform: translateY(-2px);
          border-color: #4B49AC;
          box-shadow: 0 6px 16px rgba(75, 73, 172, 0.15);
      }

      .empty-guidance-card {
          border: 2px dashed #cbd5e1;
          background: linear-gradient(135deg, #f8faff 0%, #faf5ff 100%);
          border-radius: 14px;
          padding: 24px 16px;
          text-align: center;
      }

      /* INSTRUCTION ITEM CARDS (Spacious & Modern) */
      .instruction-item-card {
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 14px;
          padding: 14px 18px;
          display: flex;
          align-items: flex-start;
          gap: 14px;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
          transition: all 0.25s ease;
      }
      .instruction-item-card:hover {
          box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
          transform: translateY(-2px);
      }
      .instruction-card-primary {
          border-left: 4px solid #4f46e5;
      }
      .instruction-card-primary:hover {
          border-color: #818cf8;
          border-left-color: #4338ca;
      }
      .instruction-card-success {
          border-left: 4px solid #10b981;
      }
      .instruction-card-success:hover {
          border-color: #6ee7b7;
          border-left-color: #059669;
      }
      .instruction-card-warning {
          border-left: 4px solid #f59e0b;
      }
      .instruction-card-warning:hover {
          border-color: #fcd34d;
          border-left-color: #d97706;
      }
      .instruction-item-badge {
          width: 28px;
          height: 28px;
          border-radius: 8px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.8rem;
          font-weight: 700;
          flex-shrink: 0;
          margin-top: 1px;
          box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
      }
      .instruction-item-text {
          color: #1e293b;
          font-size: 0.92rem;
          font-weight: 500;
          line-height: 1.65;
          word-break: break-word;
      }

      /* SUBMISSION BOX STYLING */
      .submission-container {
          border-radius: 16px;
          box-shadow: 0 8px 20px -4px rgba(16, 185, 129, 0.1);
          overflow: hidden;
          border: 1px solid #bbf7d0;
          margin-bottom: 1.5rem;
          background: #ffffff;
      }
      .submission-banner-header {
          background: linear-gradient(135deg, #064e3b 0%, #047857 60%, #10b981 100%);
          padding: 14px 18px;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: space-between;
      }

      /* AUDIT BOX STYLING */
      .audit-container {
          border-radius: 16px;
          box-shadow: 0 8px 20px -4px rgba(245, 158, 11, 0.1);
          overflow: hidden;
          border: 1px solid #fde68a;
          margin-bottom: 1.5rem;
          background: #ffffff;
      }
      .audit-banner-header {
          background: linear-gradient(135deg, #78350f 0%, #b45309 60%, #f59e0b 100%);
          padding: 14px 18px;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: space-between;
      }

      /* FLOATING ACTIONS DOCK (Side-by-Side Floating Action Buttons) */
      .floating-actions-dock {
          position: fixed;
          bottom: 24px;
          right: 24px;
          display: flex;
          align-items: center;
          gap: 12px;
          z-index: 1050;
          pointer-events: auto;
      }
      .btn-fab-item {
          width: 52px;
          height: 52px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18), 0 2px 6px rgba(0, 0, 0, 0.08);
          cursor: pointer;
          border: none;
          transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
          position: relative;
          text-decoration: none !important;
      }
      .btn-fab-item:hover {
          transform: translateY(-3px) scale(1.06);
      }
      .btn-fab-add {
          background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%);
          box-shadow: 0 6px 20px rgba(75, 73, 172, 0.45);
      }
      .btn-fab-chat {
          background: linear-gradient(135deg, #0ea5e9 0%, #3b82f6 100%);
          box-shadow: 0 6px 20px rgba(14, 165, 233, 0.45);
      }

      .chat-unread-badge {
          position: absolute;
          top: -4px;
          right: -4px;
          background: #ef4444;
          color: #ffffff;
          font-size: 0.72rem;
          font-weight: 700;
          width: 22px;
          height: 22px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          border: 2px solid #ffffff;
          box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
          animation: pulseChatBadge 2s infinite;
      }
      @keyframes pulseChatBadge {
          0% { transform: scale(1); }
          50% { transform: scale(1.15); }
          100% { transform: scale(1); }
      }

      /* BACKDROP & COMPACT DOCK */
      .chat-backdrop {
          position: fixed;
          top: 0;
          left: 0;
          width: 100vw;
          height: 100vh;
          background: rgba(15, 23, 42, 0.45);
          backdrop-filter: blur(2px);
          z-index: 1055;
          opacity: 0;
          pointer-events: none;
          transition: opacity 0.3s ease;
      }
      .chat-backdrop.active {
          opacity: 1;
          pointer-events: auto;
      }
      .chat-sheet-handle {
          width: 36px;
          height: 4px;
          border-radius: 2px;
          background: rgba(255, 255, 255, 0.4);
          margin: 2px auto 6px auto;
          display: none;
      }

      .task-chat-popup {
          position: fixed;
          bottom: 24px;
          right: 24px;
          width: 380px;
          height: 540px;
          max-height: calc(100vh - 48px);
          background: #ffffff;
          border-radius: 20px;
          box-shadow: 0 16px 40px rgba(15, 23, 42, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.05);
          display: flex;
          flex-direction: column;
          z-index: 1060;
          overflow: hidden;
          transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
          transform: translateY(20px) scale(0.95);
          opacity: 0;
          pointer-events: none;
      }
      .task-chat-popup.active {
          transform: translateY(0) scale(1);
          opacity: 1;
          pointer-events: auto;
      }
      .task-chat-popup.minimized {
          height: 52px !important;
          max-height: 52px !important;
          min-height: 52px !important;
          overflow: hidden !important;
          cursor: pointer;
      }
      .task-chat-popup.minimized .chat-popup-body,
      .task-chat-popup.minimized .chat-popup-footer {
          display: none !important;
      }
      .task-chat-popup.minimized .chat-popup-header {
          border-radius: 18px !important;
      }

      /* WHATSAPP-STYLE VOICE NOTE RECORDING BAR */
      .vn-recording-bar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          background: #ffffff;
          border: 1px solid #cbd5e1;
          border-radius: 28px;
          padding: 4px 6px 4px 8px;
          box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
          animation: slideInRecording 0.22s ease-out;
          width: 100%;
      }
      @keyframes slideInRecording {
          from { transform: translateY(6px); opacity: 0; }
          to { transform: translateY(0); opacity: 1; }
      }
      .vn-rec-trash-btn {
          width: 32px;
          height: 32px;
          border-radius: 50%;
          background: #fee2e2;
          color: #ef4444;
          border: none;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.82rem;
          transition: all 0.2s ease;
          cursor: pointer;
      }
      .vn-rec-trash-btn:hover {
          background: #ef4444;
          color: #ffffff;
          transform: scale(1.08);
      }
      .vn-rec-glowing-mic {
          width: 22px;
          height: 22px;
          border-radius: 50%;
          background: #fee2e2;
          color: #ef4444;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.72rem;
          animation: pulseRedMic 1.2s infinite;
      }
      @keyframes pulseRedMic {
          0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); transform: scale(1); }
          50% { box-shadow: 0 0 0 5px rgba(239, 68, 68, 0); transform: scale(1.1); }
          100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); transform: scale(1); }
      }
      .vn-rec-timer {
          font-weight: 700;
          font-size: 0.85rem;
          color: #ef4444;
          font-variant-numeric: tabular-nums;
          letter-spacing: 0.5px;
      }
      .vn-rec-waves {
          display: flex;
          align-items: center;
          gap: 2.5px;
          height: 18px;
      }
      .vn-rec-waves span {
          width: 2.5px;
          background: #25d366;
          border-radius: 2px;
          display: inline-block;
          animation: soundWaveAnim 0.8s ease-in-out infinite alternate;
      }
      .vn-rec-waves .w1 { height: 6px; animation-delay: 0.1s; }
      .vn-rec-waves .w2 { height: 14px; animation-delay: 0.25s; }
      .vn-rec-waves .w3 { height: 9px; animation-delay: 0.4s; }
      .vn-rec-waves .w4 { height: 16px; animation-delay: 0.15s; }
      .vn-rec-waves .w5 { height: 12px; animation-delay: 0.35s; }
      @keyframes soundWaveAnim {
          0% { transform: scaleY(0.4); opacity: 0.6; }
          100% { transform: scaleY(1.3); opacity: 1; }
      }
      .vn-rec-send-btn {
          width: 34px;
          height: 34px;
          border-radius: 50%;
          background: #25d366;
          color: #ffffff;
          border: none;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.85rem;
          box-shadow: 0 2px 8px rgba(37, 211, 102, 0.4);
          transition: all 0.2s ease;
          cursor: pointer;
          flex-shrink: 0;
      }
      .vn-rec-send-btn:hover {
          transform: scale(1.08);
          background: #20ba59;
      }
      /* WHATSAPP-STYLE VOICE NOTE BUBBLE STYLING */
      .whatsapp-vn-bubble {
          width: 220px;
          padding: 4px 2px 2px 2px;
          user-select: none;
      }
      .vn-play-btn {
          width: 36px;
          height: 36px;
          border-radius: 50%;
          background: #25d366;
          color: #ffffff;
          border: none;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.9rem;
          flex-shrink: 0;
          box-shadow: 0 2px 8px rgba(37, 211, 102, 0.4);
          transition: all 0.2s ease;
          cursor: pointer;
      }
      .vn-play-btn:hover {
          transform: scale(1.08);
          background: #20ba59;
      }
      .chat-bubble-row.self .vn-play-btn {
          background: #ffffff;
          color: #4f46e5;
          box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
      }
      .chat-bubble-row.self .vn-play-btn:hover {
          background: #f1f5f9;
      }

      .vn-track-container {
          flex-grow: 1;
          position: relative;
          height: 24px;
          display: flex;
          align-items: center;
          cursor: pointer;
          overflow: hidden;
      }
      .vn-waveform {
          display: flex;
          align-items: center;
          gap: 2.5px;
          width: 100%;
          height: 100%;
      }
      .vn-waveform span {
          width: 3px;
          background: #cbd5e1;
          border-radius: 2px;
          display: inline-block;
          transition: background 0.15s ease, height 0.15s ease;
      }
      .vn-waveform span.played {
          background: #25d366 !important;
      }
      .chat-bubble-row.self .vn-waveform span {
          background: rgba(255, 255, 255, 0.4);
      }
      .chat-bubble-row.self .vn-waveform span.played {
          background: #ffffff !important;
      }

      .vn-mic-badge {
          width: 30px;
          height: 30px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.82rem;
          flex-shrink: 0;
          position: relative;
      }
      .vn-mic-badge.partner {
          background: #dcfce7;
          color: #15803d;
          border: 1px solid #bbf7d0;
      }
      .vn-mic-badge.self {
          background: rgba(255, 255, 255, 0.22);
          color: #ffffff;
          border: 1px solid rgba(255, 255, 255, 0.35);
      }

      .vn-timer-text {
          font-size: 0.68rem;
          font-weight: 600;
          color: #64748b;
      }
      .chat-bubble-row.self .vn-timer-text {
          color: rgba(255, 255, 255, 0.85);
      }
      .vn-speed-btn {
          background: rgba(0, 0, 0, 0.06);
          border: none;
          border-radius: 10px;
          font-size: 0.62rem;
          font-weight: 700;
          padding: 1px 5px;
          color: #475569;
          cursor: pointer;
          transition: all 0.2s ease;
      }
      .vn-speed-btn:hover {
          background: rgba(0, 0, 0, 0.12);
      }
      .chat-bubble-row.self .vn-speed-btn {
          background: rgba(255, 255, 255, 0.22);
          color: #ffffff;
      }
      @media (max-width: 768px) {
          .floating-actions-dock {
              bottom: 74px !important;
              right: 14px !important;
              gap: 8px !important;
          }
          .btn-fab-item {
              width: 44px !important;
              height: 44px !important;
          }
          .btn-fab-item i {
              font-size: 1.05rem !important;
          }
          
          /* When chat is active on mobile, hide the floating actions dock to avoid overlay */
          body.chat-active .floating-actions-dock {
              display: none !important;
          }
          /* FLOATING POPUP CARD MODAL ON MOBILE */
          .task-chat-popup {
              width: calc(100% - 28px) !important;
              max-width: 380px !important;
              height: 480px !important;
              max-height: calc(100vh - 130px) !important;
              bottom: 70px !important;
              right: 14px !important;
              left: auto !important;
              top: auto !important;
              margin: 0 !important;
              border-radius: 18px !important;
              z-index: 1060 !important;
              box-shadow: 0 16px 40px rgba(15, 23, 42, 0.28), 0 0 0 1px rgba(0, 0, 0, 0.08) !important;
              transform: scale(0.92) translateY(20px) !important;
              opacity: 0 !important;
              pointer-events: none !important;
              transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1) !important;
          }
          .task-chat-popup.active {
              transform: scale(1) translateY(0) !important;
              opacity: 1 !important;
              pointer-events: auto !important;
          }
          .chat-sheet-handle {
              display: none !important;
          }
          .chat-popup-header {
              padding: 10px 14px !important;
              border-radius: 18px 18px 0 0;
          }
          .chat-popup-header h6 {
              font-size: 0.84rem !important;
          }
          .chat-popup-header small {
              font-size: 0.64rem !important;
          }
          .chat-popup-body {
              padding: 12px !important;
              gap: 8px !important;
          }
          .chat-bubble {
              padding: 7px 11px !important;
              font-size: 0.82rem !important;
              border-radius: 13px !important;
          }
          .chat-avatar {
              width: 26px !important;
              height: 26px !important;
              font-size: 0.65rem !important;
          }
          .chat-popup-footer {
              padding: 8px 12px !important;
          }
          .chat-popup-footer input {
              font-size: 0.82rem !important;
              padding: 6px 12px !important;
          }
          .chat-popup-footer button {
              width: 32px !important;
              height: 32px !important;
          }
      }

      .chat-popup-header {
          background: linear-gradient(135deg, #1e1b4b 0%, #312e81 60%, #4338ca 100%);
          padding: 12px 16px;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: space-between;
          flex-shrink: 0;
          border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      }
      .chat-popup-body {
          flex-grow: 1;
          overflow-y: auto;
          padding: 16px;
          background: #f8fafc;
          display: flex;
          flex-direction: column;
          gap: 12px;
      }
      .chat-popup-footer {
          padding: 12px 14px;
          background: #ffffff;
          border-top: 1px solid #e2e8f0;
          flex-shrink: 0;
      }

      /* CHAT BUBBLE STYLES */
      .chat-bubble-row {
          display: flex;
          align-items: flex-end;
          gap: 8px;
          margin-bottom: 2px;
      }
      .chat-bubble-row.self {
          flex-direction: row-reverse;
      }
      .chat-avatar {
          width: 32px;
          height: 32px;
          border-radius: 50%;
          background: #e2e8f0;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.75rem;
          font-weight: 700;
          color: #475569;
          flex-shrink: 0;
          border: 1px solid #cbd5e1;
          overflow: hidden;
      }
      .chat-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
      }
      .chat-bubble-content {
          max-width: 75%;
          display: flex;
          flex-direction: column;
      }
      .chat-bubble-row.self .chat-bubble-content {
          align-items: flex-end;
      }
      .chat-sender-label {
          font-size: 0.72rem;
          color: #64748b;
          font-weight: 600;
          margin-bottom: 3px;
          padding: 0 4px;
      }
      .chat-bubble {
          padding: 10px 14px;
          border-radius: 16px;
          font-size: 0.88rem;
          line-height: 1.5;
          word-break: break-word;
          box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      }
      .chat-bubble-row.self .chat-bubble {
          background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
          color: #ffffff;
          border-bottom-right-radius: 4px;
      }
      .chat-bubble-row.partner .chat-bubble {
          background: #ffffff;
          color: #1e293b;
          border: 1px solid #e2e8f0;
          border-bottom-left-radius: 4px;
      }
      .chat-meta {
          font-size: 0.68rem;
          color: #94a3b8;
          margin-top: 3px;
          padding: 0 4px;
      }
      .chat-attachment-card {
          background: rgba(255, 255, 255, 0.95);
          border: 1px solid #cbd5e1;
          border-radius: 10px;
          padding: 8px 10px;
          display: flex;
          align-items: center;
          gap: 8px;
          text-decoration: none !important;
          color: #1e293b;
          margin-top: 6px;
          font-size: 0.78rem;
          font-weight: 600;
      }
      .chat-attachment-img {
          max-width: 100%;
          max-height: 150px;
          border-radius: 8px;
          margin-top: 6px;
          object-fit: cover;
          border: 1px solid rgba(0,0,0,0.1);
      }

      /* WHATSAPP-STYLE QUOTED REPLY BOX */
      .chat-quote-box {
          background: rgba(0, 0, 0, 0.05);
          border-left: 3.5px solid #25d366;
          border-radius: 6px;
          padding: 4px 8px;
          margin-bottom: 6px;
          cursor: pointer;
          font-size: 0.76rem;
          line-height: 1.3;
          transition: opacity 0.15s ease;
      }
      .chat-bubble-row.self .chat-quote-box {
          background: rgba(255, 255, 255, 0.18);
          border-left-color: #ffffff;
          color: #ffffff;
      }
      .chat-quote-box:hover {
          opacity: 0.85;
      }
      .quote-sender {
          font-weight: 700;
          font-size: 0.72rem;
          color: #25d366;
      }
      .chat-bubble-row.self .quote-sender {
          color: #ffffff;
      }
      .quote-text {
          color: inherit;
          font-size: 0.74rem;
          opacity: 0.9;
      }

      /* BUBBLE WRAPPER & HOVER ACTIONS */
      .chat-bubble-wrapper {
          position: relative;
          display: inline-block;
          max-width: 100%;
      }
      .chat-bubble-actions {
          position: absolute;
          top: -12px;
          right: 4px;
          display: none;
          background: #ffffff;
          border: 1px solid #e2e8f0;
          border-radius: 20px;
          padding: 2px 4px;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
          z-index: 5;
          gap: 2px;
      }
      .chat-bubble-row.self .chat-bubble-actions {
          right: auto;
          left: 4px;
      }
      .chat-bubble-wrapper:hover .chat-bubble-actions,
      .chat-bubble-wrapper.menu-active .chat-bubble-actions {
          display: flex;
      }
      .btn-bubble-act {
          width: 22px;
          height: 22px;
          border-radius: 50%;
          border: none;
          background: transparent;
          color: #64748b;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 0.7rem;
          cursor: pointer;
          transition: all 0.15s ease;
      }
      .btn-bubble-act:hover {
          background: #f1f5f9;
          color: #1e293b;
          transform: scale(1.1);
      }
      .btn-bubble-act.danger:hover {
          background: #fee2e2;
          color: #ef4444;
      }

      /* ACTION BANNERS ABOVE FOOTER */
      .chat-action-banner {
          background: #f1f5f9;
          border-left: 4px solid #4f46e5;
          border-radius: 8px;
          padding: 6px 10px;
          margin-bottom: 8px;
          animation: slideDownBanner 0.2s ease-out;
      }
      .chat-action-banner.reply-mode {
          border-left-color: #25d366;
          background: #f0fdf4;
      }
      .chat-action-banner.edit-mode {
          border-left-color: #f59e0b;
          background: #fefce8;
      }
      @keyframes slideDownBanner {
          from { opacity: 0; transform: translateY(-4px); }
          to { opacity: 1; transform: translateY(0); }
      }
      .chat-edited-tag {
          font-size: 0.62rem;
          font-style: italic;
          color: #94a3b8;
          margin-left: 3px;
      }
      .chat-bubble-row.self .chat-edited-tag {
          color: rgba(255, 255, 255, 0.75);
      }
      .chat-deleted-text {
          font-style: italic;
          color: #94a3b8;
          font-size: 0.82rem;
      }
      .chat-bubble-row.self .chat-deleted-text {
          color: rgba(255, 255, 255, 0.7);
      }
      .chat-msg-highlight {
          animation: highlightMsg 1.6s ease;
      }
      @keyframes highlightMsg {
          0% { background: rgba(79, 70, 229, 0.2); }
          100% { background: transparent; }
      }
    </style>

    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4 shadow-sm" role="alert">
          <i class="fa-solid fa-circle-info me-2"></i> <?= e($flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
          <h3 class="font-weight-bold mb-1">Kelola Tugas / To-Do List</h3>
          <p class="text-muted mb-0">Instruksi terarah, contoh pengerjaan, dan audit kualitas project sebelum penyelesaian tugas</p>
        </div>
        <?php if (hasPermission('manage_tasks')): ?>
          <!-- Tombol Desktop (Header) -->
          <button class="btn btn-primary rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#addTaskModal">
            <i class="fa-solid fa-plus me-1"></i> Buat Tugas Baru
          </button>
        <?php endif; ?>
      </div>

      <!-- Filters & Search -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body py-3">
          <form action="<?= BASE_URL ?>/tasks.php" method="GET">
            <div class="row g-2 align-items-center">
              
              <!-- Search bar -->
              <div class="col-8 col-md-4">
                <input type="text" name="search" class="form-control form-control-sm rounded-3" placeholder="Cari judul atau instruksi..." value="<?= e($search) ?>">
              </div>
              
              <!-- Tombol Pemicu Collapse (Hanya Mobile) -->
              <div class="col-4 d-md-none text-end">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100 rounded-3 font-weight-bold" data-bs-toggle="collapse" data-bs-target="#advancedFiltersCollapse" aria-expanded="false" style="padding: 0.55rem 0.5rem !important;">
                  <i class="fa-solid fa-sliders me-1"></i> Filter
                </button>
              </div>

              <!-- Advanced Filters Panel -->
              <div class="col-12 col-md-8 collapse d-md-block" id="advancedFiltersCollapse">
                <div class="row g-2 mt-1 mt-md-0">
                  <div class="col-md-5">
                    <select name="status" class="form-select form-control form-control-sm rounded-3">
                      <option value="">-- Semua Status --</option>
                      <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Belum Dikerjakan</option>
                      <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>Sedang Dikerjakan</option>
                      <option value="in_review" <?= $statusFilter === 'in_review' ? 'selected' : '' ?>>Menunggu Audit Admin</option>
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
              <button class="nav-link <?= $activeTab === 'inreview' ? 'active' : '' ?> font-weight-bold btn-sm py-1.5 px-3 rounded-pill text-dark border position-relative" id="tab-inreview" data-bs-toggle="tab" data-bs-target="#pane-inreview" type="button" role="tab" style="font-size: 0.85rem;">
                <i class="fa-solid fa-clipboard-check me-1 text-warning"></i> Menunggu Audit
                <?php if (count($inReviewTasks) > 0): ?>
                  <span class="badge bg-danger rounded-pill ms-1"><?= count($inReviewTasks) ?></span>
                <?php else: ?>
                  (0)
                <?php endif; ?>
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

            <!-- Pane In Review (Menunggu Audit) -->
            <div class="tab-pane fade <?= $activeTab === 'inreview' ? 'show active' : '' ?>" id="pane-inreview" role="tabpanel">
              <?php if (empty($inReviewTasks)): ?>
                <div class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted">
                  <i class="fa-solid fa-clipboard-check fs-1 mb-3 text-secondary" style="opacity: 0.4;"></i>
                  <p class="mb-0 small">Tidak ada tugas yang sedang menunggu audit saat ini.</p>
                </div>
              <?php else: ?>
                <div class="d-flex flex-column gap-2">
                  <?php foreach ($inReviewTasks as $t) { renderTaskCard($t, $selectedTaskId); } ?>
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
              <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom flex-wrap gap-2">
                <h5 class="mb-0 font-weight-bold text-dark"><i class="fa-solid fa-clipboard-list text-primary me-2"></i> Detail Tugas</h5>
                <div class="d-flex align-items-center gap-2">
                  <button type="button" class="btn btn-xs btn-primary rounded-pill px-3 py-1 font-weight-bold shadow-sm d-flex align-items-center gap-1.5" onclick="TaskChatEngine.openChat(<?= (int)$selectedTask['id'] ?>, <?= htmlspecialchars(json_encode($selectedTask['title']), ENT_QUOTES) ?>);">
                    <i class="fa-solid fa-comments text-warning"></i> Chat Diskusi
                    <span class="badge bg-danger rounded-pill d-none" id="taskDetailChatBadge">0</span>
                  </button>
                  <a href="<?= BASE_URL ?>/tasks.php" class="btn btn-sm btn-light rounded-circle"><i class="fa-solid fa-xmark"></i></a>
                </div>
              </div>
              <div class="card-body p-4">
                
                <h4 class="font-weight-bold text-dark mb-2"><?= e($selectedTask['title']) ?></h4>
                <div class="d-flex gap-2 mb-3 flex-wrap">
                  <?php if ($selectedTask['status'] === 'completed'): ?>
                    <span class="badge bg-success text-white font-weight-bold px-2.5 py-1.5 rounded-3">
                      <i class="fa-solid fa-circle-check me-1"></i> SELESAI (LOLOS AUDIT)
                    </span>
                  <?php elseif ($selectedTask['status'] === 'in_review'): ?>
                    <span class="badge bg-warning text-dark font-weight-bold px-2.5 py-1.5 rounded-3">
                      <i class="fa-solid fa-hourglass-half me-1"></i> MENUNGGU AUDIT ADMIN
                    </span>
                  <?php elseif ($selectedTask['status'] === 'in_progress'): ?>
                    <span class="badge bg-primary text-white font-weight-bold px-2.5 py-1.5 rounded-3">
                      <i class="fa-solid fa-spinner fa-spin me-1"></i> SEDANG DIKERJAKAN
                    </span>
                  <?php else: ?>
                    <span class="badge bg-secondary text-white font-weight-bold px-2.5 py-1.5 rounded-3">
                      <i class="fa-regular fa-clock me-1"></i> BELUM DIKERJAKAN
                    </span>
                  <?php endif; ?>

                  <span class="badge bg-light text-dark border font-weight-bold px-2.5 py-1.5 rounded-3">
                    Assignee: <?= e($selectedTask['assign_type'] === 'employee' ? 'Perorangan (' . ($selectedTask['pic_name'] ?? 'Karyawan') . ')' : ($selectedTask['assign_type'] === 'team' ? 'Tim (' . ($selectedTask['team_name'] ?? '-') . ')' : 'Divisi (' . ($selectedTask['division_name'] ?? '-') . ')')) ?>
                  </span>
                </div>

                <!-- Deskripsi Tugas -->
                <div class="mb-4">
                  <h6 class="text-muted small font-weight-bold mb-2">DESKRIPSI TUGAS</h6>
                  <div class="bg-light p-3 rounded-3 text-dark small" style="white-space: pre-wrap; line-height: 1.6; border: 1px solid #e2e8f0;"><?= e($selectedTask['description'] ?: 'Tidak ada rincian deskripsi.') ?></div>
                </div>

                <!-- 1. KOTAK INSTRUKSI, CATATAN & CONTOH DARI SUPER ADMIN (DESAIN ULTRA-PREMIUM) -->
                <div class="guidance-container">
                  <div class="guidance-banner-header">
                    <div class="d-flex align-items-center gap-2.5">
                      <div class="guidance-icon-circle">
                        <i class="fa-solid fa-wand-magic-sparkles text-warning fs-6"></i>
                      </div>
                      <div>
                        <h6 class="font-weight-bold text-white mb-0" style="font-size: 0.95rem; letter-spacing: 0.3px;">
                          INSTRUKSI &amp; CONTOH DARI ADMIN
                        </h6>
                        <small class="text-white-50 d-block" style="font-size: 0.72rem;">Standar kualitas &amp; panduan pengerjaan</small>
                      </div>
                    </div>

                    <?php if (hasPermission('manage_tasks')): ?>
                      <button type="button" class="guidance-edit-btn" data-bs-toggle="modal" data-bs-target="#editGuidelinesModal">
                        <i class="fa-solid fa-pen-to-square me-1"></i> Edit Instruksi
                      </button>
                    <?php endif; ?>
                  </div>

                  <div class="guidance-body">
                    <?php if (!empty($selectedTask['admin_notes'])): ?>
                      <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                          <div class="d-flex align-items-center gap-1.5">
                            <i class="fa-solid fa-clipboard-check text-warning small"></i>
                            <span class="text-dark font-weight-bold small">Poin-Poin Instruksi Wajib:</span>
                          </div>
                          <span class="badge bg-warning text-dark font-weight-bold" style="font-size: 0.65rem;">Perlu Diperhatikan</span>
                        </div>
                        <?= renderFormattedNotes($selectedTask['admin_notes'], 'primary') ?>
                      </div>
                    <?php endif; ?>

                    <!-- Link Referensi & Lampiran File Contoh -->
                    <?php if (!empty($selectedTask['example_url']) || !empty($selectedTask['example_file'])): ?>
                      <div class="d-flex flex-column gap-2.5 <?= !empty($selectedTask['admin_notes']) ? 'mt-3 pt-3 border-top' : '' ?>">
                        
                        <?php if (!empty($selectedTask['example_url'])): ?>
                          <a href="<?= e($selectedTask['example_url']) ?>" target="_blank" rel="noopener noreferrer" class="ref-interactive-link shadow-sm">
                            <div class="rounded-3 bg-primary text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px; flex-shrink: 0;">
                              <i class="fa-solid fa-arrow-up-right-from-square fs-6"></i>
                            </div>
                            <div class="text-truncate flex-grow-1">
                              <span class="d-block text-dark font-weight-bold small mb-0.5">Tautan Contoh / Referensi Pengerjaan</span>
                              <small class="text-primary text-truncate d-block" style="font-size: 0.75rem;"><?= e($selectedTask['example_url']) ?></small>
                            </div>
                            <span class="btn btn-xs btn-primary rounded-pill px-3 py-1 font-weight-bold shadow-xs">
                              Buka Link &rarr;
                            </span>
                          </a>
                        <?php endif; ?>

                        <?php if (!empty($selectedTask['example_file'])): ?>
                          <a href="<?= BASE_URL . e($selectedTask['example_file']) ?>" target="_blank" download class="ref-interactive-link shadow-sm" style="border-color: #c7d2fe; background: #fafbff;">
                            <div class="rounded-3 bg-success text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px; flex-shrink: 0;">
                              <i class="fa-solid fa-file-arrow-down fs-6"></i>
                            </div>
                            <div class="text-truncate flex-grow-1">
                              <span class="d-block text-dark font-weight-bold small mb-0.5">File Dokumen / Panduan Contoh</span>
                              <small class="text-muted d-block" style="font-size: 0.75rem;"><?= e(basename($selectedTask['example_file'])) ?></small>
                            </div>
                            <span class="btn btn-xs btn-success text-white rounded-pill px-3 py-1 font-weight-bold shadow-xs">
                              <i class="fa-solid fa-download me-1"></i> Unduh File
                            </span>
                          </a>
                        <?php endif; ?>

                      </div>
                    <?php endif; ?>

                    <!-- Empty state if no guidance attached -->
                    <?php if (empty($selectedTask['admin_notes']) && empty($selectedTask['example_url']) && empty($selectedTask['example_file'])): ?>
                      <div class="empty-guidance-card">
                        <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width: 44px; height: 44px; border: 1px solid #e0e7ff;">
                          <i class="fa-solid fa-lightbulb fs-5 text-warning"></i>
                        </div>
                        <h6 class="font-weight-bold text-dark mb-1 small">Belum Ada Instruksi Khusus</h6>
                        <p class="text-muted small mb-2.5" style="font-size: 0.78rem;">Admin belum menambahkan catatan petunjuk atau link/file contoh pada tugas ini.</p>
                        <?php if (hasPermission('manage_tasks')): ?>
                          <button type="button" class="btn btn-xs btn-primary rounded-pill px-3 py-1.5 font-weight-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#editGuidelinesModal">
                            <i class="fa-solid fa-plus me-1"></i> Tambah Catatan &amp; Contoh Sekarang
                          </button>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Info Due Date & Bonus -->
                <div class="row mb-4 g-2">
                  <div class="col-6">
                    <div class="p-3 bg-light rounded-3 border text-center">
                      <small class="text-muted d-block mb-1">Deadline Selesai</small>
                      <strong class="text-dark font-weight-bold fs-7"><?= formatTanggalIndo($selectedTask['due_date']) ?></strong>
                    </div>
                  </div>
                  <div class="col-6">
                    <div class="p-3 rounded-3 border text-center" style="background-color: #fcfdf8; border-color: #fef08a !important;">
                      <small class="text-muted d-block mb-1">Reward Bonus</small>
                      <strong class="text-success font-weight-bold fs-7">
                        <?php if ($selectedTask['bonus_type_name']): ?>
                          <i class="fa-solid fa-coins text-warning me-1"></i> +<?= formatRupiah($selectedTask['bonus_rate'] * $selectedTask['bonus_quantity']) ?>
                        <?php else: ?>
                          Tanpa Bonus
                        <?php endif; ?>
                      </strong>
                    </div>
                  </div>
                </div>

                <!-- 2. HASIL PENGERJAAN KARYAWAN (SUBMISSION) JIKA ADA -->
                <?php if (!empty($selectedTask['submission_notes']) || !empty($selectedTask['submission_url']) || !empty($selectedTask['submission_file'])): ?>
                  <div class="submission-container">
                    <div class="submission-banner-header">
                      <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-white text-success d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                          <i class="fa-solid fa-paper-plane small"></i>
                        </div>
                        <div>
                          <h6 class="font-weight-bold text-white mb-0" style="font-size: 0.92rem;">HASIL PEKERJAAN KARYAWAN</h6>
                          <small class="text-white-50" style="font-size: 0.72rem;">Diajukan oleh <?= e($selectedTask['pic_name'] ?? 'Karyawan') ?></small>
                        </div>
                      </div>
                      <span class="badge bg-white text-success rounded-pill font-weight-bold px-2.5 py-1" style="font-size: 0.7rem;">
                        <i class="fa-solid fa-circle-check me-1"></i> Diajukan
                      </span>
                    </div>

                    <div class="p-3 bg-light">
                      <?php if (!empty($selectedTask['submission_notes'])): ?>
                        <div class="mb-2.5">
                          <small class="text-muted font-weight-bold d-block mb-1.5">Catatan Hasil Pengerjaan:</small>
                          <?= renderFormattedNotes($selectedTask['submission_notes'], 'success') ?>
                        </div>
                      <?php endif; ?>

                      <div class="d-flex flex-column gap-2 mt-2">
                        <?php if (!empty($selectedTask['submission_url'])): ?>
                          <a href="<?= e($selectedTask['submission_url']) ?>" target="_blank" rel="noopener noreferrer" class="ref-interactive-link shadow-sm border-info-subtle">
                            <div class="rounded-3 bg-info text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; flex-shrink: 0;">
                              <i class="fa-solid fa-link small"></i>
                            </div>
                            <div class="text-truncate flex-grow-1">
                              <span class="d-block text-dark font-weight-bold small">Link Hasil Project / Live Demo</span>
                              <small class="text-info text-truncate d-block" style="font-size: 0.73rem;"><?= e($selectedTask['submission_url']) ?></small>
                            </div>
                            <span class="btn btn-xs btn-outline-info rounded-pill px-2.5 py-1 font-weight-bold">Buka Project &rarr;</span>
                          </a>
                        <?php endif; ?>

                        <?php if (!empty($selectedTask['submission_file'])): ?>
                          <a href="<?= BASE_URL . e($selectedTask['submission_file']) ?>" target="_blank" download class="ref-interactive-link shadow-sm border-success-subtle" style="background: #f0fdf4;">
                            <div class="rounded-3 bg-success text-white d-flex align-items-center justify-content-center" style="width: 34px; height: 34px; flex-shrink: 0;">
                              <i class="fa-solid fa-download small"></i>
                            </div>
                            <div class="text-truncate flex-grow-1">
                              <span class="d-block text-dark font-weight-bold small">File Bukti Hasil Pekerjaan</span>
                              <small class="text-muted d-block" style="font-size: 0.73rem;"><?= e(basename($selectedTask['submission_file'])) ?></small>
                            </div>
                            <span class="btn btn-xs btn-success text-white rounded-pill px-2.5 py-1 font-weight-bold">Unduh Bukti</span>
                          </a>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- 3. HASIL AUDIT DARI ADMIN (JIKA PERNAH DIAUDIT) -->
                <?php if (!empty($selectedTask['audited_at']) || !empty($selectedTask['audit_notes'])): ?>
                  <div class="audit-container">
                    <div class="audit-banner-header">
                      <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-white text-warning d-flex align-items-center justify-content-center" style="width: 30px; height: 30px;">
                          <i class="fa-solid fa-stamp small text-dark"></i>
                        </div>
                        <div>
                          <h6 class="font-weight-bold text-white mb-0" style="font-size: 0.92rem;">CATATAN AUDIT ADMIN</h6>
                          <small class="text-white-50" style="font-size: 0.72rem;">Diaudit oleh <?= e($selectedTask['auditor_fullname'] ?: $selectedTask['auditor_name']) ?></small>
                        </div>
                      </div>
                      <span class="badge bg-white text-dark rounded-pill font-weight-bold px-2.5 py-1" style="font-size: 0.7rem;">
                        <?= formatDateTimeIndo($selectedTask['audited_at']) ?>
                      </span>
                    </div>

                    <div class="p-3 bg-light">
                      <?php if (!empty($selectedTask['audit_notes'])): ?>
                        <?= renderFormattedNotes($selectedTask['audit_notes'], 'warning') ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- 4. WORKFLOW ACTION PANEL BERDASARKAN STATUS & ROLE -->

                <!-- A. Jika tugas masih PENDING: Karyawan atau Admin bisa Mulai Kerjakan -->
                <?php if ($selectedTask['status'] === 'pending' || $selectedTask['status'] === 'overdue'): ?>
                  <form action="<?= BASE_URL ?>/tasks.php" method="POST" class="p-4 rounded-4 border mb-4 bg-light text-center shadow-sm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="start_task">
                    <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">
                    <h6 class="font-weight-bold text-dark mb-1.5"><i class="fa-solid fa-play text-primary me-1.5"></i> Siap Mengerjakan Tugas Ini?</h6>
                    <p class="text-muted small mb-3">Klik tombol di bawah untuk mengubah status tugas menjadi <strong>Sedang Dikerjakan</strong>.</p>
                    <button type="submit" class="btn btn-primary rounded-pill font-weight-bold px-4 py-2 shadow-sm" style="background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none;">
                      <i class="fa-solid fa-play me-1.5"></i> Mulai Kerjakan Tugas
                    </button>
                  </form>
                <?php endif; ?>

                <!-- B. Jika tugas IN_PROGRESS: Karyawan mengisi form pengajuan Audit -->
                <?php if ($selectedTask['status'] === 'in_progress'): ?>
                  <div class="card border-0 rounded-4 mb-4 shadow-sm" style="background: linear-gradient(135deg, #f8faff 0%, #eef2ff 100%); border: 1px solid #c7d2fe !important;">
                    <div class="card-header bg-white py-2.5 px-3.5 rounded-top-4 border-bottom d-flex align-items-center gap-2">
                      <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 26px; height: 26px;">
                        <i class="fa-solid fa-paper-plane" style="font-size: 0.75rem;"></i>
                      </div>
                      <h6 class="font-weight-bold text-dark mb-0 small">Ajukan Hasil Pekerjaan untuk Diaudit Admin</h6>
                    </div>
                    <div class="card-body p-3.5">
                      <p class="text-muted small mb-3">
                        <i class="fa-solid fa-circle-info text-primary me-1"></i> Karyawan tidak dapat menyelesaikan tugas secara langsung. Silakan sertakan ringkasan hasil, link project, atau lampiran bukti agar Admin dapat mengaudit kualitas pekerjaan Anda.
                      </p>

                      <form action="<?= BASE_URL ?>/tasks.php" method="POST" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="submit_audit">
                        <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">

                        <div class="mb-2.5">
                          <label class="form-label font-weight-bold small mb-1">Catatan Ringkasan Hasil Pengerjaan <span class="text-danger">*</span></label>
                          <textarea name="submission_notes" class="form-control form-control-sm rounded-3 bg-white" rows="3" placeholder="Contoh: Pemasangan ODP di titik RW 04 selesai dan telah diuji redamannya..." required><?= e($selectedTask['submission_notes'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-2.5">
                          <label class="form-label font-weight-bold small mb-1">Link Hasil Project / Live Demo / Git (Opsional)</label>
                          <input type="url" name="submission_url" class="form-control form-control-sm rounded-3 bg-white" placeholder="https://..." value="<?= e($selectedTask['submission_url'] ?? '') ?>">
                        </div>

                        <div class="mb-3">
                          <label class="form-label font-weight-bold small mb-1">Upload File / Foto Bukti Pekerjaan (Opsional)</label>
                          <input type="file" name="submission_file" class="form-control form-control-sm rounded-3 bg-white">
                          <small class="text-muted" style="font-size: 0.7rem;">Mendukung foto, dokumen PDF, spreadsheet, atau zip (Maks 15MB).</small>
                        </div>

                        <button type="submit" class="btn btn-primary w-100 font-weight-bold rounded-pill shadow-sm py-2" style="background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none;">
                          <i class="fa-solid fa-paper-plane me-1.5"></i> Kirim untuk Diaudit Admin
                        </button>
                      </form>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- C. Jika tugas IN_REVIEW: Karyawan melihat notice, Admin melihat Panel Audit -->
                <?php if ($selectedTask['status'] === 'in_review'): ?>
                  <?php if (hasPermission('manage_tasks')): ?>
                    <!-- ADMIN AUDIT PANEL -->
                    <div class="card border-0 rounded-4 mb-4 shadow-sm" style="background: linear-gradient(135deg, #fffdf5 0%, #fefce8 100%); border: 1px solid #fde047 !important;">
                      <div class="card-header bg-white py-2.5 px-3.5 rounded-top-4 border-bottom d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-warning text-dark d-flex align-items-center justify-content-center" style="width: 26px; height: 26px;">
                          <i class="fa-solid fa-stamp" style="font-size: 0.75rem;"></i>
                        </div>
                        <h6 class="font-weight-bold text-dark mb-0 small">Panel Audit Super Admin &amp; HR</h6>
                      </div>
                      <div class="card-body p-3.5">
                        <p class="text-dark small mb-3">
                          Periksa hasil pekerjaan di atas. Berikan feedback catatan audit, lalu tentukan apakah tugas <strong>Lolos (Selesai)</strong> atau <strong>Butuh Revisi</strong>.
                        </p>

                        <form action="<?= BASE_URL ?>/tasks.php" method="POST">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="audit_task">
                          <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">

                          <div class="mb-3">
                            <label class="form-label font-weight-bold small mb-1">Catatan / Feedback Audit Admin</label>
                            <textarea name="audit_notes" class="form-control form-control-sm rounded-3 bg-white" rows="3" placeholder="Tulis masukan, apresiasi, atau poin-poin yang harus direvisi karyawan..."></textarea>
                          </div>

                          <div class="row g-2">
                            <div class="col-6">
                              <button type="submit" name="audit_decision" value="revise" class="btn btn-outline-warning text-dark w-100 font-weight-bold rounded-pill btn-sm bg-white shadow-sm py-2">
                                <i class="fa-solid fa-rotate-left me-1"></i> Minta Revisi
                              </button>
                            </div>
                            <div class="col-6">
                              <button type="submit" name="audit_decision" value="approve" class="btn btn-success text-white w-100 font-weight-bold rounded-pill btn-sm shadow-sm py-2">
                                <i class="fa-solid fa-check-double me-1"></i> Loloskan &amp; Selesai
                              </button>
                            </div>
                          </div>
                        </form>
                      </div>
                    </div>
                  <?php else: ?>
                    <!-- EMPLOYEE NOTICE -->
                    <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-4" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 1px solid #fde68a !important;">
                      <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-hourglass-half fs-4 text-warning"></i>
                        <div>
                          <strong class="d-block text-dark">Sedang Menunggu Audit Admin</strong>
                          <small class="text-muted">Hasil pekerjaan Anda telah dikirim dan sedang dalam proses verifikasi kualitas oleh Admin.</small>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>

                <!-- D. Jika tugas COMPLETED -->
                <?php if ($selectedTask['status'] === 'completed'): ?>
                  <div class="alert alert-success border-0 rounded-4 shadow-sm mb-4" style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #bbf7d0 !important;">
                    <div class="d-flex align-items-center gap-2">
                      <i class="fa-solid fa-circle-check fs-4 text-success"></i>
                      <div>
                        <strong class="d-block text-dark">Tugas Telah Lolos Audit &amp; Selesai!</strong>
                        <small class="text-muted">Pekerjaan telah diverifikasi memenuhi standar kualitas oleh Admin.</small>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- Admin Manual Status Adjuster (Hanya untuk Admin / Super Admin) -->
                <?php if (hasPermission('manage_tasks')): ?>
                  <div class="accordion mb-4" id="adminManualStatusAccordion">
                    <div class="accordion-item border-0 bg-light rounded-4">
                      <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2 px-3 bg-light text-muted small font-weight-bold rounded-4" type="button" data-bs-toggle="collapse" data-bs-target="#adminManualStatusCollapse">
                          <i class="fa-solid fa-sliders me-1.5"></i> Pengaturan Status Manual (Khusus Admin)
                        </button>
                      </h2>
                      <div id="adminManualStatusCollapse" class="accordion-collapse collapse p-3 pt-0">
                        <form action="<?= BASE_URL ?>/tasks.php" method="POST">
                          <?= csrfField() ?>
                          <input type="hidden" name="action" value="update_status">
                          <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">

                          <div class="row g-2">
                            <div class="col-12">
                              <select name="status" class="form-select form-control form-control-sm rounded-3" required>
                                <option value="pending" <?= $selectedTask['status'] === 'pending' ? 'selected' : '' ?>>Belum Dikerjakan (Pending)</option>
                                <option value="in_progress" <?= $selectedTask['status'] === 'in_progress' ? 'selected' : '' ?>>Sedang Dikerjakan (In Progress)</option>
                                <option value="in_review" <?= $selectedTask['status'] === 'in_review' ? 'selected' : '' ?>>Menunggu Audit (In Review)</option>
                                <option value="completed" <?= $selectedTask['status'] === 'completed' ? 'selected' : '' ?>>Selesai (Completed)</option>
                              </select>
                            </div>
                            <div class="col-12">
                              <input type="text" name="notes" class="form-control form-control-sm rounded-3" placeholder="Catatan log perubahan manual...">
                            </div>
                            <div class="col-12">
                              <button type="submit" class="btn btn-secondary btn-sm w-100 font-weight-bold rounded-pill">Simpan Perubahan Manual</button>
                            </div>
                          </div>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

                <!-- History Log -->
                <div>
                  <h6 class="font-weight-bold text-muted mb-3 small"><i class="fa-solid fa-clock-rotate-left me-1"></i> LOG AKTIVITAS &amp; PROGRES AUDIT</h6>
                  <?php if (empty($taskHistories)): ?>
                    <p class="text-muted small italic">Belum ada catatan log aktivitas.</p>
                  <?php else: ?>
                    <div class="timeline border-start ps-3 ms-2 position-relative" style="border-color: #ebedf2 !important;">
                      <?php foreach ($taskHistories as $th): ?>
                        <div class="mb-3 position-relative timeline-item">
                          <small class="text-primary font-weight-bold d-block" style="font-size: 0.75rem;"><?= formatDateTimeIndo($th['created_at']) ?></small>
                          <div class="fw-bold text-dark small mb-1"><?= e($th['user_fullname'] ?: $th['username']) ?></div>
                          <div class="small text-muted mb-1">
                            Status: <span class="badge bg-light border text-dark"><?= e($th['old_status'] ?? 'BARU') ?></span> &rarr; <span class="badge bg-primary text-white"><?= e($th['new_status'] ?? '-') ?></span>
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

    <!-- Modal Edit Guidelines (Admin only) -->
    <?php if ($selectedTask && hasPermission('manage_tasks')): ?>
      <div class="modal fade" id="editGuidelinesModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom">
              <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-pen me-2 text-primary"></i> Edit Instruksi &amp; Contoh Admin</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="<?= BASE_URL ?>/tasks.php" method="POST" enctype="multipart/form-data">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="update_guidelines">
              <input type="hidden" name="task_id" value="<?= $selectedTask['id'] ?>">

              <div class="modal-body p-4">
                <div class="mb-3">
                  <label class="form-label font-weight-bold small">Catatan / Petunjuk Khusus Admin</label>
                  <textarea name="admin_notes" class="form-control" rows="4" placeholder="Tulis instruksi khusus yang wajib diperhatikan karyawan (gunakan tanda - untuk poin-poin)..."><?= e($selectedTask['admin_notes'] ?? '') ?></textarea>
                  <small class="text-muted">Gunakan tanda <code>-</code> di awal baris untuk membuat daftar poin/checklist rapi.</small>
                </div>

                <div class="mb-3">
                  <label class="form-label font-weight-bold small">Link Contoh / Referensi Pengerjaan</label>
                  <input type="url" name="example_url" class="form-control" placeholder="https://drive.google.com/... atau https://figma.com/..." value="<?= e($selectedTask['example_url'] ?? '') ?>">
                </div>

                <div class="mb-3">
                  <label class="form-label font-weight-bold small">Ganti File Lampiran Contoh</label>
                  <input type="file" name="example_file" class="form-control">
                  <?php if (!empty($selectedTask['example_file'])): ?>
                    <small class="text-muted d-block mt-1">File saat ini: <a href="<?= BASE_URL . e($selectedTask['example_file']) ?>" target="_blank">Unduh file saat ini</a></small>
                  <?php endif; ?>
                </div>
              </div>

              <div class="modal-footer border-top">
                <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-pill font-weight-bold btn-sm px-4">Simpan Perubahan</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Modal Add Task (Super Admin & HR) -->
    <div class="modal fade" id="addTaskModal" tabindex="-1">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-list-check me-2 text-primary"></i> Buat Tugas Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/tasks.php" method="POST" enctype="multipart/form-data">
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
                    <i class="fa-solid fa-lightbulb me-1.5"></i> 2. Catatan &amp; Contoh Admin
                  </button>
                </li>
                <li class="nav-item" role="presentation">
                  <button class="nav-link font-weight-bold py-2 rounded-3" id="task-step-3-tab" data-bs-toggle="pill" data-bs-target="#task-step-3" type="button" role="tab">
                    <i class="fa-solid fa-gift me-1.5"></i> 3. Tanggal &amp; Bonus
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
                      Lanjut ke Catatan &amp; Contoh <i class="fa-solid fa-arrow-right ms-1"></i>
                    </button>
                  </div>
                </div>

                <!-- STEP 2: Admin Notes & Examples -->
                <div class="tab-pane fade" id="task-step-2" role="tabpanel">
                  <div class="alert alert-info border-0 rounded-3 small mb-3">
                    <i class="fa-solid fa-circle-info me-1"></i> Berikan instruksi tambahan dan contoh/referensi agar karyawan dapat mengerjakan tugas dengan standar yang diharapkan.
                  </div>

                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Catatan Khusus dari Super Admin (Opsional)</label>
                    <textarea name="admin_notes" class="form-control" rows="3" placeholder="Contoh: Pastikan foto sebelum dan sesudah instalasi jelas, gunakan tanda - untuk daftar poin..."></textarea>
                  </div>

                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Link Contoh / Referensi / Figma / Repo (Opsional)</label>
                    <input type="url" name="example_url" class="form-control" placeholder="https://drive.google.com/... atau https://figma.com/...">
                  </div>

                  <div class="mb-3">
                    <label class="form-label font-weight-bold">Upload File Lampiran / Contoh Panduan (Opsional)</label>
                    <input type="file" name="example_file" class="form-control">
                    <small class="text-muted">Mendukung file gambar, PDF, zip, spreadsheet, dll.</small>
                  </div>

                  <div class="d-flex justify-content-between mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4" onclick="var t = new bootstrap.Tab(document.getElementById('task-step-1-tab')); t.show();">
                      <i class="fa-solid fa-arrow-left me-1"></i> Kembali
                    </button>
                    <button type="button" class="btn btn-primary rounded-pill font-weight-bold btn-sm px-4" onclick="var t = new bootstrap.Tab(document.getElementById('task-step-3-tab')); t.show();">
                      Lanjut ke Tanggal &amp; Bonus <i class="fa-solid fa-arrow-right ms-1"></i>
                    </button>
                  </div>
                </div>

                <!-- STEP 3: Dates, Priorities & Bonuses -->
                <div class="tab-pane fade" id="task-step-3" role="tabpanel">
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
                    <button type="button" class="btn btn-secondary rounded-pill btn-sm px-4" onclick="var t = new bootstrap.Tab(document.getElementById('task-step-2-tab')); t.show();">
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

    <!-- Floating Action Buttons Dock (Side-by-Side at Bottom Right) -->
    <div class="floating-actions-dock" id="floatingActionsDock">
      <?php if (hasPermission('manage_tasks')): ?>
        <!-- Tombol Mobile Tambah Tugas (FAB Samping) -->
        <button type="button" class="btn-fab-item btn-fab-add d-md-none" data-bs-toggle="modal" data-bs-target="#addTaskModal" title="Buat Tugas Baru">
          <i class="fa-solid fa-plus fs-5 text-white"></i>
        </button>
      <?php endif; ?>

      <!-- Tombol Chat Diskusi (FAB) -->
      <button type="button" class="btn-fab-item btn-fab-chat" id="taskChatFloatingBtn" onclick="TaskChatEngine.toggleChat();" title="Buka Chat Diskusi Tugas">
        <i class="fa-solid fa-comments fs-5 text-white"></i>
        <span class="chat-unread-badge d-none" id="floatingChatBadge">0</span>
      </button>
    </div>

    <!-- Backdrop Overlay for Mobile Chat -->
    <div class="chat-backdrop" id="taskChatBackdrop" onclick="TaskChatEngine.closeChat();"></div>

    <!-- Real-time Task Chat Popup Window -->
    <div class="task-chat-popup" id="taskChatPopup">
      <!-- Header -->
      <div class="chat-popup-header" onclick="if(document.getElementById('taskChatPopup').classList.contains('minimized')) TaskChatEngine.toggleMinimize();">
        <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 250px;">
          <div class="rounded-circle bg-success" style="width: 9px; height: 9px; box-shadow: 0 0 8px #22c55e; flex-shrink: 0;"></div>
          <div class="text-truncate">
            <h6 class="mb-0 font-weight-bold text-white text-truncate" id="chatPopupTaskTitle" style="font-size: 0.88rem;">Diskusi Tugas</h6>
            <small class="text-white-50 d-block text-truncate" style="font-size: 0.68rem;"><span id="chatOnlineIndicator">Live Online</span> &bull; <span id="chatPartnerName">Admin / Karyawan</span></small>
          </div>
        </div>
        <div class="d-flex align-items-center gap-1.5">
          <button type="button" class="btn btn-xs btn-link text-white text-decoration-none p-1" id="chatSoundToggleBtn" onclick="event.stopPropagation(); TaskChatEngine.toggleSound();" title="Toggle Suara Notifikasi">
            <i class="fa-solid fa-volume-high" id="chatSoundIcon"></i>
          </button>
          <button type="button" class="btn btn-xs btn-link text-white text-decoration-none p-1" id="chatMinimizeBtn" onclick="event.stopPropagation(); TaskChatEngine.toggleMinimize();" title="Minimize / Perbesar Chat">
            <i class="fa-solid fa-minus" id="chatMinimizeIcon"></i>
          </button>
          <button type="button" class="btn btn-xs btn-link text-white text-decoration-none p-1" onclick="event.stopPropagation(); TaskChatEngine.closeChat();" title="Tutup Chat">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>

      <!-- Messages Body -->
      <div class="chat-popup-body" id="taskChatMessagesBody">
        <div class="text-center py-5 text-muted" id="chatLoadingPlaceholder">
          <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
          <p class="small mb-0">Memuat percakapan...</p>
        </div>
      </div>

      <!-- Footer Input Area -->
      <!-- Footer Input Area -->
      <div class="chat-popup-footer">
        <!-- Reply Preview Banner (WhatsApp Style) -->
        <div class="d-none align-items-center justify-content-between chat-action-banner reply-mode" id="chatReplyBanner">
          <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 88%;">
            <div class="text-truncate">
              <small class="quote-sender d-block text-truncate" id="replyTargetSender">Membalas pesan</small>
              <small class="text-muted text-truncate d-block" id="replyTargetText" style="font-size: 0.74rem;"></small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-xs" onclick="TaskChatEngine.cancelReply();" title="Batal Balas"></button>
        </div>

        <!-- Edit Message Banner -->
        <div class="d-none align-items-center justify-content-between chat-action-banner edit-mode" id="chatEditBanner">
          <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 88%;">
            <i class="fa-solid fa-pen text-warning small"></i>
            <div class="text-truncate">
              <small class="font-weight-bold text-dark d-block">Mengedit Pesan</small>
              <small class="text-muted text-truncate d-block" id="editOriginalText" style="font-size: 0.74rem;"></small>
            </div>
          </div>
          <button type="button" class="btn-close btn-close-xs" onclick="TaskChatEngine.cancelEdit();" title="Batal Edit"></button>
        </div>

        <!-- Selected file preview tag (hidden by default) -->
        <div class="d-none align-items-center justify-content-between bg-light p-1.5 px-2.5 rounded-3 mb-2 border" id="chatAttachmentPreview">
          <div class="d-flex align-items-center gap-1.5 text-truncate">
            <i class="fa-solid fa-paperclip text-primary small"></i>
            <small class="text-dark font-weight-bold text-truncate" id="chatAttachmentName" style="font-size: 0.75rem;"></small>
          </div>
          <button type="button" class="btn-close btn-close-xs" style="font-size: 0.65rem;" onclick="TaskChatEngine.clearAttachment();"></button>
        </div>

        <form id="taskChatForm" onsubmit="event.preventDefault(); TaskChatEngine.sendMessage();">
          <input type="hidden" id="chatActiveTaskId" value="">
          <input type="hidden" id="chatReplyToId" value="">
          <input type="hidden" id="chatEditingId" value="">
          <input type="file" id="chatFileInput" class="d-none" onchange="TaskChatEngine.handleFileSelect(this);">

          <!-- Standard text input row -->
          <div class="d-flex align-items-center gap-1.5" id="chatInputStandardRow">
            <button type="button" class="btn btn-light rounded-circle text-muted p-0 d-flex align-items-center justify-content-center shadow-xs" style="width: 34px; height: 34px; flex-shrink: 0;" onclick="document.getElementById('chatFileInput').click();" title="Lampirkan File / Gambar">
              <i class="fa-solid fa-paperclip"></i>
            </button>
            <div class="flex-grow-1 position-relative">
              <input type="text" id="chatMessageInput" class="form-control form-control-sm rounded-pill px-3 py-1.5 bg-light border-0" placeholder="Ketik pesan..." autocomplete="off" style="font-size: 0.84rem;">
            </div>
            <button type="button" class="btn btn-light rounded-circle text-danger p-0 d-flex align-items-center justify-content-center shadow-xs" id="chatMicBtn" style="width: 34px; height: 34px; flex-shrink: 0;" onclick="TaskChatEngine.startRecording();" title="Rekam Voice Note / Suara">
              <i class="fa-solid fa-microphone"></i>
            </button>
            <button type="submit" class="btn btn-primary rounded-circle p-0 d-flex align-items-center justify-content-center shadow-sm" id="chatSendBtn" style="width: 34px; height: 34px; flex-shrink: 0; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none;" title="Kirim Pesan">
              <i class="fa-solid fa-paper-plane small text-white"></i>
            </button>
          </div>

          <!-- Active WhatsApp-style Voice Note Recording Row (Hidden by default) -->
          <div class="d-none align-items-center justify-content-between vn-recording-bar" id="chatRecordingRow">
            <!-- Left: Trash/Delete Button & Live Timer with Glowing Red Mic -->
            <div class="d-flex align-items-center gap-2">
              <button type="button" class="vn-rec-trash-btn" onclick="TaskChatEngine.cancelRecording();" title="Batalkan & Hapus Rekaman">
                <i class="fa-solid fa-trash-can"></i>
              </button>
              <div class="d-flex align-items-center gap-1.5">
                <div class="vn-rec-glowing-mic">
                  <i class="fa-solid fa-microphone"></i>
                </div>
                <span class="vn-rec-timer" id="chatVnTimer">00:00</span>
              </div>
            </div>

            <!-- Center: Animated Soundwave Visualizer Bars -->
            <div class="vn-rec-waves d-none d-sm-flex">
              <span class="w1"></span><span class="w2"></span><span class="w3"></span><span class="w4"></span>
              <span class="w5"></span><span class="w3"></span><span class="w2"></span><span class="w4"></span>
              <span class="w1"></span><span class="w5"></span><span class="w2"></span><span class="w3"></span>
            </div>

            <!-- Right: Green WhatsApp Send Button -->
            <button type="button" class="vn-rec-send-btn" onclick="TaskChatEngine.stopAndSendRecording();" title="Kirim Voice Note">
              <i class="fa-solid fa-paper-plane"></i>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Real-time Task Chat Engine JavaScript -->
    <script>
      const TaskChatEngine = {
          activeTaskId: null,
          activeTaskTitle: '',
          lastMessageId: 0,
          pollTimer: null,
          isPolling: false,
          soundEnabled: true,
          isOpen: false,
          audioCtx: null,

          init() {
              // Check if URL has active task ID or open_chat query param
              const urlParams = new URLSearchParams(window.location.search);
              const paramTaskId = urlParams.get('id');
              const openChatParam = urlParams.get('open_chat');

              <?php if ($selectedTask): ?>
                this.activeTaskId = <?= (int)$selectedTask['id'] ?>;
                this.activeTaskTitle = <?= json_encode($selectedTask['title']) ?>;
              <?php endif; ?>

              if (openChatParam === '1' && paramTaskId) {
                  const defaultTitle = <?= $selectedTask ? json_encode($selectedTask['title']) : "'Tugas #' + paramTaskId" ?>;
                  this.openChat(parseInt(paramTaskId), defaultTitle);
              }

              // Initial unread count poll
              this.checkUnreadCounts();
              setInterval(() => {
                  if (!this.isOpen) {
                      this.checkUnreadCounts();
                  }
              }, 6000);
          },

          playNotificationDing() {
              if (!this.soundEnabled) return;
              try {
                  if (!this.audioCtx) {
                      this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                  }
                  if (this.audioCtx.state === 'suspended') {
                      this.audioCtx.resume();
                  }
                  const now = this.audioCtx.currentTime;
                  const osc = this.audioCtx.createOscillator();
                  const gain = this.audioCtx.createGain();
                  
                  osc.type = 'sine';
                  osc.frequency.setValueAtTime(587.33, now); // D5
                  osc.frequency.exponentialRampToValueAtTime(880.00, now + 0.12); // A5
                  
                  gain.gain.setValueAtTime(0.25, now);
                  gain.gain.exponentialRampToValueAtTime(0.01, now + 0.35);
                  
                  osc.connect(gain);
                  gain.connect(this.audioCtx.destination);
                  
                  osc.start(now);
                  osc.stop(now + 0.35);
              } catch (e) {
                  // Audio synthesis fallback
              }
          },

          toggleSound() {
              this.soundEnabled = !this.soundEnabled;
              const icon = document.getElementById('chatSoundIcon');
              if (this.soundEnabled) {
                  icon.className = 'fa-solid fa-volume-high';
                  this.playNotificationDing();
              } else {
                  icon.className = 'fa-solid fa-volume-xmark';
              }
          },

          toggleChat() {
              if (this.isOpen) {
                  this.closeChat();
              } else {
                  <?php if ($selectedTask): ?>
                    this.openChat(<?= (int)$selectedTask['id'] ?>, <?= json_encode($selectedTask['title']) ?>);
                  <?php else: ?>
                    <?php if (!empty($tasks)): ?>
                      this.openChat(<?= (int)$tasks[0]['id'] ?>, <?= json_encode($tasks[0]['title']) ?>);
                    <?php else: ?>
                      alert('Pilih tugas terlebih dahulu untuk memulai diskusi chat.');
                    <?php endif; ?>
                  <?php endif; ?>
              }
          },

          toggleMinimize() {
              const popup = document.getElementById('taskChatPopup');
              const icon = document.getElementById('chatMinimizeIcon');
              const backdrop = document.getElementById('taskChatBackdrop');
              if (!popup) return;

              const isMin = popup.classList.toggle('minimized');
              if (icon) {
                  icon.className = isMin ? 'fa-solid fa-chevron-up' : 'fa-solid fa-minus';
              }
              if (backdrop) {
                  if (isMin) {
                      backdrop.classList.remove('active');
                  } else {
                      backdrop.classList.add('active');
                  }
              }
          },

          // VOICE NOTE MEDIA RECORDER METHODS
          mediaRecorder: null,
          audioChunks: [],
          recordingTimer: null,
          recordingSeconds: 0,
          mediaStream: null,

          startRecording() {
              if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                  alert('Browser Anda belum mendukung fitur perekaman audio.');
                  return;
              }

              navigator.mediaDevices.getUserMedia({ audio: true })
                  .then(stream => {
                      this.mediaStream = stream;
                      this.audioChunks = [];

                      let options = { mimeType: 'audio/webm' };
                      if (!MediaRecorder.isTypeSupported('audio/webm')) {
                          if (MediaRecorder.isTypeSupported('audio/mp4')) options = { mimeType: 'audio/mp4' };
                          else if (MediaRecorder.isTypeSupported('audio/ogg')) options = { mimeType: 'audio/ogg' };
                          else options = {};
                      }

                      try {
                          this.mediaRecorder = new MediaRecorder(stream, options);
                      } catch (e) {
                          this.mediaRecorder = new MediaRecorder(stream);
                      }

                      this.mediaRecorder.ondataavailable = (e) => {
                          if (e.data && e.data.size > 0) {
                              this.audioChunks.push(e.data);
                          }
                      };

                      this.mediaRecorder.start(100);

                      // Switch UI to recording bar
                      const stdRow = document.getElementById('chatInputStandardRow');
                      const recRow = document.getElementById('chatRecordingRow');
                      if (stdRow) {
                          stdRow.classList.remove('d-flex');
                          stdRow.classList.add('d-none');
                      }
                      if (recRow) {
                          recRow.classList.remove('d-none');
                          recRow.classList.add('d-flex');
                      }

                      // Start Timer
                      this.recordingSeconds = 0;
                      const timerEl = document.getElementById('chatVnTimer');
                      if (timerEl) timerEl.textContent = '00:00';

                      if (this.recordingTimer) clearInterval(this.recordingTimer);
                      this.recordingTimer = setInterval(() => {
                          this.recordingSeconds++;
                          const mins = String(Math.floor(this.recordingSeconds / 60)).padStart(2, '0');
                          const secs = String(this.recordingSeconds % 60).padStart(2, '0');
                          if (timerEl) timerEl.textContent = `${mins}:${secs}`;
                      }, 1000);
                  })
                  .catch(err => {
                      console.error('Microphone error:', err);
                      alert('Tidak dapat mengakses mikrofon. Pastikan Anda telah mengizinkan akses mic di browser.');
                  });
          },

          cancelRecording() {
              if (this.recordingTimer) {
                  clearInterval(this.recordingTimer);
                  this.recordingTimer = null;
              }
              if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                  this.mediaRecorder.stop();
              }
              if (this.mediaStream) {
                  this.mediaStream.getTracks().forEach(track => track.stop());
                  this.mediaStream = null;
              }
              this.audioChunks = [];
              this.resetRecordingUI();
          },

          stopAndSendRecording() {
              if (!this.mediaRecorder || this.mediaRecorder.state === 'inactive') return;

              if (this.recordingTimer) {
                  clearInterval(this.recordingTimer);
                  this.recordingTimer = null;
              }

              this.mediaRecorder.onstop = () => {
                  if (this.mediaStream) {
                      this.mediaStream.getTracks().forEach(track => track.stop());
                      this.mediaStream = null;
                  }

                  const mimeType = this.mediaRecorder.mimeType || 'audio/webm';
                  const ext = mimeType.includes('mp4') ? 'm4a' : (mimeType.includes('ogg') ? 'ogg' : 'webm');
                  const audioBlob = new Blob(this.audioChunks, { type: mimeType });
                  const audioFile = new File([audioBlob], `voicenote_${Date.now()}.${ext}`, { type: mimeType });

                  this.resetRecordingUI();
                  this.sendMessage(audioFile, '🎙️ Voice Note');
              };

              this.mediaRecorder.stop();
          },

          resetRecordingUI() {
              const stdRow = document.getElementById('chatInputStandardRow');
              const recRow = document.getElementById('chatRecordingRow');
              if (recRow) {
                  recRow.classList.remove('d-flex');
                  recRow.classList.add('d-none');
              }
              if (stdRow) {
                  stdRow.classList.remove('d-none');
                  stdRow.classList.add('d-flex');
              }
          },

          openChat(taskId, taskTitle) {
              if (!taskId) return;
              this.activeTaskId = taskId;
              this.activeTaskTitle = taskTitle || 'Diskusi Tugas #' + taskId;
              this.isOpen = true;
              this.lastMessageId = 0;

              const popup = document.getElementById('taskChatPopup');
              const titleEl = document.getElementById('chatPopupTaskTitle');
              const activeTaskIdInput = document.getElementById('chatActiveTaskId');
              const body = document.getElementById('taskChatMessagesBody');
              const icon = document.getElementById('chatMinimizeIcon');

              const backdrop = document.getElementById('taskChatBackdrop');

              if (popup) {
                  popup.classList.remove('minimized');
                  popup.classList.add('active');
                  document.body.classList.add('chat-active');
              }
              if (icon) icon.className = 'fa-solid fa-minus';
              if (backdrop) backdrop.classList.add('active');
              if (titleEl) titleEl.textContent = this.activeTaskTitle;
              if (activeTaskIdInput) activeTaskIdInput.value = taskId;
              
              if (body) {
                  body.innerHTML = `
                    <div class="text-center py-5 text-muted" id="chatLoadingPlaceholder">
                      <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                      <p class="small mb-0">Memuat percakapan...</p>
                    </div>
                  `;
              }

              // Load history
              this.fetchMessages(false);

              // Start Polling Interval every 2.5s
              if (this.pollTimer) clearInterval(this.pollTimer);
              this.pollTimer = setInterval(() => {
                  this.fetchMessages(true);
              }, 2500);

              // Focus input
              setTimeout(() => {
                  const input = document.getElementById('chatMessageInput');
                  if (input) input.focus();
              }, 300);
          },

          closeChat() {
              this.isOpen = false;
              const popup = document.getElementById('taskChatPopup');
              const backdrop = document.getElementById('taskChatBackdrop');
              if (popup) {
                  popup.classList.remove('active');
                  popup.classList.remove('minimized');
              }
              if (backdrop) backdrop.classList.remove('active');
              document.body.classList.remove('chat-active');
              if (this.pollTimer) {
                  clearInterval(this.pollTimer);
                  this.pollTimer = null;
              }
              this.cancelRecording();
              this.checkUnreadCounts();
          },

          fetchMessages(isPolling = false) {
              if (!this.activeTaskId || this.isPolling) return;
              this.isPolling = true;

              const url = `<?= BASE_URL ?>/api/task_chat.php?action=get_messages&task_id=${this.activeTaskId}&after_id=${this.lastMessageId}`;

              fetch(url)
                  .then(res => res.json())
                  .then(res => {
                      this.isPolling = false;
                      if (res.success && Array.isArray(res.data)) {
                          const body = document.getElementById('taskChatMessagesBody');
                          const loading = document.getElementById('chatLoadingPlaceholder');

                          if (!isPolling && loading) {
                              body.innerHTML = '';
                              if (res.data.length === 0) {
                                  body.innerHTML = `
                                      <div class="text-center py-5 text-muted">
                                          <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-2" style="width: 48px; height: 48px; border: 1px solid #e2e8f0;">
                                              <i class="fa-regular fa-comments fs-4 text-primary opacity-60"></i>
                                          </div>
                                          <h6 class="font-weight-bold text-dark mb-1 small">Belum Ada Pesan Diskusi</h6>
                                          <p class="small text-muted mb-0">Mulai kirim instruksi, pertanyaan, atau klarifikasi tugas di sini.</p>
                                      </div>
                                  `;
                              }
                          }

                          if (res.data.length > 0) {
                              let hasIncoming = false;
                              res.data.forEach(msg => {
                                  if (msg.id > this.lastMessageId) {
                                      this.lastMessageId = msg.id;
                                  }
                                  this.appendMessageElement(msg);
                                  if (!msg.is_self) {
                                      hasIncoming = true;
                                  }
                              });

                              if (isPolling && hasIncoming) {
                                  this.playNotificationDing();
                              }

                              this.scrollToBottom();
                          }
                      }
                  })
                  .catch(err => {
                      this.isPolling = false;
                      console.error('Chat fetch error:', err);
                  });
          },

          isAdmin: <?= in_array($currentUser['role_id'] ?? 3, [1, 2]) ? 'true' : 'false' ?>,
          replyingTo: null,
          editingMessage: null,

          startReply(id, sender, text) {
              this.cancelEdit();
              this.replyingTo = { id, sender, text };
              
              const banner = document.getElementById('chatReplyBanner');
              const senderEl = document.getElementById('replyTargetSender');
              const textEl = document.getElementById('replyTargetText');
              const replyInput = document.getElementById('chatReplyToId');

              if (senderEl) senderEl.textContent = 'Membalas ' + sender;
              if (textEl) textEl.textContent = text || 'Lampiran';
              if (replyInput) replyInput.value = id;
              if (banner) {
                  banner.classList.remove('d-none');
                  banner.classList.add('d-flex');
              }

              const msgInput = document.getElementById('chatMessageInput');
              if (msgInput) msgInput.focus();
          },

          cancelReply() {
              this.replyingTo = null;
              const banner = document.getElementById('chatReplyBanner');
              const replyInput = document.getElementById('chatReplyToId');
              if (banner) {
                  banner.classList.remove('d-flex');
                  banner.classList.add('d-none');
              }
              if (replyInput) replyInput.value = '';
          },

          startEdit(id, text) {
              this.cancelReply();
              this.editingMessage = { id, originalText: text };

              const banner = document.getElementById('chatEditBanner');
              const textEl = document.getElementById('editOriginalText');
              const editInput = document.getElementById('chatEditingId');
              const msgInput = document.getElementById('chatMessageInput');

              if (textEl) textEl.textContent = text;
              if (editInput) editInput.value = id;
              if (banner) {
                  banner.classList.remove('d-none');
                  banner.classList.add('d-flex');
              }

              if (msgInput) {
                  msgInput.value = text;
                  msgInput.focus();
                  msgInput.select();
              }
          },

          cancelEdit() {
              this.editingMessage = null;
              const banner = document.getElementById('chatEditBanner');
              const editInput = document.getElementById('chatEditingId');
              const msgInput = document.getElementById('chatMessageInput');
              if (banner) {
                  banner.classList.remove('d-flex');
                  banner.classList.add('d-none');
              }
              if (editInput) editInput.value = '';
              if (msgInput) msgInput.value = '';
          },

          deleteMessage(id) {
              if (!confirm('Hapus pesan ini untuk semua orang di obrolan?')) return;

              const formData = new FormData();
              formData.append('action', 'delete_message');
              formData.append('chat_id', id);

              fetch('<?= BASE_URL ?>/api/task_chat.php', {
                  method: 'POST',
                  body: formData
              })
              .then(res => res.json())
              .then(res => {
                  if (res.success) {
                      const bubbleEl = document.querySelector(`#chat-msg-${id} .chat-bubble`);
                      const actionsEl = document.querySelector(`#chat-msg-${id} .chat-bubble-actions`);
                      if (bubbleEl) {
                          bubbleEl.innerHTML = '<span class="chat-deleted-text"><i class="fa-solid fa-ban me-1 text-danger"></i> Pesan ini telah dihapus.</span>';
                      }
                      if (actionsEl) actionsEl.remove();
                  } else {
                      alert(res.message || 'Gagal menghapus pesan');
                  }
              })
              .catch(err => {
                  console.error('Delete error:', err);
                  alert('Terjadi kesalahan saat menghapus pesan.');
              });
          },

          scrollToMessage(targetId) {
              const targetEl = document.getElementById(`chat-msg-${targetId}`);
              if (targetEl) {
                  targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                  targetEl.classList.add('chat-msg-highlight');
                  setTimeout(() => targetEl.classList.remove('chat-msg-highlight'), 1800);
              } else {
                  alert('Pesan asli tidak ditemukan di riwayat percakapan saat ini.');
              }
          },

          appendMessageElement(msg) {
              const body = document.getElementById('taskChatMessagesBody');
              if (!body) return;

              // Check if empty placeholder exists and remove it
              const emptyState = body.querySelector('.text-muted.py-5');
              if (emptyState) {
                  body.innerHTML = '';
              }

              // Check if message ID already rendered -> update if edited/deleted
              const existingMsg = document.getElementById(`chat-msg-${msg.id}`);
              if (existingMsg) {
                  if (msg.is_deleted) {
                      const bubble = existingMsg.querySelector('.chat-bubble');
                      if (bubble) bubble.innerHTML = '<span class="chat-deleted-text"><i class="fa-solid fa-ban me-1 text-danger"></i> Pesan ini telah dihapus.</span>';
                      const actions = existingMsg.querySelector('.chat-bubble-actions');
                      if (actions) actions.remove();
                  } else if (msg.is_edited) {
                      const textEl = existingMsg.querySelector('.chat-bubble-text');
                      if (textEl && msg.message) {
                          textEl.innerHTML = escapeHtml(msg.message).replace(/\n/g, '<br>');
                      }
                      const meta = existingMsg.querySelector('.chat-meta');
                      if (meta && !meta.querySelector('.chat-edited-tag')) {
                          meta.insertAdjacentHTML('beforeend', '<span class="chat-edited-tag">diedit</span>');
                      }
                  }
                  return;
              }

              const isSelf = msg.is_self;
              const initials = (msg.sender_name || 'U').substring(0, 2).toUpperCase();
              
              let avatarHtml = `<div class="chat-avatar">${initials}</div>`;
              if (msg.user_photo) {
                  avatarHtml = `<div class="chat-avatar"><img src="<?= BASE_URL ?>${msg.user_photo}" alt="${msg.sender_name}"></div>`;
              }

              let attachmentHtml = '';
              let showTextMessage = true;
              let isAudio = false;

              if (msg.is_deleted) {
                  showTextMessage = false;
                  attachmentHtml = '<span class="chat-deleted-text"><i class="fa-solid fa-ban me-1 text-danger"></i> Pesan ini telah dihapus.</span>';
              } else if (msg.attachment) {
                  isAudio = msg.attachment.match(/\.(webm|ogg|mp3|wav|m4a|aac|opus)$/i) || msg.attachment.includes('/vn_');
                  const isImg = msg.attachment.match(/\.(jpeg|jpg|png|gif|webp)$/i);
                  if (isAudio) {
                      showTextMessage = false;
                      attachmentHtml = `
                          <div class="whatsapp-vn-bubble" id="vn-player-${msg.id}">
                              <audio id="vn-audio-${msg.id}" src="<?= BASE_URL ?>${msg.attachment}" preload="metadata" onloadedmetadata="TaskChatEngine.onVnLoaded(${msg.id})" ontimeupdate="TaskChatEngine.updateVnProgress(${msg.id})" onended="TaskChatEngine.onVnEnded(${msg.id})"></audio>
                              
                              <div class="d-flex align-items-center gap-2">
                                  <button type="button" class="vn-play-btn" id="vn-btn-${msg.id}" onclick="TaskChatEngine.togglePlayVn(${msg.id})" title="Putar Pesan Suara">
                                      <i class="fa-solid fa-play" id="vn-icon-${msg.id}"></i>
                                  </button>

                                  <div class="vn-track-container" onclick="TaskChatEngine.seekVn(event, ${msg.id})">
                                      <div class="vn-waveform" id="vn-wave-${msg.id}">
                                          <span style="height: 6px;"></span><span style="height: 12px;"></span><span style="height: 18px;"></span>
                                          <span style="height: 10px;"></span><span style="height: 16px;"></span><span style="height: 22px;"></span>
                                          <span style="height: 14px;"></span><span style="height: 8px;"></span><span style="height: 19px;"></span>
                                          <span style="height: 24px;"></span><span style="height: 15px;"></span><span style="height: 11px;"></span>
                                          <span style="height: 17px;"></span><span style="height: 23px;"></span><span style="height: 13px;"></span>
                                          <span style="height: 9px;"></span><span style="height: 16px;"></span><span style="height: 21px;"></span>
                                          <span style="height: 14px;"></span><span style="height: 7px;"></span>
                                      </div>
                                  </div>

                                  <div class="vn-mic-badge ${isSelf ? 'self' : 'partner'}">
                                      <i class="fa-solid fa-microphone"></i>
                                  </div>
                              </div>

                              <div class="d-flex justify-content-between align-items-center mt-1 px-1">
                                  <span class="vn-timer-text" id="vn-time-${msg.id}">0:00</span>
                                  <button type="button" class="vn-speed-btn" id="vn-speed-${msg.id}" onclick="TaskChatEngine.changeVnSpeed(${msg.id})" title="Kecepatan Putar">1x</button>
                              </div>
                          </div>
                      `;
                  } else if (isImg) {
                      attachmentHtml = `
                          <a href="<?= BASE_URL ?>${msg.attachment}" target="_blank" class="d-block">
                              <img src="<?= BASE_URL ?>${msg.attachment}" class="chat-attachment-img" alt="Lampiran">
                          </a>
                      `;
                  } else {
                      const fileName = msg.attachment.split('/').pop();
                      attachmentHtml = `
                          <a href="<?= BASE_URL ?>${msg.attachment}" target="_blank" download class="chat-attachment-card shadow-xs">
                              <i class="fa-solid fa-file-arrow-down text-primary fs-6"></i>
                              <span class="text-truncate" style="max-width: 170px;">${fileName}</span>
                          </a>
                      `;
                  }
              }

              // Reply Quote Card if replied to another message
              let quoteHtml = '';
              if (msg.reply_to && !msg.is_deleted) {
                  const replyText = msg.reply_to.message || (msg.reply_to.attachment ? '📎 Lampiran File' : '');
                  quoteHtml = `
                      <div class="chat-quote-box" onclick="TaskChatEngine.scrollToMessage(${msg.reply_to.id})" title="Menuju ke pesan asli">
                          <div class="quote-sender">${escapeHtml(msg.reply_to.sender_name)}</div>
                          <div class="quote-text text-truncate">${escapeHtml(replyText)}</div>
                      </div>
                  `;
              }

              // Bubble Action buttons (Reply, Edit, Delete)
              let actionsHtml = '';
              if (!msg.is_deleted) {
                  const safeMsg = (msg.message || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                  const safeSender = (msg.sender_name || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                  const previewSnippet = (msg.message || (msg.attachment ? '📎 Lampiran File' : '')).replace(/'/g, "\\'").replace(/"/g, '&quot;');

                  actionsHtml = `
                      <div class="chat-bubble-actions">
                          <button type="button" class="btn-bubble-act" onclick="TaskChatEngine.startReply(${msg.id}, '${safeSender}', '${previewSnippet}')" title="Balas Pesan">
                              <i class="fa-solid fa-reply"></i>
                          </button>
                          ${(isSelf && !isAudio && msg.message) ? `
                              <button type="button" class="btn-bubble-act" onclick="TaskChatEngine.startEdit(${msg.id}, '${safeMsg}')" title="Edit Pesan">
                                  <i class="fa-solid fa-pen"></i>
                              </button>
                          ` : ''}
                          ${(isSelf || this.isAdmin) ? `
                              <button type="button" class="btn-bubble-act danger" onclick="TaskChatEngine.deleteMessage(${msg.id})" title="Hapus Pesan">
                                  <i class="fa-solid fa-trash-can"></i>
                              </button>
                          ` : ''}
                      </div>
                  `;
              }

              const roleBadge = msg.role_slug === 'employee' 
                  ? '<span class="badge bg-light text-secondary border rounded-pill px-1.5 py-0.5" style="font-size: 0.62rem;">Karyawan</span>'
                  : '<span class="badge bg-indigo-subtle text-primary border rounded-pill px-1.5 py-0.5" style="font-size: 0.62rem; background: #eef2ff;">Admin/HR</span>';

              const row = document.createElement('div');
              row.className = `chat-bubble-row ${isSelf ? 'self' : 'partner'}`;
              row.id = `chat-msg-${msg.id}`;

              const textHtml = (showTextMessage && msg.message) ? `<div class="chat-bubble-text">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>` : '';
              const editedTag = msg.is_edited ? '<span class="chat-edited-tag">diedit</span>' : '';

              row.innerHTML = `
                  ${!isSelf ? avatarHtml : ''}
                  <div class="chat-bubble-content">
                      ${!isSelf ? `<div class="chat-sender-label">${escapeHtml(msg.sender_name)} ${roleBadge}</div>` : ''}
                      <div class="chat-bubble-wrapper">
                          ${actionsHtml}
                          <div class="chat-bubble">
                              ${quoteHtml}
                              ${textHtml}
                              ${attachmentHtml}
                          </div>
                      </div>
                      <div class="chat-meta">
                          ${msg.time_formatted} ${editedTag} ${isSelf ? '<i class="fa-solid fa-check ms-0.5 text-primary"></i>' : ''}
                      </div>
                  </div>
              `;

              body.appendChild(row);
          },

          // WHATSAPP VOICE NOTE INTERACTIVE METHODS
          currentPlayingAudioId: null,

          onVnLoaded(msgId) {
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const timer = document.getElementById(`vn-time-${msgId}`);
              if (audio && timer && !isNaN(audio.duration) && isFinite(audio.duration)) {
                  timer.textContent = this.formatAudioTime(audio.duration);
              }
          },

          togglePlayVn(msgId) {
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const icon = document.getElementById(`vn-icon-${msgId}`);
              if (!audio || !icon) return;

              if (!audio.paused) {
                  audio.pause();
                  icon.className = 'fa-solid fa-play';
                  this.currentPlayingAudioId = null;
              } else {
                  if (this.currentPlayingAudioId && this.currentPlayingAudioId !== msgId) {
                      const prevAudio = document.getElementById(`vn-audio-${this.currentPlayingAudioId}`);
                      const prevIcon = document.getElementById(`vn-icon-${this.currentPlayingAudioId}`);
                      if (prevAudio) prevAudio.pause();
                      if (prevIcon) prevIcon.className = 'fa-solid fa-play';
                  }

                  audio.play().then(() => {
                      icon.className = 'fa-solid fa-pause';
                      this.currentPlayingAudioId = msgId;
                  }).catch(e => console.log('Audio play error:', e));
              }
          },

          updateVnProgress(msgId) {
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const timer = document.getElementById(`vn-time-${msgId}`);
              const wave = document.getElementById(`vn-wave-${msgId}`);
              if (!audio) return;

              const current = audio.currentTime;
              const duration = audio.duration || 1;
              const progress = current / duration;

              if (timer) {
                  timer.textContent = this.formatAudioTime(current);
              }

              if (wave) {
                  const spans = wave.querySelectorAll('span');
                  const activeIndex = Math.floor(progress * spans.length);
                  spans.forEach((span, idx) => {
                      if (idx <= activeIndex) {
                          span.classList.add('played');
                      } else {
                          span.classList.remove('played');
                      }
                  });
              }
          },

          onVnEnded(msgId) {
              const icon = document.getElementById(`vn-icon-${msgId}`);
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const timer = document.getElementById(`vn-time-${msgId}`);
              const wave = document.getElementById(`vn-wave-${msgId}`);
              
              if (icon) icon.className = 'fa-solid fa-play';
              if (audio && timer) timer.textContent = this.formatAudioTime(audio.duration || 0);
              if (wave) {
                  wave.querySelectorAll('span').forEach(s => s.classList.remove('played'));
              }
              if (this.currentPlayingAudioId === msgId) {
                  this.currentPlayingAudioId = null;
              }
          },

          seekVn(event, msgId) {
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const container = event.currentTarget;
              if (!audio || !container) return;

              const rect = container.getBoundingClientRect();
              const clickX = event.clientX - rect.left;
              const pct = Math.max(0, Math.min(1, clickX / rect.width));
              if (audio.duration && !isNaN(audio.duration)) {
                  audio.currentTime = pct * audio.duration;
              }
          },

          changeVnSpeed(msgId) {
              const audio = document.getElementById(`vn-audio-${msgId}`);
              const btn = document.getElementById(`vn-speed-${msgId}`);
              if (!audio || !btn) return;

              const speeds = [1, 1.5, 2];
              let nextIdx = (speeds.indexOf(audio.playbackRate) + 1) % speeds.length;
              if (nextIdx < 0) nextIdx = 0;
              const newSpeed = speeds[nextIdx];
              audio.playbackRate = newSpeed;
              btn.textContent = `${newSpeed}x`;
          },

          formatAudioTime(seconds) {
              if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
              const mins = Math.floor(seconds / 60);
              const secs = Math.floor(seconds % 60);
              return `${mins}:${String(secs).padStart(2, '0')}`;
          },

          sendMessage(overrideFile = null, overrideMsg = '') {
              const input = document.getElementById('chatMessageInput');
              const fileInput = document.getElementById('chatFileInput');
              const replyInput = document.getElementById('chatReplyToId');
              const editInput = document.getElementById('chatEditingId');
              
              let message = overrideMsg || (input ? input.value.trim() : '');
              let file = overrideFile || (fileInput && fileInput.files.length > 0 ? fileInput.files[0] : null);
              const replyToId = replyInput ? replyInput.value : '';
              const editingId = editInput ? editInput.value : '';

              if (!message && !file) return;
              if (!this.activeTaskId) return;

              const sendBtn = document.getElementById('chatSendBtn');
              if (sendBtn) {
                  sendBtn.disabled = true;
                  sendBtn.innerHTML = '<div class="spinner-border spinner-border-sm text-white" style="width: 14px; height: 14px;"></div>';
              }

              // Handle EDIT MESSAGE MODE
              if (editingId && !overrideFile) {
                  const editData = new FormData();
                  editData.append('action', 'edit_message');
                  editData.append('chat_id', editingId);
                  editData.append('message', message);

                  fetch('<?= BASE_URL ?>/api/task_chat.php', {
                      method: 'POST',
                      body: editData
                  })
                  .then(res => res.json())
                  .then(res => {
                      if (sendBtn) {
                          sendBtn.disabled = false;
                          sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane small text-white"></i>';
                      }
                      if (res.success && res.data) {
                          const targetBubbleText = document.querySelector(`#chat-msg-${editingId} .chat-bubble-text`);
                          if (targetBubbleText) {
                              targetBubbleText.innerHTML = escapeHtml(res.data.message).replace(/\n/g, '<br>');
                          }
                          const targetMeta = document.querySelector(`#chat-msg-${editingId} .chat-meta`);
                          if (targetMeta && !targetMeta.querySelector('.chat-edited-tag')) {
                              targetMeta.insertAdjacentHTML('beforeend', '<span class="chat-edited-tag">diedit</span>');
                          }
                          this.cancelEdit();
                      } else {
                          alert(res.message || 'Gagal mengedit pesan');
                      }
                  })
                  .catch(err => {
                      if (sendBtn) {
                          sendBtn.disabled = false;
                          sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane small text-white"></i>';
                      }
                      console.error('Edit error:', err);
                      alert('Terjadi kesalahan saat mengedit pesan.');
                  });
                  return;
              }

              // Handle NORMAL SEND MESSAGE (Supports Reply & Attachments)
              const formData = new FormData();
              formData.append('task_id', this.activeTaskId);
              formData.append('message', message);
              if (replyToId) {
                  formData.append('reply_to_id', replyToId);
              }
              if (file) {
                  formData.append('attachment', file);
              }

              // Clear input immediately for snappy feel
              if (!overrideMsg && input) input.value = '';
              if (!overrideFile) this.clearAttachment();
              this.cancelReply();

              fetch('<?= BASE_URL ?>/api/task_chat.php', {
                  method: 'POST',
                  body: formData
              })
              .then(res => res.json())
              .then(res => {
                  if (sendBtn) {
                      sendBtn.disabled = false;
                      sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane small text-white"></i>';
                  }
                  if (res.success && res.data) {
                      if (res.data.id > this.lastMessageId) {
                          this.lastMessageId = res.data.id;
                      }
                      this.appendMessageElement(res.data);
                      this.scrollToBottom();
                  } else {
                      alert(res.message || 'Gagal mengirim pesan');
                  }
              })
              .catch(err => {
                  if (sendBtn) {
                      sendBtn.disabled = false;
                      sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane small text-white"></i>';
                  }
                  console.error('Send error:', err);
                  alert('Terjadi kesalahan saat mengirim pesan.');
              });
          },

          handleFileSelect(input) {
              if (input.files && input.files[0]) {
                  const file = input.files[0];
                  const preview = document.getElementById('chatAttachmentPreview');
                  const nameEl = document.getElementById('chatAttachmentName');
                  if (preview && nameEl) {
                      nameEl.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
                      preview.classList.remove('d-none');
                      preview.classList.add('d-flex');
                  }
              }
          },

          clearAttachment() {
              const fileInput = document.getElementById('chatFileInput');
              const preview = document.getElementById('chatAttachmentPreview');
              if (fileInput) fileInput.value = '';
              if (preview) {
                  preview.classList.remove('d-flex');
                  preview.classList.add('d-none');
              }
          },

          scrollToBottom() {
              const body = document.getElementById('taskChatMessagesBody');
              if (body) {
                  body.scrollTop = body.scrollHeight;
              }
          },

          checkUnreadCounts() {
              fetch('<?= BASE_URL ?>/api/task_chat.php?action=unread_count')
                  .then(res => res.json())
                  .then(res => {
                      if (res.success) {
                          const count = res.unread_count || 0;
                          const floatingBadge = document.getElementById('floatingChatBadge');
                          const detailBadge = document.getElementById('taskDetailChatBadge');

                          if (floatingBadge) {
                              if (count > 0) {
                                  floatingBadge.textContent = count > 99 ? '99+' : count;
                                  floatingBadge.classList.remove('d-none');
                              } else {
                                  floatingBadge.classList.add('d-none');
                              }
                          }

                          if (detailBadge && this.activeTaskId) {
                              if (count > 0) {
                                  detailBadge.textContent = count;
                                  detailBadge.classList.remove('d-none');
                              } else {
                                  detailBadge.classList.add('d-none');
                              }
                          }
                      }
                  })
                  .catch(() => {});
          }
      };

      function escapeHtml(str) {
          if (!str) return '';
          return String(str)
              .replace(/&/g, '&amp;')
              .replace(/</g, '&lt;')
              .replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;')
              .replace(/'/g, '&#039;');
      }

      document.addEventListener('DOMContentLoaded', function() {
          TaskChatEngine.init();
      });
    </script>

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
