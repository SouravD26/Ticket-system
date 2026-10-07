<?php
/**
 * Attendance sync, both ways: old attendance app DB (ATT_SYNC_DB_*)  <->  ticket DB.
 * The pull (old app -> here) is att_sync() below; the push (here -> old app:
 * employees, attendance, OD, comp-off) is hrms_exchange(), run in the same pass.
 * Used by cron/sync_attendance.php and the API (admin/sync_run).
 *
 * Copies new employees (same id) and every punch in / punch out. Rows are
 * matched on attendance.src_att_id, the source row id: the old app allows
 * several punches a day, and this app makes its own punches, so neither
 * (user_id, date) nor the plain id would do.
 *
 * Only rows changed since the last run are read (source updated_at, with a
 * two-minute overlap); $full re-reads everything. Re-copying is an upsert.
 * The whole run is one transaction: on failure nothing is kept.
 */
require_once __DIR__ . '/db.php';

/**
 * @param callable|null $log  called with each progress line as it happens
 * @return array ok, busy, full, synced, skipped, missing_user_ids,
 *               employees_added [{id,name}], since, log [lines], error?
 */
function att_sync(bool $full = false, ?callable $log = null): array
{
    $dst = db();
    $res = ['ok' => false, 'busy' => false, 'full' => $full, 'synced' => 0, 'skipped' => 0,
            'missing_user_ids' => [], 'employees_added' => [], 'since' => null, 'log' => []];
    $out = function (string $msg) use (&$res, $log) {
        $res['log'][] = $msg;
        if ($log) $log($msg);
    };

    // One run at a time: cron fires every minute and an API call can overlap it.
    if (!(int)$dst->query("SELECT GET_LOCK('att_sync', 0)")->fetchColumn()) {
        $res['busy'] = true;
        $out('Another sync is running; skipped.');
        return $res;
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
            $out('attendance.src_att_id added.');
        }
        // For the push (hrms_exchange). DDL commits implicitly, so it stays out of the transaction.
        $dst->exec('CREATE TABLE IF NOT EXISTS hrms_sync_marks (
                        name VARCHAR(30) PRIMARY KEY, mark DATETIME NOT NULL) ENGINE=InnoDB');
        $dst->exec('CREATE TABLE IF NOT EXISTS hrms_sync_pairs (
                        tbl VARCHAR(30) NOT NULL, user_id INT NOT NULL, d DATE NOT NULL,
                        PRIMARY KEY (tbl, user_id, d)) ENGINE=InnoDB');
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
            $out("First run: $linked existing rows linked to their source rows.");
            $since = '1970-01-01 00:00:00';
        } elseif ($full) {
            $since = '1970-01-01 00:00:00';   // re-copy everything (safe: it is an upsert)
            $out('Full re-sync requested.');
        } else {
            $since = date('Y-m-d H:i:s', strtotime($state) - 120);
        }

        $dst->beginTransaction();

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
                $out("Employee #{$s['id']} {$s['name']} not added: phone {$s['phone']} already belongs to another user.");
                continue;
            }
            // Email is optional here but unique: if someone already has it, add
            // the employee without one rather than not at all.
            $email = trim((string)$s['email']) ?: null;
            if ($email !== null && isset($emails[strtolower($email)])) {
                $out("Employee #{$s['id']} {$s['name']}: email $email already belongs to another user; added without email.");
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
                $out("Employee #{$s['id']} {$s['name']} not added: " . $e->getMessage());
                continue;
            }
            $phones[$s['phone']] = true;
            if ($email !== null) $emails[strtolower($email)] = true;
            $added[] = (int)$s['id'];
            $res['employees_added'][] = ['id' => (int)$s['id'], 'name' => $s['name']];
            $out("Employee #{$s['id']} {$s['name']} added.");
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

        // Two-way: a row edited here after the source's last change is newer; keep it
        // (the push below sends it to the old app instead).
        $mine = $dst->prepare('SELECT updated_at FROM attendance WHERE src_att_id = ?');

        $done = $skipped = 0;
        $max  = $state ?: $since;
        $missing = [];
        while ($r = $rows->fetch()) {
            if ($r['updated_at'] > $max) $max = $r['updated_at'];
            if (!isset($users[$r['user_id']])) {   // employee not in the ticket DB yet
                $skipped++;
                $missing[$r['user_id']] = true;
                continue;
            }
            $mine->execute([$r['id']]);
            $at = $mine->fetchColumn();
            if ($at !== false && $at > $r['updated_at']) continue;
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

        /* ---- the other way: ticket DB -> old app ---- */
        $src->beginTransaction();
        $res['exchanged'] = hrms_exchange($src, $dst, $out);
        // Old app first: if the ticket commit then failed, the next run would
        // only re-send rows, whereas the reverse could lose them.
        $src->commit();
        $dst->commit();
        // Both apps hand out user ids; keep their counters level so the next new
        // employee on either side does not take an id the other already used.
        // (ALTER commits implicitly, hence after the transactions.)
        $next = 1 + max((int)$src->query('SELECT MAX(id) FROM users')->fetchColumn(),
                        (int)$dst->query('SELECT MAX(id) FROM users')->fetchColumn());
        $src->exec("ALTER TABLE users AUTO_INCREMENT = $next");
        $dst->exec("ALTER TABLE users AUTO_INCREMENT = $next");
        $res = ['ok' => true, 'synced' => $done, 'skipped' => $skipped,
                'missing_user_ids' => array_keys($missing), 'since' => $since] + $res;

        $out("Synced $done rows since $since.");
        if ($skipped) {
            $out("Skipped $skipped rows: user id(s) " . implode(', ', array_keys($missing))
                . ' not found in the ticket DB users table.');
        }
    } catch (Throwable $e) {
        if (isset($src) && $src->inTransaction()) $src->rollBack();
        if ($dst->inTransaction()) $dst->rollBack();
        error_log('Attendance sync failed: ' . $e->getMessage());
        $out('ERROR: ' . $e->getMessage());
        $res['error'] = $e->getMessage();
    } finally {
        $dst->query("SELECT RELEASE_LOCK('att_sync')");
    }
    return $res;
}

