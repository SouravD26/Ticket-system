<?php
/**
 * Employee directory - readable by any authenticated user.
 * Contact details only; salary and documents stay in the admin routes.
 */
declare(strict_types=1);

/** GET employees - searchable, paged directory. */
function employees_index(mysqli $conn): void {
    auth_user($conn);
    $p = paging(50);

    $where  = ["u.role <> 'superadmin'"];
    $types  = '';
    $params = [];

    $status = param('status');
    if ($status === 'Working' || $status === 'Resign') {
        $where[]  = 'u.status = ?';
        $types   .= 's';
        $params[] = $status;
    } else {
        // Default to current staff only.
        $where[] = "(u.status IS NULL OR u.status <> 'Resign')";
    }

    foreach (['department', 'company', 'location'] as $field) {
        $v = param($field);
        if ($v !== null && $v !== '') {
            $where[]  = ($field === 'department' ? DEPT_SQL : "u.$field") . " = ?";
            $types   .= 's';
            $params[] = $v;
        }
    }

    $search = param('search') ?? param('q');
    if ($search !== null && $search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(u.name LIKE ? OR u.employee_id LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
        $types  .= 'ssss';
        array_push($params, $like, $like, $like, $like);
    }

    $clause = implode(' AND ', $where);
    $total  = (int)fetch_value($conn, "SELECT COUNT(*) FROM users u WHERE $clause", $types, $params);

    $rows = fetch_all(
        $conn,
        "SELECT u.*, " . DEPT_SQL . " AS department FROM users u WHERE $clause ORDER BY u.name ASC LIMIT ? OFFSET ?",
        $types . 'ii',
        array_merge($params, [$p['limit'], $p['offset']])
    );

    ok(array_map(static function (array $r): array { return shape_user($r); }, $rows),
       ['meta' => meta($p, $total)]);
}

/** GET employees/show?id=  - one employee's directory card. */
function employees_show(mysqli $conn): void {
    $user = auth_user($conn);
    $id   = param_int('id');
    if (!$id) fail('id is required.', 422, 'validation_error');

    $row = fetch_one($conn, "SELECT u.*, " . DEPT_SQL . " AS department FROM users u WHERE u.id = ?", 'i', [$id]);
    if (!$row) fail('Employee not found.', 404, 'not_found');

    // Full detail only for admins or the employee themselves.
    $full = is_admin($user) || (int)$id === (int)$user['id'];
    ok(shape_user($row, $full));
}

/** GET employees/on_duty - who is currently punched in. */
function employees_on_duty(mysqli $conn): void {
    auth_user($conn);
    $date = attendance_date();

    $rows = fetch_all(
        $conn,
        "SELECT a.*, u.name AS employee_name, u.employee_id AS employee_code, " . DEPT_SQL . " AS department, u.profile_photo
           FROM attendance a
           JOIN users u ON u.id = a.user_id
          WHERE a.date = ? AND a.punch_out IS NULL
          ORDER BY a.punch_in ASC",
        's',
        [$date]
    );

    ok(array_map(static function (array $r): array {
        $shaped = shape_attendance($r);
        $shaped['profile_photo'] = photo_url($r['profile_photo'] ?? null);
        return $shaped;
    }, $rows), ['date' => $date, 'count' => count($rows)]);
}
