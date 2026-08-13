<?php
// app/Views/layouts/header.php
require_once __DIR__ . '/../../Helpers/functions.php';
require_once __DIR__ . '/../../Helpers/auth.php';
require_once __DIR__ . '/../../Helpers/permissions.php';
require_once __DIR__ . '/../../Helpers/csrf.php';

$pageTitle = $pageTitle ?? 'Dashboard';
$currentUser = getAuthUser();
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
  
  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <!-- Skydash & Vendor CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/feather/feather.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/ti-icons/css/themify-icons.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/css/vendor.bundle.base.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
  
  <!-- Premium Proportional Design & Clean Layout Overrides -->
  <style>
    :root {
      --primary-color: #4B49AC;
      --primary-hover: #3f3d91;
      --primary-light: #EEF2FF;
      --body-bg: #F5F7FF;
      --card-radius: 12px;
    }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
      font-size: 0.875rem;
      background-color: var(--body-bg);
      color: #2b2d42;
      -webkit-font-smoothing: antialiased;
    }

    /* Fixed Proportional Layout Structure */
    .container-scroller {
      min-height: 100vh;
    }
    .page-body-wrapper {
      padding-top: 60px !important;
      min-height: calc(100vh - 60px);
      display: flex !important;
      flex-direction: row !important;
    }

    /* Clean Navbar Fixes */
    .navbar {
      height: 60px !important;
      background: #ffffff !important;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05) !important;
    }
    .navbar .navbar-brand-wrapper {
      width: 240px !important;
      height: 60px !important;
    }
    .navbar .navbar-menu-wrapper {
      width: calc(100% - 240px) !important;
      height: 60px !important;
    }

    /* Clean Proportional Sidebar Navigation */
    .sidebar {
      width: 240px !important;
      min-width: 240px !important;
      max-width: 240px !important;
      background: #ffffff !important;
      border-right: 1px solid #eef2f6 !important;
      padding-top: 1rem !important;
    }
    .sidebar .nav {
      padding: 0 0.75rem !important;
    }
    .sidebar .nav .nav-item {
      margin-bottom: 0.2rem !important;
    }
    .sidebar .nav .nav-item .nav-link {
      padding: 0.6rem 0.9rem !important;
      border-radius: 8px !important;
      color: #475569 !important;
      font-weight: 500 !important;
      font-size: 0.875rem !important;
      display: flex !important;
      align-items: center !important;
      justify-content: flex-start !important;
      transition: all 0.2s ease !important;
      background: transparent !important;
      border: none !important;
      box-shadow: none !important;
      height: auto !important;
    }
    .sidebar .nav .nav-item .nav-link:hover {
      background-color: var(--primary-light) !important;
      color: var(--primary-color) !important;
    }
    .sidebar .nav .nav-item.active > .nav-link {
      background-color: var(--primary-color) !important;
      color: #ffffff !important;
      font-weight: 600 !important;
      box-shadow: 0 4px 12px rgba(75, 73, 172, 0.2) !important;
    }
    .sidebar .nav .nav-item .nav-link i.menu-icon {
      font-size: 1rem !important;
      width: 24px !important;
      margin-right: 0.75rem !important;
      text-align: center !important;
      color: #64748b;
    }
    .sidebar .nav .nav-item.active > .nav-link i.menu-icon {
      color: #ffffff !important;
    }
    .sidebar .nav .nav-item .nav-link .menu-title {
      display: inline-block !important;
      font-size: 0.875rem !important;
      line-height: 1.2 !important;
    }

    /* Main Content Panel */
    .main-panel {
      flex: 1 !important;
      width: calc(100% - 240px) !important;
      min-height: calc(100vh - 60px) !important;
      background-color: var(--body-bg) !important;
    }
    .content-wrapper {
      padding: 1.75rem 2rem !important;
      background-color: var(--body-bg) !important;
    }

    /* Cards & Stat Boxes Fix */
    .card {
      border: none !important;
      border-radius: var(--card-radius) !important;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03) !important;
    }
    .card:not([class*="bg-"]) {
      background: #ffffff !important;
    }
    .card-title {
      font-size: 1.05rem !important;
      color: #1e293b !important;
    }

    /* Tables */
    .table-responsive {
      border-radius: 8px !important;
    }
    .table {
      font-size: 0.85rem !important;
      color: #334155 !important;
    }
    .table th {
      font-weight: 600 !important;
      text-transform: uppercase !important;
      font-size: 0.75rem !important;
      letter-spacing: 0.5px !important;
      color: #64748b !important;
      background-color: #f8fafc !important;
      border-bottom: 1px solid #e2e8f0 !important;
      padding: 0.75rem 1rem !important;
    }
    .table td {
      padding: 0.85rem 1rem !important;
      vertical-align: middle !important;
      border-bottom: 1px solid #f1f5f9 !important;
    }

    /* Form Controls */
    .form-control, .form-select {
      border-radius: 8px !important;
      border: 1px solid #cbd5e1 !important;
      padding: 0.55rem 0.85rem !important;
      font-size: 0.875rem !important;
    }
    .form-control:focus, .form-select:focus {
      border-color: var(--primary-color) !important;
      box-shadow: 0 0 0 3px rgba(75, 73, 172, 0.15) !important;
    }
    .btn {
      border-radius: 8px !important;
      padding: 0.55rem 1.25rem !important;
      font-weight: 500 !important;
      font-size: 0.85rem !important;
    }

    @media print {
      .navbar, .sidebar, .footer, .btn, .no-print {
        display: none !important;
      }
      .main-panel {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
      }
      .content-wrapper {
        background: #fff !important;
        padding: 0 !important;
      }
    }
    @media (max-width: 991.98px) {
        body {
            padding-bottom: 75px !important;
        }
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            width: 100%;
            height: 60px;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 1030;
            box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.05);
            padding: 0;
        }
        .mobile-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #64748b;
            text-decoration: none !important;
            font-size: 0.65rem;
            font-weight: 600;
            flex: 1;
            height: 100%;
            transition: all 0.2s ease;
        }
        .mobile-nav-item i {
            font-size: 1.2rem;
            margin-bottom: 4px;
            color: #64748b;
            transition: all 0.2s ease;
        }
        .mobile-nav-item.active {
            color: var(--primary-color);
        }
        .mobile-nav-item.active i {
            color: var(--primary-color);
            transform: translateY(-2px);
        }
        /* Center Floating Action Button */
        .mobile-nav-item.center-fab {
            flex: 0 0 50px;
            height: 50px;
            margin-top: -24px;
            background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%);
            border-radius: 50%;
            box-shadow: 0 4px 12px rgba(75, 73, 172, 0.3);
            border: 3px solid #ffffff;
            z-index: 1035;
            justify-content: center;
        }
        .mobile-nav-item.center-fab i {
            font-size: 1.15rem;
            color: #ffffff !important;
            margin-bottom: 0;
            transform: none !important;
        }
        .mobile-nav-item.center-fab span {
            display: none;
        }
        .mobile-nav-item.center-fab:active {
            transform: scale(0.92);
        }
        /* Hide navbar toggler duplicate on mobile header */
        .navbar-toggler-right {
            display: none !important;
        }
        /* Slide in from the left on mobile for off-canvas sidebar */
        .sidebar-offcanvas {
            left: -235px !important;
            right: auto !important;
            top: 60px !important;
            height: calc(100vh - 60px) !important;
        }
        .sidebar-offcanvas.active {
            left: 0 !important;
            right: auto !important;
        }
    }
  </style>
</head>
<body>
  <div class="container-scroller">
