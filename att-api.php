<?php
/**
 * JSON endpoints for the face kiosk (face.php). Session-authenticated, CSRF-checked
 * through the X-CSRF header. The mobile app has its own API under api/v1.
 */
require_once __DIR__ . '/includes/attendance.php';
header('Content-Type: application/json');

function out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a); exit; }

if (!is_logged_in()) out(['success' => false, 'message' => 'Please sign in again.'], 401);
if (!can_run_kiosk()) out(['success' => false, 'message' => 'Not allowed.'], 403);

$action = get_('action');
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
    out(['success' => false, 'message' => 'Session expired. Reload the page.'], 419);
}

/** A face seen again within this many seconds is ignored, so standing in front of the camera does not flip in/out. */
const KIOSK_REPEAT_SECONDS = 120;

switch ($action) {

    // Everyone the kiosk can recognise: a stored descriptor, or a photo to compute one from.
    case 'people':
        $rows = q("SELECT id, name, employee_id, face_descriptor FROM users
                   WHERE is_active = 1 AND status = 'Working' AND role NOT IN ('superadmin','admin','hod','face_operator')")->fetchAll();
        $people = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r['face_descriptor'], true);
            $hasPhoto = (bool) glob(ATT_DIR . '/employee_photos/' . (int) $r['id'] . '.{jpg,jpeg,png}', GLOB_BRACE);
            if (!(is_array($d) && count($d) === 128) && !$hasPhoto) continue;
            $people[] = [
                'id' => (int) $r['id'], 'name' => $r['name'], 'employee_id' => $r['employee_id'],
                'descriptor' => is_array($d) && count($d) === 128 ? $d : null,
                'photo' => $hasPhoto ? url('att-file.php?photo=' . (int) $r['id']) : null,
            ];
        }
        out(['success' => true, 'people' => $people]);

    // The kiosk computed a descriptor from a photo; keep it so the next load is instant.
    case 'save_descriptor':
        $id = (int) ($_POST['id'] ?? 0);
        $d  = json_decode((string) ($_POST['descriptor'] ?? ''), true);
        if (!$id || !is_array($d) || count($d) !== 128 || array_filter($d, fn($v) => !is_numeric($v))) {
            out(['success' => false, 'message' => 'Bad descriptor.'], 422);
        }
        q('UPDATE users SET face_descriptor = ? WHERE id = ?', [json_encode(array_map('floatval', $d)), $id]);
        out(['success' => true]);

    case 'punch':
        $u = q('SELECT * FROM users WHERE id = ?', [(int) ($_POST['id'] ?? 0)])->fetch();
        if (!$u) out(['success' => false, 'message' => 'Unknown person.'], 404);

        $last = q('SELECT GREATEST(COALESCE(punch_out, punch_in), punch_in) t FROM attendance
                   WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1', [$u['id'], att_workday()])->fetch();
        if ($last && abs(time() - strtotime(date('Y-m-d') . ' ' . $last['t'])) < KIOSK_REPEAT_SECONDS) {
            out(['success' => true, 'repeat' => true, 'name' => $u['name'], 'message' => $u['name'] . ' was just recorded.']);
        }
        [$ok, $msg, $type] = att_face_punch($u);
        out(['success' => $ok, 'type' => $type, 'name' => $u['name'], 'employee_id' => $u['employee_id'],
             'time' => date('h:i:s A'), 'message' => $ok ? $u['name'] . ' — ' . ($type === 'in' ? 'Punch In' : 'Punch Out') : $msg]);

    case 'today':
        $rows = q("SELECT u.name, u.employee_id, a.punch_in, a.punch_out FROM attendance a JOIN users u ON u.id = a.user_id
                   WHERE a.date = ? ORDER BY GREATEST(COALESCE(a.punch_out, a.punch_in), a.punch_in) DESC LIMIT 50",
                  [att_workday()])->fetchAll();
        out(['success' => true, 'records' => array_map(fn($r) => [
            'name' => $r['name'], 'employee_id' => $r['employee_id'],
            'in' => att_time($r['punch_in']), 'out' => $r['punch_out'] ? att_time($r['punch_out']) : null,
        ], $rows)]);
}
out(['success' => false, 'message' => 'Unknown action.'], 404);
