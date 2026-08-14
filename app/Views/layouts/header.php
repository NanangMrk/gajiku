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
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
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
    html, body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
      font-size: 0.875rem;
      background-color: var(--body-bg);
      color: #2b2d42;
      -webkit-font-smoothing: antialiased;
      -webkit-tap-highlight-color: transparent;
      touch-action: manipulation;
      overflow-x: hidden;
      max-width: 100%;
    }

    /* Fixed Proportional Layout Structure */
    .container-scroller {
      min-height: 100vh;
      width: 100%;
      overflow-x: hidden;
    }

    /* Desktop Styles (>= 992px) */
    @media (min-width: 992px) {
      .page-body-wrapper {
        padding-top: 60px !important;
        min-height: calc(100vh - 60px);
        display: flex !important;
        flex-direction: row !important;
      }
      .navbar {
        height: 60px !important;
        background: #ffffff !important;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05) !important;
      }
      .navbar .navbar-brand-wrapper {
        width: 240px !important;
        height: 60px !important;
        transition: width 0.25s ease !important;
      }
      .navbar .navbar-brand-wrapper .brand-logo-mini {
        display: none !important;
      }
      .navbar .navbar-menu-wrapper {
        width: calc(100% - 240px) !important;
        height: 60px !important;
        transition: width 0.25s ease !important;
      }
      .sidebar {
        width: 240px !important;
        min-width: 240px !important;
        max-width: 240px !important;
        background: #ffffff !important;
        border-right: 1px solid #eef2f6 !important;
        padding-top: 1rem !important;
        transition: width 0.25s ease !important;
      }
      .main-panel {
        flex: 1 !important;
        width: calc(100% - 240px) !important;
        min-height: calc(100vh - 60px) !important;
        background-color: var(--body-bg) !important;
        transition: width 0.25s ease !important;
      }
      .content-wrapper {
        padding: 1.75rem 2rem !important;
        background-color: var(--body-bg) !important;
      }

      /* Desktop Collapse Mode (sidebar-icon-only) */
      body.sidebar-icon-only .navbar .navbar-brand-wrapper {
        width: 70px !important;
        min-width: 70px !important;
        max-width: 70px !important;
        padding: 0 !important;
        justify-content: center !important;
      }
      body.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo {
        display: none !important;
      }
      body.sidebar-icon-only .navbar .navbar-brand-wrapper .brand-logo-mini {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 100%;
        text-align: center;
      }
      body.sidebar-icon-only .navbar .navbar-menu-wrapper {
        width: calc(100% - 70px) !important;
      }
      body.sidebar-icon-only .sidebar {
        width: 70px !important;
        min-width: 70px !important;
        max-width: 70px !important;
      }
      body.sidebar-icon-only .sidebar .nav {
        padding: 0 0.25rem !important;
      }
      body.sidebar-icon-only .sidebar .nav .nav-item {
        margin-bottom: 0.35rem !important;
      }
      body.sidebar-icon-only .sidebar .nav .nav-item .nav-link {
        padding: 0.65rem 0.25rem !important;
        justify-content: center !important;
        text-align: center !important;
      }
      body.sidebar-icon-only .sidebar .nav .nav-item .nav-link i.menu-icon {
        margin-right: 0 !important;
        font-size: 1.15rem !important;
        width: auto !important;
      }
      body.sidebar-icon-only .sidebar .nav .nav-item .nav-link .menu-title,
      body.sidebar-icon-only .sidebar .nav .nav-category {
        display: none !important;
      }
      body.sidebar-icon-only .main-panel {
        width: calc(100% - 70px) !important;
      }
    }

    /* Shared Sidebar Navigation Items */
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

    /* Cards & Stat Boxes */
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

    /* Tables & Responsive Scrolling */
    .table-responsive {
      border-radius: 8px !important;
      -webkit-overflow-scrolling: touch !important;
      scrollbar-width: thin;
    }
    .table-responsive::-webkit-scrollbar {
      height: 6px;
    }
    .table-responsive::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }
    .table {
      font-size: 0.85rem !important;
      color: #334155 !important;
      margin-bottom: 0 !important;
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
      white-space: nowrap !important;
    }
    .table td {
      padding: 0.85rem 1rem !important;
      vertical-align: middle !important;
      border-bottom: 1px solid #f1f5f9 !important;
      white-space: nowrap !important;
    }
    .table td.text-wrap-cell {
      white-space: normal !important;
    }

    /* Form Controls */
    .form-control, .form-select {
      border-radius: 8px !important;
      border: 1px solid #cbd5e1 !important;
      padding: 0.55rem 0.85rem !important;
      font-size: 0.875rem;
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
      transition: all 0.2s ease !important;
    }
    .btn:active {
      transform: scale(0.97);
    }

    /* Sidebar Backdrop Overlay */
    .sidebar-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100vw;
      height: 100vh;
      height: 100dvh;
      background: rgba(15, 23, 42, 0.55);
      backdrop-filter: blur(3px);
      -webkit-backdrop-filter: blur(3px);
      z-index: 1055;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.28s ease;
    }
    .sidebar-backdrop.active {
      opacity: 1;
      pointer-events: auto;
    }
    body.sidebar-mobile-open {
      overflow: hidden !important;
    }

    @media print {
      .navbar, .sidebar, .footer, .btn, .no-print, .mobile-bottom-nav, .sidebar-backdrop {
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

    /* Mobile & Tablet Styles (< 992px) */
    @media (max-width: 991.98px) {
      body {
        padding-bottom: calc(65px + env(safe-area-inset-bottom, 0px)) !important;
      }
      .page-body-wrapper {
        padding-top: 58px !important;
        min-height: calc(100vh - 58px) !important;
        display: flex !important;
        flex-direction: column !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
      }
      .navbar {
        height: 58px !important;
        min-height: 58px !important;
        background: #ffffff !important;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06) !important;
        padding: 0 0.75rem !important;
      }
      .navbar .navbar-brand-wrapper {
        width: auto !important;
        min-width: auto !important;
        max-width: 160px !important;
        height: 58px !important;
        padding: 0 !important;
        justify-content: flex-start !important;
      }
      .navbar .navbar-brand.brand-logo {
        font-size: 1.2rem !important;
      }
      .navbar .navbar-brand.brand-logo-mini {
        display: none !important;
      }
      .navbar .navbar-menu-wrapper {
        width: auto !important;
        flex: 1 !important;
        height: 58px !important;
        padding: 0 !important;
        justify-content: flex-end !important;
      }
      .navbar-toggler-mobile-menu {
        display: inline-flex !important;
        align-items: center;
        justify-content: center;
        width: 38px;
        height: 38px;
        border-radius: 8px;
        border: none;
        background: #f1f5f9;
        color: #334155;
        font-size: 1.1rem;
        margin-right: 0.5rem;
      }
      .navbar-toggler-mobile-menu:active {
        background: #e2e8f0;
      }
      
      /* Main Panel full width */
      .main-panel {
        width: 100% !important;
        max-width: 100% !important;
        flex: 1 1 100% !important;
        margin-left: 0 !important;
        min-height: calc(100vh - 58px) !important;
        background-color: var(--body-bg) !important;
      }
      .content-wrapper {
        padding: 1rem 0.85rem calc(4.5rem + env(safe-area-inset-bottom, 0px)) 0.85rem !important;
        width: 100% !important;
        max-width: 100% !important;
      }

      /* Slide-over Off-canvas Sidebar */
      .sidebar-offcanvas {
        position: fixed !important;
        top: 0 !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 320px !important;
        min-width: 290px !important;
        max-width: 86vw !important;
        height: 100vh !important;
        height: 100dvh !important;
        max-height: 100vh !important;
        max-height: 100dvh !important;
        z-index: 1065 !important;
        transform: translateX(-105%) !important;
        transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1) !important;
        background: #ffffff !important;
        overflow-y: auto !important;
        -webkit-overflow-scrolling: touch !important;
        box-shadow: none !important;
        border-right: 1px solid rgba(0, 0, 0, 0.1) !important;
        padding-top: 0 !important;
        padding-bottom: calc(90px + env(safe-area-inset-bottom, 20px)) !important;
        display: flex !important;
        flex-direction: column !important;
      }
      .sidebar-offcanvas.active {
        transform: translateX(0) !important;
        box-shadow: 15px 0 40px rgba(0, 0, 0, 0.28) !important;
      }
      .sidebar-offcanvas .nav {
        padding: 0 0.85rem !important;
        flex: 1 1 auto;
      }
      .sidebar-offcanvas .nav .nav-item {
        margin-bottom: 0.35rem !important;
      }
      .sidebar-offcanvas .nav .nav-item .nav-link {
        padding: 0.75rem 1rem !important;
        font-size: 0.92rem !important;
        border-radius: 10px !important;
      }
      .sidebar-offcanvas .nav .nav-item .nav-link i.menu-icon {
        font-size: 1.15rem !important;
        width: 28px !important;
        margin-right: 0.85rem !important;
      }
      .sidebar-offcanvas .nav .nav-item .nav-link .menu-title {
        font-size: 0.92rem !important;
        font-weight: 500 !important;
      }
      .sidebar-offcanvas .nav .nav-category {
        margin-top: 1rem !important;
        margin-bottom: 0.35rem !important;
      }

      /* Mobile Bottom Navigation Bar */
      .mobile-bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        width: 100%;
        height: calc(60px + env(safe-area-inset-bottom, 0px));
        padding-bottom: env(safe-area-inset-bottom, 0px);
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-around;
        align-items: center;
        z-index: 1040;
        box-shadow: 0 -3px 16px rgba(0, 0, 0, 0.06);
        padding-left: 0.25rem;
        padding-right: 0.25rem;
      }
      .mobile-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #64748b;
        text-decoration: none !important;
        font-size: 0.68rem;
        font-weight: 600;
        flex: 1;
        height: 100%;
        transition: all 0.2s ease;
        -webkit-tap-highlight-color: transparent;
      }
      .mobile-nav-item i {
        font-size: 1.25rem;
        margin-bottom: 3px;
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
        flex: 0 0 48px;
        height: 48px;
        margin-top: -22px;
        background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%);
        border-radius: 50%;
        box-shadow: 0 4px 14px rgba(75, 73, 172, 0.4);
        border: 3px solid #ffffff;
        z-index: 1045;
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

      /* Form inputs 16px to prevent iOS auto zoom */
      .form-control, .form-select {
        font-size: 16px !important;
      }

      /* Compact table paddings on small screens */
      .table th, .table td {
        padding: 0.65rem 0.75rem !important;
        font-size: 0.8rem !important;
      }
      
      /* Modals on mobile */
      .modal-dialog {
        margin: 0.75rem auto !important;
        max-width: calc(100% - 1.5rem) !important;
      }
    }
  </style>
</head>
<body>
  <div class="container-scroller">
