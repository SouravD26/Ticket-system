<?php
/**
 * All-employee report — read only. Admin's whole world; Super Admin sees it too.
 */
require_once __DIR__ . '/includes/functions.php';
require_reports();

$from = get_('from') ?: date('Y-m-01');
$to   = get_('to')   ?: date('Y-m-d');
$who  = (int) get_('user', '0');
$dept = (int) get_('dept', '0');

$departments = all_departments();

// Only the people who actually keep a task sheet appear here.
$deptWhere = $dept > 0 ? ' AND u.department_id = ?' : '';
$deptArgs  = $dept > 0 ? [$dept] : [];

$rows = q('SELECT u.id, u.name, u.username, u.email, u.role, u.is_active, u.department_id,
                  d.name AS dept_name,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.user_id = u.id AND DATE(t.created_at) BETWEEN ? AND ?)          AS raised,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.user_id = u.id AND t.status IN ("open","pending"))               AS raised_open,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.assigned_to = u.id)                                              AS assigned,
                  (SELECT COUNT(*) FROM tickets t
                     WHERE t.assigned_to = u.id AND t.status IN ("resolved","closed"))        AS assigned_done,
                  (SELECT COUNT(*) FROM daily_tasks dt
                     WHERE dt.user_id = u.id AND dt.task_date BETWEEN ? AND ?)                AS tasks,
                  (SELECT COALESCE(SUM(dt.hours),0) FROM daily_tasks dt
                     WHERE dt.user_id = u.id AND dt.task_date BETWEEN ? AND ?)                AS hours,
                  (SELECT MAX(dt.task_date) FROM daily_tasks dt WHERE dt.user_id = u.id)      AS last_task
           FROM users u
           LEFT JOIN departments d ON d.id = u.department_id
           WHERE u.role IN ("employee","it")' . $deptWhere . '
           ORDER BY d.name IS NULL, d.name, FIELD(u.role,"it","employee"), u.name',
        array_merge([$from, $to, $from, $to, $from, $to], $deptArgs))->fetchAll();

$totals = [
    'raised' => array_sum(array_column($rows, 'raised')),
    'tasks'  => array_sum(array_column($rows, 'tasks')),
    'hours'  => array_sum(array_map('floatval', array_column($rows, 'hours'))),
    'people' => count($rows),
];

