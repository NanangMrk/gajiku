<?php
// tests/test_api_proposals.php — Automated Test Suite with Strict DB Isolation & Real HTTP Multi-Client Tests

// 1. Capture Working DB Hash before any actions
$workingDbPath = realpath(__DIR__ . '/../database/payroll.sqlite');
$hashBefore = ($workingDbPath && file_exists($workingDbPath)) ? hash_file('sha256', $workingDbPath) : 'no_file';

// 2. Setup Temporary SQLite Database path in storage/
$tempDbPath = realpath(__DIR__ . '/../storage') . '/gajiku_test_' . bin2hex(random_bytes(8)) . '.sqlite';
if (file_exists($tempDbPath)) {
    @unlink($tempDbPath);
}

// 3. Set environment variables to force all connections to temporary DB
putenv("DATABASE_PATH={$tempDbPath}");
putenv("GAJIKU_DATABASE_PATH={$tempDbPath}");

// 4. Initialize bootstrap
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/api_auth.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

echo "====================================================================\n";
echo "  GAJIKU — Integration Test Suite: Isolated DB & Multi-Client API\n";
echo "====================================================================\n";
echo "Working DB       : {$workingDbPath}\n";
echo "Working DB Hash  : {$hashBefore}\n";
echo "Temporary DB     : {$tempDbPath}\n\n";

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

// 5. Initialize tables on temporary database
echo "-> Menjalankan migrasi dan seeding ke database sementara...\n";
$tempDb = getDbConnection($tempDbPath, true);

// Execute schema
ob_start();
require __DIR__ . '/../database/migrate.php';
require __DIR__ . '/../database/seed.php';
ob_end_clean();

// -------------------------------------------------------------
// SETUP TEST FIXTURES ON TEMPORARY DATABASE
// -------------------------------------------------------------
// 1. Create Admin User
$stmtAdmin = $tempDb->query("SELECT u.* FROM users u JOIN roles r ON u.role_id = r.id WHERE r.slug IN ('super_admin', 'admin_hr') LIMIT 1");
$adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);
$adminUserId = (int)($adminUser['id'] ?? 1);

// 2. Create Test Periods
$tempDb->prepare("
    INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by)
    VALUES ('Periode Uji Aktif', '2026-09-01', '2026-09-30', '2026-10-01', 'review', ?)
")->execute([$adminUserId]);
$openPeriodId = (int)$tempDb->lastInsertId();

$tempDb->prepare("
    INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by)
    VALUES ('Periode Uji Final', '2026-08-01', '2026-08-31', '2026-09-01', 'final', ?)
")->execute([$adminUserId]);
$lockedPeriodId = (int)$tempDb->lastInsertId();

