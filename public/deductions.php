<?php
// deductions.php — Manage division-level deduction rules
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('manage_deductions');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Potongan Gaji';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_deduction_type') {
        $name = trim($_POST['name'] ?? '');
        $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
        $defaultAmount = (float)($_POST['default_amount'] ?? 0);
        $unitName = trim($_POST['unit_name'] ?? 'Bulan');
        $calcMethod = $_POST['calculation_method'] ?? 'fixed';

        if ($calcMethod === 'team' && empty($divisionId)) {
            setFlashMessage('danger', 'Untuk tipe Potongan Tim, Anda wajib memilih Divisi Target agar nominal dapat dibagi rata secara otomatis.');
            header('Location: ' . BASE_URL . '/deductions.php');
            exit;
        }

        if (!empty($name)) {
            $stmt = $db->prepare("
                INSERT INTO deduction_types (name, division_id, calculation_method, default_amount, unit_name, status) 
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$name, $divisionId, $calcMethod, $defaultAmount, $unitName]);
            logAudit($user['id'], 'ADD_DEDUCTION_TYPE', 'Created deduction rule: ' . $name . ' (' . ($divisionId ? 'Divisi ID ' . $divisionId : 'Semua Divisi') . ')');
            setFlashMessage('success', 'Aturan potongan ' . e($name) . ' berhasil ditambahkan.');
        }
        header('Location: ' . BASE_URL . '/deductions.php');
        exit;
    }

    if ($action === 'edit_deduction_type') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
        $defaultAmount = (float)($_POST['default_amount'] ?? 0);
        $unitName = trim($_POST['unit_name'] ?? 'Bulan');
        $calcMethod = $_POST['calculation_method'] ?? 'fixed';

        if ($calcMethod === 'team' && empty($divisionId)) {
            setFlashMessage('danger', 'Untuk tipe Potongan Tim, Anda wajib memilih Divisi Target agar nominal dapat dibagi rata secara otomatis.');
            header('Location: ' . BASE_URL . '/deductions.php');
            exit;
        }

        if ($id > 0 && !empty($name)) {
            $stmt = $db->prepare("
                UPDATE deduction_types 
                SET name = ?, division_id = ?, calculation_method = ?, default_amount = ?, unit_name = ? 
                WHERE id = ?
            ");
            $stmt->execute([$name, $divisionId, $calcMethod, $defaultAmount, $unitName, $id]);
            logAudit($user['id'], 'EDIT_DEDUCTION_TYPE', 'Edited deduction rule ID ' . $id);
            setFlashMessage('success', 'Aturan potongan berhasil diperbarui.');
        }
        header('Location: ' . BASE_URL . '/deductions.php');
        exit;
    }

    if ($action === 'delete_deduction_type') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmtName = $db->prepare("SELECT name FROM deduction_types WHERE id = ?");
            $stmtName->execute([$id]);
            $name = $stmtName->fetchColumn();

            $db->prepare("DELETE FROM deduction_types WHERE id = ?")->execute([$id]);
            logAudit($user['id'], 'DELETE_DEDUCTION_TYPE', 'Deleted deduction rule: ' . $name);
            setFlashMessage('success', 'Aturan potongan ' . e($name) . ' berhasil dihapus.');
        }
        header('Location: ' . BASE_URL . '/deductions.php');
        exit;
    }
}

