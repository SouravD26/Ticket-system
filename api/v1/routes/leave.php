<?php
/**
 * Leave endpoints - apply, list, cancel, balances, plus OD and comp-off records.
 */
declare(strict_types=1);

const LEAVE_TYPES = [
    'Casual Leave', 'Sick Leave', 'Earned Leave',
    'Maternity Leave', 'Paternity Leave', 'Unpaid Leave',
];

/** GET leave/types */
function leave_types(mysqli $conn): void {
    auth_user($conn);
    $policies = fetch_all(
        $conn,
        "SELECT leave_type, days_allowed, year FROM leave_policies WHERE year = ? ORDER BY leave_type",
        'i',
        [(int)date('Y')]
    );
    $allowed = [];
    foreach ($policies as $p) $allowed[$p['leave_type']] = (float)$p['days_allowed'];

    ok(array_map(static function (string $t) use ($allowed): array {
        return ['leave_type' => $t, 'days_allowed' => $allowed[$t] ?? null];
    }, LEAVE_TYPES));
}

/** GET leave/list - this user's applications (admins may pass user_id or status). */
function leave_list(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);
    $p    = paging(30);

    $where  = 'la.user_id = ?';
    $types  = 'i';
    $params = [$uid];

    $status = param('status');
    if ($status !== null && $status !== '' && in_array($status, ['Pending', 'Approved', 'Rejected'], true)) {
        $where   .= ' AND la.status = ?';
        $types   .= 's';
        $params[] = $status;
    }

    $total = (int)fetch_value($conn, "SELECT COUNT(*) FROM leave_applications la WHERE $where", $types, $params);

    $rows = fetch_all(
        $conn,
        "SELECT la.*, r.name AS reviewer_name
           FROM leave_applications la
           LEFT JOIN users r ON r.id = la.reviewed_by
          WHERE $where
          ORDER BY la.created_at DESC
          LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$p['limit'], $p['offset']])
    );

    ok(array_map('shape_leave', $rows), ['meta' => meta($p, $total)]);
}

function shape_leave(array $r): array {
    return [
        'id'            => (int)$r['id'],
        'user_id'       => (int)$r['user_id'],
        'leave_type'    => $r['leave_type'],
        'start_date'    => $r['start_date'],
        'end_date'      => $r['end_date'],
        'days_count'    => (int)$r['days_count'],
        'reason'        => $r['reason'],
        'status'        => $r['status'],
        'admin_notes'   => $r['admin_notes'],
        'reviewed_by'   => $r['reviewed_by'] !== null ? (int)$r['reviewed_by'] : null,
        'reviewer_name' => $r['reviewer_name'] ?? null,
        'reviewed_at'   => $r['reviewed_at'],
        'created_at'    => $r['created_at'],
        'can_cancel'    => $r['status'] === 'Pending',
        'employee_name' => $r['employee_name'] ?? null,
        'employee_code' => $r['employee_code'] ?? null,
    ];
}

/** POST leave/apply { leave_type, start_date, end_date, reason } */
function leave_apply(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);
    $uid  = (int)$user['id'];

    $in = require_params(['leave_type', 'start_date', 'end_date', 'reason']);

    if (!in_array($in['leave_type'], LEAVE_TYPES, true)) {
        fail('Unsupported leave type. Call leave/types for the valid list.', 422, 'validation_error');
    }
    if (!valid_date($in['start_date']) || !valid_date($in['end_date'])) {
        fail('Dates must be in YYYY-MM-DD format.', 422, 'validation_error');
    }
    if ($in['start_date'] > $in['end_date']) {
        fail('End date cannot be before the start date.', 422, 'validation_error');
    }

    $days = (int)((strtotime($in['end_date']) - strtotime($in['start_date'])) / 86400) + 1;
    if ($days > 366) fail('A single application cannot span more than a year.', 422, 'validation_error');

    // Block overlapping applications that are still live.
    $clash = fetch_one(
        $conn,
        "SELECT id, start_date, end_date FROM leave_applications
          WHERE user_id = ? AND status IN ('Pending','Approved')
            AND start_date <= ? AND end_date >= ? LIMIT 1",
        'iss',
        [$uid, $in['end_date'], $in['start_date']]
    );
    if ($clash) {
        fail('You already have a leave request covering ' . $clash['start_date'] . ' to ' . $clash['end_date'] . '.',
             409, 'overlapping_leave');
    }

    $stmt = $conn->prepare(
        "INSERT INTO leave_applications (user_id, leave_type, start_date, end_date, days_count, reason, status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'Pending', NOW())"
    );
    $reason = mb_substr((string)$in['reason'], 0, 2000);
    $stmt->bind_param('isssis', $uid, $in['leave_type'], $in['start_date'], $in['end_date'], $days, $reason);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not submit your leave request. Please try again.', 500, 'db_error');
    }
    $id = (int)$conn->insert_id;
    $stmt->close();

    $row = fetch_one($conn, "SELECT * FROM leave_applications WHERE id = ?", 'i', [$id]);
    ok(['message' => 'Leave request submitted.', 'leave' => shape_leave($row)]);
}

