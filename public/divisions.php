<?php
// divisions.php
require_once __DIR__ . '/../app/bootstrap.php';

requirePermission('manage_divisions');
$user = getAuthUser();
$db = getDbConnection();

$pageTitle = 'Kelola Divisi & Tim';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_division') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (!empty($name)) {
            try {
                $stmt = $db->prepare("INSERT INTO divisions (name, description, status) VALUES (?, ?, 'active')");
                $stmt->execute([$name, $description]);
                logAudit($user['id'], 'ADD_DIVISION', 'Added division: ' . $name);
                setFlashMessage('success', 'Divisi ' . e($name) . ' berhasil ditambahkan.');
            } catch (Exception $e) {
                setFlashMessage('danger', 'Gagal menambah divisi. Nama divisi mungkin sudah ada.');
            }
        }
        header('Location: ' . BASE_URL . '/divisions.php');
        exit;
    }

    if ($action === 'add_team') {
        $divisionId = (int)($_POST['division_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($divisionId > 0 && !empty($name)) {
            $stmt = $db->prepare("INSERT INTO teams (division_id, name, description, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$divisionId, $name, $description]);
            logAudit($user['id'], 'ADD_TEAM', 'Added team: ' . $name);
            setFlashMessage('success', 'Tim ' . e($name) . ' berhasil ditambahkan.');
        }
        header('Location: ' . BASE_URL . '/divisions.php');
        exit;
    }

    if ($action === 'edit_division') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($id > 0 && !empty($name)) {
            try {
                $stmt = $db->prepare("UPDATE divisions SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $description, $id]);
                logAudit($user['id'], 'EDIT_DIVISION', 'Edited division ID ' . $id . ': ' . $name);
                setFlashMessage('success', 'Divisi ' . e($name) . ' berhasil diperbarui.');
            } catch (Exception $e) {
                setFlashMessage('danger', 'Gagal memperbarui divisi. Nama mungkin sudah digunakan.');
            }
        }
        header('Location: ' . BASE_URL . '/divisions.php');
        exit;
    }

    if ($action === 'delete_division') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Check if division has employees
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = ? AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)");
            $stmtCheck->execute([$id]);
            $empCount = (int)$stmtCheck->fetchColumn();

            if ($empCount > 0) {
                setFlashMessage('danger', 'Tidak dapat menghapus divisi karena masih memiliki ' . $empCount . ' karyawan aktif.');
            } else {
                $stmtName = $db->prepare("SELECT name FROM divisions WHERE id = ?");
                $stmtName->execute([$id]);
                $divName = $stmtName->fetchColumn();

                $db->prepare("DELETE FROM teams WHERE division_id = ?")->execute([$id]);
                $db->prepare("DELETE FROM divisions WHERE id = ?")->execute([$id]);
                logAudit($user['id'], 'DELETE_DIVISION', 'Deleted division: ' . $divName);
                setFlashMessage('success', 'Divisi ' . e($divName) . ' berhasil dihapus.');
            }
        }
        header('Location: ' . BASE_URL . '/divisions.php');
        exit;
    }
}

