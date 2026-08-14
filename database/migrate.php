<?php
// database/migrate.php
require_once __DIR__ . '/../app/Config/database.php';

echo "Running Database Migration...\n";

$db = getDbConnection();

$queries = [
    // 1. Roles
    "CREATE TABLE IF NOT EXISTS roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // 2. Permissions
    "CREATE TABLE IF NOT EXISTS permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        category TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // 3. Role Permissions
    "CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INTEGER NOT NULL,
        permission_id INTEGER NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    )",

    // 4. Users
    "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role_id INTEGER NOT NULL,
        status TEXT DEFAULT 'active', -- active, inactive
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (role_id) REFERENCES roles(id)
    )",

    // 5. Divisions
    "CREATE TABLE IF NOT EXISTS divisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        status TEXT DEFAULT 'active', -- active, inactive
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // 6. Teams
    "CREATE TABLE IF NOT EXISTS teams (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        division_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        status TEXT DEFAULT 'active', -- active, inactive
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
    )",

    // 7. Employees
    "CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER UNIQUE,
        nik TEXT NOT NULL UNIQUE,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        address TEXT,
        division_id INTEGER,
        team_id INTEGER,
        position TEXT,
        join_date DATE,
        status TEXT DEFAULT 'active', -- active, inactive, resign
        bank_name TEXT,
        account_number TEXT,
        photo TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
    )",

    // 8. Team Members
    "CREATE TABLE IF NOT EXISTS team_members (
        team_id INTEGER NOT NULL,
        employee_id INTEGER NOT NULL,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (team_id, employee_id),
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )",

    // 9. Salary History
    "CREATE TABLE IF NOT EXISTS salary_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        basic_salary REAL NOT NULL,
        effective_date DATE NOT NULL,
        notes TEXT,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    )",

    // 10. Tasks
    "CREATE TABLE IF NOT EXISTS tasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT,
        assign_type TEXT NOT NULL, -- employee, team, division
        division_id INTEGER,
        team_id INTEGER,
        employee_id INTEGER,
        start_date DATE,
        due_date DATE,
        priority TEXT DEFAULT 'normal', -- low, normal, high, urgent
        status TEXT DEFAULT 'pending', -- pending, in_progress, completed, overdue
        bonus_type_id INTEGER, -- optional linked bonus type
        bonus_quantity INTEGER DEFAULT 1,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL,
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 11. Task Assignments
    "CREATE TABLE IF NOT EXISTS task_assignments (
        task_id INTEGER NOT NULL,
        employee_id INTEGER NOT NULL,
        PRIMARY KEY (task_id, employee_id),
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )",

    // 12. Task History
    "CREATE TABLE IF NOT EXISTS task_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        old_status TEXT,
        new_status TEXT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )",

    // 13. Task Attachments
    "CREATE TABLE IF NOT EXISTS task_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id INTEGER NOT NULL,
        file_path TEXT NOT NULL,
        file_name TEXT NOT NULL,
        uploaded_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
        FOREIGN KEY (uploaded_by) REFERENCES users(id)
    )",

    // 14. Bonus Types (Master Bonus)
    "CREATE TABLE IF NOT EXISTS bonus_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        division_id INTEGER,
        calculation_method TEXT NOT NULL, -- fixed, per_unit, percentage, manual
        rate REAL DEFAULT 0,
        unit_name TEXT,
        status TEXT DEFAULT 'active', -- active, inactive
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL
    )",

    // 15. Bonus Transactions (Individual Bonus)
    "CREATE TABLE IF NOT EXISTS bonus_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        bonus_type_id INTEGER,
        task_id INTEGER,
        transaction_date DATE NOT NULL,
        quantity INTEGER DEFAULT 1,
        rate_snapshot REAL NOT NULL,
        total_amount REAL NOT NULL,
        status TEXT DEFAULT 'pending', -- pending, approved, rejected
        notes TEXT,
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (bonus_type_id) REFERENCES bonus_types(id) ON DELETE SET NULL,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 16. Team Bonus Transactions
    "CREATE TABLE IF NOT EXISTS team_bonus_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        total_bonus REAL NOT NULL,
        distribution_method TEXT NOT NULL DEFAULT 'equal', -- equal, per_member, manual
        transaction_date DATE NOT NULL,
        status TEXT DEFAULT 'pending', -- pending, approved, rejected
        approved_by INTEGER,
        approved_at DATETIME,
        rejection_reason TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 17. Team Bonus Members (Snapshot per member)
    "CREATE TABLE IF NOT EXISTS team_bonus_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        team_bonus_id INTEGER NOT NULL,
        employee_id INTEGER NOT NULL,
        amount_snapshot REAL NOT NULL,
        FOREIGN KEY (team_bonus_id) REFERENCES team_bonus_transactions(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )",

    // 18. Deduction Types (Master Potongan)
    "CREATE TABLE IF NOT EXISTS deduction_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        division_id INTEGER,
        calculation_method TEXT DEFAULT 'fixed', -- fixed, per_unit, percentage, manual
        default_amount REAL DEFAULT 0,
        unit_name TEXT, -- Hari, Bulan, Jam, Kali, dll
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL
    )",

    // 19. Deduction Transactions
    "CREATE TABLE IF NOT EXISTS deduction_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        deduction_type_id INTEGER,
        transaction_date DATE NOT NULL,
        amount REAL NOT NULL,
        reason TEXT,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        FOREIGN KEY (deduction_type_id) REFERENCES deduction_types(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 20. Payroll Periods
    "CREATE TABLE IF NOT EXISTS payroll_periods (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL, -- e.g., Payroll Agustus 2026
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        payment_date DATE NOT NULL,
        status TEXT DEFAULT 'draft', -- draft, review, final, paid
        finalized_at DATETIME,
        finalized_by INTEGER,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 21. Payrolls (Final Snapshot per Employee)
    "CREATE TABLE IF NOT EXISTS payrolls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payroll_period_id INTEGER NOT NULL,
        employee_id INTEGER NOT NULL,
        nik_snapshot TEXT NOT NULL,
        employee_name_snapshot TEXT NOT NULL,
        division_snapshot TEXT,
        team_snapshot TEXT,
        position_snapshot TEXT,
        basic_salary REAL NOT NULL,
        total_individual_bonus REAL DEFAULT 0,
        total_team_bonus REAL DEFAULT 0,
        total_adjustments REAL DEFAULT 0,
        total_deductions REAL DEFAULT 0,
        take_home_pay REAL NOT NULL,
        payslip_number TEXT UNIQUE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )",

    // 22. Payroll Items (Detailed breakdowns for payslips)
    "CREATE TABLE IF NOT EXISTS payroll_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payroll_id INTEGER NOT NULL,
        type TEXT NOT NULL, -- basic, individual_bonus, team_bonus, adjustment, deduction
        name TEXT NOT NULL,
        amount REAL NOT NULL,
        notes TEXT,
        FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE
    )",

    // 23. Payslips
    "CREATE TABLE IF NOT EXISTS payslips (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        payroll_id INTEGER NOT NULL UNIQUE,
        employee_id INTEGER NOT NULL,
        payslip_number TEXT NOT NULL UNIQUE,
        generated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payroll_id) REFERENCES payrolls(id) ON DELETE CASCADE,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )",

    // 24. Notifications
    "CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL,
        link TEXT,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",

    // 25. Audit Logs
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        action TEXT NOT NULL,
        details TEXT,
        ip_address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    )",

    // 26. Company Settings
    "CREATE TABLE IF NOT EXISTS company_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_name TEXT NOT NULL DEFAULT 'PT Gajiku Indonesia',
        logo TEXT,
        address TEXT,
        phone TEXT,
        email TEXT,
        website TEXT,
        person_in_charge TEXT,
        payroll_payment_day INTEGER DEFAULT 25,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // 27. Database Backups
    "CREATE TABLE IF NOT EXISTS database_backups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL,
        file_path TEXT NOT NULL,
        file_size INTEGER NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // 28. Remember Tokens (Persistent Mobile & Web Login)
    "CREATE TABLE IF NOT EXISTS remember_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        selector TEXT NOT NULL UNIQUE,
        token_hash TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )"
];

foreach ($queries as $sql) {
    $db->exec($sql);
}

// Performance Indexes
$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_employees_user_id ON employees(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_employees_division_id ON employees(division_id)",
    "CREATE INDEX IF NOT EXISTS idx_employees_team_id ON employees(team_id)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status)",
    "CREATE INDEX IF NOT EXISTS idx_tasks_due_date ON tasks(due_date)",
    "CREATE INDEX IF NOT EXISTS idx_bonus_tx_employee ON bonus_transactions(employee_id)",
    "CREATE INDEX IF NOT EXISTS idx_bonus_tx_status ON bonus_transactions(status)",
    "CREATE INDEX IF NOT EXISTS idx_payrolls_period ON payrolls(payroll_period_id)",
    "CREATE INDEX IF NOT EXISTS idx_payrolls_employee ON payrolls(employee_id)",
    "CREATE INDEX IF NOT EXISTS idx_audit_logs_user ON audit_logs(user_id)",
    "CREATE INDEX IF NOT EXISTS idx_remember_tokens_selector ON remember_tokens(selector)"
];

foreach ($indexes as $idx) {
    $db->exec($idx);
}

echo "Migration executed successfully!\n";
