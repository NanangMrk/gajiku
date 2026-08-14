<?php
// bonuses.php — Manage division-level bonus rules
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('manage_bonus_types');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Bonus & Prestatif';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_bonus_type') {
        $name = trim($_POST['name'] ?? '');
        $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
        $rate = (float)($_POST['rate'] ?? 0);
        $unitName = trim($_POST['unit_name'] ?? 'Bulan');
        $calcMethod = $_POST['calculation_method'] ?? 'fixed';

        if ($calcMethod === 'team' && empty($divisionId)) {
            setFlashMessage('danger', 'Untuk tipe Bonus Tim, Anda wajib memilih Divisi Target agar nominal dapat dibagi rata secara otomatis.');
            header('Location: ' . BASE_URL . '/bonuses.php');
            exit;
        }

        if (!empty($name)) {
            $stmt = $db->prepare("
                INSERT INTO bonus_types (name, division_id, calculation_method, rate, unit_name, status) 
                VALUES (?, ?, ?, ?, ?, 'active')
            ");
            $stmt->execute([$name, $divisionId, $calcMethod, $rate, $unitName]);
            logAudit($user['id'], 'ADD_BONUS_TYPE', 'Created bonus rule: ' . $name . ' (' . ($divisionId ? 'Divisi ID ' . $divisionId : 'Semua Divisi') . ')');
            setFlashMessage('success', 'Aturan bonus ' . e($name) . ' berhasil ditambahkan.');
        }
        header('Location: ' . BASE_URL . '/bonuses.php');
        exit;
    }

    if ($action === 'edit_bonus_type') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $divisionId = !empty($_POST['division_id']) ? (int)$_POST['division_id'] : null;
        $rate = (float)($_POST['rate'] ?? 0);
        $unitName = trim($_POST['unit_name'] ?? 'Bulan');
        $calcMethod = $_POST['calculation_method'] ?? 'fixed';

        if ($calcMethod === 'team' && empty($divisionId)) {
            setFlashMessage('danger', 'Untuk tipe Bonus Tim, Anda wajib memilih Divisi Target agar nominal dapat dibagi rata secara otomatis.');
            header('Location: ' . BASE_URL . '/bonuses.php');
            exit;
        }

        if ($id > 0 && !empty($name)) {
            $stmt = $db->prepare("
                UPDATE bonus_types 
                SET name = ?, division_id = ?, calculation_method = ?, rate = ?, unit_name = ? 
                WHERE id = ?
            ");
            $stmt->execute([$name, $divisionId, $calcMethod, $rate, $unitName, $id]);
            logAudit($user['id'], 'EDIT_BONUS_TYPE', 'Edited bonus rule ID ' . $id);
            setFlashMessage('success', 'Aturan bonus berhasil diperbarui.');
        }
        header('Location: ' . BASE_URL . '/bonuses.php');
        exit;
    }

    if ($action === 'delete_bonus_type') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmtName = $db->prepare("SELECT name FROM bonus_types WHERE id = ?");
            $stmtName->execute([$id]);
            $name = $stmtName->fetchColumn();

            $db->prepare("DELETE FROM bonus_types WHERE id = ?")->execute([$id]);
            logAudit($user['id'], 'DELETE_BONUS_TYPE', 'Deleted bonus rule: ' . $name);
            setFlashMessage('success', 'Aturan bonus ' . e($name) . ' berhasil dihapus.');
        }
        header('Location: ' . BASE_URL . '/bonuses.php');
        exit;
    }
}

