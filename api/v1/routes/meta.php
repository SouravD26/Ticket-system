<?php
/**
 * Discovery endpoints - the route index and a health check.
 */
declare(strict_types=1);

function api_endpoint_map(): array {
    return [
        'auth' => [
            'POST auth/login'            => 'employee ID, phone or email + password, device_info? -> token + user',
            'POST auth/logout'           => 'all=1 to sign out every device',
            'GET  auth/me'               => 'current user + today punch state',
            'GET  auth/devices'          => 'active sessions for this user',
            'POST auth/change_password'  => 'current_password, new_password',
        ],
        'profile' => [
            'GET  profile'               => 'full profile of the signed-in user',
            'POST profile/update'        => 'email, alternate_number, address, family_member_name',
            'POST profile/photo'         => 'image (base64 or data URI)',
            'GET  profile/salary'        => 'salary structure',
        ],
        'attendance' => [
            'GET  attendance/today'      => "today's punches and punch state",
            'POST attendance/punch_in'   => 'selfie_image, latitude, longitude, accuracy?',
            'POST attendance/punch_out'  => 'selfie_image, latitude, longitude, accuracy?',
            'GET  attendance/history'    => 'month=YYYY-MM or from/to, page, per_page',
            'GET  attendance/summary'    => 'day-by-day summary + month stats',
            'POST attendance/track'      => 'latitude, longitude, accuracy?, address? (while punched in)',
            'GET  attendance/route'      => 'punch_in_id -> tracked points and distance',
        ],
        'leave' => [
            'GET  leave/types'           => 'leave types with yearly allowance',
            'GET  leave/list'            => 'own applications; status?, page',
            'POST leave/apply'           => 'leave_type, start_date, end_date, reason',
            'POST leave/cancel'          => 'id (pending only)',
            'GET  leave/balance'         => 'year? -> allowed / used / balance',
            'GET  leave/od'              => 'on-duty records for a range',
            'GET  leave/comp_off'        => 'comp-off records for a range',
        ],
        'tickets' => [
            'GET  tickets'               => 'own tickets (IT: assigned + raised; admin: all); status?, search?, page',
            'GET  tickets/show'          => 'id -> ticket, replies and activity',
            'POST tickets/create'        => 'subject, body, location, trained_before (yes|no)',
            'POST tickets/reply'         => 'id, message, is_internal? (IT only)',
            'POST tickets/update'        => 'id, status?, priority?, assigned_to?, department_id? (IT / Super Admin)',
            'POST tickets/acknowledge'   => 'id -> requester signs the work off and the ticket closes',
            'POST tickets/reraise'       => 'id, reason? -> still broken, back to the queue',
            'GET  tickets/locations'     => 'location list, statuses and priorities for the form',
            'GET  tickets/it_staff'      => 'employees a ticket may be assigned to (HRMS IT department / designation)',
            'GET  tickets/dashboard'     => 'counts + the queue for this role + recent movement (the IT dashboard)',
            'POST tickets/assign'        => 'id, assigned_to (0 to unassign), priority? - Super Admin',
            'GET  tickets/worklog'       => 'id -> work recorded against the ticket + total hours',
            'POST tickets/log_work'      => 'id, summary, work_date?, hours?, details? - the assignee',
            'POST tickets/delete_work'   => 'id of the work entry',
        ],
        'employees' => [
            'GET  employees'             => 'directory: search, department, company, location, page',
            'GET  employees/show'        => 'id -> one employee',
            'GET  employees/on_duty'     => 'who is currently punched in',
        ],
        'master' => [
            'GET  master'                => 'all reference lists in one call',
            'GET  master/departments'    => 'department list',
            'GET  master/companies'      => 'company list',
            'GET  master/locations'      => 'location list',
            'GET  master/shifts'         => 'shift list',
            'GET  master/office'         => 'geofence settings for this user',
            'GET  master/holidays'       => 'year?, project?',
            'GET  master/policy'         => 'attendance policy thresholds',
        ],
        'admin' => [
            'GET  admin/dashboard'         => 'headline counts for today',
            'GET  admin/today'             => 'per-employee status board',
            'GET  admin/attendance'        => 'org-wide records: from/to, department, search, page',
            'GET  admin/employee_summary'  => 'per-employee monthly totals',
            'GET  admin/leaves'            => 'approval queue; status?',
            'POST admin/leave_action'      => 'id, action=approve|reject, notes?',
            'POST admin/manual_attendance' => 'user_id, date, punch_in, punch_out?, status?',
            'POST admin/mark_od'           => 'user_id, date',
            'POST admin/mark_comp_off'     => 'user_id, comp_off_date, earned_date',
            'POST admin/reset_password'    => 'user_id, new_password',
        ],
        'meta' => [
            'GET  meta/health'           => 'service + database health (no auth)',
        ],
    ];
}

