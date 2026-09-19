<?php
/**
 * Manual attendance: add a punch for someone who could not record one, and
 * correct or delete existing punches. Replaces the old admin/manual_attendance.php.
 */
require_once __DIR__ . '/includes/attendance.php';
require_att('manual_attendance');

$time = fn($k) => preg_match('/^\d{2}:\d{2}(:\d{2})?$/', post($k)) ? post($k) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    if ($a === 'add') {
        $uid = (int) post('user_id'); $date = post('date');
        if (!$uid || !strtotime($date) || $date > date('Y-m-d') || !$time('punch_in')) {
            flash('Pick an employee, a date (not in the future) and a punch-in time.', 'error');
        } else {
            $where = 'Manual entry by ' . user()['name'];
            q('INSERT INTO attendance (user_id, date, punch_in, punch_out, status, punch_in_location, punch_out_location, punch_in_by, punch_out_by)
               VALUES (?,?,?,?,"Present",?,?,?,?)',
              [$uid, $date, $time('punch_in'), $time('punch_out'), $where, $time('punch_out') ? $where : null,
               user()['id'], $time('punch_out') ? user()['id'] : null]);
            flash('Manual attendance added.');
        }
    } elseif ($a === 'update') {
        if (!$time('punch_in')) { flash('A punch-in time is required.', 'error'); }
        else {
            q('UPDATE attendance SET punch_in = ?, punch_out = ? WHERE id = ?', [$time('punch_in'), $time('punch_out'), (int) post('id')]);
            flash('Punch times updated.');
        }
    } elseif ($a === 'delete') {
        q('DELETE FROM attendance WHERE id = ?', [(int) post('id')]);
        flash('Punch record deleted.');
    }
    redirect('hrms-manual.php?' . http_build_query(['user' => (int) (post('user_id') ?: get_('user')), 'date' => post('date') ?: get_('date')]));
}

$userId = (int) get_('user');
$date = strtotime(get_('date')) ? date('Y-m-d', strtotime(get_('date'))) : att_workday();
$employees = q("SELECT id, name, employee_id FROM users WHERE phone IS NOT NULL AND role NOT IN ('superadmin','admin','hod','face_operator') AND status = 'Working' ORDER BY name")->fetchAll();

// The punches being corrected: one person's day, or everyone's manual entries that day.
$rows = $userId
    ? q('SELECT a.*, u.name, u.employee_id FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.user_id = ? AND a.date = ? ORDER BY a.id', [$userId, $date])->fetchAll()
    : q("SELECT a.*, u.name, u.employee_id FROM attendance a JOIN users u ON u.id = a.user_id
         WHERE a.date = ? AND a.punch_in_location LIKE 'Manual entry%' ORDER BY u.name, a.id", [$date])->fetchAll();

$pageTitle = 'Manual Attendance';
require __DIR__ . '/layout/header.php';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
$empSelect = function (string $name, int $sel, string $extra = '') use ($employees) {
    $o = '<select name="' . $name . '" class="' . ATT_FIELD . '" ' . $extra . '><option value="">Employee…</option>';
    foreach ($employees as $x) $o .= '<option value="' . $x['id'] . '"' . ($x['id'] == $sel ? ' selected' : '') . '>' . e($x['name'] . ($x['employee_id'] ? " ({$x['employee_id']})" : '')) . '</option>';
    return $o . '</select>';
};
?>
<div class="mx-auto max-w-5xl space-y-4">
  <form method="post" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-card">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2 class="text-[13px] font-semibold text-zinc-900">Add manual attendance</h2>
    <p class="mt-1 text-[12px] text-zinc-500">For someone who could not punch. The entry is marked "Manual entry by <?= e(user()['name']) ?>".</p>
    <div class="mt-4 grid gap-3 sm:grid-cols-[1fr_10rem_8rem_8rem_auto] sm:items-end">
      <div><label class="<?= $lbl ?>">Employee *</label><?= $empSelect('user_id', $userId, 'required') ?></div>
      <div><label class="<?= $lbl ?>">Date *</label><input type="date" name="date" required value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">In *</label><input type="time" name="punch_in" required class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Out</label><input type="time" name="punch_out" class="<?= ATT_FIELD ?>"></div>
      <button class="<?= ATT_BTN ?>">Add</button>
    </div>
  </form>

  <section class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <form class="flex flex-wrap items-end gap-2 border-b border-zinc-200 bg-zinc-50/70 p-3">
      <div class="w-64"><?= $empSelect('user', $userId) ?></div>
      <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" class="<?= ATT_FIELD ?> !w-40">
      <button class="<?= ATT_BTN2 ?>">Show punches</button>
      <p class="ml-auto text-[12px] text-zinc-500"><?= $userId ? 'All punches of this person on the day' : 'Manual entries made for this day — pick an employee to correct any punch' ?></p>
    </form>
    <ul class="divide-y divide-zinc-100">
      <?php foreach ($rows as $p): ?>
        <li class="flex flex-wrap items-center gap-3 px-4 py-2 text-[13px]">
          <span class="w-48 min-w-0 truncate font-medium text-zinc-900"><?= e($p['name']) ?> <span class="font-normal text-zinc-400"><?= e($p['employee_id'] ?? '') ?></span></span>
          <form method="post" class="flex items-center gap-1">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <input type="time" step="1" name="punch_in" required value="<?= e($p['punch_in']) ?>" class="rounded border border-zinc-200 px-1 py-0.5 tabular-nums">
            <span class="text-zinc-400">→</span>
            <input type="time" step="1" name="punch_out" value="<?= e($p['punch_out']) ?>" class="rounded border border-zinc-200 px-1 py-0.5 tabular-nums">
            <button class="rounded border border-zinc-200 px-2 py-0.5 text-[12px] text-zinc-600 hover:bg-zinc-50">Save</button>
          </form>
          <form method="post" onsubmit="return confirm('Delete this punch?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button class="text-[12px] text-rose-600 hover:underline">Delete</button>
          </form>
          <span class="max-w-[16rem] truncate text-[12px] text-zinc-400"><?= e($p['punch_in_location'] ?: '') ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (!$rows): ?><li class="px-4 py-8 text-center text-zinc-400">No punches to show.</li><?php endif; ?>
    </ul>
  </section>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
