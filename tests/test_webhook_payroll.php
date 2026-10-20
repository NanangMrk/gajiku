<?php
// tests/test_webhook_payroll.php — Test Suite for Payroll Webhook & Cronjob Automation

$tempDbPath = realpath(__DIR__ . '/../storage') . '/cron_test_' . bin2hex(random_bytes(6)) . '.sqlite';
if (file_exists($tempDbPath)) {
    @unlink($tempDbPath);
}

putenv("DATABASE_PATH={$tempDbPath}");
putenv("GAJIKU_DATABASE_PATH={$tempDbPath}");

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/api_auth.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

echo "====================================================================\n";
echo "  GAJIKU — Test Suite: Payroll Cronjob & Webhook Automation\n";
echo "====================================================================\n";

$passed = 0;
$failed = 0;

function assertCronTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo " [\033[32mPASS\033[0m] " . $description . "\n";
    } else {
        $failed++;
        echo " [\033[31mFAIL\033[0m] " . $description . ($detail ? " ($detail)" : "") . "\n";
    }
}

// 1. Initialize DB schema
$db = getDbConnection();
$schemaSql = file_get_contents(__DIR__ . '/../database/migrate.php');
// Run migrations
require __DIR__ . '/../database/migrate.php';
require __DIR__ . '/../database/seed.php';

// Test 1: Webhook Secret Token Generation
$secret1 = getOrCreateWebhookSecret($db);
assertCronTest("getOrCreateWebhookSecret menghasilkan token berawalan 'gjk_cron_'", str_starts_with($secret1, 'gjk_cron_'), $secret1);

$secretStored = getOrCreateWebhookSecret($db);
assertCronTest("getOrCreateWebhookSecret idempoten (mengembalikan token yang sama jika sudah ada)", $secret1 === $secretStored);

$secret2 = regenerateWebhookSecret($db);
assertCronTest("regenerateWebhookSecret menghasilkan token baru yang berbeda", $secret1 !== $secret2 && str_starts_with($secret2, 'gjk_cron_'));

// Test 2: Workflow Dry Run
$dryRunRes = runPayrollCronWorkflow($db, [
    'action' => 'auto',
    'month' => 10,
    'year' => 2026,
    'dry_run' => true,
    'triggered_by' => 'Unit Test'
]);
assertCronTest("Dry run berhasil dieksekusi", $dryRunRes['success'] === true && $dryRunRes['dry_run'] === true);
assertCronTest("Dry run mendeteksi pembuatan periode baru", $dryRunRes['period']['was_newly_created'] === true);

// Verify that dry run did NOT actually persist the period
$stmtCheckPeriod = $db->prepare("SELECT COUNT(*) FROM payroll_periods WHERE name = 'Oktober 2026'");
$stmtCheckPeriod->execute();
assertCronTest("Dry run terbukti me-rollback transaksi (tidak ada data tersimpan di DB)", (int)$stmtCheckPeriod->fetchColumn() === 0);

// Test 3: Live Workflow Execution
$liveRes = runPayrollCronWorkflow($db, [
    'action' => 'auto',
    'month' => 10,
    'year' => 2026,
    'dry_run' => false,
    'triggered_by' => 'Unit Test Live'
]);
assertCronTest("Live execution berhasil dieksekusi", $liveRes['success'] === true && $liveRes['dry_run'] === false);
assertCronTest("Periode baru 'Oktober 2026' tercatat dibuat", $liveRes['period']['name'] === 'Oktober 2026');

// Verify DB persistence
$stmtCheckPeriod->execute();
assertCronTest("Periode 'Oktober 2026' tersimpan di database", (int)$stmtCheckPeriod->fetchColumn() === 1);

// Test 4: Idempotent re-run on existing period
$rerunRes = runPayrollCronWorkflow($db, [
    'action' => 'auto',
    'month' => 10,
    'year' => 2026,
    'dry_run' => false,
    'triggered_by' => 'Unit Test Rerun'
]);
assertCronTest("Rerun pada bulan yang sama tidak membuat periode ganda", $rerunRes['period']['was_newly_created'] === false);
$stmtCheckPeriod->execute();
assertCronTest("Jumlah periode 'Oktober 2026' tetap tepat 1", (int)$stmtCheckPeriod->fetchColumn() === 1);

// Test 5: Audit Log recorded
$stmtAudit = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = 'CRON_PAYROLL_EXECUTE'");
$stmtAudit->execute();
assertCronTest("Audit log mencatat eksekusi cronjob", (int)$stmtAudit->fetchColumn() > 0);

// Test 6: Finalize action (Dry Run)
$finalizeDry = runPayrollCronWorkflow($db, [
    'action' => 'finalize',
    'month' => 10,
    'year' => 2026,
    'dry_run' => true,
    'triggered_by' => 'Unit Test Finalize Dry'
]);
assertCronTest("Finalize Dry run sukses dan mengembalikan status 'final' di output", $finalizeDry['period']['status'] === 'final');
$statusInDb = $db->query("SELECT status FROM payroll_periods WHERE name = 'Oktober 2026'")->fetchColumn();
assertCronTest("Database tetap dalam status 'review' setelah Finalize Dry run (dirollback)", $statusInDb === 'review');

// Test 7: Finalize action (Live Execution)
$finalizeLive = runPayrollCronWorkflow($db, [
    'action' => 'finalize',
    'month' => 10,
    'year' => 2026,
    'dry_run' => false,
    'triggered_by' => 'Unit Test Finalize Live'
]);
assertCronTest("Finalize Live eksekusi sukses", $finalizeLive['success'] === true);
$statusLiveDb = $db->query("SELECT status FROM payroll_periods WHERE name = 'Oktober 2026'")->fetchColumn();
assertCronTest("Status periode di database berubah permanen menjadi 'final'", $statusLiveDb === 'final');

$payslipCount = (int)$db->query("SELECT COUNT(*) FROM payslips")->fetchColumn();
assertCronTest("Slip gaji (payslips) berhasil diterbitkan secara otomatis", $payslipCount > 0);

// Test 8: Finalize Idempotence (re-calling finalize on already final period)
$finalizeRerun = runPayrollCronWorkflow($db, [
    'action' => 'finalize',
    'month' => 10,
    'year' => 2026,
    'dry_run' => false,
    'triggered_by' => 'Unit Test Finalize Rerun'
]);
assertCronTest("Pemanggilan ulang finalize bersifat idempoten (sukses tanpa error)", $finalizeRerun['success'] === true);

// Clean up
@unlink($tempDbPath);

echo "\n====================================================================\n";
echo "  HASIL PENGUJIAN CRONJOB: {$passed} BERHASIL, {$failed} GAGAL\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
