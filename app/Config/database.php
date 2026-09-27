<?php
// app/Config/database.php — PDO SQLite Singleton
require_once __DIR__ . '/app.php';

function getDbConnection(?string $customPath = null, bool $forceNew = false): PDO {
    static $db = null;
    static $lastPath = null;
    
    // Header-based isolation for local test runs (only from localhost and restricted to temp directory)
    $testDbHeader = null;
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
    if (in_array($remoteIp, ['127.0.0.1', '::1', '']) && !empty($_SERVER['HTTP_X_GAJIKU_TEST_DB'])) {
        $candidate = $_SERVER['HTTP_X_GAJIKU_TEST_DB'];
        $cleanCandidate = str_replace('/private/var/', '/var/', str_replace('/private/tmp/', '/tmp/', $candidate));
        $cleanSysTemp = str_replace('/private/var/', '/var/', str_replace('/private/tmp/', '/tmp/', sys_get_temp_dir()));
        if (
            strpos($cleanCandidate, '/tmp/') === 0 || 
            strpos($cleanCandidate, '/var/folders/') === 0 || 
            strpos($cleanCandidate, $cleanSysTemp) === 0
        ) {
            $testDbHeader = $candidate;
        }
    }

    $envPath = getenv('DATABASE_PATH') ?: getenv('GAJIKU_DATABASE_PATH');
    $dbPath = $customPath ?: ($testDbHeader ?: ($envPath ?: DATABASE_PATH));
    
    if ($db === null || $forceNew || $dbPath !== $lastPath) {
        $lastPath = $dbPath;
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        // Enable foreign keys, WAL mode, and busy timeout in SQLite
        $db->exec('PRAGMA foreign_keys = ON;');
        $db->exec('PRAGMA journal_mode = WAL;');
        $db->exec('PRAGMA busy_timeout = 5000;');

        // Dynamic Schema Update for new columns
        try {
            // Check company_settings
            $q = $db->query("PRAGMA table_info(company_settings)");
            $cols = $q->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('payroll_payment_day', $cols)) {
                $db->exec("ALTER TABLE company_settings ADD COLUMN payroll_payment_day INTEGER DEFAULT 25");
            }

            // Check deduction_types
            $q = $db->query("PRAGMA table_info(deduction_types)");
            $cols = $q->fetchAll(PDO::FETCH_COLUMN, 1);
            if (!in_array('division_id', $cols)) {
                $db->exec("ALTER TABLE deduction_types ADD COLUMN division_id INTEGER");
            }
            if (!in_array('unit_name', $cols)) {
                $db->exec("ALTER TABLE deduction_types ADD COLUMN unit_name TEXT DEFAULT 'Bulan'");
            }
        } catch (Exception $e) {
            error_log("Dynamic migration warning: " . $e->getMessage());
        }
    }
    
    return $db;
}
