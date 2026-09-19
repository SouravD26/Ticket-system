<?php
/**
 * Profile endpoints - the signed-in user's own record, photo and salary.
 */
declare(strict_types=1);

/** GET profile - full profile of the signed-in user. */
function profile_index(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);

    $row = ((int)$uid === (int)$user['id'])
        ? $user
        : fetch_one($conn, "SELECT u.*, " . DEPT_SQL . " AS department FROM users u WHERE u.id = ?", 'i', [$uid]);
    if (!$row) fail('Employee not found.', 404, 'not_found');

    $tracking = fetch_one(
        $conn,
        "SELECT enable_tracking, geofence_radius, tracking_interval FROM employee_tracking_settings WHERE user_id = ?",
        'i',
        [$uid]
    );

    $profile = shape_user($row, true);
    $profile['bank'] = [
        'account_number' => $row['bank_account_number'] ?? null,
        'ifsc_code'      => $row['bank_ifsc_code'] ?? null,
        'bank_name'      => $row['bank_name'] ?? null,
    ];
    $profile['tracking'] = [
        'enabled'           => (int)($tracking['enable_tracking'] ?? 0) === 1,
        'geofence_radius'   => isset($tracking['geofence_radius']) ? (int)$tracking['geofence_radius'] : null,
        'tracking_interval' => isset($tracking['tracking_interval']) ? (int)$tracking['tracking_interval'] : null,
    ];

    ok($profile);
}

/** POST profile/update - the fields an employee may edit about themselves. */
function profile_update(mysqli $conn): void {
    require_method('POST', 'PUT', 'PATCH');
    $user = auth_user($conn);
    $uid  = (int)$user['id'];

    // Deliberately narrow: an employee cannot change their own role,
    // department, salary, geofence flag or employment status.
    $editable = [
        'email'            => 100,
        'alternate_number' => 20,
        'address'          => 500,
        'family_member_name' => 100,
    ];

    $sets = [];
    $types = '';
    $params = [];
    foreach ($editable as $field => $max) {
        $v = param($field);
        if ($v === null) continue;
        if ($field === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            fail('Please provide a valid email address.', 422, 'validation_error');
        }
        $sets[]   = "$field = ?";
        $types   .= 's';
        $params[] = mb_substr((string)$v, 0, $max);
    }

    if (!$sets) fail('No editable fields were supplied.', 422, 'validation_error');

    $types   .= 'i';
    $params[] = $uid;
    $stmt = $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?");
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        fail('Could not update your profile. Please try again.', 500, 'db_error');
    }
    $stmt->close();

    $row = fetch_one($conn, "SELECT u.*, " . DEPT_SQL . " AS department FROM users u WHERE u.id = ?", 'i', [$uid]);
    ok(['message' => 'Profile updated.', 'user' => shape_user($row, true)]);
}

/** POST profile/photo { image } - base64 profile picture. */
function profile_photo(mysqli $conn): void {
    require_method('POST');
    $user = auth_user($conn);
    $uid  = (int)$user['id'];

    $image = (string)(param('image') ?? param('photo') ?? param('profile_photo') ?? '');
    if ($image === '') fail('image is required (base64 or data URI).', 422, 'validation_error');

    $data = preg_replace('#^data:image/\w+;base64,#i', '', $image);
    $bin  = base64_decode(str_replace(' ', '+', (string)$data), true);
    if ($bin === false || strlen($bin) < 100) fail('Image could not be decoded.', 422, 'validation_error');
    if (strlen($bin) > 5 * 1024 * 1024) fail('Image is too large (max 5 MB).', 422, 'validation_error');
    if (function_exists('getimagesizefromstring') && @getimagesizefromstring($bin) === false) {
        fail('File must be a valid JPEG or PNG image.', 422, 'validation_error');
    }

    $dir = UPLOAD_BASE . '/profile_photos';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fail('Server could not create the upload folder.', 500, 'server_error');
    }

    $filename = 'profile_' . $uid . '_' . date('YmdHis') . '.jpg';
    if (file_put_contents($dir . '/' . $filename, $bin) === false) {
        fail('Server could not save the image.', 500, 'server_error');
    }

    $stored = 'uploads/profile_photos/' . $filename;
    $stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
    $stmt->bind_param('si', $stored, $uid);
    $stmt->execute();
    $stmt->close();

    ok(['message' => 'Profile photo updated.', 'profile_photo' => photo_url($stored)]);
}

/** GET profile/salary - the caller's own salary structure. */
function profile_salary(mysqli $conn): void {
    $user = auth_user($conn);
    $uid  = target_user_id($user);

    $row = fetch_one(
        $conn,
        "SELECT * FROM salary_structures WHERE user_id = ? ORDER BY id DESC LIMIT 1",
        'i',
        [$uid]
    );
    if (!$row) ok(null, ['message' => 'No salary structure has been configured yet.']);

    $custom = json_decode((string)($row['custom_components'] ?? ''), true);

    ok([
        'user_id'                   => (int)$row['user_id'],
        'template'                  => $row['template'],
        'statutory_component'       => $row['statutory_component'],
        'effective_cycle'           => $row['effective_cycle'],
        'salary_ctc'                => (float)$row['salary_ctc'],
        'basic_monthly'             => (float)$row['basic_monthly'],
        'special_allowance_monthly' => (float)$row['special_allowance_monthly'],
        'pf_monthly'                => $row['pf_monthly'] !== null ? (float)$row['pf_monthly'] : null,
        'esi_monthly'               => $row['esi_monthly'] !== null ? (float)$row['esi_monthly'] : null,
        'pf_calc'                   => $row['pf_calc'],
        'esi_calc'                  => $row['esi_calc'],
        'custom_components'         => is_array($custom) ? $custom : [],
        'updated_at'                => $row['updated_at'],
    ]);
}
