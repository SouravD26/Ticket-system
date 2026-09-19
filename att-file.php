<?php
/**
 * Serves attendance selfies and employee photos. Nothing under uploads/hrms is
 * reachable directly: a person sees their own photos, attendance managers see all.
 */
require_once __DIR__ . '/includes/attendance.php';
require_login();

$me = user();
$path = null;

if (($s = get_('selfie')) !== '') {
    $path = att_selfie_path($s);
    if ($path && !can_manage_attendance()) {
        $owner = q('SELECT user_id FROM attendance WHERE selfie_punchin = ? OR selfie_punchout = ? LIMIT 1', [basename($s), basename($s)])->fetch();
        if (!$owner || (int) $owner['user_id'] !== (int) $me['id']) { http_response_code(403); die('403'); }
    }
} elseif (($id = (int) get_('photo')) > 0) {
    if ($id !== (int) $me['id'] && !can_manage_attendance() && role() !== 'face_operator') { http_response_code(403); die('403'); }
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        if (is_file($p = ATT_DIR . "/employee_photos/$id.$ext")) { $path = $p; break; }
    }
}

if (!$path) { http_response_code(404); die('404 — File not found.'); }

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (strpos($mime, 'image/') !== 0) { http_response_code(404); die('404'); }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
