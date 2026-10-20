<?php
// public/api/v1/webhook_payroll.php — Automated Cronjob & Webhook Endpoint for Gajiku Payroll

require_once __DIR__ . '/../../../app/Helpers/api_auth.php';
require_once __DIR__ . '/../../../app/Helpers/payroll_service.php';

// Set response header
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Webhook-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = getDbConnection();

// --- 1. Authentication ---
$isCli = (php_sapi_name() === 'cli');
$authenticated = false;
$callerIdentity = 'Unknown';

if ($isCli) {
    $authenticated = true;
    $callerIdentity = 'Local Terminal CLI';
} else {
    $expectedSecret = getOrCreateWebhookSecret($db);

    // Check Header X-Webhook-Secret
    $incomingSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? null;

    // Check Authorization: Bearer <secret or api_client_token>
    $bearerToken = getBearerToken();

    // Check Query param or POST param ?secret= or ?token=
    $querySecret = $_GET['secret'] ?? $_GET['token'] ?? null;

    // Check JSON body for secret
    $rawInput = file_get_contents('php://input');
    $jsonInput = [];
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $jsonInput = $decoded;
            if (empty($querySecret) && isset($jsonInput['secret'])) {
                $querySecret = $jsonInput['secret'];
            }
        }
    }

    // A. Match dedicated webhook secret
    if (!empty($incomingSecret) && hash_equals($expectedSecret, (string)$incomingSecret)) {
        $authenticated = true;
        $callerIdentity = 'Webhook Secret Header';
    } elseif (!empty($querySecret) && hash_equals($expectedSecret, (string)$querySecret)) {
        $authenticated = true;
        $callerIdentity = 'Webhook Secret Parameter';
    } elseif (!empty($bearerToken) && hash_equals($expectedSecret, (string)$bearerToken)) {
        $authenticated = true;
        $callerIdentity = 'Webhook Secret Bearer';
    } elseif (!empty($bearerToken)) {
        // B. Match api_clients table (e.g. Lisa / Hermes Agent / external API client)
        $tokenHash = hash('sha256', $bearerToken);
        $stmtClient = $db->prepare("SELECT * FROM api_clients WHERE token_hash = ? AND status = 'active'");
        $stmtClient->execute([$tokenHash]);
        $client = $stmtClient->fetch();

        if ($client) {
            $clientScopes = array_map('trim', explode(',', $client['scopes'] ?? ''));
            $allowedScopes = ['*', 'payroll:cron', 'payroll:propose', 'payroll:read'];
            $hasScope = false;
            foreach ($allowedScopes as $sc) {
                if (in_array($sc, $clientScopes)) {
                    $hasScope = true;
                    break;
                }
            }

            if ($hasScope) {
                $authenticated = true;
                $callerIdentity = 'API Client: ' . $client['name'];
                try {
                    $db->prepare("UPDATE api_clients SET last_used_at = datetime('now', 'localtime') WHERE id = ?")->execute([$client['id']]);
                } catch (\Throwable $e) {}
            } else {
                sendJsonResponse(403, [
                    'success' => false,
                    'error' => 'Forbidden',
                    'message' => 'API client tidak memiliki hak akses (scope) untuk webhook cronjob payroll.',
                    'client_scopes' => $clientScopes
                ]);
            }
        }
    }
}

if (!$authenticated) {
    sendJsonResponse(401, [
        'success' => false,
        'error' => 'Unauthorized',
        'message' => 'Autentikasi gagal. Harap sertakan header X-Webhook-Secret atau Bearer token yang valid.',
        'help' => [
            'method_1' => 'Kirim header HTTP: X-Webhook-Secret: <YOUR_WEBHOOK_SECRET>',
            'method_2' => 'Kirim header HTTP: Authorization: Bearer <API_CLIENT_OR_WEBHOOK_TOKEN>',
            'method_3' => 'Kirim query string parameter: ?secret=<YOUR_WEBHOOK_SECRET>',
            'method_4' => 'Jalankan langsung melalui terminal CLI server: php bin/cron_payroll.php'
        ]
    ]);
}

// --- 2. Request Parameters Handling ---
// Merge params: JSON Body overrides $_POST and $_GET
$params = array_merge($_GET, $_POST, $jsonInput ?? []);

// If GET request without explicit action or run trigger, return health/status info
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($params['action']) && empty($params['run'])) {
    $stmtLastPeriod = $db->query("SELECT * FROM payroll_periods ORDER BY id DESC LIMIT 1");
    $lastPeriod = $stmtLastPeriod ? $stmtLastPeriod->fetch() : null;

    sendJsonResponse(200, [
        'service' => 'GAJIKU Payroll Webhook & Cronjob API',
        'status' => 'operational',
        'authenticated_as' => $callerIdentity,
        'current_time' => date('Y-m-d H:i:s'),
        'last_payroll_period' => $lastPeriod ? [
            'id' => (int)$lastPeriod['id'],
            'name' => $lastPeriod['name'],
            'status' => $lastPeriod['status'],
            'payment_date' => $lastPeriod['payment_date']
        ] : null,
        'supported_actions' => [
            'auto' => 'Otomatis buat periode bulan berjalan jika belum ada, sinkronkan karyawan & aturan master, dan kalkulasi total (Default)',
            'create_period' => 'Buat periode payroll baru dan inisialisasi karyawan & aturan',
            'sync_rules' => 'Sinkronkan karyawan baru dan aturan master bonus/potongan ke periode draft/review',
            'recalculate' => 'Kalkulasi ulang seluruh nominal take home pay periode berjalan',
            'finalize' => 'Finalisasi periode review ke status final'
        ],
        'usage_example' => 'Kirim HTTP POST dengan JSON payload: {"action": "auto", "dry_run": false}'
    ]);
}

$action = strtolower(trim($params['action'] ?? 'auto'));
$month = isset($params['month']) ? (int)$params['month'] : null;
$year = isset($params['year']) ? (int)$params['year'] : null;
$periodId = isset($params['period_id']) ? (int)$params['period_id'] : null;
$dryRun = filter_var($params['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

$options = [
    'action' => $action,
    'month' => $month,
    'year' => $year,
    'period_id' => $periodId,
    'dry_run' => $dryRun,
    'triggered_by' => "Webhook API ({$callerIdentity})"
];

// --- 3. Execute Workflow ---
try {
    $result = runPayrollCronWorkflow($db, $options);
    $result['authenticated_as'] = $callerIdentity;
    sendJsonResponse(200, $result);
} catch (InvalidArgumentException $e) {
    sendJsonResponse(400, [
        'success' => false,
        'error' => 'Bad Request',
        'message' => $e->getMessage()
    ]);
} catch (DomainException $e) {
    sendJsonResponse(422, [
        'success' => false,
        'error' => 'Unprocessable Entity',
        'message' => $e->getMessage()
    ]);
} catch (\Throwable $e) {
    error_log("Webhook Payroll Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    sendJsonResponse(500, [
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => 'Terjadi kesalahan sistem saat memproses webhook cronjob: ' . $e->getMessage()
    ]);
}