/* ======================================================================
 * Ticket DB -> old app, so the old app shows what is done here too.
 * Called inside att_sync() after the pull, with a transaction open on both.
 *
 *   users       new employees here are added there under the same id; edits
 *               go whichever way is newer (updated_at), both ways
 *   attendance  punches made here are added there and linked via src_att_id;
 *               edits go there unless the old app's copy is newer
 *   od_records / comp_off_requests
 *               matched on (user, date); additions and removals go both ways
 *
 * Leave applications have no table in the old app, so they stay here.
 * ==================================================================== */

/** Columns users share under the same name (department and role are mapped apart). */
const HRMS_USER_COLS = ['name', 'email', 'password', 'phone', 'employee_id', 'designation', 'company',
    'location', 'shift_time', 'date_of_joining', 'date_of_exit', 'resign_date', 'status', 'sex', 'week_off',
    'password_set', 'profile_photo', 'dashboard_role', 'face_descriptor', 'geo_restricted', 'rights'];

/** Ticket role as the old app spells it; null where it has no equivalent (keep theirs). */
function hrms_role_out(string $r): ?string
{
    return ['superadmin' => 'suparadmin', 'admin' => 'admin', 'face_operator' => 'face_operator',
            'employee' => 'employee', 'hr' => 'employee', 'it' => 'employee'][$r] ?? null;
}

/** Sync marks (name => DATETIME) kept in the ticket DB; returns the mark, or null if never set. */
function hrms_mark(PDO $dst, string $name, ?string $set = null): ?string
{
    if ($set !== null) {
        $dst->prepare('REPLACE INTO hrms_sync_marks (name, mark) VALUES (?, ?)')->execute([$name, $set]);
        return $set;
    }
    $st = $dst->prepare('SELECT mark FROM hrms_sync_marks WHERE name = ?');
    $st->execute([$name]);
    return $st->fetchColumn() ?: null;
}

/** Same id on both sides is the same person only if phone, employee ID or name agree. */
function hrms_same_person(array $a, array $b): bool
{
    foreach (['phone', 'employee_id', 'name'] as $k) {
        $x = trim((string)($a[$k] ?? ''));
        if ($x !== '' && strcasecmp($x, trim((string)($b[$k] ?? ''))) === 0) return true;
    }
    return false;
}