$detail = [];
$person = null;
if ($who) {
    $person = q('SELECT u.*, d.name AS dept_name FROM users u
                 LEFT JOIN departments d ON d.id = u.department_id
                 WHERE u.id = ?', [$who])->fetch();
    if ($person) {
        $detail = q('SELECT * FROM daily_tasks WHERE user_id = ? AND task_date BETWEEN ? AND ?
                     ORDER BY task_date DESC, id DESC', [$who, $from, $to])->fetchAll();
    }
}

/* Without a person picked, show every entry from the selected department instead. */
$allTasks = [];
if (!$who) {
    $allTasks = q('SELECT dt.*, u.name AS user_name, u.role, d.name AS dept_name
                   FROM daily_tasks dt
                   JOIN users u ON u.id = dt.user_id
                   LEFT JOIN departments d ON d.id = u.department_id
                   WHERE dt.task_date BETWEEN ? AND ? AND u.role IN ("employee","it")' . $deptWhere . '
                   ORDER BY dt.task_date DESC, d.name, u.name, dt.id DESC
                   LIMIT 300',
                  array_merge([$from, $to], $deptArgs))->fetchAll();
}

$qs = fn(array $over = []) => http_build_query(array_merge(
    ['from' => $from, 'to' => $to, 'user' => $who ?: '', 'dept' => $dept ?: ''], $over));

$pageTitle = 'Reports';
require __DIR__ . '/layout/header.php';
?>
<form method="get" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white shadow-sm p-4">
  <div>
    <label class="mb-1 block text-xs text-slate-500">From</label>
    <input name="from" type="date" value="<?= e($from) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">To</label>
    <input name="to" type="date" value="<?= e($to) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Department</label>
    <select name="dept" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label class="mb-1 block text-xs text-slate-500">Employee</label>
    <select name="user" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
      <option value="">Everyone</option>
      <?php foreach ($rows as $r): ?>
        <option value="<?= $r['id'] ?>" <?= $who === (int)$r['id'] ? 'selected' : '' ?>>
          <?= e($r['name']) ?> — <?= e(ROLE_LABELS[$r['role']] ?? $r['role']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="rounded-xl bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700">Apply</button>
  <a href="<?= url('reports.php') ?>" class="rounded-xl bg-slate-100 px-4 py-2 text-sm text-slate-900 hover:bg-slate-200">Reset</a>
</form>

<div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?php foreach ([
    ['People tracked', $totals['people'], 'from-indigo-500 to-violet-500'],
    ['Tickets raised', $totals['raised'], 'from-sky-500 to-cyan-500'],
    ['Task entries',   $totals['tasks'],  'from-amber-500 to-orange-500'],
    ['Hours logged',   $totals['hours'],  'from-emerald-500 to-teal-500'],
  ] as [$label, $value, $grad]): ?>
    <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
      <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-gradient-to-br <?= $grad ?> opacity-20 blur-2xl"></div>
      <p class="text-sm text-slate-500"><?= e($label) ?></p>
      <p class="mt-2 text-3xl font-semibold text-slate-900"><?= e($value) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="border-b border-slate-200 px-5 py-4">
    <h2 class="text-sm font-semibold text-slate-900">Employee &amp; IT activity<?= $dept ? ' — ' . e($departments[array_search($dept, array_column($departments, 'id'))]['name'] ?? '') : '' ?></h2>
    <p class="mt-0.5 text-xs text-slate-500"><?= date('M j, Y', strtotime($from)) ?> – <?= date('M j, Y', strtotime($to)) ?></p>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full min-w-[46rem] text-sm">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
        <tr>
          <th class="px-5 py-3">Person</th>
          <th class="px-5 py-3">Department</th>
          <th class="px-5 py-3">Role</th>
          <th class="px-5 py-3">Raised</th>
          <th class="px-5 py-3">Assigned</th>
          <th class="px-5 py-3">Tasks</th>
          <th class="px-5 py-3">Hours</th>
          <th class="px-5 py-3">Last entry</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-200">
        <?php foreach ($rows as $r): ?>
          <tr class="<?= $who === (int)$r['id'] ? 'bg-teal-50' : 'hover:bg-slate-50' ?>">
            <td class="px-5 py-3">
              <a href="<?= url('reports.php?' . $qs(['user' => $r['id']])) ?>" class="flex items-center gap-3">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-900"><?= e(initials($r['name'])) ?></span>
                <span class="min-w-0">
                  <span class="block truncate font-medium text-slate-900"><?= e($r['name']) ?></span>
                  <span class="block truncate text-xs text-slate-500"><?= e($r['username']) ?> · <?= e($r['email']) ?></span>
                </span>
              </a>
            </td>
            <td class="px-5 py-3 text-slate-600"><?= e($r['dept_name'] ?? '—') ?></td>
            <td class="px-5 py-3 text-slate-600"><?= e(ROLE_LABELS[$r['role']] ?? $r['role']) ?><?= $r['is_active'] ? '' : ' <span class="text-rose-600">(off)</span>' ?></td>
            <td class="px-5 py-3 text-slate-600"><?= (int)$r['raised'] ?> <span class="text-xs text-slate-500">(<?= (int)$r['raised_open'] ?> open)</span></td>
            <td class="px-5 py-3 text-slate-600"><?= (int)$r['assigned'] ?> <span class="text-xs text-slate-500">(<?= (int)$r['assigned_done'] ?> done)</span></td>
            <td class="px-5 py-3 text-slate-600"><?= (int)$r['tasks'] ?></td>
            <td class="px-5 py-3 text-slate-600"><?= (float)$r['hours'] ?></td>
            <td class="px-5 py-3 text-slate-500"><?= $r['last_task'] ? date('M j, Y', strtotime($r['last_task'])) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-5 py-10 text-center text-slate-500">Nobody matches this filter.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($person): ?>
  <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
    <div class="flex items-center justify-between">
      <h2 class="text-sm font-semibold text-slate-900">
        Task sheet — <?= e($person['name']) ?>
        <span class="font-normal text-slate-500">· <?= e($person['dept_name'] ?? 'No department') ?></span>
      </h2>
      <a href="<?= url('reports.php?' . $qs(['user' => ''])) ?>" class="text-xs text-slate-500 hover:text-slate-900">Close</a>
    </div>
    <ul class="mt-3 divide-y divide-slate-200">
      <?php foreach ($detail as $t): ?>
        <li class="flex items-start gap-3 py-3">
          <span class="w-24 shrink-0 text-xs text-slate-500"><?= date('M j', strtotime($t['task_date'])) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm text-slate-900"><?= e($t['title']) ?></p>
            <?php if ($t['description']): ?>
              <p class="mt-0.5 whitespace-pre-line text-xs text-slate-500"><?= e($t['description']) ?></p>
            <?php endif; ?>
          </div>
          <span class="shrink-0 rounded-full bg-slate-50 px-2.5 py-1 text-xs text-slate-600"><?= e(TASK_STATUSES[$t['status']]) ?></span>
          <span class="shrink-0 text-xs text-slate-500"><?= (float)$t['hours'] ?>h</span>
        </li>
      <?php endforeach; ?>
      <?php if (!$detail): ?>
        <li class="py-8 text-center text-sm text-slate-500">No task entries in this range.</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if (!$who): ?>
  <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-sm font-semibold text-slate-900">
        All task entries<?= $dept ? ' — ' . e($departments[array_search($dept, array_column($departments, 'id'))]['name'] ?? '') : '' ?>
      </h2>
      <p class="text-xs text-slate-500">
        <?= count($allTasks) ?> entr<?= count($allTasks) === 1 ? 'y' : 'ies' ?>
        <?= count($allTasks) === 300 ? '(first 300)' : '' ?> ·
        <?= date('M j, Y', strtotime($from)) ?> – <?= date('M j, Y', strtotime($to)) ?>
      </p>
    </div>
    <ul class="mt-3 divide-y divide-slate-200">
      <?php foreach ($allTasks as $t): ?>
        <li class="flex flex-wrap items-start gap-3 py-3">
          <span class="w-20 shrink-0 text-xs text-slate-500"><?= date('M j', strtotime($t['task_date'])) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm text-slate-900"><?= e($t['title']) ?></p>
            <?php if ($t['description']): ?>
              <p class="mt-0.5 whitespace-pre-line text-xs text-slate-500"><?= e($t['description']) ?></p>
            <?php endif; ?>
            <p class="mt-1 text-xs text-slate-500">
              <a href="<?= url('reports.php?' . $qs(['user' => $t['user_id']])) ?>" class="font-medium text-teal-700 hover:text-teal-800"><?= e($t['user_name']) ?></a>
              · <?= e($t['dept_name'] ?? 'No department') ?>
              · <?= e(ROLE_LABELS[$t['role']] ?? $t['role']) ?>
            </p>
          </div>
          <span class="shrink-0 rounded-full bg-slate-50 px-2.5 py-1 text-xs text-slate-600"><?= e(TASK_STATUSES[$t['status']]) ?></span>
          <span class="shrink-0 text-xs text-slate-500"><?= (float)$t['hours'] ?>h</span>
        </li>
      <?php endforeach; ?>
      <?php if (!$allTasks): ?>
        <li class="py-8 text-center text-sm text-slate-500">No task entries in this range.</li>
      <?php endif; ?>
    </ul>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>
