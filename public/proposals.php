<?php
// public/proposals.php — Bos Approval Interface for External API Payroll Proposals (Lisa / Hermes Agent)

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/Helpers/payroll_service.php';

$user = requireLogin();

if (!hasPermission('manage_proposals') && !hasPermission('process_payroll')) {
    setFlashMessage('danger', 'Anda tidak memiliki hak akses untuk menyetujui pengajuan payroll.');
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
$db = getDbConnection();
$pageTitle = 'Persetujuan Pengajuan Payroll (Lisa PA)';

// Handle POST actions (Approve / Reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    $proposalId = (int)($_POST['proposal_id'] ?? 0);

    if ($proposalId <= 0) {
        setFlashMessage('danger', 'ID pengajuan tidak valid.');
        header('Location: ' . BASE_URL . '/proposals.php');
        exit;
    }

    if ($action === 'approve') {
        try {
            $result = approvePayrollProposal($db, $proposalId, (int)$user['id']);
            setFlashMessage('success', "Berhasil! " . $result['message']);
        } catch (DomainException $e) {
            setFlashMessage('danger', $e->getMessage());
        } catch (InvalidArgumentException $e) {
            setFlashMessage('warning', $e->getMessage());
        } catch (\Throwable $e) {
            setFlashMessage('danger', "Terjadi kesalahan internal: " . $e->getMessage());
        }
        header('Location: ' . BASE_URL . '/proposals.php');
        exit;
    }

    if ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        try {
            $result = rejectPayrollProposal($db, $proposalId, (int)$user['id'], $reason);
            setFlashMessage('info', $result['message']);
        } catch (\Throwable $e) {
            setFlashMessage('danger', "Gagal menolak pengajuan: " . $e->getMessage());
        }
        header('Location: ' . BASE_URL . '/proposals.php');
        exit;
    }
}

// Filters
$statusFilter = trim($_GET['status'] ?? 'pending');
$periodFilter = isset($_GET['period_id']) ? (int)$_GET['period_id'] : 0;

// Fetch periods for filter
$periods = $db->query("SELECT id, name, status FROM payroll_periods ORDER BY start_date DESC")->fetchAll();

// Build Query
$sql = "
    SELECT 
        pcp.*,
        pp.name as period_name,
        pp.status as period_status,
        e.full_name as employee_name,
        e.nik as employee_nik,
        d.name as division_name,
        t.name as team_name,
        COALESCE(rev_emp.full_name, u.username) as reviewer_name
    FROM payroll_change_proposals pcp
    JOIN payroll_periods pp ON pcp.payroll_period_id = pp.id
    JOIN employees e ON pcp.employee_id = e.id
    LEFT JOIN divisions d ON e.division_id = d.id
    LEFT JOIN teams t ON e.team_id = t.id
    LEFT JOIN users u ON pcp.reviewed_by = u.id
    LEFT JOIN employees rev_emp ON rev_emp.user_id = u.id
    WHERE 1=1
";
$params = [];

if ($statusFilter !== 'all' && !empty($statusFilter)) {
    $sql .= " AND pcp.status = ?";
    $params[] = $statusFilter;
}

if ($periodFilter > 0) {
    $sql .= " AND pcp.payroll_period_id = ?";
    $params[] = $periodFilter;
}

$sql .= " ORDER BY CASE WHEN pcp.status = 'pending' THEN 1 ELSE 2 END, pcp.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$proposals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary counts
$countPending = (int)$db->query("SELECT COUNT(*) FROM payroll_change_proposals WHERE status = 'pending'")->fetchColumn();
$countApproved = (int)$db->query("SELECT COUNT(*) FROM payroll_change_proposals WHERE status = 'approved'")->fetchColumn();
$countRejected = (int)$db->query("SELECT COUNT(*) FROM payroll_change_proposals WHERE status = 'rejected'")->fetchColumn();
$countStale = (int)$db->query("SELECT COUNT(*) FROM payroll_change_proposals WHERE status = 'stale'")->fetchColumn();

