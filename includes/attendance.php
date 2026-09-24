<?php
/**
 * Attendance rules, shared by the web pages, the face kiosk and the mobile API.
 *
 * Carried over from the old HRMS as they were:
 *  - The server clock is the only clock. A phone cannot choose its own punch time.
 *  - An attendance "day" runs 06:00 to 06:00, so a night shift punching out at
 *    02:00 closes the record it opened the evening before.
 *  - A person may punch in and out several times a day. Each in/out pair is one
 *    attendance row; reports read the first in and the last out.
 *  - An employee with geo_restricted = 1 may only punch within the office radius.
 */
require_once __DIR__ . '/functions.php';

const ATT_DAY_STARTS_AT = 6; // hour
/** The longest a single punch in may stay open before it counts as forgotten. */
const ATT_SHIFT_MAX_H = 16;
const ATT_DIR = UPLOAD_DIR . '/hrms';
/** A self punch whose GPS fix is less precise than this (metres) is refused. */
const ATT_MAX_ACCURACY_M = 100;

/** The attendance date a punch made right now belongs to. */
function att_workday(?int $ts = null): string
{
    $ts = $ts ?? time();
    return (int) date('G', $ts) < ATT_DAY_STARTS_AT ? date('Y-m-d', $ts - 86400) : date('Y-m-d', $ts);
}

/**
 * The row still waiting for a punch out, if any.
 *
 * A shift that began before 6 AM yesterday's side of the boundary and runs past it
 * is still the same shift, so when today has no open punch the previous work day is
 * tried as well - otherwise a night shift ending at 06:03 would be split over two
 * days and both would show no hours. A punch left open longer than one shift
 * (ATT_SHIFT_MAX_H) is treated as forgotten and not reopened.
 */
