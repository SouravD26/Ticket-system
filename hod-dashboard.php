<?php
/**
 * The HOD's landing page: ticket details across the organisation.
 *  - How many tickets were raised each day, for all departments or one.
 *  - How many of them are completed and how many still pending.
 * Completed = IT finished it (awaiting acknowledgement) or it is closed.
 * Pending   = still open or in progress.
 */
require_once __DIR__ . '/includes/functions.php';
require_can('can_view_all_tasks', 'the HOD dashboard');

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', get_('from')) ? get_('from') : date('Y-m-01');
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', get_('to'))   ? get_('to')   : date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
$dept = (int) get_('dept');

$where = 'DATE(t.created_at) BETWEEN ? AND ?' . ($dept ? ' AND t.department_id = ?' : '');
$args  = $dept ? [$from, $to, $dept] : [$from, $to];
$counts = "COUNT(*) raised,
           SUM(t.status IN ('resolved','closed')) completed,
           SUM(t.status IN ('open','pending')) pending,
           SUM(t.status = 'open') open_n, SUM(t.status = 'pending') progress_n,
           SUM(t.status = 'resolved') resolved_n, SUM(t.status = 'closed') closed_n";

$total = q("SELECT $counts FROM tickets t WHERE $where", $args)->fetch();

// One row per calendar day in the range, zero-filled so quiet days still show.
$byDay = [];
foreach (q("SELECT DATE(t.created_at) d, $counts FROM tickets t WHERE $where GROUP BY d", $args) as $r) $byDay[$r['d']] = $r;
$days = [];
for ($d = $to; $d >= $from; $d = date('Y-m-d', strtotime("$d -1 day"))) {
    $days[$d] = $byDay[$d] ?? ['raised' => 0, 'completed' => 0, 'pending' => 0];
}
$peak = max(1, ...array_values(array_map(fn($r) => (int) $r['raised'], $days)));

// Who completed each day's finished tickets: whoever last marked it complete (the
// activity log), else the IT person it was assigned to. Grouped by the day it was raised.
$doneBy = [];
foreach (q("SELECT DATE(t.created_at) d, COALESCE(w.name, a.name, 'Unknown') who, COUNT(*) n
            FROM tickets t
            LEFT JOIN users a ON a.id = t.assigned_to
            LEFT JOIN users w ON w.id = (SELECT ac.user_id FROM ticket_activity ac
                                         WHERE ac.ticket_id = t.id AND ac.action = 'completed' ORDER BY ac.id DESC LIMIT 1)
            WHERE $where AND t.status IN ('resolved','closed')
            GROUP BY d, who ORDER BY n DESC, who", $args) as $r) {
    $doneBy[$r['d']][] = $r;
}

// With "All departments", also split the totals by department.
$byDept = $dept ? [] : q("SELECT COALESCE(d.name, 'No department') dept, d.id, $counts
                          FROM tickets t LEFT JOIN departments d ON d.id = t.department_id
                          WHERE $where GROUP BY d.id, d.name ORDER BY raised DESC, dept", $args)->fetchAll();

$deptName = '';
foreach (all_departments() as $d) if ((int) $d['id'] === $dept) $deptName = $d['name'];
$qs = fn(array $o = []) => http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'dept' => $dept ?: ''], $o), fn($v) => $v !== ''));
$n  = fn($v) => (int) $v;

