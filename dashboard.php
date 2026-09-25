<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$me = user();
// The kiosk account has one job.
if ($me['role'] === 'face_operator') redirect('face.php');
// The HOD account has its own dashboard: ticket details, then the daily task sheets.
if ($me['role'] === 'hod') redirect('hod-dashboard.php');

/* Hand a ticket to an IT person without opening it first. Super Admin only. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'assign') {
    csrf_check();
    if (!can_assign()) { http_response_code(403); die('403 — only the Super Admin assigns tickets.'); }

    $ticket = find_ticket((int) post('ticket_id'));
    $agent  = (int) post('assigned_to');
    $agentOk = $agent && is_it_staff($agent);

    if (!$ticket) {
        flash('That ticket no longer exists.', 'error');
    } elseif (!$agentOk) {
        flash('Pick an active IT person to assign the ticket to.', 'error');
    } else {
        assign_ticket($ticket, $agent, post('priority') ?: null);
        $name = q('SELECT name FROM users WHERE id = ?', [$agent])->fetch()['name'];
        flash($ticket['code'] . ' assigned to ' . $name . '.');
    }
    redirect('dashboard.php');
}

[$scopeSql, $scopeArgs] = ticket_scope('tickets');
$scope = $scopeSql ? ' WHERE ' . $scopeSql : '';
$counts = q("SELECT
      COUNT(*) total,
      SUM(status='open')     open_c,
      SUM(status='pending')  pending_c,
      SUM(status='resolved') resolved_c,
      SUM(status='closed')   closed_c,
      SUM(priority IN ('high','urgent') AND status<>'closed') hot_c
    FROM tickets{$scope}", $scopeArgs)->fetch();

[$rSql, $rArgs] = ticket_scope('t');
$recentSql = 'SELECT t.*, u.name requester_name, a.name agent_name
              FROM tickets t JOIN users u ON u.id=t.user_id
              LEFT JOIN users a ON a.id=t.assigned_to '
           . ($rSql ? 'WHERE ' . $rSql . ' ' : '')
           . 'ORDER BY t.updated_at DESC LIMIT 8';
$recent = q($recentSql, $rArgs)->fetchAll();

/**
 * The one queue this person is expected to act on next:
 *  Super Admin / Admin - tickets nobody has been given yet
 *  IT                  - what is on their desk right now
 *  Employee            - work marked complete, waiting for them to acknowledge it
 */
if (is_super() || is_admin()) {
    $queueTitle = 'Waiting to be assigned';
    $queueSql   = 'SELECT t.*, u.name requester_name, a.name agent_name
                   FROM tickets t JOIN users u ON u.id = t.user_id
                   LEFT JOIN users a ON a.id = t.assigned_to
                   WHERE t.assigned_to IS NULL AND t.status <> "closed"
                   ORDER BY t.created_at ASC LIMIT 50';
    $queueArgs  = [];
} elseif (is_it()) {
    $queueTitle = 'On your desk';
    $queueSql   = 'SELECT t.*, u.name requester_name, a.name agent_name
                   FROM tickets t JOIN users u ON u.id = t.user_id
                   LEFT JOIN users a ON a.id = t.assigned_to
                   WHERE t.assigned_to = ? AND t.status IN ("open","pending")
                   ORDER BY FIELD(t.priority,"urgent","high","medium","low"), t.created_at ASC LIMIT 8';
    $queueArgs  = [$me['id']];
} else {
    $queueTitle = 'Waiting for your acknowledgement';
    $queueSql   = 'SELECT t.*, u.name requester_name, a.name agent_name
                   FROM tickets t JOIN users u ON u.id = t.user_id
                   LEFT JOIN users a ON a.id = t.assigned_to
                   WHERE t.user_id = ? AND t.status = "resolved"
                   ORDER BY t.completed_at DESC LIMIT 8';
    $queueArgs  = [$me['id']];
}
$queue = q($queueSql, $queueArgs)->fetchAll();

// The Super Admin assigns inline, so the picker needs the IT roster.
$assignHere = can_assign();
$agents     = $assignHere ? it_agents() : [];

