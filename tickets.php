<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$me = user();

/* The three lifecycle moves, done without leaving the list. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $t      = find_ticket((int) post('ticket_id'));

    if (!$t) {
        flash('That ticket no longer exists, or is not yours.', 'error');

    } elseif ($action === 'resolve') {
        $resolution = post('resolution');
        if (!can_resolve_ticket($t)) {
            flash('Only the assigned IT person can mark this complete.', 'error');
        } elseif (mb_strlen($resolution) < 5) {
            flash('Write what you did to fix it — at least 5 characters.', 'error');
        } else {
            resolve_ticket($t, $resolution);
            flash($t['code'] . ' marked complete. ' . $t['requester_name'] . ' has been asked to acknowledge it.');
        }

    } elseif ($action === 'acknowledge') {
        if (!is_ticket_requester($t) || !ticket_awaiting_ack($t)) {
            flash('There is nothing waiting for you to acknowledge on that ticket.', 'error');
        } else {
            acknowledge_ticket($t);
            flash($t['code'] . ' acknowledged and closed.');
        }

    } elseif ($action === 'not_resolved') {
        if (!is_ticket_requester($t) || !in_array($t['status'], ['resolved', 'closed'], true)) {
            flash('That ticket cannot be raised again right now.', 'error');
        } else {
            reraise_ticket($t, post('reason'));
            flash($t['code'] . ' raised again — it is back with the Super Admin to be assigned.');
        }
    }
    redirect('tickets.php?' . http_build_query($_GET));
}

$search   = get_('q');
$status   = get_('status');
$priority = get_('priority');
$dept     = (int) get_('dept', '0');
$mine     = get_('mine') === '1';
$page     = max(1, (int) get_('page', '1'));
$perPage  = 12;

$where = [];
$args  = [];

[$scopeSql, $scopeArgs] = ticket_scope('t');
if ($scopeSql !== '') { $where[] = $scopeSql; $args = array_merge($args, $scopeArgs); }
if ($mine && can_work_tickets()) { $where[] = 't.assigned_to = ?'; $args[] = $me['id']; }

if ($search !== '') {
    $where[] = '(t.subject LIKE ? OR t.code LIKE ? OR t.body LIKE ?)';
    array_push($args, "%$search%", "%$search%", "%$search%");
}
if (isset(STATUSES[$status]))     { $where[] = 't.status = ?';   $args[] = $status; }
if (isset(PRIORITIES[$priority])) { $where[] = 't.priority = ?'; $args[] = $priority; }
if ($dept > 0)                    { $where[] = 't.department_id = ?'; $args[] = $dept; }

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total  = (int) q("SELECT COUNT(*) c FROM tickets t $whereSql", $args)->fetch()['c'];
$pages  = max(1, (int) ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$tickets = q("SELECT t.*, u.name requester_name, a.name agent_name, d.name dept_name
              FROM tickets t
              JOIN users u ON u.id = t.user_id
              LEFT JOIN users a ON a.id = t.assigned_to
              LEFT JOIN departments d ON d.id = t.department_id
              $whereSql
              ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.updated_at DESC
              LIMIT $perPage OFFSET $offset", $args)->fetchAll();

$departments = q('SELECT id, name FROM departments ORDER BY name')->fetchAll();

$qs = function (array $over = []) use ($search, $status, $priority, $dept, $mine) {
    $p = array_filter([
        'q' => $search, 'status' => $status, 'priority' => $priority,
        'dept' => $dept ?: '', 'mine' => $mine ? '1' : '',
    ], fn($v) => $v !== '' && $v !== null);
    return http_build_query(array_merge($p, $over));
};

$pageTitle = 'Tickets';
require __DIR__ . '/layout/header.php';
?>
<form method="get" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4">
  <div class="grid gap-3 md:grid-cols-12">
    <div class="md:col-span-4">
      <input name="q" value="<?= e($search) ?>" placeholder="Search subject, code or body…"
             class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
    </div>
    <select name="status" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 md:col-span-2">
      <option value="">All statuses</option>
      <?php foreach (STATUSES as $k => $l): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="priority" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 md:col-span-2">
      <option value="">All priorities</option>
      <?php foreach (PRIORITIES as $k => $l): ?>
        <option value="<?= $k ?>" <?= $priority === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dept" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-teal-500 md:col-span-2">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="flex gap-2 md:col-span-2">
      <button class="flex-1 rounded-xl bg-teal-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-teal-700">Filter</button>
      <a href="<?= url('tickets.php') ?>" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-500 hover:bg-slate-50">Reset</a>
    </div>
  </div>
  <?php if (is_staff()): ?>
    <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-sm text-slate-500">
      <input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 bg-slate-50 text-teal-700">
      Only tickets assigned to me
    </label>
  <?php endif; ?>
</form>

<div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
    <p class="text-sm text-slate-500"><span class="font-semibold text-slate-900"><?= $total ?></span> ticket<?= $total === 1 ? '' : 's' ?></p>
    <a href="<?= url('ticket-new.php') ?>" class="rounded-lg bg-slate-50 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100">+ New</a>
  </div>

  <?php if (!$tickets): ?>
    <p class="p-12 text-center text-slate-500">No tickets match these filters.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[820px] text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-5 py-3 font-medium">Ticket</th>
            <th class="px-5 py-3 font-medium">Requester</th>
            <th class="px-5 py-3 font-medium">Department</th>
            <th class="px-5 py-3 font-medium">Assignee</th>
            <th class="px-5 py-3 font-medium">Priority</th>
            <th class="px-5 py-3 font-medium">Status</th>
            <th class="px-5 py-3 font-medium">Updated</th>
            <th class="px-5 py-3 font-medium text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($tickets as $t): ?>
            <tr class="transition hover:bg-slate-50">
              <td class="px-5 py-3.5">
                <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="block max-w-xs">
                  <span class="block truncate font-medium text-slate-900"><?= e($t['subject']) ?></span>
                  <span class="text-xs text-teal-700"><?= e($t['code']) ?></span>
                </a>
              </td>
              <td class="px-5 py-3.5 text-slate-600"><?= e($t['requester_name']) ?></td>
              <td class="px-5 py-3.5 text-slate-500"><?= e($t['dept_name'] ?? '—') ?></td>
              <td class="px-5 py-3.5 text-slate-500"><?= e($t['agent_name'] ?? 'Unassigned') ?></td>
              <td class="px-5 py-3.5"><?= priority_badge($t['priority']) ?></td>
              <td class="px-5 py-3.5"><?= status_badge($t['status']) ?></td>
              <td class="whitespace-nowrap px-5 py-3.5 text-slate-500"><?= e(time_ago($t['updated_at'])) ?></td>
              <td class="whitespace-nowrap px-5 py-3.5 text-right">
                <?php if (can_resolve_ticket($t)): ?>
                  <button type="button"
                          data-resolve
                          data-id="<?= $t['id'] ?>"
                          data-code="<?= e($t['code']) ?>"
                          data-subject="<?= e($t['subject']) ?>"
                          data-requester="<?= e($t['requester_name']) ?>"
                          title="Add your resolution and mark it complete"
                          class="inline-flex items-center gap-1.5 rounded-lg border border-teal-600 px-2.5 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-50">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.9 3.8a2.1 2.1 0 013 3L7.5 19.2l-4 1 1-4L16.9 3.8z"/>
                    </svg>
                    Resolve
                  </button>

                <?php elseif (is_ticket_requester($t) && ticket_awaiting_ack($t)): ?>
                  <div class="flex justify-end gap-1.5">
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="acknowledge">
                      <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
                      <button class="rounded-lg bg-emerald-600 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">
                        Acknowledge
                      </button>
                    </form>
                    <button type="button" data-reraise data-id="<?= $t['id'] ?>" data-code="<?= e($t['code']) ?>"
                            class="rounded-lg border border-rose-300 px-2.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50">
                      Raise again
                    </button>
                  </div>

                <?php elseif (is_ticket_requester($t) && $t['status'] === 'closed'): ?>
                  <button type="button" data-reraise data-id="<?= $t['id'] ?>" data-code="<?= e($t['code']) ?>"
                          class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100">
                    Raise again
                  </button>

                <?php else: ?>
                  <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="text-xs text-teal-700 hover:text-teal-800">Open →</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
        <p class="text-xs text-slate-500">Page <?= $page ?> of <?= $pages ?></p>
        <div class="flex gap-1">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="?<?= $qs(['page' => $i]) ?>"
               class="rounded-lg px-3 py-1.5 text-sm <?= $i === $page ? 'bg-teal-600 text-white' : 'text-slate-500 hover:bg-slate-50' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Resolution dialog: IT writes what they did, and the ticket goes to Completed. -->
<div id="resolveModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <form method="post" class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resolve">
    <input type="hidden" name="ticket_id" id="rm_id">

    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h3 class="text-base font-semibold text-slate-900">Resolve ticket</h3>
        <p class="mt-0.5 truncate text-sm text-slate-500">
          <span id="rm_code" class="font-medium text-teal-700"></span> · <span id="rm_subject"></span>
        </p>
      </div>
      <button type="button" data-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="mt-5 space-y-4">
      <div>
        <label for="rm_resolution" class="mb-1 block text-sm text-slate-600">Resolution</label>
        <textarea id="rm_resolution" name="resolution" rows="5" required minlength="5"
                  placeholder="What did you do to fix it? This is shown to the person who raised the ticket."
                  class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25"></textarea>
      </div>

      <div>
        <label class="mb-1 block text-sm text-slate-600">Update status</label>
        <select disabled class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm text-slate-700">
          <option><?= e(STATUSES['resolved']) ?></option>
        </select>
        <p class="mt-1.5 text-xs text-slate-500">
          Submitting sets the ticket to <span class="font-medium text-slate-700"><?= e(STATUSES['resolved']) ?></span>.
          <span id="rm_requester"></span> is then asked to acknowledge it, which closes it.
        </p>
      </div>
    </div>

    <div class="mt-6 flex justify-end gap-3">
      <button type="button" data-close class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-100">Cancel</button>
      <button type="submit" class="rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700">Submit</button>
    </div>
  </form>
</div>

<!-- Raise again: the requester says it is still broken. -->
<div id="reraiseModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-900/40 p-4 backdrop-blur-sm">
  <form method="post" class="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="not_resolved">
    <input type="hidden" name="ticket_id" id="rr_id">

    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-base font-semibold text-slate-900">Raise this ticket again</h3>
        <p class="mt-0.5 text-sm text-slate-500"><span id="rr_code" class="font-medium text-teal-700"></span></p>
      </div>
      <button type="button" data-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Close">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="mt-5">
      <label for="rr_reason" class="mb-1 block text-sm text-slate-600">What is still wrong? <span class="text-slate-400">(optional)</span></label>
      <textarea id="rr_reason" name="reason" rows="4" placeholder="e.g. the projector still shows no signal"
                class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25"></textarea>
      <p class="mt-1.5 text-xs text-slate-500">It goes back to the Super Admin to be assigned to an IT person again.</p>
    </div>

    <div class="mt-6 flex justify-end gap-3">
      <button type="button" data-close class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm text-slate-600 hover:bg-slate-100">Cancel</button>
      <button type="submit" class="rounded-xl bg-teal-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-700">Raise again</button>
    </div>
  </form>
</div>

<script>
(function () {
  var open = null;

  function show(modal, focusId) {
    open = modal;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
    var f = document.getElementById(focusId);
    if (f) setTimeout(function () { f.focus(); }, 30);
  }

  function hide() {
    if (!open) return;
    open.classList.add('hidden');
    open.classList.remove('flex');
    document.body.style.overflow = '';
    open = null;
  }

  document.addEventListener('click', function (ev) {
    var r = ev.target.closest('[data-resolve]');
    if (r) {
      document.getElementById('rm_id').value        = r.dataset.id;
      document.getElementById('rm_code').textContent    = r.dataset.code;
      document.getElementById('rm_subject').textContent = r.dataset.subject;
      document.getElementById('rm_requester').textContent = r.dataset.requester;
      document.getElementById('rm_resolution').value = '';
      show(document.getElementById('resolveModal'), 'rm_resolution');
      return;
    }
    var a = ev.target.closest('[data-reraise]');
    if (a) {
      document.getElementById('rr_id').value = a.dataset.id;
      document.getElementById('rr_code').textContent = a.dataset.code;
      document.getElementById('rr_reason').value = '';
      show(document.getElementById('reraiseModal'), 'rr_reason');
      return;
    }
    if (ev.target.closest('[data-close]')) { hide(); return; }
    // a click on the backdrop itself, not on the panel inside it
    if (open && ev.target === open) hide();
  });

  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') hide(); });
})();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
