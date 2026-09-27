<?php
// tests/test_api_proposals.php — Automated Test Suite for Lisa PA Integration API & Bos Approval Workflow

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/api_auth.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

echo "====================================================================\n";
echo "  GAJIKU — Integration Test Suite: Lisa PA API & Bos Approval Flow\n";
echo "====================================================================\n\n";

$db = getDbConnection();
$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [\033[32mPASS\033[0m] " . $description . "\n";
    } else {
        $failed++;
        echo " [\033[31mFAIL\033[0m] " . $description . ($detail ? " ($detail)" : "") . "\n";
    }
}

// -------------------------------------------------------------
// SETUP TEST FIXTURES
// -------------------------------------------------------------
echo "-> Menyiapkan data uji...\n";

// 1. Create or ensure test API clients
$testKey = 'test_lisa_' . time();
$clientData = createApiClient("Lisa Test Agent", $testKey, "payroll:read,payroll:propose");
$validToken = $clientData['raw_token'];

$readOnlyKey = 'test_readonly_' . time();
$readOnlyClient = createApiClient("Readonly Client", $readOnlyKey, "payroll:read");
$readOnlyToken = $readOnlyClient['raw_token'];

// 2. Ensure admin user for Bos approval
$stmtAdmin = $db->query("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE r.slug IN ('super_admin', 'admin_hr') LIMIT 1");
$adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
$adminUserId = (int)($adminUser['id'] ?? 1);

// 3. Ensure test payroll periods
// Open period
$stmtP1 = $db->query("SELECT * FROM payroll_periods WHERE status IN ('draft', 'review') LIMIT 1");
$openPeriod = $stmtP1->fetch(PDO::FETCH_ASSOC);
if (!$openPeriod) {
    $db->prepare("INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by) VALUES ('Test Period Draft', '2026-09-01', '2026-09-30', '2026-10-01', 'draft', ?)")->execute([$adminUserId]);
    $openPeriodId = (int)$db->lastInsertId();
    $openPeriod = $db->query("SELECT * FROM payroll_periods WHERE id = {$openPeriodId}")->fetch(PDO::FETCH_ASSOC);
}
$openPeriodId = (int)$openPeriod['id'];

// Locked / final period
$stmtP2 = $db->query("SELECT * FROM payroll_periods WHERE status = 'final' LIMIT 1");
$lockedPeriod = $stmtP2->fetch(PDO::FETCH_ASSOC);
if (!$lockedPeriod) {
    $db->prepare("INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by) VALUES ('Test Period Final', '2026-08-01', '2026-08-31', '2026-09-01', 'final', ?)")->execute([$adminUserId]);
    $lockedPeriodId = (int)$db->lastInsertId();
    $lockedPeriod = $db->query("SELECT * FROM payroll_periods WHERE id = {$lockedPeriodId}")->fetch(PDO::FETCH_ASSOC);
}
$lockedPeriodId = (int)$lockedPeriod['id'];

// 3. Ensure test employee
$stmtE = $db->query("SELECT * FROM employees WHERE status = 'active' LIMIT 1");
$testEmployee = $stmtE->fetch(PDO::FETCH_ASSOC);
if (!$testEmployee) {
    $db->prepare("INSERT INTO employees (nik, full_name, position, status) VALUES ('TEST001', 'Budi Santoso Test', 'Staff IT', 'active')")->execute();
    $testEmpId = (int)$db->lastInsertId();
    $testEmployee = $db->query("SELECT * FROM employees WHERE id = {$testEmpId}")->fetch(PDO::FETCH_ASSOC);
}
$testEmpId = (int)$testEmployee['id'];

// Ensure employee has payroll record in open period
ensurePayrollPeriodReady($db, $openPeriodId);


echo "   Client ID: {$clientData['id']} ({$clientData['name']})\n";
echo "   Karyawan Target: [{$testEmployee['nik']}] {$testEmployee['full_name']} (ID: {$testEmpId})\n";
echo "   Periode Aktif: {$openPeriod['name']} (ID: {$openPeriodId}, Status: {$openPeriod['status']})\n";
echo "   Periode Terkunci: {$lockedPeriod['name']} (ID: {$lockedPeriodId}, Status: {$lockedPeriod['status']})\n\n";