include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';
?>

<div class="container-fluid page-body-wrapper">
  <?php include ROOT_PATH . '/app/Views/layouts/sidebar.php'; ?>

  <div class="main-panel">
    <div class="content-wrapper">

      <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-4 shadow-sm" role="alert">
          <div class="d-flex align-items-center">
            <?php if ($flash['type'] === 'success'): ?>
              <i class="fa-solid fa-circle-check fs-4 me-3 text-success"></i>
            <?php elseif ($flash['type'] === 'danger'): ?>
              <i class="fa-solid fa-triangle-exclamation fs-4 me-3 text-danger"></i>
            <?php else: ?>
              <i class="fa-solid fa-circle-info fs-4 me-3 text-info"></i>
            <?php endif; ?>
            <div><?= e($flash['text']) ?></div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Header Row -->
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
          <div class="d-flex align-items-center gap-2">
            <h3 class="font-weight-bold mb-0">Persetujuan Payroll Lisa</h3>
            <span class="badge bg-primary text-white rounded-pill px-3 py-1 fs-8">AI Assistant PA</span>
          </div>
          <p class="text-muted mb-0 mt-1">Review &amp; otorisasi perubahan gaji/potongan/bonus yang diajukan oleh Lisa (Hermes Agent) melalui API</p>
        </div>
        <div>
          <a href="<?= BASE_URL ?>/payroll.php" class="btn btn-outline-secondary rounded-pill px-3">
            <i class="fa-solid fa-arrow-left me-1"></i> Buka Payroll
          </a>
        </div>
      </div>

      <!-- Quick KPI Stats Cards -->
      <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
          <div class="card border-0 shadow-sm rounded-4 bg-white" style="border-left: 4px solid #ffc107 !important;">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <small class="text-muted fw-bold text-uppercase">Menunggu</small>
                  <h3 class="fw-bold mb-0 text-warning"><?= $countPending ?></h3>
                </div>
                <div class="bg-warning bg-opacity-10 text-warning rounded-circle p-3">
                  <i class="fa-solid fa-hourglass-half fs-4"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
          <div class="card border-0 shadow-sm rounded-4 bg-white" style="border-left: 4px solid #198754 !important;">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <small class="text-muted fw-bold text-uppercase">Disetujui</small>
                  <h3 class="fw-bold mb-0 text-success"><?= $countApproved ?></h3>
                </div>
                <div class="bg-success bg-opacity-10 text-success rounded-circle p-3">
                  <i class="fa-solid fa-circle-check fs-4"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
          <div class="card border-0 shadow-sm rounded-4 bg-white" style="border-left: 4px solid #dc3545 !important;">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <small class="text-muted fw-bold text-uppercase">Ditolak</small>
                  <h3 class="fw-bold mb-0 text-danger"><?= $countRejected ?></h3>
                </div>
                <div class="bg-danger bg-opacity-10 text-danger rounded-circle p-3">
                  <i class="fa-solid fa-circle-xmark fs-4"></i>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-md-3 col-sm-6 mb-3">
          <div class="card border-0 shadow-sm rounded-4 bg-white" style="border-left: 4px solid #6c757d !important;">
            <div class="card-body py-3">
              <div class="d-flex justify-content-between align-items-center">
                <div>
                  <small class="text-muted fw-bold text-uppercase">Kadaluarsa (Stale)</small>
                  <h3 class="fw-bold mb-0 text-secondary"><?= $countStale ?></h3>
                </div>
                <div class="bg-secondary bg-opacity-10 text-secondary rounded-circle p-3">
                  <i class="fa-solid fa-clock-rotate-left fs-4"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Filter Controls & Tabs -->
      <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <!-- Nav Filter Pills -->
            <ul class="nav nav-pills" id="pills-tab">
              <li class="nav-item">
                <a class="nav-link rounded-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="<?= BASE_URL ?>/proposals.php?status=pending<?= $periodFilter ? '&period_id='.$periodFilter : '' ?>">
                  <i class="fa-solid fa-hourglass-start me-1"></i> Menunggu (<?= $countPending ?>)
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link rounded-pill <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="<?= BASE_URL ?>/proposals.php?status=approved<?= $periodFilter ? '&period_id='.$periodFilter : '' ?>">
                  <i class="fa-solid fa-check me-1"></i> Disetujui (<?= $countApproved ?>)
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link rounded-pill <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="<?= BASE_URL ?>/proposals.php?status=rejected<?= $periodFilter ? '&period_id='.$periodFilter : '' ?>">
                  <i class="fa-solid fa-xmark me-1"></i> Ditolak (<?= $countRejected ?>)
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link rounded-pill <?= $statusFilter === 'stale' ? 'active' : '' ?>" href="<?= BASE_URL ?>/proposals.php?status=stale<?= $periodFilter ? '&period_id='.$periodFilter : '' ?>">
                  <i class="fa-solid fa-triangle-exclamation me-1"></i> Stale (<?= $countStale ?>)
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link rounded-pill <?= $statusFilter === 'all' ? 'active' : '' ?>" href="<?= BASE_URL ?>/proposals.php?status=all<?= $periodFilter ? '&period_id='.$periodFilter : '' ?>">
                  Semua
                </a>
              </li>
            </ul>

            <!-- Period Dropdown Filter -->
            <form method="GET" class="d-flex align-items-center gap-2">
              <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
              <label class="text-muted small fw-bold mb-0 text-nowrap">Periode:</label>
              <select name="period_id" class="form-select form-select-sm rounded-pill" onchange="this.form.submit()" style="min-width: 190px;">
                <option value="0">Semua Periode</option>
                <?php foreach ($periods as $p): ?>
                  <option value="<?= $p['id'] ?>" <?= $periodFilter === (int)$p['id'] ? 'selected' : '' ?>>
                    <?= e($p['name']) ?> (<?= e($p['status']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>
        </div>
      </div>

      <!-- Proposals List -->
      <?php if (empty($proposals)): ?>
        <div class="card border-0 shadow-sm rounded-4 text-center py-5">
          <div class="card-body">
            <div class="text-muted mb-3">
              <i class="fa-solid fa-inbox fa-4x opacity-25"></i>
            </div>
            <h5 class="fw-bold text-dark">Tidak Ada Pengajuan</h5>
            <p class="text-muted mb-0">Belum ada pengajuan perubahan payroll dengan filter ini.</p>
          </div>
        </div>
      <?php else: ?>
        <div class="row">
          <?php foreach ($proposals as $p): 
            $before = json_decode($p['before_value'] ?? '{}', true) ?: [];
            $after = json_decode($p['after_value'] ?? '{}', true) ?: [];
            $isDeduction = $p['change_type'] === 'deduction';
            $diffThp = ($after['take_home_pay'] ?? 0) - ($before['take_home_pay'] ?? 0);
          ?>
            <div class="col-12 mb-4">
              <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <!-- Proposal Card Header -->
                <div class="card-header bg-light py-3 d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom">
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-dark rounded-pill px-3 py-1 font-monospace">#PROP-<?= $p['id'] ?></span>
                    <span class="badge <?= $isDeduction ? 'bg-danger' : 'bg-success' ?> rounded-pill px-3 py-1">
                      <i class="fa-solid <?= $isDeduction ? 'fa-minus-circle' : 'fa-plus-circle' ?> me-1"></i>
                      <?= $isDeduction ? 'Potongan Karyawan' : 'Bonus Individu' ?>
                    </span>
                    <span class="text-muted small">
                      <i class="fa-regular fa-clock me-1"></i> <?= date('d M Y, H:i', strtotime($p['created_at'])) ?>
                    </span>
                  </div>
                  <div>
                    <?php if ($p['status'] === 'pending'): ?>
                      <span class="badge bg-warning text-dark rounded-pill px-3 py-1 fw-bold">
                        <i class="fa-solid fa-hourglass-half me-1"></i> Menunggu Otorisasi Bos
                      </span>
                    <?php elseif ($p['status'] === 'approved'): ?>
                      <span class="badge bg-success rounded-pill px-3 py-1 fw-bold">
                        <i class="fa-solid fa-circle-check me-1"></i> Disetujui
                      </span>
                    <?php elseif ($p['status'] === 'rejected'): ?>
                      <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold">
                        <i class="fa-solid fa-circle-xmark me-1"></i> Ditolak
                      </span>
                    <?php else: ?>
                      <span class="badge bg-secondary rounded-pill px-3 py-1 fw-bold">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= strtoupper($p['status']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    
                    <!-- Col 1: Employee & Target Info -->
                    <div class="col-lg-4 col-md-5 border-end">
                      <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 48px; height: 48px; font-size: 1.2rem;">
                          <?= strtoupper(substr($p['employee_name'], 0, 1)) ?>
                        </div>
                        <div>
                          <h6 class="fw-bold mb-0 text-dark"><?= e($p['employee_name']) ?></h6>
                          <small class="text-muted"><?= e($p['employee_nik']) ?> &bull; <?= e($p['division_name'] ?: 'Umum') ?></small>
                        </div>
                      </div>

                      <div class="bg-light rounded-3 p-3">
                        <div class="d-flex justify-content-between small mb-1">
                          <span class="text-muted">Periode:</span>
                          <span class="fw-bold text-dark"><?= e($p['period_name']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between small mb-1">
                          <span class="text-muted">Status Periode:</span>
                          <span class="badge <?= in_array($p['period_status'], ['draft', 'review']) ? 'bg-info text-dark' : 'bg-secondary' ?> rounded-pill px-2">
                            <?= e($p['period_status']) ?>
                          </span>
                        </div>
                        <div class="d-flex justify-content-between small">
                          <span class="text-muted">Diajukan Oleh:</span>
                          <span class="fw-bold text-primary"><i class="fa-solid fa-robot me-1"></i> <?= e($p['proposed_by']) ?></span>
                        </div>
                      </div>
                    </div>

                    <!-- Col 2: Change Breakdown & Lisa's Reason -->
                    <div class="col-lg-5 col-md-7 border-end">
                      <div class="mb-3">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">Item Perubahan:</small>
                        <div class="d-flex align-items-baseline gap-2">
                          <h5 class="fw-bold mb-0 text-dark"><?= e($p['item_name']) ?></h5>
                          <span class="badge bg-light text-dark border">
                            <?= (int)$p['qty'] ?>x @ <?= formatRupiah((float)$p['rate']) ?>
                          </span>
                        </div>
                        <h4 class="fw-bold <?= $isDeduction ? 'text-danger' : 'text-success' ?> mt-2 mb-0">
                          <?= $isDeduction ? '-' : '+' ?> <?= formatRupiah((float)$p['amount']) ?>
                        </h4>
                      </div>

                      <!-- Lisa's Reason Box -->
                      <div class="p-3 rounded-3" style="background-color: #f8faff; border-left: 4px solid #4b49ac;">
                        <div class="d-flex align-items-center gap-2 mb-1">
                          <i class="fa-solid fa-comment-dots text-primary"></i>
                          <small class="fw-bold text-primary">Alasan dari Lisa (PA Hermes):</small>
                        </div>
                        <p class="mb-0 text-dark small" style="font-style: italic;">
                          "<?= e($p['reason']) ?>"
                        </p>
                      </div>

                      <?php if (!empty($p['rejection_reason'])): ?>
                        <div class="p-2 mt-2 rounded-3 bg-danger bg-opacity-10 text-danger small">
                          <strong>Catatan Penolakan/Stale:</strong> <?= e($p['rejection_reason']) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <!-- Col 3: Take Home Pay Diff & Action Buttons -->
                    <div class="col-lg-3 col-md-12">
                      <div class="text-center p-3 rounded-4 bg-light mb-3">
                        <small class="text-muted fw-bold text-uppercase d-block mb-1">Dampak Take Home Pay</small>
                        
                        <div class="d-flex justify-content-around align-items-center my-2">
                          <div class="text-start">
                            <small class="text-muted d-block">Sebelum</small>
                            <span class="fw-bold text-muted small"><?= formatRupiah($before['take_home_pay'] ?? 0) ?></span>
                          </div>
                          <div>
                            <i class="fa-solid fa-arrow-right text-muted"></i>
                          </div>
                          <div class="text-end">
                            <small class="text-muted d-block">Sesudah</small>
                            <span class="fw-bold text-primary small"><?= formatRupiah($after['take_home_pay'] ?? 0) ?></span>
                          </div>
                        </div>

                        <div class="badge <?= $diffThp < 0 ? 'bg-danger' : 'bg-success' ?> px-3 py-1 rounded-pill mt-1">
                          Selisih: <?= ($diffThp >= 0 ? '+' : '') . formatRupiah($diffThp) ?>
                        </div>
                      </div>

                      <!-- Action Buttons (Only for Pending) -->
                      <?php if ($p['status'] === 'pending'): ?>
                        <div class="d-grid gap-2">
                          <!-- Approve Form -->
                          <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin MENYETUJUI pengajuan ini? Gaji karyawan akan otomatis diperbarui.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="proposal_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-success w-100 rounded-pill font-weight-bold py-2 shadow-sm">
                              <i class="fa-solid fa-check-circle me-1"></i> Setujui Perubahan
                            </button>
                          </form>

                          <!-- Reject Button trigger modal -->
                          <button type="button" class="btn btn-outline-danger w-100 rounded-pill font-weight-bold py-2" onclick="openRejectModal(<?= $p['id'] ?>, '<?= addslashes(e($p['employee_name'])) ?>', '<?= addslashes(e($p['item_name'])) ?>')">
                            <i class="fa-solid fa-xmark me-1"></i> Tolak Pengajuan
                          </button>
                        </div>
                      <?php else: ?>
                        <div class="text-center text-muted small">
                          <?php if ($p['reviewed_name']): ?>
                            <div>Ditinjau oleh: <strong><?= e($p['reviewed_name']) ?></strong></div>
                            <div>Waktu: <?= date('d M Y, H:i', strtotime($p['reviewed_at'])) ?></div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                    </div>

                  </div>
                </div>

              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- Reject Modal -->
    <div class="modal fade" id="rejectProposalModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow rounded-4">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="proposal_id" id="rejectProposalId" value="">

          <div class="modal-header border-bottom">
            <h5 class="modal-title font-weight-bold text-danger">
              <i class="fa-solid fa-triangle-exclamation me-2"></i> Tolak Pengajuan Lisa
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <p class="text-muted mb-3" id="rejectProposalText"></p>
            <div class="form-group mb-0">
              <label class="font-weight-bold mb-2">Alasan Penolakan <span class="text-danger">*</span></label>
              <textarea name="rejection_reason" class="form-control rounded-3" rows="3" required placeholder="Contoh: Karyawan sudah mengajukan izin lembur terpisah / Bukti belum sesuai..."></textarea>
            </div>
          </div>
          <div class="modal-footer border-top bg-light">
            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
            <button type="submit" class="btn btn-danger rounded-pill px-4 font-weight-bold">
              <i class="fa-solid fa-xmark me-1"></i> Konfirmasi Tolak
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      function openRejectModal(id, empName, itemName) {
        document.getElementById('rejectProposalId').value = id;
        document.getElementById('rejectProposalText').innerText = 
          'Anda akan menolak pengajuan item "' + itemName + '" untuk karyawan ' + empName + '. Masukkan alasan agar tercatat di log audit.';
        var modal = new bootstrap.Modal(document.getElementById('rejectProposalModal'));
        modal.show();
      }
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
