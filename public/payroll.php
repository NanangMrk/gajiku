<?php
// payroll.php
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

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
if (!function_exists('isTeamItem')) {
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
}

/**
 * Inkremental Non-Destructive Sync:
 * Menambahkan aturan bonus & potongan baru ke periode payroll draft/review
 * TANPA menghapus atau mengubah input yang sudah disimpan.
 */
if (!function_exists('syncPayrollRules')) {
    function syncPayrollRules(PDO $db, int $periodId, array $period): int {
        $addedCount = 0;
        try {
            $db->beginTransaction();

            // 1. Sinkronisasi Karyawan Baru (jika ada karyawan aktif yang belum terdaftar di periode ini)
            $stmtPayrolls = $db->prepare("SELECT employee_id FROM payrolls WHERE payroll_period_id = ?");
            $stmtPayrolls->execute([$periodId]);
            $existingEmpIds = $stmtPayrolls->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $stmtEmployees = $db->query("
                SELECT e.*, d.name as division_name, t.name as team_name
                FROM employees e
                LEFT JOIN divisions d ON e.division_id = d.id
                LEFT JOIN teams t ON e.team_id = t.id
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE e.status = 'active' AND (r.slug IS NULL OR r.slug != 'super_admin')
            ");
            $allEmployees = $stmtEmployees->fetchAll() ?: [];

            $yearMonth = date('Y/m', strtotime($period['end_date']));
            $prefix = "SLIP/" . $yearMonth . "/";

            $stmtMax = $db->prepare("SELECT payslip_number FROM payrolls WHERE payslip_number LIKE ? ORDER BY id DESC LIMIT 1");
            $stmtMax->execute([$prefix . '%']);
            $lastSlip = $stmtMax->fetchColumn();
            $counter = 1;
            if ($lastSlip) {
                $parts = explode('/', $lastSlip);
                $lastCounter = (int)end($parts);
                $counter = $lastCounter + 1;
            }

            foreach ($allEmployees as $emp) {
                $empId = (int)$emp['id'];
                if (!in_array($empId, $existingEmpIds)) {
                    $stmtSal = $db->prepare("
                        SELECT basic_salary FROM salary_history 
                        WHERE employee_id = ? AND effective_date <= ? 
                        ORDER BY effective_date DESC, id DESC LIMIT 1
                    ");
                    $stmtSal->execute([$empId, $period['end_date']]);
                    $basicSalary = (float)($stmtSal->fetchColumn() ?: 0);

                    $payslipNumber = sprintf("SLIP/%s/%05d", $yearMonth, $counter++);

                    $stmtInsPay = $db->prepare("
                        INSERT INTO payrolls (payroll_period_id, employee_id, nik_snapshot, employee_name_snapshot, division_snapshot, team_snapshot, position_snapshot, basic_salary, total_individual_bonus, total_team_bonus, total_adjustments, total_deductions, take_home_pay, payslip_number)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)
                    ");
                    $stmtInsPay->execute([
                        $periodId, $empId, $emp['nik'], $emp['full_name'], $emp['division_name'], $emp['team_name'], $emp['position'],
                        $basicSalary, $basicSalary, $payslipNumber
                    ]);
                    $newPayrollId = (int)$db->lastInsertId();

                    $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, 'basic', 'Gaji Pokok', ?, 1, 'Gaji Pokok Periode')")
                       ->execute([$newPayrollId, $basicSalary]);
                    
                    $addedCount++;
                }
            }

            // 2. Sinkronisasi Aturan Bonus & Potongan Baru untuk SEMUA karyawan di periode ini
            $stmtCurrentPayrolls = $db->prepare("
                SELECT p.id as payroll_id, p.employee_id, e.division_id 
                FROM payrolls p
                LEFT JOIN employees e ON p.employee_id = e.id
                WHERE p.payroll_period_id = ?
            ");
            $stmtCurrentPayrolls->execute([$periodId]);
            $currentPayrolls = $stmtCurrentPayrolls->fetchAll() ?: [];

            $stmtExistingItems = $db->prepare("SELECT name, qty, id FROM payroll_items WHERE payroll_id = ? AND type = ?");
            $stmtInsItem = $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, ?, ?, 0, 0, ?)");
            $stmtUpdateRate = $db->prepare("UPDATE payroll_items SET notes = ? WHERE id = ? AND qty = 0");

            foreach ($currentPayrolls as $cp) {
                $pId = (int)$cp['payroll_id'];
                $divId = $cp['division_id'] !== null ? (int)$cp['division_id'] : null;

                // --- A. BONUS ---
                $stmtExistingItems->execute([$pId, 'individual_bonus']);
                $existingBonusRows = $stmtExistingItems->fetchAll() ?: [];
                $existingBonusMap = [];
                foreach ($existingBonusRows as $row) {
                    $existingBonusMap[$row['name']] = $row;
                }

                if ($divId !== null) {
                    $stmtBonus = $db->prepare("SELECT * FROM bonus_types WHERE (division_id = ? OR division_id IS NULL) AND status = 'active'");
                    $stmtBonus->execute([$divId]);
                } else {
                    $stmtBonus = $db->query("SELECT * FROM bonus_types WHERE division_id IS NULL AND status = 'active'");
                }
                $activeBonusTypes = $stmtBonus->fetchAll() ?: [];

                foreach ($activeBonusTypes as $bt) {
                    $bName = $bt['name'];
                    $rate = (float)$bt['rate'];
                    if ($bt['calculation_method'] === 'team' && $divId !== null) {
                        $stmtCount = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = ? AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)");
                        $stmtCount->execute([$divId]);
                        $divCount = (int)$stmtCount->fetchColumn();
                        if ($divCount > 0) {
                            $rate = $rate / $divCount;
                        }
                    }

                    if (!isset($existingBonusMap[$bName])) {
                        // Aturan baru: Sisipkan aman dengan qty = 0
                        $stmtInsItem->execute([$pId, 'individual_bonus', $bName, (string)$rate]);
                        $addedCount++;
                    } else {
                        // Update tarif jika qty masih 0 dan tarif master berubah
                        $existingItem = $existingBonusMap[$bName];
                        if ((int)$existingItem['qty'] === 0) {
                            $stmtUpdateRate->execute([(string)$rate, $existingItem['id']]);
                        }
                    }
                }

                // --- B. POTONGAN ---
                $stmtExistingItems->execute([$pId, 'deduction']);
                $existingDeductRows = $stmtExistingItems->fetchAll() ?: [];
                $existingDeductMap = [];
                foreach ($existingDeductRows as $row) {
                    $existingDeductMap[$row['name']] = $row;
                }

                if ($divId !== null) {
                    $stmtDeduct = $db->prepare("SELECT * FROM deduction_types WHERE (division_id = ? OR division_id IS NULL) AND status = 'active'");
                    $stmtDeduct->execute([$divId]);
                } else {
                    $stmtDeduct = $db->query("SELECT * FROM deduction_types WHERE division_id IS NULL AND status = 'active'");
                }
                $activeDeductTypes = $stmtDeduct->fetchAll() ?: [];

                foreach ($activeDeductTypes as $dt) {
                    $dName = $dt['name'];
                    $amount = (float)$dt['default_amount'];
                    if ($dt['calculation_method'] === 'team' && $divId !== null) {
                        $stmtCount = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = ? AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)");
                        $stmtCount->execute([$divId]);
                        $divCount = (int)$stmtCount->fetchColumn();
                        if ($divCount > 0) {
                            $amount = $amount / $divCount;
                        }
                    }

                    if (!isset($existingDeductMap[$dName])) {
                        // Aturan baru: Sisipkan aman dengan qty = 0
                        $stmtInsItem->execute([$pId, 'deduction', $dName, (string)$amount]);
                        $addedCount++;
                    } else {
                        // Update tarif jika qty masih 0 dan tarif master berubah
                        $existingItem = $existingDeductMap[$dName];
                        if ((int)$existingItem['qty'] === 0) {
                            $stmtUpdateRate->execute([(string)$amount, $existingItem['id']]);
                        }
                    }
                }
            }

            $db->commit();
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("syncPayrollRules Error: " . $e->getMessage());
        }

        return $addedCount;
    }
}

