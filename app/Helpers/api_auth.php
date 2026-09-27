<?php
// app/Helpers/api_auth.php — Authentication & authorization for external API clients (e.g. Lisa / Hermes Agent)

require_once __DIR__ . '/../Config/app.php';
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Send JSON response and exit.
 */
function sendJsonResponse(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Retrieve Bearer token from incoming HTTP headers.
 */
function getBearerToken(): ?string {
    $header = null;
    
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $header = trim($headers['Authorization']);
        } elseif (isset($headers['authorization'])) {
            $header = trim($headers['authorization']);
        }
    }

    if (!empty($header) && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }

    return null;
}

/**
 * Authenticate incoming API client by Bearer token and enforce scope.
 * 
 * @param string|null $requiredScope Required permission scope, e.g. 'payroll:read', 'payroll:propose'
 * @return array Authenticated client data row
 */
function authenticateApiClient(?string $requiredScope = null): array {
    $rawToken = getBearerToken();
    if (empty($rawToken)) {
        sendJsonResponse(401, [
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Missing Bearer token in Authorization header.'
        ]);
    }

    $tokenHash = hash('sha256', $rawToken);
    $db = getDbConnection();
    
    $stmt = $db->prepare("SELECT * FROM api_clients WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);
    $client = $stmt->fetch();

    if (!$client || $client['status'] !== 'active') {
        sendJsonResponse(401, [
            'success' => false,
            'error' => 'Unauthorized',
            'message' => 'Invalid, expired, or revoked API client token.'
        ]);
    }

    // Verify scope permissions
    if ($requiredScope !== null) {
        $clientScopes = array_map('trim', explode(',', $client['scopes'] ?? ''));
        if (!in_array('*', $clientScopes) && !in_array($requiredScope, $clientScopes)) {
            sendJsonResponse(403, [
                'success' => false,
                'error' => 'Forbidden',
                'message' => "Insufficient permissions. This endpoint requires '{$requiredScope}' scope.",
                'client_scopes' => $clientScopes
            ]);
        }
    }

    // Update last used timestamp
    try {
        $stmtUp = $db->prepare("UPDATE api_clients SET last_used_at = datetime('now', 'localtime') WHERE id = ?");
        $stmtUp->execute([$client['id']]);
    } catch (\Throwable $e) {
        // silent non-blocking
    }

    return $client;
}

/**
 * Helper to generate a new API client credential.
 * Plaintext token is only returned once upon creation and never stored.
 */
function createApiClient($arg1, $arg2 = null, $arg3 = null, $arg4 = 'payroll:read,payroll:propose'): array {
    if ($arg1 instanceof PDO) {
        $db = $arg1;
        $name = (string)$arg2;
        $clientKey = (string)$arg3;
        $scopes = (string)$arg4;
    } else {
        $db = getDbConnection();
        $name = (string)$arg1;
        $clientKey = (string)$arg2;
        $scopes = (string)($arg3 ?? 'payroll:read,payroll:propose');
    }

    $rawSecret = 'gjk_' . bin2hex(random_bytes(24));
    $tokenHash = hash('sha256', $rawSecret);

    $stmt = $db->prepare("
        INSERT INTO api_clients (name, client_key, token_hash, scopes, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'active', datetime('now', 'localtime'), datetime('now', 'localtime'))
    ");
    $stmt->execute([$name, $clientKey, $tokenHash, $scopes]);
    $clientId = (int)$db->lastInsertId();

    return [
        'id' => $clientId,
        'name' => $name,
        'client_key' => $clientKey,
        'plain_token' => $rawSecret,
        'raw_token' => $rawSecret,
        'scopes' => $scopes,
        'status' => 'active'
    ];
}

/**
 * Revoke an API client by key.
 */
function revokeApiClient(PDO $db, string $clientKey): bool {
    $stmt = $db->prepare("UPDATE api_clients SET status = 'revoked', updated_at = datetime('now', 'localtime') WHERE client_key = ?");
    $stmt->execute([$clientKey]);
    return $stmt->rowCount() > 0;
}
