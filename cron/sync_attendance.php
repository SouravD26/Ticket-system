<?php
/**
 * Attendance sync: old attendance app DB  ->  ticket DB.
 *
 * Copies every punch in / punch out made in the old attendance app
 * (sanmatob_attendence.attendance) into this app's attendance table, so the
 * ticket system always shows the same timings.
 *
 * Run it from cPanel cron every minute:
 *     * * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/cron/sync_attendance.php >/dev/null 2>&1
 * or ping it over HTTP (e.g. right after a punch in the old app):
 *     https://helpdesk.sanmarg.in/cron/sync_attendance.php?key=<ATT_SYNC_KEY>
 *
 * How rows are matched: each copied row carries the source id in
 * attendance.src_att_id. The old app allows several punches a day, so
 * (user_id, date) is not unique; and this app makes its own punches too, so
 * the plain id would collide. src_att_id avoids both problems.
 *
 * `php sync_attendance.php --full` (or ?full=1) re-copies every row once (safe to repeat).
 *
 * Only rows changed since the last run are read (by source updated_at), with
 * a two-minute overlap so nothing written mid-run is missed. Re-copying a row
 * is harmless: it is an upsert.
 */
require_once __DIR__ . '/../includes/att_sync.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (ATT_SYNC_KEY === '' || !hash_equals(ATT_SYNC_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Forbidden
");
    }
}

$res = att_sync(in_array('--full', $argv ?? [], true) || isset($_GET['full']),
    function (string $msg) { echo '[' . date('Y-m-d H:i:s') . "] $msg
"; });
exit(empty($res['error']) ? 0 : 1);