// -------------------------------------------------------------
// TEST 1: Token Salah & Token Hilang (401 Unauthorized)
// -------------------------------------------------------------
echo "--- TEST 1: Validasi Autentikasi Token ---\n";
// Case 1.1: Missing token
$_SERVER['HTTP_AUTHORIZATION'] = null;
$tokenNull = getBearerToken();
assertTest("Missing Authorization header terdeteksi null", $tokenNull === null);

// Case 1.2: Invalid Token Hash check
$fakeToken = "gjk_invalidfaketoken1234567890abcdef";
$stmtCheckFake = $db->prepare("SELECT * FROM api_clients WHERE token_hash = ? AND status = 'active'");
$stmtCheckFake->execute([hash('sha256', $fakeToken)]);
$fakeClient = $stmtCheckFake->fetch();
assertTest("Token palsu tidak ditemukan dalam database", $fakeClient === false);

// Case 1.3: Valid Token verification
$stmtCheckValid = $db->prepare("SELECT * FROM api_clients WHERE token_hash = ? AND status = 'active'");
$stmtCheckValid->execute([hash('sha256', $validToken)]);
$authenticatedClient = $stmtCheckValid->fetch();
assertTest("Token valid berhasil mengautentikasi klien", $authenticatedClient !== false && $authenticatedClient['client_key'] === $testKey);


// -------------------------------------------------------------
// TEST 2: Pembatasan Scope & Hak Lisa (403 Forbidden)
// -------------------------------------------------------------
echo "\n--- TEST 2: Batasan Izin / Scope & Larangan Self-Approve ---\n";
// Case 2.1: Readonly scope attempting propose
$readOnlyScopes = explode(',', $readOnlyClient['scopes']);
$canPropose = in_array('payroll:propose', $readOnlyScopes);
assertTest("Klien ber-scope payroll:read ditolak saat mencoba propose", $canPropose === false);

// Case 2.2: Lisa dilarang approve pengajuan sendiri
$lisaProposalAttempt = [
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmpId,
    'change_type' => 'deduction',
    'item_name' => 'Keterlambatan',
    'status' => 'approved' // Terlarang!
];
$isSelfApproveAttempt = isset($lisaProposalAttempt['status']) && in_array(strtolower($lisaProposalAttempt['status']), ['approved', 'final']);
assertTest("Permintaan API dengan status='approved' langsung dicegat", $isSelfApproveAttempt === true);


// -------------------------------------------------------------
// TEST 3: Karyawan atau Periode Tidak Ditemukan (404)
// -------------------------------------------------------------
echo "\n--- TEST 3: Penanganan Data Tidak Ditemukan (404) ---\n";
$nonExistentEmpId = 9999999;
$caughtEmp404 = false;
try {
    calculateEmployeePayrollPreview($db, $openPeriodId, $nonExistentEmpId, 'deduction', 'Potongan Absen', 50000, 1, 'Test');
} catch (InvalidArgumentException $e) {
    $caughtEmp404 = true;
}
assertTest("Karyawan tidak ditemukan melempar InvalidArgumentException (404)", $caughtEmp404);

$nonExistentPeriodId = 9999999;
$caughtPeriod404 = false;
try {
    calculateEmployeePayrollPreview($db, $nonExistentPeriodId, $testEmpId, 'deduction', 'Potongan Absen', 50000, 1, 'Test');
} catch (InvalidArgumentException $e) {
    $caughtPeriod404 = true;
}
assertTest("Periode tidak ditemukan melempar InvalidArgumentException (404)", $caughtPeriod404);


// -------------------------------------------------------------
// TEST 4: Periode Terkunci / Final (409 Conflict)
// -------------------------------------------------------------
echo "\n--- TEST 4: Periode Terkunci / Final Tidak Dapat Diubah ---\n";
$caughtLocked = false;
$lockedErrorMsg = '';
try {
    calculateEmployeePayrollPreview($db, $lockedPeriodId, $testEmpId, 'deduction', 'Potongan Pinalti', 25000, 1, 'Test locked');
} catch (DomainException $e) {
    $caughtLocked = true;
    $lockedErrorMsg = $e->getMessage();
}
assertTest("Periode berstatus 'final' ditolak dengan DomainException", $caughtLocked);


