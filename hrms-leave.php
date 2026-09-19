<?php
/**
 * Comp-off (a day off taken against an extra day worked) and OD (on official duty
 * away from the office). Both only change how a day reads in the reports.
 * Replaces the old admin/comp_off_management.php and od_management.php.
 */
require_once __DIR__ . '/includes/attendance.php';
require_login();
$canCO = att_can('comp_off');
$canOD = att_can('od_management');
$canLV = att_can('leave_approval');
if (!$canCO && !$canOD && !$canLV) { http_response_code(403); die('403'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    $uid = (int) post('user_id');
    try {
        if ($a === 'add_co' && $canCO) {
            if (!$uid || !strtotime(post('comp_off_date'))) throw new RuntimeException('Pick an employee and the day off.');
            q('INSERT INTO comp_off_requests (user_id, comp_off_date, earned_date, marked_by) VALUES (?,?,?,?)',
              [$uid, post('comp_off_date'), post('earned_date') ?: null, user()['id']]);
            flash('Comp-off recorded.');
        } elseif ($a === 'add_od' && $canOD) {
            $from = post('od_from'); $to = post('od_to') ?: $from;
            if (!$uid || !strtotime($from) || $to < $from) throw new RuntimeException('Pick an employee and valid dates.');
            $n = 0;
            for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                $n += q('INSERT IGNORE INTO od_records (user_id, od_date, marked_by) VALUES (?,?,?)', [$uid, $d, user()['id']])->rowCount();
            }
            flash("$n OD day(s) recorded.");
        } elseif ($a === 'review' && $canLV) {
            // Same effect as the app's admin/leave_action: stamp the review and count approved days.
            $l = q('SELECT * FROM leave_applications WHERE id = ? AND status = "Pending"', [(int) post('id')])->fetch();
            if (!$l) throw new RuntimeException('That request was already reviewed.');
            $status = post('decision') === 'approve' ? 'Approved' : 'Rejected';
            q('UPDATE leave_applications SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
              [$status, post('notes') ?: null, user()['id'], $l['id']]);
            if ($status === 'Approved') {
                q('INSERT INTO employee_leave_balances (user_id, leave_type, year, days_allowed, days_used) VALUES (?,?,?,0,?)
                   ON DUPLICATE KEY UPDATE days_used = days_used + VALUES(days_used)',
                  [$l['user_id'], $l['leave_type'], (int) substr($l['start_date'], 0, 4), $l['days_count']]);
            }
            flash('Leave ' . strtolower($status) . '.');
        } elseif ($a === 'del_co' && $canCO) {
            q('DELETE FROM comp_off_requests WHERE id = ?', [(int) post('id')]); flash('Comp-off removed.');
        } elseif ($a === 'del_od' && $canOD) {
            q('DELETE FROM od_records WHERE id = ?', [(int) post('id')]); flash('OD removed.');
        }
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'That day is already recorded for this employee.' : 'Could not save.', 'error');
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
    }
    redirect('hrms-leave.php?month=' . urlencode(get_('month')));
}

