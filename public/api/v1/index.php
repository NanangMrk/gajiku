<?php
// public/api/v1/index.php — API v1 Root & Dispatcher for GAJIKU Integration

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

// Clean up trailing slash
$uri = rtrim($uri, '/');

// Subpath routing if accessed via /api/v1/...
if (preg_match('#/api/v1/employees$#', $uri)) {
    require __DIR__ . '/employees.php';
    exit;
} elseif (preg_match('#/api/v1/periods$#', $uri)) {
    require __DIR__ . '/periods.php';
    exit;
} elseif (preg_match('#/api/v1/(payroll[-_])?detail(\.php)?$#', $uri) || preg_match('#/api/v1/payroll_detail$#', $uri)) {
    require __DIR__ . '/payroll_detail.php';
    exit;
} elseif (preg_match('#/api/v1/(payroll/)?preview$#', $uri)) {
    require __DIR__ . '/preview.php';
    exit;
} elseif (preg_match('#/api/v1/(payroll/)?proposals$#', $uri)) {
    require __DIR__ . '/proposals.php';
    exit;
}

// Default response: API v1 status and directory
sendJsonResponse(200, [
    'service' => 'GAJIKU Integration API',
    'version' => 'v1',
    'status' => 'operational',
    'authenticated_client' => getBearerToken() ? true : false,
    'endpoints' => [
        'GET  /api/v1/employees.php' => 'Daftar data karyawan aktif (scope: payroll:read)',
        'GET  /api/v1/periods.php' => 'Daftar periode payroll dan statusnya (scope: payroll:read)',
        'GET  /api/v1/payroll_detail.php?period_id=<ID>&employee_id=<ID>' => 'Rincian nominal payroll karyawan pada satu periode (scope: payroll:read)',
        'POST /api/v1/preview.php' => 'Kalkulasi pratinjau perubahan potongan/bonus (scope: payroll:propose)',
        'POST /api/v1/proposals.php' => 'Kirim pengajuan perubahan payroll ber-idempotency (scope: payroll:propose)',
        'GET  /api/v1/proposals.php' => 'Cek status pengajuan perubahan payroll (scope: payroll:read)'
    ],
    'documentation' => 'Akses terbatas untuk klien eksternal berlisensi (Lisa / Hermes Agent). Persetujuan dilakukan secara manual oleh Bos di GAJIKU Web.'
]);
