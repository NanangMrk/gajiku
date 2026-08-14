<?php
// app/Views/pages/payslip-print.php — Standalone minimalist print template
require_once __DIR__ . '/../../bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    die('ID Slip Gaji tidak valid.');
}

$stmtSel = $db->prepare("
    SELECT p.*, pp.name as period_name, pp.start_date, pp.end_date, pp.payment_date, pp.status as period_status
    FROM payrolls p
    JOIN payroll_periods pp ON p.payroll_period_id = pp.id
    WHERE p.id = ? AND pp.status IN ('review', 'final')
");
$stmtSel->execute([$id]);
$payslip = $stmtSel->fetch();

if (!$payslip) {
    die('Dokumen Slip Gaji tidak ditemukan.');
}

// Ownership check for employees
if ($user['role_slug'] === 'employee' && (int)$payslip['employee_id'] !== (int)$user['employee_id']) {
    die('Akses Ditolak: Anda tidak memiliki akses ke slip gaji ini.');
}

$stmtItems = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? ORDER BY id ASC");
$stmtItems->execute([$payslip['id']]);
$payrollItems = $stmtItems->fetchAll();

$company = $db->query("SELECT * FROM company_settings WHERE id = 1")->fetch() ?: [
    'company_name' => 'PT Gajiku Indonesia',
    'address' => 'Jakarta, Indonesia',
    'phone' => '021-5556789',
    'person_in_charge' => 'HRD Manager'
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, viewport-fit=cover">
  <title>SLIP GAJI - <?= e($payslip['payslip_number']) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendors/css/vendor.bundle.base.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  
  <style>
    body {
        background-color: #f8fafc;
        font-family: 'Inter', sans-serif;
        color: #334155;
        margin: 0;
        padding: 0;
    }
    .action-bar {
        background-color: #ffffff;
        border-bottom: 1px solid #e2e8f0;
        padding: 12px 0;
        position: sticky;
        top: 0;
        z-index: 100;
    }
    .btn-back {
        background-color: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
        font-size: 0.8rem;
        font-weight: 500;
        border-radius: 6px;
        padding: 6px 14px;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-back:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }
    .btn-print-action {
        background-color: #0f172a;
        color: #ffffff;
        border: none;
        font-size: 0.8rem;
        font-weight: 500;
        border-radius: 6px;
        padding: 6px 16px;
        transition: all 0.15s ease;
    }
    .btn-print-action:hover {
        background-color: #1e293b;
    }
    .btn-receipt-action {
        background-color: #f0fdf4;
        color: #166534;
        border: 1px solid #bbf7d0;
        font-size: 0.8rem;
        font-weight: 500;
        border-radius: 6px;
        padding: 6px 14px;
        text-decoration: none;
        transition: all 0.15s ease;
    }
    .btn-receipt-action:hover {
        background-color: #dcfce7;
    }
    
    /* Payslip Layout */
    .payslip-wrapper {
        max-width: 780px;
        margin: 30px auto;
        padding: 0 15px;
    }
    .print-card {
        background: #ffffff;
        padding: 40px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    
    .divider {
        border-top: 1px solid #e2e8f0;
        margin: 20px 0;
    }
    .divider-double {
        border-top: 3px double #334155;
        margin: 20px 0;
    }
    
    /* Profile Table */
    .profile-table {
        width: 100%;
        margin-bottom: 25px;
    }
    .profile-table td {
        padding: 4px 0;
        font-size: 0.85rem;
        vertical-align: top;
    }
    .profile-table .label {
        color: #64748b;
        width: 120px;
    }
    .profile-table .value {
        font-weight: 600;
        color: #0f172a;
    }
    
    /* Breakdown Grid */
    .breakdown-row {
        display: flex;
        gap: 40px;
    }
    .breakdown-col {
        flex: 1;
    }
    .col-title {
        font-size: 0.8rem;
        font-weight: 700;
        color: #0f172a;
        text-transform: uppercase;
        margin-bottom: 12px;
        letter-spacing: 0.5px;
    }
    .breakdown-table {
        width: 100%;
    }
    .breakdown-table td {
        padding: 6px 0;
        font-size: 0.85rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .breakdown-table tr:last-child td {
        border-bottom: none;
    }
    .item-name {
        color: #475569;
    }
    .item-amount {
        text-align: right;
        font-weight: 600;
        color: #0f172a;
    }
    
    /* Take Home Pay Box */
    .thp-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
    }
    .thp-label {
        font-weight: 700;
        font-size: 0.85rem;
        color: #0f172a;
    }
    .thp-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: #0f172a;
    }
    
    /* Signatures */
    .signature-container {
        display: flex;
        justify-content: space-between;
        margin-top: 40px;
        padding: 0 10px;
    }
    .signature-box {
        text-align: center;
        width: 200px;
    }
    .signature-title {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 50px;
    }
    .signature-name {
        font-size: 0.85rem;
        font-weight: 600;
        color: #0f172a;
        border-bottom: 1px solid #475569;
        display: inline-block;
        padding-bottom: 2px;
        min-width: 150px;
    }
    
    @media print {
        body {
            background-color: #ffffff;
            color: #000000;
        }
        .action-bar {
            display: none !important;
        }
        .payslip-wrapper {
            margin: 0 !important;
            max-width: 100% !important;
            padding: 0 !important;
        }
        .print-card {
            border: none !important;
            padding: 0 !important;
        }
    }
  </style>
</head>
<body>

  <!-- Top Action Bar -->
  <div class="action-bar no-print">
    <div class="container d-flex justify-content-between align-items-center" style="max-width: 780px;">
      <a href="<?= BASE_URL ?>/payslips.php" class="btn btn-back d-flex align-items-center">
        <i class="fa-solid fa-arrow-left me-1.5"></i> Kembali
      </a>
      <div class="d-flex gap-2">
        <?php if (!empty($payslip['transfer_receipt'])): ?>
          <a href="<?= BASE_URL . $payslip['transfer_receipt'] ?>" target="_blank" class="btn btn-receipt-action d-flex align-items-center">
            <i class="fa-solid fa-receipt me-1.5"></i> Bukti Transfer
          </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-print-action d-flex align-items-center">
          <i class="fa-solid fa-print me-1.5"></i> Cetak / Ekspor PDF
        </button>
      </div>
    </div>
  </div>

  <div class="payslip-wrapper">
    <div class="print-card">
      <?php if (isset($payslip['period_status']) && $payslip['period_status'] === 'review'): ?>
        <div class="alert alert-warning text-center font-weight-bold mb-4 no-print" style="border: 1px solid #ffe58f; background-color: #fffbe6; color: #d46b08; border-radius: 6px; font-size: 0.8rem;">
          <i class="fa-solid fa-triangle-exclamation me-1"></i> MODE PRATINJAU DRAFT (BELUM DIFINALISASI)
        </div>
      <?php endif; ?>
      
      <!-- Company Header -->
      <table style="width: 100%;">
        <tr>
          <td>
            <h4 class="font-weight-bold text-dark mb-1" style="letter-spacing: -0.5px;"><?= e($company['company_name']) ?></h4>
            <p class="text-muted small mb-0" style="font-size: 0.75rem;">
              <?= e($company['address']) ?> &bull; Telp: <?= e($company['phone']) ?>
            </p>
          </td>
          <td style="text-align: right; vertical-align: top;">
            <h5 class="font-weight-bold text-dark mb-1" style="text-transform: uppercase; letter-spacing: 0.5px; font-size: 0.95rem;">SLIP GAJI KARYAWAN</h5>
            <small class="text-muted" style="font-size: 0.8rem;">No: <?= e($payslip['payslip_number']) ?></small>
          </td>
        </tr>
      </table>
      
      <div class="divider"></div>
      
      <!-- Profile Information -->
      <table class="profile-table">
        <tr>
          <td class="label">Nama Karyawan</td>
          <td class="value">: &nbsp;<?= e($payslip['employee_name_snapshot']) ?></td>
          <td class="label" style="padding-left: 40px;">Periode Gaji</td>
          <td class="value">: &nbsp;<?= e($payslip['period_name']) ?></td>
        </tr>
        <tr>
          <td class="label">NIK Karyawan</td>
          <td class="value">: &nbsp;<?= e($payslip['nik_snapshot']) ?></td>
          <td class="label" style="padding-left: 40px;">Rentang Kerja</td>
          <td class="value">: &nbsp;<?= formatTanggalIndo($payslip['start_date']) ?> - <?= formatTanggalIndo($payslip['end_date']) ?></td>
        </tr>
        <tr>
          <td class="label">Divisi / Jabatan</td>
          <td class="value">: &nbsp;<?= e($payslip['division_snapshot'] ?? '-') ?> / <?= e($payslip['position_snapshot'] ?? '-') ?></td>
          <td class="label" style="padding-left: 40px;">Tanggal Bayar</td>
          <td class="value">: &nbsp;<?= formatTanggalIndo($payslip['payment_date']) ?></td>
        </tr>
      </table>
      
      <div class="divider"></div>
      
      <!-- Earnings & Deductions Breakdown -->
      <div class="breakdown-row">
        <!-- Earnings Column -->
        <div class="breakdown-col" style="border-right: 1px solid #e2e8f0; padding-right: 20px;">
          <h6 class="col-title">1. Pendapatan</h6>
          <table class="breakdown-table">
            <tbody>
              <?php foreach ($payrollItems as $item): ?>
                <?php if (in_array($item['type'], ['basic', 'individual_bonus', 'team_bonus', 'adjustment']) && ($item['type'] === 'basic' || (float)$item['amount'] > 0)): ?>
                  <tr>
                    <td class="item-name">
                      <?= e($item['name']) ?>
                      <?= ($item['type'] !== 'basic' && isset($item['qty']) && $item['qty'] > 1) ? '<span class="text-muted small"> (x' . $item['qty'] . ')</span>' : '' ?>
                    </td>
                    <td class="item-amount"><?= formatRupiah($item['amount']) ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <!-- Deductions Column -->
        <div class="breakdown-col">
          <h6 class="col-title">2. Potongan</h6>
          <table class="breakdown-table">
            <tbody>
              <?php 
                $hasDeduction = false;
                foreach ($payrollItems as $item): 
              ?>
                <?php if ($item['type'] === 'deduction' && (float)$item['amount'] > 0): $hasDeduction = true; ?>
                  <tr>
                    <td class="item-name">
                      <?= e($item['name']) ?>
                      <?= (isset($item['qty']) && $item['qty'] > 1) ? '<span class="text-muted small"> (x' . $item['qty'] . ')</span>' : '' ?>
                    </td>
                    <td class="item-amount">-<?= formatRupiah($item['amount']) ?></td>
                  </tr>
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (!$hasDeduction): ?>
                <tr>
                  <td class="item-name text-muted italic">Tidak ada potongan</td>
                  <td class="item-amount">Rp0</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      
      <div class="divider-double"></div>
      
      <!-- Take Home Pay Box -->
      <div class="thp-row">
        <span class="thp-label">TOTAL GAJI BERSIH (TAKE HOME PAY)</span>
        <span class="thp-value"><?= formatRupiah($payslip['take_home_pay']) ?></span>
      </div>
      
      <div class="divider"></div>
      
      <!-- Signatures -->
      <div class="signature-container">
        <div class="signature-box">
          <p class="signature-title">Karyawan Penerima,</p>
          <p class="signature-name"><?= e($payslip['employee_name_snapshot']) ?></p>
        </div>
        <div class="signature-box">
          <p class="signature-title">Manajer Keuangan,</p>
          <p class="signature-name"><?= e($company['person_in_charge'] ?? 'HRD Manager') ?></p>
        </div>
      </div>
      
    </div>
  </div>

</body>
</html>