$month = preg_match('/^\d{4}-\d{2}$/', get_('month')) ? get_('month') : date('Y-m');
$from = "$month-01"; $to = date('Y-m-t', strtotime($from));
$employees = q("SELECT id, name, employee_id FROM users WHERE phone IS NOT NULL AND role NOT IN ('superadmin','admin','hod','face_operator') AND status = 'Working' ORDER BY name")->fetchAll();
$co = q('SELECT c.*, u.name, u.employee_id, m.name marker FROM comp_off_requests c JOIN users u ON u.id = c.user_id
         LEFT JOIN users m ON m.id = c.marked_by WHERE c.comp_off_date BETWEEN ? AND ? ORDER BY c.comp_off_date DESC', [$from, $to])->fetchAll();
$od = q('SELECT o.*, u.name, u.employee_id, m.name marker FROM od_records o JOIN users u ON u.id = o.user_id
         LEFT JOIN users m ON m.id = o.marked_by WHERE o.od_date BETWEEN ? AND ? ORDER BY o.od_date DESC', [$from, $to])->fetchAll();

$pending = $canLV ? q("SELECT la.*, u.name, u.employee_id FROM leave_applications la JOIN users u ON u.id = la.user_id
                       WHERE la.status = 'Pending' ORDER BY la.start_date")->fetchAll() : [];
$reviewed = $canLV ? q('SELECT la.*, u.name, u.employee_id, r.name reviewer FROM leave_applications la JOIN users u ON u.id = la.user_id
                        LEFT JOIN users r ON r.id = la.reviewed_by
                        WHERE la.status <> "Pending" AND la.start_date <= ? AND la.end_date >= ? ORDER BY la.start_date DESC', [$to, $from])->fetchAll() : [];

$pageTitle = 'Leave, Comp-off & OD';
require __DIR__ . '/layout/header.php';

$empSelect = function () use ($employees) {
    $o = '<select name="user_id" required class="' . ATT_FIELD . '"><option value="">Employee…</option>';
    foreach ($employees as $x) $o .= '<option value="' . $x['id'] . '">' . e($x['name'] . ($x['employee_id'] ? " ({$x['employee_id']})" : '')) . '</option>';
    return $o . '</select>';
};
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
$list = function (array $rows, string $dateCol, string $del, callable $extra) use ($month) {
    if (!$rows) return '<p class="px-4 py-6 text-center text-zinc-400">Nothing recorded this month.</p>';
    $o = '<ul class="divide-y divide-zinc-100">';
    foreach ($rows as $r) {
        $o .= '<li class="flex items-center gap-3 px-4 py-2"><span class="w-24 font-medium tabular-nums text-zinc-900">' . date('D j M', strtotime($r[$dateCol])) . '</span>'
            . '<span class="min-w-0 flex-1 truncate">' . e($r['name']) . ' <span class="text-zinc-400">' . e($r['employee_id'] ?? '') . '</span>' . $extra($r) . '</span>'
            . '<span class="hidden text-[11px] text-zinc-400 sm:inline">by ' . e($r['marker'] ?? '?') . '</span>'
            . '<form method="post" action="?month=' . e($month) . '" onsubmit="return confirm(\'Remove?\')">' . csrf_field()
            . '<input type="hidden" name="action" value="' . $del . '"><input type="hidden" name="id" value="' . $r['id'] . '">'
            . '<button class="text-[12px] text-rose-600 hover:underline">Remove</button></form></li>';
    }
    return $o . '</ul>';
};
?>
<div class="mx-auto max-w-5xl space-y-4">
  <form class="flex items-center justify-end gap-2">
    <span class="text-[12px] text-zinc-500">Month</span>
    <input type="month" name="month" value="<?= e($month) ?>" onchange="this.form.submit()" class="<?= ATT_FIELD ?> !w-44">
  </form>

  <?php if ($canLV): ?>
  <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Leave requests waiting</h2>
      <span class="text-[11px] tabular-nums text-zinc-500"><?= count($pending) ?></span>
    </div>
    <?php if (!$pending): ?><p class="px-4 py-6 text-center text-zinc-400">Nothing waiting for approval.</p><?php endif; ?>
    <ul class="divide-y divide-zinc-100">
      <?php foreach ($pending as $l): ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-3">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-zinc-900"><?= e($l['name']) ?> <span class="font-normal text-zinc-400"><?= e($l['employee_id'] ?? '') ?></span></p>
            <p class="text-[12px] text-zinc-500"><?= e($l['leave_type']) ?> · <?= date('j M', strtotime($l['start_date'])) ?> – <?= date('j M Y', strtotime($l['end_date'])) ?>
              (<?= (int) $l['days_count'] ?> day<?= $l['days_count'] == 1 ? '' : 's' ?>)<?= $l['reason'] ? ' · ' . e($l['reason']) : '' ?></p>
          </div>
          <form method="post" action="?month=<?= e($month) ?>" class="flex items-center gap-2">
            <?= csrf_field() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= $l['id'] ?>">
            <input name="notes" placeholder="Note (optional)" class="<?= ATT_FIELD ?> !w-44 !py-1.5">
            <button name="decision" value="approve" class="rounded-md bg-emerald-600 px-3 py-1.5 text-[12px] font-medium text-white hover:bg-emerald-700">Approve</button>
            <button name="decision" value="reject" class="rounded-md border border-rose-200 px-3 py-1.5 text-[12px] font-medium text-rose-700 hover:bg-rose-50">Reject</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($reviewed): ?>
      <details class="border-t border-zinc-200"><summary class="cursor-pointer px-4 py-2 text-[12px] text-zinc-500">Reviewed this month (<?= count($reviewed) ?>)</summary>
        <ul class="divide-y divide-zinc-100 text-[12px]">
          <?php foreach ($reviewed as $l): ?>
            <li class="flex gap-3 px-4 py-2"><span class="w-20 font-medium <?= $l['status'] === 'Approved' ? 'text-emerald-700' : 'text-rose-700' ?>"><?= e($l['status']) ?></span>
              <span class="flex-1"><?= e($l['name']) ?> · <?= e($l['leave_type']) ?> · <?= date('j M', strtotime($l['start_date'])) ?>–<?= date('j M', strtotime($l['end_date'])) ?></span>
              <span class="text-zinc-400">by <?= e($l['reviewer'] ?? '?') ?></span></li>
          <?php endforeach; ?>
        </ul></details>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <div class="grid gap-4 lg:grid-cols-2">
  <?php if ($canCO): ?>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
      <form method="post" action="?month=<?= e($month) ?>" class="space-y-3 border-b border-zinc-200 p-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_co">
        <h2 class="text-[13px] font-semibold text-zinc-900">Comp-off</h2>
        <p class="text-[12px] text-zinc-500">A day off taken in return for an extra day worked. The day shows as <b>ADJ</b> in reports.</p>
        <?= $empSelect() ?>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="<?= $lbl ?>">Day off *</label><input type="date" name="comp_off_date" required class="<?= ATT_FIELD ?>"></div>
          <div><label class="<?= $lbl ?>">Earned on</label><input type="date" name="earned_date" class="<?= ATT_FIELD ?>"></div>
        </div>
        <button class="<?= ATT_BTN ?>">Record comp-off</button>
      </form>
      <?= $list($co, 'comp_off_date', 'del_co', fn($r) => $r['earned_date'] ? ' <span class="text-[11px] text-violet-700">earned ' . date('j M', strtotime($r['earned_date'])) . '</span>' : '') ?>
    </section>
  <?php endif; ?>

  <?php if ($canOD): ?>
    <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
      <form method="post" action="?month=<?= e($month) ?>" class="space-y-3 border-b border-zinc-200 p-4">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_od">
        <h2 class="text-[13px] font-semibold text-zinc-900">On duty (OD)</h2>
        <p class="text-[12px] text-zinc-500">Working away from the office on official work. The day shows as <b>OD</b> in reports.</p>
        <?= $empSelect() ?>
        <div class="grid grid-cols-2 gap-2">
          <div><label class="<?= $lbl ?>">From *</label><input type="date" name="od_from" required class="<?= ATT_FIELD ?>"></div>
          <div><label class="<?= $lbl ?>">To</label><input type="date" name="od_to" class="<?= ATT_FIELD ?>"></div>
        </div>
        <button class="<?= ATT_BTN ?>">Record OD</button>
      </form>
      <?= $list($od, 'od_date', 'del_od', fn($r) => '') ?>
    </section>
  <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