function att_open_punch(int $userId, ?string $date = null, bool $carryOver = true): ?array
{
    $date = $date ?? att_workday();
    $row = q('SELECT * FROM attendance WHERE user_id = ? AND date = ? AND punch_in IS NOT NULL AND punch_out IS NULL
              ORDER BY id DESC LIMIT 1', [$userId, $date])->fetch() ?: null;
    if ($row || !$carryOver) return $row;

    $prev = date('Y-m-d', strtotime("$date -1 day"));
    $row = q('SELECT * FROM attendance WHERE user_id = ? AND date = ? AND punch_in IS NOT NULL AND punch_out IS NULL
              ORDER BY id DESC LIMIT 1', [$userId, $prev])->fetch() ?: null;
    if (!$row) return null;

    return time() - strtotime("$prev {$row['punch_in']}") <= ATT_SHIFT_MAX_H * 3600 ? $row : null;
}

function att_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
    return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * The circle a GPS-restricted person must punch inside: their own location when it
 * has latitude, longitude and a distance set (HRMS Settings -> Locations), otherwise
 * the office geofence. Null when neither is configured.
 *
 * @return array{office_name:string,latitude:float,longitude:float,radius_meters:int}|null
 */
function att_fence_for(array $u): ?array
{
    if (!empty($u['location'])) {
        $l = q('SELECT name, latitude, longitude, radius_meters FROM locations WHERE name = ?', [$u['location']])->fetch();
        if ($l && $l['latitude'] !== null && $l['radius_meters']) {
            return ['office_name' => $l['name'], 'latitude' => (float) $l['latitude'],
                    'longitude' => (float) $l['longitude'], 'radius_meters' => (int) $l['radius_meters']];
        }
    }
    $o = q('SELECT * FROM office_settings ORDER BY id LIMIT 1')->fetch();
    return $o && $o['latitude'] !== null ? $o : null;
}

/** Null when the user may punch from here, otherwise the reason they may not. */
function att_geofence_error(array $u, ?float $lat, ?float $lng): ?string
{
    if (empty($u['geo_restricted'])) return null;
    if ($lat === null || $lng === null) {
        return 'Location access is required to mark attendance. Please allow GPS and try again.';
    }
    $o = att_fence_for($u);
    if (!$o) return null;

    $radius = max(50, (int) $o['radius_meters']);
    $d = att_distance_m($lat, $lng, (float) $o['latitude'], (float) $o['longitude']);
    if ($d <= $radius) return null;
    return 'You are ' . round($d) . ' m away from ' . ($o['office_name'] ?: 'the office')
         . '. Attendance is only allowed within ' . $radius . ' m.';
}

/**
 * A readable address for a GPS fix, or the coordinates when the lookup fails.
 * Never block attendance on a slow external geocoder.
 */
function att_place_name(?float $lat, ?float $lng): string
{
    if ($lat === null || $lng === null) return 'Office';
    $fallback = sprintf('Lat: %.5f, Lng: %.5f', $lat, $lng);
    $ctx = stream_context_create([
        'http' => ['timeout' => 0.5, 'ignore_errors' => true, 'user_agent' => APP_NAME . '/1.0', 'header' => 'Accept-Language: en'],
        'https' => ['timeout' => 0.5, 'ignore_errors' => true, 'user_agent' => APP_NAME . '/1.0', 'header' => 'Accept-Language: en'],
    ]);
    $json = @file_get_contents('https://nominatim.openstreetmap.org/reverse?format=json&zoom=18&addressdetails=1&lat='
                               . urlencode((string) $lat) . '&lon=' . urlencode((string) $lng), false, $ctx);
    $a = $json ? (json_decode($json, true)['address'] ?? null) : null;
    if (!$a) return $fallback;
    // Most specific first: building/number, street, locality, town, postcode.
    $parts = array_values(array_unique(array_filter([
        trim(($a['house_number'] ?? '') . ' ' . ($a['building'] ?? $a['amenity'] ?? $a['shop'] ?? $a['office'] ?? '')),
        $a['road'] ?? $a['pedestrian'] ?? '',
        $a['neighbourhood'] ?? $a['quarter'] ?? '',
        $a['suburb'] ?? $a['village'] ?? '',
        $a['city'] ?? $a['town'] ?? $a['county'] ?? '',
        $a['postcode'] ?? '',
    ])));
    return $parts ? mb_substr(implode(', ', $parts), 0, 250) : $fallback;
}

/** Stores a base64 selfie from the camera; returns its file name, or null if it is not an image. */
function att_save_selfie(string $dataUrl, string $prefix, int $userId): ?string
{
    $bin = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', str_replace(' ', '+', $dataUrl)), true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 5 * 1024 * 1024) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) return null;

    $dir = ATT_DIR . '/selfies/' . date('Y/m/d');
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $prefix . $userId . '_' . date('YmdHis') . '.jpg';
    return file_put_contents("$dir/$name", $bin) ? $name : null;
}

/** Where a selfie lives on disk. The date folder is read back out of its name. */
function att_selfie_path(string $name): ?string
{
    $name = basename($name);
    if (!preg_match('/_(\d{4})(\d{2})(\d{2})\d{6}\.\w+$/', $name, $m)) return null;
    $p = ATT_DIR . "/selfies/$m[1]/$m[2]/$m[3]/$name";
    return is_file($p) ? $p : null;
}

/**
 * Punch in or out for one person. $by is how it was recorded: 'self' (web or app,
 * with a selfie) or 'face' (the kiosk). Returns [ok, message, type].
 *
 * @param array{lat?:?float,lng?:?float,accuracy?:?float,selfie?:string} $opt
 */
function att_punch(array $u, string $type, string $by = 'self', array $opt = []): array
{
    if (in_array($u['role'] ?? '', ATT_NO_PUNCH_ROLES, true)) {
        return [false, 'Super Admin and Admin accounts do not record attendance.', $type];
    }
    if (($u['status'] ?? 'Working') === 'Resign' || empty($u['is_active'])) {
        return [false, 'This account is marked as resigned and cannot record attendance.', $type];
    }
    $lat = isset($opt['lat']) ? (float) $opt['lat'] : null;
    $lng = isset($opt['lng']) ? (float) $opt['lng'] : null;
    $acc = isset($opt['accuracy']) ? (float) $opt['accuracy'] : null;

    // A self punch (web page) always carries a selfie and a GPS fix; geo-restricted staff must also be in range.
    if ($by === 'self' && ($lat === null || $lng === null)) {
        return [false, 'Your location could not be captured. Allow location access and try again.', $type];
    }
    if ($by === 'self' && ($acc === null || $acc <= 0 || $acc > ATT_MAX_ACCURACY_M)) {
        return [false, 'Your location is not precise enough' . ($acc ? ' (±' . round($acc) . ' m)' : '') . '. Turn on GPS and try again (need ±' . ATT_MAX_ACCURACY_M . ' m or better).', $type];
    }
    if ($by === 'self' && ($err = att_geofence_error($u, $lat, $lng))) return [false, $err, $type];

    $uid  = (int) $u['id'];
    $date = att_workday();
    $time = date('H:i:s');
    $open = att_open_punch($uid, $date);

    if ($type === 'in' && $open) {
        return [false, 'You are already punched in since ' . substr($open['punch_in'], 0, 5) . '. Please punch out first.', $type];
    }
    if ($type === 'out' && !$open) {
        return [false, 'No open punch in found for today. Please punch in first.', $type];
    }
    if ($type === 'out' && $open['date'] !== $date) {
        $date = $open['date'];   // a night shift that ran past 6 AM belongs to the day it started
    }

    $selfie = null;
    if ($by === 'self') {
        if (empty($opt['selfie'])) return [false, 'Please capture a selfie first.', $type];
        $selfie = att_save_selfie($opt['selfie'], $type === 'in' ? 'selfie_' : 'selfie_punchout_', $uid);
        if (!$selfie) return [false, 'The selfie could not be saved. Please retake it.', $type];
    }
    $place = $by === 'face' ? 'Office' : att_place_name($lat, $lng);

    if ($type === 'in') {
        q('INSERT INTO attendance (user_id, date, punch_in, status, selfie_punchin, punch_in_location,
                                   punch_in_lat, punch_in_lng, punch_in_accuracy)
           VALUES (?,?,?,"Present",?,?,?,?,?)', [$uid, $date, $time, $selfie, $place, $lat, $lng, $acc]);
        return [true, 'Punched in at ' . date('h:i A') . '.', 'in'];
    }

    q('UPDATE attendance SET punch_out = ?, selfie_punchout = ?, punch_out_location = ?,
                             punch_out_lat = ?, punch_out_lng = ?, punch_out_accuracy = ?
       WHERE id = ?', [$time, $selfie, $place, $lat, $lng, $acc, $open['id']]);
    // A carried-over shift closes on the day it started, not on today.
    att_save_route($uid, (int) $open['id'], $open['date']);
    return [true, 'Punched out at ' . date('h:i A') . '.', 'out'];
}

/** The kiosk alternates: whatever the person's last state was, the next sighting flips it. */
function att_face_punch(array $u): array
{
    return att_punch($u, att_open_punch((int) $u['id']) ? 'out' : 'in', 'face');
}

/** Field staff with tracking on leave GPS points behind; a punch out totals the trip. */
function att_save_route(int $uid, int $punchId, string $date): void
{
    $pts = q('SELECT latitude, longitude FROM location_tracking WHERE user_id = ? AND punch_in_id = ?
              ORDER BY `timestamp`', [$uid, $punchId])->fetchAll();
    if (!$pts) return;
    $m = 0.0;
    for ($i = 1, $n = count($pts); $i < $n; $i++) {
        $m += att_distance_m((float) $pts[$i - 1]['latitude'], (float) $pts[$i - 1]['longitude'],
                             (float) $pts[$i]['latitude'], (float) $pts[$i]['longitude']);
    }
    q('INSERT INTO route_summary (user_id, punch_in_id, total_distance_km, route_data, date) VALUES (?,?,?,?,?)',
      [$uid, $punchId, round($m / 1000, 2), json_encode($pts), $date]);
}

/** Worked time between an in and an out, allowing for an out after midnight. */
function att_seconds(?string $in, ?string $out): int
{
    if (!$in || !$out) return 0;
    $s = strtotime("1970-01-01 $out") - strtotime("1970-01-01 $in");
    return $s < 0 ? $s + 86400 : $s;
}

function att_hm(int $seconds): string
{
    return $seconds ? sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)) : '—';
}

function att_time(?string $t): string { return $t ? date('h:i A', strtotime("1970-01-01 $t")) : '—'; }

/** Everyone who records attendance: all staff except the kiosk account. */
/** Roles that never record attendance: the Super Admin, Admins and the kiosk account. */
const ATT_NO_PUNCH_ROLES = ['superadmin', 'admin', 'hod', 'face_operator'];

/** Staff who punch in and out and appear in attendance. */
function can_punch(): bool { return is_logged_in() && !in_array(role(), ATT_NO_PUNCH_ROLES, true); }

/**
 * HRMS rights, as the old admin screens granted them per person (users.rights, a JSON list).
 * The Super Admin holds every right.
 */
const ATT_RIGHTS = [
    'manage_employees'   => 'Manage employees',
    'manage_departments' => 'Manage departments',
    'view_attendance'    => 'View attendance',
    'manual_attendance'  => 'Manual attendance',
    'comp_off'           => 'Comp-off',
    'od_management'      => 'On duty (OD)',
    'export_reports'     => 'Excel reports',
    'manage_companies'   => 'Manage companies',
    'manage_shifts'      => 'Manage shifts',
    'manage_locations'   => 'Manage locations',
    'gps_restriction'    => 'GPS restriction',
    'manage_passwords'   => 'Manage passwords',
    'leave_approval'     => 'Leave approval & holidays',
];

/** The leave types the mobile API offers (api/v1/routes/leave.php keeps its own copy). */
const ATT_LEAVE_TYPES = ['Casual Leave', 'Sick Leave', 'Earned Leave', 'Maternity Leave', 'Paternity Leave', 'Unpaid Leave'];

function att_can(string $right): bool
{
    if (is_super()) return true;
    $r = json_decode((string) (user()['rights'] ?? ''), true);
    return is_array($r) && in_array($right, $r, true);
}

/** Sees the HRMS admin menu at all. */
function can_manage_attendance(): bool
{
    if (is_super()) return true;
    $r = json_decode((string) (user()['rights'] ?? ''), true);
    return is_array($r) && $r !== [];
}

function require_att(string $right): void
{
    require_login();
    if (!att_can($right)) { http_response_code(403); die('403 — you do not have the "' . (ATT_RIGHTS[$right] ?? $right) . '" right.'); }
}

/** Opens the kiosk: its own login, and whoever may manage employees. */
function can_run_kiosk(): bool { return role() === 'face_operator' || att_can('manage_employees'); }

/** Master lists that feed the employee form and the report filters. */
function att_companies(): array { return q('SELECT name FROM companies ORDER BY name')->fetchAll(PDO::FETCH_COLUMN); }
function att_locations(): array { return q('SELECT name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN); }
function att_shifts(): array
{
    return array_map(fn($s) => date('h:i A', strtotime($s['start_time'])) . ' - ' . date('h:i A', strtotime($s['end_time'])),
        q('SELECT start_time, end_time FROM shifts ORDER BY start_time')->fetchAll());
}

/**
 * Day-by-day attendance for a set of people over a date range, as the old monthly
 * export read it. For each user and date: code (P, A, WO, OD, ADJ, or '-' after
 * they left), the first in, the last out, the worked time, and the comp-off note.
 *
 *   WO  - their weekly off
 *   OD  - on duty, marked by an admin
 *   P   - at least one punch that day
 *   ADJ - absent, but a comp-off was taken for the day
 *   L   - approved leave (from leave_applications)
 *   H   - a holiday on the calendar
 *   A   - absent
 */
function att_grid(array $users, string $from, string $to): array
{
    if (!$users) return [];
    $ids = array_map(fn($u) => (int) $u['id'], $users);
    $in  = implode(',', $ids);

    $punch = [];
    foreach (q("SELECT user_id, date, punch_in, punch_out FROM attendance
                WHERE user_id IN ($in) AND date BETWEEN ? AND ? ORDER BY id", [$from, $to]) as $r) {
        $punch[$r['user_id']][$r['date']][] = $r;
    }
    $od = [];
    foreach (q("SELECT user_id, od_date FROM od_records WHERE user_id IN ($in) AND od_date BETWEEN ? AND ?", [$from, $to]) as $r) {
        $od[$r['user_id']][$r['od_date']] = true;
    }
    $co = [];
    foreach (q("SELECT user_id, comp_off_date, earned_date FROM comp_off_requests
                WHERE user_id IN ($in) AND comp_off_date BETWEEN ? AND ?", [$from, $to]) as $r) {
        $co[$r['user_id']][$r['comp_off_date']] = $r['earned_date'];
    }

    $leave = [];
    foreach (q("SELECT user_id, start_date, end_date FROM leave_applications
                WHERE status = 'Approved' AND user_id IN ($in) AND start_date <= ? AND end_date >= ?", [$to, $from]) as $r) {
        for ($d = max($r['start_date'], $from); $d <= min($r['end_date'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) $leave[$r['user_id']][$d] = true;
    }
    $holiday = array_flip(q('SELECT holiday_date FROM project_holidays WHERE holiday_date BETWEEN ? AND ?', [$from, $to])->fetchAll(PDO::FETCH_COLUMN));

    $grid = [];
    foreach ($users as $u) {
        $id = (int) $u['id'];
        $leftAfter = ($u['status'] ?? '') === 'Resign' && !empty($u['date_of_exit'])
            ? date('Y-m-t', strtotime($u['date_of_exit'])) : null;
        // Nobody is absent before their first day, or after a future date has arrived.
        $joined = !empty($u['date_of_joining']) ? date('Y-m-d', strtotime($u['date_of_joining'])) : null;
        $today  = att_workday();
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $blank = ($leftAfter && $d > $leftAfter) || ($joined && $d < $joined) || $d > $today;
            if ($blank) { $grid[$id][$d] = ['code' => '-', 'in' => null, 'out' => null, 'secs' => 0, 'co' => null]; continue; }
            $p = $punch[$id][$d] ?? [];
            // The day is the earliest punch in to the latest punch out, whatever order the
            // rows were written in and whichever row happens to be left open.
            $ins  = array_filter(array_column($p, 'punch_in'));
            $outs = array_filter(array_column($p, 'punch_out'));
            $first = $ins  ? min($ins)  : null;
            $last  = $outs ? max($outs) : null;
            if (($u['week_off'] ?? '') === date('l', strtotime($d))) $code = 'WO';
            elseif (isset($od[$id][$d]))  $code = 'OD';
            elseif ($p)                   $code = 'P';
            elseif (array_key_exists($d, $co[$id] ?? [])) $code = 'ADJ';
            elseif (isset($leave[$id][$d])) $code = 'L';
            elseif (isset($holiday[$d]))    $code = 'H';
            else                          $code = 'A';
            $grid[$id][$d] = [
                'code' => $code, 'in' => $first, 'out' => $last, 'secs' => att_seconds($first, $last),
                'co'   => array_key_exists($d, $co[$id] ?? []) ? ($co[$id][$d] ? 'CO-' . date('d', strtotime($co[$id][$d])) : 'CO') : null,
            ];
        }
    }
    return $grid;
}

/** Shared control styling for the HRMS screens. */
const ATT_FIELD = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400';
const ATT_BTN   = 'rounded-md bg-brand-500 px-3 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600';
const ATT_BTN2  = 'rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] font-medium text-zinc-700 transition hover:bg-zinc-50';

/** A <select> of plain values. */
function att_select(string $name, array $options, ?string $value, string $blank = '', string $extra = ''): string
{
    $o = '<select name="' . e($name) . '" class="' . ATT_FIELD . '" ' . $extra . '>';
    if ($blank !== '') $o .= '<option value="">' . e($blank) . '</option>';
    // A plain list (['Male','Female']) submits its values; a map submits its keys.
    // The keys of an id => name list are integers too, so "is the key an int" is
    // not the question - "is this list numbered 0,1,2..." is.
    $isList = array_keys($options) === range(0, count($options) - 1);
    foreach ($options as $k => $v) {
        $key = $isList ? $v : $k;
        $o .= '<option value="' . e($key) . '"' . ((string) $value === (string) $key ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $o . '</select>';
}

/**
 * A punch's place as HTML: the address, linked to Google Maps when GPS was
 * recorded, with its accuracy. Older HRMS rows have only the address text.
 */
function att_place_html(array $p, string $side = 'in'): string
{
    $name = (string) ($p["punch_{$side}_location"] ?? '');
    $lat  = $p["punch_{$side}_lat"] ?? null;
    $lng  = $p["punch_{$side}_lng"] ?? null;
    $acc  = $p["punch_{$side}_accuracy"] ?? null;
    if ($lat === null || $lng === null) return e($name);
    return '<a target="_blank" rel="noopener" class="hover:text-brand-600 hover:underline" href="https://www.google.com/maps?q='
        . (float) $lat . ',' . (float) $lng . '">' . e($name ?: sprintf('%.5f, %.5f', $lat, $lng)) . '</a>'
        . ($acc !== null ? ' <span class="' . ($acc > 50 ? 'text-amber-600' : 'text-zinc-400') . '">±' . round((float) $acc) . ' m</span>' : '');
}
