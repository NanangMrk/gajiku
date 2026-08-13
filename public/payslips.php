<?php
// payslips.php
require_once __DIR__ . '/../app/bootstrap.php';

$user = requireLogin();
$db = getDbConnection();

$pageTitle = 'Slip Gaji Karyawan';

// Filters
$search = trim($_GET['search'] ?? '');
$periodFilter = !empty($_GET['period_id']) ? (int)$_GET['period_id'] : 0;
$statusFilter = $_GET['status'] ?? ''; // 'paid', 'pending'

// Pagination Config
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 5);
if (!in_array($limit, [5, 10, 25, 100, 999999])) {
    $limit = 5;
}
$offset = ($page - 1) * $limit;

$where = "WHERE pp.status IN ('review', 'final')";
$params = [];

if ($user['role_slug'] === 'employee') {
    $where .= " AND p.employee_id = ?";
    $params[] = $user['employee_id'] ?? 0;
}

if (!empty($search)) {
    $sql_search = "%$search%";
    $where .= " AND (p.payslip_number LIKE ? OR p.employee_name_snapshot LIKE ?)";
    $params[] = $sql_search;
    $params[] = $sql_search;
}

if ($periodFilter > 0) {
    $where .= " AND p.payroll_period_id = ?";
    $params[] = $periodFilter;
}

if ($statusFilter === 'paid') {
    $where .= " AND p.transfer_receipt IS NOT NULL";
} elseif ($statusFilter === 'pending') {
    $where .= " AND p.transfer_receipt IS NULL AND pp.status = 'final'";
}

// 1. Get total record count matching filters
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM payrolls p
    JOIN payroll_periods pp ON p.payroll_period_id = pp.id
    $where
");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $limit));

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// 2. Fetch paginated records
$sql = "
    SELECT p.*, pp.name as period_name, pp.payment_date, pp.status as period_status
    FROM payrolls p
    JOIN payroll_periods pp ON p.payroll_period_id = pp.id
    $where
    ORDER BY p.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payslips = $stmt->fetchAll();

// Fetch periods for filter dropdown
$periods = $db->query("SELECT id, name FROM payroll_periods WHERE status IN ('review', 'final') ORDER BY id DESC")->fetchAll();

$selectedId = (int)($_GET['id'] ?? 0);
if ($selectedId <= 0 && $user['role_slug'] === 'employee' && !empty($payslips)) {
    $selectedId = (int)$payslips[0]['id'];
}
$selectedPayslip = null;
$payrollItems = [];
$company = $db->query("SELECT * FROM company_settings WHERE id = 1")->fetch() ?: ['company_name' => 'PT Gajiku Indonesia'];