$stmtDivs = $db->query("
    SELECT d.*, 
           (SELECT COUNT(*) FROM employees e LEFT JOIN users u ON e.user_id = u.id WHERE e.division_id = d.id AND e.status = 'active' AND (u.role_id IS NULL OR u.role_id != 1)) as member_count,
           (SELECT COUNT(*) FROM teams WHERE division_id = d.id) as team_count
    FROM divisions d
    ORDER BY d.id ASC
");
$divisions = $stmtDivs->fetchAll();



include ROOT_PATH . '/app/Views/layouts/header.php';
include ROOT_PATH . '/app/Views/layouts/navbar.php';

$totalDivisions = count($divisions);
$totalMembers = array_sum(array_column($divisions, 'member_count'));
$totalTeams = array_sum(array_column($divisions, 'team_count'));
$divColors = ['#4B49AC', '#7978E9', '#059669', '#0EA5E9', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
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

      <!-- Page Header -->
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
          <h3 class="font-weight-bold mb-1">Kelola Divisi</h3>
          <p class="text-muted mb-0">Struktur organisasi dan pengelompokan kerja karyawan</p>
        </div>
        <!-- Desktop Add Button -->
        <button class="btn btn-primary rounded-pill font-weight-bold px-4 shadow-sm d-none d-md-block" data-bs-toggle="modal" data-bs-target="#addDivisionModal">
          <i class="fa-solid fa-plus me-1"></i> Tambah Divisi
        </button>
        
        <!-- Mobile FAB Button -->
        <button type="button" class="btn btn-primary d-md-none position-fixed rounded-circle shadow-lg d-flex align-items-center justify-content-center no-print" data-bs-toggle="modal" data-bs-target="#addDivisionModal" style="bottom: calc(75px + env(safe-area-inset-bottom, 0px)); right: 18px; width: 50px; height: 50px; z-index: 1035; background: linear-gradient(135deg, #4B49AC 0%, #6366f1 100%); border: none; box-shadow: 0 4px 16px rgba(75, 73, 172, 0.45) !important;" aria-label="Tambah Divisi">
          <i class="fa-solid fa-plus fs-4 text-white"></i>
        </button>
      </div>

      <!-- Summary Stats -->
      <div class="row g-2 g-md-3 mb-4">
        <div class="col-12 col-md-4">
          <div class="card border-0 rounded-4 shadow-sm h-100">
            <div class="card-body d-flex align-items-center py-3">
              <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #4B49AC, #7978E9);">
                <i class="fa-solid fa-sitemap text-white"></i>
              </div>
              <div>
                <p class="text-muted mb-0" style="font-size: 0.78rem;">Total Divisi</p>
                <h4 class="font-weight-bold mb-0"><?= $totalDivisions ?></h4>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="card border-0 rounded-4 shadow-sm h-100">
            <div class="card-body d-flex align-items-center py-3">
              <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #059669, #10B981);">
                <i class="fa-solid fa-users text-white"></i>
              </div>
              <div>
                <p class="text-muted mb-0" style="font-size: 0.78rem;">Total Anggota</p>
                <h4 class="font-weight-bold mb-0"><?= $totalMembers ?></h4>
              </div>
            </div>
          </div>
        </div>
        <div class="col-6 col-md-4">
          <div class="card border-0 rounded-4 shadow-sm h-100">
            <div class="card-body d-flex align-items-center py-3">
              <div class="rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 48px; height: 48px; background: linear-gradient(135deg, #0EA5E9, #38BDF8);">
                <i class="fa-solid fa-people-group text-white"></i>
              </div>
              <div>
                <p class="text-muted mb-0" style="font-size: 0.78rem;">Total Tim</p>
                <h4 class="font-weight-bold mb-0"><?= $totalTeams ?></h4>
              </div>
            </div>
          </div>
      </div>

      <!-- Division Cards Grid -->
      <?php if (empty($divisions)): ?>
        <div class="card border-0 rounded-4 shadow-sm">
          <div class="card-body text-center py-5">
            <i class="fa-solid fa-folder-open text-muted mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
            <h5 class="text-muted">Belum ada divisi terdaftar</h5>
            <p class="text-muted mb-3">Klik tombol "Tambah Divisi" untuk membuat divisi pertama.</p>
            <button class="btn btn-primary rounded-pill font-weight-bold" data-bs-toggle="modal" data-bs-target="#addDivisionModal">
              <i class="fa-solid fa-plus me-1"></i> Tambah Divisi
            </button>
          </div>
        </div>
      <?php else: ?>
        <div class="row">
          <?php foreach ($divisions as $idx => $div): ?>
            <?php $accentColor = $divColors[$idx % count($divColors)]; ?>
            <div class="col-lg-4 col-md-6 mb-4">
              <div class="card border-0 rounded-4 shadow-sm h-100 position-relative overflow-hidden" style="transition: transform 0.2s ease, box-shadow 0.2s ease;" onmouseenter="this.style.transform='translateY(-4px)';this.style.boxShadow='0 8px 30px rgba(0,0,0,0.1)'" onmouseleave="this.style.transform='none';this.style.boxShadow='0 4px 20px rgba(0,0,0,0.03)'">
                <!-- Accent Bar -->
                <div style="height: 4px; background: linear-gradient(90deg, <?= $accentColor ?>, <?= $accentColor ?>88);"></div>

                <div class="card-body p-4">
                  <!-- Header Row -->
                  <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="d-flex align-items-center">
                      <div class="rounded-3 d-flex align-items-center justify-content-center me-3" style="width: 44px; height: 44px; background: <?= $accentColor ?>15;">
                        <i class="fa-solid fa-building" style="color: <?= $accentColor ?>; font-size: 1.1rem;"></i>
                      </div>
                      <div>
                        <h5 class="font-weight-bold mb-0" style="font-size: 1rem;"><?= e($div['name']) ?></h5>
                        <?php if (!empty($div['description'])): ?>
                          <p class="text-muted mb-0" style="font-size: 0.78rem; line-height: 1.4; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= e($div['description']) ?></p>
                        <?php endif; ?>
                      </div>
                    </div>
                    <!-- Action Dropdown -->
                    <div class="dropdown">
                      <button class="btn btn-sm btn-light rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; border: 1px solid #e2e8f0;" data-bs-toggle="dropdown">
                        <i class="fa-solid fa-ellipsis-vertical text-muted" style="font-size: 0.8rem;"></i>
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 rounded-3">
                        <li>
                          <button type="button" class="dropdown-item py-2" onclick="openEditDivision(<?= $div['id'] ?>, '<?= e(addslashes($div['name'])) ?>', '<?= e(addslashes($div['description'] ?? '')) ?>')">
                            <i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Divisi
                          </button>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                          <form action="<?= BASE_URL ?>/divisions.php" method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_division">
                            <input type="hidden" name="id" value="<?= $div['id'] ?>">
                            <button type="submit" class="dropdown-item py-2 text-danger" onclick="return confirm('Yakin ingin menghapus divisi <?= e(addslashes($div['name'])) ?>? Semua tim di bawahnya juga akan dihapus.')">
                              <i class="fa-solid fa-trash-can me-2"></i> Hapus Divisi
                            </button>
                          </form>
                        </li>
                      </ul>
                    </div>
                  </div>

                  <!-- Stat Badges -->
                  <div class="d-flex gap-3 mt-3 pt-3" style="border-top: 1px solid #f1f5f9;">
                    <div class="d-flex align-items-center">
                      <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px; background: #EFF6FF;">
                        <i class="fa-solid fa-users" style="font-size: 0.7rem; color: #3B82F6;"></i>
                      </div>
                      <div>
                        <span class="font-weight-bold" style="font-size: 0.95rem;"><?= $div['member_count'] ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;"> Karyawan</span>
                      </div>
                    </div>
                    <div class="d-flex align-items-center">
                      <div class="rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px; background: #F0FDF4;">
                        <i class="fa-solid fa-people-group" style="font-size: 0.7rem; color: #22C55E;"></i>
                      </div>
                      <div>
                        <span class="font-weight-bold" style="font-size: 0.95rem;"><?= $div['team_count'] ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;"> Tim</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>

    <!-- Modal Add Division -->
    <div class="modal fade" id="addDivisionModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #4B49AC08, #7978E908);">
            <h5 class="modal-title font-weight-bold"><i class="fa-solid fa-sitemap me-2 text-primary"></i> Tambah Divisi Baru</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/divisions.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_division">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Divisi</label>
                <input type="text" name="name" class="form-control" placeholder="Contoh: Divisi Teknisi" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Deskripsi</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Deskripsi tugas divisi"></textarea>
              </div>
            </div>
            <div class="modal-footer border-top">
              <button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Batal</button>
              <button type="submit" class="btn btn-primary rounded-pill font-weight-bold px-4">Simpan Divisi</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Edit Division -->
    <div class="modal fade" id="editDivisionModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
          <div class="modal-header border-bottom" style="background: linear-gradient(135deg, #4B49AC08, #7978E908);">
            <h5 class="modal-title font-weight-bold"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i> Edit Divisi</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <form action="<?= BASE_URL ?>/divisions.php" method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_division">
            <input type="hidden" name="id" id="editDivisionId">
            <div class="modal-body p-4">
              <div class="mb-3">
                <label class="form-label font-weight-bold">Nama Divisi</label>
                <input type="text" name="name" id="editDivisionName" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label font-weight-bold">Deskripsi</label>
                <textarea name="description" id="editDivisionDesc" class="form-control" rows="3"></textarea>
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
      function openEditDivision(id, name, description) {
        document.getElementById('editDivisionId').value = id;
        document.getElementById('editDivisionName').value = name;
        document.getElementById('editDivisionDesc').value = description;
        var modal = new bootstrap.Modal(document.getElementById('editDivisionModal'));
        modal.show();
      }
    </script>

<?php include ROOT_PATH . '/app/Views/layouts/footer.php'; ?>