$pageTitle = 'HOD Dashboard';
require __DIR__ . '/layout/header.php';
$field = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none focus:border-brand-400';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
?>
<div class="mx-auto max-w-6xl space-y-4">
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h2 class="text-[15px] font-semibold text-zinc-900">Ticket details</h2>
      <p class="text-[12px] text-zinc-500"><?= $deptName ? e($deptName) : 'All departments' ?> · <?= date('j M Y', strtotime($from)) ?> – <?= date('j M Y', strtotime($to)) ?></p>
    </div>
    <form class="flex flex-wrap items-end gap-2">
      <div class="w-44"><label class="<?= $lbl ?>">Department</label>
        <select name="dept" onchange="this.form.submit()" class="<?= $field ?>">
          <option value="">All departments</option>
          <?php foreach (all_departments() as $d): ?><option value="<?= $d['id'] ?>" <?= $dept === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="<?= $lbl ?>">From</label><input type="date" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>"></div>
      <div><label class="<?= $lbl ?>">To</label><input type="date" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>" class="<?= $field ?>"></div>
      <button class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm hover:bg-brand-600">Show</button>
    </form>
  </div>
  <div class="flex flex-wrap gap-1.5">
    <?php foreach (['Today' => [date('Y-m-d'), date('Y-m-d')], 'Last 7 days' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
                    'This month' => [date('Y-m-01'), date('Y-m-d')],
                    'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))]] as $l => [$a, $b]): ?>
      <a href="?<?= $qs(['from' => $a, 'to' => $b]) ?>" class="rounded-md border px-2 py-1 text-[12px] <?= $from === $a && $to === $b ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Totals -->
  <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
      <p class="text-[12px] text-zinc-500">Total tickets raised</p>
      <p class="mt-1 text-3xl font-semibold tabular-nums text-zinc-900"><?= $n($total['raised']) ?></p>
    </div>
    <div class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 shadow-sm">
      <p class="text-[12px] text-emerald-700">Completed</p>
      <p class="mt-1 text-3xl font-semibold tabular-nums text-emerald-700"><?= $n($total['completed']) ?></p>
      <p class="mt-1 text-[11px] text-zinc-500"><?= $n($total['closed_n']) ?> closed · <?= $n($total['resolved_n']) ?> awaiting acknowledgement</p>
    </div>
    <div class="rounded-lg border border-amber-200 bg-amber-50/40 p-4 shadow-sm">
      <p class="text-[12px] text-amber-700">Pending</p>
      <p class="mt-1 text-3xl font-semibold tabular-nums text-amber-700"><?= $n($total['pending']) ?></p>
      <p class="mt-1 text-[11px] text-zinc-500"><?= $n($total['open_n']) ?> open · <?= $n($total['progress_n']) ?> in progress</p>
    </div>
  </div>

  <div class="grid gap-4 <?= $byDept ? 'lg:grid-cols-[1fr_22rem]' : '' ?>">
    <!-- Day by day -->
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
      <h3 class="border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5 text-[13px] font-semibold text-zinc-900">Tickets raised day by day</h3>
      <div class="max-h-[32rem] overflow-y-auto">
        <table class="w-full text-left text-[13px]">
          <thead class="sticky top-0 bg-white text-[11px] uppercase tracking-wider text-zinc-400"><tr>
            <th class="px-4 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">Raised</th><th class="w-1/3 px-3 py-2"></th>
            <th class="px-3 py-2 text-right font-medium">Completed</th><th class="px-3 py-2 text-right font-medium">Pending</th>
            <th class="px-4 py-2 font-medium">Completed by</th></tr></thead>
          <tbody class="divide-y divide-zinc-100">
          <?php foreach ($days as $d => $r): $raised = $n($r['raised']); ?>
            <tr class="<?= $raised ? '' : 'text-zinc-300' ?>">
              <td class="whitespace-nowrap px-4 py-1.5 <?= $raised ? 'text-zinc-900' : '' ?>"><?= date('D, j M', strtotime($d)) ?></td>
              <td class="px-3 py-1.5 font-semibold tabular-nums"><?= $raised ?></td>
              <td class="px-3 py-1.5">
                <?php if ($raised): ?>
                  <div class="flex h-2 overflow-hidden rounded-full bg-zinc-100" style="width: <?= max(6, round($raised / $peak * 100)) ?>%"
                       title="<?= $n($r['completed']) ?> completed, <?= $n($r['pending']) ?> pending">
                    <div class="bg-emerald-500" style="width: <?= round($n($r['completed']) / $raised * 100) ?>%"></div>
                    <div class="bg-amber-400" style="width: <?= round($n($r['pending']) / $raised * 100) ?>%"></div>
                  </div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-1.5 text-right tabular-nums <?= $raised ? 'text-emerald-700' : '' ?>"><?= $n($r['completed']) ?></td>
              <td class="px-3 py-1.5 text-right tabular-nums <?= $raised ? 'text-amber-700' : '' ?>"><?= $n($r['pending']) ?></td>
              <td class="px-4 py-1.5 text-[12px] text-zinc-600">
                <?php foreach ($doneBy[$d] ?? [] as $w): ?>
                  <span class="mr-1 inline-block whitespace-nowrap rounded bg-emerald-50 px-1.5 py-0.5 text-emerald-800"><?= e($w['who']) ?><?= $w['n'] > 1 ? ' <b>(' . (int) $w['n'] . ')</b>' : '' ?></span>
                <?php endforeach; ?>
                <?= !isset($doneBy[$d]) && $raised ? '<span class="text-zinc-300">—</span>' : '' ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="border-t border-zinc-100 px-4 py-2 text-[11px] text-zinc-500">
        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-emerald-500"></span>Completed
        <span class="ml-3 mr-1 inline-block h-2 w-2 rounded-full bg-amber-400"></span>Pending — counted by the day each ticket was raised, as it stands now.</p>
    </section>

    <?php if ($byDept): ?>
    <!-- Department split, only for "All departments" -->
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
      <h3 class="border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5 text-[13px] font-semibold text-zinc-900">By department</h3>
      <table class="w-full text-left text-[13px]">
        <thead class="text-[11px] uppercase tracking-wider text-zinc-400"><tr>
          <th class="px-4 py-2 font-medium">Department</th><th class="px-2 py-2 text-right font-medium">Raised</th>
          <th class="px-2 py-2 text-right font-medium">Done</th><th class="px-4 py-2 text-right font-medium">Pending</th></tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php foreach ($byDept as $r): ?>
          <tr>
            <td class="px-4 py-1.5"><?= $r['id'] ? '<a class="hover:text-brand-600 hover:underline" href="?' . $qs(['dept' => $r['id']]) . '">' . e($r['dept']) . '</a>' : e($r['dept']) ?></td>
            <td class="px-2 py-1.5 text-right font-semibold tabular-nums"><?= $n($r['raised']) ?></td>
            <td class="px-2 py-1.5 text-right tabular-nums text-emerald-700"><?= $n($r['completed']) ?></td>
            <td class="px-4 py-1.5 text-right tabular-nums text-amber-700"><?= $n($r['pending']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
