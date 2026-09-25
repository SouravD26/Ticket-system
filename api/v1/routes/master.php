<?php
/**
 * Master data - the reference lists an app needs to populate its dropdowns.
 * Read-only for any authenticated user.
 */
declare(strict_types=1);

/** GET master - every list in one call, so the app can cache on login. */
function master_index(mysqli $conn): void {
    auth_user($conn);

    ok([
        'departments' => fetch_all($conn, "SELECT id, name FROM departments ORDER BY name"),
        'companies'   => fetch_all($conn, "SELECT id, name FROM companies ORDER BY name"),
        'locations'   => array_map(static function (array $r): array {
            return [
                'id'        => (int)$r['id'],
                'name'      => $r['name'],
                'latitude'  => $r['latitude'] !== null ? (float)$r['latitude'] : null,
                'longitude' => $r['longitude'] !== null ? (float)$r['longitude'] : null,
            ];
        }, fetch_all($conn, "SELECT id, name, latitude, longitude FROM locations ORDER BY name")),
        'shifts'      => array_map(static function (array $r): array {
            return [
                'id'         => (int)$r['id'],
                'start_time' => $r['start_time'],
                'end_time'   => $r['end_time'],
                'label'      => substr((string)$r['start_time'], 0, 5) . ' - ' . substr((string)$r['end_time'], 0, 5),
            ];
        }, fetch_all($conn, "SELECT id, start_time, end_time FROM shifts ORDER BY start_time")),
        'leave_types' => LEAVE_TYPES_LIST,
        'office'      => master_office_row($conn),
    ]);
}

const LEAVE_TYPES_LIST = [
    'Casual Leave', 'Sick Leave', 'Earned Leave',
    'Maternity Leave', 'Paternity Leave', 'Unpaid Leave',
];

function master_office_row(mysqli $conn): ?array {
    $row = fetch_one($conn, "SELECT office_name, latitude, longitude, radius_meters FROM office_settings ORDER BY id LIMIT 1");
    if (!$row) return null;
    return [
        'office_name'   => $row['office_name'],
        'latitude'      => $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude'     => $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'radius_meters' => (int)$row['radius_meters'],
    ];
}

/** GET master/departments */
function master_departments(mysqli $conn): void {
    auth_user($conn);
    ok(fetch_all($conn, "SELECT id, name FROM departments ORDER BY name"));
}

/** GET master/companies */
function master_companies(mysqli $conn): void {
    auth_user($conn);
    ok(fetch_all($conn, "SELECT id, name FROM companies ORDER BY name"));
}

/** GET master/locations */
function master_locations(mysqli $conn): void {
    auth_user($conn);
    ok(fetch_all($conn, "SELECT id, name, latitude, longitude FROM locations ORDER BY name"));
}

/** GET master/shifts */
function master_shifts(mysqli $conn): void {
    auth_user($conn);
    ok(fetch_all($conn, "SELECT id, start_time, end_time FROM shifts ORDER BY start_time"));
}

/** GET master/office - geofence configuration used by the punch screens. */
function master_office(mysqli $conn): void {
    $user = auth_user($conn);
    ok([
        'office'         => master_office_row($conn),
        'geo_restricted' => (int)($user['geo_restricted'] ?? 0) === 1,
        'max_accuracy_meters' => ATT_MAX_ACCURACY_M,
    ]);
}

/** GET master/holidays - holiday calendar, optionally filtered by project. */
function master_holidays(mysqli $conn): void {
    auth_user($conn);
    $year    = param_int('year', (int)date('Y'));
    $project = param('project');

    if ($project !== null && $project !== '') {
        $rows = fetch_all(
            $conn,
            "SELECT id, project, holiday_date, title FROM project_holidays
              WHERE YEAR(holiday_date) = ? AND project = ? ORDER BY holiday_date",
            'is',
            [$year, $project]
        );
    } else {
        $rows = fetch_all(
            $conn,
            "SELECT id, project, holiday_date, title FROM project_holidays
              WHERE YEAR(holiday_date) = ? ORDER BY holiday_date",
            'i',
            [$year]
        );
    }

    ok(array_map(static function (array $r): array {
        return [
            'id'           => (int)$r['id'],
            'project'      => $r['project'],
            'holiday_date' => $r['holiday_date'],
            'title'        => $r['title'],
            'day'          => date('l', strtotime($r['holiday_date'])),
        ];
    }, $rows), ['year' => $year]);
}

/** GET master/policy - attendance policy thresholds the app displays. */
function master_policy(mysqli $conn): void {
    auth_user($conn);
    $row = fetch_one($conn, "SELECT * FROM attendance_policy ORDER BY id LIMIT 1");
    if (!$row) ok(null, ['message' => 'No attendance policy configured.']);

    ok([
        'single_punch_absent'  => (int)$row['single_punch_absent'] === 1,
        'half_day_min_hours'   => (float)$row['half_day_min_hours'],
        'full_day_basis'       => $row['full_day_basis'],
        'full_day_fixed_hours' => (float)$row['full_day_fixed_hours'],
        'sandwich_absent'      => (int)$row['sandwich_absent'] === 1,
        'updated_at'           => $row['updated_at'],
    ]);
}