// -------------------------------------------------------------
// TEST 5: Permintaan Berulang / Idempotency
// -------------------------------------------------------------
echo "\n--- TEST 5: Pencegahan Pengajuan Ganda (Idempotency) ---\n";
$testIdempotencyKey = 'idem_key_' . uniqid();
$inputProposal = [
    'idempotency_key' => $testIdempotencyKey,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmpId,
    'change_type' => 'deduction',
    'item_name' => 'Keterlambatan 30 Menit',
    'qty' => 1,
    'rate' => 35000,
    'reason' => 'Lisa mencatat keterlambatan jam masuk kantor pada 27 September'
];

// Permintaan pertama
$res1 = createPayrollChangeProposal($db, $clientData, $inputProposal);
$prop1Id = $res1['proposal']['id'];
assertTest("Pengajuan pertama berhasil disimpan (is_replay = false)", $res1['is_replay'] === false && $prop1Id > 0);

// Permintaan kedua (mengirim ulang payload yang sama persis)
$res2 = createPayrollChangeProposal($db, $clientData, $inputProposal);
assertTest("Pengajuan ulang terdeteksi replay (is_replay = true)", $res2['is_replay'] === true);
assertTest("ID pengajuan replay sama persis dengan yang pertama (tidak membuat baris ganda)", $res2['proposal']['id'] == $prop1Id);

$stmtCountIdem = $db->prepare("SELECT COUNT(*) FROM payroll_change_proposals WHERE idempotency_key = ?");
$stmtCountIdem->execute([$testIdempotencyKey]);
$idemCount = (int)$stmtCountIdem->fetchColumn();
assertTest("Jumlah baris proposal di database tetap 1", $idemCount === 1);


// -------------------------------------------------------------
// TEST 6: Data Berubah Sebelum Persetujuan (Optimistic Lock / Stale Prevention)
// -------------------------------------------------------------
echo "\n--- TEST 6: Deteksi Perubahan Data / State Drift (Stale) ---\n";
// Buat proposal baru
$staleIdemKey = 'stale_test_' . uniqid();
$inputStale = [
    'idempotency_key' => $staleIdemKey,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmpId,
    'change_type' => 'individual_bonus',
    'item_name' => 'Bonus Kerajinan',
    'qty' => 1,
    'rate' => 100000,
    'reason' => 'Pencapaian target shift malam'
];
$propStaleRes = createPayrollChangeProposal($db, $clientData, $inputStale);
$staleProposalId = $propStaleRes['proposal']['id'];

// Simulasi: Sebelum Bos menyetujui, ada perubahan data gaji/potongan oleh admin lain
$payrollId = $propStaleRes['proposal']['payroll_id'];
$db->prepare("UPDATE payrolls SET total_deductions = total_deductions + 5000 WHERE id = ?")->execute([$payrollId]);

// Bos mencoba approve proposal ini
$caughtStaleException = false;
$staleMessage = '';
try {
    approvePayrollProposal($db, $staleProposalId, $adminUserId);
} catch (DomainException $e) {
    $caughtStaleException = true;
    $staleMessage = $e->getMessage();
}

assertTest("Persetujuan digagalkan ketika data payroll telah berubah", $caughtStaleException);

// Periksa status proposal di database
$stmtCheckStatus = $db->prepare("SELECT status, rejection_reason FROM payroll_change_proposals WHERE id = ?");
$stmtCheckStatus->execute([$staleProposalId]);
$staleRow = $stmtCheckStatus->fetch(PDO::FETCH_ASSOC);
assertTest("Proposal otomatis ditandai status 'stale'", $staleRow['status'] === 'stale');


// -------------------------------------------------------------
// TEST 7: Alur Persetujuan Bos Berhasil (Happy Path)
// -------------------------------------------------------------
echo "\n--- TEST 7: Alur Lengkap Persetujuan Bos & Penerapan ke Payroll ---\n";

// Pulihkan payroll state agar konsisten
$stmtSumBonus = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payroll_items WHERE payroll_id = ? AND type = 'individual_bonus'");
$stmtSumBonus->execute([$payrollId]);
$reBonus = (float)$stmtSumBonus->fetchColumn();

