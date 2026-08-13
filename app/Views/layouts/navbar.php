<?php
// app/Views/layouts/navbar.php
require_once __DIR__ . '/../../Helpers/auth.php';
require_once __DIR__ . '/../../Helpers/functions.php';

$user = getAuthUser();
$unreadNotificationsCount = 0;
$unreadNotifications = [];

if ($user) {
    $db = getDbConnection();
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmtCount->execute([$user['id']]);
    $unreadNotificationsCount = (int)$stmtCount->fetchColumn();

    $stmtList = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
    $stmtList->execute([$user['id']]);
    $unreadNotifications = $stmtList->fetchAll();
}
?>
<!-- Skydash Top Navbar -->
<nav class="navbar col-lg-12 col-12 p-0 fixed-top d-flex flex-row">
  <div class="text-center navbar-brand-wrapper d-flex align-items-center justify-content-start ps-3">
    <a class="navbar-brand brand-logo d-flex align-items-center fw-bold text-primary text-decoration-none" href="<?= BASE_URL ?>/dashboard.php">
      <i class="fa-solid fa-wallet me-2 fs-4"></i> Gajiku
    </a>
    <a class="navbar-brand brand-logo-mini text-primary fw-bold text-decoration-none" href="<?= BASE_URL ?>/dashboard.php">
      <i class="fa-solid fa-wallet fs-4"></i>
    </a>
  </div>
  <div class="navbar-menu-wrapper d-flex align-items-center justify-content-end">
    <button class="navbar-toggler navbar-toggler align-self-center" type="button" data-toggle="minimize">
      <span class="icon-menu"></span>
    </button>
    
    <ul class="navbar-nav navbar-nav-right">
      <!-- Notifications Dropdown -->
      <li class="nav-item dropdown">
        <a class="nav-link count-indicator dropdown-toggle" id="notificationDropdown" href="#" data-bs-toggle="dropdown">
          <i class="fa-solid fa-bell mx-0"></i>
          <?php if ($unreadNotificationsCount > 0): ?>
            <span class="count bg-danger"><?= $unreadNotificationsCount ?></span>
          <?php endif; ?>
        </a>
        <div class="dropdown-menu dropdown-menu-right navbar-dropdown preview-list" aria-labelledby="notificationDropdown">
          <p class="mb-0 font-weight-normal float-left dropdown-header">Notifikasi (<?= $unreadNotificationsCount ?>)</p>
          
          <?php if (empty($unreadNotifications)): ?>
            <div class="dropdown-item text-center text-muted py-3 fs-7">
              <i class="fa-solid fa-check-circle me-1"></i> Tidak ada notifikasi baru
            </div>
          <?php else: ?>
            <?php foreach ($unreadNotifications as $notif): ?>
              <a class="dropdown-item preview-item" href="<?= BASE_URL ?>/read-notification.php?id=<?= $notif['id'] ?>">
                <div class="preview-thumbnail">
                  <div class="preview-icon bg-info text-white rounded-circle p-2">
                    <i class="fa-solid fa-info"></i>
                  </div>
                </div>
                <div class="preview-item-content">
                  <h6 class="preview-subject font-weight-normal"><?= e($notif['title']) ?></h6>
                  <p class="font-weight-light small-text mb-0 text-muted"><?= e(mb_strimwidth($notif['message'], 0, 40, '...')) ?></p>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
          
          <div class="dropdown-divider"></div>
          <a class="dropdown-item text-center text-primary py-2 font-weight-bold" href="<?= BASE_URL ?>/notifications.php">
            Lihat Semua Notifikasi
          </a>
        </div>
      </li>

      <!-- User Profile Dropdown -->
      <li class="nav-item nav-profile dropdown">
        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" data-bs-toggle="dropdown" id="profileDropdown">
          <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 35px; height: 35px; font-weight: bold;">
            <?= strtoupper(substr($user['username'] ?? 'U', 0, 1)) ?>
          </div>
          <span class="font-weight-normal me-2 d-none d-md-inline"><?= e($user['employee_name'] ?? $user['username']) ?></span>
          <i class="fa-solid fa-chevron-down text-muted small"></i>
        </a>
        <div class="dropdown-menu dropdown-menu-right navbar-dropdown" aria-labelledby="profileDropdown">
          <div class="px-3 py-2 border-bottom">
            <p class="mb-0 font-weight-bold"><?= e($user['employee_name'] ?? $user['username']) ?></p>
            <small class="text-muted"><?= e($user['role_name']) ?></small>
          </div>
          <a class="dropdown-item py-2" href="<?= BASE_URL ?>/profile.php">
            <i class="fa-solid fa-user me-2 text-primary"></i> Profil Saya
          </a>
          <?php if (hasPermission('manage_settings')): ?>
            <a class="dropdown-item py-2" href="<?= BASE_URL ?>/settings.php">
              <i class="fa-solid fa-gear me-2 text-primary"></i> Pengaturan
            </a>
          <?php endif; ?>
          <div class="dropdown-divider my-1"></div>
          <a class="dropdown-item py-2 text-danger" href="<?= BASE_URL ?>/logout.php">
            <i class="fa-solid fa-right-from-bracket me-2"></i> Keluar
          </a>
        </div>
      </li>
    </ul>
    
    <button class="navbar-toggler navbar-toggler-right d-lg-none align-self-center" type="button" data-toggle="offcanvas">
      <span class="icon-menu"></span>
    </button>
  </div>
</nav>
