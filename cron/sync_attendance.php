<?php
/**
 * Attendance sync: old attendance app DB  ->  ticket DB.
 *
 * Copies every punch in / punch out made in the old attendance app
 * (sanmatob_attendence.attendance) into this app's attendance table, so the
 * ticket system always shows the same timings.
 *
 * Run it from cPanel cron every minute:
 *     * * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/cron/sync_attendance.php >/dev/null 2>&1
 * or ping it over HTTP (e.g. right after a punch in the old app):
 *     https://helpdesk.sanmarg.in/cron/sync_attendance.php?key=<ATT_SYNC_KEY>
 *
 * How rows are matched: each copied row carries the source id in
 * attendance.src_att_id. The old app allows several punches a day, so
 * (user_id, date) is not unique; and this app makes its own punches too, so
 * the plain id would collide. src_att_id avoids both problems.
 *
 * Only rows changed since the last run are read (by source updated_at), with
 * a two-minute overlap so nothing written mid-run is missed. Re-copying a row
 * is harmless: it is an upsert.
 */
require_once __DIR__ . '/../includes/db.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (ATT_SYNC_KEY === '' || !hash_equals(ATT_SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

function out(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }

$dst = db();

// One run at a time: cron fires every minute and an HTTP ping can overlap it.
if (!(int)$dst->query("SELECT GET_LOCK('att_sync', 0)")->fetchColumn()) {
    out('Another sync is running; skipped.');
    exit(0);
}