/** POST leave/cancel { id } - only while still pending. */
function leave_cancel(mysqli $conn): void {
    require_method('POST', 'DELETE');
    $user = auth_user($conn);
    $uid  = (int)$user['id'];
    $id   = param_int('id');
    if (!$id) fail('id is required.', 422, 'validation_error');

    $row = fetch_one($conn, "SELECT * FROM leave_applications WHERE id = ? AND user_id = ?", 'ii', [$id, $uid]);
    if (!$row) fail('Leave request not found.', 404, 'not_found');
    if ($row['status'] !== 'Pending') {
        fail('Only pending requests can be cancelled. This one is already ' . strtolower($row['status']) . '.', 409, 'not_cancellable');
    }

    $stmt = $conn->prepare("DELETE FROM leave_applications WHERE id = ? AND user_id = ? AND status = 'Pending'");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted < 1) fail('Leave request could not be cancelled.', 409, 'not_cancellable');
    ok(['cancelled' => true, 'id' => $id]);
}

/** GET leave/balance - allowance vs used for the year. */
function leave_balance(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);
    $year = param_int('year', (int)date('Y'));

    $balances = fetch_all(
        $conn,
        "SELECT leave_type, days_allowed, days_used FROM employee_leave_balances WHERE user_id = ? AND year = ?",
        'ii',
        [$uid, $year]
    );

    // Fall back to the company-wide policy for types with no personal row.
    $policy = fetch_all(
        $conn,
        "SELECT leave_type, days_allowed FROM leave_policies WHERE year = ?",
        'i',
        [$year]
    );

    // Approved days actually taken this year, regardless of the balance table.
    $taken = fetch_all(
        $conn,
        "SELECT leave_type, SUM(days_count) AS days FROM leave_applications
          WHERE user_id = ? AND status = 'Approved' AND YEAR(start_date) = ?
          GROUP BY leave_type",
        'ii',
        [$uid, $year]
    );
    $taken_map = [];
    foreach ($taken as $t) $taken_map[$t['leave_type']] = (float)$t['days'];

    $allowed_map = [];
    foreach ($policy as $p) $allowed_map[$p['leave_type']] = (float)$p['days_allowed'];
    foreach ($balances as $b) $allowed_map[$b['leave_type']] = (float)$b['days_allowed'];

    $used_map = $taken_map;
    foreach ($balances as $b) {
        $used_map[$b['leave_type']] = max((float)$b['days_used'], $taken_map[$b['leave_type']] ?? 0.0);
    }

    $out = [];
    foreach (array_unique(array_merge(LEAVE_TYPES, array_keys($allowed_map), array_keys($used_map))) as $type) {
        $allowed = $allowed_map[$type] ?? 0.0;
        $used    = $used_map[$type] ?? 0.0;
        $out[] = [
            'leave_type'   => $type,
            'days_allowed' => $allowed,
            'days_used'    => $used,
            'days_balance' => round($allowed - $used, 1),
        ];
    }

    ok(['year' => $year, 'balances' => $out]);
}

/** GET leave/od - on-duty records. */
function leave_od(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);
    [$from, $to] = date_range();

    $rows = fetch_all(
        $conn,
        "SELECT o.id, o.od_date, o.marked_at, m.name AS marked_by_name
           FROM od_records o
           LEFT JOIN users m ON m.id = o.marked_by
          WHERE o.user_id = ? AND o.od_date BETWEEN ? AND ?
          ORDER BY o.od_date DESC",
        'iss',
        [$uid, $from, $to]
    );

    ok(array_map(static function (array $r): array {
        return [
            'id'             => (int)$r['id'],
            'od_date'        => $r['od_date'],
            'marked_by_name' => $r['marked_by_name'],
            'marked_at'      => $r['marked_at'],
        ];
    }, $rows), ['range' => ['from' => $from, 'to' => $to]]);
}

/** GET leave/comp_off - compensatory off records. */
function leave_comp_off(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);
    [$from, $to] = date_range();

    $rows = fetch_all(
        $conn,
        "SELECT c.id, c.comp_off_date, c.earned_date, c.marked_at, m.name AS marked_by_name
           FROM comp_off_requests c
           LEFT JOIN users m ON m.id = c.marked_by
          WHERE c.user_id = ? AND c.comp_off_date BETWEEN ? AND ?
          ORDER BY c.comp_off_date DESC",
        'iss',
        [$uid, $from, $to]
    );

    ok(array_map(static function (array $r): array {
        return [
            'id'             => (int)$r['id'],
            'comp_off_date'  => $r['comp_off_date'],
            'earned_date'    => $r['earned_date'],
            'marked_by_name' => $r['marked_by_name'],
            'marked_at'      => $r['marked_at'],
        ];
    }, $rows), ['range' => ['from' => $from, 'to' => $to]]);
}
