<?php
// read-notification.php — Action handler to mark a notification as read and redirect
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    // Verify ownership and update is_read status
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['id']]);

    // Fetch the redirect link
    $stmtLink = $db->prepare("SELECT link FROM notifications WHERE id = ?");
    $stmtLink->execute([$id]);
    $link = $stmtLink->fetchColumn();

    if (!empty($link)) {
        // Redirect to the target page
        header('Location: ' . BASE_URL . $link);
        exit;
    }
}

// Default fallback redirect
header('Location: ' . BASE_URL . '/notifications.php');
exit;
