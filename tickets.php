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

/* Period. The list opens on the current month; "all" lifts either limit. */
$month = get_('month', (string) date('n'));
$year  = get_('year',  (string) date('Y'));
if ($month !== 'all' && !(ctype_digit($month) && (int)$month >= 1 && (int)$month <= 12)) {
    $month = (string) date('n');
}
if ($year !== 'all' && !(ctype_digit($year) && strlen($year) === 4)) {
    $year = (string) date('Y');
}

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
if ($year !== 'all')  { $where[] = 'YEAR(t.created_at) = ?';  $args[] = (int) $year; }
if ($month !== 'all') { $where[] = 'MONTH(t.created_at) = ?'; $args[] = (int) $month; }

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

$departments = all_departments();

/* Offer every year that actually has a ticket, plus the current one. */
$firstYear = (int) (q('SELECT MIN(YEAR(created_at)) y FROM tickets')->fetch()['y'] ?: date('Y'));
$years     = range((int) date('Y'), min($firstYear, (int) date('Y')));

$months = [
    1 => 'January', 2 => 'February', 3 => 'March',      4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',       8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
];

/* What the current filter is showing, in words, for the list header. */
$periodLabel = $year === 'all' && $month === 'all'
    ? 'All time'
    : ($month === 'all'
        ? $year
        : ($year === 'all' ? $months[(int) $month] . ', all years' : $months[(int) $month] . ' ' . $year));

$qs = function (array $over = []) use ($search, $status, $priority, $dept, $mine, $month, $year) {
    $p = array_filter([
        'q' => $search, 'status' => $status, 'priority' => $priority,
        'dept' => $dept ?: '', 'mine' => $mine ? '1' : '',
    ], fn($v) => $v !== '' && $v !== null);
    $p['month'] = $month;
    $p['year']  = $year;
    return http_build_query(array_merge($p, $over));
};

$pageTitle = 'Tickets';
require __DIR__ . '/layout/header.php';
?>
<form method="get" class="rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
  <div class="grid gap-3 md:grid-cols-12">
    <div class="md:col-span-4">
      <input name="q" value="<?= e($search) ?>" placeholder="Search subject, code or body…"
             class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
    </div>
    <select name="month" title="Month raised" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 md:col-span-2">
      <?php foreach ($months as $k => $l): ?>
        <option value="<?= $k ?>" <?= (int) $month === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
      <option value="all" <?= $month === 'all' ? 'selected' : '' ?>>All months</option>
    </select>
    <select name="year" title="Year raised" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 md:col-span-2">
      <?php foreach ($years as $y): ?>
        <option value="<?= $y ?>" <?= (int) $year === $y ? 'selected' : '' ?>><?= $y ?></option>
      <?php endforeach; ?>
      <option value="all" <?= $year === 'all' ? 'selected' : '' ?>>All years</option>
    </select>
    <select name="status" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 md:col-span-2">
      <option value="">All statuses</option>
      <?php foreach (STATUSES as $k => $l): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="priority" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 md:col-span-2">
      <option value="">All priorities</option>
      <?php foreach (PRIORITIES as $k => $l): ?>
        <option value="<?= $k ?>" <?= $priority === $k ? 'selected' : '' ?>><?= $l ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dept" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 md:col-span-3">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="flex gap-2 md:col-span-3">
      <button class="flex-1 rounded-md bg-brand-500 px-3 py-2 text-[13px] font-medium text-white hover:bg-brand-600">Filter</button>
      <a href="<?= url('tickets.php') ?>" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-500 hover:bg-zinc-50">Reset</a>
    </div>
  </div>
  <?php if (is_staff()): ?>
    <label class="mt-3 inline-flex cursor-pointer items-center gap-2 text-[13px] text-zinc-500">
      <input type="checkbox" name="mine" value="1" <?= $mine ? 'checked' : '' ?> class="h-4 w-4 rounded border-zinc-300 bg-zinc-50 text-brand-600">
      Only tickets assigned to me
    </label>
  <?php endif; ?>
</form>

