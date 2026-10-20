<?php
// bin/cron_payroll.php — CLI executable for Gajiku Payroll Cronjob

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya dapat dijalankan melalui terminal CLI.\n");
}

require_once __DIR__ . '/../app/Config/app.php';
require_once __DIR__ . '/../app/Config/database.php';
require_once __DIR__ . '/../app/Helpers/functions.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

function printHelp(): void {
    echo "====================================================================\n";
    echo "  🏢 GAJIKU — Otomasi Payroll Cronjob (CLI)\n";
    echo "====================================================================\n";
    echo "Penggunaan:\n";
    echo "  php bin/cron_payroll.php [opsi]\n\n";
    echo "Opsi yang tersedia:\n";
    echo "  --action=<aksi>       Aksi: auto (default), create_period, sync_rules,\n";
    echo "                        recalculate, finalize\n";
    echo "  --month=<1-12>        Bulan target (default: bulan saat ini)\n";
    echo "  --year=<YYYY>         Tahun target (default: tahun saat ini)\n";
    echo "  --period-id=<ID>      Targetkan spesifik ID periode payroll\n";
    echo "  --dry-run             Simulasi eksekusi tanpa menyimpan ke database\n";
    echo "  --secret              Tampilkan atau generate Webhook Secret Token\n";
    echo "  --regenerate-secret   Buat ulang (regenerate) Webhook Secret Token\n";
    echo "  --help                Tampilkan bantuan ini\n\n";
    echo "Contoh:\n";
    echo "  php bin/cron_payroll.php\n";
    echo "  php bin/cron_payroll.php --dry-run\n";
    echo "  php bin/cron_payroll.php --action=sync_rules --period-id=1\n";
    echo "  php bin/cron_payroll.php --action=create_period --month=11 --year=2026\n";
    echo "====================================================================\n";
}

$db = getDbConnection();

// Parse CLI options
$options = getopt('', [
    'action::',
    'month::',
    'year::',
    'period-id::',
    'dry-run',
    'secret',
    'regenerate-secret',
    'help'
]);

if (isset($options['help'])) {
    printHelp();
    exit(0);
}

if (isset($options['secret'])) {
    $secret = getOrCreateWebhookSecret($db);
    echo "\n[INFO] Webhook Secret Aktif: " . $secret . "\n\n";
    exit(0);
}

if (isset($options['regenerate-secret'])) {
    $secret = regenerateWebhookSecret($db);
    echo "\n[SUCCESS] Webhook Secret Baru Berhasil Dibuat: " . $secret . "\n\n";
    exit(0);
}

$action = $options['action'] ?? 'auto';
$month = isset($options['month']) ? (int)$options['month'] : null;
$year = isset($options['year']) ? (int)$options['year'] : null;
$periodId = isset($options['period-id']) ? (int)$options['period-id'] : null;
$dryRun = isset($options['dry-run']);

echo "\n------------------------------------------------------------\n";
echo "  🏢 GAJIKU — Menjalankan Cronjob Payroll\n";
echo "  Waktu       : " . date('Y-m-d H:i:s') . "\n";
echo "  Aksi        : " . strtoupper($action) . "\n";
echo "  Mode        : " . ($dryRun ? "DRY RUN (Simulasi)" : "LIVE EXECUTION") . "\n";
echo "------------------------------------------------------------\n";

try {
    $result = runPayrollCronWorkflow($db, [
        'action' => $action,
        'month' => $month,
        'year' => $year,
        'period_id' => $periodId,
        'dry_run' => $dryRun,
        'triggered_by' => 'Crontab CLI Runner'
    ]);

    echo "[✓] Status                : Sukses\n";
    echo "[✓] Periode               : " . $result['period']['name'] . " (ID: " . $result['period']['id'] . ", Status: " . $result['period']['status'] . ")\n";
    echo "[✓] Periode Baru Dibuat   : " . ($result['period']['was_newly_created'] ? 'YA' : 'TIDAK (Sudah Ada)') . "\n";
    echo "[✓] Item/Aturan Ditambah  : " . $result['summary']['rules_or_items_added'] . "\n";
    echo "[✓] Jumlah Karyawan       : " . $result['summary']['employee_count'] . " orang\n";
    if (isset($result['summary']['payslips_generated']) && $result['summary']['payslips_generated'] > 0) {
        echo "[✓] Slip Gaji Diterbitkan : " . $result['summary']['payslips_generated'] . " slip\n";
    }
    echo "[✓] Total Take Home Pay   : " . $result['summary']['formatted_total_payroll'] . "\n";
    echo "[✓] Waktu Eksekusi        : " . $result['execution_time_seconds'] . " detik\n";
    echo "[✓] Pesan                 : " . $result['message'] . "\n";
    echo "------------------------------------------------------------\n\n";
    exit(0);
} catch (\Throwable $e) {
    echo "\n[X] ERROR: " . $e->getMessage() . "\n\n";
    exit(1);
}
