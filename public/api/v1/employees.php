<?php
// public/api/v1/employees.php — List authorized employees for Lisa / Integration clients

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure HTTP GET method
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

    $q = trim($_GET['q'] ?? '');
    $divisionId = isset($_GET['division_id']) ? (int)$_GET['division_id'] : null;
    $status = trim($_GET['status'] ?? 'active');

    $sql = "
        SELECT 
            e.id, 
            e.nik, 
            e.full_name, 
            e.position, 
            e.status,
            d.id as division_id,
            d.name as division_name,
            t.id as team_id,
            t.name as team_name
        FROM employees e
        LEFT JOIN divisions d ON e.division_id = d.id
        LEFT JOIN teams t ON e.team_id = t.id
        LEFT JOIN users u ON e.user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE (r.slug IS NULL OR r.slug != 'super_admin')
    ";

    $params = [];

    if (!empty($status)) {
        $sql .= " AND e.status = ?";
        $params[] = $status;
    }

    if (!empty($divisionId)) {
        $sql .= " AND e.division_id = ?";
        $params[] = $divisionId;
    }

    if (!empty($q)) {
        $sql .= " AND (e.full_name LIKE ? OR e.nik LIKE ? OR e.position LIKE ?)";
        $searchTerm = "%{$q}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    $sql .= " ORDER BY e.full_name ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJsonResponse(200, [
        'success' => true,
        'client' => [
            'id' => $client['id'],
            'name' => $client['name']
        ],
        'total' => count($employees),
        'data' => $employees
    ]);
} catch (\Throwable $e) {
    error_log("[API Employees Error] " . $e->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => 'Terjadi kesalahan pada server saat memproses data karyawan.'
    ]);
}
