<?php
/**
 * One-time merge of the Attendance / HRMS database into ticket_db.
 *
 *  1. Import the attendance dump into its own database on the same server
 *     (default name `hrms_src`, or pass ?src=name). The ticket DB user must be
 *     able to read it.
 *  2. Open this page and press Run. It is safe to run more than once: a step that
 *     is already done is reported and skipped.
 *
 * HRMS user IDs are kept as they are, so every attendance row stays linked to the
 * same person. Only employees with status "Working" can sign in.
 * DELETE THIS FOLDER after merging on a live server.
 */
require_once __DIR__ . '/../includes/db.php';

$src = preg_replace('/[^A-Za-z0-9_]/', '', $_GET['src'] ?? 'hrms_src');
$log = [];
$error = null;

/** The tables that come across unchanged, apart from the user_id type. */
const HRMS_TABLES = [
    'attendance', 'companies', 'comp_off_requests', 'employee_tracking_settings', 'locations',
    'location_tracking', 'od_records', 'office_settings', 'route_summary', 'shifts',
];
/** Tables whose rows belong to a user and disappear with them, as they did in the HRMS. */
const HRMS_CASCADE = ['attendance', 'employee_tracking_settings', 'location_tracking'];

function has_table(PDO $pdo, string $db, string $t): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
    $st->execute([$db, $t]);
    return (bool) $st->fetch();
}

