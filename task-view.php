<?php
/**
 * Everyone's daily task sheets, read-only. The HOD role's whole job; the Super
 * Admin and Admins can open it too. Filter by dates, department, employee and
 * free text; past entries are all here.
 */
require_once __DIR__ . '/includes/functions.php';
require_can('can_view_all_tasks', 'the daily task sheets');

const TASK_PER_PAGE = 50;

// Filters. Default: this month.
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', get_('from')) ? get_('from') : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', get_('to'))   ? get_('to')   : date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$dept   = (int) get_('dept');
$who    = (int) get_('user');
$search = get_('q');
$status = isset(TASK_STATUSES[get_('status')]) ? get_('status') : '';

$where = ['dt.task_date BETWEEN ? AND ?'];
$args  = [$from, $to];
if ($dept)   { $where[] = 'u.department_id = ?'; $args[] = $dept; }
if ($who)    { $where[] = 'dt.user_id = ?'; $args[] = $who; }
if ($status) { $where[] = 'dt.status = ?'; $args[] = $status; }
if ($search !== '') {
    $where[] = '(dt.title LIKE ? OR dt.description LIKE ? OR dt.category LIKE ? OR u.name LIKE ?)';
    array_push($args, "%$search%", "%$search%", "%$search%", "%$search%");
}
$sqlWhere = implode(' AND ', $where);
$base = "FROM daily_tasks dt JOIN users u ON u.id = dt.user_id LEFT JOIN departments d ON d.id = u.department_id WHERE $sqlWhere";

