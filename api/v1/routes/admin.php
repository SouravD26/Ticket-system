<?php
/**
 * Admin endpoints - dashboard, org-wide attendance, leave approval,
 * manual attendance and OD / comp-off marking.
 * Every handler is gated by require_role().
 */
declare(strict_types=1);

// shape_leave() is shared with the employee-facing leave endpoints.
require_once __DIR__ . '/leave.php';

function admin_guard(mysqli $conn, bool $write = false): array {
    $user = auth_user($conn);
    if ($write) {
        require_role($user, 'admin', 'superadmin');
    } else {
        require_role($user, 'admin', 'superadmin', 'hr');
    }
    return $user;
}

/** GET admin/dashboard - headline numbers for the current attendance day. */
function admin_dashboard(mysqli $conn): void {
    admin_guard($conn);
    $date = attendance_date();

    $total_employees = (int)fetch_value(
        $conn,
        "SELECT COUNT(*) FROM users u WHERE " . STAFF_SQL . " AND (u.status IS NULL OR u.status <> 'Resign')"
    );

    $present = (int)fetch_value(
        $conn,
        "SELECT COUNT(DISTINCT a.user_id) FROM attendance a
           JOIN users u ON u.id = a.user_id
          WHERE a.date = ? AND a.punch_in IS NOT NULL AND " . STAFF_SQL . "",
        's',
        [$date]
    );

    $still_in = (int)fetch_value(
        $conn,
        "SELECT COUNT(DISTINCT a.user_id) FROM attendance a
           JOIN users u ON u.id = a.user_id
          WHERE a.date = ? AND a.punch_out IS NULL AND " . STAFF_SQL . "",
        's',
        [$date]
    );

    $on_od = (int)fetch_value($conn, "SELECT COUNT(DISTINCT user_id) FROM od_records WHERE od_date = ?", 's', [$date]);

    $on_leave = (int)fetch_value(
        $conn,
        "SELECT COUNT(DISTINCT user_id) FROM leave_applications
          WHERE status = 'Approved' AND ? BETWEEN start_date AND end_date",
        's',
        [$date]
    );

    $pending_leaves = (int)fetch_value($conn, "SELECT COUNT(*) FROM leave_applications WHERE status = 'Pending'");

    ok([
        'date'             => $date,
        'server_time'      => date('Y-m-d H:i:s'),
        'total_employees'  => $total_employees,
        'present'          => $present,
        'currently_in'     => $still_in,
        'on_od'            => $on_od,
        'on_leave'         => $on_leave,
        'absent'           => max(0, $total_employees - $present - $on_od - $on_leave),
        'pending_leaves'   => $pending_leaves,
        'departments'      => fetch_all(
            $conn,
            "SELECT " . DEPT_SQL . " AS department,
                    COUNT(DISTINCT u.id) AS total,
                    COUNT(DISTINCT a.user_id) AS present
               FROM users u
               LEFT JOIN attendance a ON a.user_id = u.id AND a.date = ?
              WHERE " . STAFF_SQL . " AND (u.status IS NULL OR u.status <> 'Resign')
              GROUP BY department
              ORDER BY department",
            's',
            [$date]
        ),
    ]);
}

/** GET admin/attendance - org-wide punch records with filters. */
function admin_attendance(mysqli $conn): void {
    admin_guard($conn);
    [$from, $to] = date_range();
    $p = paging(100);

    $where  = ['a.date BETWEEN ? AND ?'];
    $types  = 'ss';
    $params = [$from, $to];

    $uid = param_int('user_id');
    if ($uid) { $where[] = 'a.user_id = ?'; $types .= 'i'; $params[] = $uid; }

    foreach (['department' => DEPT_SQL, 'company' => 'u.company', 'location' => 'u.location'] as $key => $col) {
        $v = param($key);
        if ($v !== null && $v !== '') { $where[] = "$col = ?"; $types .= 's'; $params[] = $v; }
    }

    $search = param('search') ?? param('q');
    if ($search !== null && $search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(u.name LIKE ? OR u.employee_id LIKE ?)';
        $types  .= 'ss';
        array_push($params, $like, $like);
    }

    if (param_int('incomplete') === 1) $where[] = 'a.punch_out IS NULL';

    $clause = implode(' AND ', $where);
    $total  = (int)fetch_value(
        $conn,
        "SELECT COUNT(*) FROM attendance a JOIN users u ON u.id = a.user_id WHERE $clause",
        $types,
        $params
    );

    $rows = fetch_all(
        $conn,
        "SELECT a.*, u.name AS employee_name, u.employee_id AS employee_code, " . DEPT_SQL . " AS department
           FROM attendance a
           JOIN users u ON u.id = a.user_id
          WHERE $clause
          ORDER BY a.date DESC, a.id DESC
          LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$p['limit'], $p['offset']])
    );

    ok(array_map('shape_attendance', $rows), [
        'range' => ['from' => $from, 'to' => $to],
        'meta'  => meta($p, $total),
    ]);
}