function columns(PDO $pdo, string $t): array
{
    $cols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) $cols[$c['Field']] = $c['Type'];
    return $cols;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = db();
        if (!has_table($pdo, $src, 'users')) {
            throw new RuntimeException("Source database `$src` has no users table. Import the attendance dump into it first.");
        }

        /* ---- 1. users: widen the ticket users table to hold an HRMS employee ---- */
        $cols = columns($pdo, 'users');
        $add = [
            'employee_id'     => 'VARCHAR(50) DEFAULT NULL',
            'designation'     => 'VARCHAR(100) DEFAULT NULL',
            'company'         => 'VARCHAR(100) DEFAULT NULL',
            'location'        => 'VARCHAR(100) DEFAULT NULL',
            'shift_time'      => 'VARCHAR(50) DEFAULT NULL',
            'date_of_joining' => 'DATE DEFAULT NULL',
            'date_of_exit'    => 'DATE DEFAULT NULL',
            'resign_date'     => 'DATE DEFAULT NULL',
            'status'          => "ENUM('Working','Resign') NOT NULL DEFAULT 'Working'",
            'sex'             => "ENUM('Male','Female','Other') DEFAULT NULL",
            'week_off'        => "ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') DEFAULT NULL",
            'password_set'    => 'TINYINT(1) NOT NULL DEFAULT 1',
            'profile_photo'   => 'VARCHAR(255) DEFAULT NULL',
            'dashboard_role'  => 'VARCHAR(50) DEFAULT NULL',
            'face_descriptor' => 'LONGTEXT DEFAULT NULL',
            'geo_restricted'  => 'TINYINT(1) NOT NULL DEFAULT 0',
            'rights'          => 'TEXT DEFAULT NULL',
            'updated_at'      => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ];
        foreach ($add as $name => $def) {
            if (isset($cols[$name])) { $log[] = "users.$name already present."; continue; }
            $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $def");
            $log[] = "users.$name added.";
        }

        // Most employees have no email, and they sign in with their phone.
        if ($pdo->query("SHOW COLUMNS FROM users LIKE 'email'")->fetch()['Null'] === 'NO') {
            $pdo->exec('ALTER TABLE users MODIFY email VARCHAR(160) NULL');
            $log[] = 'users.email is now optional.';
        }
        if (strpos(columns($pdo, 'users')['role'], "'hod'") === false) {
            $pdo->exec("ALTER TABLE users MODIFY role
                        ENUM('superadmin','admin','it','employee','hr','face_operator','hod') NOT NULL DEFAULT 'employee'");
            $log[] = 'users.role: Face Operator and HOD added.';
        }
        $keys = [];
        foreach ($pdo->query('SHOW INDEX FROM users') as $k) $keys[$k['Key_name']] = true;
        if (!isset($keys['uq_phone'])) {
            $pdo->exec('ALTER TABLE users ADD UNIQUE KEY uq_phone (phone)');
            $log[] = 'users.phone is now unique (it is a login).';
        }
        if (!isset($keys['idx_employee_id'])) {
            $pdo->exec('ALTER TABLE users ADD KEY idx_employee_id (employee_id)');
        }

        /* ---- 2. departments: one list, the ticket system's ---- */
        $before = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
        $pdo->exec("INSERT IGNORE INTO departments (name)
                    SELECT TRIM(name) FROM `$src`.departments WHERE TRIM(name) <> ''
                    UNION
                    SELECT DISTINCT TRIM(department) FROM `$src`.users WHERE TRIM(COALESCE(department,'')) <> ''");
        $n = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() - $before;
        $log[] = "departments: $n added from the HRMS.";

        /* ---- 3. users: bring every employee across under their HRMS id ---- */
        $pdo->beginTransaction();
        $st = $pdo->exec("INSERT IGNORE INTO users
            (id, name, username, email, password, role, phone, department_id, is_active, created_at,
             employee_id, designation, company, location, shift_time, date_of_joining, date_of_exit, resign_date,
             status, sex, week_off, password_set, profile_photo, dashboard_role, face_descriptor, geo_restricted,
             rights, updated_at)
            SELECT s.id, s.name, s.phone, NULLIF(TRIM(s.email), ''), s.password,
                   CASE s.role WHEN 'suparadmin' THEN 'superadmin' ELSE s.role END,
                   s.phone, d.id, s.status = 'Working', s.created_at,
                   NULLIF(TRIM(s.employee_id), ''), s.designation, s.company, s.location, s.shift_time,
                   s.date_of_joining, s.date_of_exit, s.resign_date, COALESCE(s.status, 'Working'), s.sex,
                   s.week_off, COALESCE(s.password_set, 0), s.profile_photo, s.dashboard_role, s.face_descriptor,
                   COALESCE(s.geo_restricted, 0), s.rights, s.updated_at
            FROM `$src`.users s
            LEFT JOIN departments d ON d.name = TRIM(s.department) COLLATE utf8mb4_general_ci");
        $pdo->commit();
        $log[] = "users: $st employees copied (existing IDs were skipped).";

        /* ---- 4. the attendance tables ---- */
        foreach (HRMS_TABLES as $t) {
            if (!has_table($pdo, $src, $t)) { $log[] = "$t: not in the source, skipped."; continue; }
            if (has_table($pdo, DB_NAME, $t)) { $log[] = "$t already present."; continue; }

            $pdo->exec("CREATE TABLE `$t` LIKE `$src`.`$t`");
            $rows = $pdo->exec("INSERT INTO `$t` SELECT * FROM `$src`.`$t`");
            $log[] = "$t: created, $rows rows copied.";

            // The HRMS used utf8mb4_unicode_ci; matching the ticket tables lets them be joined on names.
            $coll = $pdo->query("SELECT table_collation FROM information_schema.tables
                                 WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
            $pdo->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE $coll");

            // ticket users.id is unsigned; a foreign key needs the same type on both ends.
            $tcols = columns($pdo, $t);
            if (isset($tcols['user_id'])) {
                $null = $pdo->query("SELECT IS_NULLABLE FROM information_schema.columns
                                     WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = 'user_id'")
                            ->fetchColumn() === 'YES' ? 'NULL' : 'NOT NULL';
                $pdo->exec("ALTER TABLE `$t` MODIFY user_id INT UNSIGNED $null");

                if (in_array($t, HRMS_CASCADE, true)) {
                    $orphans = $pdo->exec("DELETE x FROM `$t` x LEFT JOIN users u ON u.id = x.user_id WHERE u.id IS NULL");
                    if ($orphans) $log[] = "$t: $orphans rows removed that belonged to no user.";
                    $pdo->exec("ALTER TABLE `$t` ADD CONSTRAINT fk_{$t}_user
                                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                }
            }
        }

        /* ---- 5. what the mobile API (api/v1) needs beyond the old HRMS ---- */
        $ucols = columns($pdo, 'users');
        foreach ([
            'address' => 'VARCHAR(500) DEFAULT NULL', 'alternate_number' => 'VARCHAR(20) DEFAULT NULL',
            'family_member_name' => 'VARCHAR(100) DEFAULT NULL', 'bank_account_number' => 'VARCHAR(50) DEFAULT NULL',
            'bank_ifsc_code' => 'VARCHAR(20) DEFAULT NULL', 'bank_name' => 'VARCHAR(100) DEFAULT NULL',
        ] as $name => $def) {
            if (!isset($ucols[$name])) { $pdo->exec("ALTER TABLE users ADD COLUMN `$name` $def"); $log[] = "users.$name added."; }
        }
        // Each location carries its own punch radius (metres) for GPS-restricted staff.
        if (!isset(columns($pdo, 'locations')['radius_meters'])) {
            $pdo->exec('ALTER TABLE locations ADD COLUMN radius_meters INT UNSIGNED DEFAULT NULL AFTER longitude');
            $log[] = 'locations.radius_meters added.';
        }
        $acols = columns($pdo, 'attendance');
        foreach (['punch_in_by', 'punch_out_by'] as $name) {
            if (!isset($acols[$name])) { $pdo->exec("ALTER TABLE attendance ADD COLUMN `$name` INT UNSIGNED DEFAULT NULL"); $log[] = "attendance.$name added."; }
        }
        $apiTables = [
            'auth_tokens' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL, token CHAR(64) NOT NULL, device_info VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL,
                UNIQUE KEY uq_token (token), KEY idx_user (user_id),
                CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
            'leave_applications' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL, leave_type VARCHAR(50) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
                days_count INT NOT NULL DEFAULT 1, reason TEXT, status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
                admin_notes TEXT, reviewed_by INT UNSIGNED DEFAULT NULL, reviewed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_user (user_id), KEY idx_status (status), KEY idx_dates (start_date, end_date),
                CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
            'leave_policies' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                leave_type VARCHAR(50) NOT NULL, year SMALLINT NOT NULL, days_allowed DECIMAL(5,1) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_type_year (leave_type, year)",
            'employee_leave_balances' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL, leave_type VARCHAR(50) NOT NULL, year SMALLINT NOT NULL,
                days_allowed DECIMAL(5,1) NOT NULL DEFAULT 0, days_used DECIMAL(5,1) NOT NULL DEFAULT 0,
                UNIQUE KEY uq_user_type_year (user_id, leave_type, year),
                CONSTRAINT fk_elb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
            'attendance_policy' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                single_punch_absent TINYINT(1) NOT NULL DEFAULT 0, half_day_min_hours DECIMAL(4,2) NOT NULL DEFAULT 4,
                full_day_basis VARCHAR(20) NOT NULL DEFAULT 'shift', full_day_fixed_hours DECIMAL(4,2) NOT NULL DEFAULT 8,
                sandwich_absent TINYINT(1) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            'project_holidays' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project VARCHAR(100) DEFAULT NULL, holiday_date DATE NOT NULL, title VARCHAR(150) NOT NULL,
                KEY idx_date (holiday_date)",
            'salary_structures' => "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL, template VARCHAR(100) DEFAULT NULL, statutory_component VARCHAR(100) DEFAULT NULL,
                effective_cycle VARCHAR(50) DEFAULT NULL, salary_ctc DECIMAL(12,2) NOT NULL DEFAULT 0,
                basic_monthly DECIMAL(12,2) NOT NULL DEFAULT 0, special_allowance_monthly DECIMAL(12,2) NOT NULL DEFAULT 0,
                pf_monthly DECIMAL(12,2) DEFAULT NULL, esi_monthly DECIMAL(12,2) DEFAULT NULL,
                pf_calc VARCHAR(50) DEFAULT NULL, esi_calc VARCHAR(50) DEFAULT NULL, custom_components TEXT,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_user (user_id),
                CONSTRAINT fk_ss_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE",
        ];
        foreach ($apiTables as $t => $def) {
            if (has_table($pdo, DB_NAME, $t)) { $log[] = "$t already present."; continue; }
            $pdo->exec("CREATE TABLE `$t` ($def) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $log[] = "$t created for the mobile API.";
        }
        if (!(int) $pdo->query('SELECT COUNT(*) FROM attendance_policy')->fetchColumn()) {
            $pdo->exec('INSERT INTO attendance_policy () VALUES ()');
        }

        /* ---- 6. check ---- */
        $c = $pdo->query("SELECT
                (SELECT COUNT(*) FROM users) users,
                (SELECT COUNT(*) FROM users WHERE is_active = 1) active,
                (SELECT COUNT(*) FROM users WHERE phone IS NOT NULL AND department_id IS NULL AND role = 'employee') no_dept,
                (SELECT COUNT(*) FROM attendance) att,
                (SELECT COUNT(*) FROM `$src`.attendance) src_att")->fetch();
        $log[] = "Done. {$c['users']} users ({$c['active']} can sign in), {$c['att']} attendance rows (source had {$c['src_att']}).";
        if ($c['no_dept']) $log[] = "Note: {$c['no_dept']} employees have no department in the HRMS.";
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>HRMS merge</title>
<style>body{font:14px system-ui,sans-serif;max-width:760px;margin:3rem auto;padding:0 1rem;color:#18181b}
li{margin:.2rem 0}.err{background:#fef2f2;border:1px solid #fca5a5;padding:.75rem;border-radius:6px;color:#b91c1c}
button{background:#18181b;color:#fff;border:0;padding:.6rem 1.2rem;border-radius:6px;cursor:pointer}</style></head>
<body>
<h1>Merge HRMS / Attendance into <?= htmlspecialchars(DB_NAME) ?></h1>
<p>Source database: <code><?= htmlspecialchars($src) ?></code>. Back up <code><?= htmlspecialchars(DB_NAME) ?></code> before running.</p>
<?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if ($log): ?><ul><?php foreach ($log as $l): ?><li><?= htmlspecialchars($l) ?></li><?php endforeach; ?></ul><?php endif; ?>
<form method="post"><button>Run merge</button></form>
</body></html>
