<?php
// app/Helpers/auth.php — Authentication & session management
require_once __DIR__ . '/../Config/app.php';
require_once __DIR__ . '/../Config/database.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getAuthUser(): ?array {
    if (!isLoggedIn()) return null;
    
    static $user = null;
    if ($user === null) {
        $db = getDbConnection();
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.slug as role_slug,
                   e.id as employee_id, e.nik, e.full_name as employee_name, e.photo, e.position,
                   e.division_id, e.team_id, e.phone, e.address, e.bank_name, e.account_number,
                   d.name as division_name, t.name as team_name
            FROM users u
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN employees e ON u.id = e.user_id
            LEFT JOIN divisions d ON e.division_id = d.id
            LEFT JOIN teams t ON e.team_id = t.id
            WHERE u.id = ? AND u.status = 'active'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function requireLogin(): array {
    if (!isLoggedIn() || !getAuthUser()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    return getAuthUser();
}

function requireAuth(): array {
    return requireLogin();
}


function attemptLogin(string $usernameOrEmail, string $password): bool {
    $db = getDbConnection();
    $stmt = $db->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND status = 'active'");
    $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role_id'] = $user['role_id'];
        
        // Issue persistent remember token
        createRememberToken((int)$user['id']);
        
        logAudit($user['id'], 'LOGIN', 'User logged in successfully');
        return true;
    }
    
    return false;
}

function logoutUser(): void {
    if (isLoggedIn()) {
        logAudit($_SESSION['user_id'], 'LOGOUT', 'User logged out');
    }
    
    // Revoke persistent remember token from SQLite database and clear cookie
    revokeRememberToken();
    
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
