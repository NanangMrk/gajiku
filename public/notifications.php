<?php
// notifications.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Notifikasi Saya';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        setFlashMessage('success', 'Semua notifikasi telah ditandai sebagai dibaca.');
        header('Location: ' . BASE_URL . '/notifications.php');
        exit;
    }
}

$stmtNotif = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
$stmtNotif->execute([$user['id']]);
$notifications = $stmtNotif->fetchAll();

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4" role="alert">
          <i class="fa-solid fa-circle-info me-2"></i> <?= e($flash['text']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-0">Pusat Notifikasi</h3>
          <p class="text-muted mb-0">Pemberitahuan aktivitas tugas, persetujuan bonus, dan info payroll</p>
        </div>
        <?php if (!empty($notifications)): ?>
          <form action="<?= BASE_URL ?>/notifications.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-outline-primary rounded-pill font-weight-bold">
              <i class="fa-solid fa-check-double me-1"></i> Tandai Semua Dibaca
            </button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
          <?php if (empty($notifications)): ?>
            <div class="text-center py-5 text-muted">
              <i class="fa-solid fa-bell-slash fs-1 mb-2 d-block text-secondary"></i>
              Belum ada notifikasi.
            </div>
          <?php else: ?>
            <div class="list-group list-group-flush">
              <?php foreach ($notifications as $n): ?>
                <a href="<?= BASE_URL ?>/read-notification.php?id=<?= $n['id'] ?>" class="list-group-item list-group-item-action px-3 py-3 border-bottom <?= $n['is_read'] ? 'bg-white' : 'bg-light font-weight-bold' ?>">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0 text-primary font-weight-bold">
                      <?php if (!$n['is_read']): ?>
                        <span class="badge bg-danger rounded-circle p-1 me-1"> </span>
                      <?php endif; ?>
                      <?= e($n['title']) ?>
                    </h6>
                    <small class="text-muted"><i class="fa-solid fa-clock me-1"></i> <?= formatDateTimeIndo($n['created_at']) ?></small>
                  </div>
                  <p class="mb-0 text-dark small"><?= e($n['message']) ?></p>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
