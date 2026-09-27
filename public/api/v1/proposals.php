<?php
// public/api/v1/proposals.php — Create and query payroll change proposals

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';
require_once __DIR__ . '/../../../app/Helpers/payroll_service.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
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

    // STRICT PERMISSION CHECK: Lisa/API client cannot approve proposals or finalize
    if (isset($input['status']) && in_array(strtolower($input['status']), ['approved', 'final', 'rejected'])) {
        sendJsonResponse(403, [
            'success' => false,
            'error' => 'Forbidden',
            'message' => 'Klien API eksternal (Lisa) dilarang menyetujui, menolak, atau memfinalisasi pengajuan secara mandiri. Persetujuan hanya dapat dilakukan oleh Bos melalui antarmuka web.'
        ]);
    }

    // Validation
    $periodId = (int)($input['payroll_period_id'] ?? 0);
    $employeeId = (int)($input['employee_id'] ?? 0);
    $changeType = trim($input['change_type'] ?? '');
    $itemName = trim($input['item_name'] ?? '');
    $reason = trim($input['reason'] ?? '');
    $qty = isset($input['qty']) ? (int)$input['qty'] : 1;

    if ($periodId <= 0) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => 'payroll_period_id wajib diisi.']);
    }
    if ($employeeId <= 0) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => 'employee_id wajib diisi.']);
    }
    if (!in_array($changeType, ['deduction', 'individual_bonus'])) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => "change_type harus 'deduction' atau 'individual_bonus'."]);
    }
    if (empty($itemName)) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => 'item_name wajib diisi.']);
    }
    if (empty($reason)) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => 'reason (alasan pengajuan) wajib diisi dengan jelas.']);
    }
    if ($qty <= 0) {
        sendJsonResponse(422, ['success' => false, 'error' => 'Unprocessable Entity', 'message' => 'qty harus lebih besar dari 0.']);
    }

    try {
        $db = getDbConnection();
        $result = createPayrollChangeProposal($db, $client, $input);

        $httpCode = $result['is_replay'] ? 200 : 201;

        sendJsonResponse($httpCode, [
            'success' => true,
            'is_replay' => $result['is_replay'],
            'message' => $result['is_replay'] ? 'Pengajuan terdeteksi berulang (idempotent). Mengembalikan data yang sudah ada.' : 'Pengajuan perubahan payroll berhasil disimpan dan menunggu persetujuan Bos.',
            'data' => [
                'id' => (int)$result['proposal']['id'],
                'client_id' => $result['proposal']['client_id'],
                'proposed_by' => $result['proposal']['proposed_by'],
                'payroll_period_id' => (int)$result['proposal']['payroll_period_id'],
                'employee_id' => (int)$result['proposal']['employee_id'],
                'change_type' => $result['proposal']['change_type'],
                'item_name' => $result['proposal']['item_name'],
                'qty' => (int)$result['proposal']['qty'],
                'rate' => (float)$result['proposal']['rate'],
                'amount' => (float)$result['proposal']['amount'],
                'reason' => $result['proposal']['reason'],
                'status' => $result['proposal']['status'],
                'idempotency_key' => $result['proposal']['idempotency_key'],
                'before_value' => json_decode($result['proposal']['before_value'], true),
                'after_value' => json_decode($result['proposal']['after_value'], true),
                'created_at' => $result['proposal']['created_at']
            ]
        ]);
    } catch (InvalidArgumentException $e) {
        sendJsonResponse(404, ['success' => false, 'error' => 'Not Found', 'message' => $e->getMessage()]);
    } catch (DomainException $e) {
        sendJsonResponse(409, ['success' => false, 'error' => 'Conflict / Locked', 'message' => $e->getMessage()]);
    } catch (\Throwable $e) {
        sendJsonResponse(500, ['success' => false, 'error' => 'Internal Server Error', 'message' => $e->getMessage()]);
    }
} elseif ($method === 'GET') {
    // Authenticate client
    $client = authenticateApiClient('payroll:read');

    try {
        $db = getDbConnection();

        $proposalId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $idempotencyKey = trim($_GET['idempotency_key'] ?? '');

        $clientId = (int)$client['id'];

        if ($proposalId > 0) {
            $stmt = $db->prepare("
                SELECT pcp.*, pp.name as period_name, pp.status as period_status,
                       e.full_name as employee_name, e.nik as employee_nik
                FROM payroll_change_proposals pcp
                JOIN payroll_periods pp ON pcp.payroll_period_id = pp.id
                JOIN employees e ON pcp.employee_id = e.id
                WHERE pcp.id = ? AND pcp.client_id = ?
            ");
            $stmt->execute([$proposalId, $clientId]);
            $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$proposal) {
                sendJsonResponse(404, ['success' => false, 'error' => 'Not Found', 'message' => 'Pengajuan tidak ditemukan.']);
            }

            $proposal['before_value'] = json_decode($proposal['before_value'], true);
            $proposal['after_value'] = json_decode($proposal['after_value'], true);

            sendJsonResponse(200, ['success' => true, 'data' => $proposal]);
        } elseif (!empty($idempotencyKey)) {
            $stmt = $db->prepare("
                SELECT pcp.*, pp.name as period_name, pp.status as period_status,
                       e.full_name as employee_name, e.nik as employee_nik
                FROM payroll_change_proposals pcp
                JOIN payroll_periods pp ON pcp.payroll_period_id = pp.id
                JOIN employees e ON pcp.employee_id = e.id
                WHERE pcp.idempotency_key = ? AND pcp.client_id = ?
            ");
            $stmt->execute([$idempotencyKey, $clientId]);
            $proposal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$proposal) {
                sendJsonResponse(404, ['success' => false, 'error' => 'Not Found', 'message' => 'Pengajuan dengan idempotency_key tersebut tidak ditemukan.']);
            }

            $proposal['before_value'] = json_decode($proposal['before_value'], true);
            $proposal['after_value'] = json_decode($proposal['after_value'], true);

            sendJsonResponse(200, ['success' => true, 'data' => $proposal]);
        } else {
            // List proposals strictly scoped to authenticated client
            $statusFilter = trim($_GET['status'] ?? '');
            $periodFilter = isset($_GET['payroll_period_id']) ? (int)$_GET['payroll_period_id'] : 0;

            $sql = "
                SELECT pcp.id, pcp.client_id, pcp.proposed_by, pcp.payroll_period_id, pcp.employee_id,
                       pcp.change_type, pcp.item_name, pcp.amount, pcp.qty, pcp.rate, pcp.reason,
                       pcp.status, pcp.reviewed_by, pcp.reviewed_at, pcp.rejection_reason, pcp.idempotency_key,
                       pcp.created_at, pcp.updated_at,
                       pp.name as period_name, e.full_name as employee_name, e.nik as employee_nik
                FROM payroll_change_proposals pcp
                JOIN payroll_periods pp ON pcp.payroll_period_id = pp.id
                JOIN employees e ON pcp.employee_id = e.id
                WHERE pcp.client_id = ?
            ";
            $params = [$clientId];

            if (!empty($statusFilter)) {
                $sql .= " AND pcp.status = ?";
                $params[] = $statusFilter;
            }

            if ($periodFilter > 0) {
                $sql .= " AND pcp.payroll_period_id = ?";
                $params[] = $periodFilter;
            }

            $sql .= " ORDER BY pcp.id DESC LIMIT 50";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            sendJsonResponse(200, [
                'success' => true,
                'total' => count($list),
                'data' => $list
            ]);
        }
    } catch (\Throwable $e) {
        sendJsonResponse(500, ['success' => false, 'error' => 'Internal Server Error', 'message' => $e->getMessage()]);
    }
} else {
    sendJsonResponse(405, ['success' => false, 'error' => 'Method Not Allowed', 'message' => 'Hanya metode GET dan POST yang didukung.']);
}
