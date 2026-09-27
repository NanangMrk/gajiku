<?php
// public/api/v1/periods.php — List payroll periods for Lisa / Integration clients

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, [
        'success' => false,
        'error' => 'Method Not Allowed',
        'message' => 'Hanya metode GET yang didukung pada endpoint ini.'
    ]);
}

// Authenticate API client with scope 'payroll:read'
$client = authenticateApiClient('payroll:read');

try {
    $db = getDbConnection();

    $statusFilter = trim($_GET['status'] ?? '');

    $sql = "
        SELECT 
            id,
            name,
            start_date,
            end_date,
            payment_date,
            status,
            created_at
        FROM payroll_periods
    ";

    $params = [];
    if (!empty($statusFilter)) {
        $sql .= " WHERE status = ?";
        $params[] = $statusFilter;
    }

    $sql .= " ORDER BY start_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rawPeriods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $periods = array_map(function($p) {
        $isEditable = in_array($p['status'], ['draft', 'review']);
        return [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'start_date' => $p['start_date'],
            'end_date' => $p['end_date'],
            'payment_date' => $p['payment_date'],
            'status' => $p['status'],
            'is_editable' => $isEditable,
            'can_accept_proposals' => $isEditable,
            'created_at' => $p['created_at']
        ];
    }, $rawPeriods);

    sendJsonResponse(200, [
        'success' => true,
        'client' => [
            'id' => $client['id'],
            'name' => $client['name']
        ],
        'total' => count($periods),
        'data' => $periods
    ]);
} catch (\Throwable $e) {
    error_log("[API Periods Error] " . $e->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => 'Terjadi kesalahan pada server saat memproses data periode payroll.'
    ]);
}
