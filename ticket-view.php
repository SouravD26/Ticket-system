<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/upload.php';
require_login();

$id     = (int) get_('id');
$ticket = find_ticket($id);
if (!$ticket) { http_response_code(404); die('404 — Ticket not found (or not yours).'); }

$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'reply' && is_admin()) {   // Admin is a read-only reporting role
        flash('Admins have read-only access to tickets.', 'error');
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    if ($action === 'reply') {
        $msg      = post('message');
        $internal = can_work_tickets() && post('is_internal') === '1' ? 1 : 0;

        if ($msg === '' && empty($_FILES['files']['name'][0])) {
            flash('Write a message or attach a file.', 'error');
        } else {
            q('INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,?)',
              [$ticket['id'], $me['id'], $msg, $internal]);
            $replyId = (int) db()->lastInsertId();

            try { store_uploads($ticket['id'], $replyId, $_FILES['files'] ?? null); }
            catch (Throwable $ex) { flash('Reply posted, attachment failed: ' . $ex->getMessage(), 'error'); }

            // Completed and closed tickets are only reopened through the acknowledgement panel.
            q('UPDATE tickets SET updated_at = NOW() WHERE id = ?', [$ticket['id']]);
            log_activity($ticket['id'], 'replied', $internal ? 'Internal note added' : 'Reply added');
            flash('Reply posted.');
        }
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    if ($action === 'update' && can_work_tickets()) {
        $status = post('status');

        // Priority, assignee and department are the Super Admin's call; IT keeps what it was given.
        $priority = can_set_priority() ? post('priority') : $ticket['priority'];
        $agent    = can_assign()       ? (int) post('assigned_to')   : (int) $ticket['assigned_to'];
        $deptId   = can_assign()       ? (int) post('department_id') : (int) $ticket['department_id'];

        if (!isset(STATUSES[$status]) || !isset(PRIORITIES[$priority])) {
            flash('Invalid status or priority.', 'error');
            redirect('ticket-view.php?id=' . $ticket['id']);
        }
        // Only the requester closes a ticket, by acknowledging the work.
        if (!in_array($status, IT_STATUSES, true) && $status !== $ticket['status']) {
            flash('A ticket is closed by the person who raised it, once they acknowledge the work.', 'error');
            redirect('ticket-view.php?id=' . $ticket['id']);
        }
        // Work cannot be marked complete before somebody is on it.
        if ($status === 'resolved' && !$agent) {
            flash('Assign the ticket to an IT person before marking it complete.', 'error');
            redirect('ticket-view.php?id=' . $ticket['id']);
        }

        $wasAgent = (int) $ticket['assigned_to'];
        q('UPDATE tickets SET status=?, priority=?, assigned_to=?, department_id=?,
                  assigned_at  = IF(? = 0, NULL, IF(? = ?, assigned_at, NOW())),
                  completed_at = IF(? = "resolved", COALESCE(completed_at, NOW()), NULL),
                  closed_at    = NULL
           WHERE id = ?',
          [$status, $priority, $agent ?: null, $deptId ?: null,
           $agent, $agent, $wasAgent,
           $status, $ticket['id']]);

        if ($status !== $ticket['status'])     log_activity($ticket['id'], 'status', 'Status -> ' . STATUSES[$status]);
        if ($priority !== $ticket['priority']) log_activity($ticket['id'], 'priority', 'Priority -> ' . PRIORITIES[$priority]);
        if ($agent !== $wasAgent) {
            $an = $agent ? (q('SELECT name FROM users WHERE id=?', [$agent])->fetch()['name'] ?? '?') : 'nobody';
            log_activity($ticket['id'], 'assigned', ($wasAgent ? 'Re-assigned to ' : 'Assigned to ') . $an);
        }

        $justCompleted = $status === 'resolved' && $ticket['status'] !== 'resolved';
        if ($justCompleted) {
            log_activity($ticket['id'], 'completed', 'Marked complete - waiting for the requester to acknowledge');
        }
        flash($justCompleted
            ? 'Marked complete. ' . $ticket['requester_name'] . ' has been asked to acknowledge it.'
            : 'Ticket updated.');
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    /* The requester signs the work off, or sends it back. */
    if ($action === 'acknowledge' && is_ticket_requester($ticket)) {
        if (!ticket_awaiting_ack($ticket)) {
            flash('There is nothing waiting to be acknowledged on this ticket.', 'error');
        } else {
            acknowledge_ticket($ticket);
            flash('Thanks - the ticket is now closed.');
        }
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    if ($action === 'not_resolved' && is_ticket_requester($ticket)) {
        if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
            flash('This ticket is already open.', 'error');
        } else {
            reraise_ticket($ticket, post('reason'));
            flash('Raised again - it is back with the Super Admin to be assigned.');
        }
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    /* The assignee records the work they did, separately from the conversation. */
    if ($action === 'worklog' && can_log_work($ticket)) {
        $summary = post('summary');
        $date    = post('work_date') ?: date('Y-m-d');
        $hours   = (float) post('hours', '0');

        if (mb_strlen($summary) < 3) {
            flash('Describe the work in at least 3 characters.', 'error');
        } elseif (!strtotime($date) || $date > date('Y-m-d')) {
            flash('Pick a valid work date - future dates are not allowed.', 'error');
        } else {
            q('INSERT INTO ticket_work_logs (ticket_id, user_id, work_date, summary, details, hours) VALUES (?,?,?,?,?,?)',
              [$ticket['id'], $me['id'], $date, $summary, post('details') ?: null, max(0, min(24, $hours))]);
            q('UPDATE tickets SET updated_at = NOW() WHERE id = ?', [$ticket['id']]);
            log_activity($ticket['id'], 'work_logged', 'Work logged: ' . mb_substr($summary, 0, 180));
            flash('Work entry saved.');
        }
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    if ($action === 'worklog_delete' && can_log_work($ticket)) {
        // Authors remove their own entries; the Super Admin may remove any.
        $sql  = 'DELETE FROM ticket_work_logs WHERE id = ? AND ticket_id = ?' . (is_super() ? '' : ' AND user_id = ?');
        $args = [(int) post('id'), $ticket['id']];
        if (!is_super()) $args[] = $me['id'];
        q($sql, $args);
        flash('Work entry removed.');
        redirect('ticket-view.php?id=' . $ticket['id']);
    }

    if ($action === 'delete' && is_super()) {
        foreach (q('SELECT stored_name FROM attachments WHERE ticket_id=?', [$ticket['id']])->fetchAll() as $a) {
            @unlink(UPLOAD_DIR . '/' . $a['stored_name']);
        }
        q('DELETE FROM tickets WHERE id = ?', [$ticket['id']]);
        flash('Ticket ' . $ticket['code'] . ' deleted.');
        redirect('tickets.php');
    }
}

$replySql = 'SELECT r.*, u.name, u.role FROM ticket_replies r JOIN users u ON u.id = r.user_id
             WHERE r.ticket_id = ? ' . (is_staff() ? '' : 'AND r.is_internal = 0 ') . 'ORDER BY r.created_at';
$replies = q($replySql, [$ticket['id']])->fetchAll();

$attachments = q('SELECT * FROM attachments WHERE ticket_id = ? ORDER BY id', [$ticket['id']])->fetchAll();
$byReply = [];
foreach ($attachments as $a) { $byReply[(int)$a['reply_id']][] = $a; }

$activity = q('SELECT a.*, u.name FROM ticket_activity a LEFT JOIN users u ON u.id = a.user_id
               WHERE a.ticket_id = ? ORDER BY a.created_at DESC LIMIT 20', [$ticket['id']])->fetchAll();

// Only the Super Admin hands tickets to IT staff, and re-hands them when someone is away.
$agents      = can_assign() ? q('SELECT id, name FROM users WHERE role = "it" AND is_active = 1 ORDER BY name')->fetchAll() : [];
$departments = can_assign() ? all_departments() : [];

// The work sheet for this ticket — visible to staff, kept apart from the conversation.
$workLogs = is_staff()
    ? q('SELECT w.*, u.name FROM ticket_work_logs w JOIN users u ON u.id = w.user_id
         WHERE w.ticket_id = ? ORDER BY w.work_date DESC, w.id DESC', [$ticket['id']])->fetchAll()
    : [];
$workHours = array_sum(array_map('floatval', array_column($workLogs, 'hours')));

// IT moves a ticket between open / in progress / complete; the requester does the closing.
$statusChoices = can_set_priority() || !can_work_tickets()
    ? STATUSES
    : array_intersect_key(STATUSES, array_flip(IT_STATUSES));
if (!isset($statusChoices[$ticket['status']])) {
    $statusChoices = [$ticket['status'] => STATUSES[$ticket['status']]] + $statusChoices;
}
$awaitingAck = ticket_awaiting_ack($ticket);

$pageTitle = $ticket['code'];
require __DIR__ . '/layout/header.php';

function attachment_list(array $items): string
{
    if (!$items) return '';
    $out = '<div class="mt-3 flex flex-wrap gap-2">';
    foreach ($items as $a) {
        $out .= '<a href="' . url('download.php?id=' . $a['id']) . '" class="inline-flex items-center gap-2 rounded-md border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-[11px] text-zinc-600 hover:bg-zinc-100">'
             . '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21.4 11.6l-9.2 9.2a5 5 0 01-7-7l9.2-9.2a3.3 3.3 0 114.7 4.7l-9.2 9.2a1.7 1.7 0 11-2.4-2.4l8.5-8.5"/></svg>'
             . e($a['original_name']) . '<span class="text-zinc-500">' . human_size((int)$a['size_bytes']) . '</span></a>';
    }
    return $out . '</div>';
}
?>
<div class="grid gap-4 lg:grid-cols-3">
  <!-- Conversation -->
  <div class="space-y-4 lg:col-span-2">
    <div class="rounded-lg border border-zinc-200 bg-white shadow-sm p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-[11px] font-medium text-brand-600"><?= e($ticket['code']) ?></p>
          <h2 class="mt-1 text-xl font-semibold text-zinc-900"><?= e($ticket['subject']) ?></h2>
          <p class="mt-1 text-[11px] text-zinc-500">
            Opened by <?= e($ticket['requester_name']) ?> · <?= date('M j, Y g:i a', strtotime($ticket['created_at'])) ?>
          </p>
        </div>
        <div class="flex gap-2"><?= priority_badge($ticket['priority']) ?><?= status_badge($ticket['status']) ?></div>
      </div>

      <div class="mt-5 whitespace-pre-wrap border-t border-zinc-200 pt-5 text-[13px] leading-relaxed text-zinc-600"><?= e($ticket['body']) ?></div>
      <?= attachment_list($byReply[0] ?? []) ?>
    </div>

    <?php foreach ($replies as $r): ?>
      <div class="rounded-lg border <?= $r['is_internal'] ? 'border-amber-300 bg-amber-50' : 'border-zinc-200 bg-white' ?> p-4">
        <div class="flex items-center gap-3">
          <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full <?= $r['role'] === 'user' ? 'bg-zinc-100' : 'bg-brand-100 text-brand-600' ?> text-[11px] font-semibold"><?= e(initials($r['name'])) ?></div>
          <div class="min-w-0">
            <p class="text-[13px] font-medium text-zinc-900">
              <?= e($r['name']) ?>
              <?php if ($r['role'] !== 'user'): ?><span class="ml-1 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-brand-600 ring-1 ring-inset ring-brand-500/25">Staff</span><?php endif; ?>
              <?php if ($r['is_internal']): ?><span class="ml-1 rounded px-1.5 py-0.5 text-[10px] uppercase tracking-wide text-amber-700 ring-1 ring-inset ring-amber-600/20">Internal</span><?php endif; ?>
            </p>
            <p class="text-[11px] text-zinc-500"><?= date('M j, Y g:i a', strtotime($r['created_at'])) ?></p>
          </div>
        </div>
        <?php if ($r['message'] !== ''): ?>
          <div class="mt-3 whitespace-pre-wrap text-[13px] leading-relaxed text-zinc-600"><?= e($r['message']) ?></div>
        <?php endif; ?>
        <?= attachment_list($byReply[(int)$r['id']] ?? []) ?>
      </div>
    <?php endforeach; ?>

    <?php if (is_ticket_requester($ticket) && $awaitingAck): ?>
      <div class="rounded-lg border border-emerald-300 bg-emerald-50 p-6">
        <h3 class="text-[13px] font-semibold text-emerald-700">
          <?= e($ticket['agent_name'] ?? 'IT') ?> has marked this complete
        </h3>
        <p class="mt-1 text-[13px] text-zinc-500">
          Please confirm the problem is actually sorted. Acknowledging closes the ticket; if it is not fixed,
          raising it again sends it back to the Super Admin, who will hand it to an IT person afresh.
        </p>
        <form method="post" class="mt-5">
          <?= csrf_field() ?>
          <label class="mb-1 block text-[11px] text-zinc-500">If it is not fixed, tell them what is still wrong (optional)</label>
          <input name="reason" placeholder="e.g. the projector still shows no signal"
                 class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <button name="action" value="acknowledge"
                    class="rounded-md bg-emerald-600 px-3 py-2 text-[13px] font-semibold text-white transition hover:bg-emerald-700">
              Acknowledge &amp; close
            </button>
            <button name="action" value="not_resolved"
                    class="rounded-md border border-rose-300 bg-white px-3 py-2 text-[13px] font-semibold text-rose-700 transition hover:bg-rose-50">
              Not resolved — raise it again
            </button>
          </div>
        </form>
      </div>
    <?php elseif (is_ticket_requester($ticket) && $ticket['status'] === 'closed'): ?>
      <div class="rounded-lg border border-zinc-200 bg-white shadow-sm p-6">
        <h3 class="text-[13px] font-semibold text-zinc-900">This ticket is closed</h3>
        <p class="mt-1 text-[13px] text-zinc-500">
          You acknowledged the work<?= $ticket['acknowledged_at'] ? ' on ' . date('M j, Y', strtotime($ticket['acknowledged_at'])) : '' ?>.
          If the problem has come back, raise this same ticket again.
        </p>
        <form method="post" class="mt-4 flex flex-wrap items-center gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="not_resolved">
          <input name="reason" placeholder="What went wrong again? (optional)"
                 class="min-w-[14rem] flex-1 rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
          <button class="rounded-md bg-brand-500 px-5 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-600">Raise again</button>
        </form>
      </div>
    <?php endif; ?>

    <?php if (is_admin()): ?>
      <p class="rounded-lg border border-zinc-200 bg-white shadow-sm p-6 text-center text-[13px] text-zinc-500">Read-only view — Admin accounts report on tickets, they do not answer them.</p>
    <?php elseif ($ticket['status'] === 'closed' && !can_work_tickets() && !is_ticket_requester($ticket)): ?>
      <p class="rounded-lg border border-zinc-200 bg-white shadow-sm p-6 text-center text-[13px] text-zinc-500">This ticket is closed.</p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reply">
        <h3 class="text-[13px] font-semibold text-zinc-900">Add a reply</h3>
        <textarea name="message" rows="5" placeholder="Type your message…"
                  class="mt-3 w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"></textarea>
        <input type="file" name="files[]" multiple
               class="mt-3 w-full rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-3 py-2 text-[13px] text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-[13px] file:text-white">
        <div class="mt-4 flex flex-wrap items-center gap-4">
          <button class="rounded-md bg-brand-500 px-5 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-600">Send reply</button>
          <?php if (can_work_tickets()): ?>
            <label class="inline-flex cursor-pointer items-center gap-2 text-[13px] text-amber-700">
              <input type="checkbox" name="is_internal" value="1" class="h-4 w-4 rounded border-zinc-300 bg-zinc-50 text-amber-500">
              Internal note (hidden from the requester)
            </label>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </div>

  <!-- Sidebar -->
  <div class="space-y-4">
    <div class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
      <h3 class="text-[13px] font-semibold text-zinc-900">Details</h3>
      <dl class="mt-4 space-y-3 text-[13px]">
        <?php
        $details = [
          'Requester'  => $ticket['requester_name'],
          'Email'      => $ticket['requester_email'],
          'Department' => $ticket['dept_name'] ?? '—',
          'Assignee'   => $ticket['agent_name'] ?? 'Unassigned',
          'Assigned'   => $ticket['assigned_at'] ? date('M j, Y', strtotime($ticket['assigned_at'])) : '—',
          'Completed'  => $ticket['completed_at'] ? date('M j, Y', strtotime($ticket['completed_at'])) : '—',
          'Acknowledged' => $ticket['acknowledged_at'] ? date('M j, Y', strtotime($ticket['acknowledged_at'])) : '—',
          'Re-raised'  => (int) $ticket['reopen_count'] . '×',
          'Created'    => date('M j, Y', strtotime($ticket['created_at'])),
          'Updated'    => time_ago($ticket['updated_at']),
        ];
        foreach ($details as $k => $v): ?>
          <div class="flex justify-between gap-3">
            <dt class="text-zinc-500"><?= e($k) ?></dt>
            <dd class="truncate text-right text-zinc-600"><?= e($v) ?></dd>
          </div>
        <?php endforeach; ?>
      </dl>
    </div>

    <?php if (is_staff()): ?>
      <?php if (can_work_tickets()): ?>
      <form method="post" class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update">
        <h3 class="text-[13px] font-semibold text-zinc-900">Manage</h3>
        <div class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-[11px] text-zinc-500">Status</label>
            <select name="status" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
              <?php foreach ($statusChoices as $k => $l): ?>
                <option value="<?= $k ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="mt-1 text-[11px] text-zinc-500">
              <?= $awaitingAck
                    ? 'Waiting for ' . e($ticket['requester_name']) . ' to acknowledge.'
                    : 'Set “Completed” when the work is done — the requester then acknowledges it and the ticket closes.' ?>
            </p>
          </div>

          <div>
            <label class="mb-1 block text-[11px] text-zinc-500">Priority</label>
            <?php if (can_set_priority()): ?>
              <select name="priority" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
                <?php foreach (PRIORITIES as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $ticket['priority'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$ticket['assigned_to']): ?>
                <p class="mt-1 text-[11px] text-zinc-500">Grade it as you assign it.</p>
              <?php endif; ?>
            <?php else: ?>
              <div class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2"><?= priority_badge($ticket['priority']) ?></div>
              <p class="mt-1 text-[11px] text-zinc-500">Only the Super Admin changes priority.</p>
            <?php endif; ?>
          </div>

          <div>
            <label class="mb-1 block text-[11px] text-zinc-500">Assignee<?= can_assign() ? ' (IT staff)' : '' ?></label>
            <?php if (can_assign()): ?>
              <select name="assigned_to" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
                <option value="">Unassigned</option>
                <?php foreach ($agents as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= (int)$ticket['assigned_to'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$agents): ?>
                <p class="mt-1 text-[11px] text-amber-600">No active IT accounts yet — create one under Users.</p>
              <?php elseif ($ticket['assigned_to']): ?>
                <p class="mt-1 text-[11px] text-zinc-500">Pick someone else to re-assign if <?= e($ticket['agent_name']) ?> is unavailable.</p>
              <?php endif; ?>
            <?php else: ?>
              <p class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2 text-[13px] text-zinc-500"><?= e($ticket['agent_name'] ?? 'Unassigned') ?></p>
              <p class="mt-1 text-[11px] text-zinc-500">Only the Super Admin assigns and re-assigns tickets.</p>
            <?php endif; ?>
          </div>

          <?php if (can_assign()): ?>
            <div>
              <label class="mb-1 block text-[11px] text-zinc-500">Department</label>
              <select name="department_id" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
                <option value="">— None —</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= (int)$ticket['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>
          <button class="w-full rounded-md bg-brand-500 py-2.5 text-[13px] font-semibold text-white hover:bg-brand-600">Save changes</button>
        </div>
      </form>
      <?php endif; ?>

      <?php if (can_log_work($ticket)): ?>
        <form method="post" class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="worklog">
          <h3 class="text-[13px] font-semibold text-zinc-900">Log your work</h3>
          <p class="mt-1 text-[11px] text-zinc-500">Kept out of the conversation — this is your own record of what you did.</p>
          <div class="mt-4 space-y-3">
            <input name="summary" required minlength="3" placeholder="What did you do?"
                   class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
            <textarea name="details" rows="3" placeholder="Details (optional)"
                      class="w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400"></textarea>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="mb-1 block text-[11px] text-zinc-500">Date</label>
                <input name="work_date" type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"
                       class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
              </div>
              <div>
                <label class="mb-1 block text-[11px] text-zinc-500">Hours</label>
                <input name="hours" type="number" step="0.25" min="0" max="24" value="1"
                       class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
              </div>
            </div>
            <button class="w-full rounded-md border border-brand-500 py-2.5 text-[13px] font-semibold text-brand-600 transition hover:bg-brand-50">Add work entry</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
        <div class="flex items-baseline justify-between">
          <h3 class="text-[13px] font-semibold text-zinc-900">Work done</h3>
          <span class="text-[11px] text-zinc-500"><?= (float)$workHours ?> hrs</span>
        </div>
        <ul class="mt-3 divide-y divide-zinc-100">
          <?php foreach ($workLogs as $w): ?>
            <li class="flex items-start gap-3 py-3">
              <div class="min-w-0 flex-1">
                <p class="text-[13px] font-medium text-zinc-900"><?= e($w['summary']) ?></p>
                <?php if ($w['details']): ?>
                  <p class="mt-0.5 whitespace-pre-line text-[11px] text-zinc-500"><?= e($w['details']) ?></p>
                <?php endif; ?>
                <p class="mt-1 text-[11px] text-zinc-400">
                  <?= e($w['name']) ?> · <?= date('M j, Y', strtotime($w['work_date'])) ?> · <?= (float)$w['hours'] ?>h
                </p>
              </div>
              <?php if (is_super() || (int)$w['user_id'] === (int)$me['id']): ?>
                <form method="post" onsubmit="return confirm('Remove this work entry?')" class="shrink-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="worklog_delete">
                  <input type="hidden" name="id" value="<?= $w['id'] ?>">
                  <button class="rounded-md p-1 text-zinc-500 hover:text-rose-600" title="Delete">&times;</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <?php if (!$workLogs): ?><li class="py-3 text-[13px] text-zinc-400">No work logged on this ticket yet.</li><?php endif; ?>
        </ul>
      </div>

      <div class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
        <h3 class="text-[13px] font-semibold text-zinc-900">Activity</h3>
        <ol class="mt-4 space-y-3">
          <?php foreach ($activity as $a): ?>
            <li class="flex gap-3 text-[13px]">
              <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-400"></span>
              <div>
                <p class="text-zinc-600"><?= e($a['detail'] ?: $a['action']) ?></p>
                <p class="text-[11px] text-zinc-400"><?= e($a['name'] ?? 'System') ?> · <?= e(time_ago($a['created_at'])) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (!$activity): ?><li class="text-[13px] text-zinc-400">Nothing logged yet.</li><?php endif; ?>
        </ol>
      </div>
    <?php endif; ?>

    <?php if (is_super()): ?>
      <form method="post" onsubmit="return confirm('Delete this ticket and all its replies? This cannot be undone.');"
            class="rounded-lg border border-rose-200 bg-rose-50 p-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <h3 class="text-[13px] font-semibold text-rose-700">Danger zone</h3>
        <p class="mt-1 text-[11px] text-zinc-500">Permanently removes the ticket, replies and attachments.</p>
        <button class="mt-3 w-full rounded-md border border-rose-300 py-2.5 text-[13px] font-medium text-rose-700 hover:bg-rose-100">Delete ticket</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
