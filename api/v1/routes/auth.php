<?php
/**
 * Auth endpoints - login, logout, session, password.
 */
declare(strict_types=1);

/** POST auth/login  { phone|username, password, device_info? } */
function auth_login(mysqli $conn): void {
    require_method('POST');

    $identifier = (string)(param('phone') ?? param('username') ?? param('email') ?? '');
    $password   = (string)(param('password') ?? '');

    if ($identifier === '' || $password === '') {
        fail('Phone and password are required.', 422, 'validation_error');
    }

    // Login by phone, employee code or email - whichever the app sends.
    $user = fetch_one(
        $conn,
        "SELECT u.*, " . DEPT_SQL . " AS department FROM users u
          WHERE u.phone = ? OR u.employee_id = ? OR u.email = ? ORDER BY (u.phone = ?) DESC LIMIT 1",
        'ssss',
        [$identifier, $identifier, $identifier, $identifier]
    );

    // Same message either way so the endpoint cannot be used to enumerate users.
    if (!$user || !password_verify($password, (string)$user['password'])) {
        fail('Invalid phone number or password.', 401, 'invalid_credentials');
    }
    if (($user['status'] ?? '') === 'Resign' || empty($user['is_active'])) {
        fail('Your account is inactive. Contact your administrator.', 403, 'account_inactive');
    }

    // Housekeeping: drop this user's expired tokens on each login.
    $cleanup = $conn->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND expires_at < NOW()");
    $cleanup->bind_param('i', $user['id']);
    $cleanup->execute();
    $cleanup->close();

    $device = (string)(param('device_info') ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'mobile-app'));
    $issued = issue_token($conn, (int)$user['id'], $device);

    ok([
        'token'         => $issued['token'],
        'token_type'    => 'Bearer',
        'expires_at'    => $issued['expires_at'],
        'must_set_password' => (int)($user['password_set'] ?? 1) === 0,
        'user'          => shape_user($user, true),
    ]);
}

/** POST auth/logout - revokes the current token (or all of them with all=1). */
function auth_logout(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);

    if (param_int('all') === 1) {
        $stmt = $conn->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
        $stmt->bind_param('i', $user['id']);
    } else {
        $stmt = $conn->prepare("DELETE FROM auth_tokens WHERE id = ?");
        $stmt->bind_param('i', $user['token_id']);
    }
    $stmt->execute();
    $stmt->close();

    ok(['logged_out' => true]);
}

/** GET auth/me - the signed-in user plus today's punch state. */
function auth_me(mysqli $conn): void {
    $user = auth_user($conn);
    $date = attendance_date();

    $open = fetch_one(
        $conn,
        "SELECT id, punch_in FROM attendance WHERE user_id = ? AND date = ? AND punch_out IS NULL ORDER BY id DESC LIMIT 1",
        'is',
        [$user['id'], $date]
    );

    ok([
        'user'            => shape_user($user, true),
        'attendance_date' => $date,
        'server_time'     => date('Y-m-d H:i:s'),
        'is_punched_in'   => $open !== null,
        'open_punch_id'   => $open ? (int)$open['id'] : null,
    ]);
}

/** GET auth/devices - active sessions for this user. */
function auth_devices(mysqli $conn): void {
    $user = auth_user($conn);
    $rows = fetch_all(
        $conn,
        "SELECT id, device_info, created_at, expires_at FROM auth_tokens
          WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC",
        'i',
        [$user['id']]
    );

    ok(array_map(static function (array $r) use ($user): array {
        return [
            'id'          => (int)$r['id'],
            'device_info' => $r['device_info'],
            'created_at'  => $r['created_at'],
            'expires_at'  => $r['expires_at'],
            'current'     => (int)$r['id'] === (int)$user['token_id'],
        ];
    }, $rows));
}

/** POST auth/change_password { current_password, new_password } */
function auth_change_password(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);

    $current = (string)(param('current_password') ?? '');
    $new     = (string)(param('new_password') ?? '');

    if ($new === '') fail('New password is required.', 422, 'validation_error');
    if (strlen($new) < 6) fail('New password must be at least 6 characters.', 422, 'weak_password');
    if (!password_verify($current, (string)$user['password'])) {
        fail('Your current password is incorrect.', 403, 'invalid_credentials');
    }

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ?, password_set = 1 WHERE id = ?");
    $stmt->bind_param('si', $hash, $user['id']);
    $stmt->execute();
    $stmt->close();

    // Every other device must re-authenticate with the new password.
    $revoke = $conn->prepare("DELETE FROM auth_tokens WHERE user_id = ? AND id <> ?");
    $revoke->bind_param('ii', $user['id'], $user['token_id']);
    $revoke->execute();
    $revoke->close();

    ok(['password_changed' => true]);
}
