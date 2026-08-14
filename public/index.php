<?php
// public/index.php — Landing Page
require_once __DIR__ . '/../app/bootstrap.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$db = getDbConnection();
$company = $db->query("SELECT * FROM company_settings WHERE id = 1")->fetch() ?: [
    'company_name' => 'PT Gajiku Indonesia',
    'person_in_charge' => 'Manajer HRD'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
  <title>Beranda - <?= e(APP_NAME) ?></title>
  
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/css/vendor.bundle.base.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  
  <style>
    .hero-section {
        background: linear-gradient(135deg, #4B49AC 0%, #7978E9 100%);
        color: #fff;
        padding: 100px 0 80px 0;
        border-radius: 0 0 30px 30px;
    }
    .feature-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        transition: transform 0.3s ease;
    }
    .feature-card:hover {
        transform: translateY(-8px);
    }
    .feature-icon {
        width: 60px;
        height: 60px;
        border-radius: 15px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        margin-bottom: 1.25rem;
    }
  </style>
</head>
<body class="bg-light">

  <!-- Landing Navbar -->
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm py-3">
    <div class="container">
      <a class="navbar-brand fw-bold text-primary fs-4 d-flex align-items-center" href="<?= BASE_URL ?>">
        <i class="fa-solid fa-wallet me-2"></i> Gajiku
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#landingNav">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="landingNav">
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item me-3"><a class="nav-link text-dark font-weight-bold" href="#features">Fitur Utama</a></li>
          <li class="nav-item me-3"><a class="nav-link text-dark font-weight-bold" href="#about">Tentang Aplikasi</a></li>
          <li class="nav-item">
            <a class="btn btn-primary px-4 py-2 font-weight-bold rounded-pill shadow-sm" href="<?= BASE_URL ?>/login.php">
              <i class="fa-solid fa-right-to-bracket me-2"></i> Masuk ke Aplikasi
            </a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- Hero Section -->
  <header class="hero-section text-center text-lg-start">
    <div class="container">
      <div class="row align-items-center">
        <div class="col-lg-7 mb-4 mb-lg-0">
          <span class="badge bg-white text-primary font-weight-bold px-3 py-2 rounded-pill mb-3">Sistem Penggajian &amp; Tugas Karyawan</span>
          <h1 class="display-4 font-weight-bold mb-3">Kelola Gaji, Bonus, &amp; Tugas Karyawan Secara Akurat</h1>
          <p class="lead text-white-50 mb-4">Aplikasi manajemen internal terpadu untuk efisiensi penggajian berbasis performa, perhitungan bonus transparan, dan pengelolaan to-do list tim secara real-time.</p>
          <div class="d-flex flex-wrap gap-3 justify-content-center justify-content-lg-start">
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-warning btn-lg text-dark font-weight-bold px-4 py-3 rounded-pill shadow">
              <i class="fa-solid fa-arrow-right-to-bracket me-2"></i> Masuk Sekarang
            </a>
          </div>
        </div>
        <div class="col-lg-5 text-center">
          <div class="bg-white p-4 rounded-4 shadow-lg text-dark text-start">
            <h5 class="fw-bold mb-3 text-primary"><i class="fa-solid fa-shield-halved me-2"></i> Portal Keamanan Internal</h5>
            <p class="text-muted small">Sistem ini memfasilitasi audit trail lengkap, proteksi slip gaji terenkripsi, serta pemisahan hak akses berbasis peran (Super Admin, HR, Karyawan).</p>
            <hr>
            <div class="d-flex justify-content-between align-items-center">
              <div>
                <small class="text-muted d-block">Perusahaan</small>
                <strong><?= e($company['company_name']) ?></strong>
              </div>
              <span class="badge bg-success text-white">Sistem Aktif</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </header>

  <!-- Features Section -->
  <section id="features" class="py-5">
    <div class="container py-4">
      <div class="text-center mb-5">
        <h2 class="font-weight-bold text-dark">Fitur Unggulan</h2>
        <p class="text-muted">Dirancang khusus untuk mendukung operasional perusahaan modern</p>
      </div>

      <div class="row g-4">
        <div class="col-md-4">
          <div class="card feature-card p-4 h-100 bg-white">
            <div class="feature-icon bg-primary text-white">
              <i class="fa-solid fa-calculator"></i>
            </div>
            <h4 class="font-weight-bold mb-2">Payroll &amp; Snapshot</h4>
            <p class="text-muted mb-0">Kalkulasi gaji otomatis mencakup gaji pokok, bonus individu, bonus tim, dan potongan. Data payroll final tersimpan permanen tanpa risiko eror akibat perubahan master data.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card feature-card p-4 h-100 bg-white">
            <div class="feature-icon bg-success text-white">
              <i class="fa-solid fa-tasks"></i>
            </div>
            <h4 class="font-weight-bold mb-2">Task Management</h4>
            <p class="text-muted mb-0">Distribusi tugas untuk karyawan individu, tim, atau divisi. Dilengkapi pelacakan status real-time, histori progres, dan integrasi pengajuan bonus berbasis pencapaian.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card feature-card p-4 h-100 bg-white">
            <div class="feature-icon bg-warning text-dark">
              <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <h4 class="font-weight-bold mb-2">Slip Gaji Transparan</h4>
            <p class="text-muted mb-0">Cetak dan download slip gaji resmi dengan format standar Indonesia. Setiap karyawan dapat melihat rincian gaji dan bonus secara transparan melalui portal mandiri.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-white border-top py-4 text-center">
    <div class="container">
      <p class="text-muted mb-0">&copy; <?= date('Y') ?> <strong><?= e($company['company_name']) ?></strong>. All rights reserved.</p>
    </div>
  </footer>

  <script src="<?= BASE_URL ?>/assets/vendors/js/vendor.bundle.base.js"></script>
</body>
</html>