/** GET / - the route index. No auth so an app can probe the base URL. */
function route_index(): void {
    ok([
        'name'        => 'Attendance Mobile API',
        'version'     => 'v1',
        'base_url'    => base_url() . '/api/v1',
        'auth'        => 'Send "Authorization: Bearer <token>" on every endpoint except auth/login and meta/health.',
        'server_time' => date('Y-m-d H:i:s'),
        'timezone'    => date_default_timezone_get(),
        'endpoints'   => api_endpoint_map(),
    ]);
}

/** GET meta - same as the index. */
function meta_index(mysqli $conn): void {
    route_index();
}

/** GET meta/health - unauthenticated liveness probe. */
/**
 * GET meta/diag - deployment check, no auth.
 *
 * Reports which files the server is actually running and which shared helpers
 * resolve, so a half-finished upload can be identified without shell access.
 * Reports no credentials and no data: file sizes, PHP version and whether a
 * function exists. Safe to leave in place, but it is a diagnostic, not an API.
 */
function meta_diag(mysqli $conn): void {
    $files = [];
    foreach ([
        'core.php'             => API_ROOT . '/core.php',
        'index.php'            => API_ROOT . '/index.php',
        'routes/tickets.php'   => API_ROOT . '/routes/tickets.php',
        'routes/meta.php'      => API_ROOT . '/routes/meta.php',
        'includes/config.php'  => APP_ROOT . '/includes/config.php',
    ] as $label => $path) {
        $files[$label] = is_file($path)
            ? ['bytes' => filesize($path), 'modified' => date('Y-m-d H:i:s', (int)filemtime($path))]
            : ['bytes' => null, 'modified' => null];
    }

    ok([
        'php'        => PHP_VERSION,
        'api_root'   => API_ROOT,
        'app_root'   => APP_ROOT,
        'files'      => $files,
        // Helpers defined in core.php. Anything from routes/tickets.php is
        // absent here by design: that file only loads for tickets/* routes.
        'functions'  => [
            'tickets_can_work'       => function_exists('tickets_can_work'),
            'tickets_it_designation' => function_exists('tickets_it_designation'),
            'store_base64_image'     => function_exists('store_base64_image'),
            'attachment_url'         => function_exists('attachment_url'),
            // debug_allowed() only exists in the newer index.php.
            'debug_allowed'          => function_exists('debug_allowed'),
        ],
        'constants'  => [
            'APP_KEY'        => defined('APP_KEY'),
            'API_DEBUG_KEY'  => defined('API_DEBUG_KEY'),
        ],
        'opcache'    => function_exists('opcache_get_status') ? 'available' : 'absent',
    ]);
}

function meta_health(mysqli $conn): void {
    $db = false;
    try {
        $db = (bool)$conn->query('SELECT 1');
    } catch (Throwable $e) {
        $db = false;
    }

    ok([
        'status'      => $db ? 'ok' : 'degraded',
        'database'    => $db,
        'php'         => PHP_VERSION,
        'server_time' => date('Y-m-d H:i:s'),
        'timezone'    => date_default_timezone_get(),
    ]);
}
