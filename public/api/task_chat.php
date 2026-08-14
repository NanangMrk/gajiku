<?php
// public/api/task_chat.php
require_once __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = getAuthUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$db = getDbConnection();

// Ensure task_chats table exists
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            attachment TEXT,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
} catch (Exception $e) {}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'get_messages';
    $taskId = (int)($_GET['task_id'] ?? 0);

    if ($action === 'unread_count') {
        if ($taskId > 0) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM task_chats WHERE task_id = ? AND user_id != ? AND is_read = 0");
            $stmt->execute([$taskId, $user['id']]);
            $count = (int)$stmt->fetchColumn();
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM task_chats WHERE user_id != ? AND is_read = 0");
            $stmt->execute([$user['id']]);
            $count = (int)$stmt->fetchColumn();
        }
        echo json_encode(['success' => true, 'unread_count' => $count]);
        exit;
    }

    if ($taskId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid task ID', 'data' => []]);
        exit;
    }

    $afterId = (int)($_GET['after_id'] ?? 0);

    if ($afterId > 0) {
        $stmt = $db->prepare("
            SELECT tc.*, u.username, u.role_id, r.slug as role_slug, r.name as role_name,
                   e.full_name as user_fullname, e.photo as user_photo,
                   rtc.message as reply_message, rtc.attachment as reply_attachment,
                   re.full_name as reply_user_fullname, ru.username as reply_username
            FROM task_chats tc
            JOIN users u ON tc.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN employees e ON u.id = e.user_id
            LEFT JOIN task_chats rtc ON tc.reply_to_id = rtc.id
            LEFT JOIN users ru ON rtc.user_id = ru.id
            LEFT JOIN employees re ON ru.id = re.user_id
            WHERE tc.task_id = ? AND tc.id > ?
            ORDER BY tc.id ASC
        ");
        $stmt->execute([$taskId, $afterId]);
    } else {
        $stmt = $db->prepare("
            SELECT tc.*, u.username, u.role_id, r.slug as role_slug, r.name as role_name,
                   e.full_name as user_fullname, e.photo as user_photo,
                   rtc.message as reply_message, rtc.attachment as reply_attachment,
                   re.full_name as reply_user_fullname, ru.username as reply_username
            FROM task_chats tc
            JOIN users u ON tc.user_id = u.id
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN employees e ON u.id = e.user_id
            LEFT JOIN task_chats rtc ON tc.reply_to_id = rtc.id
            LEFT JOIN users ru ON rtc.user_id = ru.id
            LEFT JOIN employees re ON ru.id = re.user_id
            WHERE tc.task_id = ?
            ORDER BY tc.id ASC
        ");
        $stmt->execute([$taskId]);
    }

    $messages = $stmt->fetchAll();

    // Mark unread messages sent by others as read
    $stmtMark = $db->prepare("UPDATE task_chats SET is_read = 1 WHERE task_id = ? AND user_id != ? AND is_read = 0");
    $stmtMark->execute([$taskId, $user['id']]);

    // Format messages for client
    $formatted = array_map(function($m) use ($user) {
        $replyData = null;
        if (!empty($m['reply_to_id'])) {
            $replyData = [
                'id' => (int)$m['reply_to_id'],
                'sender_name' => $m['reply_user_fullname'] ?: ($m['reply_username'] ?: 'Pengguna'),
                'message' => $m['reply_message'] ?: '',
                'attachment' => $m['reply_attachment'] ?: null
            ];
        }

        return [
            'id' => (int)$m['id'],
            'task_id' => (int)$m['task_id'],
            'user_id' => (int)$m['user_id'],
            'sender_name' => $m['user_fullname'] ?: $m['username'],
            'role_name' => $m['role_name'] ?: ($m['role_slug'] === 'employee' ? 'Karyawan' : 'Admin'),
            'role_slug' => $m['role_slug'] ?: 'user',
            'is_self' => ((int)$m['user_id'] === (int)$user['id']),
            'message' => $m['message'],
            'attachment' => $m['attachment'],
            'user_photo' => $m['user_photo'],
            'reply_to' => $replyData,
            'is_edited' => (bool)($m['is_edited'] ?? 0),
            'is_deleted' => (bool)($m['is_deleted'] ?? 0),
            'time_formatted' => date('H:i', strtotime($m['created_at'])),
            'date_formatted' => formatTanggalIndo(date('Y-m-d', strtotime($m['created_at']))),
            'created_at' => $m['created_at']
        ];
    }, $messages);

    echo json_encode([
        'success' => true,
        'task_id' => $taskId,
        'current_user_id' => (int)$user['id'],
        'data' => $formatted
    ]);
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? 'send';

    // 1. EDIT MESSAGE ACTION
    if ($action === 'edit_message') {
        $chatId = (int)($_POST['chat_id'] ?? 0);
        $newMessage = trim($_POST['message'] ?? '');

        if ($chatId <= 0 || empty($newMessage)) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak boleh kosong.']);
            exit;
        }

        $stmtCheck = $db->prepare("SELECT * FROM task_chats WHERE id = ?");
        $stmtCheck->execute([$chatId]);
        $chat = $stmtCheck->fetch();

        if (!$chat) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak ditemukan.']);
            exit;
        }

        if ((int)$chat['user_id'] !== (int)$user['id']) {
            echo json_encode(['success' => false, 'message' => 'Anda hanya dapat mengedit pesan Anda sendiri.']);
            exit;
        }

        if ($chat['is_deleted']) {
            echo json_encode(['success' => false, 'message' => 'Pesan yang sudah dihapus tidak dapat diedit.']);
            exit;
        }

        $stmtUpdate = $db->prepare("
            UPDATE task_chats 
            SET message = ?, is_edited = 1, updated_at = datetime('now', 'localtime') 
            WHERE id = ?
        ");
        $stmtUpdate->execute([$newMessage, $chatId]);

        echo json_encode([
            'success' => true,
            'message' => 'Pesan berhasil diperbarui.',
            'data' => [
                'id' => $chatId,
                'message' => $newMessage,
                'is_edited' => true
            ]
        ]);
        exit;
    }

    // 2. DELETE MESSAGE ACTION
    if ($action === 'delete_message') {
        $chatId = (int)($_POST['chat_id'] ?? 0);

        if ($chatId <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID pesan tidak valid.']);
            exit;
        }

        $stmtCheck = $db->prepare("SELECT * FROM task_chats WHERE id = ?");
        $stmtCheck->execute([$chatId]);
        $chat = $stmtCheck->fetch();

        if (!$chat) {
            echo json_encode(['success' => false, 'message' => 'Pesan tidak ditemukan.']);
            exit;
        }

        // Can delete if owner or Admin/HR (role_id 1, 2)
        $isAdmin = in_array((int)$user['role_id'], [1, 2]);
        $isOwner = ((int)$chat['user_id'] === (int)$user['id']);

        if (!$isOwner && !$isAdmin) {
            echo json_encode(['success' => false, 'message' => 'Anda tidak memiliki hak akses untuk menghapus pesan ini.']);
            exit;
        }

        $stmtDel = $db->prepare("
            UPDATE task_chats 
            SET is_deleted = 1, message = '🚫 Pesan ini telah dihapus.', attachment = NULL, updated_at = datetime('now', 'localtime') 
            WHERE id = ?
        ");
        $stmtDel->execute([$chatId]);

        echo json_encode([
            'success' => true,
            'message' => 'Pesan berhasil dihapus.',
            'data' => [
                'id' => $chatId,
                'is_deleted' => true,
                'message' => '🚫 Pesan ini telah dihapus.'
            ]
        ]);
        exit;
    }

    // 3. SEND MESSAGE ACTION (DEFAULT)
    $taskId = (int)($_POST['task_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    $replyToId = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;

    if ($taskId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Task ID diperlukan.']);
        exit;
    }

    // Handle File Upload if provided
    $attachmentPath = null;
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $uploadBase = ROOT_PATH . '/public/uploads/tasks/chat/';
        if (!is_dir($uploadBase)) {
            mkdir($uploadBase, 0777, true);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // Default to webm if extension is missing (e.g. from raw audio blob)
        if (empty($ext)) {
            $mime = $file['type'] ?? '';
            if (strpos($mime, 'audio/webm') !== false) $ext = 'webm';
            elseif (strpos($mime, 'audio/ogg') !== false) $ext = 'ogg';
            elseif (strpos($mime, 'audio/mp4') !== false || strpos($mime, 'audio/m4a') !== false) $ext = 'm4a';
            elseif (strpos($mime, 'audio/wav') !== false) $ext = 'wav';
            else $ext = 'webm';
        }
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt', 'webm', 'ogg', 'mp3', 'wav', 'm4a', 'aac', 'mp4', 'opus'];
        if (in_array($ext, $allowed)) {
            $prefix = in_array($ext, ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'aac', 'opus']) ? 'vn_' : 'chat_';
            $filename = $prefix . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $dest = $uploadBase . $filename;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $attachmentPath = '/uploads/tasks/chat/' . $filename;
            }
        }
    }

    if (empty($message) && empty($attachmentPath)) {
        echo json_encode(['success' => false, 'message' => 'Pesan atau lampiran tidak boleh kosong.']);
        exit;
    }

    // Check task existence
    $stmtTask = $db->prepare("SELECT title, employee_id, team_id, division_id FROM tasks WHERE id = ?");
    $stmtTask->execute([$taskId]);
    $task = $stmtTask->fetch();

    if (!$task) {
        echo json_encode(['success' => false, 'message' => 'Tugas tidak ditemukan.']);
        exit;
    }

    // Fetch reply_to details if present
    $replyData = null;
    if ($replyToId) {
        $stmtRep = $db->prepare("
            SELECT tc.id, tc.message, tc.attachment, e.full_name as user_fullname, u.username
            FROM task_chats tc
            JOIN users u ON tc.user_id = u.id
            LEFT JOIN employees e ON u.id = e.user_id
            WHERE tc.id = ?
        ");
        $stmtRep->execute([$replyToId]);
        $repRow = $stmtRep->fetch();
        if ($repRow) {
            $replyData = [
                'id' => (int)$repRow['id'],
                'sender_name' => $repRow['user_fullname'] ?: $repRow['username'],
                'message' => $repRow['message'],
                'attachment' => $repRow['attachment']
            ];
        }
    }

    // Insert chat message
    $stmt = $db->prepare("
        INSERT INTO task_chats (task_id, user_id, reply_to_id, message, attachment, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, datetime('now', 'localtime'))
    ");
    $stmt->execute([$taskId, $user['id'], $replyToId, $message, $attachmentPath]);
    $newChatId = (int)$db->lastInsertId();

    // Fetch user details for notification & response
    $senderName = $user['employee_name'] ?? $user['username'];

    // Send notifications to relevant participants
    if ($user['role_slug'] === 'employee') {
        // Employee sent message -> notify Admins
        $stmtAdmins = $db->query("SELECT id FROM users WHERE role_id IN (1, 2) AND status = 'active'");
        while ($admId = $stmtAdmins->fetchColumn()) {
            if ($admId != $user['id']) {
                sendNotification($admId, 'Pesan Baru pada Tugas: ' . $task['title'], $senderName . ': ' . (mb_strimwidth($message, 0, 40, '...')), '/tasks.php?id=' . $taskId . '&open_chat=1');
            }
        }
    } else {
        // Admin sent message -> notify assigned employee
        if ($task['employee_id']) {
            $stmtUserEmp = $db->prepare("SELECT user_id FROM employees WHERE id = ?");
            $stmtUserEmp->execute([$task['employee_id']]);
            $empUserId = $stmtUserEmp->fetchColumn();
            if ($empUserId && $empUserId != $user['id']) {
                sendNotification($empUserId, 'Pesan Baru dari ' . $senderName . ' (' . $task['title'] . ')', (mb_strimwidth($message, 0, 40, '...')), '/tasks.php?id=' . $taskId . '&open_chat=1');
            }
        }
    }

    $createdAt = date('Y-m-d H:i:s');
    $stmtEmp = $db->prepare("SELECT photo, full_name FROM employees WHERE user_id = ?");
    $stmtEmp->execute([$user['id']]);
    $empInfo = $stmtEmp->fetch();

    echo json_encode([
        'success' => true,
        'message' => 'Pesan berhasil dikirim.',
        'data' => [
            'id' => $newChatId,
            'task_id' => $taskId,
            'user_id' => (int)$user['id'],
            'sender_name' => $empInfo['full_name'] ?? $user['username'],
            'role_name' => $user['role_name'] ?? ($user['role_slug'] === 'employee' ? 'Karyawan' : 'Admin'),
            'role_slug' => $user['role_slug'] ?? 'user',
            'is_self' => true,
            'message' => $message,
            'attachment' => $attachmentPath,
            'user_photo' => $empInfo['photo'] ?? null,
            'reply_to' => $replyData,
            'is_edited' => false,
            'is_deleted' => false,
            'time_formatted' => date('H:i'),
            'date_formatted' => formatTanggalIndo(date('Y-m-d')),
            'created_at' => $createdAt
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed']);