/** GET admin/today - one row per employee with today's status. */
function admin_today(mysqli $conn): void {
    admin_guard($conn);
    $date = attendance_date();

    $rows = fetch_all(
        $conn,
        "SELECT u.id, u.name, u.employee_id, " . DEPT_SQL . " AS department, u.profile_photo,
                MIN(a.punch_in)  AS first_in,
                MAX(a.punch_out) AS last_out,
                COUNT(a.id)      AS punch_count,
                SUM(a.punch_out IS NULL AND a.punch_in IS NOT NULL) AS open_punches
           FROM users u
           LEFT JOIN attendance a ON a.user_id = u.id AND a.date = ?
          WHERE " . STAFF_SQL . " AND (u.status IS NULL OR u.status <> 'Resign')
          GROUP BY u.id, u.name, u.employee_id, u.department_id, u.profile_photo
          ORDER BY u.name",
        's',
        [$date]
    );

    $od = array_column(fetch_all($conn, "SELECT user_id FROM od_records WHERE od_date = ?", 's', [$date]), 'user_id');
    $leave = array_column(fetch_all(
        $conn,
        "SELECT user_id FROM leave_applications WHERE status = 'Approved' AND ? BETWEEN start_date AND end_date",
        's',
        [$date]
    ), 'user_id');

    ok(array_map(static function (array $r) use ($od, $leave): array {
        $id = (int)$r['id'];
        $status = 'Absent';
        if ((int)$r['punch_count'] > 0)          $status = (int)$r['open_punches'] > 0 ? 'In' : 'Out';
        elseif (in_array((string)$id, array_map('strval', $od), true))    $status = 'OD';
        elseif (in_array((string)$id, array_map('strval', $leave), true)) $status = 'Leave';

        return [
            'user_id'       => $id,
            'name'          => $r['name'],
            'employee_code' => $r['employee_id'],
            'department'    => $r['department'],
            'profile_photo' => photo_url($r['profile_photo']),
            'status'        => $status,
            'punch_in'      => $r['first_in'],
            'punch_out'     => $r['last_out'],
            'punch_count'   => (int)$r['punch_count'],
        ];
    }, $rows), ['date' => $date]);
}

/** GET admin/leaves - the approval queue. */
function admin_leaves(mysqli $conn): void {
    admin_guard($conn);
    $p = paging(30);

    $where  = ['1 = 1'];
    $types  = '';
    $params = [];

    $status = param('status', 'Pending');
    if (in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $where[]  = 'la.status = ?';
        $types   .= 's';
        $params[] = $status;
    }

    $uid = param_int('user_id');
    if ($uid) { $where[] = 'la.user_id = ?'; $types .= 'i'; $params[] = $uid; }

    $clause = implode(' AND ', $where);
    $total = (int)fetch_value(
        $conn,
        "SELECT COUNT(*) FROM leave_applications la JOIN users u ON u.id = la.user_id WHERE $clause",
        $types,
        $params
    );

    $rows = fetch_all(
        $conn,
        "SELECT la.*, u.name AS employee_name, u.employee_id AS employee_code, " . DEPT_SQL . " AS department,
                r.name AS reviewer_name
           FROM leave_applications la
           JOIN users u ON u.id = la.user_id
           LEFT JOIN users r ON r.id = la.reviewed_by
          WHERE $clause
          ORDER BY la.created_at DESC
          LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$p['limit'], $p['offset']])
    );

    ok(array_map('shape_leave', $rows), ['meta' => meta($p, $total)]);
}