// Ensure qty column exists in payroll_items
try {
    $db->exec("ALTER TABLE payroll_items ADD COLUMN qty INTEGER DEFAULT 1");
} catch (Exception $e) {
    // Column already exists
}

$cronWebhookSecret = getOrCreateWebhookSecret($db);
$webhookUrl = BASE_URL . '/api/v1/webhook_payroll.php';

$pageTitle = 'Proses Payroll';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'regenerate_cron_secret') {
        $newSec = regenerateWebhookSecret($db);
        logAudit($user['id'], 'REGENERATE_CRON_WEBHOOK_SECRET', 'Regenerated payroll cron webhook secret token');
        setFlashMessage('success', 'Secret token webhook cronjob berhasil di-regenerate: ' . $newSec);
        header('Location: ' . BASE_URL . '/payroll.php');
        exit;
    }

    if ($action === 'trigger_cron_now') {
        $cronAction = trim($_POST['cron_action'] ?? 'auto');
        $dryRun = !empty($_POST['dry_run']);
        try {
            $cronResult = runPayrollCronWorkflow($db, [
                'action' => $cronAction,
                'dry_run' => $dryRun,
                'triggered_by' => 'Admin UI (' . $user['username'] . ')'
            ]);
            $msg = ($dryRun ? "[Simulasi/Dry-run] " : "") . "Cronjob berhasil dijalankan untuk periode " . e($cronResult['period']['name']) . ". Total THP: " . $cronResult['summary']['formatted_total_payroll'] . " (" . $cronResult['summary']['employee_count'] . " karyawan).";
            setFlashMessage('success', $msg);
            $targetPid = $cronResult['period']['id'] ?? null;
            header('Location: ' . BASE_URL . '/payroll.php' . ($targetPid ? '?period_id=' . $targetPid : ''));
            exit;
        } catch (\Throwable $e) {
            setFlashMessage('danger', 'Gagal menjalankan cronjob: ' . $e->getMessage());
            header('Location: ' . BASE_URL . '/payroll.php');
            exit;
        }
    }

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

    if ($action === 'sync_rules') {
        $periodId = (int)($_POST['period_id'] ?? 0);
        $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ? AND status IN ('draft', 'review')");
        $stmtP->execute([$periodId]);
        $period = $stmtP->fetch();

        if ($period) {
            $added = syncPayrollRules($db, $periodId, $period);
            logAudit($user['id'], 'SYNC_PAYROLL_RULES', "Synced payroll rules for period ID {$periodId}. Added {$added} items.");
            if ($added > 0) {
                setFlashMessage('success', "Berhasil menyinkronkan aturan master! {$added} aturan/item baru ditambahkan tanpa mengubah data yang telah Anda simpan.");
            } else {
                setFlashMessage('info', 'Semua aturan master bonus dan potongan sudah sinkron (tidak ada aturan baru). Data yang sudah Anda simpan tetap aman.');
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
        } else {
            // Otomatis sinkronkan aturan bonus/potongan baru tanpa mereset atau mengubah data yang sudah diisi
            if ($selectedPeriod['status'] === 'draft' || $selectedPeriod['status'] === 'review') {
                syncPayrollRules($db, $selectedPeriodId, $selectedPeriod);
            }
        }

        // Fetch payroll items for display
        $stmtPay = $db->prepare("SELECT * FROM payrolls WHERE payroll_period_id = ? ORDER BY employee_name_snapshot ASC");
        $stmtPay->execute([$selectedPeriodId]);
        $payrollItems = $stmtPay->fetchAll();
    }
}

