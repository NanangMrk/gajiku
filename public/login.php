<?php
// public/login.php
require_once __DIR__ . '/../app/bootstrap.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    
    $usernameOrEmail = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($usernameOrEmail) || empty($password)) {
        $error = 'Username/Email dan Password wajib diisi.';
    } else {
        if (attemptLogin($usernameOrEmail, $password)) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            $error = 'Username/Email atau Password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
  <title>Login - <?= e(APP_NAME) ?></title>
  
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/feather/feather.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/ti-icons/css/themify-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/css/vendor.bundle.base.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    body {
        background: #f4f6fa;
        font-family: 'Inter', sans-serif;
    }
    .login-card {
        border: none;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05);
        background: #fff;
    }
    .login-sidebar {
        background: linear-gradient(135deg, #4B49AC 0%, #6866D0 100%);
        color: #fff;
        padding: 3.5rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
    }
    .login-sidebar::before {
        content: '';
        position: absolute;
        width: 250px;
        height: 250px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 50%;
        top: -50px;
        left: -50px;
    }
    .login-sidebar::after {
        content: '';
        position: absolute;
        width: 350px;
        height: 350px;
        background: rgba(255, 255, 255, 0.03);
        border-radius: 50%;
        bottom: -100px;
        right: -100px;
    }
    .form-control:focus {
        border-color: #4B49AC !important;
        box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
    }
    .input-group-text {
        border-color: #ced4da;
    }
    .btn-primary {
        background-color: #4B49AC !important;
        border-color: #4B49AC !important;
        transition: all 0.3s ease;
    }
    .btn-primary:hover {
        background-color: #3f3e91 !important;
        border-color: #3f3e91 !important;
        transform: translateY(-2px);
    }
  </style>
</head>
<body>

  <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 0;">
    <div class="col-12 col-xl-10">
      <div class="card login-card">
        <div class="row g-0">
          
          <!-- Left Sidebar (Desktop Only) -->
          <div class="col-lg-6 d-none d-lg-flex login-sidebar">
            <div class="brand-logo">
              <h3 class="font-weight-bold text-white mb-0"><i class="fa-solid fa-wallet me-2"></i> Gajiku</h3>
            </div>
            <div class="my-5">
              <h1 class="font-weight-bold mb-3 text-white" style="line-height: 1.25; font-size: 2.25rem;">
                Sistem Payroll & Gaji Real-Time Terpercaya
              </h1>
              <p class="text-white opacity-75 lead" style="font-size: 1.05rem;">
                Kelola gaji, bonus, potongan, dan cetak slip gaji karyawan Anda secara instan, aman, dan transparan.
              </p>
            </div>
            <div>
              <p class="mb-0 text-white opacity-50 small">&copy; <?= date('Y') ?> Gajiku Nusantara. All rights reserved.</p>
            </div>
          </div>
          
          <!-- Right Form -->
          <div class="col-lg-6 p-4 p-md-5 d-flex flex-column justify-content-center">
            <div class="auth-form-light text-start">
              <div class="d-lg-none text-center mb-4">
                <h2 class="font-weight-bold text-primary mb-1"><i class="fa-solid fa-wallet me-2"></i> Gajiku</h2>
              </div>
              <h3 class="font-weight-bold text-dark mb-1">Selamat Datang Kembali!</h3>
              <p class="text-muted mb-4">Masuk dengan akun Anda untuk mengakses dashboard.</p>
              
              <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                  <i class="fa-solid fa-circle-exclamation me-2"></i> <?= e($error) ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <form action="<?= BASE_URL ?>/login.php" method="POST">
                <?= csrfField() ?>
                
                <div class="form-group mb-3">
                  <label class="font-weight-bold text-dark mb-1">Username / Email</label>
                  <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-user text-muted"></i></span>
                    <input type="text" name="username" class="form-control border-start-0 form-control-lg text-dark" style="font-size: 0.95rem; padding: 0.75rem 1rem;" placeholder="Masukkan username atau email" value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                  </div>
                </div>
                
                <div class="form-group mb-4">
                  <label class="font-weight-bold text-dark mb-1">Password</label>
                  <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-lock text-muted"></i></span>
                    <input type="password" name="password" class="form-control border-start-0 form-control-lg text-dark" style="font-size: 0.95rem; padding: 0.75rem 1rem;" placeholder="Masukkan password" required>
                  </div>
                </div>

                <div class="mb-4">
                  <button type="submit" class="btn btn-primary btn-lg w-100 font-weight-bold shadow-sm py-3" style="border-radius: 10px; font-size: 1.05rem; letter-spacing: 0.5px;">
                    <i class="fa-solid fa-right-to-bracket me-2"></i> MASUK
                  </button>
                </div>

                <div class="mb-4 d-flex justify-content-between align-items-center">
                  <a href="<?= BASE_URL ?>" class="text-primary small font-weight-bold text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i> Kembali ke Beranda</a>
                </div>


              </form>
            </div>
          </div>
          
        </div>
      </div>
    </div>
  </div>

  <script src="<?= BASE_URL ?>/assets/vendors/js/vendor.bundle.base.js"></script>
</body>
</html>