/** POST admin/leave_action { id, action: approve|reject, notes? } */
function admin_leave_action(mysqli $conn): void {
    require_method('POST');
    $admin = admin_guard($conn, true);

    $id     = param_int('id');
    $action = strtolower((string)(param('action') ?? ''));
    if (!$id) fail('id is required.', 422, 'validation_error');
    if (!in_array($action, ['approve', 'reject'], true)) {
        fail("action must be either 'approve' or 'reject'.", 422, 'validation_error');
    }

    $leave = fetch_one($conn, "SELECT * FROM leave_applications WHERE id = ?", 'i', [$id]);
    if (!$leave) fail('Leave request not found.', 404, 'not_found');
    if ($leave['status'] !== 'Pending') {
        fail('This request was already ' . strtolower($leave['status']) . '.', 409, 'already_reviewed');
    }

    $status = $action === 'approve' ? 'Approved' : 'Rejected';
    $notes  = mb_substr((string)(param('notes') ?? ''), 0, 1000);
    $by     = (int)$admin['id'];

    $stmt = $conn->prepare(
        "UPDATE leave_applications
            SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
          WHERE id = ? AND status = 'Pending'"
    );
    $stmt->bind_param('ssii', $status, $notes, $by, $id);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed < 1) fail('This request was already reviewed by someone else.', 409, 'already_reviewed');

    // Keep the yearly balance in step when leave is approved.
    if ($status === 'Approved') {
        $year = (int)date('Y', strtotime($leave['start_date']));
        $sync = $conn->prepare(
            "INSERT INTO employee_leave_balances (user_id, leave_type, year, days_allowed, days_used)
             VALUES (?, ?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE days_used = days_used + VALUES(days_used)"
        );
        $days = (float)$leave['days_count'];
        $sync->bind_param('isid', $leave['user_id'], $leave['leave_type'], $year, $days);
        // A missing unique key on (user_id, leave_type, year) makes this a no-op insert;
        // the balance endpoint recomputes from approved applications either way.
        @$sync->execute();
        $sync->close();
    }

    $row = fetch_one($conn, "SELECT * FROM leave_applications WHERE id = ?", 'i', [$id]);
    ok(['message' => 'Leave request ' . strtolower($status) . '.', 'leave' => shape_leave($row)]);
}

/** POST admin/manual_attendance { user_id, date, punch_in, punch_out?, status? } */
function admin_manual_attendance(mysqli $conn): void {
    require_method('POST');
    $admin = admin_guard($conn, true);

    $uid  = param_int('user_id');
    $date = (string)(param('date') ?? '');
    $in   = param('punch_in');
    $out  = param('punch_out');

    if (!$uid) fail('user_id is required.', 422, 'validation_error');
    if (!valid_date($date)) fail('date must be YYYY-MM-DD.', 422, 'validation_error');
    if ($date > date('Y-m-d')) fail('You cannot record attendance for a future date.', 422, 'validation_error');

    $employee = fetch_one($conn, "SELECT id FROM users WHERE id = ?", 'i', [$uid]);
    if (!$employee) fail('Employee not found.', 404, 'not_found');

    foreach (['punch_in' => $in, 'punch_out' => $out] as $label => $t) {
        if ($t !== null && $t !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$t)) {
            fail("$label must be HH:MM or HH:MM:SS.", 422, 'validation_error');
        }
    }
    if (($in === null || $in === '') && ($out === null || $out === '')) {
        fail('Provide at least a punch_in time.', 422, 'validation_error');
    }

    $in     = $in  ? substr((string)$in . ':00', 0, 8)  : null;
    $out    = $out ? substr((string)$out . ':00', 0, 8) : null;
    $status = (string)(param('status') ?? 'Present');
    if (!in_array($status, ['Present', 'Absent', 'Leave', 'Late'], true)) $status = 'Present';
    $by = (int)$admin['id'];

    $location = 'Manual entry by ' . $admin['name'];
    $stmt = $conn->prepare(
        "INSERT INTO attendance (user_id, date, punch_in, punch_out, status, punch_in_location, punch_in_by, punch_out_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $out_by = $out ? $by : null;
    $stmt->bind_param('isssssii', $uid, $date, $in, $out, $status, $location, $by, $out_by);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not save the manual attendance entry.', 500, 'db_error');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    error_log("API MANUAL_ATTENDANCE: admin {$by} added record {$id} for user {$uid} on {$date}");
    $row = fetch_one($conn, "SELECT * FROM attendance WHERE id = ?", 'i', [$id]);
    ok(['message' => 'Attendance recorded.', 'attendance' => shape_attendance($row)]);
}

