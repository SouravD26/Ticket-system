<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name('TICKETSESS');
    session_start();
}

/* ---------- helpers ---------- */

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function url(string $path = ''): string { return BASE_URL . '/' . ltrim($path, '/'); }

function redirect(string $path): void { header('Location: ' . url($path)); exit; }

function flash(string $msg = null, string $type = 'success')
{
    if ($msg !== null) { $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type]; return null; }
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_token'] ?? '')) {
        http_response_code(419);
        die('Invalid or expired form token. Please go back and try again.');
    }
}

function post(string $k, $default = '') { return trim((string)($_POST[$k] ?? $default)); }
function get_(string $k, $default = '')  { return trim((string)($_GET[$k]  ?? $default)); }

/* ---------- auth ---------- */

function user(): ?array
{
    static $u = null;
    if ($u === null && !empty($_SESSION['uid'])) {
        $u = q('SELECT * FROM users WHERE id = ? AND is_active = 1', [$_SESSION['uid']])->fetch() ?: null;
        if (!$u) { session_destroy(); }
    }
    return $u ?: null;
}

function is_logged_in(): bool { return user() !== null; }
function role(): string       { return user()['role'] ?? 'guest'; }

/**
 * Roles
 *  superadmin - everything: users, departments, tickets, assignment, reports
 *  admin      - read-only reports on every employee (tickets + daily tasks)
 *  it         - tickets assigned to them + daily tasks
 *  employee   - raise tickets, watch their status + daily tasks
 *  hr         - an employee who also runs onboarding and offboarding
 * Only IT, Employees and HR keep a task sheet; Super Admin and Admin read the reports.
 */
function is_super(): bool    { return role() === 'superadmin'; }
function is_admin(): bool    { return role() === 'admin'; }
function is_it(): bool       { return role() === 'it'; }
function is_employee(): bool { return role() === 'employee'; }
function is_hr(): bool       { return role() === 'hr'; }

/** Sees every ticket, including internal notes. */
function is_staff(): bool          { return in_array(role(), ['superadmin', 'admin', 'it'], true); }
/** May change status and post internal notes. */
function can_work_tickets(): bool  { return is_super() || is_it(); }
/** Only the Super Admin grades a ticket — priority is set when it is assigned. */
function can_set_priority(): bool  { return is_super(); }
/** Only the Super Admin assigns, and re-assigns, a ticket to IT staff. */
function can_assign(): bool        { return is_super(); }
/** May open a new ticket. */
function can_raise_tickets(): bool { return is_super() || is_employee() || is_hr(); }
/**
 * May fill in the daily task sheet. The Super Admin does not keep one — he reads
 * everyone else's through reports.php, filtered by department and person.
 */
function can_fill_tasks(): bool    { return is_it() || is_employee() || is_hr(); }

/** Runs the joiner / leaver records. HR only - it is their desk, nobody else's. */
function can_manage_people(): bool { return is_hr(); }

/**
 * Whatever HR files lands on the Super Admin's desk to be actioned, and is
 * only finished once he marks it done. This counts what is still waiting.
 */
function pending_people_count(): int
{
    if (!is_super()) return 0;
    static $n = null;
    if ($n === null) {
        $n = (int) q('SELECT (SELECT COUNT(*) FROM onboarding  WHERE admin_done_at IS NULL)
                           + (SELECT COUNT(*) FROM offboarding WHERE admin_done_at IS NULL) c')->fetch()['c'];
    }
    return $n;
}
/** May read the all-employee reports. */
function can_view_reports(): bool  { return is_super() || is_admin(); }

function require_login(): void
{
    if (!is_logged_in()) { flash('Please sign in to continue.', 'error'); redirect('login.php'); }
}

function require_super(): void
{
    require_login();
    if (!is_super()) { http_response_code(403); die('403 — Super Admin only.'); }
}

function require_people(): void
{
    require_login();
    if (!can_manage_people()) { http_response_code(403); die('403 — HR only.'); }
}

function require_reports(): void
{
    require_login();
    if (!can_view_reports()) { http_response_code(403); die('403 — Admins only.'); }
}

function require_can(callable $check, string $what): void
{
    require_login();
    if (!$check()) { http_response_code(403); die('403 — you do not have access to ' . $what . '.'); }
}

function require_staff(): void
{
    require_login();
    if (!is_staff()) { http_response_code(403); die('403 — Staff only.'); }
}

/* ---------- domain ---------- */

const STATUSES   = ['open' => 'Open', 'pending' => 'In Progress', 'resolved' => 'Completed', 'closed' => 'Closed'];

/** Statuses IT staff may set themselves. Closing is the requester's acknowledgement. */
const IT_STATUSES = ['open', 'pending', 'resolved'];
const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];

