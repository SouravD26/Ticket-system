<?php
/**
 * All-employee report — read only. Admin's whole world; Super Admin sees it too.
 */
require_once __DIR__ . '/includes/functions.php';
require_reports();

/* The report opens on today; widen the range to look further back. */
$valid_date = fn(string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

$from = get_('from');
$to   = get_('to');
if (!$valid_date($from)) { $from = date('Y-m-d'); }
if (!$valid_date($to))   { $to   = date('Y-m-d'); }
if ($from > $to)         { [$from, $to] = [$to, $from]; }

$who  = (int) get_('user', '0');
$dept = (int) get_('dept', '0');

$departments = all_departments();

// Only the people who actually keep a task sheet appear here.
$deptWhere = $dept > 0 ? ' AND u.department_id = ?' : '';
$deptArgs  = $dept > 0 ? [$dept] : [];

// Picking a person narrows the table itself, not just the detail panel below.
$whoWhere = $who > 0 ? ' AND u.id = ?' : '';
$whoArgs  = $who > 0 ? [$who] : [];

/* Nobody idle in this range belongs in the list - a page of zero rows carrying
   last-entry dates from months ago reads as stale data. One picked person is
   the exception: their zero is the answer to the question that was asked. */
$activeOnly = $who > 0 ? '' : ' HAVING raised + assigned + tasks + hours > 0';

/* The Employee picker lists the whole department, so it never collapses to
   the one person already chosen. */
$roster = q('SELECT u.id, u.name, u.role FROM users u
             WHERE u.role IN ("employee","it")' . $deptWhere . '
             ORDER BY u.name', $deptArgs)->fetchAll();

$rows = q('SELECT u.id, u.name, u.username, u.email, u.role, u.is_active, u.department_id,
                  d.name AS dept_name,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.user_id = u.id AND DATE(t.created_at) BETWEEN ? AND ?)          AS raised,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.user_id = u.id AND t.status IN ("open","pending")
                       AND DATE(t.created_at) BETWEEN ? AND ?)                                AS raised_open,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.assigned_to = u.id AND DATE(t.created_at) BETWEEN ? AND ?)       AS assigned,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.assigned_to = u.id AND t.status IN ("resolved","closed")
                       AND DATE(t.created_at) BETWEEN ? AND ?)                                AS assigned_done,
                  (SELECT COUNT(*) FROM daily_tasks dt
                     WHERE dt.user_id = u.id AND dt.task_date BETWEEN ? AND ?)                AS tasks,
                  (SELECT COALESCE(SUM(dt.hours),0) FROM daily_tasks dt
                     WHERE dt.user_id = u.id AND dt.task_date BETWEEN ? AND ?)                AS hours,
                  (SELECT MAX(dt.task_date) FROM daily_tasks dt
                     WHERE dt.user_id = u.id AND dt.task_date BETWEEN ? AND ?)                AS last_task
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
           WHERE u.role IN ("employee","it")' . $deptWhere . $whoWhere . $activeOnly . '
           ORDER BY d.name IS NULL, d.name, FIELD(u.role,"it","employee"), u.name',
        array_merge(
            [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to, $from, $to],
            $deptArgs,
            $whoArgs
        ))->fetchAll();

$totals = [
    'raised' => array_sum(array_column($rows, 'raised')),
    'tasks'  => array_sum(array_column($rows, 'tasks')),
    'hours'  => array_sum(array_map('floatval', array_column($rows, 'hours'))),
    'people' => count($rows),
];

/* Task entries are only listed for the one person picked from the table, 15 per page. */
const REPORT_PER_PAGE = 15;
$detail     = [];
$person     = null;
$page       = max(1, (int) get_('page', '1'));
$pages      = 1;
$detailTotal = 0;
if ($who) {
    $person = q('SELECT u.*, d.name AS dept_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.id = ?', [$who])->fetch();
    if ($person) {
        $detailTotal = (int) q('SELECT COUNT(*) FROM daily_tasks WHERE user_id = ? AND task_date BETWEEN ? AND ?',
                               [$who, $from, $to])->fetchColumn();
        $pages  = max(1, (int) ceil($detailTotal / REPORT_PER_PAGE));
        $page   = min($page, $pages);
        $detail = q('SELECT * FROM daily_tasks WHERE user_id = ? AND task_date BETWEEN ? AND ?
                     ORDER BY task_date DESC, id DESC
                     LIMIT ' . REPORT_PER_PAGE . ' OFFSET ' . (($page - 1) * REPORT_PER_PAGE),
                    [$who, $from, $to])->fetchAll();
    }
}

// Any filter change starts over at page 1; only the pager passes 'page' explicitly.
$qs = fn(array $over = []) => http_build_query(array_merge(
    ['from' => $from, 'to' => $to, 'user' => $who ?: '', 'dept' => $dept ?: ''], $over));