// Fetch Master Bonus Rules
$bonusTypes = $db->query("
    SELECT bt.*, d.name as division_name
    FROM bonus_types bt
    LEFT JOIN divisions d ON bt.division_id = d.id
    ORDER BY bt.id ASC
")->fetchAll();

// Filter bonuses by calculation method type
$individualBonuses = array_filter($bonusTypes, function($bt) {
    return $bt['calculation_method'] !== 'team';
});

$teamBonuses = array_filter($bonusTypes, function($bt) {
    return $bt['calculation_method'] === 'team';
});

// Fetch Divisions
$allDivisions = $db->query("SELECT id, name FROM divisions WHERE status = 'active' ORDER BY name ASC")->fetchAll();

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
          <h3 class="font-weight-bold mb-1">Aturan Bonus Karyawan</h3>
          <p class="text-muted mb-0">Kelola master bonus individu dan tim yang akan dihitung dan dibagi rata saat payroll</p>
        </div>
        <!-- Desktop Add Button -->
        <button class="btn btn-danger rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" onclick="openAddBonusTypeModal()">
          <i class="fa-solid fa-plus me-1"></i> Tambah Aturan Bonus
        </button>
        
        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" onclick="openAddBonusTypeModal()" style="bottom: calc(75px + env(safe-area-inset-bottom, 0px)); right: 18px; width: 50px; height: 50px; z-index: 1035; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;" aria-label="Tambah Aturan Bonus">
          <i class="fa-solid fa-plus fs-4 text-white"></i>
        </button>
      </div>

      <!-- Tab Navigation -->
      <ul class="nav nav-tabs mb-4" id="bonusTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active font-weight-bold text-dark" id="individual-tab" data-bs-toggle="tab" data-bs-target="#individual" type="button" role="tab">
            <i class="fa-solid fa-user me-1 text-primary"></i> Bonus Individu
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link font-weight-bold text-dark" id="team-tab" data-bs-toggle="tab" data-bs-target="#team" type="button" role="tab">
            <i class="fa-solid fa-users me-1 text-success"></i> Bonus Tim / Divisi (Dibagi Rata)
          </button>
        </li>
      </ul>

      <div class="tab-content border-0 p-0" id="bonusTabsContent">
        <!-- Individual Bonus Tab -->
        <div class="tab-pane fade show active" id="individual" role="tabpanel">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h4 class="card-title font-weight-bold mb-3 text-primary"><i class="fa-solid fa-user-tag me-2"></i> Master Bonus Individu</h4>

              <?php if (empty($individualBonuses)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="fa-solid fa-user-minus fs-1 mb-2 d-block text-secondary" style="opacity: 0.3;"></i>
                  Belum ada aturan bonus individu terdaftar.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Nama Bonus</th>
                        <th>Divisi Target</th>
                        <th>Nominal Per Unit</th>
                        <th>Satuan</th>
                        <th>Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($individualBonuses as $bt): ?>
                        <tr>
                          <td class="font-weight-bold text-dark"><?= e($bt['name']) ?></td>
                          <td>
                            <?php if ($bt['division_name']): ?>
                              <span class="badge bg-primary px-3 py-1 font-weight-bold"><?= e($bt['division_name']) ?></span>
                            <?php else: ?>
                              <span class="badge bg-secondary px-3 py-1 font-weight-bold">Semua Divisi</span>
                            <?php endif; ?>
                          </td>
                          <td class="font-weight-bold text-success"><?= formatRupiah($bt['rate']) ?></td>
                          <td>
                            <span class="badge bg-light text-dark border font-weight-bold">Per <?= e($bt['unit_name'] ?: 'Bulan') ?></span>
                          </td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button type="button" class="btn btn-xs btn-outline-primary rounded-pill" onclick="openEditBonusType(<?= $bt['id'] ?>, '<?= e(addslashes($bt['name'])) ?>', '<?= (int)$bt['division_id'] ?>', <?= (float)$bt['rate'] ?>, '<?= e(addslashes($bt['unit_name'])) ?>', '<?= e($bt['calculation_method']) ?>')">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                              </button>
                              <form action="<?= BASE_URL ?>/bonuses.php" method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_bonus_type">
                                <input type="hidden" name="id" value="<?= $bt['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="return confirm('Yakin ingin menghapus aturan bonus <?= e(addslashes($bt['name'])) ?>?')">
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

        <!-- Team Bonus Tab -->
        <div class="tab-pane fade" id="team" role="tabpanel">
          <div class="card shadow-sm border-0 rounded-4">
            <div class="card-body">
              <h4 class="card-title font-weight-bold mb-3 text-success"><i class="fa-solid fa-users me-2"></i> Master Bonus Tim / Divisi (Dibagi Rata)</h4>
              <div class="alert alert-info py-2 small mb-3 border-0" style="background-color: #ebf5ff; color: #1e3a8a;">
                <i class="fa-solid fa-info-circle me-1"></i> Bonus Tim ditargetkan untuk satu divisi penuh. Ketika payroll dihitung, **nominal total** pool akan **dibagi rata secara otomatis** dengan jumlah anggota aktif di divisi tersebut.
              </div>

              <?php if (empty($teamBonuses)): ?>
                <div class="text-center py-5 text-muted">
                  <i class="fa-solid fa-users-slash fs-1 mb-2 d-block text-secondary" style="opacity: 0.3;"></i>
                  Belum ada aturan bonus tim terdaftar.
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover align-middle">
                    <thead>
                      <tr>
                        <th>Nama Bonus</th>
                        <th>Divisi Target</th>
                        <th>Nominal Pool Total</th>
                        <th>Satuan</th>
                        <th>Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($teamBonuses as $bt): ?>
                        <tr>
                          <td class="font-weight-bold text-dark"><?= e($bt['name']) ?></td>
                          <td>
                            <span class="badge bg-success px-3 py-1 font-weight-bold"><?= e($bt['division_name'] ?: 'Belum diatur') ?></span>
                          </td>
                          <td class="font-weight-bold text-success"><?= formatRupiah($bt['rate']) ?></td>
                          <td>
                            <span class="badge bg-light text-dark border font-weight-bold">Per <?= e($bt['unit_name'] ?: 'Project') ?></span>
                          </td>
                          <td>
                            <div class="d-flex gap-1 flex-wrap">
                              <button type="button" class="btn btn-xs btn-outline-primary rounded-pill" onclick="openEditBonusType(<?= $bt['id'] ?>, '<?= e(addslashes($bt['name'])) ?>', '<?= (int)$bt['division_id'] ?>', <?= (float)$bt['rate'] ?>, '<?= e(addslashes($bt['unit_name'])) ?>', '<?= e($bt['calculation_method']) ?>')">
                                <i class="fa-solid fa-pen-to-square me-1"></i> Edit
                              </button>
                              <form action="<?= BASE_URL ?>/bonuses.php" method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_bonus_type">
                                <input type="hidden" name="id" value="<?= $bt['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger rounded-pill" onclick="return confirm('Yakin ingin menghapus aturan bonus <?= e(addslashes($bt['name'])) ?>?')">
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

    <!-- Modal Add Bonus Rule -->
    <div class="modal fade" id="addBonusTypeModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #10B98108, #3B82F608);">
            <h5 class="modal-title font-weight-bold" id="addBonusModalTitle"><i class="fa-solid fa-tags me-2 text-primary"></i> Tambah Aturan Bonus</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/bonuses.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_bonus_type">
            <input type="hidden" name="calculation_method" id="addBonusCalcMethod" value="fixed">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Bonus</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: Bonus Pasang ODP" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nominal Bonus (Rp)</label>
                <input type="number" name="rate" class="form-control" placeholder="100000" required>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Satuan / Frekuensi</label>
                  <input type="text" name="unit_name" class="form-control" placeholder="Contoh: Bulan, Hari, Kali" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold" id="addBonusDivLabel">Divisi Target</label>
                  <select name="division_id" id="addBonusDivSelect" class="form-select form-control">
                    <option value="">-- Semua Divisi --</option>
                    <?php foreach ($allDivisions as $div): ?>
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

    <!-- Modal Edit Bonus Rule -->
    <div class="modal fade" id="editBonusTypeModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #10B98108, #3B82F608);">
            <h5 class="modal-title font-weight-bold" id="editBonusModalTitle"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Aturan Bonus</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/bonuses.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_bonus_type">
            <input type="hidden" name="id" id="editBonusTypeId">
            <input type="hidden" name="calculation_method" id="editBonusTypeCalcMethod">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Bonus</label>
                <input type="text" name="name" id="editBonusTypeName" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nominal Bonus (Rp)</label>
                <input type="number" name="rate" id="editBonusTypeRate" class="form-control" required>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold">Satuan / Frekuensi</label>
                  <input type="text" name="unit_name" id="editBonusTypeUnit" class="form-control" required>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label font-weight-bold" id="editBonusDivLabel">Divisi Target</label>
                  <select name="division_id" id="editBonusTypeDiv" class="form-select form-control">
                    <option value="">-- Semua Divisi --</option>
                    <?php foreach ($allDivisions as $div): ?>
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
      function openAddBonusTypeModal() {
        const isTeamTab = document.getElementById('team-tab').classList.contains('active');
        const calcMethod = isTeamTab ? 'team' : 'fixed';
        
        document.getElementById('addBonusCalcMethod').value = calcMethod;
        
        const titleEl = document.getElementById('addBonusModalTitle');
        const divSelect = document.getElementById('addBonusDivSelect');
        const divLabel = document.getElementById('addBonusDivLabel');
        
        if (isTeamTab) {
            titleEl.innerHTML = '<i class="fa-solid fa-users me-2 text-success"></i> Tambah Aturan Bonus Tim';
            divSelect.setAttribute('required', 'required');
            divLabel.innerHTML = 'Divisi Target <span class="text-danger">*</span>';
            if (divSelect.value === "") {
                divSelect.selectedIndex = 1;
            }
        } else {
            titleEl.innerHTML = '<i class="fa-solid fa-tags me-2 text-primary"></i> Tambah Aturan Bonus Individu';
            divSelect.removeAttribute('required');
            divLabel.innerHTML = 'Divisi Target';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('addBonusTypeModal'));
        modal.show();
      }

      function openEditBonusType(id, name, divisionId, rate, unitName, calcMethod) {
        document.getElementById('editBonusTypeId').value = id;
        document.getElementById('editBonusTypeName').value = name;
        document.getElementById('editBonusTypeRate').value = rate;
        document.getElementById('editBonusTypeUnit').value = unitName;
        document.getElementById('editBonusTypeCalcMethod').value = calcMethod;
        
        const titleEl = document.getElementById('editBonusModalTitle');
        const divSelect = document.getElementById('editBonusTypeDiv');
        const divLabel = document.getElementById('editBonusDivLabel');
        divSelect.value = divisionId ? divisionId : "";

        if (calcMethod === 'team') {
            titleEl.innerHTML = '<i class="fa-solid fa-users me-2 text-success"></i> Edit Aturan Bonus Tim';
            divSelect.setAttribute('required', 'required');
            divLabel.innerHTML = 'Divisi Target <span class="text-danger">*</span>';
        } else {
            titleEl.innerHTML = '<i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Aturan Bonus Individu';
            divSelect.removeAttribute('required');
            divLabel.innerHTML = 'Divisi Target';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('editBonusTypeModal'));
        modal.show();
      }
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>