function status_badge(string $s): string
{
    $map = [
        'open'     => 'text-zinc-700 border-zinc-200 bg-white [&>span]:bg-brand-500',
        'pending'  => 'text-zinc-700 border-zinc-200 bg-white [&>span]:bg-amber-500',
        'resolved' => 'text-zinc-700 border-zinc-200 bg-white [&>span]:bg-emerald-500',
        'closed'   => 'text-zinc-500 border-zinc-200 bg-zinc-50 [&>span]:bg-zinc-400',
    ];
    $c = $map[$s] ?? $map['closed'];
    return '<span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11px] font-medium ' . $c . '">'
        . '<span class="h-1.5 w-1.5 rounded-full"></span>' . e(STATUSES[$s] ?? $s) . '</span>';
}

function priority_badge(string $p): string
{
    $map = [
        'low'    => 'border-zinc-200 bg-zinc-50 text-zinc-500',
        'medium' => 'border-zinc-200 bg-white text-zinc-600',
        'high'   => 'border-amber-200 bg-amber-50 text-amber-700',
        'urgent' => 'border-rose-200 bg-rose-50 text-rose-700',
    ];
    $c = $map[$p] ?? $map['low'];
    return '<span class="inline-flex items-center rounded-md border px-2 py-0.5 text-[11px] font-medium ' . $c . '">' . e(PRIORITIES[$p] ?? $p) . '</span>';
}

/** True while the requester still has to acknowledge the IT person's work. */
function ticket_awaiting_ack(array $t): bool { return $t['status'] === 'resolved'; }

/** The person who raised the ticket, and so the one who acknowledges it. */
function is_ticket_requester(array $t): bool { return (int)$t['user_id'] === (int)(user()['id'] ?? 0); }

/** The assignee logs the work they did on this ticket; the Super Admin can too. */
function can_log_work(array $t): bool
{
    return is_super() || (is_it() && (int)$t['assigned_to'] === (int)(user()['id'] ?? 0));
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $s = mb_substr($parts[0] ?? '?', 0, 1);
    if (count($parts) > 1) $s .= mb_substr(end($parts), 0, 1);
    return mb_strtoupper($s);
}

function time_ago(string $ts): string
{
    $d = time() - strtotime($ts);
    if ($d < 60)    return 'just now';
    if ($d < 3600)  return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}