<div class="mt-4 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
  <div class="flex items-center justify-between border-b border-zinc-200 px-3 py-2">
    <p class="text-[13px] text-zinc-500">
      <span class="font-semibold text-zinc-900"><?= $total ?></span> ticket<?= $total === 1 ? '' : 's' ?>
      · <span class="text-zinc-600"><?= e($periodLabel) ?></span>
    </p>
    <a href="<?= url('ticket-new.php') ?>" class="rounded-md bg-zinc-50 px-3 py-1.5 text-[13px] text-zinc-700 hover:bg-zinc-100">+ New</a>
  </div>

  <?php if (!$tickets): ?>
    <div class="p-12 text-center">
      <p class="text-zinc-500">No tickets match these filters<?= $periodLabel === 'All time' ? '' : ' in ' . e($periodLabel) ?>.</p>
      <?php if ($month !== 'all' || $year !== 'all'): ?>
        <a href="?<?= $qs(['month' => 'all', 'year' => 'all', 'page' => 1]) ?>" class="mt-2 inline-block text-[13px] text-brand-600 hover:text-brand-700">Look across all months →</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[820px] text-left text-[13px]">
        <thead class="border-b border-zinc-200 bg-zinc-50/70 text-[11px] font-medium uppercase tracking-wider text-zinc-400">
          <tr>
            <th class="px-3 py-2 font-medium">Ticket</th>
            <th class="px-3 py-2 font-medium">Requester</th>
            <th class="px-3 py-2 font-medium">Department</th>
            <th class="px-3 py-2 font-medium">Assignee</th>
            <th class="px-3 py-2 font-medium">Priority</th>
            <th class="px-3 py-2 font-medium">Status</th>
            <th class="px-3 py-2 font-medium">Updated</th>
            <th class="px-3 py-2 font-medium text-right">Action</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
          <?php foreach ($tickets as $t): ?>
            <tr class="transition hover:bg-zinc-50">
              <td class="px-3 py-2">
                <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="block max-w-xs">
                  <span class="block truncate font-medium text-zinc-900"><?= e($t['subject']) ?></span>
                  <span class="text-[11px] text-brand-600"><?= e($t['code']) ?></span>
                </a>
              </td>
              <td class="px-3 py-2 text-zinc-600"><?= e($t['requester_name']) ?></td>
              <td class="px-3 py-2 text-zinc-500"><?= e($t['dept_name'] ?? '—') ?></td>
              <td class="px-3 py-2 text-zinc-500"><?= e($t['agent_name'] ?? 'Unassigned') ?></td>
              <td class="px-3 py-2"><?= priority_badge($t['priority']) ?></td>
              <td class="px-3 py-2"><?= status_badge($t['status']) ?></td>
              <td class="whitespace-nowrap px-3 py-2 text-zinc-500"><?= e(time_ago($t['updated_at'])) ?></td>
              <td class="whitespace-nowrap px-3 py-2 text-right">
                <?php if (can_resolve_ticket($t)): ?>
                  <button type="button"
                          data-resolve
                          data-id="<?= $t['id'] ?>"
                          data-code="<?= e($t['code']) ?>"
                          data-subject="<?= e($t['subject']) ?>"
                          data-requester="<?= e($t['requester_name']) ?>"
                          title="Add your resolution and mark it complete"
                          class="inline-flex items-center gap-1.5 rounded-md border border-brand-500 px-2.5 py-1.5 text-[11px] font-semibold text-brand-600 transition hover:bg-brand-50">
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
                      <button class="rounded-md bg-emerald-600 px-2.5 py-1.5 text-[11px] font-semibold text-white transition hover:bg-emerald-700">
                        Acknowledge
                      </button>
                    </form>
                    <button type="button" data-reraise data-id="<?= $t['id'] ?>" data-code="<?= e($t['code']) ?>"
                            class="rounded-md border border-rose-300 px-2.5 py-1.5 text-[11px] font-semibold text-rose-700 transition hover:bg-rose-50">
                      Raise again
                    </button>
                  </div>

                <?php elseif (is_ticket_requester($t) && $t['status'] === 'closed'): ?>
                  <button type="button" data-reraise data-id="<?= $t['id'] ?>" data-code="<?= e($t['code']) ?>"
                          class="rounded-md border border-zinc-200 px-2.5 py-1.5 text-[11px] text-zinc-600 transition hover:bg-zinc-100">
                    Raise again
                  </button>

                <?php elseif ($t['status'] === 'closed'): ?>
                  <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" title="View ticket"
                     class="inline-flex items-center rounded-md bg-zinc-100 px-2.5 py-1.5 text-[11px] font-semibold text-zinc-600 transition hover:bg-zinc-200">Closed</a>

                <?php else: ?>
                  <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="text-[11px] text-brand-600 hover:text-brand-700">Open →</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-between border-t border-zinc-200 px-3 py-2">
        <p class="text-[11px] text-zinc-500">Page <?= $page ?> of <?= $pages ?></p>
        <div class="flex gap-1">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="?<?= $qs(['page' => $i]) ?>"
               class="rounded-md px-3 py-1.5 text-[13px] <?= $i === $page ? 'bg-brand-500 text-white' : 'text-zinc-500 hover:bg-zinc-50' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- Resolution dialog: IT writes what they did, and the ticket goes to Completed. -->
