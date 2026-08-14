<?php
// app/Views/layouts/footer.php
?>
      </div>
      <!-- main-panel ends -->
    </div>
    <!-- page-body-wrapper ends -->
  <!-- Mobile Bottom Navigation -->
  <?php
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!function_exists('isActiveMobileMenu')) {
        function isActiveMobileMenu(string $path, string $uri): string {
            return strpos($uri, $path) !== false ? 'active' : '';
        }
    }
  ?>
  <div class="mobile-bottom-nav d-flex d-md-none no-print">
    <a href="<?= BASE_URL ?>/dashboard.php" class="mobile-nav-item <?= isActiveMobileMenu('/dashboard.php', $uri) ?>">
      <i class="fa-solid fa-house"></i>
      <span>Beranda</span>
    </a>
    <a href="<?= BASE_URL ?>/payslips.php" class="mobile-nav-item <?= isActiveMobileMenu('/payslips.php', $uri) ?>">
      <i class="fa-solid fa-file-invoice-dollar"></i>
      <span>Slip Gaji</span>
    </a>
    <a href="#" class="mobile-nav-item center-fab" data-toggle="offcanvas" onclick="event.preventDefault();">
      <i class="fa-solid fa-bars"></i>
      <span>Menu</span>
    </a>
    <a href="<?= BASE_URL ?>/tasks.php" class="mobile-nav-item <?= isActiveMobileMenu('/tasks.php', $uri) ?>">
      <i class="fa-solid fa-list-check"></i>
      <span>Tugas</span>
    </a>
    <a href="<?= BASE_URL ?>/profile.php" class="mobile-nav-item <?= isActiveMobileMenu('/profile.php', $uri) ?>">
      <i class="fa-solid fa-user"></i>
      <span>Profil</span>
    </a>
  </div>

  </div>
  <!-- container-scroller -->

  <!-- Vendor JS -->
  <script src="<?= BASE_URL ?>/assets/vendors/js/vendor.bundle.base.js"></script>
  <script src="<?= BASE_URL ?>/assets/vendors/chart.js/chart.umd.js"></script>
  
  <!-- Skydash JS -->
  <script src="<?= BASE_URL ?>/assets/js/off-canvas.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/template.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/settings.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/todolist.js"></script>
  
  <!-- Auto-hide Flash Messages & Android Back Button Handler -->
  <script>
    document.addEventListener("DOMContentLoaded", function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });

        // Android Hardware Back Button listener if running inside Capacitor APK
        if (window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.App) {
            window.Capacitor.Plugins.App.addListener('backButton', function() {
                const currentPath = window.location.pathname;
                if (currentPath.endsWith('/dashboard.php') || currentPath.endsWith('/login.php') || currentPath === '/' || currentPath === '') {
                    window.Capacitor.Plugins.App.minimizeApp();
                } else if (window.history.length > 1) {
                    window.history.back();
                } else {
                    window.location.href = '<?= BASE_URL ?>/dashboard.php';
                }
            });
        }
    });
  </script>
</body>
</html>