$presets = [
    'Today'      => [date('Y-m-d'), date('Y-m-d')],
    'Yesterday'  => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'Last 7 days'=> [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'This month' => [date('Y-m-01'), date('Y-m-d')],
    'Last month' => [date('Y-m-01', strtotime('first day of last month')),
                     date('Y-m-t',  strtotime('last day of last month'))],
    'This year'  => [date('Y-01-01'), date('Y-m-d')],
];

/* "Sep 8, 2026" reads better than "Sep 8, 2026 - Sep 8, 2026". */
$rangeLabel = $from === $to
    ? ($from === date('Y-m-d') ? 'Today · ' . date('M j, Y', strtotime($from)) : date('M j, Y', strtotime($from)))
    : date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));

$pageTitle = 'Reports';
require __DIR__ . '/layout/header.php';
?>
<form method="get" class="flex flex-wrap items-end gap-3 rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
  <div>
    <label class="mb-1 block text-[11px] text-zinc-500">From</label>
    <input name="from" type="date" value="<?= e($from) ?>" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
  </div>
  <div>
    <label class="mb-1 block text-[11px] text-zinc-500">To</label>
    <input name="to" type="date" value="<?= e($to) ?>" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
  </div>
  <div>
    <label class="mb-1 block text-[11px] text-zinc-500">Department</label>
    <select name="dept" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-[11px] text-zinc-500">Employee</label>
    <select name="user" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400">
      <option value="">Everyone</option>
      <?php foreach ($roster as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $who === (int)$r['id'] ? 'selected' : '' ?>>
          <?= e($r['name']) ?> — <?= e(ROLE_LABELS[$r['role']] ?? $r['role']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white hover:bg-brand-600">Apply</button>
  <a href="<?= url('reports.php') ?>" class="rounded-md bg-zinc-100 px-4 py-2 text-[13px] text-zinc-900 hover:bg-zinc-200">Reset</a>

  <!-- Jumps for the ranges people actually ask for. -->
  <div class="flex w-full flex-wrap items-center gap-1.5 border-t border-zinc-200 pt-3 text-[11px]">
    <span class="mr-1 text-zinc-500">Quick range:</span>
    <?php foreach ($presets as $label => [$pf, $pt]):
      $on = $from === $pf && $to === $pt; ?>
      <a href="<?= url('reports.php?' . $qs(['from' => $pf, 'to' => $pt])) ?>"
         class="rounded-md px-2.5 py-1 <?= $on ? 'bg-brand-500 text-white' : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</form>

<div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
  <?php foreach ([
    ['People tracked', $totals['people'], 'bg-brand-500'],
    ['Tickets raised', $totals['raised'], 'bg-sky-500'],
    ['Task entries',   $totals['tasks'],  'bg-amber-500'],
    ['Time logged (h:mm)', hm($totals['hours']), 'bg-emerald-500'],
  ] as [$label, $value, $accent]): ?>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
      <div class="flex items-center gap-2">
        <span class="h-1.5 w-1.5 rounded-full <?= $accent ?>"></span>
        <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= e($label) ?></p>
      </div>
      <p class="mt-2 text-[26px] font-semibold leading-none tracking-tight text-zinc-900 tabular-nums"><?= e($value) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-4 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
  <div class="border-b border-zinc-200 px-3 py-2">
    <h2 class="text-[13px] font-semibold text-zinc-900">Employee &amp; IT activity<?= $dept ? ' — ' . e($departments[array_search($dept, array_column($departments, 'id'))]['name'] ?? '') : '' ?></h2>
    <p class="mt-0.5 text-[11px] text-zinc-500">
      <?= e($rangeLabel) ?>
      <?= $who ? ' · filtered to one person' : ' · only people active in this range' ?>
    </p>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[46rem] text-[13px]">
      <thead class="border-b border-zinc-200 bg-zinc-50/70 text-left text-[11px] font-medium uppercase tracking-wider text-zinc-400">
        <tr>
          <th class="px-3 py-2">Person</th>
          <th class="px-3 py-2">Department</th>
          <th class="px-3 py-2">Role</th>
          <th class="px-3 py-2">Raised</th>
          <th class="px-3 py-2">Assigned</th>
          <th class="px-3 py-2">Tasks</th>
          <th class="px-3 py-2">Time (h:mm)</th>
          <th class="px-3 py-2">Last entry</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-zinc-100">
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $who === (int)$r['id'] ? 'bg-brand-50' : 'hover:bg-zinc-50' ?>">
            <td class="px-3 py-2">
              <?php $isOpen = $who === (int) $r['id']; // tapping the open person again closes their report ?>
              <a href="<?= url('reports.php?' . $qs(['user' => $isOpen ? '' : $r['id']])) ?>"
                 title="<?= $isOpen ? 'Close report' : 'Open report' ?>" class="flex items-center gap-3">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-zinc-100 text-[11px] font-semibold text-zinc-900"><?= e(initials($r['name'])) ?></span>
                <span class="min-w-0">
                  <span class="block truncate font-medium text-zinc-900"><?= e($r['name']) ?></span>
                  <span class="block truncate text-[11px] text-zinc-500"><?= e($r['username']) ?> · <?= e($r['email']) ?></span>
                </span>
              </a>
            </td>
            <td class="px-3 py-2 text-zinc-600"><?= e($r['dept_name'] ?? '—') ?></td>
            <td class="px-3 py-2 text-zinc-600"><?= e(ROLE_LABELS[$r['role']] ?? $r['role']) ?><?= $r['is_active'] ? '' : ' <span class="text-rose-600">(off)</span>' ?></td>
            <td class="px-3 py-2 text-zinc-600"><?= (int)$r['raised'] ?> <span class="text-[11px] text-zinc-500">(<?= (int)$r['raised_open'] ?> open)</span></td>
            <td class="px-3 py-2 text-zinc-600"><?= (int)$r['assigned'] ?> <span class="text-[11px] text-zinc-500">(<?= (int)$r['assigned_done'] ?> done)</span></td>
            <td class="px-3 py-2 text-zinc-600"><?= (int)$r['tasks'] ?></td>
            <td class="px-3 py-2 text-zinc-600"><?= hm($r['hours']) ?></td>
            <td class="px-3 py-2 text-zinc-500"><?= $r['last_task'] ? date('M j, Y', strtotime($r['last_task'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-5 py-10 text-center text-zinc-500">
            No activity <?= $from === $to ? 'on ' : 'between ' ?><?= e($rangeLabel) ?>.
            <span class="block text-[11px]">Only people with a ticket or a task entry in this range are listed.</span>
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($person): ?>
  <div class="mt-4 rounded-lg border border-zinc-200 bg-white shadow-sm p-4">
    <div class="flex items-center justify-between">
      <h2 class="text-[13px] font-semibold text-zinc-900">
        Task sheet — <?= e($person['name']) ?>
        <span class="font-normal text-zinc-500">· <?= e($person['dept_name'] ?? 'No department') ?></span>
      </h2>
      <a href="<?= url('reports.php?' . $qs(['user' => ''])) ?>" class="text-[11px] text-zinc-500 hover:text-zinc-900">Close</a>
    </div>
    <ul class="mt-3 divide-y divide-zinc-100">
      <?php foreach ($detail as $t): ?>
        <li class="flex items-start gap-3 py-3">
          <span class="w-24 shrink-0 text-[11px] text-zinc-500"><?= date('M j', strtotime($t['task_date'])) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-[13px] text-zinc-900"><?= e($t['title']) ?></p>
            <?php if ($t['description']): ?>
              <p class="mt-0.5 whitespace-pre-line text-[11px] text-zinc-500"><?= e($t['description']) ?></p>
            <?php endif; ?>
          </div>
          <span class="shrink-0 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] text-zinc-600"><?= e(TASK_STATUSES[$t['status']]) ?></span>
          <span class="shrink-0 text-[11px] text-zinc-500"><?= hm($t['hours']) ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (!$detail): ?>
        <li class="py-8 text-center text-[13px] text-zinc-500">No task entries in this range.</li>
      <?php endif; ?>
    </ul>

    <?php if ($detailTotal): ?>
      <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200 pt-3">
        <p class="text-[11px] text-zinc-500">
          Showing <?= ($page - 1) * REPORT_PER_PAGE + 1 ?>–<?= min($page * REPORT_PER_PAGE, $detailTotal) ?>
          of <?= $detailTotal ?> entr<?= $detailTotal === 1 ? 'y' : 'ies' ?> · Page <?= $page ?> of <?= $pages ?>
        </p>
        <?php if ($pages > 1): ?>
          <div class="flex gap-2">
            <?php if ($page > 1): ?>
              <a href="<?= url('reports.php?' . $qs(['page' => $page - 1])) ?>" class="rounded-md border border-zinc-200 px-3 py-1.5 text-[11px] text-zinc-600 hover:bg-zinc-100">← Previous</a>
            <?php endif; ?>
            <?php if ($page < $pages): ?>
              <a href="<?= url('reports.php?' . $qs(['page' => $page + 1])) ?>" class="rounded-md border border-zinc-200 px-3 py-1.5 text-[11px] text-zinc-600 hover:bg-zinc-100">Next →</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php elseif ($rows): ?>
  <p class="mt-4 text-center text-[12px] text-zinc-500">Click an employee above to open their task report.</p>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>
