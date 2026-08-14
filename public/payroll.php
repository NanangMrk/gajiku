<?php
// payroll.php
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('process_payroll');
$user = getAuthUser();
$db = getDbConnection();

// Dynamic schema upgrade
try {
    $db->exec("ALTER TABLE payrolls ADD COLUMN transfer_receipt TEXT");
} catch (Exception $e) {
    // Column already exists
}

// Helper function to check if a bonus or deduction is a team (division-wide) item
function isTeamItem($db, $name, $type) {
    if (stripos($name, 'tim') !== false || stripos($name, 'team') !== false || stripos($name, 'project') !== false) {
        return true;
    }
    if ($type === 'individual_bonus') {
        $stmt = $db->prepare("SELECT division_id, calculation_method FROM bonus_types WHERE name = ?");
        $stmt->execute([$name]);
        $res = $stmt->fetch();
        if ($res && $res['division_id'] !== null && ($res['calculation_method'] === 'fixed' || $res['calculation_method'] === 'team')) {
            return true;
        }
    }
    if ($type === 'deduction') {
        $stmt = $db->prepare("SELECT division_id, calculation_method FROM deduction_types WHERE name = ?");
        $stmt->execute([$name]);
        $res = $stmt->fetch();
        if ($res && $res['division_id'] !== null && ($res['calculation_method'] === 'fixed' || $res['calculation_method'] === 'team')) {
            return true;
        }
    }
    return false;
}

// Ensure qty column exists in payroll_items
try {
    $db->exec("ALTER TABLE payroll_items ADD COLUMN qty INTEGER DEFAULT 1");
} catch (Exception $e) {
    // Column already exists
}