// Fetch Master Deduction Rules
$deductionTypes = $db->query("
    SELECT dt.*, d.name as division_name
    FROM deduction_types dt
    LEFT JOIN divisions d ON dt.division_id = d.id
    ORDER BY dt.id ASC
")->fetchAll();

// Filter deductions by type
$individualDeductions = array_filter($deductionTypes, function($dt) {
    return $dt['calculation_method'] !== 'team';
});

$teamDeductions = array_filter($deductionTypes, function($dt) {
    return $dt['calculation_method'] === 'team';
});

// Fetch Divisions
$divisions = $db->query("SELECT id, name FROM divisions WHERE status = 'active' ORDER BY name ASC")->fetchAll();

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

      <!-- Header Row -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-1">Aturan Potongan Gaji</h3>
          <p class="text-muted mb-0">Kelola master potongan individu dan tim yang akan dihitung dan dibagi rata saat payroll</p>
        </div>
        <!-- Desktop Add Button -->
        <button class="btn btn-danger rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" onclick="openAddDeductionModal()">
          <i class="fa-solid fa-plus me-1"></i> Tambah Aturan Potongan
        </button>
        
        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" onclick="openAddDeductionModal()" style="bottom: 80px; right: 20px; width: 52px; height: 52px; z-index: 1040; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;">
          <i class="fa-solid fa-plus fs-3 text-white"></i>
        </button>
      </div>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs mb-4" id="deductionTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active font-weight-bold text-dark" id="individual-tab" data-bs-toggle="tab" data-bs-target="#individual" type="button" role="tab">
            <i class="fa-solid fa-user me-1 text-primary"></i> Potongan Individu
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link font-weight-bold text-dark" id="team-tab" data-bs-toggle="tab" data-bs-target="#team" type="button" role="tab">
            <i class="fa-solid fa-users me-1 text-danger"></i> Potongan Tim / Divisi (Dibagi Rata)
          </button>
        </li>
      </ul>

      <div class="tab-content border-0 p-0" id="deductionTabsContent">
        <!-- Individual Deduction Tab -->
        <div class="tab-pane fade show active" id="individual" role="tabpanel">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h4 class="card-title font-weight-bold mb-3 text-primary"><i class="fa-solid fa-user-tag me-2"></i> Master Potongan Individu</h4>

              <?php if (empty($individualDeductions)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="fa-solid fa-file-circle-minus fs-1 mb-2 d-block text-secondary" style="opacity: 0.3;"></i>
                  Belum ada aturan potongan individu terdaftar.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Nama Potongan</th>
                        <th>Divisi Target</th>
                        <th>Nominal Potongan</th>
                        <th>Satuan</th>
                        <th>Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($individualDeductions as $dt): ?>
                        <tr>
                          <td class="font-weight-bold text-dark"><?= e($dt['name']) ?></td>
                          <td>
                            <?php if ($dt['division_name']): ?>
                              <span class="badge bg-primary px-3 py-1 font-weight-bold"><?= e($dt['division_name']) ?></span>
                            <?php else: ?>
                              <span class="badge bg-secondary px-3 py-1 font-weight-bold">Semua Divisi</span>
                            <?php endif; ?>
                          </td>
                          <td class="font-weight-bold text-danger"><?= formatRupiah($dt['default_amount']) ?></td>
                          <td>
                            <span class="badge bg-light text-dark border font-weight-bold">Per <?= e($dt['unit_name'] ?: 'Bulan') ?></span>
                          </td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button type="button" class="btn btn-xs btn-outline-primary rounded-pill" onclick="openEditDeduction(<?= $dt['id'] ?>, '<?= e(addslashes($dt['name'])) ?>', '<?= (int)$dt['division_id'] ?>', <?= (float)$dt['default_amount'] ?>, '<?= e(addslashes($dt['unit_name'])) ?>', '<?= e($dt['calculation_method']) ?>')">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                              </button>
                              <form action="<?= BASE_URL ?>/deductions.php" method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_deduction_type">
                                <input type="hidden" name="id" value="<?= $dt['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="return confirm('Yakin ingin menghapus aturan potongan <?= e(addslashes($dt['name'])) ?>?')">
                                  <i class="fa-solid fa-trash-can me-1"></i> Hapus
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Team Deduction Tab -->
        <div class="tab-pane fade" id="team" role="tabpanel">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h4 class="card-title font-weight-bold mb-3 text-danger"><i class="fa-solid fa-users me-2"></i> Master Potongan Tim / Divisi (Dibagi Rata)</h4>
              <div class="alert alert-info py-2 small mb-3 border-0" style="background-color: #ebf5ff; color: #1e3a8a;">
                <i class="fa-solid fa-info-circle me-1"></i> Potongan Tim ditargetkan untuk satu divisi penuh. Ketika payroll dihitung, **nominal total** pool potongan akan **dibagi rata secara otomatis** dengan jumlah anggota aktif di divisi tersebut.
              </div>

              <?php if (empty($teamDeductions)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="fa-solid fa-users-slash fs-1 mb-2 d-block text-secondary" style="opacity: 0.3;"></i>
                  Belum ada aturan potongan tim terdaftar.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Nama Potongan</th>
                        <th>Divisi Target</th>
                        <th>Nominal Pool Total</th>
                        <th>Satuan</th>
                        <th>Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($teamDeductions as $dt): ?>
                        <tr>
                          <td class="font-weight-bold text-dark"><?= e($dt['name']) ?></td>
                          <td>
                            <span class="badge bg-danger px-3 py-1 font-weight-bold"><?= e($dt['division_name'] ?: 'Belum diatur') ?></span>
                          </td>
                          <td class="font-weight-bold text-danger"><?= formatRupiah($dt['default_amount']) ?></td>
                          <td>
                            <span class="badge bg-light text-dark border font-weight-bold">Per <?= e($dt['unit_name'] ?: 'Bulan') ?></span>
                          </td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button type="button" class="btn btn-xs btn-outline-primary rounded-pill" onclick="openEditDeduction(<?= $dt['id'] ?>, '<?= e(addslashes($dt['name'])) ?>', '<?= (int)$dt['division_id'] ?>', <?= (float)$dt['default_amount'] ?>, '<?= e(addslashes($dt['unit_name'])) ?>', '<?= e($dt['calculation_method']) ?>')">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                              </button>
                              <form action="<?= BASE_URL ?>/deductions.php" method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_deduction_type">
                                <input type="hidden" name="id" value="<?= $dt['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="return confirm('Yakin ingin menghapus aturan potongan <?= e(addslashes($dt['name'])) ?>?')">
                                  <i class="fa-solid fa-trash-can me-1"></i> Hapus
                                </button>
                              </form>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- Modal Add Deduction Rule -->
    <div class="modal fade" id="addDeductionTypeModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #EF444408, #F59E0B08);">
            <h5 class="modal-title font-weight-bold" id="addDeductionModalTitle"><i class="fa-solid fa-minus-circle me-2 text-danger"></i> Tambah Aturan Potongan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/deductions.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_deduction_type">
            <input type="hidden" name="calculation_method" id="addDeductionCalcMethod" value="fixed">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Potongan</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: Potongan Keterlambatan" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nominal Potongan (Rp)</label>
                <input type="number" name="default_amount" class="form-control" placeholder="50000" required>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Satuan / Frekuensi</label>
                  <input type="text" name="unit_name" class="form-control" placeholder="Contoh: Bulan, Hari, Kali" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold" id="addDeductionDivLabel">Divisi Target</label>
                  <select name="division_id" id="addDeductionDivSelect" class="form-select form-control">
                    <option value="">-- Semua Divisi --</option>
                    <?php foreach ($divisions as $div): ?>
                      <option value="<?= $div['id'] ?>"><?= e($div['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-danger rounded-pill font-weight-bold px-4">Simpan Aturan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Edit Deduction Rule -->
    <div class="modal fade" id="editDeductionModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #EF444408, #F59E0B08);">
            <h5 class="modal-title font-weight-bold" id="editDeductionModalTitle"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Aturan Potongan</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/deductions.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_deduction_type">
            <input type="hidden" name="id" id="editDeductionId">
            <input type="hidden" name="calculation_method" id="editDeductionCalcMethod">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Potongan</label>
                <input type="text" name="name" id="editDeductionName" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nominal Potongan (Rp)</label>
                <input type="number" name="default_amount" id="editDeductionAmount" class="form-control" required>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Satuan / Frekuensi</label>
                  <input type="text" name="unit_name" id="editDeductionUnit" class="form-control" placeholder="Contoh: Bulan, Hari, Kali" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold" id="editDeductionDivLabel">Divisi Target</label>
                  <select name="division_id" id="editDeductionDiv" class="form-select form-control">
                    <option value="">-- Semua Divisi --</option>
                    <?php foreach ($divisions as $div): ?>
                      <option value="<?= $div['id'] ?>"><?= e($div['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary rounded-pill font-weight-bold px-4">Simpan Perubahan</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script>
      function openAddDeductionModal() {
        const isTeamTab = document.getElementById('team-tab').classList.contains('active');
        const calcMethod = isTeamTab ? 'team' : 'fixed';
        
        document.getElementById('addDeductionCalcMethod').value = calcMethod;
        
        const titleEl = document.getElementById('addDeductionModalTitle');
        const divSelect = document.getElementById('addDeductionDivSelect');
        const divLabel = document.getElementById('addDeductionDivLabel');
        
        if (isTeamTab) {
            titleEl.innerHTML = '<i class="fa-solid fa-users me-2 text-danger"></i> Tambah Aturan Potongan Tim';
            divSelect.setAttribute('required', 'required');
            divLabel.innerHTML = 'Divisi Target <span class="text-danger">*</span>';
            // Select first division as placeholder/default if empty
            if (divSelect.value === "") {
                divSelect.selectedIndex = 1;
            }
        } else {
            titleEl.innerHTML = '<i class="fa-solid fa-user-tag me-2 text-primary"></i> Tambah Aturan Potongan Individu';
            divSelect.removeAttribute('required');
            divLabel.innerHTML = 'Divisi Target';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('addDeductionTypeModal'));
        modal.show();
      }

      function openEditDeduction(id, name, divisionId, amount, unitName, calcMethod) {
        document.getElementById('editDeductionId').value = id;
        document.getElementById('editDeductionName').value = name;
        document.getElementById('editDeductionAmount').value = amount;
        document.getElementById('editDeductionUnit').value = unitName;
        document.getElementById('editDeductionCalcMethod').value = calcMethod;
        
        const titleEl = document.getElementById('editDeductionModalTitle');
        const divSelect = document.getElementById('editDeductionDiv');
        const divLabel = document.getElementById('editDeductionDivLabel');
        divSelect.value = divisionId ? divisionId : "";

        if (calcMethod === 'team') {
            titleEl.innerHTML = '<i class="fa-solid fa-users me-2 text-danger"></i> Edit Aturan Potongan Tim';
            divSelect.setAttribute('required', 'required');
            divLabel.innerHTML = 'Divisi Target <span class="text-danger">*</span>';
        } else {
            titleEl.innerHTML = '<i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Aturan Potongan Individu';
            divSelect.removeAttribute('required');
            divLabel.innerHTML = 'Divisi Target';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('editDeductionModal'));
        modal.show();
      }
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
