<?php
/**
 * Idempotent upgrade for databases created before the assign/acknowledge workflow.
 * Adds the new ticket columns, the per-ticket work log and users.department_id, then reports what it did.
 * Safe to run more than once. DELETE THIS FOLDER after upgrading on a live server.
 */
require_once __DIR__ . '/../includes/db.php';

$log = [];
$error = null;

try {
    $pdo = db();

    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM tickets') as $c) { $cols[$c['Field']] = true; }

    $add = [
        'assigned_at'     => 'DATETIME DEFAULT NULL',
        'completed_at'    => 'DATETIME DEFAULT NULL',
        'acknowledged_at' => 'DATETIME DEFAULT NULL',
        'reopen_count'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'location'        => 'VARCHAR(80) DEFAULT NULL',
    ];
    foreach ($add as $name => $def) {
        if (isset($cols[$name])) { $log[] = "tickets.$name already present."; continue; }
        $pdo->exec("ALTER TABLE tickets ADD COLUMN `$name` $def");
        $log[] = "tickets.$name added.";
    }

    // Staff belong to a department, so daily-task reports can be grouped by it.
    $ucols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) { $ucols[$c['Field']] = true; }
    if (isset($ucols['department_id'])) {
        $log[] = 'users.department_id already present.';
    } else {
        $pdo->exec('ALTER TABLE users ADD COLUMN department_id INT UNSIGNED DEFAULT NULL, ADD KEY idx_u_dept (department_id)');
        $pdo->exec('ALTER TABLE users ADD CONSTRAINT fk_u_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL');
        $log[] = 'users.department_id added.';
    }

    // Task rows gained a Pending status and a free-text category from the quick templates.
    $dtStatus = '';
    foreach ($pdo->query('SHOW COLUMNS FROM daily_tasks') as $c) {
        if ($c['Field'] === 'status') $dtStatus = $c['Type'];
    }
    if (strpos($dtStatus, "'pending'") !== false) {
        $log[] = 'daily_tasks.status already has Pending.';
    } else {
        $pdo->exec("ALTER TABLE daily_tasks MODIFY status
                    ENUM('in_progress','completed','blocked','pending') NOT NULL DEFAULT 'completed'");
        $log[] = 'daily_tasks.status: Pending added.';
    }

    $dcols0 = [];
    foreach ($pdo->query('SHOW COLUMNS FROM daily_tasks') as $c) { $dcols0[$c['Field']] = true; }
    if (isset($dcols0['category'])) {
        $log[] = 'daily_tasks.category already present.';
    } else {
        $pdo->exec('ALTER TABLE daily_tasks ADD COLUMN category VARCHAR(60) DEFAULT NULL AFTER status');
        $log[] = 'daily_tasks.category added.';
    }

    // A closed ticket writes itself into the assignee's task sheet; this links the two.
    $dcols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM daily_tasks') as $c) { $dcols[$c['Field']] = true; }
    if (isset($dcols['ticket_id'])) {
        $log[] = 'daily_tasks.ticket_id already present.';
    } else {
        $pdo->exec('ALTER TABLE daily_tasks ADD COLUMN ticket_id INT UNSIGNED DEFAULT NULL, ADD KEY idx_dt_ticket (ticket_id)');
        $pdo->exec('ALTER TABLE daily_tasks ADD CONSTRAINT fk_dt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL');
        $log[] = 'daily_tasks.ticket_id added.';
    }

    // Backfill sensible values for tickets that already exist.
    $pdo->exec('UPDATE tickets SET assigned_at = created_at WHERE assigned_to IS NOT NULL AND assigned_at IS NULL');
    $pdo->exec('UPDATE tickets SET completed_at = closed_at WHERE status IN ("resolved","closed") AND completed_at IS NULL AND closed_at IS NOT NULL');
    $pdo->exec('UPDATE tickets SET acknowledged_at = closed_at WHERE status = "closed" AND acknowledged_at IS NULL AND closed_at IS NOT NULL');
    $log[] = 'Existing tickets backfilled.';

    // The HR role, and the joiner / leaver records it keeps.
    $roleType = '';
    foreach ($pdo->query('SHOW COLUMNS FROM users') as $c) {
        if ($c['Field'] === 'role') $roleType = $c['Type'];
    }
    if (strpos($roleType, "'hr'") !== false) {
        $log[] = 'users.role already has HR.';
    } else {
        $pdo->exec("ALTER TABLE users MODIFY role
                    ENUM('superadmin','admin','it','employee','hr') NOT NULL DEFAULT 'employee'");
        $log[] = 'users.role: HR added.';
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS onboarding (
      id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_name VARCHAR(120) NOT NULL,
      department_id INT UNSIGNED DEFAULT NULL,
      join_date     DATE NOT NULL,
      email         VARCHAR(160) DEFAULT NULL,
      system_spec   TEXT DEFAULT NULL,
      assets        TEXT DEFAULT NULL,
      status        ENUM("pending","in_progress","completed") NOT NULL DEFAULT "pending",
      created_by    INT UNSIGNED DEFAULT NULL,
      created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_ob_dept (department_id),
      KEY idx_ob_date (join_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $log[] = 'onboarding ready.';

    $pdo->exec('CREATE TABLE IF NOT EXISTS offboarding (
      id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      employee_name  VARCHAR(120) NOT NULL,
      department_id  INT UNSIGNED DEFAULT NULL,
      last_working_day DATE NOT NULL,
      email          VARCHAR(160) DEFAULT NULL,
      assets_returned TEXT DEFAULT NULL,
      exit_notes     TEXT DEFAULT NULL,
      status         ENUM("pending","in_progress","completed") NOT NULL DEFAULT "pending",
      created_by     INT UNSIGNED DEFAULT NULL,
      created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_off_dept (department_id),
      KEY idx_off_date (last_working_day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $log[] = 'offboarding ready.';

    // The Super Admin actions what HR files; these columns record that he did.
    foreach (['onboarding', 'offboarding'] as $tbl) {
        $have = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$tbl`") as $c) { $have[$c['Field']] = true; }
        $need = [
            'admin_done_at' => 'DATETIME DEFAULT NULL',
            'admin_done_by' => 'INT UNSIGNED DEFAULT NULL',
            'admin_note'    => 'VARCHAR(255) DEFAULT NULL',
            'admin_pending_note' => 'VARCHAR(255) DEFAULT NULL',
            'admin_pending_at'   => 'DATETIME DEFAULT NULL',
        ];
        foreach ($need as $col => $def) {
            if (isset($have[$col])) { $log[] = "$tbl.$col already present."; continue; }
            $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def");
            $log[] = "$tbl.$col added.";
        }
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS ticket_work_logs (
      id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      ticket_id   INT UNSIGNED NOT NULL,
      user_id     INT UNSIGNED NOT NULL,
      work_date   DATE NOT NULL,
      summary     VARCHAR(200) NOT NULL,
      details     TEXT DEFAULT NULL,
      hours       DECIMAL(4,2) NOT NULL DEFAULT 0,
      created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_ticket (ticket_id),
      KEY idx_user (user_id),
      CONSTRAINT fk_wl_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
      CONSTRAINT fk_wl_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $log[] = 'ticket_work_logs ready.';
} catch (Throwable $ex) {
    $error = $ex->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upgrade · <?= APP_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 antialiased flex items-center justify-center p-6">
<div class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl backdrop-blur">
  <h1 class="text-2xl font-semibold text-slate-900">Upgrade database</h1>
  <p class="mt-1 text-sm text-slate-500">Adds the assignment / acknowledgement columns, the ticket work log and the user department.</p>
  <?php if ($error): ?>
    <div class="mt-5 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
  <?php else: ?>
    <ul class="mt-5 space-y-1 text-sm text-emerald-700">
      <?php foreach ($log as $l): ?><li>&#10003; <?= htmlspecialchars($l) ?></li><?php endforeach; ?>
    </ul>
    <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
      All done. Now delete the <code>install/</code> folder.
    </div>
  <?php endif; ?>
  <a href="<?= BASE_URL ?>/dashboard.php" class="mt-6 block rounded-xl bg-teal-600 py-3 text-center font-medium text-white hover:bg-teal-700">Go to dashboard &rarr;</a>
</div>
</body>
</html>
