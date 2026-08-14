<?php
// app/Helpers/remember.php — Remember Me persistent login token handler

require_once __DIR__ . '/../Config/app.php';
require_once __DIR__ . '/../Config/database.php';

define('REMEMBER_COOKIE_NAME', 'gajiku_remember');
define('REMEMBER_EXPIRATION_DAYS', 365);

/**
 * Generate cryptographically secure remember token and set persistent cookie.
 */
function createRememberToken(int $userId): bool {
    try {
        $db = getDbConnection();
        
        $selector = bin2hex(random_bytes(16)); // 32 hex chars
        $validator = bin2hex(random_bytes(32)); // 64 hex chars
        
        $tokenHash = hash('sha256', $validator);
        $expiresAt = date('Y-m-d H:i:s', time() + (REMEMBER_EXPIRATION_DAYS * 86400));
        
        $stmt = $db->prepare("
            INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, created_at, last_used_at)
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        $stmt->execute([$userId, $selector, $tokenHash, $expiresAt]);
        
        $cookieValue = $selector . ':' . $validator;
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                   (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        
        setcookie(
            REMEMBER_COOKIE_NAME,
            $cookieValue,
            [
                'expires' => time() + (REMEMBER_EXPIRATION_DAYS * 86400),
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
        
        cleanupExpiredRememberTokens();
        return true;
    } catch (\Throwable $e) {
        error_log('createRememberToken Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Automatically restore session from persistent cookie if session is absent/expired.
 */
function restoreSessionFromRememberToken(): bool {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        return true;
    }
    
    $cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
    if (empty($cookie) || strpos($cookie, ':') === false) {
        return false;
    }
    
    list($selector, $validator) = explode(':', $cookie, 2);
    if (empty($selector) || empty($validator)) {
        revokeRememberToken();
        return false;
    }
    
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("
            SELECT rt.*, u.status as user_status, u.role_id
            FROM remember_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.selector = ? AND rt.expires_at > datetime('now')
        ");
        $stmt->execute([$selector]);
        $tokenRow = $stmt->fetch();
        
        if (!$tokenRow) {
            revokeRememberToken();
            return false;
        }
        
        // Verify validator hash safely against timing attacks
        $calcHash = hash('sha256', $validator);
        if (!hash_equals($tokenRow['token_hash'], $calcHash)) {
            revokeRememberToken();
            return false;
        }
        
        // Ensure user account is active
        if ($tokenRow['user_status'] !== 'active') {
            revokeRememberToken();
            return false;
        }
        
        // Restore user session
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$tokenRow['user_id'];
        $_SESSION['role_id'] = (int)$tokenRow['role_id'];
        
        // Sliding expiration / update last used timestamp
        $updateStmt = $db->prepare("UPDATE remember_tokens SET last_used_at = datetime('now') WHERE id = ?");
        $updateStmt->execute([$tokenRow['id']]);
        
        logAudit((int)$tokenRow['user_id'], 'AUTO_LOGIN', 'Session auto-restored via remember token');
        return true;
    } catch (\Throwable $e) {
        error_log('restoreSessionFromRememberToken Error: ' . $e->getMessage());
        revokeRememberToken();
        return false;
    }
}

/**
 * Revoke current device's persistent remember token and remove cookie.
 */
function revokeRememberToken(): void {
    $cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
    if (!empty($cookie) && strpos($cookie, ':') !== false) {
        list($selector) = explode(':', $cookie, 2);
        if (!empty($selector)) {
            try {
                $db = getDbConnection();
                $stmt = $db->prepare("DELETE FROM remember_tokens WHERE selector = ?");
                $stmt->execute([$selector]);
            } catch (\Throwable $e) {
                error_log('revokeRememberToken Error: ' . $e->getMessage());
            }
        }
    }
    
    // Clear cookie
    setcookie(REMEMBER_COOKIE_NAME, '', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}

/**
 * Revoke all active remember tokens for a specific user (e.g. on password change or logout all).
 */
function revokeAllRememberTokens(int $userId): void {
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (\Throwable $e) {
        error_log('revokeAllRememberTokens Error: ' . $e->getMessage());
    }
}

/**
 * Maintenance cleanup of expired tokens (runs probabilistically or on creation).
 */
function cleanupExpiredRememberTokens(): void {
    if (rand(1, 20) === 1) {
        try {
            $db = getDbConnection();
            $db->exec("DELETE FROM remember_tokens WHERE expires_at < datetime('now')");
        } catch (\Throwable $e) {
            // silent fail for non-critical maintenance
        }
    }
}