function next_ticket_code(): string
{
    $n = (int) q("SELECT COUNT(*) c FROM tickets")->fetch()['c'] + 1;
    do {
        $code = 'TKT-' . date('y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
        $exists = q('SELECT id FROM tickets WHERE code = ?', [$code])->fetch();
        $n++;
    } while ($exists);
    return $code;
}

function log_activity(int $ticketId, string $action, string $detail = ''): void
{
    q('INSERT INTO ticket_activity (ticket_id, user_id, action, detail) VALUES (?,?,?,?)',
        [$ticketId, user()['id'] ?? null, $action, $detail]);
}

const ROLE_LABELS = ['superadmin'=>'Super Admin', 'admin'=>'Admin', 'it'=>'IT', 'employee'=>'Employee', 'hr'=>'HR'];

/* Where a joiner or a leaver has got to. */
const ONBOARD_STATUSES  = ['pending'=>'Pending', 'in_progress'=>'In Progress', 'completed'=>'Completed'];
const OFFBOARD_STATUSES = ['pending'=>'Pending', 'in_progress'=>'In Progress', 'completed'=>'Completed'];

function people_status_badge(string $s): string
{
    $map = [
        'pending'     => 'border-zinc-200 bg-zinc-50 text-zinc-500 [&>span]:bg-zinc-400',
        'in_progress' => 'border-zinc-200 bg-white text-zinc-700 [&>span]:bg-amber-500',
        'completed'   => 'border-zinc-200 bg-white text-zinc-700 [&>span]:bg-emerald-500',
    ];
    $c = $map[$s] ?? $map['pending'];
    return '<span class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-[11px] font-medium ' . $c . '">'
        . '<span class="h-1.5 w-1.5 rounded-full"></span>' . e(ONBOARD_STATUSES[$s] ?? $s) . '</span>';
}
const TASK_STATUSES = ['completed'=>'Completed', 'in_progress'=>'In Progress', 'pending'=>'Pending', 'blocked'=>'Blocked'];

/** Dot colour + row icon tint for each task status. */
const TASK_STATUS_STYLES = [
    'completed'   => ['dot' => 'bg-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
    'in_progress' => ['dot' => 'bg-sky-500',     'chip' => 'bg-sky-50 text-sky-700 ring-sky-600/20'],
    'pending'     => ['dot' => 'bg-amber-500',   'chip' => 'bg-amber-50 text-amber-700 ring-amber-600/20'],
    'blocked'     => ['dot' => 'bg-rose-500',    'chip' => 'bg-rose-50 text-rose-700 ring-rose-600/20'],
];

/** The hour steps offered in the dropdown; anything else goes in via Custom. */
const TASK_HOUR_STEPS = ['0.25', '0.5', '1', '1.5', '2', '3', '4'];

/**
 * SQL fragment limiting a ticket query to what the current user may see.
 * Returns [conditionOrEmptyString, bindArgs].
 */
function ticket_scope(string $a = 't'): array
{
    if (is_super() || is_admin())  return ['', []];
    $id = (int) user()['id'];
    if (is_it()) return ["($a.assigned_to = ? OR $a.user_id = ?)", [$id, $id]];
    return ["$a.user_id = ?", [$id]];
}

/** Every department, for the pickers and the report filter. */
/**
 * The one department list. Tickets, reports, onboarding and offboarding all
 * read it from here, so what the Departments page holds is what every dropdown
 * in the app offers - there is no second list anywhere.
 */
function all_departments(): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();
    }
    return $rows;
}

/** Active IT accounts, in the order they should appear in an assignee picker. */
function it_agents(): array
{
    return q('SELECT id, name, username FROM users WHERE role = "it" AND is_active = 1 ORDER BY name')->fetchAll();
}

/**
 * Hand a ticket to an IT person (or take it back), stamping and logging the move.
 * `assigned_at` is only re-stamped when it actually changes hands, so the original
 * hand-over time survives an edit that leaves the assignee alone.
 */
function assign_ticket(array $ticket, ?int $agentId, ?string $priority = null): void
{
    // Both sides must be nullable ints, or an unassigned ticket compares 0 !== null and
    // every save logs a bogus "Assigned to nobody" move.
    $was     = $ticket['assigned_to'] !== null ? (int) $ticket['assigned_to'] : null;
    $agentId = $agentId ?: null;
    $newPri  = ($priority !== null && isset(PRIORITIES[$priority])) ? $priority : $ticket['priority'];

    if ($agentId === null)        $stamp = 'NULL';
    elseif ($agentId !== $was)    $stamp = 'NOW()';
    else                          $stamp = 'assigned_at';

    q("UPDATE tickets SET assigned_to = ?, priority = ?, assigned_at = $stamp WHERE id = ?",
      [$agentId, $newPri, $ticket['id']]);

    if ($agentId !== $was) {
        $name = $agentId ? (q('SELECT name FROM users WHERE id = ?', [$agentId])->fetch()['name'] ?? '?') : 'nobody';
        log_activity((int) $ticket['id'], 'assigned', ($was ? 'Re-assigned to ' : 'Assigned to ') . $name);
    }
    if ($newPri !== $ticket['priority']) {
        log_activity((int) $ticket['id'], 'priority', 'Priority -> ' . PRIORITIES[$newPri]);
    }
}

/** May this person mark the given ticket complete? The assignee, or the Super Admin. */
function can_resolve_ticket(array $t): bool
{
    if (!can_work_tickets()) return false;
    if (in_array($t['status'], ['resolved', 'closed'], true)) return false;
    if (!$t['assigned_to']) return false;
    return is_super() || (int) $t['assigned_to'] === (int) (user()['id'] ?? 0);
}

/**
 * IT finishes the job: the resolution is posted to the thread so the requester can read
 * what was done, and the ticket parks in "Completed" until they acknowledge it.
 */