$sum = q("SELECT COUNT(*) n, COALESCE(SUM(dt.hours), 0) h, COUNT(DISTINCT dt.user_id) people, COUNT(DISTINCT dt.task_date) days $base", $args)->fetch();
$pages = max(1, (int) ceil($sum['n'] / TASK_PER_PAGE));
$page  = min($pages, max(1, (int) get_('page', '1')));
$rows  = q("SELECT dt.*, u.name, u.employee_id, d.name dept_name $base
            ORDER BY dt.task_date DESC, d.name, u.name, dt.id LIMIT " . TASK_PER_PAGE . ' OFFSET ' . (($page - 1) * TASK_PER_PAGE), $args)->fetchAll();

// Staff who keep a task sheet, for the employee picker (narrowed to the chosen department).
$people = q('SELECT u.id, u.name, u.employee_id, u.department_id FROM users u
             WHERE u.role IN ("employee","hr","it") AND u.is_active = 1' . ($dept ? ' AND u.department_id = ' . $dept : '') . '
             ORDER BY u.name')->fetchAll();

$qs = fn(array $o = []) => http_build_query(array_filter(array_merge(
    ['from' => $from, 'to' => $to, 'dept' => $dept ?: '', 'user' => $who ?: '', 'q' => $search, 'status' => $status], $o), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'Employee Daily Tasks';
require __DIR__ . '/layout/header.php';
$field = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
?>
<div class="mx-auto max-w-6xl space-y-4">

  <form class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
      <div><label class="<?= $lbl ?>">From</label><input type="date" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>"></div>
      <div><label class="<?= $lbl ?>">To</label><input type="date" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>"></div>
      <div><label class="<?= $lbl ?>">Department</label>
        <select name="dept" class="<?= $field ?>" onchange="this.form.user.value=''; this.form.submit()">
          <option value="">All departments</option>
          <?php foreach (all_departments() as $d): ?><option value="<?= $d['id'] ?>" <?= $dept === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="<?= $lbl ?>">Employee</label>
        <select name="user" class="<?= $field ?>">
          <option value="">All employees</option>
          <?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>" <?= $who === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name'] . ($p['employee_id'] ? " ({$p['employee_id']})" : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="<?= $lbl ?>">Status</label>
        <select name="status" class="<?= $field ?>"><option value="">Any status</option>
          <?php foreach (TASK_STATUSES as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $lbl ?>">Search</label><input name="q" value="<?= e($search) ?>" placeholder="Task, note or name" class="<?= $field ?>"></div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <button class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:bg-brand-600">Show</button>
      <a href="<?= url('task-view.php') ?>" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] font-medium text-zinc-600 hover:bg-zinc-50">Reset</a>
      <span class="ml-2 text-[12px] text-zinc-400">Quick:</span>
      <?php foreach (['Today' => [date('Y-m-d'), date('Y-m-d')],
                      'Yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
                      'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
                      'This month' => [date('Y-m-01'), date('Y-m-d')],
                      'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))]] as $l => [$a, $b]): ?>
        <a href="?<?= $qs(['from' => $a, 'to' => $b, 'page' => '']) ?>" class="rounded-md border px-2 py-1 text-[12px] <?= $from === $a && $to === $b ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-zinc-200 text-zinc-600 hover:bg-zinc-50' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
  </form>

  <div class="grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
    <?php foreach ([[$sum['n'], 'Tasks'], [hm($sum['h']), 'Time (h:mm)'], [$sum['people'], 'Employees'], [$sum['days'], 'Days']] as [$v, $l]): ?>
      <div class="rounded-lg border border-zinc-200 bg-white p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= $v ?></p><p class="text-[11px] text-zinc-500"><?= $l ?></p></div>
    <?php endforeach; ?>
  </div>

  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
      <table class="w-full text-left text-[13px]">
        <thead class="text-[11px] uppercase tracking-wider text-zinc-400"><tr>
          <th class="px-4 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Employee</th><th class="px-3 py-2 font-medium">Task</th>
          <th class="px-3 py-2 font-medium">Status</th><th class="px-4 py-2 text-right font-medium">Time (h:mm)</th></tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php $lastDate = null; foreach ($rows as $t): $st = TASK_STATUS_STYLES[$t['status']] ?? TASK_STATUS_STYLES['pending']; ?>
          <tr class="align-top <?= $lastDate !== null && $lastDate !== $t['task_date'] ? 'border-t-2 border-t-zinc-200' : '' ?>">
            <td class="whitespace-nowrap px-4 py-2 <?= $lastDate === $t['task_date'] ? 'text-zinc-300' : 'font-medium text-zinc-900' ?>"><?= date('D, j M Y', strtotime($t['task_date'])) ?></td>
            <td class="px-3 py-2"><a href="?<?= $qs(['user' => $t['user_id'], 'page' => '']) ?>" class="font-medium text-zinc-900 hover:text-brand-600"><?= e($t['name']) ?></a>
              <p class="text-[11px] text-zinc-400"><?= e(implode(' · ', array_filter([$t['employee_id'], $t['dept_name']]))) ?></p></td>
            <td class="px-3 py-2"><p class="text-zinc-900"><?= e($t['title']) ?></p>
              <?php if ($t['description']): ?><p class="mt-0.5 whitespace-pre-line text-[12px] text-zinc-500"><?= e($t['description']) ?></p><?php endif; ?>
              <?php if ($t['category']): ?><span class="mt-1 inline-block rounded bg-zinc-100 px-1.5 text-[11px] text-zinc-600"><?= e($t['category']) ?></span><?php endif; ?></td>
            <td class="px-3 py-2"><span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset <?= $st['chip'] ?>">
              <span class="h-1.5 w-1.5 rounded-full <?= $st['dot'] ?>"></span><?= e(TASK_STATUSES[$t['status']] ?? $t['status']) ?></span></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= hm($t['hours']) ?></td>
          </tr>
        <?php $lastDate = $t['task_date']; endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="px-4 py-10 text-center text-zinc-400">No tasks match these filters.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-between border-t border-zinc-200 px-4 py-2 text-[12px]">
        <span class="text-zinc-500">Page <?= $page ?> of <?= $pages ?> · <?= $sum['n'] ?> tasks</span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?><a class="rounded-md border border-zinc-200 px-3 py-1 hover:bg-zinc-50" href="?<?= $qs(['page' => $page - 1]) ?>">Previous</a><?php endif; ?>
          <?php if ($page < $pages): ?><a class="rounded-md border border-zinc-200 px-3 py-1 hover:bg-zinc-50" href="?<?= $qs(['page' => $page + 1]) ?>">Next</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
