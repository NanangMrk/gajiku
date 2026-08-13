<?php
// app/Config/app.php — Core application configuration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Application Details
define('APP_NAME', 'Gajiku - Payroll & Task Management');
define('APP_VERSION', '1.0.0');

// Directory paths (ROOT_PATH = project root, 2 levels up from app/Config/)
define('ROOT_PATH', dirname(__DIR__, 2));
define('DATABASE_PATH', ROOT_PATH . '/database/payroll.sqlite');
define('STORAGE_PATH', ROOT_PATH . '/storage');

// Robust Base URL detection matching document root
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';

$docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$publicPath = realpath(ROOT_PATH . '/public');

$urlPath = '';
if ($docRoot && $publicPath && strpos($publicPath, $docRoot) === 0) {
    $urlPath = str_replace('\\', '/', substr($publicPath, strlen($docRoot)));
}

define('BASE_URL', rtrim($protocol . '://' . $host . $urlPath, '/'));