function resolve_ticket(array $ticket, string $resolution): void
{
    q('INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,0)',
      [$ticket['id'], user()['id'], $resolution]);
    q('UPDATE tickets SET status = "resolved", completed_at = NOW(), closed_at = NULL WHERE id = ?',
      [$ticket['id']]);
    log_activity((int) $ticket['id'], 'completed', 'Marked complete - waiting for the requester to acknowledge');
}

/**
 * A closed ticket writes itself into the assignee's daily task sheet, so IT never has to
 * type up work the system already knows about. Hours come from whatever they logged on the
 * ticket. Re-closing the same ticket on the same day will not duplicate the entry.
 */
function log_ticket_as_task(array $ticket): bool
{
    $agent = (int) ($ticket['assigned_to'] ?? 0);
    if (!$agent) return false;

    $today = date('Y-m-d');
    $dupe  = q('SELECT id FROM daily_tasks WHERE ticket_id = ? AND user_id = ? AND task_date = ?',
               [$ticket['id'], $agent, $today])->fetch();
    if ($dupe) return false;

    // Only the work from this round of assignment. A ticket that was re-raised and closed
    // again must not count the hours that were already written up the first time.
    $since = $ticket['assigned_at'] ?: '1970-01-01 00:00:00';
    $hours = (float) q('SELECT COALESCE(SUM(hours), 0) h FROM ticket_work_logs
                        WHERE ticket_id = ? AND user_id = ? AND created_at >= ?',
                       [$ticket['id'], $agent, $since])->fetch()['h'];

    $title = mb_substr($ticket['code'] . ' — ' . $ticket['subject'], 0, 200);
    $detail = 'Closed after ' . ($ticket['requester_name'] ?? 'the requester') . ' acknowledged the work.';

    q('INSERT INTO daily_tasks (user_id, task_date, title, description, hours, status, ticket_id)
       VALUES (?,?,?,?,?,"completed",?)',
      [$agent, $today, $title, $detail, max(0, min(24, $hours)), $ticket['id']]);
    return true;
}

/** The requester signs the work off and the ticket closes. */
function acknowledge_ticket(array $ticket): void
{
    q('UPDATE tickets SET status = "closed", acknowledged_at = NOW(), closed_at = NOW() WHERE id = ?',
      [$ticket['id']]);
    log_activity((int) $ticket['id'], 'acknowledged', 'Requester acknowledged the work - ticket closed');

    if (log_ticket_as_task($ticket)) {
        log_activity((int) $ticket['id'], 'task_logged', "Added to the assignee's daily task sheet");
    }
}

/**
 * The requester says it is still broken. The ticket is unassigned on the way back, so it
 * lands in the Super Admin's "Waiting to be assigned" queue to be handed out again -
 * possibly to somebody else.
 */
function reraise_ticket(array $ticket, string $reason = ''): void
{
    q('UPDATE tickets SET status = "open", assigned_to = NULL, assigned_at = NULL,
              completed_at = NULL, acknowledged_at = NULL, closed_at = NULL,
              reopen_count = reopen_count + 1
       WHERE id = ?', [$ticket['id']]);

    if ($reason !== '') {
        q('INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,0)',
          [$ticket['id'], user()['id'], $reason]);
    }
    log_activity((int) $ticket['id'], 'reopened',
        'Requester marked it not resolved' . ($reason !== '' ? ' - ' . mb_substr($reason, 0, 160) : '')
        . ' - returned to the assignment queue');
}

/** A ticket the current user is allowed to see. */
function find_ticket(int $id): ?array
{
    $sql = 'SELECT t.*, u.name AS requester_name, u.email AS requester_email,
                   a.name AS agent_name, d.name AS dept_name
            FROM tickets t
            JOIN users u ON u.id = t.user_id
            LEFT JOIN users a ON a.id = t.assigned_to
            LEFT JOIN departments d ON d.id = t.department_id
            WHERE t.id = ?';
    $t = q($sql, [$id])->fetch();
    if (!$t) return null;
    $me = (int) user()['id'];
    if (is_super() || is_admin()) return $t;
    if (is_it() && ((int)$t['assigned_to'] === $me || (int)$t['user_id'] === $me)) return $t;
    if ((int)$t['user_id'] === $me) return $t;
    return null;
}