$pageTitle = 'Proses Payroll';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_period') {
        $month = (int)($_POST['month'] ?? date('n'));
        $year = (int)($_POST['year'] ?? date('Y'));

        $indonesianMonths = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        $name = $indonesianMonths[$month] . ' ' . $year;
        
        // End Date & Payment Date = 28th of current month
        $endDate = sprintf('%04d-%02d-28', $year, $month);
        $paymentDate = sprintf('%04d-%02d-28', $year, $month);

        // Start Date = 29th of previous month
        if ($month === 1) {
            $prevMonth = 12;
            $prevYear = $year - 1;
        } else {
            $prevMonth = $month - 1;
            $prevYear = $year;
        }
        $startDate = sprintf('%04d-%02d-29', $prevYear, $prevMonth);

        // Check for duplicates
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM payroll_periods WHERE name = ?");
        $stmtCheck->execute([$name]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            setFlashMessage('danger', 'Periode payroll ' . e($name) . ' sudah terdaftar sebelumnya.');
            header('Location: ' . BASE_URL . '/payroll.php');
            exit;
        }

        $stmt = $db->prepare("
            INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by)
            VALUES (?, ?, ?, ?, 'draft', ?)
        ");
        $stmt->execute([$name, $startDate, $endDate, $paymentDate, $user['id']]);
        $periodId = (int)$db->lastInsertId();

        logAudit($user['id'], 'CREATE_PAYROLL_PERIOD', 'Created payroll period: ' . $name);
        setFlashMessage('success', 'Periode payroll ' . e($name) . ' berhasil dibuat (Draft).');
        header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
        exit;
    }

    if ($action === 'calculate_payroll') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ? AND status IN ('draft', 'review')");
        $stmtP->execute([$periodId]);
        $period = $stmtP->fetch();

        if ($period) {
            try {
                $db->beginTransaction();

                // Delete existing payroll records for this period to trigger re-initialization
                $stmtDel = $db->prepare("DELETE FROM payrolls WHERE payroll_period_id = ?");
                $stmtDel->execute([$periodId]);

                $stmtUpP = $db->prepare("UPDATE payroll_periods SET status = 'review' WHERE id = ?");
                $stmtUpP->execute([$periodId]);

                $db->commit();
                logAudit($user['id'], 'RESET_PAYROLL', 'Reset/Calculated payroll period ID ' . $periodId);
                setFlashMessage('success', 'Data payroll berhasil direset. Silakan isi gaji & bonus secara manual di bawah.');
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal mereset payroll: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
        exit;
    }

    if ($action === 'edit_employee_payroll') {
        $payrollId = (int)($_POST['payroll_id'] ?? 0);
        $basicSalary = (float)($_POST['basic_salary'] ?? 0);
        $quantities = $_POST['qty'] ?? [];

        if ($payrollId > 0) {
            try {
                $db->beginTransaction();

                // Find period ID
                $stmtPeriodId = $db->prepare("SELECT payroll_period_id FROM payrolls WHERE id = ?");
                $stmtPeriodId->execute([$payrollId]);
                $periodId = (int)$stmtPeriodId->fetchColumn();

                // 1. Update basic salary item in payroll_items for the individual
                $stmtUpBasicItem = $db->prepare("
                    UPDATE payroll_items 
                    SET amount = ? 
                    WHERE payroll_id = ? AND type = 'basic'
                ");
                $stmtUpBasicItem->execute([$basicSalary, $payrollId]);

                // 2. Update basic salary in payroll record
                $db->prepare("UPDATE payrolls SET basic_salary = ? WHERE id = ?")->execute([$basicSalary, $payrollId]);

                // 3. Update quantities and calculate amounts for each item
                $stmtGetItem = $db->prepare("SELECT * FROM payroll_items WHERE id = ? AND payroll_id = ?");
                $stmtUpItem = $db->prepare("UPDATE payroll_items SET qty = ?, amount = ? WHERE id = ?");

                foreach ($quantities as $itemId => $qty) {
                    $qty = (int)$qty;
                    if ($qty < 0) $qty = 0;

                    $stmtGetItem->execute([$itemId, $payrollId]);
                    $item = $stmtGetItem->fetch();
                    if ($item && $item['type'] !== 'basic') {
                        $rate = (float)($item['notes'] ?: 0);
                        $amount = $qty * $rate;

                        // Check if it is a team item
                        if (isTeamItem($db, $item['name'], $item['type'])) {
                            // Find employee's division
                            $stmtDiv = $db->prepare("SELECT division_snapshot FROM payrolls WHERE id = ?");
                            $stmtDiv->execute([$payrollId]);
                            $division = $stmtDiv->fetchColumn() ?: '';

                            // Update the item for everyone in the same division for this period
                            $stmtUpAll = $db->prepare("
                                UPDATE payroll_items 
                                SET qty = ?, amount = ? 
                                WHERE type = ? AND name = ? AND payroll_id IN (
                                    SELECT id FROM payrolls 
                                    WHERE payroll_period_id = ? AND division_snapshot = ?
                                )
                            ");
                            $stmtUpAll->execute([$qty, $amount, $item['type'], $item['name'], $periodId, $division]);
                        } else {
                            // Update individual item
                            $stmtUpItem->execute([$qty, $amount, $itemId]);
                        }
                    }
                }

                // 4. Re-calculate totals for ALL employees in the period (since team items affect multiple people)
                $stmtAllPay = $db->prepare("SELECT id, basic_salary FROM payrolls WHERE payroll_period_id = ?");
                $stmtAllPay->execute([$periodId]);
                $allPays = $stmtAllPay->fetchAll();

                $stmtBonusTotal = $db->prepare("
                    SELECT SUM(amount) FROM payroll_items 
                    WHERE payroll_id = ? AND type = 'individual_bonus'
                ");
                $stmtDeductTotal = $db->prepare("
                    SELECT SUM(amount) FROM payroll_items 
                    WHERE payroll_id = ? AND type = 'deduction'
                ");
                $stmtUpPay = $db->prepare("
                    UPDATE payrolls 
                    SET total_individual_bonus = ?, total_deductions = ?, take_home_pay = ? 
                    WHERE id = ?
                ");

                foreach ($allPays as $p) {
                    $pId = $p['id'];
                    $bSalary = (float)$p['basic_salary'];

                    $stmtBonusTotal->execute([$pId]);
                    $totalBonus = (float)$stmtBonusTotal->fetchColumn();

                    $stmtDeductTotal->execute([$pId]);
                    $totalDeductions = (float)$stmtDeductTotal->fetchColumn();

                    $takeHomePay = $bSalary + $totalBonus - $totalDeductions;

                    $stmtUpPay->execute([$totalBonus, $totalDeductions, $takeHomePay, $pId]);
                }

                $db->commit();
                logAudit($user['id'], 'EDIT_EMPLOYEE_PAYROLL', 'Updated payroll items for payroll ID ' . $payrollId);
                setFlashMessage('success', 'Gaji & bonus karyawan berhasil diperbarui secara real-time.');
                header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal memperbarui payroll karyawan: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'finalize_payroll') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ? AND status = 'review'");
        $stmtP->execute([$periodId]);
        $period = $stmtP->fetch();

        if ($period) {
            try {
                $db->beginTransaction();

                $stmtFinal = $db->prepare("UPDATE payroll_periods SET status = 'final', finalized_at = datetime('now', 'localtime'), finalized_by = ? WHERE id = ?");
                $stmtFinal->execute([$user['id'], $periodId]);

                $payrolls = $db->query("SELECT id, employee_id, payslip_number FROM payrolls WHERE payroll_period_id = {$periodId}")->fetchAll();
                $stmtSlip = $db->prepare("INSERT OR IGNORE INTO payslips (payroll_id, employee_id, payslip_number) VALUES (?, ?, ?)");
                foreach ($payrolls as $pay) {
                    $stmtSlip->execute([$pay['id'], $pay['employee_id'], $pay['payslip_number']]);

                    $stmtEmpUser = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
                    $stmtEmpUser->execute([$pay['employee_id']]);
                    $empUserId = $stmtEmpUser->fetchColumn();
                    if ($empUserId) {
                        sendNotification($empUserId, 'Slip Gaji Tersedia', 'Slip Gaji periode ' . $period['name'] . ' telah difinalisasi.', '/payslips.php?id=' . $pay['id']);
                    }
                }

                $db->commit();
                logAudit($user['id'], 'FINALIZE_PAYROLL', 'Finalized payroll period ID ' . $periodId . ' (' . $period['name'] . ')');
                setFlashMessage('success', 'Payroll periode ' . e($period['name']) . ' telah DIFINALISASI & DIKUNCI PERMANEN.');
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal memfinalisasi payroll: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
        exit;
    }

    if ($action === 'upload_receipt') {
        $payrollId = (int)($_POST['payroll_id'] ?? 0);
        $periodId = (int)($_POST['period_id'] ?? 0);

        if ($payrollId > 0 && isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['receipt'];
            $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
            $maxSize = 2 * 1024 * 1024; // 2MB

            if (!in_array($file['type'], $allowedTypes)) {
                setFlashMessage('danger', 'Gagal: Format bukti transfer harus berupa JPG, PNG, atau PDF.');
            } elseif ($file['size'] > $maxSize) {
                setFlashMessage('danger', 'Gagal: Ukuran bukti transfer tidak boleh melebihi 2MB.');
            } else {
                // Ensure upload directory exists
                $uploadDir = ROOT_PATH . '/public/uploads/receipts/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'receipt_' . $payrollId . '_' . time() . '.' . $ext;
                $dest = $uploadDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    // Update database
                    $receiptUrl = '/uploads/receipts/' . $filename;
                    $stmtUpdate = $db->prepare("UPDATE payrolls SET transfer_receipt = ? WHERE id = ?");
                    $stmtUpdate->execute([$receiptUrl, $payrollId]);

                    // Send notification to employee
                    $stmtEmp = $db->prepare("
                        SELECT e.user_id, p.payslip_number 
                        FROM payrolls p
                        JOIN employees e ON p.employee_id = e.id
                        WHERE p.id = ?
                    ");
                    $stmtEmp->execute([$payrollId]);
                    $empInfo = $stmtEmp->fetch();
                    if ($empInfo && $empInfo['user_id'] > 0) {
                        sendNotification($empInfo['user_id'], 'Gaji Ditransfer', 'Bukti transfer untuk slip gaji ' . $empInfo['payslip_number'] . ' telah diunggah oleh HRD.', '/payslips.php?id=' . $payrollId);
                    }

                    logAudit($user['id'], 'UPLOAD_TRANSFER_RECEIPT', 'Uploaded transfer receipt for payroll ID ' . $payrollId);
                    setFlashMessage('success', 'Bukti transfer berhasil diunggah.');
                } else {
                    setFlashMessage('danger', 'Gagal mengunggah file bukti transfer.');
                }
            }
        } else {
            setFlashMessage('danger', 'Gagal: File tidak ditemukan atau terjadi kesalahan saat mengunggah.');
        }

        header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
        exit;
    }

    if ($action === 'delete_receipt') {
        $payrollId = (int)($_POST['payroll_id'] ?? 0);
        $periodId = (int)($_POST['period_id'] ?? 0);

        if ($payrollId > 0) {
            try {
                $stmt = $db->prepare("SELECT transfer_receipt FROM payrolls WHERE id = ?");
                $stmt->execute([$payrollId]);
                $receiptPath = $stmt->fetchColumn();

                if ($receiptPath) {
                    $fullPath = ROOT_PATH . '/public' . $receiptPath;
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        unlink($fullPath);
                    }

                    $db->prepare("UPDATE payrolls SET transfer_receipt = NULL WHERE id = ?")->execute([$payrollId]);
                    logAudit($user['id'], 'DELETE_TRANSFER_RECEIPT', 'Deleted transfer receipt for payroll ID ' . $payrollId);
                    setFlashMessage('success', 'Bukti transfer berhasil dihapus.');
                }
            } catch (Exception $e) {
                setFlashMessage('danger', 'Gagal menghapus bukti transfer: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/payroll.php?period_id=' . $periodId);
        exit;
    }
}

$company = $db->query("SELECT * FROM company_settings WHERE id = 1")->fetch() ?: ['payroll_payment_day' => 25];
$paymentDay = (int)($company['payroll_payment_day'] ?? 25);
$defaultPaymentDate = date('Y-m-', strtotime('+1 month')) . sprintf('%02d', $paymentDay);

$filterYear = !empty($_GET['filter_year']) ? (int)$_GET['filter_year'] : 0;
$filterStatus = $_GET['filter_status'] ?? '';

$whereClause = "WHERE 1=1";
$queryParams = [];

if ($filterYear > 0) {
    $whereClause .= " AND strftime('%Y', pp.start_date) = ?";
    $queryParams[] = (string)$filterYear;
}

if (!empty($filterStatus)) {
    $whereClause .= " AND pp.status = ?";
    $queryParams[] = $filterStatus;
}

$stmtPeriods = $db->prepare("
    SELECT pp.*, u.username as creator_name,
           (SELECT COUNT(*) FROM payrolls WHERE payroll_period_id = pp.id) as employee_count,
           (SELECT SUM(take_home_pay) FROM payrolls WHERE payroll_period_id = pp.id) as total_amount
    FROM payroll_periods pp
    LEFT JOIN users u ON pp.created_by = u.id
    $whereClause
    ORDER BY pp.id DESC
");
$stmtPeriods->execute($queryParams);
$periods = $stmtPeriods->fetchAll();

// Fetch distinct years for dropdown
$years = $db->query("SELECT DISTINCT strftime('%Y', start_date) as year FROM payroll_periods ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);

$selectedPeriodId = (int)($_GET['period_id'] ?? ($periods[0]['id'] ?? 0));
$selectedPeriod = null;
$payrollItems = [];

if ($selectedPeriodId > 0) {
    $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmtP->execute([$selectedPeriodId]);
    $selectedPeriod = $stmtP->fetch();

    if ($selectedPeriod) {
        // Auto-initialize payroll records if they do not exist
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM payrolls WHERE payroll_period_id = ?");
        $stmtCheck->execute([$selectedPeriodId]);
        $hasPayrolls = (int)$stmtCheck->fetchColumn() > 0;

        if (!$hasPayrolls) {
            try {
                $db->beginTransaction();
                
                // Get all active employees (excluding Super Admin)
                $employees = $db->query("
                    SELECT e.*, d.name as division_name, t.name as team_name
                    FROM employees e
                    LEFT JOIN divisions d ON e.division_id = d.id
                    LEFT JOIN teams t ON e.team_id = t.id
                    LEFT JOIN users u ON e.user_id = u.id
                    LEFT JOIN roles r ON u.role_id = r.id
                    WHERE e.status = 'active' AND (r.slug IS NULL OR r.slug != 'super_admin')
                ")->fetchAll();
                
                $yearMonth = date('Y/m', strtotime($selectedPeriod['end_date']));
                $prefix = "SLIP/" . $yearMonth . "/";

                // Fetch max counter for this yearMonth prefix to prevent UNIQUE constraint violation
                $stmtMax = $db->prepare("SELECT payslip_number FROM payrolls WHERE payslip_number LIKE ? ORDER BY id DESC LIMIT 1");
                $stmtMax->execute([$prefix . '%']);
                $lastSlip = $stmtMax->fetchColumn();

                $counter = 1;
                if ($lastSlip) {
                    $parts = explode('/', $lastSlip);
                    $lastCounter = (int)end($parts);
                    $counter = $lastCounter + 1;
                }
                
                foreach ($employees as $emp) {
                    $empId = $emp['id'];
                    
                    // Get current active basic salary
                    $stmtSal = $db->prepare("
                        SELECT basic_salary FROM salary_history 
                        WHERE employee_id = ? AND effective_date <= ? 
                        ORDER BY effective_date DESC, id DESC LIMIT 1
                    ");
                    $stmtSal->execute([$empId, $selectedPeriod['end_date']]);
                    $basicSalary = (float)($stmtSal->fetchColumn() ?: 0);
                    
                    $payslipNumber = sprintf("SLIP/%s/%05d", $yearMonth, $counter++);
                    
                    // Insert payroll record
                    $stmtInsPay = $db->prepare("
                        INSERT INTO payrolls (payroll_period_id, employee_id, nik_snapshot, employee_name_snapshot, division_snapshot, team_snapshot, position_snapshot, basic_salary, total_individual_bonus, total_team_bonus, total_adjustments, total_deductions, take_home_pay, payslip_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)
                    ");
                    $stmtInsPay->execute([
                        $selectedPeriodId, $empId, $emp['nik'], $emp['full_name'], $emp['division_name'], $emp['team_name'], $emp['position'],
                        $basicSalary, $basicSalary, $payslipNumber
                    ]);
                    $payrollId = (int)$db->lastInsertId();
                    
                    // Insert Gaji Pokok item
                    $stmtItem = $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, 'basic', 'Gaji Pokok', ?, 1, 'Gaji Pokok Periode')");
                    $stmtItem->execute([$payrollId, $basicSalary]);
                    
                    // Get applicable bonus types
                    $stmtBonus = $db->prepare("
                        SELECT * FROM bonus_types
                        WHERE (division_id = ? OR division_id IS NULL)
                        AND status = 'active'
                    ");
                    $stmtBonus->execute([$emp['division_id']]);
                    $bonusTypes = $stmtBonus->fetchAll();
                    
                    // Insert bonus items with qty = 0, storing the rate in notes
                    $stmtInsItem = $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, 'individual_bonus', ?, 0, 0, ?)");
                    foreach ($bonusTypes as $bt) {
                        $rate = (float)$bt['rate'];
                        if ($bt['calculation_method'] === 'team' && $emp['division_id'] !== null) {
                            // Count active employees in this division (excluding Super Admin)
                            $stmtCount = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = ? AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)");
                            $stmtCount->execute([$emp['division_id']]);
                            $divCount = (int)$stmtCount->fetchColumn();
                            if ($divCount > 0) {
                                $rate = $rate / $divCount;
                            }
                        }
                        $stmtInsItem->execute([$payrollId, $bt['name'], (string)$rate]);
                    }
                    
                    // Get applicable deduction types
                    $stmtDeduct = $db->prepare("
                        SELECT * FROM deduction_types
                        WHERE (division_id = ? OR division_id IS NULL)
                        AND status = 'active'
                    ");
                    $stmtDeduct->execute([$emp['division_id']]);
                    $deductionTypes = $stmtDeduct->fetchAll();
                    
                    // Insert deduction items with qty = 0, storing the default amount in notes
                    $stmtInsItemDeduct = $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, 'deduction', ?, 0, 0, ?)");
                    foreach ($deductionTypes as $dt) {
                        $amount = (float)$dt['default_amount'];
                        if ($dt['calculation_method'] === 'team' && $emp['division_id'] !== null) {
                            // Count active employees in this division (excluding Super Admin)
                            $stmtCount = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = ? AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)");
                            $stmtCount->execute([$emp['division_id']]);
                            $divCount = (int)$stmtCount->fetchColumn();
                            if ($divCount > 0) {
                                $amount = $amount / $divCount;
                            }
                        }
                        $stmtInsItemDeduct->execute([$payrollId, $dt['name'], (string)$amount]);
                    }
                }
                
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                setFlashMessage('danger', 'Gagal inisialisasi data payroll: ' . $e->getMessage());
            }
        }

        // Fetch payroll items for display
        $stmtPay = $db->prepare("SELECT * FROM payrolls WHERE payroll_period_id = ? ORDER BY employee_name_snapshot ASC");
        $stmtPay->execute([$selectedPeriodId]);
        $payrollItems = $stmtPay->fetchAll();
    }
}

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <style>
      .payroll-card {
          transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
          border: 1px solid #eef2f5 !important;
          background-color: #ffffff;
      }
      .payroll-card:hover {
          transform: translateY(-4px);
          box-shadow: 0 12px 20px rgba(0, 0, 0, 0.05) !important;
          border-color: #d1d9e6 !important;
      }
      /* Hide spinner arrows for clean number inputs */
      .qty-input::-webkit-outer-spin-button,
      .qty-input::-webkit-inner-spin-button {
          -webkit-appearance: none;
          margin: 0;
      }
      .qty-input {
          -moz-appearance: textfield;
          outline: none;
          transition: all 0.2s ease;
      }
      .qty-input:focus {
          border-color: #4b49ac !important;
          box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
      }
      .basic-salary-input:focus {
          border-color: #4b49ac !important;
          box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
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
          <h3 class="font-weight-bold mb-0">Proses &amp; Kalkulasi Payroll</h3>
          <p class="text-muted mb-0">Manajemen periode gaji, monitoring gaji real-time, dan pencetakan slip gaji</p>
        </div>
        <!-- Desktop Add Button -->
        <button class="btn btn-primary rounded-pill font-weight-bold d-none d-md-block" data-bs-toggle="modal" data-bs-target="#createPeriodModal">
          <i class="fa-solid fa-calendar-plus me-1"></i> Buat Periode Payroll Baru
        </button>
        
        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" data-bs-toggle="modal" data-bs-target="#createPeriodModal" style="bottom: calc(75px + env(safe-area-inset-bottom, 0px)); right: 18px; width: 50px; height: 50px; z-index: 1035; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;" aria-label="Buat Periode Baru">
          <i class="fa-solid fa-plus fs-4 text-white"></i>
        </button>
      </div>

      <!-- Period Filters -->
      <div class="card shadow-sm border-0 rounded-4 mb-3">
        <div class="card-body py-2.5">
          <form action="<?= BASE_URL ?>/payroll.php" method="GET" class="row g-2 align-items-center">
            <!-- Simpan period_id terpilih agar tidak hilang saat memfilter, jika periode tersebut masih cocok -->
            <input type="hidden" name="period_id" value="<?= $selectedPeriodId ?>">
            
            <div class="col-6 col-md-3">
              <select name="filter_year" class="form-select form-control form-control-sm rounded-3">
                <option value="">-- Semua Tahun --</option>
                <?php foreach ($years as $yr): ?>
                  <option value="<?= $yr ?>" <?= $filterYear === (int)$yr ? 'selected' : '' ?>><?= $yr ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="col-6 col-md-3">
              <select name="filter_status" class="form-select form-control form-control-sm rounded-3">
                <option value="">-- Semua Status --</option>
                <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="review" <?= $filterStatus === 'review' ? 'selected' : '' ?>>Review</option>
                <option value="final" <?= $filterStatus === 'final' ? 'selected' : '' ?>>Final</option>
              </select>
            </div>
            
            <div class="col-12 col-md-3 d-flex gap-2">
              <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill font-weight-bold">
                <i class="fa-solid fa-filter me-1"></i> Filter Periode
              </button>
              <a href="<?= BASE_URL ?>/payroll.php" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="fa-solid fa-rotate-right"></i>
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Periods List -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body py-3">
          <div class="d-flex align-items-center gap-2 overflow-auto">
            <span class="font-weight-bold text-dark me-2">Pilih Periode:</span>
            <?php if (empty($periods)): ?>
              <span class="text-muted small">Tidak ada periode yang cocok dengan filter.</span>
            <?php else: ?>
              <?php foreach ($periods as $p): ?>
                <a href="<?= BASE_URL ?>/payroll.php?period_id=<?= $p['id'] ?>&amp;filter_year=<?= $filterYear ?>&amp;filter_status=<?= urlencode($filterStatus) ?>" class="btn btn-sm <?= $selectedPeriodId === (int)$p['id'] ? 'btn-primary font-weight-bold' : 'btn-light' ?> rounded-pill text-nowrap">
                  <?= e($p['name']) ?> 
                  <span class="badge bg-<?= $p['status'] === 'final' ? 'success' : ($p['status'] === 'review' ? 'warning' : 'secondary') ?> ms-1">
                    <?= strtoupper($p['status']) ?>
                  </span>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Selected Period Detail View & Actions -->
      <?php if ($selectedPeriod): ?>
        <div class="card shadow-sm border-0 rounded-4 mb-4">
          <div class="card-body">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 border-bottom pb-3 mb-4">
              <div>
                <h4 class="font-weight-bold text-primary mb-1"><?= e($selectedPeriod['name']) ?></h4>
                <div class="d-flex flex-column flex-sm-row gap-1 gap-sm-3 text-muted small mb-0 flex-wrap">
                  <span><i class="fa-solid fa-calendar me-1"></i> Periode: <?= formatTanggalIndo($selectedPeriod['start_date']) ?> s/d <?= formatTanggalIndo($selectedPeriod['end_date']) ?></span>
                  <span class="d-none d-sm-inline">|</span>
                  <span><i class="fa-solid fa-credit-card me-1"></i> Bayar: <?= formatTanggalIndo($selectedPeriod['payment_date']) ?></span>
                </div>
              </div>
              <div class="d-flex align-items-center gap-2 flex-wrap w-100 w-md-auto">
                <span class="badge bg-<?= $selectedPeriod['status'] === 'final' ? 'success' : ($selectedPeriod['status'] === 'review' ? 'warning' : 'secondary') ?> px-3 py-2" style="font-size: 0.75rem;">
                  STATUS: <?= strtoupper($selectedPeriod['status']) ?>
                </span>

                <?php if ($selectedPeriod['status'] === 'draft'): ?>
                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="calculate_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-warning rounded-pill font-weight-bold">
                      <i class="fa-solid fa-calculator me-1"></i> Mulai Proses Payroll
                    </button>
                  </form>
                <?php elseif ($selectedPeriod['status'] === 'review'): ?>
                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="calculate_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-warning rounded-pill font-weight-bold" onclick="return confirm('Mereset data akan mengembalikan seluruh nominal ke nilai default. Lanjutkan?')">
                      <i class="fa-solid fa-rotate me-1"></i> Reset Data
                    </button>
                  </form>
                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="finalize_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success rounded-pill font-weight-bold" onclick="return confirm('PERHATIAN: Setelah difinalisasi, data payroll dikunci dan snapshot slip gaji dihasilkan. Lanjutkan?')">
                      <i class="fa-solid fa-lock me-1"></i> Finalisasi &amp; Kunci Payroll
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Payroll Breakdown Grid of Cards -->
            <?php if (empty($payrollItems)): ?>
              <div class="text-center py-5 text-muted col-12">
                <i class="fa-solid fa-calculator fs-1 mb-2 d-block text-warning"></i>
                Payroll periode ini belum dimulai. Klik tombol <strong>Mulai Proses Payroll</strong> di atas.
              </div>
            <?php else: ?>
              <div class="row">
                <?php 
                  $grandTotal = 0;
                  foreach ($payrollItems as $item): 
                    $grandTotal += (float)$item['take_home_pay'];
                ?>
                  <div class="col-12 col-md-6 col-lg-4 mb-4">
                    <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="payroll-card-form h-100">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="edit_employee_payroll">
                      <input type="hidden" name="payroll_id" value="<?= $item['id'] ?>">
                      
                      <div class="card shadow-sm border-0 rounded-4 h-100 payroll-card">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                          <div>
                            <!-- Header -->
                            <div class="mb-3">
                              <h4 class="font-weight-bold text-dark mb-1"><?= e($item['employee_name_snapshot']) ?></h4>
                              <span class="text-muted small font-weight-bold"><?= e($item['position_snapshot'] ?: '-') ?> (<?= e($item['division_snapshot'] ?: '-') ?>)</span>
                            </div>
                            <hr class="my-3">
                            <!-- Body -->
                            <div class="mb-3">
                              <span class="text-muted small d-block mb-1">Gaji Pokok</span>
                              <?php if ($selectedPeriod['status'] === 'review'): ?>
                                <div class="input-group mb-3">
                                  <span class="input-group-text bg-light text-dark font-weight-bold border-end-0">Rp</span>
                                  <input type="number" name="basic_salary" class="form-control font-weight-bold text-dark basic-salary-input border-start-0 ps-1" style="font-size: 1.25rem;" value="<?= (int)$item['basic_salary'] ?>" data-payroll-id="<?= $item['id'] ?>" required>
                                </div>
                              <?php else: ?>
                                <h3 class="font-weight-bold text-dark mb-3"><?= formatRupiah($item['basic_salary']) ?></h3>
                              <?php endif; ?>

                              <!-- Bonuses -->
                              <?php 
                                $stmtB = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = 'individual_bonus' ORDER BY id ASC");
                                $stmtB->execute([$item['id']]);
                                $empBonuses = $stmtB->fetchAll();
                              ?>
                              <span class="text-muted small d-block mb-2 font-weight-bold">Daftar Bonus Bulan Ini</span>
                              <?php if (empty($empBonuses)): ?>
                                <p class="text-muted small italic">Tidak ada master bonus divisi.</p>
                              <?php else: ?>
                                <?php foreach ($empBonuses as $eb): ?>
                                  <?php if ($selectedPeriod['status'] === 'review'): ?>
                                    <div class="p-3 mb-2 rounded-3" style="background-color: #f6ffed; border: 1px solid #d9f7be;">
                                      <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                          <h6 class="font-weight-bold mb-1" style="color: #389e0d;">
                                            <?= e($eb['name']) ?>
                                            <?php if (isTeamItem($db, $eb['name'], $eb['type'])): ?>
                                              <span class="badge text-white ms-1 px-2 py-0.5" style="font-size: 0.7rem; vertical-align: middle; background-color: #389e0d;">Tim</span>
                                            <?php endif; ?>
                                          </h6>
                                          <small class="opacity-75" style="color: #389e0d; font-weight: 500;"><?= formatRupiah((float)$eb['notes']) ?> / unit</small>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                          <input type="number" name="qty[<?= $eb['id'] ?>]" class="form-control text-center qty-input bonus-qty-input px-1" style="width: 55px; height: 32px; border: 1px solid #b7eb8f; border-radius: 6px; font-weight: bold; color: #389e0d;" value="<?= $eb['qty'] ?>" data-rate="<?= (float)$eb['notes'] ?>" data-payroll-id="<?= $item['id'] ?>" min="0" required>
                                          <span class="small font-weight-bold" style="color: #389e0d;">unit</span>
                                        </div>
                                      </div>
                                    </div>
                                  <?php else: ?>
                                    <?php if ($eb['qty'] > 0): ?>
                                      <div class="p-3 mb-2 rounded-3 bg-light border-0" style="background-color: #f6ffed80 !important; border: 1px dashed #d9f7be !important;">
                                        <div class="d-flex justify-content-between align-items-center">
                                          <div>
                                            <h6 class="font-weight-bold mb-1" style="color: #389e0d;">
                                              <?= e($eb['name']) ?>
                                              <?php if (isTeamItem($db, $eb['name'], $eb['type'])): ?>
                                                <span class="badge text-white ms-1 px-2 py-0.5" style="font-size: 0.7rem; vertical-align: middle; background-color: #389e0d;">Tim</span>
                                              <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><?= formatRupiah((float)$eb['notes']) ?> x <?= $eb['qty'] ?></small>
                                          </div>
                                          <span class="font-weight-bold" style="color: #389e0d;">+<?= formatRupiah($eb['amount']) ?></span>
                                        </div>
                                      </div>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                <?php endforeach; ?>
                              <?php endif; ?>

                              <!-- Deductions -->
                              <?php 
                                $stmtD = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = 'deduction' ORDER BY id ASC");
                                $stmtD->execute([$item['id']]);
                                $empDeductions = $stmtD->fetchAll();
                              ?>
                              <span class="text-muted small d-block mb-2 mt-3 font-weight-bold">Daftar Potongan Bulan Ini</span>
                              <?php if (empty($empDeductions)): ?>
                                <p class="text-muted small italic">Tidak ada master potongan divisi.</p>
                              <?php else: ?>
                                <?php foreach ($empDeductions as $ed): ?>
                                  <?php if ($selectedPeriod['status'] === 'review'): ?>
                                    <div class="p-3 mb-2 rounded-3" style="background-color: #fff2f0; border: 1px solid #ffccc7;">
                                      <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                          <h6 class="font-weight-bold mb-1" style="color: #cf1322;">
                                            <?= e($ed['name']) ?>
                                            <?php if (isTeamItem($db, $ed['name'], $ed['type'])): ?>
                                              <span class="badge text-white ms-1 px-2 py-0.5" style="font-size: 0.7rem; vertical-align: middle; background-color: #cf1322;">Tim</span>
                                            <?php endif; ?>
                                          </h6>
                                          <small class="opacity-75" style="color: #cf1322; font-weight: 500;"><?= formatRupiah((float)$ed['notes']) ?> / jam</small>
                                        </div>
                                        <div class="d-flex align-items-center gap-1">
                                          <input type="number" name="qty[<?= $ed['id'] ?>]" class="form-control text-center qty-input deduction-qty-input px-1" style="width: 55px; height: 32px; border: 1px solid #ffa39e; border-radius: 6px; font-weight: bold; color: #cf1322;" value="<?= $ed['qty'] ?>" data-rate="<?= (float)$ed['notes'] ?>" data-payroll-id="<?= $item['id'] ?>" min="0" required>
                                          <span class="small font-weight-bold" style="color: #cf1322;">jam</span>
                                        </div>
                                      </div>
                                    </div>
                                  <?php else: ?>
                                    <?php if ($ed['qty'] > 0): ?>
                                      <div class="p-3 mb-2 rounded-3 bg-light border-0" style="background-color: #fff2f080 !important; border: 1px dashed #ffccc7 !important;">
                                        <div class="d-flex justify-content-between align-items-center">
                                          <div>
                                            <h6 class="font-weight-bold mb-1" style="color: #cf1322;">
                                              <?= e($ed['name']) ?>
                                              <?php if (isTeamItem($db, $ed['name'], $ed['type'])): ?>
                                                <span class="badge text-white ms-1 px-2 py-0.5" style="font-size: 0.7rem; vertical-align: middle; background-color: #cf1322;">Tim</span>
                                              <?php endif; ?>
                                            </h6>
                                            <small class="text-muted"><?= formatRupiah((float)$ed['notes']) ?> x <?= $ed['qty'] ?></small>
                                          </div>
                                          <span class="font-weight-bold text-danger">-<?= formatRupiah($ed['amount']) ?></span>
                                        </div>
                                      </div>
                                    <?php endif; ?>
                                  <?php endif; ?>
                                <?php endforeach; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                          
                          <div>
                            <hr class="my-3">
                            <!-- Totals -->
                            <div class="d-flex justify-content-between align-items-center mb-3">
                              <h5 class="font-weight-bold text-dark mb-0">Total Take Home Pay</h5>
                              <h4 class="font-weight-bold text-success mb-0 thp-display" id="thpDisplay<?= $item['id'] ?>"><?= formatRupiah($item['take_home_pay']) ?></h4>
                            </div>

                            <!-- Actions & Payment Status -->
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top" onclick="event.stopPropagation();">
                              <div>
                                <?php if ($selectedPeriod['status'] === 'final'): ?>
                                  <?php if (!empty($item['transfer_receipt'])): ?>
                                    <span class="text-success font-weight-bold small"><i class="fa-solid fa-circle-check"></i> Gaji Lunas</span>
                                  <?php else: ?>
                                    <span class="text-muted small"><i class="fa-solid fa-circle-exclamation"></i> Belum ditransfer</span>
                                  <?php endif; ?>
                                <?php endif; ?>
                              </div>
                              <div class="d-flex gap-2">
                                <?php if ($selectedPeriod['status'] === 'final'): ?>
                                  <button type="button" class="btn btn-xs btn-outline-primary rounded-pill font-weight-bold px-3" onclick="event.stopPropagation(); openReceiptModal(<?= $item['id'] ?>, '<?= e(addslashes($item['employee_name_snapshot'])) ?>', '<?= e(addslashes($item['transfer_receipt'] ?? '')) ?>', <?= $selectedPeriod['id'] ?>)">
                                    <i class="fa-solid fa-receipt me-1"></i> Bukti Transfer
                                  </button>
                                <?php endif; ?>
                                
                                <?php if ($selectedPeriod['status'] === 'review'): ?>
                                  <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 font-weight-bold">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> Simpan
                                  </button>
                                <?php endif; ?>
                                <a href="<?= BASE_URL ?>/payslip-print.php?id=<?= $item['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill px-3 font-weight-bold d-flex align-items-center">
                                  <i class="fa-solid fa-print me-1"></i> Cetak Slip
                                </a>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </form>
                  </div>
                <?php endforeach; ?>
              </div>

              <!-- Grand Total Summary Banner -->
              <div class="card shadow-sm border-0 rounded-4 mt-4" style="background: linear-gradient(135deg, #f8f9fa 0%, #ebf1fa 100%); border-left: 4px solid #4b49ac !important;">
                <div class="card-body py-3 px-3 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-1">
                  <span class="text-muted small font-weight-bold">
                    <i class="fa-solid fa-calculator me-1 text-primary"></i> Total Estimasi Payroll Periode Ini
                  </span>
                  <span class="font-weight-bold text-success" style="font-size: 1.35rem;"><?= formatRupiah($grandTotal) ?></span>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Modal Create Period -->
    <div class="modal fade" id="createPeriodModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom">
            <h5 class="modal-title font-weight-bold"><i class="fa-solid fa-calendar-plus me-2 text-primary"></i> Buat Periode Payroll Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/payroll.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_period">
            <div class="modal-body">
              <p class="text-muted small mb-4">
                <strong>Aturan Siklus Penggajian:</strong><br>
                &bull; Gaji dibayarkan pada tanggal <strong>28</strong>.<br>
                &bull; Periode dihitung otomatis dari tanggal <strong>29 bulan sebelumnya</strong> s/d tanggal <strong>28 bulan terpilih</strong>.
              </p>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Bulan Periode</label>
                  <select name="month" class="form-select form-control" required>
                    <?php
                      $months = [
                          1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                          5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                          9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                      ];
                      $currentMonth = (int)date('n');
                      foreach ($months as $num => $name):
                    ?>
                      <option value="<?= $num ?>" <?= $num === $currentMonth ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Tahun Periode</label>
                  <select name="year" class="form-select form-control" required>
                    <?php
                      $currentYear = (int)date('Y');
                      for ($y = $currentYear - 1; $y <= $currentYear + 2; $y++):
                    ?>
                      <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary rounded-pill font-weight-bold">Simpan Periode</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Kelola Bukti Transfer -->
    <div class="modal fade" id="receiptModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom">
            <h5 class="modal-title font-weight-bold text-dark"><i class="fa-solid fa-receipt me-2 text-primary"></i> Kelola Bukti Transfer</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="mb-3">
              <span class="text-muted small d-block">Nama Karyawan</span>
              <h5 class="font-weight-bold text-dark mb-0" id="receiptModalEmpName">Nama Karyawan</h5>
            </div>

            <!-- Preview Container -->
            <div id="receiptPreviewContainer" class="mb-4 d-none">
              <span class="text-muted small d-block mb-2 font-weight-bold">Pratinjau Bukti Saat Ini:</span>
              <div class="p-2 rounded bg-light border text-center mb-2" style="max-height: 250px; overflow: hidden;" id="receiptPreviewMedia">
                <!-- Media pratinjau dimuat secara dinamis -->
              </div>
              <div class="d-flex gap-2 justify-content-center">
                <a href="#" target="_blank" id="receiptModalViewFull" class="btn btn-xs btn-outline-primary rounded-pill font-weight-bold"><i class="fa-solid fa-up-right-from-square me-1"></i> Lihat Ukuran Penuh</a>
                <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus bukti transfer ini?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_receipt">
                  <input type="hidden" name="payroll_id" id="deleteReceiptPayrollId">
                  <input type="hidden" name="period_id" id="deleteReceiptPeriodId">
                  <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill font-weight-bold"><i class="fa-solid fa-trash-can me-1"></i> Hapus Bukti</button>
                </form>
              </div>
            </div>

            <!-- Upload / Ganti Form -->
            <form action="<?= BASE_URL ?>/payroll.php" method="POST" enctype="multipart/form-data">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="upload_receipt">
              <input type="hidden" name="payroll_id" id="uploadReceiptPayrollId">
              <input type="hidden" name="period_id" id="uploadReceiptPeriodId">
              
              <div class="mb-3 border-top pt-3">
                <label class="form-label font-weight-bold" id="uploadReceiptLabel">Unggah Bukti Transfer Baru</label>
                <input type="file" name="receipt" class="form-control" accept="image/*,application/pdf" required>
                <small class="text-muted d-block mt-1">Menerima format JPG, PNG, atau PDF (Maks. 2MB)</small>
              </div>
              
              <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                <button type="button" class="btn btn-secondary rounded-pill btn-sm" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary rounded-pill btn-sm font-weight-bold px-4">Mulai Unggah</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Client-side script for payroll and receipts management -->
    <script>
      function openReceiptModal(payrollId, employeeName, receiptUrl, periodId) {
          document.getElementById('receiptModalEmpName').textContent = employeeName;
          document.getElementById('uploadReceiptPayrollId').value = payrollId;
          document.getElementById('uploadReceiptPeriodId').value = periodId;
          document.getElementById('deleteReceiptPayrollId').value = payrollId;
          document.getElementById('deleteReceiptPeriodId').value = periodId;

          const previewContainer = document.getElementById('receiptPreviewContainer');
          const previewMedia = document.getElementById('receiptPreviewMedia');
          const viewFullBtn = document.getElementById('receiptModalViewFull');
          const uploadLabel = document.getElementById('uploadReceiptLabel');

          if (receiptUrl) {
              previewContainer.classList.remove('d-none');
              viewFullBtn.href = '<?= BASE_URL ?>' + receiptUrl;
              uploadLabel.textContent = "Ganti / Perbarui Bukti Transfer";

              const ext = receiptUrl.split('.').pop().toLowerCase();
              if (ext === 'pdf') {
                  previewMedia.innerHTML = `<div class="py-4 text-muted"><i class="fa-solid fa-file-pdf fs-1 mb-2 text-danger"></i><br><span class="small font-weight-bold">Berkas PDF Bukti Transfer</span></div>`;
              } else {
                  previewMedia.innerHTML = `<img src="<?= BASE_URL ?>${receiptUrl}" class="img-fluid rounded shadow-sm" style="max-height: 200px; object-fit: contain;">`;
              }
          } else {
              previewContainer.classList.add('d-none');
              previewMedia.innerHTML = '';
              uploadLabel.textContent = "Unggah Bukti Transfer Baru";
          }

          var modal = new bootstrap.Modal(document.getElementById('receiptModal'));
          modal.show();
      }

      document.addEventListener('DOMContentLoaded', function() {
          function formatRupiah(number) {
              return new Intl.NumberFormat('id-ID', {
                  style: 'currency',
                  currency: 'IDR',
                  minimumFractionDigits: 0,
                  maximumFractionDigits: 0
              }).format(number);
          }

          const inputs = document.querySelectorAll('.basic-salary-input, .qty-input');
          inputs.forEach(input => {
              input.addEventListener('input', function() {
                  const payrollId = this.getAttribute('data-payroll-id');
                  calculateTHP(payrollId);
              });
          });

          function calculateTHP(payrollId) {
              const basicSalaryInput = document.querySelector(`.basic-salary-input[data-payroll-id="${payrollId}"]`);
              const basicSalary = basicSalaryInput ? parseFloat(basicSalaryInput.value) || 0 : 0;

              let totalBonus = 0;
              const bonusInputs = document.querySelectorAll(`.bonus-qty-input[data-payroll-id="${payrollId}"]`);
              bonusInputs.forEach(input => {
                  const qty = parseFloat(input.value) || 0;
                  const rate = parseFloat(input.getAttribute('data-rate')) || 0;
                  totalBonus += qty * rate;
              });

              let totalDeductions = 0;
              const deductionInputs = document.querySelectorAll(`.deduction-qty-input[data-payroll-id="${payrollId}"]`);
              deductionInputs.forEach(input => {
                  const qty = parseFloat(input.value) || 0;
                  const rate = parseFloat(input.getAttribute('data-rate')) || 0;
                  totalDeductions += qty * rate;
              });

              const takeHomePay = basicSalary + totalBonus - totalDeductions;

              const display = document.getElementById(`thpDisplay${payrollId}`);
              if (display) {
                  display.textContent = formatRupiah(takeHomePay);
              }
          }
      });
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