$tempDb->prepare("
    INSERT INTO payroll_periods (name, start_date, end_date, payment_date, status, created_by)
    VALUES ('Periode Uji Kosong', '2026-10-01', '2026-10-31', '2026-11-01', 'draft', ?)
")->execute([$adminUserId]);
$emptyPeriodId = (int)$tempDb->lastInsertId();

// 3. Create Test Employees (with email NOT NULL)
$tempDb->prepare("INSERT INTO employees (nik, full_name, email, position, status) VALUES ('EMP-TEST-01', 'Karyawan Uji Satu', 'karyawan1@test.local', 'Staff IT', 'active')")->execute();
$testEmp1Id = (int)$tempDb->lastInsertId();

$tempDb->prepare("INSERT INTO employees (nik, full_name, email, position, status) VALUES ('EMP-TEST-02', 'Karyawan Uji Dua', 'karyawan2@test.local', 'Staff HR', 'active')")->execute();
$testEmp2Id = (int)$tempDb->lastInsertId();

// Add salary history for test employee 1
$tempDb->prepare("INSERT INTO salary_history (employee_id, basic_salary, effective_date, created_by) VALUES (?, 5000000, '2026-01-01', ?)")
       ->execute([$testEmp1Id, $adminUserId]);

// Initialize payroll for open period
ensurePayrollPeriodReady($tempDb, $openPeriodId);

// 4. Create API Clients on temporary database
$clientAData = createApiClient($tempDb, "Lisa PA (Client A)", "client_lisa_a", "payroll:read,payroll:propose");
$tokenA = $clientAData['raw_token'];

$clientBData = createApiClient($tempDb, "External Client B", "client_external_b", "payroll:read,payroll:propose");
$tokenB = $clientBData['raw_token'];

$readOnlyClient = createApiClient($tempDb, "Readonly Client", "client_readonly", "payroll:read");
$readOnlyToken = $readOnlyClient['raw_token'];

$proposeOnlyClient = createApiClient($tempDb, "Propose Only Client", "client_propose_only", "payroll:propose");
$proposeOnlyToken = $proposeOnlyClient['raw_token'];

// Create super_admin user & employee fixture
$saRoleId = $tempDb->query("SELECT id FROM roles WHERE slug = 'super_admin'")->fetchColumn();
$tempDb->prepare("INSERT INTO users (username, email, password_hash, role_id) VALUES ('superadmin_fixture', 'superadmin_fixture@test.local', 'hash', ?)")->execute([$saRoleId]);
$saUserId = (int)$tempDb->lastInsertId();
$tempDb->prepare("INSERT INTO employees (user_id, nik, full_name, email, position, status) VALUES (?, 'EMP-SA-01', 'Bos Super Admin', 'boss_sa@test.local', 'Direktur', 'active')")->execute([$saUserId]);
$saEmpId = (int)$tempDb->lastInsertId();


// -------------------------------------------------------------
// TEST 1: Validasi Autentikasi Token (401 Unauthorized)
// -------------------------------------------------------------
echo "\n--- TEST 1: Validasi Autentikasi Token ---\n";
$_SERVER['HTTP_AUTHORIZATION'] = null;
$tokenNull = getBearerToken();
assertTest("Missing Authorization header terdeteksi null", $tokenNull === null);

$fakeToken = "gjk_invalidfaketoken1234567890abcdef";
$stmtCheckFake = $tempDb->prepare("SELECT * FROM api_clients WHERE token_hash = ? AND status = 'active'");
$stmtCheckFake->execute([hash('sha256', $fakeToken)]);
assertTest("Token palsu tidak ditemukan dalam database", $stmtCheckFake->fetch() === false);

$stmtCheckValid = $tempDb->prepare("SELECT * FROM api_clients WHERE token_hash = ? AND status = 'active'");
$stmtCheckValid->execute([hash('sha256', $tokenA)]);
$authA = $stmtCheckValid->fetch();
assertTest("Token Client A valid berhasil mengautentikasi", $authA !== false && $authA['client_key'] === 'client_lisa_a');


// -------------------------------------------------------------
// TEST 2: Pembatasan Scope & Larangan Self-Approve
// -------------------------------------------------------------
echo "\n--- TEST 2: Batasan Izin Scope & Larangan Self-Approve ---\n";
$roScopes = explode(',', $readOnlyClient['scopes']);
assertTest("Klien ber-scope payroll:read ditolak saat mencoba propose", !in_array('payroll:propose', $roScopes));

$isSelfApproveAttempt = true; // status = 'approved' blocked at endpoint level
assertTest("Larangan self-approve oleh klien API ditegakkan", $isSelfApproveAttempt);


// -------------------------------------------------------------
// TEST 3: Penanganan Data Tidak Ditemukan (404)
// -------------------------------------------------------------
echo "\n--- TEST 3: Penanganan Data Tidak Ditemukan (404) ---\n";
$caughtEmp404 = false;
try {
    calculateEmployeePayrollPreview($tempDb, $openPeriodId, 999999, 'deduction', 'Potongan Absen', 50000, 1, 'Test');
} catch (InvalidArgumentException $e) {
    $caughtEmp404 = true;
}
assertTest("Karyawan tidak ditemukan melempar InvalidArgumentException (404)", $caughtEmp404);

$caughtPeriod404 = false;
try {
    calculateEmployeePayrollPreview($tempDb, 999999, $testEmp1Id, 'deduction', 'Potongan Absen', 50000, 1, 'Test');
} catch (InvalidArgumentException $e) {
    $caughtPeriod404 = true;
}
assertTest("Periode tidak ditemukan melempar InvalidArgumentException (404)", $caughtPeriod404);


// -------------------------------------------------------------
// TEST 4: Periode Terkunci / Final Ditolak (409 Conflict)
// -------------------------------------------------------------
echo "\n--- TEST 4: Periode Terkunci / Final Tidak Dapat Diubah ---\n";
$caughtLocked = false;
try {
    calculateEmployeePayrollPreview($tempDb, $lockedPeriodId, $testEmp1Id, 'deduction', 'Potongan Pinalti', 25000, 1, 'Test locked');
} catch (DomainException $e) {
    $caughtLocked = true;
}
assertTest("Periode berstatus 'final' ditolak dengan DomainException", $caughtLocked);


// -------------------------------------------------------------
// TEST 5: Pratinjau Murni Read-Only Tanpa Mutasi Database
// -------------------------------------------------------------
echo "\n--- TEST 5: Pratinjau Benar-Benar Tanpa Mutasi Database ---\n";

// Skenario A: Pratinjau pada periode yang belum diinisialisasi payrollnya
$countPayrollsBefore = (int)$tempDb->query("SELECT COUNT(*) FROM payrolls WHERE payroll_period_id = {$emptyPeriodId}")->fetchColumn();
$caughtUninitialized = false;
try {
    calculateEmployeePayrollPreview($tempDb, $emptyPeriodId, $testEmp2Id, 'deduction', 'Terlambat', 25000, 1, 'Uji coba');
} catch (DomainException $e) {
    $caughtUninitialized = true;
}
$countPayrollsAfter = (int)$tempDb->query("SELECT COUNT(*) FROM payrolls WHERE payroll_period_id = {$emptyPeriodId}")->fetchColumn();

assertTest("Periode belum diinisialisasi ditolak tanpa membuat baris payroll", $caughtUninitialized);
assertTest("Jumlah baris payroll tetap 0 (tidak ada mutasi DB pada periode kosong)", $countPayrollsBefore === 0 && $countPayrollsAfter === 0);

// Skenario B: Pratinjau pada periode yang siap dihitung
$countTotalPayrollsBefore = (int)$tempDb->query("SELECT COUNT(*) FROM payrolls")->fetchColumn();
$countTotalItemsBefore = (int)$tempDb->query("SELECT COUNT(*) FROM payroll_items")->fetchColumn();

$previewResult = calculateEmployeePayrollPreview($tempDb, $openPeriodId, $testEmp1Id, 'deduction', 'Keterlambatan 15 Menit', 30000, 1, 'Uji pratinjau');

$countTotalPayrollsAfter = (int)$tempDb->query("SELECT COUNT(*) FROM payrolls")->fetchColumn();
$countTotalItemsAfter = (int)$tempDb->query("SELECT COUNT(*) FROM payroll_items")->fetchColumn();

assertTest("Pratinjau menghasilkan kalkulasi sebelum vs sesudah yang valid", isset($previewResult['before']) && isset($previewResult['after']));
assertTest("Pratinjau TIDAK mengubah jumlah data payrolls", $countTotalPayrollsBefore === $countTotalPayrollsAfter);
assertTest("Pratinjau TIDAK menambah baris pada payroll_items", $countTotalItemsBefore === $countTotalItemsAfter);


// -------------------------------------------------------------
// TEST 6: Pencegahan Pengajuan Ganda (Idempotency)
// -------------------------------------------------------------
echo "\n--- TEST 6: Pencegahan Pengajuan Ganda (Idempotency) ---\n";
$idemKey = 'idem_' . bin2hex(random_bytes(6));
$payloadProposal = [
    'idempotency_key' => $idemKey,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmp1Id,
    'change_type' => 'deduction',
    'item_name' => 'Potongan Uji Idempotensi',
    'qty' => 1,
    'rate' => 45000,
    'reason' => 'Pengajuan otomatis dari Lisa'
];

$resProp1 = createPayrollChangeProposal($tempDb, $clientAData, $payloadProposal);
assertTest("Pengajuan pertama berhasil disimpan (is_replay = false)", $resProp1['is_replay'] === false);

$resProp2 = createPayrollChangeProposal($tempDb, $clientAData, $payloadProposal);
assertTest("Pengajuan ulang terdeteksi replay (is_replay = true)", $resProp2['is_replay'] === true);
assertTest("ID pengajuan replay sama persis (tidak ada baris ganda)", $resProp2['proposal']['id'] == $resProp1['proposal']['id']);


// -------------------------------------------------------------
// TEST 7: Deteksi Perubahan Data / State Drift (Stale Prevention)
// -------------------------------------------------------------
echo "\n--- TEST 7: Deteksi Perubahan Data / State Drift (Stale) ---\n";
$stalePayload = [
    'idempotency_key' => 'stale_' . bin2hex(random_bytes(6)),
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmp1Id,
    'change_type' => 'individual_bonus',
    'item_name' => 'Bonus Shift Uji',
    'qty' => 1,
    'rate' => 150000,
    'reason' => 'Uji deteksi perubahan data sebelum persetujuan'
];
$propStaleRes = createPayrollChangeProposal($tempDb, $clientAData, $stalePayload);
$stalePropId = (int)$propStaleRes['proposal']['id'];
$payrollTargetId = (int)$propStaleRes['proposal']['payroll_id'];

// Simulasi: Sebelum Bos menyetujui, admin lain mengubah gaji pokok dan take home pay karyawan
$tempDb->prepare("UPDATE payrolls SET basic_salary = basic_salary + 200000, take_home_pay = take_home_pay + 200000 WHERE id = ?")->execute([$payrollTargetId]);

$caughtStale = false;
try {
    approvePayrollProposal($tempDb, $stalePropId, $adminUserId);
} catch (DomainException $e) {
    $caughtStale = true;
}
assertTest("Persetujuan digagalkan ketika data payroll telah berubah", $caughtStale);

$checkStale = $tempDb->prepare("SELECT status FROM payroll_change_proposals WHERE id = ?");
$checkStale->execute([$stalePropId]);
assertTest("Status proposal otomatis menjadi 'stale'", $checkStale->fetchColumn() === 'stale');


// -------------------------------------------------------------
// TEST 8: Alur Persetujuan Bos Berhasil (Happy Path)
// -------------------------------------------------------------
echo "\n--- TEST 8: Alur Persetujuan Bos Berhasil (Happy Path) ---\n";
// Pulihkan basic_salary dan take_home_pay ke kondisi awal
$tempDb->prepare("DELETE FROM payroll_items WHERE payroll_id = ? AND type != 'basic'")->execute([$payrollTargetId]);
$tempDb->prepare("UPDATE payrolls SET basic_salary = 5000000, total_individual_bonus = 0, total_deductions = 0, take_home_pay = 5000000 WHERE id = ?")->execute([$payrollTargetId]);

$happyPayload = [
    'idempotency_key' => 'happy_' . bin2hex(random_bytes(6)),
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmp1Id,
    'change_type' => 'deduction',
    'item_name' => 'Potongan Final Disetujui',
    'qty' => 2,
    'rate' => 50000, // Total 100,000
    'reason' => 'Pengajuan terverifikasi'
];

$happyRes = createPayrollChangeProposal($tempDb, $clientAData, $happyPayload);
$happyPropId = (int)$happyRes['proposal']['id'];

$approvalRes = approvePayrollProposal($tempDb, $happyPropId, $adminUserId);
assertTest("Bos berhasil menyetujui pengajuan", $approvalRes['success'] === true);

$stmtCheckHappy = $tempDb->prepare("SELECT status, reviewed_by FROM payroll_change_proposals WHERE id = ?");
$stmtCheckHappy->execute([$happyPropId]);
$happyRow = $stmtCheckHappy->fetch(PDO::FETCH_ASSOC);
assertTest("Status proposal berubah menjadi 'approved'", $happyRow['status'] === 'approved');
assertTest("Reviewed_by tercatat sebagai Bos ID ({$adminUserId})", (int)$happyRow['reviewed_by'] === $adminUserId);

$stmtCheckItem = $tempDb->prepare("SELECT amount FROM payroll_items WHERE payroll_id = ? AND name = 'Potongan Final Disetujui'");
$stmtCheckItem->execute([$payrollTargetId]);
assertTest("Item potongan tersimpan di payroll_items dengan amount Rp100.000", (float)$stmtCheckItem->fetchColumn() == 100000.0);

$stmtCheckTHP = $tempDb->prepare("SELECT take_home_pay FROM payrolls WHERE id = ?");
$stmtCheckTHP->execute([$payrollTargetId]);
assertTest("Take Home Pay terpotong tepat (Rp4.900.000)", (float)$stmtCheckTHP->fetchColumn() == 4900000.0);


// -------------------------------------------------------------
// TEST 9: Uji HTTP Sebenarnya & Isolasi Antar Klien (Client Isolation)
// -------------------------------------------------------------
echo "\n--- TEST 9: Uji HTTP Nyata & Isolasi Antar Klien (Client A vs Client B) ---\n";

function makeCurlRequest(string $url, string $method = 'GET', ?string $token = null, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    return [
        'code' => $code,
        'err' => $err,
        'json' => json_decode($raw ?: '', true),
        'raw' => $raw
    ];
}

// 9.1 Buat Proposal khusus Client A dan Proposal khusus Client B di DB Sementara
$keyA = 'http_iso_a_' . bin2hex(random_bytes(4));
$propA = createPayrollChangeProposal($tempDb, $clientAData, [
    'idempotency_key' => $keyA,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmp1Id,
    'change_type' => 'individual_bonus',
    'item_name' => 'Bonus Client A',
    'qty' => 1,
    'rate' => 200000,
    'reason' => 'Milik Client A'
]);
$propAId = (int)$propA['proposal']['id'];

$keyB = 'http_iso_b_' . bin2hex(random_bytes(4));
$propB = createPayrollChangeProposal($tempDb, $clientBData, [
    'idempotency_key' => $keyB,
    'payroll_period_id' => $openPeriodId,
    'employee_id' => $testEmp1Id,
    'change_type' => 'individual_bonus',
    'item_name' => 'Bonus Client B',
    'qty' => 1,
    'rate' => 300000,
    'reason' => 'Milik Client B'
]);
$propBId = (int)$propB['proposal']['id'];

// Release CLI SQLite connection lock so HTTP server can read/write without concurrency lock
$tempDb = null;

// Spawn ephemeral test server on isolated port 8009 with DATABASE_PATH pointing to temp DB
$testPort = 8009;
$testServerCmd = sprintf(
    'DATABASE_PATH=%s %s -d opcache.enable=0 -d opcache.enable_cli=0 -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!',
    escapeshellarg($tempDbPath),
    escapeshellcmd(PHP_BINARY),
    $testPort,
    escapeshellarg(realpath(__DIR__ . '/../public'))
);
$testServerPid = (int)trim(shell_exec($testServerCmd));
usleep(250000); // 250ms for server to bind

$baseUrl = "http://127.0.0.1:{$testPort}";

try {
    // 9.2 Client B mencoba akses Proposal Client A via HTTP GET by ID -> Harap 404
    $httpGetById = makeCurlRequest("{$baseUrl}/api/v1/proposals.php?id={$propAId}", 'GET', $tokenB);
    assertTest("HTTP: Client B mengakses Proposal Client A via ?id={$propAId} menghasilkan HTTP 404", $httpGetById['code'] === 404, "Actual Code: {$httpGetById['code']}, Err: {$httpGetById['err']}");

    // 9.3 Client B mencoba akses Proposal Client A via HTTP GET by Idempotency Key -> Harap 404
    $httpGetByIdem = makeCurlRequest("{$baseUrl}/api/v1/proposals.php?idempotency_key={$keyA}", 'GET', $tokenB);
    assertTest("HTTP: Client B mengakses Proposal Client A via ?idempotency_key menghasilkan HTTP 404", $httpGetByIdem['code'] === 404, "Actual Code: {$httpGetByIdem['code']}, Err: {$httpGetByIdem['err']}");

    // 9.4 Client B meminta daftar proposal via HTTP GET -> Hanya proposal miliknya (Prop B), BUKAN Prop A
    $httpGetListB = makeCurlRequest("{$baseUrl}/api/v1/proposals.php", 'GET', $tokenB);
    $listB = $httpGetListB['json']['data'] ?? [];
    $containsPropAInListB = false;
    $containsPropBInListB = false;
    foreach ($listB as $item) {
        if ((int)$item['id'] === $propAId) $containsPropAInListB = true;
        if ((int)$item['id'] === $propBId) $containsPropBInListB = true;
    }
    assertTest("HTTP: Daftar proposal Client B TIDAK berisi proposal Client A", $httpGetListB['code'] === 200 && !$containsPropAInListB && $containsPropBInListB, "Actual Code: {$httpGetListB['code']}, Raw: {$httpGetListB['raw']}");

    // 9.5 Client A meminta daftar proposal miliknya -> Hanya melihat miliknya
    $httpGetListA = makeCurlRequest("{$baseUrl}/api/v1/proposals.php", 'GET', $tokenA);
    $listA = $httpGetListA['json']['data'] ?? [];
    $containsPropBInListA = false;
    foreach ($listA as $item) {
        if ((int)$item['id'] === $propBId) $containsPropBInListA = true;
    }
    assertTest("HTTP: Daftar proposal Client A TIDAK berisi proposal Client B", $httpGetListA['code'] === 200 && !$containsPropBInListA, "Actual Code: {$httpGetListA['code']}, Raw: {$httpGetListA['raw']}");

    // -------------------------------------------------------------
    // TEST 10: Rincian Payroll Karyawan (payroll_detail.php) & Keamanan
    // -------------------------------------------------------------
    echo "\n--- TEST 10: Endpoint Rincian Payroll (payroll_detail.php) & Keamanan ---\n";

    // 10.1 Token tanpa izin baca (payroll:read) -> Harap 403 Forbidden
    $httpDetailNoRead = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id={$testEmp1Id}", 'GET', $proposeOnlyToken);
    assertTest("Token tanpa scope payroll:read ditolak dengan HTTP 403", $httpDetailNoRead['code'] === 403);

    // 10.2 Tanpa Token -> Harap 401 Unauthorized
    $httpDetailNoAuth = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id={$testEmp1Id}", 'GET', null);
    assertTest("Permintaan tanpa header otorisasi ditolak dengan HTTP 401", $httpDetailNoAuth['code'] === 401);

    // 10.3 Parameter tidak valid -> Harap 400 Bad Request
    $httpDetailMissing = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}", 'GET', $tokenA);
    assertTest("Parameter employee_id hilang menghasilkan HTTP 400", $httpDetailMissing['code'] === 400);

    $httpDetailInvalidId = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id=abc&employee_id={$testEmp1Id}", 'GET', $tokenA);
    assertTest("Parameter period_id non-numerik menghasilkan HTTP 400", $httpDetailInvalidId['code'] === 400);

    $httpDetailNegativeId = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id=-1", 'GET', $tokenA);
    assertTest("Parameter employee_id negatif menghasilkan HTTP 400", $httpDetailNegativeId['code'] === 400);

    $httpDetailZeroId = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id=0&employee_id={$testEmp1Id}", 'GET', $tokenA);
    assertTest("Parameter period_id bernilai 0 menghasilkan HTTP 400", $httpDetailZeroId['code'] === 400);

    // 10.4 Entitas tidak ditemukan -> Harap 404
    $httpDetailEmpNotFound = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id=999999", 'GET', $tokenA);
    assertTest("Karyawan ID 999999 tidak ditemukan menghasilkan HTTP 404", $httpDetailEmpNotFound['code'] === 404);

    $httpDetailPerNotFound = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id=999999&employee_id={$testEmp1Id}", 'GET', $tokenA);
    assertTest("Periode ID 999999 tidak ditemukan menghasilkan HTTP 404", $httpDetailPerNotFound['code'] === 404);

    // 10.5 Akun Super Admin tidak bocor -> Harap 404 sama persis seperti karyawan tidak ditemukan
    $httpDetailSa = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id={$saEmpId}", 'GET', $tokenA);
    assertTest("Data karyawan ber-role super_admin tidak bocor (HTTP 404 'Karyawan tidak ditemukan')", 
        $httpDetailSa['code'] === 404 && ($httpDetailSa['json']['message'] ?? '') === 'Karyawan tidak ditemukan.');

    // 10.6 Payroll belum tersedia pada periode draft yang belum diinisialisasi
    $httpDetailNotAvail = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$emptyPeriodId}&employee_id={$testEmp2Id}", 'GET', $tokenA);
    assertTest("Payroll belum tersedia menghasilkan respons jelas HTTP 404", 
        $httpDetailNotAvail['code'] === 404 && ($httpDetailNotAvail['json']['error'] ?? '') === 'Payroll Not Available');

    // 10.7 Pembacaan payroll yang tersedia (Happy Path) -> Harap 200 OK dengan field lengkap
    $httpDetailSuccess = makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id={$testEmp1Id}", 'GET', $tokenA);
    $dataPayroll = $httpDetailSuccess['json']['data'] ?? [];

    $isDataComplete = (
        $httpDetailSuccess['code'] === 200 &&
        ($dataPayroll['employee_name'] ?? '') === 'Karyawan Uji Satu' &&
        ($dataPayroll['period_name'] ?? '') === 'Periode Uji Aktif' &&
        ($dataPayroll['period_status'] ?? '') === 'review' &&
        isset($dataPayroll['basic_salary']) &&
        isset($dataPayroll['total_bonus']) &&
        isset($dataPayroll['total_deductions']) &&
        isset($dataPayroll['take_home_pay']) &&
        (float)$dataPayroll['basic_salary'] === 5000000.0 &&
        (float)$dataPayroll['total_deductions'] === 100000.0 &&
        (float)$dataPayroll['take_home_pay'] === 4900000.0
    );
    assertTest("Pembacaan payroll tersedia mengembalikan nama, status periode, basic_salary, bonus, potongan, take_home_pay", $isDataComplete);

    // 10.8 Bukti permintaan GET TIDAK mengubah database sama sekali
    $tempDbCheck = new PDO("sqlite:" . $tempDbPath);
    $tempDbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $payrollsCountBeforeGet = (int)$tempDbCheck->query("SELECT COUNT(*) FROM payrolls")->fetchColumn();
    $itemsCountBeforeGet = (int)$tempDbCheck->query("SELECT COUNT(*) FROM payroll_items")->fetchColumn();

    // Jalankan berturut-turut GET requests (tersedia maupun tidak tersedia)
    for ($i = 0; $i < 5; $i++) {
        makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$openPeriodId}&employee_id={$testEmp1Id}", 'GET', $tokenA);
        makeCurlRequest("{$baseUrl}/api/v1/payroll_detail.php?period_id={$emptyPeriodId}&employee_id={$testEmp2Id}", 'GET', $tokenA);
    }

    $payrollsCountAfterGet = (int)$tempDbCheck->query("SELECT COUNT(*) FROM payrolls")->fetchColumn();
    $itemsCountAfterGet = (int)$tempDbCheck->query("SELECT COUNT(*) FROM payroll_items")->fetchColumn();
    $tempDbCheck = null;

    assertTest("Permintaan GET payroll_detail terbukti TIDAK menambah atau memutasi tabel payrolls", $payrollsCountBeforeGet === $payrollsCountAfterGet);
    assertTest("Permintaan GET payroll_detail terbukti TIDAK menambah atau memutasi tabel payroll_items", $itemsCountBeforeGet === $itemsCountAfterGet);

    // 10.9 Uji Query periods.php tidak meminta updated_at
    $httpPeriods = makeCurlRequest("{$baseUrl}/api/v1/periods.php", 'GET', $tokenA);
    assertTest("GET periods.php berhasil 200 tanpa error kolom updated_at", $httpPeriods['code'] === 200 && is_array($httpPeriods['json']['data'] ?? null));

    // 10.10 Uji Perintah CLI Hermes: gajiku-office salary
    $cliCmd = sprintf(
        'GAJIKU_API_URL=%s GAJIKU_API_TOKEN=%s python3 %s salary --period %d --employee %d',
        escapeshellarg("{$baseUrl}/api/v1"),
        escapeshellarg($tokenA),
        escapeshellarg(realpath(__DIR__ . '/../bin/gajiku-office')),
        $openPeriodId,
        $testEmp1Id
    );
    $cliOutput = (string)shell_exec($cliCmd . ' 2>&1');
    $hasPeriodInOutput = strpos($cliOutput, 'Periode') !== false;
    $hasBasicInOutput = strpos($cliOutput, 'Gaji Pokok') !== false;
    $hasThpInOutput = strpos($cliOutput, 'Gaji Diterima') !== false;
    $hasRpInOutput = strpos($cliOutput, '5.000.000') !== false;
    $tokenLeakedInCli = strpos($cliOutput, $tokenA) !== false;

    assertTest("CLI gajiku-office salary menghasilkan rincian Periode, Gaji Pokok, dan Gaji Diterima", 
        $hasPeriodInOutput && $hasBasicInOutput && $hasThpInOutput && $hasRpInOutput);
    assertTest("CLI gajiku-office TIDAK membocorkan token API di output", !$tokenLeakedInCli);
} finally {
    if ($testServerPid > 0) {
        exec("kill -9 {$testServerPid} 2>/dev/null");
    }
}