/** POST admin/mark_od { user_id, date } */
function admin_mark_od(mysqli $conn): void {
    require_method('POST');
    $admin = admin_guard($conn, true);

    $uid  = param_int('user_id');
    $date = (string)(param('date') ?? param('od_date') ?? '');
    if (!$uid) fail('user_id is required.', 422, 'validation_error');
    if (!valid_date($date)) fail('date must be YYYY-MM-DD.', 422, 'validation_error');

    $exists = fetch_one($conn, "SELECT id FROM od_records WHERE user_id = ? AND od_date = ?", 'is', [$uid, $date]);
    if ($exists) fail('This employee is already marked on OD for ' . $date . '.', 409, 'duplicate');

    $by = (int)$admin['id'];
    $stmt = $conn->prepare("INSERT INTO od_records (user_id, od_date, marked_by, marked_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('isi', $uid, $date, $by);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not mark OD.', 500, 'db_error');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    ok(['message' => 'OD marked for ' . $date . '.', 'id' => $id]);
}

/** POST admin/mark_comp_off { user_id, comp_off_date, earned_date } */
function admin_mark_comp_off(mysqli $conn): void {
    require_method('POST');
    $admin = admin_guard($conn, true);

    $uid     = param_int('user_id');
    $date    = (string)(param('comp_off_date') ?? param('date') ?? '');
    $earned  = (string)(param('earned_date') ?? '');
    if (!$uid) fail('user_id is required.', 422, 'validation_error');
    if (!valid_date($date)) fail('comp_off_date must be YYYY-MM-DD.', 422, 'validation_error');
    if (!valid_date($earned)) fail('earned_date must be YYYY-MM-DD.', 422, 'validation_error');

    $exists = fetch_one(
        $conn,
        "SELECT id FROM comp_off_requests WHERE user_id = ? AND comp_off_date = ?",
        'is',
        [$uid, $date]
    );
    if ($exists) fail('A comp off already exists for ' . $date . '.', 409, 'duplicate');

    $by = (int)$admin['id'];
    $stmt = $conn->prepare(
        "INSERT INTO comp_off_requests (user_id, comp_off_date, earned_date, marked_by, marked_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param('issi', $uid, $date, $earned, $by);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not mark comp off.', 500, 'db_error');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    ok(['message' => 'Comp off marked for ' . $date . '.', 'id' => $id]);
}

/** GET admin/employee_summary - monthly totals for every employee. */
function admin_employee_summary(mysqli $conn): void {
    admin_guard($conn);
    [$from, $to] = date_range();

    $rows = fetch_all(
        $conn,
        "SELECT u.id, u.name, u.employee_id, " . DEPT_SQL . " AS department,
                COUNT(DISTINCT a.date) AS present_days,
                COUNT(a.id)            AS punch_count,
                SUM(a.punch_out IS NULL AND a.punch_in IS NOT NULL) AS incomplete
           FROM users u
           LEFT JOIN attendance a ON a.user_id = u.id AND a.date BETWEEN ? AND ?
          WHERE " . STAFF_SQL . " AND (u.status IS NULL OR u.status <> 'Resign')
          GROUP BY u.id, u.name, u.employee_id, u.department_id
          ORDER BY u.name",
        'ss',
        [$from, $to]
    );

    ok(array_map(static function (array $r): array {
        return [
            'user_id'       => (int)$r['id'],
            'name'          => $r['name'],
            'employee_code' => $r['employee_id'],
            'department'    => $r['department'],
            'present_days'  => (int)$r['present_days'],
            'punch_count'   => (int)$r['punch_count'],
            'incomplete'    => (int)$r['incomplete'],
        ];
    }, $rows), ['range' => ['from' => $from, 'to' => $to]]);
}

/** POST admin/reset_password { user_id, new_password } */
function admin_reset_password(mysqli $conn): void {
    require_method('POST');
    $admin = admin_guard($conn, true);

    $uid = param_int('user_id');
    $new = (string)(param('new_password') ?? '');
    if (!$uid) fail('user_id is required.', 422, 'validation_error');
    if (strlen($new) < 6) fail('New password must be at least 6 characters.', 422, 'weak_password');

    $target = fetch_one($conn, "SELECT id, role FROM users WHERE id = ?", 'i', [$uid]);
    if (!$target) fail('Employee not found.', 404, 'not_found');
    if ($target['role'] === 'superadmin' && $admin['role'] !== 'superadmin') {
        fail('Only a super admin can reset a super admin password.', 403, 'forbidden');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, password_set = 1 WHERE id = ?");
    $stmt->bind_param('si', $hash, $uid);
    $stmt->execute();
    $stmt->close();

    // Force the employee's devices to sign in again.
    $revoke = $conn->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
    $revoke->bind_param('i', $uid);
    $revoke->execute();
    $revoke->close();

    error_log("API RESET_PASSWORD: admin {$admin['id']} reset password for user {$uid}");
    ok(['message' => 'Password reset. The employee must sign in again.']);
}