try {
    $src = new PDO('mysql:host=' . ATT_SYNC_DB_HOST . ';dbname=' . ATT_SYNC_DB_NAME . ';charset=utf8mb4',
        ATT_SYNC_DB_USER, ATT_SYNC_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    // Both sides are stamped in IST; compare updated_at on the same clock.
    $src->exec("SET time_zone = '+05:30'");
    $dst->exec("SET time_zone = '+05:30'");

    /* ---- one-time setup ---- */
    $hasCol = $dst->query("SHOW COLUMNS FROM attendance LIKE 'src_att_id'")->fetch();
    if (!$hasCol) {
        $dst->exec('ALTER TABLE attendance ADD COLUMN src_att_id INT NULL DEFAULT NULL,
                    ADD UNIQUE KEY uq_src_att_id (src_att_id)');
        out('attendance.src_att_id added.');
    }
    $dst->exec('CREATE TABLE IF NOT EXISTS att_sync_state (
                    id TINYINT PRIMARY KEY, last_src_updated DATETIME NOT NULL, last_run DATETIME NOT NULL
                ) ENGINE=InnoDB');

    $state = $dst->query('SELECT last_src_updated FROM att_sync_state WHERE id = 1')->fetchColumn();

    if ($state === false) {
        // First run: rows already brought over by the HRMS merge kept their
        // source id. Link them (same id, user and date) so they are updated,
        // not duplicated.
        $link = $dst->prepare('UPDATE attendance SET src_att_id = ?
                               WHERE id = ? AND user_id = ? AND date = ? AND src_att_id IS NULL');
        $linked = 0;
        foreach ($src->query('SELECT id, user_id, date FROM attendance', PDO::FETCH_NUM) as [$id, $u, $d]) {
            $link->execute([$id, $id, $u, $d]);
            $linked += $link->rowCount();
        }
        out("First run: $linked existing rows linked to their source rows.");
        $since = '1970-01-01 00:00:00';
    } else {
        $since = date('Y-m-d H:i:s', strtotime($state) - 120);
    }

    /* ---- new employees: copy anyone missing here, under the same id ---- */
    // Mapping as in install/hrms_merge.php. Existing users are never touched,
    // so edits made in the ticket system stay.
    $have = array_flip($dst->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $phones = array_flip(array_filter($dst->query('SELECT phone FROM users')->fetchAll(PDO::FETCH_COLUMN)));
    $emails = array_flip(array_map('strtolower', array_filter(
        $dst->query('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN))));
    $deptId = $dst->prepare('SELECT id FROM departments WHERE name = ?');
    $addDept = $dst->prepare('INSERT INTO departments (name) VALUES (?)');
    $addUser = $dst->prepare('INSERT INTO users
            (id, name, username, email, password, role, phone, department_id, is_active, created_at,
             employee_id, designation, company, location, shift_time, date_of_joining, date_of_exit, resign_date,
             status, sex, week_off, password_set, profile_photo, dashboard_role, face_descriptor, geo_restricted,
             rights, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $added = [];
    foreach ($src->query('SELECT * FROM users ORDER BY id') as $s) {
        if (isset($have[$s['id']])) continue;
        if ($s['phone'] !== null && $s['phone'] !== '' && isset($phones[$s['phone']])) {
            out("Employee #{$s['id']} {$s['name']} not added: phone {$s['phone']} already belongs to another user.");
            continue;
        }
        // Email is optional here but unique: if someone already has it, add
        // the employee without one rather than not at all.
        $email = trim((string)$s['email']) ?: null;
        if ($email !== null && isset($emails[strtolower($email)])) {
            out("Employee #{$s['id']} {$s['name']}: email $email already belongs to another user; added without email.");
            $email = null;
        }
        $dept = null;
        if (trim((string)$s['department']) !== '') {
            $deptId->execute([trim($s['department'])]);
            $dept = $deptId->fetchColumn();
            if ($dept === false) { $addDept->execute([trim($s['department'])]); $dept = $dst->lastInsertId(); }
        }
        try {
            $addUser->execute([
                $s['id'], $s['name'], $s['phone'], $email, $s['password'],
                $s['role'] === 'suparadmin' ? 'superadmin' : $s['role'],
                $s['phone'], $dept, (int)(($s['status'] ?? 'Working') === 'Working'), $s['created_at'],
                trim((string)$s['employee_id']) ?: null, $s['designation'], $s['company'], $s['location'], $s['shift_time'],
                $s['date_of_joining'], $s['date_of_exit'], $s['resign_date'], $s['status'] ?? 'Working', $s['sex'],
                $s['week_off'], (int)($s['password_set'] ?? 0), $s['profile_photo'], $s['dashboard_role'], $s['face_descriptor'],
                (int)($s['geo_restricted'] ?? 0), $s['rights'], $s['updated_at'],
            ]);
        } catch (PDOException $e) {
            // One bad record must not hold up everyone else's punches.
            out("Employee #{$s['id']} {$s['name']} not added: " . $e->getMessage());
            continue;
        }
        $phones[$s['phone']] = true;
        if ($email !== null) $emails[strtolower($email)] = true;
        $added[] = (int)$s['id'];
        out("Employee #{$s['id']} {$s['name']} added.");
    }

    /* ---- copy changed rows (plus all past punches of employees just added) ---- */
    $forNew = $added ? ' OR user_id IN (' . implode(',', $added) . ')' : '';
    $rows = $src->prepare("SELECT id, user_id, date, punch_in, punch_out, status,
                                  punch_in_lat, punch_in_lng, punch_in_accuracy, punch_in_location,
                                  punch_out_lat, punch_out_lng, punch_out_accuracy, punch_out_location,
                                  created_at, updated_at
                           FROM attendance WHERE updated_at >= ?$forNew ORDER BY updated_at, id");
    $rows->execute([$since]);

    $users = array_flip($dst->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));

    $upsert = $dst->prepare('INSERT INTO attendance
            (src_att_id, user_id, date, punch_in, punch_out, status,
             punch_in_lat, punch_in_lng, punch_in_accuracy, punch_in_location,
             punch_out_lat, punch_out_lng, punch_out_accuracy, punch_out_location, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id), date = VALUES(date),
            punch_in = VALUES(punch_in), punch_out = VALUES(punch_out), status = VALUES(status),
            punch_in_lat = VALUES(punch_in_lat), punch_in_lng = VALUES(punch_in_lng),
            punch_in_accuracy = VALUES(punch_in_accuracy), punch_in_location = VALUES(punch_in_location),
            punch_out_lat = VALUES(punch_out_lat), punch_out_lng = VALUES(punch_out_lng),
            punch_out_accuracy = VALUES(punch_out_accuracy), punch_out_location = VALUES(punch_out_location)');

    $done = $skipped = 0;
    $max  = $state ?: $since;
    $missing = [];
    $dst->beginTransaction();
    while ($r = $rows->fetch()) {
        if ($r['updated_at'] > $max) $max = $r['updated_at'];
        if (!isset($users[$r['user_id']])) {   // employee not in the ticket DB yet
            $skipped++;
            $missing[$r['user_id']] = true;
            continue;
        }
        $upsert->execute([
            $r['id'], $r['user_id'], $r['date'], $r['punch_in'], $r['punch_out'], $r['status'],
            $r['punch_in_lat'], $r['punch_in_lng'], $r['punch_in_accuracy'], $r['punch_in_location'],
            $r['punch_out_lat'], $r['punch_out_lng'], $r['punch_out_accuracy'], $r['punch_out_location'],
            $r['created_at'],
        ]);
        $done++;
    }
    $dst->prepare('REPLACE INTO att_sync_state (id, last_src_updated, last_run) VALUES (1, ?, NOW())')
        ->execute([$max]);
    $dst->commit();

    out("Synced $done rows since $since.");
    if ($skipped) {
        out("Skipped $skipped rows: user id(s) " . implode(', ', array_keys($missing))
            . ' not found in the ticket DB users table.');
    }
} catch (Throwable $e) {
    if ($dst->inTransaction()) $dst->rollBack();
    error_log('Attendance sync failed: ' . $e->getMessage());
    out('ERROR: ' . $e->getMessage());
    exit(1);
} finally {
    $dst->query("SELECT RELEASE_LOCK('att_sync')");
}
