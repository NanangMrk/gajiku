<?php
// public/api/v1/payroll_detail.php — Read-only endpoint to get payroll details for one employee in one period

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Enforce GET method only
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, [
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Hanya metode GET yang didukung pada endpoint ini.'
    ]);
}

// 2. Authenticate API client with required scope 'payroll:read'
$client = authenticateApiClient('payroll:read');

// 3. Validate query parameters: period_id and employee_id must be positive integers
$rawPeriodId = $_GET['period_id'] ?? null;
$rawEmployeeId = $_GET['employee_id'] ?? null;

$isPeriodValid = ($rawPeriodId !== null) && 
    filter_var($rawPeriodId, FILTER_VALIDATE_INT) !== false && 
    ((int)$rawPeriodId > 0);

$isEmployeeValid = ($rawEmployeeId !== null) && 
    filter_var($rawEmployeeId, FILTER_VALIDATE_INT) !== false && 
    ((int)$rawEmployeeId > 0);

if (!$isPeriodValid || !$isEmployeeValid) {
    sendJsonResponse(400, [
        'success' => false,
        'error' => 'Bad Request',
        'message' => 'Parameter period_id dan employee_id wajib disertakan dan harus berupa bilangan bulat positif.'
    ]);
}

$periodId = (int)$rawPeriodId;
$employeeId = (int)$rawEmployeeId;

try {
    $db = getDbConnection();

    // 4. Verify employee exists and is NOT a super_admin (aligning with employees.php restriction)
    $stmtEmp = $db->prepare("
        SELECT 
            e.id, 
            e.nik, 
            e.full_name, 
            e.position, 
            e.status,
            d.id as division_id,
            d.name as division_name,
            t.id as team_id,
            t.name as team_name,
            r.slug as role_slug
        FROM employees e
        LEFT JOIN divisions d ON e.division_id = d.id
        LEFT JOIN teams t ON e.team_id = t.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE e.id = ?
    ");
    $stmtEmp->execute([$employeeId]);
    $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    // If employee not found OR role is super_admin, return 404 (prevents leaking super_admin existence)
    if (!$employee || ($employee['role_slug'] === 'super_admin')) {
        sendJsonResponse(404, [
            'success' => false,
            'error' => 'Not Found',
            'message' => 'Karyawan tidak ditemukan.'
        ]);
    }

    // 5. Verify payroll period exists
    $stmtPeriod = $db->prepare("
        SELECT id, name, start_date, end_date, payment_date, status, created_at
        FROM payroll_periods
        WHERE id = ?
    ");
    $stmtPeriod->execute([$periodId]);
    $period = $stmtPeriod->fetch(PDO::FETCH_ASSOC);

    if (!$period) {
        sendJsonResponse(404, [
            'success' => false,
            'error' => 'Not Found',
            'message' => 'Periode payroll tidak ditemukan.'
        ]);
    }

    // 6. Fetch existing payroll snapshot (STRICTLY READ-ONLY - ZERO MUTATION)
    $stmtPayroll = $db->prepare("
        SELECT 
            id,
            payroll_period_id,
            employee_id,
            nik_snapshot,
            employee_name_snapshot,
            division_snapshot,
            team_snapshot,
            position_snapshot,
            basic_salary,
            total_individual_bonus,
            total_team_bonus,
            total_adjustments,
            total_deductions,
            take_home_pay,
            payslip_number,
            created_at
        FROM payrolls
        WHERE payroll_period_id = ? AND employee_id = ?
    ");
    $stmtPayroll->execute([$periodId, $employeeId]);
    $payroll = $stmtPayroll->fetch(PDO::FETCH_ASSOC);

    if (!$payroll) {
        sendJsonResponse(404, [
            'success' => false,
            'error' => 'Payroll Not Available',
            'message' => "Data payroll untuk karyawan '{$employee['full_name']}' pada periode '{$period['name']}' belum tersedia atau belum digenerate."
        ]);
    }

    $basicSalary = (float)$payroll['basic_salary'];
    $indivBonus = (float)$payroll['total_individual_bonus'];
    $teamBonus = (float)$payroll['total_team_bonus'];
    $totalBonus = $indivBonus + $teamBonus;
    $totalDeductions = (float)$payroll['total_deductions'];
    $takeHomePay = (float)$payroll['take_home_pay'];

    sendJsonResponse(200, [
        'success' => true,
        'client' => [
            'id' => $client['id'],
            'name' => $client['name']
        ],
        'data' => [
            'payroll_id' => (int)$payroll['id'],
            'employee_id' => (int)$employee['id'],
            'employee_name' => $employee['full_name'],
            'nik' => $employee['nik'],
            'position' => $employee['position'],
            'division' => $employee['division_name'],
            'team' => $employee['team_name'],
            'period_id' => (int)$period['id'],
            'period_name' => $period['name'],
            'period_status' => $period['status'],
            'basic_salary' => $basicSalary,
            'total_individual_bonus' => $indivBonus,
            'total_team_bonus' => $teamBonus,
            'total_bonus' => $totalBonus,
            'total_deductions' => $totalDeductions,
            'take_home_pay' => $takeHomePay,
            'currency' => 'IDR',
            'formatted' => [
                'basic_salary' => 'Rp ' . number_format($basicSalary, 0, ',', '.'),
                'total_bonus' => 'Rp ' . number_format($totalBonus, 0, ',', '.'),
                'total_deductions' => 'Rp ' . number_format($totalDeductions, 0, ',', '.'),
                'take_home_pay' => 'Rp ' . number_format($takeHomePay, 0, ',', '.')
            ]
        ]
    ]);
} catch (\Throwable $e) {
    error_log("[API Payroll Detail Error] " . $e->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => 'Terjadi kesalahan pada server saat memproses rincian payroll.'
    ]);
}
