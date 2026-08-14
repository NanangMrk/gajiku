<?php
// app/Views/layouts/sidebar.php
require_once __DIR__ . '/../../Helpers/auth.php';
require_once __DIR__ . '/../../Helpers/permissions.php';

$uri = $_SERVER['REQUEST_URI'] ?? '';
$sidebarUser = getAuthUser();
function isActiveMenu(string $path, string $uri): string {
    return strpos($uri, $path) !== false ? 'active' : '';
}
?>
<!-- Skydash Sidebar Navigation -->
<nav class="sidebar sidebar-offcanvas" id="sidebar">
  <!-- Mobile Sidebar Brand & Close Header -->
  <div class="sidebar-mobile-header d-flex align-items-center justify-content-between px-3 py-3 border-bottom d-lg-none mb-2 bg-light">
    <div class="d-flex align-items-center gap-2">
      <div class="bg-primary text-white rounded-3 d-flex align-items-center justify-content-center fw-bold shadow-sm" style="width: 38px; height: 38px;">
        <i class="fa-solid fa-wallet fs-5"></i>
      </div>
      <div>
        <h6 class="fw-bold mb-0 text-dark" style="font-size: 1rem;">Gajiku</h6>
        <small class="text-muted" style="font-size: 0.75rem;"><?= e($sidebarUser['role_name'] ?? 'Menu Navigasi') ?></small>
      </div>
    </div>
    <button type="button" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center shadow-sm" data-toggle="offcanvas" aria-label="Tutup Menu" style="width: 32px; height: 32px;">
      <i class="fa-solid fa-xmark text-dark fs-6"></i>
    </button>
  </div>

  <ul class="nav">
    <!-- Dashboard -->
    <li class="nav-item <?= isActiveMenu('/dashboard.php', $uri) ?>">
      <a class="nav-link" href="<?= BASE_URL ?>/dashboard.php">
        <i class="fa-solid fa-chart-line menu-icon"></i>
        <span class="menu-title">Dashboard</span>
      </a>
    </li>
    
    <!-- Tasks -->
    <li class="nav-item <?= isActiveMenu('/tasks.php', $uri) ?>">
      <a class="nav-link" href="<?= BASE_URL ?>/tasks.php">
        <i class="fa-solid fa-list-check menu-icon"></i>
        <span class="menu-title">Tugas / To-Do</span>
      </a>
    </li>
    
    <!-- Bonuses -->
    <?php if (hasPermission('manage_bonus_types')): ?>
      <li class="nav-item <?= isActiveMenu('/bonuses.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/bonuses.php">
          <i class="fa-solid fa-gift menu-icon"></i>
          <span class="menu-title">Bonus &amp; Prestatif</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Deductions -->
    <?php if (hasPermission('manage_deductions') || hasPermission('process_payroll')): ?>
      <li class="nav-item <?= isActiveMenu('/deductions.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/deductions.php">
          <i class="fa-solid fa-minus-circle menu-icon"></i>
          <span class="menu-title">Potongan Gaji</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Payroll -->
    <?php if (hasPermission('process_payroll')): ?>
      <li class="nav-item <?= isActiveMenu('/payroll.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/payroll.php">
          <i class="fa-solid fa-calculator menu-icon"></i>
          <span class="menu-title">Proses Payroll</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Payslips -->
    <li class="nav-item <?= isActiveMenu('/payslips.php', $uri) ?>">
      <a class="nav-link" href="<?= BASE_URL ?>/payslips.php">
        <i class="fa-solid fa-file-invoice-dollar menu-icon"></i>
        <span class="menu-title">Slip Gaji</span>
      </a>
    </li>

    <!-- Employees -->
    <?php if (hasPermission('manage_employees')): ?>
      <li class="nav-item <?= isActiveMenu('/employees.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/employees.php">
          <i class="fa-solid fa-users menu-icon"></i>
          <span class="menu-title">Karyawan</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Divisions & Teams -->
    <?php if (hasPermission('manage_divisions') || hasPermission('manage_teams')): ?>
      <li class="nav-item <?= isActiveMenu('/divisions.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/divisions.php">
          <i class="fa-solid fa-sitemap menu-icon"></i>
          <span class="menu-title">Divisi &amp; Tim</span>
        </a>
      </li>
    <?php endif; ?>

    <li class="nav-item nav-category mt-3">
      <span class="nav-link text-muted font-weight-bold text-uppercase fs-8" style="letter-spacing: 1px;">Pengaturan</span>
    </li>

    <!-- Profile -->
    <li class="nav-item <?= isActiveMenu('/profile.php', $uri) ?>">
      <a class="nav-link" href="<?= BASE_URL ?>/profile.php">
        <i class="fa-solid fa-user-gear menu-icon"></i>
        <span class="menu-title">Profil Saya</span>
      </a>
    </li>

    <!-- Company & System Settings -->
    <?php if (hasPermission('manage_settings')): ?>
      <li class="nav-item <?= isActiveMenu('/settings.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/settings.php">
          <i class="fa-solid fa-gear menu-icon"></i>
          <span class="menu-title">Pengaturan Sistem</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Audit Log -->
    <?php if (hasPermission('view_audit_log')): ?>
      <li class="nav-item <?= isActiveMenu('/audit.php', $uri) ?>">
        <a class="nav-link" href="<?= BASE_URL ?>/audit.php">
          <i class="fa-solid fa-shield-halved menu-icon"></i>
          <span class="menu-title">Audit Log</span>
        </a>
      </li>
    <?php endif; ?>

    <!-- Logout -->
    <li class="nav-item mt-2">
      <a class="nav-link text-danger" href="<?= BASE_URL ?>/logout.php">
        <i class="fa-solid fa-right-from-bracket menu-icon text-danger"></i>
        <span class="menu-title">Keluar</span>
      </a>
    </li>
  </ul>
</nav>