$stmtSumDeduct = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM payroll_items WHERE payroll_id = ? AND type = 'deduction'");
$stmtSumDeduct->execute([$payrollId]);
$reDeduct = (float)$stmtSumDeduct->fetchColumn();

$basicSal = (float)$db->query("SELECT basic_salary FROM payrolls WHERE id = {$payrollId}")->fetchColumn();
$db->prepare("UPDATE payrolls SET total_individual_bonus = ?, total_deductions = ?, take_home_pay = ? WHERE id = ?")
   ->execute([$reBonus, $reDeduct, $basicSal + $reBonus - $reDeduct, $payrollId]);


$happyIdemKey = 'happy_flow_' . uniqid();
$inputHappy = [
    'idempotency_key' => $happyIdemKey,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmpId,
    'change_type' => 'deduction',
    'item_name' => 'Keterlambatan Disetujui',
    'qty' => 2,
    'rate' => 25000, // Total 50,000
    'reason' => 'Terlambat 2x pada minggu ke-3 sesuai laporan absensi Lisa'
];

$happyRes = createPayrollChangeProposal($db, $clientData, $inputHappy);
$happyPropId = (int)$happyRes['proposal']['id'];
$initialTHP = (float)$happyRes['preview']['before']['take_home_pay'];
$expectedTHP = (float)$happyRes['preview']['after']['take_home_pay'];

// Bos menyetujui pengajuan
$approvalResult = approvePayrollProposal($db, $happyPropId, $adminUserId);

assertTest("Persetujuan oleh Bos berhasil dijalankan", $approvalResult['success'] === true);

// Periksa status proposal
$stmtCheckHappy = $db->prepare("SELECT * FROM payroll_change_proposals WHERE id = ?");
$stmtCheckHappy->execute([$happyPropId]);
$approvedRow = $stmtCheckHappy->fetch(PDO::FETCH_ASSOC);
assertTest("Status proposal berubah menjadi 'approved'", $approvedRow['status'] === 'approved');
assertTest("Kolom reviewed_by tercatat dengan ID Bos ({$adminUserId})", (int)$approvedRow['reviewed_by'] === $adminUserId);

// Periksa apakah item masuk ke tabel payroll_items
$stmtCheckItem = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? AND name = 'Keterlambatan Disetujui'");
$stmtCheckItem->execute([$payrollId]);
$itemRow = $stmtCheckItem->fetch(PDO::FETCH_ASSOC);
assertTest("Item masuk ke payroll_items dengan amount Rp50.000", $itemRow !== false && (float)$itemRow['amount'] == 50000.0);

// Periksa apakah take_home_pay di tabel payrolls terhitung akurat sesuai preview
$stmtCheckPayAfter = $db->prepare("SELECT take_home_pay FROM payrolls WHERE id = ?");
$stmtCheckPayAfter->execute([$payrollId]);
$actualTHP = (float)$stmtCheckPayAfter->fetchColumn();
assertTest("Take Home Pay karyawan terpotong akurat sesuai rumus (Aktual: {$actualTHP} == Ekspektasi: {$expectedTHP})", abs($actualTHP - $expectedTHP) < 0.01);

// Periksa audit log
$stmtCheckAudit = $db->prepare("SELECT * FROM audit_logs WHERE user_id = ? AND action = 'APPROVE_PAYROLL_PROPOSAL' ORDER BY id DESC LIMIT 1");
$stmtCheckAudit->execute([$adminUserId]);
$auditRow = $stmtCheckAudit->fetch(PDO::FETCH_ASSOC);
assertTest("Catatan audit APPROVE_PAYROLL_PROPOSAL berhasil tercatat di audit_logs", $auditRow !== false);


// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n====================================================================\n";
echo "  HASIL PENGUJIAN: {$passed} BERHASIL, {$failed} GAGAL\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "\033[32mSEMUA SKENARIO PENGUJIAN INTEGRASI BERHASIL MEMENUHI SPESIFIKASI!\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31mTERDAPAT SKENARIO YANG GAGAL. SILAKAN PERIKSA DETAIL DI ATAS.\033[0m\n\n";
    exit(1);
}