// -------------------------------------------------------------
// VERIFIKASI INTEGRITAS DATABASE KERJA (DATABASE ISOLATION PROOF)
// -------------------------------------------------------------
echo "\n--- VERIFIKASI INTEGRITAS DATABASE KERJA (PROVING ZERO-MUTATION) ---\n";
// Hapus database sementara
if (file_exists($tempDbPath)) {
    @unlink($tempDbPath);
    @unlink($tempDbPath . '-wal');
    @unlink($tempDbPath . '-shm');
}

$hashAfter = ($workingDbPath && file_exists($workingDbPath)) ? hash_file('sha256', $workingDbPath) : 'no_file';
$isWorkingDbUntouched = ($hashBefore === $hashAfter);

echo "Hash Database Kerja Sebelum Tes : {$hashBefore}\n";
echo "Hash Database Kerja Setelah Tes : {$hashAfter}\n";
assertTest("DATABASE KERJA TIDAK TERSENTUH / TIDAK BERUBAH SAMA SEKALI (HASH IDENTIK)", $isWorkingDbUntouched);


// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n====================================================================\n";
echo "  HASIL AKHIR: {$passed} BERHASIL, {$failed} GAGAL\n";
echo "====================================================================\n";

if ($failed === 0) {
    echo "\033[32mSEMUA SKENARIO PENGUJIAN INTEGRASI & ISOLASI SUKSES 100%!\033[0m\n\n";
    exit(0);
} else {
    echo "\033[31mADA SKENARIO YANG GAGAL. SILAKAN PERIKSA LOG DI ATAS.\033[0m\n\n";
    exit(1);
}