$cards = [
  ['Total tickets', (int)$counts['total'],      'bg-brand-500'],
  ['Open',          (int)$counts['open_c'],     'bg-sky-500'],
  [STATUSES['pending'],  (int)$counts['pending_c'],  'bg-amber-500'],
  [STATUSES['resolved'], (int)$counts['resolved_c'], 'bg-emerald-500'],
];

// Daily-task summary for the roles that keep a sheet.
$taskStats = can_fill_tasks()
    ? q('SELECT
             (SELECT COUNT(*) FROM daily_tasks WHERE user_id = ? AND task_date = CURDATE()) today_c,
             (SELECT COUNT(*) FROM daily_tasks WHERE user_id = ? AND task_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) week_c,
             (SELECT COALESCE(SUM(hours),0) FROM daily_tasks WHERE user_id = ? AND task_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) week_h',
           [$me['id'], $me['id'], $me['id']])->fetch()
    : null;

/**
 * HR watches its own filings from here: what is still with the Super Admin,
 * what he has flagged as pending, and what he has signed off.
 */
$people = [];
$peopleCounts = ['waiting' => 0, 'pending' => 0, 'done' => 0];
if (is_hr()) {
    $people = q("(SELECT id, employee_name, join_date AS on_date, status,
                        admin_pending_note, admin_done_at, admin_note, 'onboarding' AS kind
                 FROM onboarding)
                UNION ALL
                (SELECT id, employee_name, last_working_day AS on_date, status,
                        admin_pending_note, admin_done_at, admin_note, 'offboarding' AS kind
                 FROM offboarding)
                ORDER BY admin_done_at IS NOT NULL, on_date DESC
                LIMIT 8")->fetchAll();

    $peopleCounts = q("SELECT
        SUM(admin_done_at IS NULL AND admin_pending_note IS NULL) waiting,
        SUM(admin_done_at IS NULL AND admin_pending_note IS NOT NULL) pending,
        SUM(admin_done_at IS NOT NULL) done
      FROM ((SELECT admin_done_at, admin_pending_note FROM onboarding)
            UNION ALL
            (SELECT admin_done_at, admin_pending_note FROM offboarding)) x")->fetch();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/layout/header.php';
?>
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?php foreach ($cards as [$label, $value, $accent]): ?>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-2">
        <span class="h-1.5 w-1.5 rounded-full <?= $accent ?>"></span>
        <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></p>
      </div>
      <p class="mt-2 text-[26px] font-semibold leading-none tracking-tight text-zinc-900 tabular-nums"><?= $value ?></p>
    </div>
  <?php endforeach; ?>
</div>

<?php // "Waiting to be assigned" is hidden on the Super Admin dashboard; to bring it back, drop the is_super() check.
if ($queue && !is_super()): ?>
  <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="flex items-center gap-2 text-[13px] font-semibold text-zinc-900"><?= e($queueTitle) ?>
        <span class="rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 tabular-nums"><?= count($queue) ?></span>
      </h2>
      <a href="<?= url('tickets.php') ?>" class="text-[13px] font-medium text-zinc-500 hover:text-zinc-900">All tickets &rarr;</a>
    </div>

    <?php if ($assignHere && !$agents): ?>
      <p class="border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-800">
        Nobody is in IT yet, so there is nobody to assign these to. IT staff are the employees in the
        IT department (or with an IT designation).
        <a href="<?= url('hrms-employees.php') ?>" class="font-medium underline">Open Employees &rarr;</a>
      </p>
    <?php endif; ?>

    <ul class="divide-y divide-zinc-100">
      <?php foreach ($queue as $t): ?>
        <li class="flex flex-col gap-2.5 px-4 py-2.5 transition hover:bg-zinc-50 lg:flex-row lg:items-center lg:gap-4">
          <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="min-w-0 flex-1">
            <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($t['subject']) ?></p>
            <p class="truncate text-[11px] text-zinc-500">
              <span class="font-medium text-zinc-400"><?= e($t['code']) ?></span> &middot; <?= e($t['requester_name']) ?> &middot; raised <?= e(time_ago($t['created_at'])) ?>
            </p>
          </a>

          <?php if ($assignHere && $agents): ?>
            <form method="post" class="flex flex-wrap items-center gap-2 lg:shrink-0" data-assign>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="assign">
              <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">

              <select name="priority" title="Priority"
                      class="rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-[12px] text-zinc-700 outline-none focus:border-brand-400">
                <?php foreach (PRIORITIES as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $t['priority'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>

              <!-- type-to-search picker; the hidden input carries the chosen id -->
              <div class="relative" data-picker>
                <input type="text" data-search placeholder="Search IT staff&hellip;" autocomplete="off"
                       role="combobox" aria-expanded="false" aria-autocomplete="list"
                       class="w-44 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-[12px] text-zinc-800 outline-none placeholder:text-zinc-400 focus:border-brand-400">
                <input type="hidden" name="assigned_to" data-value>
                <ul data-list role="listbox"
                    class="fixed z-[100] hidden max-h-56 w-56 overflow-auto overscroll-contain rounded-lg border border-zinc-200 bg-white py-1 shadow-lg">
                  <?php foreach ($agents as $a): ?>
                    <li role="option" data-id="<?= $a['id'] ?>" data-name="<?= e($a['name']) ?>"
                        class="cursor-pointer px-2.5 py-1.5 text-[12px] text-zinc-600 hover:bg-zinc-50">
                      <span class="font-medium text-zinc-900"><?= e($a['name']) ?></span>
                      <span class="text-zinc-400"><?= e($a['username']) ?></span>
                    </li>
                  <?php endforeach; ?>
                  <li data-empty class="hidden px-2.5 py-1.5 text-[12px] text-zinc-500">No IT person matches that name.</li>
                </ul>
              </div>

              <button type="submit" data-send disabled
                      class="rounded-md bg-brand-500 px-3 py-1.5 text-[12px] font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-400 disabled:shadow-none">
                Assign
              </button>
            </form>
          <?php else: ?>
            <div class="flex shrink-0 items-center gap-1.5">
              <?= priority_badge($t['priority']) ?><?= status_badge($t['status']) ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <script>
  /* Type-to-search assignee pickers on the unassigned queue. */
  (function () {
    document.querySelectorAll('[data-picker]').forEach(function (picker) {
      var search  = picker.querySelector('[data-search]');
      var value   = picker.querySelector('[data-value]');
      var list    = picker.querySelector('[data-list]');
      var empty   = list.querySelector('[data-empty]');
      var options = [].slice.call(list.querySelectorAll('[data-id]'));
      var send    = picker.closest('form').querySelector('[data-send]');
      var active  = -1;

      // Lift the panel to <body>: the queue card clips its children, and a dropdown
      // must be free to hang past the bottom edge and over whatever follows it.
      document.body.appendChild(list);

      function place() {
        var r = search.getBoundingClientRect();
        var width = Math.max(r.width, 224);                       // never narrower than the input
        var left  = Math.min(r.left, window.innerWidth - width - 8);
        list.style.width = width + 'px';
        list.style.left  = Math.max(8, left) + 'px';

        // Flip above the input when there is not enough room beneath it.
        var below = window.innerHeight - r.bottom - 8;
        list.style.maxHeight = Math.min(224, Math.max(below, 120)) + 'px';
        if (below < 140 && r.top > below) {
          list.style.maxHeight = Math.min(224, r.top - 8) + 'px';
          list.style.top = '';
          list.style.bottom = (window.innerHeight - r.top + 4) + 'px';
        } else {
          list.style.bottom = '';
          list.style.top = (r.bottom + 4) + 'px';
        }
      }

      function visible() { return options.filter(function (o) { return !o.classList.contains('hidden'); }); }

      function open()  { place(); list.classList.remove('hidden'); search.setAttribute('aria-expanded', 'true'); }
      function close() { list.classList.add('hidden'); search.setAttribute('aria-expanded', 'false'); highlight(-1); }
      function isOpen() { return !list.classList.contains('hidden'); }

      // A fixed panel does not travel with the page, so keep it pinned to its input.
      window.addEventListener('scroll', function () { if (isOpen()) place(); }, true);
      window.addEventListener('resize', function () { if (isOpen()) place(); });

      function highlight(i) {
        var vis = visible();
        vis.forEach(function (o) { o.classList.remove('bg-zinc-100'); });
        active = i;
        if (i >= 0 && vis[i]) { vis[i].classList.add('bg-zinc-100'); vis[i].scrollIntoView({ block: 'nearest' }); }
      }

      function filter() {
        var qy = search.value.trim().toLowerCase();
        options.forEach(function (o) {
          o.classList.toggle('hidden', qy !== '' && o.textContent.toLowerCase().indexOf(qy) === -1);
        });
        empty.classList.toggle('hidden', visible().length > 0);
        highlight(-1);
      }

      function choose(o) {
        value.value = o.dataset.id;
        search.value = o.dataset.name;
        send.disabled = false;
        close();
      }

      function clearChoice() { value.value = ''; send.disabled = true; }

      search.addEventListener('focus', function () { filter(); open(); });
      search.addEventListener('input', function () { clearChoice(); filter(); open(); });

      search.addEventListener('keydown', function (ev) {
        var vis = visible();
        if (ev.key === 'ArrowDown')      { ev.preventDefault(); open(); highlight(Math.min(active + 1, vis.length - 1)); }
        else if (ev.key === 'ArrowUp')   { ev.preventDefault(); highlight(Math.max(active - 1, 0)); }
        else if (ev.key === 'Enter')     {
          if (!list.classList.contains('hidden') && vis.length) { ev.preventDefault(); choose(vis[active >= 0 ? active : 0]); }
        }
        else if (ev.key === 'Escape')    { close(); }
      });

      list.addEventListener('mousedown', function (ev) {   // mousedown: fires before blur
        var o = ev.target.closest('[data-id]');
        if (o) { ev.preventDefault(); choose(o); }
      });

      search.addEventListener('blur', function () {
        setTimeout(function () {
          close();
          // Free text that was never confirmed must not look like a selection.
          if (!value.value) { search.value = ''; }
        }, 120);
      });
    });
  })();
  </script>
<?php endif; ?>

<?php if (is_hr()): ?>
  <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Onboarding &amp; offboarding</h2>
      <div class="flex flex-wrap items-center gap-1.5 text-[11px]">
        <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-0.5 font-medium text-zinc-600">
          <span class="h-1.5 w-1.5 rounded-full bg-zinc-400"></span><?= (int)$peopleCounts['waiting'] ?> with Super Admin
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 font-medium text-amber-800">
          <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span><?= (int)$peopleCounts['pending'] ?> pending
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">
          <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span><?= (int)$peopleCounts['done'] ?> done
        </span>
      </div>
    </div>

    <?php if (!$people): ?>
      <p class="p-8 text-center text-[13px] text-zinc-500">
        Nothing filed yet.
        <a href="<?= url('onboarding.php?add=1') ?>" class="font-medium text-brand-600 hover:text-brand-700">Add a joiner &rarr;</a>
      </p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-100">
        <?php foreach ($people as $r): $isJoin = $r['kind'] === 'onboarding'; ?>
          <li class="flex flex-col gap-1.5 px-4 py-2.5 sm:flex-row sm:items-center sm:gap-3">
            <a href="<?= url($r['kind'] . '.php') ?>" class="min-w-0 flex-1">
              <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($r['employee_name']) ?></p>
              <p class="truncate text-[11px] text-zinc-500">
                <?= $isJoin ? 'Onboarding' : 'Offboarding' ?>
                · <?= $isJoin ? 'joins' : 'last day' ?> <?= date('M j, Y', strtotime($r['on_date'])) ?>
                <?php if (!$r['admin_done_at'] && $r['admin_pending_note']): ?>
                  · <span class="text-amber-700">pending: <?= e($r['admin_pending_note']) ?></span>
                <?php elseif ($r['admin_done_at'] && $r['admin_note']): ?>
                  · <span class="text-emerald-700"><?= e($r['admin_note']) ?></span>
                <?php endif; ?>
              </p>
            </a>
            <div class="shrink-0">
              <?php if ($r['admin_done_at']): ?>
                <span class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
                  <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Done by Super Admin
                </span>
              <?php elseif ($r['admin_pending_note']): ?>
                <span class="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                  <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>Pending
                </span>
              <?php else: ?>
                <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-0.5 text-[11px] font-medium text-zinc-600">
                  <span class="h-1.5 w-1.5 rounded-full bg-zinc-400"></span>With Super Admin
                </span>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="mt-3 grid gap-3 lg:grid-cols-3">
  <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm lg:col-span-1">
    <h2 class="text-[13px] font-semibold text-zinc-900">Status breakdown</h2>
    <?php
    $total = max(1, (int)$counts['total']);
    $bars = [
      ['Open', (int)$counts['open_c'], 'bg-sky-500'],
      [STATUSES['pending'], (int)$counts['pending_c'], 'bg-amber-500'],
      [STATUSES['resolved'], (int)$counts['resolved_c'], 'bg-emerald-500'],
      ['Closed', (int)$counts['closed_c'], 'bg-zinc-400'],
    ];
    foreach ($bars as [$l, $v, $c]): $pct = round($v / $total * 100); ?>
      <div class="mt-3.5">
        <div class="flex justify-between text-[11px] text-zinc-500">
          <span class="font-medium text-zinc-600"><?= $l ?></span>
          <span class="tabular-nums"><?= $v ?> &middot; <?= $pct ?>%</span>
        </div>
        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-100">
          <div class="h-full rounded-full <?= $c ?>" style="width: <?= $pct ?>%"></div>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="mt-5 flex items-center justify-between rounded-md border border-zinc-200 bg-zinc-50 px-3 py-2.5">
      <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">High / urgent open</p>
      <p class="text-lg font-semibold leading-none text-rose-600 tabular-nums"><?= (int)$counts['hot_c'] ?></p>
    </div>
  </div>

  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm lg:col-span-2">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Recent activity</h2>
      <a href="<?= url(is_admin() ? 'reports.php' : 'tickets.php') ?>" class="text-[13px] font-medium text-zinc-500 hover:text-zinc-900"><?= is_admin() ? 'Reports' : 'View all' ?> &rarr;</a>
    </div>
    <?php if (!$recent): ?>
      <div class="p-10 text-center">
        <p class="text-[13px] text-zinc-500"><?= is_it() ? 'Nothing assigned to you yet.' : 'No tickets yet.' ?></p>
        <?php if (can_raise_tickets()): ?>
          <a href="<?= url('ticket-new.php') ?>" class="mt-3 inline-block rounded-md bg-brand-500 px-3 py-1.5 text-[13px] font-medium text-white shadow-sm hover:bg-brand-600">Create the first one</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="divide-y divide-zinc-100">
        <?php foreach ($recent as $t): ?>
          <li>
            <a href="<?= url('ticket-view.php?id=' . $t['id']) ?>" class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-zinc-50">
              <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-zinc-100 text-[11px] font-semibold text-zinc-600"><?= e(initials($t['requester_name'])) ?></div>
              <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($t['subject']) ?></p>
                <p class="truncate text-[11px] text-zinc-500"><span class="font-medium text-zinc-400"><?= e($t['code']) ?></span> &middot; <?= e($t['requester_name']) ?> &middot; <?= e(time_ago($t['updated_at'])) ?></p>
              </div>
              <div class="hidden shrink-0 sm:block"><?= priority_badge($t['priority']) ?></div>
              <div class="shrink-0"><?= status_badge($t['status']) ?></div>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php if ($taskStats): ?>
  <div class="mt-3 rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-[13px] font-semibold text-zinc-900">Your daily task sheet</h2>
        <p class="mt-1 text-[12px] text-zinc-500 tabular-nums">
          <?= (int)$taskStats['today_c'] ?> logged today &middot;
          <?= (int)$taskStats['week_c'] ?> this week &middot;
          <?= hm($taskStats['week_h']) ?> h
        </p>
      </div>
      <a href="<?= url('tasks.php') ?>" class="rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-[13px] font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50">
        <?= (int)$taskStats['today_c'] ? 'Open task sheet' : "Fill today's tasks" ?>
      </a>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
