<?php
// audit.php
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('view_audit_log');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Audit Log Sistem';

$stmtAudit = $db->query("
    SELECT al.*, u.username, u.email, r.name as role_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY al.id DESC LIMIT 100
");
$logs = $stmtAudit->fetchAll();

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <div class="content-wrapper">

      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-0">Audit Log Sistem</h3>
          <p class="text-muted mb-0">Catatan jejak audit permanen untuk aktivitas pengguna dan finansial penting</p>
        </div>
      </div>

      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
          <?php if (empty($logs)): ?>
            <div class="text-center py-5 text-muted">
              <i class="fa-solid fa-shield-halved fs-1 mb-2 d-block"></i>
              Belum ada catatan audit log.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Waktu &amp; Tanggal</th>
                    <th>Pengguna</th>
                    <th>Aksi / Aktivitas</th>
                    <th>Rincian Detail</th>
                    <th>Alamat IP</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($logs as $log): ?>
                    <tr>
                      <td class="font-weight-bold text-nowrap"><small><?= formatDateTimeIndo($log['created_at']) ?></small></td>
                      <td>
                        <div class="font-weight-bold"><?= e($log['username'] ?? 'Guest / System') ?></div>
                        <small class="text-muted"><?= e($log['role_name'] ?? '-') ?></small>
                      </td>
                      <td><span class="badge bg-primary font-weight-bold"><?= e($log['action']) ?></span></td>
                      <td><small><?= e($log['details']) ?></small></td>
                      <td><code><?= e($log['ip_address']) ?></code></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
