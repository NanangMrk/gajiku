<?php
// public/api/v1/preview.php — Calculate preview of payroll change without modifying data

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';
require_once __DIR__ . '/../../../app/Helpers/payroll_service.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, [
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Hanya metode POST yang didukung pada endpoint ini.'
    ]);
}

// Authenticate API client with scope 'payroll:propose'
$client = authenticateApiClient('payroll:propose');

// Parse JSON payload
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);

if (!is_array($input)) {
    sendJsonResponse(400, [
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Format payload harus berupa JSON yang valid.'
    ]);
}

$periodId = (int)($input['payroll_period_id'] ?? 0);
$employeeId = (int)($input['employee_id'] ?? 0);
$changeType = trim($input['change_type'] ?? '');
$itemName = trim($input['item_name'] ?? '');
$qty = isset($input['qty']) ? (int)$input['qty'] : 1;
$rate = isset($input['rate']) ? (float)$input['rate'] : 0.0;
$reason = trim($input['reason'] ?? 'Pratinjau perubahan gaji');

if ($periodId <= 0) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => 'payroll_period_id wajib diisi dengan ID periode yang valid.'
    ]);
}

if ($employeeId <= 0) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => 'employee_id wajib diisi dengan ID karyawan yang valid.'
    ]);
}

if (!in_array($changeType, ['deduction', 'individual_bonus'])) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => "change_type harus bernilai 'deduction' atau 'individual_bonus'."
    ]);
}

if (empty($itemName)) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => 'item_name wajib diisi.'
    ]);
}

if ($qty <= 0) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => 'qty harus lebih besar dari 0.'
    ]);
}

try {
    $db = getDbConnection();
    $preview = calculateEmployeePayrollPreview(
        $db,
        $periodId,
        $employeeId,
        $changeType,
        $itemName,
        $rate,
        $qty,
        $reason
    );

    sendJsonResponse(200, [
        'success' => true,
        'message' => 'Pratinjau perhitungan payroll berhasil.',
        'data' => $preview
    ]);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(404, [
        'success' => false,
        'error' => 'Not Found',
        'message' => $e->getMessage()
    ]);
} catch (DomainException $e) {
    sendJsonResponse(409, [
        'success' => false,
        'error' => 'Conflict / Locked',
        'message' => $e->getMessage()
    ]);
} catch (\Throwable $e) {
    sendJsonResponse(500, [
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
