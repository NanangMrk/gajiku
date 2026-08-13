<?php
// app/Helpers/permissions.php — Role & permission enforcement
require_once __DIR__ . '/auth.php';

function getUserPermissions(?int $userId = null): array {
    static $permissionsCache = [];
    $user = getAuthUser();
    if (!$user) return [];
    
    $uid = $user['id'];
    if (isset($permissionsCache[$uid])) {
        return $permissionsCache[$uid];
    }
    
    // Super admin has all permissions
    if ($user['role_slug'] === 'super_admin') {
        $permissionsCache[$uid] = ['*'];
        return ['*'];
    }

    $db = getDbConnection();
    $stmt = $db->prepare("
        SELECT p.slug
        FROM role_permissions rp
        JOIN permissions p ON rp.permission_id = p.id
        WHERE rp.role_id = ?
    ");
    $stmt->execute([$user['role_id']]);
    $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $permissionsCache[$uid] = $perms;
    return $perms;
}

function hasPermission(string $permissionSlug): bool {
    $user = getAuthUser();
    if (!$user) return false;
    if ($user['role_slug'] === 'super_admin') return true;
    
    $perms = getUserPermissions();
    return in_array('*', $perms) || in_array($permissionSlug, $perms);
}

function requirePermission(string $permissionSlug): void {
    $user = requireLogin();
    if (!hasPermission($permissionSlug)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html><head><title>403 Forbidden</title><link rel='stylesheet' href='" . BASE_URL . "/assets/css/style.css'></head>";
        echo "<body class='d-flex align-items-center justify-content-center vh-100 bg-light'><div class='text-center p-5 card shadow'>";
        echo "<h1 class='text-danger display-1 fw-bold'>403</h1>";
        echo "<h3>Akses Ditolak</h3>";
        echo "<p class='text-muted'>Anda tidak memiliki izin untuk mengakses halaman ini.</p>";
        echo "<a href='" . BASE_URL . "/dashboard.php' class='btn btn-primary mt-3'><i class='fa-solid fa-arrow-left me-2'></i> Kembali ke Dashboard</a>";
        echo "</div></body></html>";
        exit;
    }
}
