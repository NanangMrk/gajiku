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

        $db->beginTransaction();
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
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
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