function hrms_exchange(PDO $src, PDO $dst, callable $out): array
{
    $n = ['users_in' => 0, 'users_out' => 0, 'attendance_out' => 0, 'od' => 0, 'comp_off' => 0];
    $nowDst = $dst->query('SELECT NOW()')->fetchColumn();
    $nowSrc = $src->query('SELECT NOW()')->fetchColumn();
    $ago = fn(?string $m) => $m ? date('Y-m-d H:i:s', strtotime($m) - 120) : null;

    $deptName = $dst->query('SELECT id, name FROM departments')->fetchAll(PDO::FETCH_KEY_PAIR);

    /* ---- users edited in the old app -> here (new ones were added by the pull) ---- */
    $since = $ago(hrms_mark($dst, 'users_in'));   // first run: nothing, the merge already did it
    if ($since !== null) {
        $theirs = $src->prepare('SELECT * FROM users WHERE updated_at >= ?');
        $theirs->execute([$since]);
        $mine = $dst->prepare('SELECT * FROM users WHERE id = ?');
        $set = implode(', ', array_map(fn($c) => "$c = ?", HRMS_USER_COLS));
        // is_active only follows a change of status, so a login switched off here stays off.
        $upd = $dst->prepare("UPDATE users SET is_active = IF(status <> ?, ? = 'Working', is_active),
                                     $set, role = ?, department_id = ? WHERE id = ?");
        $deptId = array_change_key_case(array_flip($deptName));
        $addDept = $dst->prepare('INSERT INTO departments (name) VALUES (?)');
        foreach ($theirs->fetchAll() as $s) {
            $mine->execute([$s['id']]);
            $m = $mine->fetch();
            if (!$m || $m['updated_at'] >= $s['updated_at']) continue;
            if (!hrms_same_person($m, $s)) {
                $out("User #{$s['id']}: '{$s['name']}' in the old app but '{$m['name']}' here; not synced.");
                continue;
            }
            $role = $s['role'] === 'suparadmin' ? 'superadmin' : $s['role'];
            if ($role === 'employee' && in_array($m['role'], ['hr', 'it', 'hod'], true)) $role = $m['role'];
            $dname = trim((string)$s['department']);
            $dept = null;
            if ($dname !== '') {
                $dept = $deptId[strtolower($dname)] ?? null;
                if ($dept === null) {
                    $addDept->execute([$dname]);
                    $dept = $deptId[strtolower($dname)] = (int)$dst->lastInsertId();
                    $deptName[$dept] = $dname;
                }
            }
            $vals = array_map(fn($c) => $c === 'email' ? (trim((string)$s[$c]) ?: null) : $s[$c], HRMS_USER_COLS);
            try {
                $upd->execute(array_merge([$s['status'], $s['status']], $vals, [$role, $dept, $s['id']]));
                $n['users_in'] += $upd->rowCount();
            } catch (PDOException $e) {
                $out("User #{$s['id']} {$s['name']}: not updated here: " . $e->getMessage());
            }
        }
    }
    hrms_mark($dst, 'users_in', $nowSrc);

    /* ---- users here -> old app: new employees, and edits since the last run ---- */
    $since = $ago(hrms_mark($dst, 'users_out')) ?? $nowDst;   // first run: new ones only
    $have = $src->query('SELECT id, name, phone, employee_id, updated_at FROM users')->fetchAll(PDO::FETCH_UNIQUE);
    $mine = $dst->prepare("SELECT * FROM users WHERE updated_at >= ? OR role IN ('employee','hr','it')");
    $mine->execute([$since]);
    $cols = array_merge(HRMS_USER_COLS, ['department', 'role']);
    $ins = $src->prepare('INSERT INTO users (id, created_at, ' . implode(', ', $cols) . ')
                          VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')');
    $upd = $src->prepare('UPDATE users SET ' . implode(', ', array_map(fn($c) => "$c = ?", HRMS_USER_COLS))
                       . ', department = ?, role = COALESCE(?, role) WHERE id = ?');
    foreach ($mine->fetchAll() as $m) {
        $h = $have[$m['id']] ?? null;
        $new = $h === null;
        if ($new && !in_array($m['role'], ['employee', 'hr', 'it'], true)) continue;   // system accounts stay here
        if (!$new && $m['updated_at'] < $since) continue;      // unchanged
        if ((string)$m['phone'] === '') {                      // the old app requires a phone
            if ($new) $out("Employee #{$m['id']} {$m['name']}: no phone, so not added to the old app.");
            continue;
        }
        if (!$new && ($h['updated_at'] > $m['updated_at'] || !hrms_same_person($m, $h))) continue;
        $vals = array_map(fn($c) => $m[$c], HRMS_USER_COLS);
        $vals[] = $deptName[$m['department_id']] ?? null;
        $vals[] = hrms_role_out($m['role']);
        try {
            if ($new) {
                $ins->execute(array_merge([$m['id'], $m['created_at']], $vals));
                $n['users_out']++;
                $out("Employee #{$m['id']} {$m['name']} added to the old app.");
            } else {
                $upd->execute(array_merge($vals, [$m['id']]));
                $n['users_out'] += $upd->rowCount();
            }
        } catch (PDOException $e) {
            $out("Employee #{$m['id']} {$m['name']}: not sent to the old app: " . $e->getMessage());
        }
    }
    hrms_mark($dst, 'users_out', $nowDst);

    /* ---- attendance here -> old app ---- */
    $since = $ago(hrms_mark($dst, 'attendance_out')) ?? $nowDst;   // first run: unlinked rows only
    $theirUsers = array_flip($src->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
    $rows = $dst->prepare('SELECT * FROM attendance WHERE src_att_id IS NULL OR updated_at >= ?');
    $rows->execute([$since]);
    $f = ['user_id', 'date', 'punch_in', 'punch_out', 'status',
          'punch_in_lat', 'punch_in_lng', 'punch_in_accuracy', 'punch_in_location',
          'punch_out_lat', 'punch_out_lng', 'punch_out_accuracy', 'punch_out_location'];
    $theirAt = $src->prepare('SELECT updated_at FROM attendance WHERE id = ?');
    // With the id given, a row the old app has lost is put back rather than missed.
    $put = $src->prepare('INSERT INTO attendance (id, created_at, ' . implode(', ', $f) . ')
                          VALUES (?, ?' . str_repeat(', ?', count($f)) . ')
                          ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $f)));
    $link = $dst->prepare('UPDATE attendance SET src_att_id = ?, updated_at = updated_at WHERE id = ?');
    $unsent = [];
    foreach ($rows->fetchAll() as $r) {
        if (!isset($theirUsers[$r['user_id']])) { $unsent[$r['user_id']] = true; continue; }
        if ($r['src_att_id'] !== null) {
            $theirAt->execute([$r['src_att_id']]);
            $at = $theirAt->fetchColumn();
            if ($at !== false && $at > $r['updated_at']) continue;   // theirs is newer
        }
        $put->execute(array_merge([$r['src_att_id'], $r['created_at']], array_map(fn($c) => $r[$c], $f)));
        if ($r['src_att_id'] === null) $link->execute([$src->lastInsertId(), $r['id']]);
        if ($put->rowCount()) $n['attendance_out']++;   // 0 = already the same there
    }
    if ($unsent) $out('Attendance not sent: user id(s) ' . implode(', ', array_keys($unsent)) . ' not in the old app.');
    hrms_mark($dst, 'attendance_out', $nowDst);

    /* ---- OD and comp-off, both ways ---- */
    $n['od']       = hrms_pairs($src, $dst, 'od_records', 'od_date', ['marked_by', 'marked_at'], $out);
    $n['comp_off'] = hrms_pairs($src, $dst, 'comp_off_requests', 'comp_off_date', ['earned_date', 'marked_by', 'marked_at'], $out);

    $out("Exchanged: {$n['users_in']} user edits in, {$n['users_out']} users out, "
       . "{$n['attendance_out']} attendance rows out, {$n['od']} OD and {$n['comp_off']} comp-off changes.");
    return $n;
}