// Fetch unit names from master bonus & deduction
$bonusUnitMap = $db->query("SELECT name, unit_name FROM bonus_types")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$deductUnitMap = $db->query("SELECT name, unit_name FROM deduction_types")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <style>
      .payroll-card {
          transition: transform 0.25s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.25s cubic-bezier(0.165, 0.84, 0.44, 1), border-color 0.25s ease;
          border: 1px solid #e9edf4 !important;
          background: #ffffff;
          border-radius: 1.1rem !important;
      }
      .payroll-card:hover {
          transform: translateY(-4px);
          box-shadow: 0 14px 28px rgba(75, 73, 172, 0.08) !important;
          border-color: #c7d2fe !important;
      }
      .avatar-circle {
          width: 44px;
          height: 44px;
          border-radius: 12px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-weight: 700;
          font-size: 0.95rem;
          flex-shrink: 0;
      }
      /* Clean number input without browser spinners */
      .qty-input::-webkit-outer-spin-button,
      .qty-input::-webkit-inner-spin-button {
          -webkit-appearance: none;
          margin: 0;
      }
      .qty-input {
          -moz-appearance: textfield;
          outline: none;
          transition: all 0.2s ease;
          border-radius: 8px;
          font-weight: 700;
      }
      .qty-input:focus {
          border-color: #4b49ac !important;
          box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
      }
      .basic-salary-input:focus {
          border-color: #4b49ac !important;
          box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
      }
      .period-pill-btn {
          transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
          border: 1px solid #e2e8f0;
          background: #ffffff;
          text-decoration: none;
          padding: 0.5rem 1rem;
          font-weight: 600;
          font-size: 0.875rem;
      }
      .period-pill-btn:hover {
          background-color: #f8fafc;
          border-color: #cbd5e1;
          transform: translateY(-1px);
      }
      .period-pill-btn.active {
          background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%) !important;
          color: #ffffff !important;
          border-color: transparent !important;
          box-shadow: 0 4px 14px rgba(75, 73, 172, 0.35) !important;
      }
      .stat-widget {
          background: #ffffff;
          border: 1px solid #edf2f7;
          border-radius: 1rem;
          padding: 1.15rem 1.25rem;
          transition: all 0.2s ease;
      }
      .stat-widget:hover {
          border-color: #e2e8f0;
          box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
      }
      .rule-item-box {
          border-radius: 10px;
          transition: background-color 0.15s ease, border-color 0.15s ease;
      }
      .rule-item-box:hover {
          filter: brightness(0.98);
      }
    </style>
    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4 rounded-4 shadow-sm border-0" role="alert">
          <div class="d-flex align-items-center">
            <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-info text-primary' ?> fs-5 me-3"></i>
            <div><?= e($flash['text']) ?></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Executive Header -->
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 mb-4">
        <div>
          <h3 class="font-weight-bold mb-1 text-dark">
            <i class="fa-solid fa-calculator text-primary me-2"></i>Proses &amp; Kalkulasi Payroll
          </h3>
          <p class="text-muted mb-0 small">Kelola periode penggajian, hitung bonus &amp; potongan real-time, dan terbitkan slip gaji.</p>
        </div>
        
        <!-- Desktop Action Buttons -->
        <div class="d-none d-md-flex align-items-center gap-2">
          <button class="btn btn-outline-primary rounded-pill font-weight-bold px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#cronWebhookModal">
            <i class="fa-solid fa-bolt me-1 text-warning"></i> Webhook &amp; Cronjob
          </button>
          <button class="btn btn-primary rounded-pill font-weight-bold px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#createPeriodModal" style="background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none;">
            <i class="fa-solid fa-calendar-plus me-1"></i> Buat Periode Baru
          </button>
        </div>
        
        <!-- Mobile Trigger Buttons -->
        <div class="d-md-none w-100 d-flex gap-2">
          <button class="btn btn-outline-primary rounded-pill btn-sm w-50 font-weight-bold" data-bs-toggle="modal" data-bs-target="#cronWebhookModal">
            <i class="fa-solid fa-bolt me-1 text-warning"></i> Webhook
          </button>
          <button class="btn btn-primary rounded-pill btn-sm w-50 font-weight-bold" data-bs-toggle="modal" data-bs-target="#createPeriodModal">
            <i class="fa-solid fa-calendar-plus me-1"></i> Buat Periode
          </button>
        </div>

        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" data-bs-toggle="modal" data-bs-target="#createPeriodModal" style="bottom: calc(75px + env(safe-area-inset-bottom, 0px)); right: 18px; width: 52px; height: 52px; z-index: 1035; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 6px 18px rgba(75, 73, 172, 0.45) !important;" aria-label="Buat Periode Baru">
          <i class="fa-solid fa-plus fs-4 text-white"></i>
        </button>
      </div>

      <!-- Unified Period Navigator & Filter Bar -->
      <div class="card shadow-sm border-0 rounded-4 mb-4" style="background: #ffffff;">
        <div class="card-body p-3">
          <div class="row g-2 align-items-center justify-content-between">
            
            <!-- Period Selector Horizontal Scroll -->
            <div class="col-12 col-lg-7">
              <div class="d-flex align-items-center gap-2 overflow-auto py-1" style="scrollbar-width: thin;">
                <span class="small font-weight-bold text-muted text-nowrap me-1">
                  <i class="fa-regular fa-calendar-check me-1"></i>Periode:
                </span>
                <?php if (empty($periods)): ?>
                  <span class="text-muted small italic">Tidak ada periode yang cocok.</span>
                <?php else: ?>
                  <?php foreach ($periods as $p): 
                    $isActive = ($selectedPeriodId === (int)$p['id']);
                    $stColor = $p['status'] === 'final' ? 'success' : ($p['status'] === 'review' ? 'warning' : 'secondary');
                  ?>
                    <a href="<?= BASE_URL ?>/payroll.php?period_id=<?= $p['id'] ?>&amp;filter_year=<?= $filterYear ?>&amp;filter_status=<?= urlencode($filterStatus) ?>" 
                       class="btn btn-sm rounded-pill text-nowrap period-pill-btn <?= $isActive ? 'active' : 'text-dark' ?>">
                      <?= e($p['name']) ?>
                      <span class="badge rounded-pill ms-1 <?= $isActive ? 'bg-white text-primary' : 'bg-' . $stColor . '-subtle text-' . $stColor ?>" style="font-size: 0.65rem;">
                        <?= strtoupper($p['status']) ?>
                      </span>
                    </a>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
            </div>

            <!-- Quick Filter Form -->
            <div class="col-12 col-lg-5">
              <form action="<?= BASE_URL ?>/payroll.php" method="GET" class="d-flex align-items-center justify-content-lg-end gap-2">
                <input type="hidden" name="period_id" value="<?= $selectedPeriodId ?>">
                
                <select name="filter_year" class="form-select form-select-sm rounded-pill font-weight-bold text-muted border-1" style="min-width: 120px;" onchange="this.form.submit()">
                  <option value="">Tahun (Semua)</option>
                  <?php foreach ($years as $yr): ?>
                    <option value="<?= $yr ?>" <?= $filterYear === (int)$yr ? 'selected' : '' ?>><?= $yr ?></option>
                  <?php endforeach; ?>
                </select>
                
                <select name="filter_status" class="form-select form-select-sm rounded-pill font-weight-bold text-muted border-1" style="min-width: 120px;" onchange="this.form.submit()">
                  <option value="">Status (Semua)</option>
                  <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                  <option value="review" <?= $filterStatus === 'review' ? 'selected' : '' ?>>Review</option>
                  <option value="final" <?= $filterStatus === 'final' ? 'selected' : '' ?>>Final</option>
                </select>

                <?php if ($filterYear || $filterStatus): ?>
                  <a href="<?= BASE_URL ?>/payroll.php" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center text-muted" title="Reset Filter" style="width: 32px; height: 32px;">
                    <i class="fa-solid fa-xmark"></i>
                  </a>
                <?php endif; ?>
              </form>
            </div>

          </div>
        </div>
      </div>

      <!-- Selected Period Detail View & Actions -->
      <?php if ($selectedPeriod): ?>
        <?php
          // Compute summary totals for executive stat cards
          $totalEmployees = count($payrollItems ?? []);
          $totalBasicSalary = 0;
          $totalBonusesAll = 0;
          $totalDeductionsAll = 0;
          $grandTotal = 0;
          if (!empty($payrollItems)) {
              foreach ($payrollItems as $pItem) {
                  $totalBasicSalary += (float)$pItem['basic_salary'];
                  $totalBonusesAll += (float)$pItem['total_individual_bonus'] + (float)$pItem['total_team_bonus'];
                  $totalDeductionsAll += (float)$pItem['total_deductions'];
                  $grandTotal += (float)$pItem['take_home_pay'];
              }
          }
        ?>

        <!-- Executive Metrics Grid -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-lg-3">
            <div class="stat-widget d-flex align-items-center gap-3">
              <div class="avatar-circle" style="background-color: #ede9fe; color: #6366f1;">
                <i class="fa-solid fa-users fs-5"></i>
              </div>
              <div class="overflow-hidden">
                <small class="text-muted font-weight-bold d-block text-truncate" style="font-size: 0.75rem;">TOTAL KARYAWAN</small>
                <span class="fs-5 font-weight-bold text-dark"><?= $totalEmployees ?> <span class="small font-weight-normal text-muted" style="font-size: 0.75rem;">Orang</span></span>
              </div>
            </div>
          </div>
          
          <div class="col-6 col-lg-3">
            <div class="stat-widget d-flex align-items-center gap-3">
              <div class="avatar-circle" style="background-color: #e0f2fe; color: #0284c7;">
                <i class="fa-solid fa-money-bill-wave fs-5"></i>
              </div>
              <div class="overflow-hidden">
                <small class="text-muted font-weight-bold d-block text-truncate" style="font-size: 0.75rem;">TOTAL GAJI POKOK</small>
                <span class="fs-5 font-weight-bold text-dark"><?= formatRupiah($totalBasicSalary) ?></span>
              </div>
            </div>
          </div>

          <div class="col-6 col-lg-3">
            <div class="stat-widget d-flex align-items-center gap-3">
              <div class="avatar-circle" style="background-color: #fef3c7; color: #d97706;">
                <i class="fa-solid fa-scale-balanced fs-5"></i>
              </div>
              <div class="overflow-hidden">
                <small class="text-muted font-weight-bold d-block text-truncate" style="font-size: 0.75rem;">BONUS / POTONGAN</small>
                <div class="d-flex align-items-center gap-1 font-weight-bold">
                  <span class="text-success small">+<?= formatRupiah($totalBonusesAll) ?></span>
                  <span class="text-danger small ms-1">-<?= formatRupiah($totalDeductionsAll) ?></span>
                </div>
              </div>
            </div>
          </div>

          <div class="col-6 col-lg-3">
            <div class="stat-widget d-flex align-items-center gap-3" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.05) 100%); border-color: rgba(16, 185, 129, 0.3);">
              <div class="avatar-circle bg-success text-white">
                <i class="fa-solid fa-wallet fs-5"></i>
              </div>
              <div class="overflow-hidden">
                <small class="text-success font-weight-bold d-block text-truncate" style="font-size: 0.75rem;">TOTAL TAKE HOME PAY</small>
                <span class="fs-5 font-weight-bold text-success"><?= formatRupiah($grandTotal) ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Period Action & Details Container -->
        <div class="card shadow-sm border-0 rounded-4 mb-4">
          <div class="card-body p-4">
            
            <!-- Period Header Banner -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3 border-bottom pb-3 mb-4">
              <div>
                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                  <h4 class="font-weight-bold text-dark mb-0"><?= e($selectedPeriod['name']) ?></h4>
                  <?php 
                    $badgeStyle = 'bg-secondary';
                    if ($selectedPeriod['status'] === 'final') $badgeStyle = 'bg-success';
                    elseif ($selectedPeriod['status'] === 'review') $badgeStyle = 'bg-warning text-dark';
                  ?>
                  <span class="badge <?= $badgeStyle ?> rounded-pill px-3 py-1 font-weight-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                    STATUS: <?= strtoupper($selectedPeriod['status']) ?>
                  </span>
                </div>
                
                <div class="d-flex flex-wrap align-items-center gap-2 gap-md-3 text-muted small">
                  <span><i class="fa-regular fa-calendar me-1 text-primary"></i> Cutoff: <strong><?= formatTanggalIndo($selectedPeriod['start_date']) ?></strong> s/d <strong><?= formatTanggalIndo($selectedPeriod['end_date']) ?></strong></span>
                  <span class="d-none d-md-inline text-muted opacity-50">•</span>
                  <span><i class="fa-regular fa-credit-card me-1 text-success"></i> Tgl Pembayaran: <strong><?= formatTanggalIndo($selectedPeriod['payment_date']) ?></strong></span>
                </div>
              </div>

              <!-- Period Management Actions -->
              <div class="d-flex align-items-center gap-2 flex-wrap w-100 w-md-auto justify-content-start justify-content-md-end">
                <?php if ($selectedPeriod['status'] === 'draft'): ?>
                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="calculate_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-warning rounded-pill font-weight-bold px-3 shadow-sm">
                      <i class="fa-solid fa-calculator me-1"></i> Mulai Proses Payroll
                    </button>
                  </form>
                <?php elseif ($selectedPeriod['status'] === 'review'): ?>
                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="sync_rules">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-primary rounded-pill font-weight-bold px-3 shadow-sm" title="Tarik karyawan baru dan aturan bonus/potongan baru tanpa merusak data tersimpan">
                      <i class="fa-solid fa-arrows-rotate me-1"></i> Sinkronkan Aturan
                    </button>
                  </form>

                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="finalize_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success rounded-pill font-weight-bold px-3 shadow-sm" onclick="return confirm('PERHATIAN: Setelah difinalisasi, nominal payroll akan dikunci permanen dan slip gaji siap didistribusikan. Lanjutkan?')">
                      <i class="fa-solid fa-lock me-1"></i> Finalisasi &amp; Kunci Payroll
                    </button>
                  </form>

                  <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="calculate_payroll">
                    <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-light text-danger rounded-pill font-weight-bold px-2.5 border" title="Reset seluruh data ke nilai default awal" onclick="return confirm('PERINGATAN: Mereset akan MENGHAPUS SEMUA data yang sudah Anda sesuaikan. Apakah Anda yakin?')">
                      <i class="fa-solid fa-rotate"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Payroll Breakdown Grid of Cards -->
            <?php if (empty($payrollItems)): ?>
              <div class="text-center py-5 text-muted col-12">
                <div class="avatar-circle mx-auto mb-3 bg-warning-subtle text-warning" style="width: 64px; height: 64px;">
                  <i class="fa-solid fa-calculator fs-2"></i>
                </div>
                <h5 class="font-weight-bold text-dark">Data Payroll Belum Diinisialisasi</h5>
                <p class="small text-muted mb-3">Klik tombol di bawah ini untuk memulai kalkulasi dan menginisialisasi gaji semua karyawan aktif.</p>
                <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="calculate_payroll">
                  <input type="hidden" name="period_id" value="<?= $selectedPeriod['id'] ?>">
                  <button type="submit" class="btn btn-warning rounded-pill font-weight-bold px-4 shadow-sm">
                    <i class="fa-solid fa-play me-1"></i> Mulai Proses Payroll Sekarang
                  </button>
                </form>
              </div>
            <?php else: ?>
              <div class="row g-4">
                <?php foreach ($payrollItems as $item): 
                  $nameParts = explode(' ', trim($item['employee_name_snapshot']));
                  $initials = strtoupper(substr($nameParts[0], 0, 1) . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
                ?>
                  <div class="col-12 col-md-6 col-xl-4">
                    <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="payroll-card-form h-100">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="edit_employee_payroll">
                      <input type="hidden" name="payroll_id" value="<?= $item['id'] ?>">
                      
                      <div class="card shadow-sm border-0 rounded-4 h-100 payroll-card">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                          
                          <!-- Top Info -->
                          <div>
                            <!-- Employee Header Card -->
                            <div class="d-flex align-items-center gap-3 mb-3">
                              <div class="avatar-circle" style="background: linear-gradient(135deg, rgba(75, 73, 172, 0.12) 0%, rgba(99, 102, 241, 0.18) 100%); color: #4B49AC;">
                                <?= e($initials) ?>
                              </div>
                              <div class="overflow-hidden flex-grow-1">
                                <h5 class="font-weight-bold text-dark mb-0 text-truncate" title="<?= e($item['employee_name_snapshot']) ?>">
                                  <?= e($item['employee_name_snapshot']) ?>
                                </h5>
                                <div class="d-flex align-items-center gap-1 text-muted small text-truncate">
                                  <span class="badge bg-light text-muted border px-1.5 py-0.5 rounded" style="font-size: 0.7rem; font-weight: 600;">
                                    <?= e($item['nik_snapshot'] ?: 'EMP') ?>
                                  </span>
                                  <span><?= e($item['position_snapshot'] ?: '-') ?></span>
                                  <span>•</span>
                                  <span class="text-truncate"><?= e($item['division_snapshot'] ?: '-') ?></span>
                                </div>
                              </div>
                            </div>

                            <hr class="my-3 opacity-25">

                            <!-- Gaji Pokok -->
                            <div class="mb-3">
                              <label class="text-muted small font-weight-bold d-block mb-1">
                                <i class="fa-solid fa-coins me-1 text-primary"></i> Gaji Pokok
                              </label>
                              <?php if ($selectedPeriod['status'] === 'review'): ?>
                                <div class="input-group">
                                  <span class="input-group-text bg-light text-dark font-weight-bold border-end-0 py-1" style="font-size: 0.9rem;">Rp</span>
                                  <input type="number" name="basic_salary" class="form-control font-weight-bold text-dark basic-salary-input border-start-0 ps-1" style="font-size: 1.15rem;" value="<?= (int)$item['basic_salary'] ?>" data-payroll-id="<?= $item['id'] ?>" required>
                                </div>
                              <?php else: ?>
                                <h4 class="font-weight-bold text-dark mb-0"><?= formatRupiah($item['basic_salary']) ?></h4>
                              <?php endif; ?>
                            </div>

                            <!-- Bonuses Section -->
                            <?php 
                              $stmtB = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = 'individual_bonus' ORDER BY id ASC");
                              $stmtB->execute([$item['id']]);
                              $empBonuses = $stmtB->fetchAll();
                            ?>
                            <div class="mb-3">
                              <div class="d-flex justify-content-between align-items-center mb-1.5">
                                <span class="small font-weight-bold text-success">
                                  <i class="fa-solid fa-plus-circle me-1"></i> Bonus / Tambahan
                                </span>
                              </div>
                              
                              <?php if (empty($empBonuses)): ?>
                                <span class="text-muted small italic d-block py-1">Tidak ada komponen bonus.</span>
                              <?php else: ?>
                                <div class="d-flex flex-column gap-1.5">
                                  <?php foreach ($empBonuses as $eb): 
                                      $bUnit = !empty($bonusUnitMap[$eb['name']]) ? strtolower($bonusUnitMap[$eb['name']]) : 'unit';
                                      $isTeam = isTeamItem($db, $eb['name'], $eb['type']);
                                  ?>
                                    <?php if ($selectedPeriod['status'] === 'review'): ?>
                                      <div class="p-2.5 rounded-3 rule-item-box transition-all" style="background-color: #f6ffed; border: 1px solid #d9f7be;">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                          <div class="me-2 overflow-hidden">
                                            <div class="font-weight-bold text-truncate d-flex align-items-center flex-wrap gap-1" style="color: #237804; font-size: 0.85rem;">
                                              <span><?= e($eb['name']) ?></span>
                                              <?php if ($isTeam): ?>
                                                <span class="badge text-white px-1.5 py-0.5 rounded-pill" style="font-size: 0.65rem; background-color: #389e0d;">Tim</span>
                                              <?php endif; ?>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.75rem;"><?= formatRupiah((float)$eb['notes']) ?> <span class="opacity-75">/ <?= e($bUnit) ?></span></small>
                                          </div>
                                          <div class="text-end flex-shrink-0">
                                            <span class="badge bg-white shadow-xs fw-bold px-2 py-1 border" id="subtotalItem<?= $eb['id'] ?>" style="font-size: 0.8rem; color: #237804; border-color: #b7eb8f !important;">
                                              +<?= formatRupiah((float)$eb['notes'] * (float)$eb['qty']) ?>
                                            </span>
                                          </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center pt-1.5 border-top" style="border-color: rgba(183, 235, 143, 0.45) !important; border-top-style: dashed !important;">
                                          <span class="small text-muted" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-calculator me-1 opacity-50"></i> Jumlah (<?= e($bUnit) ?>):
                                          </span>
                                          <div class="input-group input-group-sm flex-nowrap" style="width: 124px;">
                                            <button type="button" class="btn btn-outline-success px-2 py-0 border" style="border-color: #b7eb8f; background: #ffffff;" onclick="stepQty('qtyInput<?= $eb['id'] ?>', -1, <?= $item['id'] ?>)" title="Kurangi 1">
                                              <i class="fa-solid fa-minus" style="font-size: 0.7rem;"></i>
                                            </button>
                                            <input type="number" id="qtyInput<?= $eb['id'] ?>" name="qty[<?= $eb['id'] ?>]" class="form-control text-center qty-input bonus-qty-input fw-bold px-1" style="height: 30px; border-color: #b7eb8f; color: #237804; background: #ffffff;" value="<?= (float)$eb['qty'] ?>" data-rate="<?= (float)$eb['notes'] ?>" data-item-id="<?= $eb['id'] ?>" data-payroll-id="<?= $item['id'] ?>" min="0" required>
                                            <button type="button" class="btn btn-outline-success px-2 py-0 border" style="border-color: #b7eb8f; background: #ffffff;" onclick="stepQty('qtyInput<?= $eb['id'] ?>', 1, <?= $item['id'] ?>)" title="Tambah 1">
                                              <i class="fa-solid fa-plus" style="font-size: 0.7rem;"></i>
                                            </button>
                                          </div>
                                        </div>
                                      </div>
                                    <?php else: ?>
                                      <?php if ($eb['qty'] > 0): ?>
                                        <div class="p-2 rule-item-box bg-light border-0 d-flex justify-content-between align-items-center" style="background-color: #f6ffed80 !important; border: 1px dashed #d9f7be !important;">
                                          <div>
                                            <span class="font-weight-bold" style="color: #237804; font-size: 0.85rem;"><?= e($eb['name']) ?></span>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;"><?= formatRupiah((float)$eb['notes']) ?> × <?= $eb['qty'] ?> <?= e($bUnit) ?></small>
                                          </div>
                                          <span class="font-weight-bold text-success" style="font-size: 0.9rem;">+<?= formatRupiah($eb['amount']) ?></span>
                                        </div>
                                      <?php endif; ?>
                                    <?php endif; ?>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </div>

                            <!-- Deductions Section -->
                            <?php 
                              $stmtD = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = 'deduction' ORDER BY id ASC");
                              $stmtD->execute([$item['id']]);
                              $empDeductions = $stmtD->fetchAll();
                            ?>
                            <div class="mb-3">
                              <div class="d-flex justify-content-between align-items-center mb-1.5">
                                <span class="small font-weight-bold text-danger">
                                  <i class="fa-solid fa-minus-circle me-1"></i> Potongan
                                </span>
                              </div>

                              <?php if (empty($empDeductions)): ?>
                                <span class="text-muted small italic d-block py-1">Tidak ada komponen potongan.</span>
                              <?php else: ?>
                                <div class="d-flex flex-column gap-1.5">
                                  <?php foreach ($empDeductions as $ed): 
                                      $dUnit = !empty($deductUnitMap[$ed['name']]) ? strtolower($deductUnitMap[$ed['name']]) : 'unit';
                                      $isTeam = isTeamItem($db, $ed['name'], $ed['type']);
                                  ?>
                                    <?php if ($selectedPeriod['status'] === 'review'): ?>
                                      <div class="p-2.5 rounded-3 rule-item-box transition-all" style="background-color: #fff2f0; border: 1px solid #ffccc7;">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                          <div class="me-2 overflow-hidden">
                                            <div class="font-weight-bold text-truncate d-flex align-items-center flex-wrap gap-1" style="color: #a8071a; font-size: 0.85rem;">
                                              <span><?= e($ed['name']) ?></span>
                                              <?php if ($isTeam): ?>
                                                <span class="badge text-white px-1.5 py-0.5 rounded-pill" style="font-size: 0.65rem; background-color: #cf1322;">Tim</span>
                                              <?php endif; ?>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.75rem;"><?= formatRupiah((float)$ed['notes']) ?> <span class="opacity-75">/ <?= e($dUnit) ?></span></small>
                                          </div>
                                          <div class="text-end flex-shrink-0">
                                            <span class="badge bg-white shadow-xs fw-bold px-2 py-1 border" id="subtotalItem<?= $ed['id'] ?>" style="font-size: 0.8rem; color: #cf1322; border-color: #ffa39e !important;">
                                              -<?= formatRupiah((float)$ed['notes'] * (float)$ed['qty']) ?>
                                            </span>
                                          </div>
                                        </div>

                                        <div class="d-flex justify-content-between align-items-center pt-1.5 border-top" style="border-color: rgba(255, 163, 158, 0.45) !important; border-top-style: dashed !important;">
                                          <span class="small text-muted" style="font-size: 0.75rem;">
                                            <i class="fa-solid fa-calculator me-1 opacity-50"></i> Jumlah (<?= e($dUnit) ?>):
                                          </span>
                                          <div class="input-group input-group-sm flex-nowrap" style="width: 124px;">
                                            <button type="button" class="btn btn-outline-danger px-2 py-0 border" style="border-color: #ffa39e; background: #ffffff;" onclick="stepQty('qtyInput<?= $ed['id'] ?>', -1, <?= $item['id'] ?>)" title="Kurangi 1">
                                              <i class="fa-solid fa-minus" style="font-size: 0.7rem;"></i>
                                            </button>
                                            <input type="number" id="qtyInput<?= $ed['id'] ?>" name="qty[<?= $ed['id'] ?>]" class="form-control text-center qty-input deduction-qty-input fw-bold px-1" style="height: 30px; border-color: #ffa39e; color: #a8071a; background: #ffffff;" value="<?= (float)$ed['qty'] ?>" data-rate="<?= (float)$ed['notes'] ?>" data-item-id="<?= $ed['id'] ?>" data-payroll-id="<?= $item['id'] ?>" min="0" required>
                                            <button type="button" class="btn btn-outline-danger px-2 py-0 border" style="border-color: #ffa39e; background: #ffffff;" onclick="stepQty('qtyInput<?= $ed['id'] ?>', 1, <?= $item['id'] ?>)" title="Tambah 1">
                                              <i class="fa-solid fa-plus" style="font-size: 0.7rem;"></i>
                                            </button>
                                          </div>
                                        </div>
                                      </div>
                                    <?php else: ?>
                                      <?php if ($ed['qty'] > 0): ?>
                                        <div class="p-2 rule-item-box bg-light border-0 d-flex justify-content-between align-items-center" style="background-color: #fff2f080 !important; border: 1px dashed #ffccc7 !important;">
                                          <div>
                                            <span class="font-weight-bold" style="color: #a8071a; font-size: 0.85rem;"><?= e($ed['name']) ?></span>
                                            <small class="text-muted d-block" style="font-size: 0.75rem;"><?= formatRupiah((float)$ed['notes']) ?> × <?= $ed['qty'] ?> <?= e($dUnit) ?></small>
                                          </div>
                                          <span class="font-weight-bold text-danger" style="font-size: 0.9rem;">-<?= formatRupiah($ed['amount']) ?></span>
                                        </div>
                                      <?php endif; ?>
                                    <?php endif; ?>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </div>

                          </div>
                          
                          <!-- Bottom Totals & Actions -->
                          <div>
                            <hr class="my-3 opacity-25">
                            
                            <!-- Take Home Pay Display -->
                            <div class="p-3 rounded-3 mb-3 d-flex justify-content-between align-items-center" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.08) 0%, rgba(5, 150, 105, 0.04) 100%); border: 1px solid rgba(16, 185, 129, 0.2);">
                              <div>
                                <small class="text-muted font-weight-bold d-block" style="font-size: 0.75rem;">TAKE HOME PAY</small>
                                <span class="font-weight-bold text-success fs-5 thp-display" id="thpDisplay<?= $item['id'] ?>">
                                  <?= formatRupiah($item['take_home_pay']) ?>
                                </span>
                              </div>

                              <?php if ($selectedPeriod['status'] === 'final'): ?>
                                <div>
                                  <?php if (!empty($item['transfer_receipt'])): ?>
                                    <span class="badge bg-success text-white rounded-pill px-2.5 py-1" style="font-size: 0.7rem;">
                                      <i class="fa-solid fa-check me-1"></i> Lunas
                                    </span>
                                  <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-muted rounded-pill px-2.5 py-1" style="font-size: 0.7rem;">
                                      Belum Transfer
                                    </span>
                                  <?php endif; ?>
                                </div>
                              <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex justify-content-end align-items-center gap-2" onclick="event.stopPropagation();">
                              <?php if ($selectedPeriod['status'] === 'final'): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill font-weight-bold px-3" onclick="event.stopPropagation(); openReceiptModal(<?= $item['id'] ?>, '<?= e(addslashes($item['employee_name_snapshot'])) ?>', '<?= e(addslashes($item['transfer_receipt'] ?? '')) ?>', <?= $selectedPeriod['id'] ?>)">
                                  <i class="fa-solid fa-receipt me-1"></i> Bukti Transfer
                                </button>
                              <?php endif; ?>
                              
                              <?php if ($selectedPeriod['status'] === 'review'): ?>
                                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3 font-weight-bold shadow-sm">
                                  <i class="fa-solid fa-floppy-disk me-1"></i> Simpan
                                </button>
                              <?php endif; ?>

                              <a href="<?= BASE_URL ?>/payslip-print.php?id=<?= $item['id'] ?>" target="_blank" class="btn btn-sm btn-light border rounded-pill px-3 font-weight-bold d-flex align-items-center text-muted">
                                <i class="fa-solid fa-print me-1"></i> Slip
                              </a>
                            </div>

                          </div>
                        </div>
                      </div>
                    </form>
                  </div>
                <?php endforeach; ?>
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

    <!-- Modal Webhook & Cronjob Payroll -->
    <div class="modal fade" id="cronWebhookModal" tabindex="-1" aria-labelledby="cronWebhookModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
          <div class="modal-header border-bottom pb-3" style="background: linear-gradient(135deg, rgba(75, 73, 172, 0.08) 0%, rgba(99, 102, 241, 0.04) 100%);">
            <div>
              <h5 class="modal-title font-weight-bold d-flex align-items-center text-primary" id="cronWebhookModalLabel">
                <i class="fa-solid fa-bolt me-2"></i> Webhook &amp; Cronjob Otomasi Payroll
              </h5>
              <small class="text-muted">Jadwalkan kalkulasi, sinkronisasi aturan &amp; pembuatan periode gaji secara otomatis</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            
            <!-- Webhook URL Box -->
            <div class="mb-4">
              <label class="form-label font-weight-bold text-dark mb-1">
                <i class="fa-solid fa-link me-1 text-primary"></i> Webhook Endpoint URL
              </label>
              <div class="input-group">
                <input type="text" class="form-control font-monospace text-muted bg-light" id="webhookUrlInput" value="<?= e($webhookUrl) ?>" readonly>
                <button class="btn btn-outline-primary" type="button" onclick="copyToClipboard('webhookUrlInput', this)">
                  <i class="fa-regular fa-copy me-1"></i> Salin URL
                </button>
              </div>
              <small class="text-muted">Menerima request HTTP POST atau GET dari server eksternal, aaPanel Cron, atau cURL.</small>
            </div>

            <!-- Webhook Secret Key Box -->
            <div class="mb-4">
              <label class="form-label font-weight-bold text-dark mb-1">
                <i class="fa-solid fa-key me-1 text-warning"></i> Webhook Secret Token (Header: <code>X-Webhook-Secret</code>)
              </label>
              <div class="input-group mb-2">
                <input type="password" class="form-control font-monospace" id="webhookSecretInput" value="<?= e($cronWebhookSecret) ?>" readonly>
                <button class="btn btn-outline-secondary" type="button" onclick="toggleSecretVisibility()">
                  <i class="fa-regular fa-eye" id="toggleSecretIcon"></i>
                </button>
                <button class="btn btn-outline-primary" type="button" onclick="copyToClipboard('webhookSecretInput', this)">
                  <i class="fa-regular fa-copy me-1"></i> Salin Token
                </button>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted">Simpan secret token ini dengan aman. Hanya request dengan token ini yang diizinkan.</small>
                <form action="<?= BASE_URL ?>/payroll.php" method="POST" class="d-inline" onsubmit="return confirm('PENTING: Menghasilkan token baru akan membatalkan token lama. Lanjutkan?');">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="regenerate_cron_secret">
                  <button type="submit" class="btn btn-link btn-sm text-danger p-0 text-decoration-none font-weight-bold">
                    <i class="fa-solid fa-arrows-rotate me-1"></i> Buat Ulang Secret Token
                  </button>
                </form>
              </div>
            </div>

            <!-- Panduan Penjadwalan (Tabs/Pills) -->
            <div class="card border bg-light rounded-3 mb-4">
              <div class="card-body p-3">
                <h6 class="font-weight-bold text-dark mb-2">
                  <i class="fa-solid fa-terminal me-1 text-success"></i> Contoh Penjadwalan di Server / aaPanel
                </h6>

                <div class="mb-3">
                  <span class="badge bg-primary text-white mb-1">Opsi 1: aaPanel Cron / Linux Crontab (via cURL Webhook)</span>
                  <div class="position-relative mt-1">
                    <pre class="bg-dark text-light p-2.5 rounded-3 font-monospace small mb-1" id="crontabCurlCode">0 1 25 * * curl -s -X POST -H "X-Webhook-Secret: <?= e($cronWebhookSecret) ?>" -H "Content-Type: application/json" -d '{"action":"auto"}' <?= e($webhookUrl) ?> &gt; /dev/null 2&gt;&amp;1</pre>
                    <button class="btn btn-sm btn-secondary position-absolute top-0 end-0 m-1.5 py-0 px-2" onclick="copyText('crontabCurlCode', this)">Salin</button>
                  </div>
                  <small class="text-muted">Dijalankan tiap tanggal 25 jam 01:00 dini hari secara otomatis.</small>
                </div>

                <div>
                  <span class="badge bg-secondary text-white mb-1">Opsi 2: Eksekusi Langsung via CLI Server (Tanpa HTTP)</span>
                  <div class="position-relative mt-1">
                    <pre class="bg-dark text-light p-2.5 rounded-3 font-monospace small mb-1" id="crontabCliCode">0 1 25 * * /usr/bin/php <?= e(ROOT_PATH) ?>/bin/cron_payroll.php &gt;&gt; <?= e(STORAGE_PATH) ?>/cron.log 2&gt;&amp;1</pre>
                    <button class="btn btn-sm btn-secondary position-absolute top-0 end-0 m-1.5 py-0 px-2" onclick="copyText('crontabCliCode', this)">Salin</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Test Run Manual dari Web UI -->
            <div class="card border-primary border-opacity-25 rounded-3" style="background: rgba(75, 73, 172, 0.02);">
              <div class="card-body p-3">
                <h6 class="font-weight-bold text-primary mb-2">
                  <i class="fa-solid fa-play me-1"></i> Uji Coba Eksekusi Cronjob Sekarang
                </h6>
                <form action="<?= BASE_URL ?>/payroll.php" method="POST">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="trigger_cron_now">

                  <div class="row g-2 align-items-center mb-3">
                    <div class="col-md-7">
                      <label class="form-label small text-muted mb-1">Pilih Tindakan (Action):</label>
                      <select name="cron_action" class="form-select form-control form-control-sm">
                        <option value="auto">auto — Buat Periode (jika belum ada) + Sinkron Aturan + Kalkulasi (Direkomendasikan)</option>
                        <option value="sync_rules">sync_rules — Hanya Sinkronkan Karyawan &amp; Aturan Master</option>
                        <option value="recalculate">recalculate — Hanya Kalkulasi Ulang Total Gaji (THP)</option>
                      </select>
                    </div>
                    <div class="col-md-5 pt-md-3">
                      <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="dry_run" value="1" id="dryRunCheck" checked>
                        <label class="form-check-label small" for="dryRunCheck">
                          <strong>Dry Run (Simulasi Aman)</strong><br>
                          <span class="text-muted" style="font-size: 0.75rem;">Cek hasil kalkulasi tanpa menyimpan</span>
                        </label>
                      </div>
                    </div>
                  </div>

                  <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill btn-sm px-4 font-weight-bold">
                      <i class="fa-solid fa-bolt me-1"></i> Jalankan Eksekusi
                    </button>
                  </div>
                </form>
              </div>
            </div>

          </div>
          <div class="modal-footer border-top py-2">
            <button type="button" class="btn btn-secondary rounded-pill btn-sm" data-bs-dismiss="modal">Tutup</button>
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

      function stepQty(inputId, delta, payrollId) {
          const input = document.getElementById(inputId);
          if (!input) return;
          let val = parseFloat(input.value) || 0;
          val = Math.max(0, val + delta);
          input.value = val;
          input.dispatchEvent(new Event('input', { bubbles: true }));
      }

      function formatRupiah(number) {
          return new Intl.NumberFormat('id-ID', {
              style: 'currency',
              currency: 'IDR',
              minimumFractionDigits: 0,
              maximumFractionDigits: 0
          }).format(number);
      }

      document.addEventListener('DOMContentLoaded', function() {
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
                  const itemSubtotal = qty * rate;
                  totalBonus += itemSubtotal;
                  
                  const itemId = input.getAttribute('data-item-id');
                  const subtotalEl = document.getElementById(`subtotalItem${itemId}`);
                  if (subtotalEl) {
                      subtotalEl.textContent = '+' + formatRupiah(itemSubtotal);
                  }
              });

              let totalDeductions = 0;
              const deductionInputs = document.querySelectorAll(`.deduction-qty-input[data-payroll-id="${payrollId}"]`);
              deductionInputs.forEach(input => {
                  const qty = parseFloat(input.value) || 0;
                  const rate = parseFloat(input.getAttribute('data-rate')) || 0;
                  const itemSubtotal = qty * rate;
                  totalDeductions += itemSubtotal;

                  const itemId = input.getAttribute('data-item-id');
                  const subtotalEl = document.getElementById(`subtotalItem${itemId}`);
                  if (subtotalEl) {
                      subtotalEl.textContent = '-' + formatRupiah(itemSubtotal);
                  }
              });

              const takeHomePay = basicSalary + totalBonus - totalDeductions;

              const display = document.getElementById(`thpDisplay${payrollId}`);
              if (display) {
                  display.textContent = formatRupiah(takeHomePay);
              }
          }
      });

      function copyToClipboard(elementId, btn) {
          const input = document.getElementById(elementId);
          if (!input) return;
          const origType = input.type;
          if (origType === 'password') input.type = 'text';
          input.select();
          input.setSelectionRange(0, 99999);
          navigator.clipboard.writeText(input.value).then(() => {
              const origHtml = btn.innerHTML;
              btn.innerHTML = '<i class="fa-solid fa-check text-success me-1"></i> Tersalin!';
              btn.classList.add('btn-success');
              btn.classList.remove('btn-outline-primary');
              setTimeout(() => {
                  btn.innerHTML = origHtml;
                  btn.classList.remove('btn-success');
                  btn.classList.add('btn-outline-primary');
              }, 2000);
          });
          if (origType === 'password') input.type = 'password';
      }

      function copyText(elementId, btn) {
          const el = document.getElementById(elementId);
          if (!el) return;
          navigator.clipboard.writeText(el.innerText || el.textContent).then(() => {
              const origHtml = btn.innerHTML;
              btn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Tersalin';
              btn.classList.add('btn-success');
              setTimeout(() => {
                  btn.innerHTML = origHtml;
                  btn.classList.remove('btn-success');
              }, 2000);
          });
      }

      function toggleSecretVisibility() {
          const input = document.getElementById('webhookSecretInput');
          const icon = document.getElementById('toggleSecretIcon');
          if (input.type === 'password') {
              input.type = 'text';
              icon.classList.remove('fa-eye');
              icon.classList.add('fa-eye-slash');
          } else {
              input.type = 'password';
              icon.classList.remove('fa-eye-slash');
              icon.classList.add('fa-eye');
          }
      }
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