<div id="resolveModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-zinc-900/40 p-4 backdrop-blur-sm">
  <form method="post" class="w-full max-w-lg rounded-lg border border-zinc-200 bg-white p-6 shadow-lg">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resolve">
    <input type="hidden" name="ticket_id" id="rm_id">

    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <h3 class="text-[15px] font-semibold text-zinc-900">Resolve ticket</h3>
        <p class="mt-0.5 truncate text-[13px] text-zinc-500">
          <span id="rm_code" class="font-medium text-brand-600"></span> · <span id="rm_subject"></span>
        </p>
      </div>
      <button type="button" data-close class="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700" aria-label="Close">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="mt-5 space-y-4">
      <div>
        <label for="rm_resolution" class="mb-1 block text-[13px] text-zinc-600">Resolution</label>
        <textarea id="rm_resolution" name="resolution" rows="5" required minlength="5"
                  placeholder="What did you do to fix it? This is shown to the person who raised the ticket."
                  class="w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"></textarea>
      </div>

      <div>
        <label class="mb-1 block text-[13px] text-zinc-600">Update status</label>
        <select disabled class="w-full rounded-md border border-zinc-200 bg-zinc-100 px-3 py-2 text-[13px] text-zinc-700">
          <option><?= e(STATUSES['resolved']) ?></option>
        </select>
        <p class="mt-1.5 text-[11px] text-zinc-500">
          Submitting sets the ticket to <span class="font-medium text-zinc-700"><?= e(STATUSES['resolved']) ?></span>.
          <span id="rm_requester"></span> is then asked to acknowledge it, which closes it.
        </p>
      </div>
    </div>

    <div class="mt-6 flex justify-end gap-3">
      <button type="button" data-close class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-600 hover:bg-zinc-100">Cancel</button>
      <button type="submit" class="rounded-md bg-brand-500 px-5 py-2.5 text-[13px] font-semibold text-white transition hover:bg-brand-600">Submit</button>
    </div>
  </form>
</div>

<!-- Raise again: the requester says it is still broken. -->
<div id="reraiseModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-zinc-900/40 p-4 backdrop-blur-sm">
  <form method="post" class="w-full max-w-lg rounded-lg border border-zinc-200 bg-white p-6 shadow-lg">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="not_resolved">
    <input type="hidden" name="ticket_id" id="rr_id">

    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-[15px] font-semibold text-zinc-900">Raise this ticket again</h3>
        <p class="mt-0.5 text-[13px] text-zinc-500"><span id="rr_code" class="font-medium text-brand-600"></span></p>
      </div>
      <button type="button" data-close class="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700" aria-label="Close">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>

    <div class="mt-5">
      <label for="rr_reason" class="mb-1 block text-[13px] text-zinc-600">What is still wrong? <span class="text-zinc-400">(optional)</span></label>
      <textarea id="rr_reason" name="reason" rows="4" placeholder="e.g. the projector still shows no signal"
                class="w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"></textarea>
      <p class="mt-1.5 text-[11px] text-zinc-500">It goes back to the Super Admin to be assigned to an IT person again.</p>
    </div>

    <div class="mt-6 flex justify-end gap-3">
      <button type="button" data-close class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-600 hover:bg-zinc-100">Cancel</button>
      <button type="submit" class="rounded-md bg-brand-500 px-5 py-2.5 text-[13px] font-semibold text-white transition hover:bg-brand-600">Raise again</button>
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