/**
 * Tables keyed by (user_id, date) with no updated_at: compare the full sets.
 * hrms_sync_pairs remembers which keys both sides had last time, which tells a
 * removal (was on both, now on one) from an addition (was on neither).
 */
function hrms_pairs(PDO $src, PDO $dst, string $table, string $dcol, array $extra, callable $out): int
{
    $cols = "user_id, $dcol, " . implode(', ', $extra);
    $load = function (PDO $db) use ($table, $cols, $dcol) {
        $all = [];
        foreach ($db->query("SELECT $cols FROM $table") as $r) $all[$r['user_id'] . '|' . $r[$dcol]] = $r;
        return $all;
    };
    $here = $load($dst);
    $there = $load($src);
    $seen = [];
    $st = $dst->prepare('SELECT user_id, d FROM hrms_sync_pairs WHERE tbl = ?');
    $st->execute([$table]);
    foreach ($st as $r) $seen[$r['user_id'] . '|' . $r['d']] = true;

    $ph = implode(', ', array_fill(0, count($extra) + 2, '?'));
    $ins = ['dst' => $dst->prepare("INSERT IGNORE INTO $table ($cols) VALUES ($ph)"),
            'src' => $src->prepare("INSERT IGNORE INTO $table ($cols) VALUES ($ph)")];
    $del = ['dst' => $dst->prepare("DELETE FROM $table WHERE user_id = ? AND $dcol = ?"),
            'src' => $src->prepare("DELETE FROM $table WHERE user_id = ? AND $dcol = ?")];
    $addSeen = $dst->prepare('INSERT IGNORE INTO hrms_sync_pairs (tbl, user_id, d) VALUES (?, ?, ?)');
    $dropSeen = $dst->prepare('DELETE FROM hrms_sync_pairs WHERE tbl = ? AND user_id = ? AND d = ?');
    $users = ['dst' => array_flip($dst->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN)),
              'src' => array_flip($src->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN))];

    $fallback = (int)$src->query("SELECT MIN(id) FROM users WHERE role = 'suparadmin'")->fetchColumn();

    $changes = 0;
    foreach (array_keys($here + $there) as $k) {
        [$u, $d] = explode('|', $k);
        if (isset($here[$k], $there[$k])) { $addSeen->execute([$table, $u, $d]); continue; }
        $onlyHere = isset($here[$k]);
        if (isset($seen[$k])) {                    // was on both: removed on the other side
            $del[$onlyHere ? 'dst' : 'src']->execute([$u, $d]);
            $dropSeen->execute([$table, $u, $d]);
        } else {                                   // new on one side: copy it over
            $to = $onlyHere ? 'src' : 'dst';
            if (!isset($users[$to][$u])) continue;
            $row = $onlyHere ? $here[$k] : $there[$k];
            // The old app requires marked_by to be one of its users; system
            // accounts exist only here, so credit its Super Admin instead.
            if (!isset($users[$to][$row['marked_by']])) $row['marked_by'] = $fallback;
            $ins[$to]->execute(array_values($row));
            if (!$ins[$to]->rowCount()) {
                $out("$table user #$u $d: could not be copied " . ($onlyHere ? 'to the old app.' : 'here.'));
                continue;
            }
            $addSeen->execute([$table, $u, $d]);
        }
        $changes++;
    }
    return $changes;
}

/** Last run and how many source rows have changed since, for the status API. */
function att_sync_status(): array
{
    $dst = db();
    $hasTable = $dst->query("SHOW TABLES LIKE 'att_sync_state'")->fetch();
    $state = $hasTable ? $dst->query('SELECT last_src_updated, last_run FROM att_sync_state WHERE id = 1')->fetch() : false;
    $res = [
        'last_run'         => $state['last_run'] ?? null,
        'last_src_updated' => $state['last_src_updated'] ?? null,
        'pending_rows'     => null,
        'source_reachable' => false,
    ];
    try {
        $src = new PDO('mysql:host=' . ATT_SYNC_DB_HOST . ';dbname=' . ATT_SYNC_DB_NAME . ';charset=utf8mb4',
            ATT_SYNC_DB_USER, ATT_SYNC_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $src->exec("SET time_zone = '+05:30'");
        $res['source_reachable'] = true;
        $st = $src->prepare('SELECT COUNT(*) FROM attendance WHERE updated_at > ?');
        $st->execute([$state['last_src_updated'] ?? '1970-01-01 00:00:00']);
        $res['pending_rows'] = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('Attendance sync status: ' . $e->getMessage());
    }
    return $res;
}
