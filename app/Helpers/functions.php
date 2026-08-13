<?php
// app/Helpers/functions.php — Utility functions
require_once __DIR__ . '/../Config/app.php';
require_once __DIR__ . '/../Config/database.php';

function e($string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

function formatRupiah($amount): string {
    $val = (float)($amount ?? 0);
    return 'Rp' . number_format($val, 0, ',', '.');
}

function formatTanggalIndo($dateStr): string {
    if (empty($dateStr)) return '-';
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return $dateStr;

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    
    $d = date('d', $timestamp);
    $m = (int)date('m', $timestamp);
    $y = date('Y', $timestamp);
    
    return $d . ' ' . ($bulan[$m] ?? date('F', $timestamp)) . ' ' . $y;
}

function formatDateTimeIndo($dateStr): string {
    if (empty($dateStr)) return '-';
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return $dateStr;

    $tgl = formatTanggalIndo($dateStr);
    $time = date('H:i', $timestamp);
    return $tgl . ' ' . $time;
}

function setFlashMessage(string $type, string $message): void {
    $_SESSION['flash_message'] = [
        'type' => $type, // success, danger, warning, info
        'text' => $message
    ];
}

function getFlashMessage(): ?array {
    if (isset($_SESSION['flash_message'])) {
        $msg = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        return $msg;
    }
    return null;
}

function logAudit($userId, string $action, string $details = ''): void {
    try {
        $db = getDbConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, datetime('now', 'localtime'))");
        $stmt->execute([$userId, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log("Failed to insert audit log: " . $e->getMessage());
    }
}

function sendNotification($userId, string $title, string $message, string $link = ''): void {
    try {
        $db = getDbConnection();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, 0, datetime('now', 'localtime'))");
        $stmt->execute([$userId, $title, $message, $link]);
    } catch (Exception $e) {
        error_log("Failed to send notification: " . $e->getMessage());
    }
}
