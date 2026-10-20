<?php
// app/Helpers/payroll_service.php — Centralized business logic for payroll calculations and proposals

require_once __DIR__ . '/../Config/app.php';
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Ensure payroll records exist for a period (auto-initializes if missing and period is draft/review).
 */
function ensurePayrollPeriodReady(PDO $db, int $periodId): array {
    $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmtP->execute([$periodId]);
    $period = $stmtP->fetch();

    if (!$period) {
        throw new InvalidArgumentException("Periode payroll ID {$periodId} tidak ditemukan.");
    }

    // Check if payrolls exist
    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM payrolls WHERE payroll_period_id = ?");
    $stmtCheck->execute([$periodId]);
    $hasPayrolls = (int)$stmtCheck->fetchColumn() > 0;

    if (!$hasPayrolls && in_array($period['status'], ['draft', 'review'])) {
        // Initialize payrolls for all active employees
        $employees = $db->query("
            SELECT e.*, d.name as division_name, t.name as team_name
            FROM employees e
            LEFT JOIN divisions d ON e.division_id = d.id
            LEFT JOIN teams t ON e.team_id = t.id
            LEFT JOIN users u ON e.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE e.status = 'active' AND (r.slug IS NULL OR r.slug != 'super_admin')
        ")->fetchAll();

        $yearMonth = date('Y/m', strtotime($period['end_date']));
        $counter = 1;

        $inTrans = $db->inTransaction();
        if (!$inTrans) {
            $db->beginTransaction();
        }
        try {
            foreach ($employees as $emp) {
                $empId = (int)$emp['id'];
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
            }
            if (!$inTrans) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTrans && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    return $period;
}

/**
 * Compute the base hash of an employee's payroll state to detect concurrency/stale proposals.
 */
function computePayrollStateHash(int $payrollId, float $takeHomePay, float $totalBonus, float $totalDeductions, float $itemAmount, int $itemQty): string {
    $raw = sprintf("pid:%d:thp:%.2f:bonus:%.2f:deduct:%.2f:item_amt:%.2f:item_qty:%d", $payrollId, $takeHomePay, $totalBonus, $totalDeductions, $itemAmount, $itemQty);
    return hash('sha256', $raw);
}

/**
 * Calculate preview of a proposed deduction or bonus change for one employee in one period.
 * Uses the exact payroll calculation model without divergence.
 */
function calculateEmployeePayrollPreview(
    PDO $db, 
    int $periodId, 
    int $employeeId, 
    string $changeType, 
    string $itemName, 
    float $rate, 
    int $qty, 
    string $reason
): array {
    // 1. Fetch period directly without mutating DB
    $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmtP->execute([$periodId]);
    $period = $stmtP->fetch();

    if (!$period) {
        throw new InvalidArgumentException("Periode payroll ID {$periodId} tidak ditemukan.");
    }

    // Validate period status
    if (!in_array($period['status'], ['draft', 'review'])) {
        throw new DomainException("Periode payroll '{$period['name']}' berstatus '{$period['status']}' dan sudah terkunci. Perubahan tidak dapat diajukan.");
    }

    // Validate employee
    $stmtEmp = $db->prepare("
        SELECT e.*, d.name as division_name, t.name as team_name
        FROM employees e
        LEFT JOIN divisions d ON e.division_id = d.id
        LEFT JOIN teams t ON e.team_id = t.id
        WHERE e.id = ? AND e.status = 'active'
    ");
    $stmtEmp->execute([$employeeId]);
    $emp = $stmtEmp->fetch();
    if (!$emp) {
        throw new InvalidArgumentException("Karyawan aktif dengan ID {$employeeId} tidak ditemukan.");
    }

    // Validate change type
    if (!in_array($changeType, ['deduction', 'individual_bonus'])) {
        throw new InvalidArgumentException("Tipe perubahan harus 'deduction' atau 'individual_bonus'.");
    }

    $itemName = trim($itemName);
    if (empty($itemName)) {
        throw new InvalidArgumentException("Nama item perubahan wajib diisi.");
    }

    if ($qty <= 0) {
        throw new InvalidArgumentException("Kuantitas (qty) harus lebih besar dari 0.");
    }

    // If rate <= 0, try to find default rate from master
    if ($rate <= 0) {
        if ($changeType === 'deduction') {
            $stmtM = $db->prepare("SELECT default_amount FROM deduction_types WHERE name = ? LIMIT 1");
            $stmtM->execute([$itemName]);
            $rate = (float)($stmtM->fetchColumn() ?: 0);
        } else {
            $stmtM = $db->prepare("SELECT rate FROM bonus_types WHERE name = ? LIMIT 1");
            $stmtM->execute([$itemName]);
            $rate = (float)($stmtM->fetchColumn() ?: 0);
        }
    }

    // Find employee's payroll record in this period
    $stmtPay = $db->prepare("SELECT * FROM payrolls WHERE payroll_period_id = ? AND employee_id = ?");
    $stmtPay->execute([$periodId, $employeeId]);
    $payroll = $stmtPay->fetch();

    if (!$payroll) {
        throw new DomainException("Data payroll untuk karyawan {$emp['full_name']} pada periode '{$period['name']}' belum diinisialisasi oleh admin HR. Pratinjau atau pengajuan perubahan hanya dapat dilakukan setelah periode payroll diinisialisasi.");
    }

    $payrollId = (int)$payroll['id'];
    $basicSalary = (float)$payroll['basic_salary'];
    $curTotalBonus = (float)$payroll['total_individual_bonus'];
    $curTotalDeduct = (float)$payroll['total_deductions'];
    $curTakeHomePay = (float)$payroll['take_home_pay'];

    // Check existing item in payroll_items
    $stmtItem = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = ? AND name = ?");
    $stmtItem->execute([$payrollId, $changeType, $itemName]);
    $curItem = $stmtItem->fetch();

    $curItemQty = $curItem ? (int)$curItem['qty'] : 0;
    $curItemAmount = $curItem ? (float)$curItem['amount'] : 0;
    $curItemRate = $curItem ? (float)($curItem['notes'] ?: $rate) : $rate;

    if ($rate <= 0 && $curItemRate > 0) {
        $rate = $curItemRate;
    }

    // Calculate new item amount
    $newItemQty = $qty;
    $newItemRate = $rate;
    $newItemAmount = $newItemQty * $newItemRate;
    $diffItemAmount = $newItemAmount - $curItemAmount;

    // Projected payroll totals
    if ($changeType === 'deduction') {
        $projTotalBonus = $curTotalBonus;
        $projTotalDeduct = $curTotalDeduct + $diffItemAmount;
    } else {
        $projTotalBonus = $curTotalBonus + $diffItemAmount;
        $projTotalDeduct = $curTotalDeduct;
    }

    $projTakeHomePay = $basicSalary + $projTotalBonus - $projTotalDeduct;
    $diffTakeHomePay = $projTakeHomePay - $curTakeHomePay;

    // Fingerprint for optimistic lock
    $basePayrollHash = computePayrollStateHash($payrollId, $curTakeHomePay, $curTotalBonus, $curTotalDeduct, $curItemAmount, $curItemQty);

    $beforeSnapshot = [
        'basic_salary' => $basicSalary,
        'total_bonus' => $curTotalBonus,
        'total_deductions' => $curTotalDeduct,
        'take_home_pay' => $curTakeHomePay,
        'item_qty' => $curItemQty,
        'item_amount' => $curItemAmount,
        'item_rate' => $curItemRate
    ];

    $afterSnapshot = [
        'basic_salary' => $basicSalary,
        'total_bonus' => $projTotalBonus,
        'total_deductions' => $projTotalDeduct,
        'take_home_pay' => $projTakeHomePay,
        'item_qty' => $newItemQty,
        'item_amount' => $newItemAmount,
        'item_rate' => $newItemRate,
        'diff_thp' => $diffTakeHomePay
    ];

    return [
        'payroll_period_id' => $periodId,
        'period_name' => $period['name'],
        'period_status' => $period['status'],
        'employee_id' => $employeeId,
        'employee_name' => $emp['full_name'],
        'employee_nik' => $emp['nik'],
        'division_name' => $emp['division_name'],
        'payroll_id' => $payrollId,
        'change_type' => $changeType,
        'item_name' => $itemName,
        'qty' => $newItemQty,
        'rate' => $newItemRate,
        'amount' => $newItemAmount,
        'reason' => $reason,
        'before' => $beforeSnapshot,
        'after' => $afterSnapshot,
        'base_payroll_hash' => $basePayrollHash
    ];
}

/**
 * Create a new proposal with idempotency handling.
 */
function createPayrollChangeProposal(PDO $db, array $client, array $input): array {
    $idempotencyKey = trim($input['idempotency_key'] ?? '');
    
    // 1. Idempotency Check: prevent duplicate proposals if retried
    if (!empty($idempotencyKey)) {
        $stmtCheck = $db->prepare("SELECT * FROM payroll_change_proposals WHERE idempotency_key = ? AND client_id = ?");
        $stmtCheck->execute([$idempotencyKey, $client['id']]);
        $existing = $stmtCheck->fetch();
        if ($existing) {
            return [
                'is_replay' => true,
                'proposal' => $existing,
                'message' => 'Proposal with this idempotency key already exists. Returning existing record.'
            ];
        }
    }

    $periodId = (int)($input['payroll_period_id'] ?? 0);
    $employeeId = (int)($input['employee_id'] ?? 0);
    $changeType = trim($input['change_type'] ?? '');
    $itemName = trim($input['item_name'] ?? '');
    $qty = (int)($input['qty'] ?? 1);
    $rate = (float)($input['rate'] ?? 0);
    $reason = trim($input['reason'] ?? '');

    if (empty($reason)) {
        throw new InvalidArgumentException("Alasan pengajuan (reason) wajib diisi secara jelas.");
    }

    // Run preview calculation
    $calc = calculateEmployeePayrollPreview($db, $periodId, $employeeId, $changeType, $itemName, $rate, $qty, $reason);

    // Insert proposal record
    $stmtIns = $db->prepare("
        INSERT INTO payroll_change_proposals (
            client_id, proposed_by, payroll_period_id, employee_id, payroll_id,
            change_type, item_name, amount, qty, rate, reason,
            before_value, after_value, base_payroll_hash, status, idempotency_key,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, 'pending', ?,
            datetime('now', 'localtime'), datetime('now', 'localtime')
        )
    ");

    $proposedBy = $client['name'] ?? 'Lisa (Hermes Agent)';
    $stmtIns->execute([
        $client['id'],
        $proposedBy,
        $periodId,
        $employeeId,
        $calc['payroll_id'],
        $changeType,
        $itemName,
        $calc['amount'],
        $calc['qty'],
        $calc['rate'],
        $reason,
        json_encode($calc['before'], JSON_UNESCAPED_UNICODE),
        json_encode($calc['after'], JSON_UNESCAPED_UNICODE),
        $calc['base_payroll_hash'],
        $idempotencyKey ?: null
    ]);

    $proposalId = (int)$db->lastInsertId();

    // Log audit
    logAudit(
        null,
        'API_PROPOSE_PAYROLL_CHANGE',
        "Klien '{$proposedBy}' mengajukan {$changeType} '{$itemName}' ({$calc['qty']}x Rp" . number_format($calc['rate'], 0, ',', '.') . ") untuk karyawan {$calc['employee_name']} (Periode {$calc['period_name']}). Alasan: {$reason}"
    );

    $stmtFetch = $db->prepare("SELECT * FROM payroll_change_proposals WHERE id = ?");
    $stmtFetch->execute([$proposalId]);
    $newProposal = $stmtFetch->fetch();

    return [
        'is_replay' => false,
        'proposal' => $newProposal,
        'preview' => $calc
    ];
}

/**
 * Approve and apply a proposal into actual payroll.
 * Must be executed by an authenticated human user with proper permissions.
 */
function approvePayrollProposal(PDO $db, int $proposalId, int $approverUserId): array {
    $stmt = $db->prepare("
        SELECT pcp.*, pp.name as period_name, pp.status as period_status,
               e.full_name as employee_name, e.nik as employee_nik
        FROM payroll_change_proposals pcp
        JOIN payroll_periods pp ON pcp.payroll_period_id = pp.id
        JOIN employees e ON pcp.employee_id = e.id
        WHERE pcp.id = ?
    ");
    $stmt->execute([$proposalId]);
    $proposal = $stmt->fetch();

    if (!$proposal) {
        throw new InvalidArgumentException("Proposal ID {$proposalId} tidak ditemukan.");
    }

    if ($proposal['status'] !== 'pending') {
        throw new DomainException("Proposal ini sudah tidak berstatus pending (status saat ini: '{$proposal['status']}').");
    }

    // Safety Check 1: Check if period is still editable
    if (!in_array($proposal['period_status'], ['draft', 'review'])) {
        $db->prepare("UPDATE payroll_change_proposals SET status = 'stale', rejection_reason = 'Periode telah dikunci', updated_at = datetime('now', 'localtime') WHERE id = ?")->execute([$proposalId]);
        throw new DomainException("Gagal: Periode payroll '{$proposal['period_name']}' saat ini berstatus '{$proposal['period_status']}' (terkunci). Perubahan tidak dapat diterapkan.");
    }

    // Safety Check 2: Check current payroll record for concurrency / state drift
    $stmtPay = $db->prepare("SELECT * FROM payrolls WHERE id = ?");
    $stmtPay->execute([$proposal['payroll_id']]);
    $payroll = $stmtPay->fetch();

    if (!$payroll) {
        $db->prepare("UPDATE payroll_change_proposals SET status = 'stale', rejection_reason = 'Data payroll tidak ditemukan', updated_at = datetime('now', 'localtime') WHERE id = ?")->execute([$proposalId]);
        throw new DomainException("Gagal: Data payroll untuk karyawan {$proposal['employee_name']} tidak ditemukan.");
    }

    // Check item state in payroll_items
    $stmtItem = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND type = ? AND name = ?");
    $stmtItem->execute([$proposal['payroll_id'], $proposal['change_type'], $proposal['item_name']]);
    $curItem = $stmtItem->fetch();

    $curItemQty = $curItem ? (int)$curItem['qty'] : 0;
    $curItemAmount = $curItem ? (float)$curItem['amount'] : 0;
    $curTakeHomePay = (float)$payroll['take_home_pay'];
    $curTotalBonus = (float)$payroll['total_individual_bonus'];
    $curTotalDeduct = (float)$payroll['total_deductions'];

    $currentHash = computePayrollStateHash((int)$payroll['id'], $curTakeHomePay, $curTotalBonus, $curTotalDeduct, $curItemAmount, $curItemQty);

    if ($currentHash !== $proposal['base_payroll_hash']) {
        // Mark proposal as stale to prevent corrupting data
        $db->prepare("
            UPDATE payroll_change_proposals 
            SET status = 'stale', rejection_reason = 'Data payroll telah berubah sejak proposal dibuat (konflik perubahan)', updated_at = datetime('now', 'localtime') 
            WHERE id = ?
        ")->execute([$proposalId]);

        throw new DomainException("Gagal menerapkan perubahan: Data gaji karyawan {$proposal['employee_name']} telah dimodifikasi oleh admin lain sejak proposal ini diajukan. Status pengajuan ditandai sebagai 'stale' (kadaluarsa) demi menjaga integritas data.");
    }

    // All safety checks passed! Apply changes in an SQLite transaction
    $db->beginTransaction();
    try {
        $newQty = (int)$proposal['qty'];
        $newAmount = (float)$proposal['amount'];
        $newRate = (float)$proposal['rate'];

        if ($curItem) {
            // Update existing item
            $stmtUpItem = $db->prepare("UPDATE payroll_items SET qty = ?, amount = ?, notes = ? WHERE id = ?");
            $stmtUpItem->execute([$newQty, $newAmount, (string)$newRate, $curItem['id']]);
        } else {
            // Insert new item for this employee's payroll
            $stmtInsItem = $db->prepare("INSERT INTO payroll_items (payroll_id, type, name, amount, qty, notes) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtInsItem->execute([$payroll['id'], $proposal['change_type'], $proposal['item_name'], $newAmount, $newQty, (string)$newRate]);
        }

        // Recalculate totals for this employee
        $stmtBonusTotal = $db->prepare("SELECT SUM(amount) FROM payroll_items WHERE payroll_id = ? AND type = 'individual_bonus'");
        $stmtBonusTotal->execute([$payroll['id']]);
        $totalBonus = (float)$stmtBonusTotal->fetchColumn();

        $stmtDeductTotal = $db->prepare("SELECT SUM(amount) FROM payroll_items WHERE payroll_id = ? AND type = 'deduction'");
        $stmtDeductTotal->execute([$payroll['id']]);
        $totalDeductions = (float)$stmtDeductTotal->fetchColumn();

        $basicSalary = (float)$payroll['basic_salary'];
        $newTakeHomePay = $basicSalary + $totalBonus - $totalDeductions;

        $stmtUpPay = $db->prepare("
            UPDATE payrolls 
            SET total_individual_bonus = ?, total_deductions = ?, take_home_pay = ? 
            WHERE id = ?
        ");
        $stmtUpPay->execute([$totalBonus, $totalDeductions, $newTakeHomePay, $payroll['id']]);

        // Mark proposal as approved
        $stmtApprove = $db->prepare("
            UPDATE payroll_change_proposals 
            SET status = 'approved', reviewed_by = ?, reviewed_at = datetime('now', 'localtime'), updated_at = datetime('now', 'localtime')
            WHERE id = ?
        ");
        $stmtApprove->execute([$approverUserId, $proposalId]);

        // Audit Trail
        logAudit(
            $approverUserId,
            'APPROVE_PAYROLL_PROPOSAL',
            "Bos/Admin menyetujui pengajuan #{$proposalId} ({$proposal['change_type']}: {$proposal['item_name']} {$newQty}x) untuk {$proposal['employee_name']}. Take Home Pay baru: " . formatRupiah($newTakeHomePay)
        );

        $db->commit();

        return [
            'success' => true,
            'proposal_id' => $proposalId,
            'employee_name' => $proposal['employee_name'],
            'new_take_home_pay' => $newTakeHomePay,
            'formatted_thp' => formatRupiah($newTakeHomePay),
            'message' => "Pengajuan #{$proposalId} berhasil disetujui. Gaji karyawan telah diperbarui secara otomatis."
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Reject a proposal with reason.
 */
function rejectPayrollProposal(PDO $db, int $proposalId, int $reviewerUserId, string $reason): array {
    $stmt = $db->prepare("
        SELECT pcp.*, e.full_name as employee_name
        FROM payroll_change_proposals pcp
        JOIN employees e ON pcp.employee_id = e.id
        WHERE pcp.id = ?
    ");
    $stmt->execute([$proposalId]);
    $proposal = $stmt->fetch();

    if (!$proposal) {
        throw new InvalidArgumentException("Proposal ID {$proposalId} tidak ditemukan.");
    }

    if ($proposal['status'] !== 'pending') {
        throw new DomainException("Proposal ini sudah tidak berstatus pending (status: '{$proposal['status']}').");
    }

    $reason = trim($reason);
    if (empty($reason)) {
        $reason = "Ditolak oleh admin/bos tanpa catatan khusus.";
    }

    $db->prepare("
        UPDATE payroll_change_proposals 
        SET status = 'rejected', reviewed_by = ?, reviewed_at = datetime('now', 'localtime'), rejection_reason = ?, updated_at = datetime('now', 'localtime')
        WHERE id = ?
    ")->execute([$reviewerUserId, $reason, $proposalId]);

    logAudit(
        $reviewerUserId,
        'REJECT_PAYROLL_PROPOSAL',
        "Bos/Admin menolak pengajuan #{$proposalId} ({$proposal['change_type']}: {$proposal['item_name']}) untuk {$proposal['employee_name']}. Alasan: {$reason}"
    );

    return [
        'success' => true,
        'proposal_id' => $proposalId,
        'message' => "Pengajuan #{$proposalId} berhasil ditolak."
    ];
}

/**
 * Retrieve or automatically generate a secure Webhook Secret for payroll cronjob.
 */
function getOrCreateWebhookSecret(PDO $db): string {
    try {
        $db->exec("ALTER TABLE company_settings ADD COLUMN cron_webhook_secret TEXT");
    } catch (\Throwable $e) {}

    $stmt = $db->query("SELECT cron_webhook_secret FROM company_settings WHERE id = 1");
    $secret = $stmt ? $stmt->fetchColumn() : null;

    if (!empty($secret)) {
        return (string)$secret;
    }

    $newSecret = 'gjk_cron_' . bin2hex(random_bytes(20));
    $stmtUp = $db->prepare("UPDATE company_settings SET cron_webhook_secret = ? WHERE id = 1");
    $stmtUp->execute([$newSecret]);

    return $newSecret;
}

/**
 * Regenerate Webhook Secret for payroll cronjob.
 */
function regenerateWebhookSecret(PDO $db): string {
    try {
        $db->exec("ALTER TABLE company_settings ADD COLUMN cron_webhook_secret TEXT");
    } catch (\Throwable $e) {}

    $newSecret = 'gjk_cron_' . bin2hex(random_bytes(20));
    $stmtUp = $db->prepare("UPDATE company_settings SET cron_webhook_secret = ? WHERE id = 1");
    $stmtUp->execute([$newSecret]);

    return $newSecret;
}

/**
 * Helper function to check if a bonus or deduction is a team (division-wide) item.
 */
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
 * Menambahkan karyawan baru & aturan bonus/potongan baru ke periode payroll draft/review
 * TANPA menghapus atau mengubah input yang sudah disimpan.
 */
if (!function_exists('syncPayrollRules')) {
    function syncPayrollRules(PDO $db, int $periodId, array $period): int {
        $addedCount = 0;
        try {
            $inTransactionBefore = $db->inTransaction();
            if (!$inTransactionBefore) {
                $db->beginTransaction();
            }

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
                        $stmtInsItem->execute([$pId, 'individual_bonus', $bName, (string)$rate]);
                        $addedCount++;
                    } else {
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
                        $stmtInsItem->execute([$pId, 'deduction', $dName, (string)$amount]);
                        $addedCount++;
                    } else {
                        $existingItem = $existingDeductMap[$dName];
                        if ((int)$existingItem['qty'] === 0) {
                            $stmtUpdateRate->execute([(string)$amount, $existingItem['id']]);
                        }
                    }
                }
            }

            if (!$inTransactionBefore) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransactionBefore && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("syncPayrollRules Error: " . $e->getMessage());
        }

        return $addedCount;
    }
}

/**
 * Automatically create monthly payroll period if it doesn't already exist.
 */
function autoCreatePayrollPeriod(PDO $db, ?int $month = null, ?int $year = null, ?int $userId = null): array {
    $month = $month ?: (int)date('n');
    $year = $year ?: (int)date('Y');

    $indonesianMonths = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    $monthName = $indonesianMonths[$month] ?? date('F', mktime(0, 0, 0, $month, 10));
    $name = $monthName . ' ' . $year;

    // Check if period already exists
    $stmtCheck = $db->prepare("SELECT * FROM payroll_periods WHERE name = ?");
    $stmtCheck->execute([$name]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        return [
            'created' => false,
            'period' => $existing,
            'message' => "Periode payroll '{$name}' sudah ada."
        ];
    }

    // Determine payment date & cutoff
    $stmtSet = $db->query("SELECT payroll_payment_day FROM company_settings WHERE id = 1");
    $paymentDay = (int)($stmtSet ? $stmtSet->fetchColumn() : 25) ?: 25;
    if ($paymentDay < 1) $paymentDay = 25;
    if ($paymentDay > 28) $paymentDay = 28; // safe for February

    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $paymentDay);
    $paymentDate = $endDate;

    if ($month === 1) {
        $prevMonth = 12;
        $prevYear = $year - 1;
    } else {
        $prevMonth = $month - 1;
        $prevYear = $year;
    }
    $startDay = $paymentDay + 1;
    $startDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $startDay);

    // If userId not provided, find super admin
    if (!$userId) {
        $stmtU = $db->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.slug = 'super_admin' LIMIT 1");
        $userId = (int)($stmtU ? $stmtU->fetchColumn() : 1) ?: 1;
    }

    $stmtIns = $db->prepare("
        INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by, created_at)
        VALUES (?, ?, ?, ?, 'draft', ?, datetime('now', 'localtime'))
    ");
    $stmtIns->execute([$name, $startDate, $endDate, $paymentDate, $userId]);
    $periodId = (int)$db->lastInsertId();

    $stmtGet = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmtGet->execute([$periodId]);
    $newPeriod = $stmtGet->fetch();

    // Ensure payroll records and rules are populated
    ensurePayrollPeriodReady($db, $periodId);
    syncPayrollRules($db, $periodId, $newPeriod);

    // Log audit
    logAudit($userId, 'CRON_AUTO_CREATE_PERIOD', "Periode payroll '{$name}' otomatis dibuat via cronjob.");

    // Notify admins
    $admins = $db->query("SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE r.slug IN ('super_admin', 'admin_hr') AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($admins as $adminId) {
        sendNotification(
            $adminId,
            'Periode Payroll Baru Terbentuk (Otomatis)',
            "Periode payroll '{$name}' telah dibuat otomatis oleh cronjob sistem dalam status Draft.",
            BASE_URL . '/payroll.php?period_id=' . $periodId
        );
    }

    return [
        'created' => true,
        'period' => $newPeriod,
        'message' => "Periode payroll '{$name}' berhasil dibuat secara otomatis."
    ];
}

/**
 * Recalculate totals for all employees in a payroll period.
 */
function recalculatePayrollPeriodTotals(PDO $db, int $periodId): array {
    $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmtP->execute([$periodId]);
    $period = $stmtP->fetch();

    if (!$period) {
        throw new InvalidArgumentException("Periode payroll ID {$periodId} tidak ditemukan.");
    }

    $stmtAllPay = $db->prepare("SELECT id, basic_salary FROM payrolls WHERE payroll_period_id = ?");
    $stmtAllPay->execute([$periodId]);
    $allPays = $stmtAllPay->fetchAll() ?: [];

    $stmtBonusTotal = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM payroll_items 
        WHERE payroll_id = ? AND type = 'individual_bonus'
    ");
    $stmtDeductTotal = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) FROM payroll_items 
        WHERE payroll_id = ? AND type = 'deduction'
    ");
    $stmtUpPay = $db->prepare("
        UPDATE payrolls 
        SET total_individual_bonus = ?, total_deductions = ?, take_home_pay = ? 
        WHERE id = ?
    ");

    $grandTotalSalary = 0;
    $grandTotalBonus = 0;
    $grandTotalDeduct = 0;
    $grandTakeHomePay = 0;

    foreach ($allPays as $p) {
        $pId = (int)$p['id'];
        $bSalary = (float)$p['basic_salary'];

        $stmtBonusTotal->execute([$pId]);
        $totalBonus = (float)$stmtBonusTotal->fetchColumn();

        $stmtDeductTotal->execute([$pId]);
        $totalDeductions = (float)$stmtDeductTotal->fetchColumn();

        $takeHomePay = $bSalary + $totalBonus - $totalDeductions;
        $stmtUpPay->execute([$totalBonus, $totalDeductions, $takeHomePay, $pId]);

        $grandTotalSalary += $bSalary;
        $grandTotalBonus += $totalBonus;
        $grandTotalDeduct += $totalDeductions;
        $grandTakeHomePay += $takeHomePay;
    }

    return [
        'period_id' => $periodId,
        'period_name' => $period['name'],
        'employee_count' => count($allPays),
        'grand_basic_salary' => $grandTotalSalary,
        'grand_total_bonus' => $grandTotalBonus,
        'grand_total_deductions' => $grandTotalDeduct,
        'grand_take_home_pay' => $grandTakeHomePay,
        'formatted_thp' => formatRupiah($grandTakeHomePay)
    ];
}

/**
 * Orchestrator to execute full payroll cronjob workflow.
 */
function runPayrollCronWorkflow(PDO $db, array $options = []): array {
    $startTime = microtime(true);
    $action = $options['action'] ?? 'auto'; // 'auto', 'create_period', 'sync_rules', 'recalculate', 'finalize'
    $month = isset($options['month']) ? (int)$options['month'] : null;
    $year = isset($options['year']) ? (int)$options['year'] : null;
    $periodId = isset($options['period_id']) ? (int)$options['period_id'] : null;
    $dryRun = !empty($options['dry_run']);
    $triggeredBy = $options['triggered_by'] ?? 'Cron Webhook';

    if ($dryRun) {
        $db->beginTransaction();
    }

    $actionsPerformed = [];
    $period = null;
    $createdPeriod = false;

    try {
        // Step 1: Find or Create Target Period
        if ($periodId) {
            $stmtP = $db->prepare("SELECT * FROM payroll_periods WHERE id = ?");
            $stmtP->execute([$periodId]);
            $period = $stmtP->fetch();
            if (!$period) {
                throw new InvalidArgumentException("Periode payroll ID {$periodId} tidak ditemukan.");
            }
        } else {
            $periodRes = autoCreatePayrollPeriod($db, $month, $year);
            $period = $periodRes['period'];
            $periodId = (int)$period['id'];
            if ($periodRes['created']) {
                $createdPeriod = true;
                $actionsPerformed[] = 'created_period';
            }
        }

        // Step 2: Sync Rules & New Employees
        $rulesAdded = 0;
        if (in_array($action, ['auto', 'sync_rules', 'create_period'])) {
            ensurePayrollPeriodReady($db, $periodId);
            $rulesAdded = syncPayrollRules($db, $periodId, $period);
            $actionsPerformed[] = 'synced_rules';
        }

        // Step 3: Recalculate Totals
        $recalc = [];
        if (in_array($action, ['auto', 'sync_rules', 'recalculate', 'create_period'])) {
            $recalc = recalculatePayrollPeriodTotals($db, $periodId);
            $actionsPerformed[] = 'recalculated_totals';
        }

        // Step 4: Finalize if requested
        $finalized = false;
        if ($action === 'finalize') {
            if ($period['status'] === 'review') {
                $stmtFinal = $db->prepare("UPDATE payroll_periods SET status = 'final', finalized_at = datetime('now', 'localtime'), finalized_by = 1 WHERE id = ?");
                $stmtFinal->execute([$periodId]);
                $finalized = true;
                $actionsPerformed[] = 'finalized_period';
            }
        }

        $executionTime = round(microtime(true) - $startTime, 4);

        if ($dryRun) {
            $db->rollBack();
            $statusMsg = "Dry run selesai (perubahan di-rollback).";
        } else {
            $statusMsg = "Cronjob payroll berhasil dieksekusi.";
            logAudit(null, 'CRON_PAYROLL_EXECUTE', "{$triggeredBy} executed action '{$action}' on period '{$period['name']}' (ID: {$periodId}). Execution time: {$executionTime}s");
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'action' => $action,
            'triggered_by' => $triggeredBy,
            'actions_performed' => $actionsPerformed,
            'period' => [
                'id' => (int)$period['id'],
                'name' => $period['name'],
                'status' => $finalized ? 'final' : $period['status'],
                'start_date' => $period['start_date'],
                'end_date' => $period['end_date'],
                'payment_date' => $period['payment_date'],
                'was_newly_created' => $createdPeriod
            ],
            'summary' => [
                'rules_or_items_added' => $rulesAdded,
                'employee_count' => $recalc['employee_count'] ?? 0,
                'total_payroll_amount' => $recalc['grand_take_home_pay'] ?? 0,
                'formatted_total_payroll' => $recalc['formatted_thp'] ?? 'Rp0'
            ],
            'execution_time_seconds' => $executionTime,
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $statusMsg
        ];

    } catch (\Throwable $e) {
        if ($dryRun && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
