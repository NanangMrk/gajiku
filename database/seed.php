<?php
// database/seed.php
require_once __DIR__ . '/../app/Config/database.php';

echo "Running Database Seeder...\n";

$db = getDbConnection();

// 1. Seed Roles
$roles = [
    ['Super Admin', 'super_admin', 'Akses penuh ke seluruh sistem'],
    ['Admin / HR', 'admin_hr', 'Mengelola karyawan, tugas, bonus, dan payroll'],
    ['Karyawan', 'employee', 'Akses ke tugas mandiri, bonus, dan slip gaji']
];

$stmtRole = $db->prepare("INSERT OR IGNORE INTO roles (id, name, slug, description) VALUES (?, ?, ?, ?)");
foreach ($roles as $idx => $r) {
    $stmtRole->execute([$idx + 1, $r[0], $r[1], $r[2]]);
}

// 2. Seed Permissions
$permissions = [
    ['Manage Users', 'manage_users', 'User'],
    ['Manage Employees', 'manage_employees', 'Employee'],
    ['Manage Divisions', 'manage_divisions', 'Division'],
    ['Manage Teams', 'manage_teams', 'Team'],
    ['Manage Tasks', 'manage_tasks', 'Task'],
    ['Manage Bonus Master', 'manage_bonus_types', 'Bonus'],
    ['Request Bonus', 'request_bonus', 'Bonus'],
    ['Approve Bonus', 'approve_bonus', 'Bonus'],
    ['Manage Deductions', 'manage_deductions', 'Deduction'],
    ['Process Payroll', 'process_payroll', 'Payroll'],
    ['View All Payslips', 'view_all_payslips', 'Payslip'],
    ['View Own Payslip', 'view_own_payslip', 'Payslip'],
    ['Manage Settings', 'manage_settings', 'Setting'],
    ['Backup System', 'backup_system', 'Setting'],
    ['View Audit Log', 'view_audit_log', 'Audit'],
    ['Manage Payroll Proposals', 'manage_proposals', 'Payroll']
];

$stmtPerm = $db->prepare("INSERT OR IGNORE INTO permissions (id, name, slug, category) VALUES (?, ?, ?, ?)");
foreach ($permissions as $idx => $p) {
    $stmtPerm->execute([$idx + 1, $p[0], $p[1], $p[2]]);
}