if ($selectedId > 0) {
    $stmtSel = $db->prepare("
        SELECT p.*, pp.name as period_name, pp.start_date, pp.end_date, pp.payment_date, pp.status as period_status
        FROM payrolls p
        JOIN payroll_periods pp ON p.payroll_period_id = pp.id
        WHERE p.id = ? AND pp.status IN ('review', 'final')
    ");
    $stmtSel->execute([$selectedId]);
    $selectedPayslip = $stmtSel->fetch();

    if ($selectedPayslip && $user['role_slug'] === 'employee' && (int)$selectedPayslip['employee_id'] !== (int)$user['employee_id']) {
        setFlashMessage('danger', 'Anda tidak memiliki hak akses untuk melihat slip gaji karyawan lain.');
        header('Location: ' . BASE_URL . '/payslips.php');
        exit;
    }

    if ($selectedPayslip) {
        $stmtItems = $db->prepare("SELECT * FROM payroll_items WHERE payroll_id = ? ORDER BY id ASC");
        $stmtItems->execute([$selectedPayslip['id']]);
        $payrollItems = $stmtItems->fetchAll();
    }
}

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
          <h3 class="font-weight-bold mb-0">Slip Gaji Karyawan</h3>
          <p class="text-muted mb-0">Arsip dokumen slip gaji resmi yang telah difinalisasi</p>
        </div>
      </div>

      <!-- Filters & Search -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body py-3">
          <form action="<?= BASE_URL ?>/payslips.php" method="GET">
            <div class="row g-2 align-items-center">
              
              <!-- Search bar (Selalu Terlihat) -->
              <div class="col-8 col-md-4">
                <input type="text" name="search" class="form-control form-control-sm rounded-3" placeholder="Cari NIK, nama, atau no slip..." value="<?= e($search) ?>">
              </div>
              
              <!-- Tombol Pemicu Collapse (Hanya Mobile) -->
              <div class="col-4 d-md-none text-end">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100 rounded-3 font-weight-bold" data-bs-toggle="collapse" data-bs-target="#advancedFiltersCollapse" aria-expanded="false" style="padding: 0.55rem 0.5rem !important;">
                  <i class="fa-solid fa-sliders me-1"></i> Filter
                </button>
              </div>

              <!-- Advanced Filters Panel (Collapsed di Mobile, Eksplisit di Desktop) -->
              <div class="col-12 col-md-8 collapse d-md-block" id="advancedFiltersCollapse">
                <div class="row g-2 mt-1 mt-md-0">
                  <div class="col-md-5">
                    <select name="period_id" class="form-select form-control form-control-sm rounded-3">
                      <option value="">-- Pilih Periode --</option>
                      <?php foreach ($periods as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $periodFilter === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <select name="status" class="form-select form-control form-control-sm rounded-3">
                      <option value="">-- Status Bayar --</option>
                      <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Lunas / Ditransfer</option>
                      <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending / Proses</option>
                    </select>
                  </div>
                  <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100 rounded-pill font-weight-bold"><i class="fa-solid fa-filter me-1"></i> Cari</button>
                    <a href="<?= BASE_URL ?>/payslips.php" class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;"><i class="fa-solid fa-rotate-right"></i></a>
                  </div>
                </div>
              </div>
              
            </div>
          </form>
        </div>
      </div>

      <style>
        .table-responsive thead th {
          position: sticky;
          top: 0;
          background-color: #ffffff !important;
          z-index: 10;
          box-shadow: inset 0 -1px 0 #e2e8f0;
        }
      </style>

      <!-- Payslips List Table -->
      <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
          <?php if (empty($payslips)): ?>
            <div class="text-center py-5 text-muted">
              <i class="fa-solid fa-file-invoice-dollar fs-1 mb-2 d-block text-secondary"></i>
              Belum ada slip gaji yang tersedia.
            </div>
          <?php else: ?>
            <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th>Nomor Slip</th>
                    <th>Periode</th>
                    <th>Nama Karyawan</th>
                    <th>Divisi &amp; Jabatan</th>
                    <th>Take Home Pay</th>
                    <th>Tanggal Pembayaran</th>
                    <th>Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($payslips as $ps): ?>
                    <tr onclick="window.location.href = '<?= BASE_URL ?>/payslips.php?id=<?= $ps['id'] ?>&amp;page=<?= $page ?>&amp;limit=<?= $limit ?>&amp;search=<?= urlencode($search) ?>&amp;period_id=<?= $periodFilter ?>&amp;status=<?= urlencode($statusFilter) ?>';" style="cursor: pointer;" class="<?= $selectedId === (int)$ps['id'] ? 'table-active font-weight-bold' : '' ?>">
                      <td>
                        <div class="font-weight-bold text-primary"><?= e($ps['payslip_number']) ?></div>
                        <?php if (!empty($ps['transfer_receipt'])): ?>
                          <span class="badge bg-success" style="font-size: 0.65rem; padding: 2px 6px;"><i class="fa-solid fa-circle-check"></i> Lunas</span>
                        <?php elseif ($ps['period_status'] === 'final'): ?>
                          <span class="badge bg-secondary text-dark" style="font-size: 0.65rem; padding: 2px 6px; background-color: #e2e8f0 !important;"><i class="fa-solid fa-clock"></i> Pending</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <?= e($ps['period_name']) ?>
                        <?php if ($ps['period_status'] === 'review'): ?>
                          <span class="badge bg-warning text-dark ms-1">Real-time</span>
                        <?php endif; ?>
                      </td>
                      <td class="font-weight-bold"><?= e($ps['employee_name_snapshot']) ?></td>
                      <td><?= e($ps['division_snapshot'] ?? '-') ?> (<?= e($ps['position_snapshot'] ?? '-') ?>)</td>
                      <td class="font-weight-bold text-success fs-6"><?= formatRupiah($ps['take_home_pay']) ?></td>
                      <td><?= formatTanggalIndo($ps['payment_date']) ?></td>
                      <td>
                        <a href="<?= BASE_URL ?>/payslips.php?id=<?= $ps['id'] ?>" class="btn btn-xs btn-primary rounded-pill me-1" onclick="event.stopPropagation();">
                          <i class="fa-solid fa-eye me-1"></i> Lihat
                        </a>
                        <a href="<?= BASE_URL ?>/payslip-print.php?id=<?= $ps['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary rounded-pill" onclick="event.stopPropagation();">
                          <i class="fa-solid fa-print me-1"></i> Cetak
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ($totalItems > 0): ?>
              <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3 mt-4 pt-3 border-top">
                <div class="text-muted small d-flex align-items-center flex-wrap gap-2">
                  <span>
                    Menampilkan <?= count($payslips) ?> dari <?= $totalItems ?> slip gaji (Halaman <?= $page ?> dari <?= $totalPages ?>)
                  </span>
                  <span>&bull;</span>
                  <div class="d-flex align-items-center gap-1">
                    <span class="small text-muted">Tampilkan:</span>
                    <select class="form-select form-select-sm d-inline-block w-auto border-0 py-0 px-1 bg-transparent font-weight-bold" onchange="window.location.href = this.value;" style="box-shadow: none; font-size: 0.8rem; cursor: pointer; color: #4B49AC;">
                      <?php
                        $limits = [5 => '5', 10 => '10', 25 => '25', 100 => '100', 999999 => 'Semua'];
                        foreach ($limits as $lVal => $lLabel):
                          $optParams = $_GET;
                          $optParams['limit'] = $lVal;
                          $optParams['page'] = 1;
                          $optUrl = BASE_URL . '/payslips.php?' . http_build_query($optParams);
                      ?>
                        <option value="<?= $optUrl ?>" <?= ($limit === $lVal) ? 'selected' : '' ?>><?= $lLabel ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                
                <?php if ($totalPages > 1): ?>
                  <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                      <!-- Previous Page -->
                      <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <?php 
                          $prevParams = $_GET;
                          $prevParams['page'] = $page - 1;
                          $prevUrl = BASE_URL . '/payslips.php?' . http_build_query($prevParams);
                        ?>
                        <a class="page-link" href="<?= $page <= 1 ? '#' : $prevUrl ?>" aria-label="Previous">
                          <span aria-hidden="true">&laquo;</span>
                        </a>
                      </li>

                      <!-- Page Numbers -->
                      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php 
                          $numParams = $_GET;
                          $numParams['page'] = $i;
                          $numUrl = BASE_URL . '/payslips.php?' . http_build_query($numParams);
                        ?>
                        <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                          <a class="page-link" href="<?= $numUrl ?>"><?= $i ?></a>
                        </li>
                      <?php endfor; ?>

                      <!-- Next Page -->
                      <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <?php 
                          $nextParams = $_GET;
                          $nextParams['page'] = $page + 1;
                          $nextUrl = BASE_URL . '/payslips.php?' . http_build_query($nextParams);
                        ?>
                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : $nextUrl ?>" aria-label="Next">
                          <span aria-hidden="true">&raquo;</span>
                        </a>
                      </li>
                    </ul>
                  </nav>
                <?php endif; ?>
              </div>
            <?php endif; ?>

          <?php endif; ?>
        </div>
      </div>

      <!-- Selected Payslip Preview Card -->
      <?php if ($selectedPayslip): ?>
        <div class="card shadow-sm border rounded-4 mb-4">
          <div class="card-header bg-white d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 py-3 border-bottom">
            <h5 class="mb-0 font-weight-bold text-dark d-flex align-items-center flex-wrap gap-1">
              <i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> Preview: <span class="text-primary"><?= e($selectedPayslip['payslip_number']) ?></span>
              <?php if ($selectedPayslip['period_status'] === 'review'): ?>
                <span class="badge bg-warning text-dark ms-1" style="font-size: 0.75rem;">Real-time</span>
              <?php endif; ?>
            </h5>
            <div class="d-flex align-items-center gap-2 flex-wrap w-100 w-sm-auto justify-content-end">
              <?php if (!empty($selectedPayslip['transfer_receipt'])): ?>
                <a href="<?= BASE_URL . $selectedPayslip['transfer_receipt'] ?>" target="_blank" class="btn btn-sm btn-success rounded-pill font-weight-bold">
                  <i class="fa-solid fa-receipt me-1"></i> Bukti<span class="d-none d-sm-inline"> Transfer</span>
                </a>
              <?php endif; ?>
              <a href="<?= BASE_URL ?>/payslip-print.php?id=<?= $selectedPayslip['id'] ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill font-weight-bold">
                <i class="fa-solid fa-print me-1"></i> Cetak<span class="d-none d-sm-inline"> Slip Gaji</span>
              </a>
              <a href="<?= BASE_URL ?>/payslips.php" class="btn btn-sm btn-light rounded-circle p-0 d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;"><i class="fa-solid fa-xmark"></i></a>
            </div>
          </div>
          <div class="card-body p-4">
            
            <div class="text-center border-bottom pb-4 mb-4">
              <h4 class="font-weight-bold text-primary mb-1"><?= e($company['company_name']) ?></h4>
              <h5 class="text-dark font-weight-bold mb-0">SLIP GAJI KARYAWAN</h5>
              <p class="text-muted small mb-0">
                <?= e($selectedPayslip['period_name']) ?>
                <?php if ($selectedPayslip['period_status'] === 'review'): ?>
                  <span class="badge bg-warning text-dark ms-1">Real-time Monitoring</span>
                <?php endif; ?>
              </p>
            </div>

            <div class="row mb-4">
              <div class="col-md-6 mb-3 mb-md-0">
                <div class="d-flex flex-column gap-2">
                  <div class="d-flex justify-content-between border-bottom pb-1">
                    <span class="text-muted small">Nomor Slip</span>
                    <span class="font-weight-bold text-primary text-end small"><?= e($selectedPayslip['payslip_number']) ?></span>
                  </div>
                  <div class="d-flex justify-content-between border-bottom pb-1">
                    <span class="text-muted small">Nama Karyawan</span>
                    <span class="font-weight-bold text-end small"><?= e($selectedPayslip['employee_name_snapshot']) ?></span>
                  </div>
                  <div class="d-flex justify-content-between pb-1">
                    <span class="text-muted small">NIK Internal</span>
                    <span class="font-weight-bold text-end small"><?= e($selectedPayslip['nik_snapshot']) ?></span>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="d-flex flex-column gap-2">
                  <div class="d-flex justify-content-between border-bottom pb-1">
                    <span class="text-muted small">Divisi / Jabatan</span>
                    <span class="font-weight-bold text-end small text-wrap" style="max-width: 60%; word-break: break-word;"><?= e($selectedPayslip['division_snapshot'] ?? '-') ?> / <?= e($selectedPayslip['position_snapshot'] ?? '-') ?></span>
                  </div>
                  <div class="d-flex justify-content-between border-bottom pb-1">
                    <span class="text-muted small">Periode Kerja</span>
                    <span class="font-weight-bold text-end small text-wrap" style="max-width: 60%;"><?= formatTanggalIndo($selectedPayslip['start_date']) ?> - <?= formatTanggalIndo($selectedPayslip['end_date']) ?></span>
                  </div>
                  <div class="d-flex justify-content-between pb-1">
                    <span class="text-muted small">Tgl Pembayaran</span>
                    <span class="font-weight-bold text-end small"><?= formatTanggalIndo($selectedPayslip['payment_date']) ?></span>
                  </div>
                </div>
              </div>
            </div>

            <div class="row border-top border-bottom py-3 mb-4">
              <!-- Income Column -->
              <div class="col-md-6 border-0 border-md-end mb-3 mb-md-0">
                <h6 class="font-weight-bold text-success mb-3"><i class="fa-solid fa-circle-plus me-1"></i> PENDAPATAN</h6>
                <table class="table table-sm table-borderless mb-0">
                  <?php foreach ($payrollItems as $item): ?>
                    <?php if (in_array($item['type'], ['basic', 'individual_bonus', 'team_bonus', 'adjustment']) && ($item['type'] === 'basic' || (float)$item['amount'] > 0)): ?>
                      <tr>
                        <td><?= e($item['name']) ?><?= ($item['type'] !== 'basic' && isset($item['qty']) && $item['qty'] > 1) ? ' (x' . $item['qty'] . ')' : '' ?></td>
                        <td class="text-end font-weight-bold"><?= formatRupiah($item['amount']) ?></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </table>
              </div>

              <!-- Deduction Column -->
              <div class="col-md-6">
                <h6 class="font-weight-bold text-danger mb-3"><i class="fa-solid fa-circle-minus me-1"></i> POTONGAN</h6>
                <table class="table table-sm table-borderless mb-0">
                  <?php 
                    $hasDeduction = false;
                    foreach ($payrollItems as $item): 
                  ?>
                    <?php if ($item['type'] === 'deduction' && (float)$item['amount'] > 0): $hasDeduction = true; ?>
                      <tr>
                        <td><?= e($item['name']) ?><?= (isset($item['qty']) && $item['qty'] > 1) ? ' (x' . $item['qty'] . ')' : '' ?></td>
                        <td class="text-end font-weight-bold text-danger">-<?= formatRupiah($item['amount']) ?></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <?php if (!$hasDeduction): ?>
                    <tr><td class="text-muted small">Tidak ada potongan.</td><td class="text-end">Rp0</td></tr>
                  <?php endif; ?>
                </table>
              </div>
            </div>

            <!-- Take Home Pay Box -->
            <div class="p-3 bg-light rounded-3 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-2 border">
              <div>
                <span class="text-muted font-weight-bold d-block" style="font-size: 0.8rem;">TOTAL PENERIMAAN (TAKE HOME PAY)</span>
                <small class="text-muted" style="font-size: 0.75rem;">Sudah termasuk seluruh bonus dan potongan</small>
              </div>
              <h3 class="font-weight-bold text-success mb-0"><?= formatRupiah($selectedPayslip['take_home_pay']) ?></h3>
            </div>

          </div>
        </div>
      <?php endif; ?>

    </div>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