// 2b. Seed Role Permissions (admin_hr & employee)
$stmtRolePerm = $db->prepare("INSERT OR IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
$permSlugs = array_column($permissions, 1);
$hrPermSlugs = [
    'manage_users', 'manage_employees', 'manage_divisions', 'manage_teams', 
    'manage_tasks', 'manage_bonus_types', 'request_bonus', 'approve_bonus', 
    'manage_deductions', 'process_payroll', 'view_all_payslips', 'view_audit_log',
    'manage_proposals'
];
foreach ($hrPermSlugs as $slug) {
    $permId = array_search($slug, $permSlugs);
    if ($permId !== false) {
        $stmtRolePerm->execute([2, $permId + 1]);
    }
}
// Employee permissions
$empPermSlugs = ['view_own_payslip', 'request_bonus'];
foreach ($empPermSlugs as $slug) {
    $permId = array_search($slug, $permSlugs);
    if ($permId !== false) {
        $stmtRolePerm->execute([3, $permId + 1]);
    }
}

// 3. Seed Users
$superAdminPass = password_hash('admin123', PASSWORD_BCRYPT);
$hrPass = password_hash('hr123', PASSWORD_BCRYPT);
$empPass = password_hash('emp123', PASSWORD_BCRYPT);

$users = [
    [1, 'admin', 'admin@gajiku.com', $superAdminPass, 1, 'active'], // Super Admin
    [2, 'hradmin', 'hr@gajiku.com', $hrPass, 2, 'active'],         // HR Admin
    [3, 'andi', 'andi@gajiku.com', $empPass, 3, 'active'],          // Employee 1
    [4, 'budi', 'budi@gajiku.com', $empPass, 3, 'active']           // Employee 2
];

$stmtUser = $db->prepare("INSERT OR IGNORE INTO users (id, username, email, password_hash, role_id, status) VALUES (?, ?, ?, ?, ?, ?)");
foreach ($users as $u) {
    $stmtUser->execute($u);
}

// 4. Seed Divisions
$divisions = [
    [1, 'Teknisi', 'Divisi teknisi dan perbaikan jaringan'],
    [2, 'Editor', 'Divisi konten dan penyuntingan media'],
    [3, 'Toko', 'Divisi operasional toko dan penjualan'],
    [4, 'Administrasi', 'Divisi keuangan dan administrasi umum']
];
$stmtDiv = $db->prepare("INSERT OR IGNORE INTO divisions (id, name, description, status) VALUES (?, ?, ?, 'active')");
foreach ($divisions as $d) {
    $stmtDiv->execute($d);
}

// 5. Seed Teams
$teams = [
    [1, 1, 'Tim Teknisi Alpha', 'Tim Teknisi area timur'],
    [2, 1, 'Tim Teknisi Beta', 'Tim Teknisi area barat'],
    [3, 2, 'Tim Video Editor', 'Tim penyunting video utama']
];
$stmtTeam = $db->prepare("INSERT OR IGNORE INTO teams (id, division_id, name, description, status) VALUES (?, ?, ?, ?, 'active')");
foreach ($teams as $t) {
    $stmtTeam->execute($t);
}

// 6. Seed Employees
$employees = [
    [2, 2, 'EMP-002', 'Siti Rahma HR', 'hr@gajiku.com', '081234567891', 'Jl. Merdeka No 12', 4, NULL, 'HR Specialist', '2025-02-01', 'active', 'Mandiri', '9876543210', 4500000],
    [3, 3, 'EMP-003', 'Andi Pratama', 'andi@gajiku.com', '081234567892', 'Jl. Mawar No 4', 1, 1, 'Teknisi Lapangan', '2025-03-01', 'active', 'BCA', '1122334455', 3000000],
    [4, 4, 'EMP-004', 'Budi Santoso', 'budi@gajiku.com', '081234567893', 'Jl. Melati No 8', 1, 1, 'Teknisi Lapangan', '2025-03-15', 'active', 'BRI', '5544332211', 3000000]
];

$stmtEmp = $db->prepare("INSERT OR IGNORE INTO employees (id, user_id, nik, full_name, email, phone, address, division_id, team_id, position, join_date, status, bank_name, account_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmtSal = $db->prepare("INSERT OR IGNORE INTO salary_history (id, employee_id, basic_salary, effective_date, notes, created_by) VALUES (?, ?, ?, ?, 'Initial Salary', 1)");

foreach ($employees as $e) {
    $stmtEmp->execute([$e[0], $e[1], $e[2], $e[3], $e[4], $e[5], $e[6], $e[7], $e[8], $e[9], $e[10], $e[11], $e[12], $e[13]]);
    $stmtSal->execute([$e[0], $e[0], $e[14], $e[10]]);
}

// 7. Seed Team Members
$stmtTm = $db->prepare("INSERT OR IGNORE INTO team_members (team_id, employee_id) VALUES (?, ?)");
$stmtTm->execute([1, 3]); // Andi in Tim Alpha
$stmtTm->execute([1, 4]); // Budi in Tim Alpha

// 8. Seed Bonus Types
$bonusTypes = [
    [1, 'Bonus Pasang ODP', 1, 'per_unit', 10000, 'ODP', 'active'],
    [2, 'Bonus Project Instalasi', 1, 'fixed', 250000, 'Project', 'active'],
    [3, 'Bonus Video Editing', 2, 'per_unit', 50000, 'Video', 'active']
];
$stmtBt = $db->prepare("INSERT OR IGNORE INTO bonus_types (id, name, division_id, calculation_method, rate, unit_name, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ($bonusTypes as $bt) {
    $stmtBt->execute($bt);
}

// 9. Seed Deduction Types
$deductionTypes = [
    [1, 'Terlambat', 'fixed', 50000, 'active'],
    [2, 'Kasbon / Pinjaman', 'manual', 0, 'active'],
    [3, 'Tidak Masuk Tanpa Keterangan', 'fixed', 100000, 'active']
];
$stmtDt = $db->prepare("INSERT OR IGNORE INTO deduction_types (id, name, calculation_method, default_amount, status) VALUES (?, ?, ?, ?, ?)");
foreach ($deductionTypes as $dt) {
    $stmtDt->execute($dt);
}

// 10. Seed Company Settings
$stmtCompany = $db->prepare("INSERT OR IGNORE INTO company_settings (id, company_name, logo, address, phone, email, website, person_in_charge) VALUES (1, 'PT Gajiku Nusantara', NULL, 'Jl. Sudirman No. 88, Jakarta Selatan', '021-5556789', 'info@gajiku.com', 'https://gajiku.com', 'Manajer HRD')");
$stmtCompany->execute();

echo "Seeder completed successfully!\n";
