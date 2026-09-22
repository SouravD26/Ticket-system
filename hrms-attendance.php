<?php
/**
 * Everyone's attendance for a day, or one person's month. Replaces the old
 * admin/attendance.php, all_punch_records and employee_attendance. Read-only;
 * manual entries and corrections live in hrms-manual.php.
 */
require_once __DIR__ . '/includes/attendance.php';
require_att('view_attendance');

$userId = (int) get_('user');

if ($userId) {
    /* ---------- one person, one month ---------- */
    $person = q('SELECT u.*, d.name dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?', [$userId])->fetch();
    if (!$person) redirect('hrms-attendance.php');
    $month = preg_match('/^\d{4}-\d{2}$/', get_('month')) ? get_('month') : date('Y-m');
    $from = "$month-01"; $to = min(date('Y-m-t', strtotime($from)), date('Y-m-d'));
    $grid = att_grid([$person], $from, max($from, $to))[$userId] ?? [];
    $punches = [];
    foreach (q('SELECT * FROM attendance WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY id', [$userId, $from, date('Y-m-t', strtotime($from))]) as $r) {
        $punches[$r['date']][] = $r;
    }
    $count = array_count_values(array_column($grid, 'code'));
    $pageTitle = $person['name'] . ' — Attendance';
} else {
    /* ---------- everyone, one day ---------- */
    $date = strtotime(get_('date')) ? date('Y-m-d', strtotime(get_('date'))) : att_workday();
    $f = ['dept' => dept_filter_id(get_('dept')), 'company' => get_('company'), 'location' => get_('location'), 'show' => get_('show', 'all')];
    // Working staff, plus anyone who actually punched that day - a since-resigned
    // employee still belongs on the sheet for the days they worked.
    $where = ["u.phone IS NOT NULL", "u.role NOT IN ('superadmin','admin','hod','face_operator')",
              "(u.status = 'Working' OR u.id IN (SELECT user_id FROM attendance WHERE date = ?))"];
    $args = [$date];
    if ($f['dept'])     { $where[] = 'u.department_id = ?'; $args[] = $f['dept']; }
    if ($f['company'])  { $where[] = 'u.company = ?';  $args[] = $f['company']; }
    if ($f['location']) { $where[] = 'u.location = ?'; $args[] = $f['location']; }
    $people = q('SELECT u.id, u.name, u.employee_id, u.week_off, u.status, u.date_of_joining, u.date_of_exit, u.location, d.name dept_name
                 FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE ' . implode(' AND ', $where) . ' ORDER BY d.name, u.name', $args)->fetchAll();
    $grid = att_grid($people, $date, $date);
    $punches = [];
    foreach (q('SELECT * FROM attendance WHERE date = ? ORDER BY id', [$date]) as $r) $punches[$r['user_id']][] = $r;
    $count = array_count_values(array_map(fn($p) => $grid[$p['id']][$date]['code'], $people));
    if ($f['show'] === 'present') $people = array_filter($people, fn($p) => isset($punches[$p['id']]));
    if ($f['show'] === 'absent')  $people = array_filter($people, fn($p) => $grid[$p['id']][$date]['code'] === 'A');

    // The counters above the table stay for the whole day; only the rows are paged.
    $per   = 20;
    $total = count($people);
    $pages = max(1, (int) ceil($total / $per));
    $page  = min($pages, max(1, (int) get_('page', 1)));
    $people = array_slice(array_values($people), ($page - 1) * $per, $per);
    $qs = fn(array $o) => http_build_query(array_filter(array_merge(['date' => $date], $f, $o), fn($x) => $x !== '' && $x !== 0));
    $pageTitle = 'Attendance';
}

require __DIR__ . '/layout/header.php';

$codeCls = ['P' => 'bg-emerald-50 text-emerald-700', 'A' => 'bg-rose-50 text-rose-700', 'WO' => 'bg-zinc-100 text-zinc-500',
            'OD' => 'bg-sky-50 text-sky-700', 'ADJ' => 'bg-violet-50 text-violet-700', 'L' => 'bg-amber-50 text-amber-700', 'H' => 'bg-zinc-100 text-zinc-600', '-' => 'text-zinc-300'];
$chip = fn($c) => '<span class="inline-block min-w-[2rem] rounded px-1.5 py-0.5 text-center text-[11px] font-semibold ' . ($codeCls[$c] ?? '') . '">' . e($c) . '</span>';

/** One punch: times, selfies, place. Corrections are made on the Manual Attendance page. */
$punchRow = function (array $p) {
    // A selfie thumbnail with its time under it; clicking opens the full photo.
    $shot = function (?string $file, ?string $t, string $label) {
        $missing = $file && !att_selfie_path($file);   // recorded in the database, but the file never reached this server
        $img = $file && !$missing
            ? '<a target="_blank" href="' . url('att-file.php?selfie=' . urlencode($file)) . '"><img loading="lazy" alt="' . $label . ' photo" src="'
              . url('att-file.php?selfie=' . urlencode($file)) . '" class="h-12 w-12 rounded-md border border-zinc-200 object-cover hover:ring-2 hover:ring-brand-400"></a>'
            : ($missing
                ? '<span title="' . e($file) . ' is not in uploads/hrms/selfies on this server" class="grid h-12 w-12 place-items-center rounded-md border border-dashed border-amber-300 bg-amber-50 text-center text-[10px] leading-tight text-amber-600">Photo missing</span>'
                : '<span class="grid h-12 w-12 place-items-center rounded-md border border-dashed border-zinc-200 text-[10px] text-zinc-300">No photo</span>');
        return '<div class="text-center">' . $img . '<p class="mt-0.5 text-[10px] text-zinc-400">' . $label . ' ' . att_time($t) . '</p></div>';
    };
    return '<div class="flex flex-wrap items-center gap-3 text-[12px]">'
        . $shot($p['selfie_punchin'], $p['punch_in'], 'In')
        . $shot($p['selfie_punchout'], $p['punch_out'], 'Out')
        . '<span class="max-w-[16rem] text-zinc-400" title="' . e($p['punch_in_location'] . ' / ' . $p['punch_out_location']) . '">'
        . att_place_html($p, 'in') . ($p['punch_out_location'] ? '<br>→ ' . att_place_html($p, 'out') : '') . '</span>'
        . '</div>';
};
$stats = ['P' => 'Present', 'A' => 'Absent', 'L' => 'Leave', 'WO' => 'Week off', 'OD' => 'On duty', 'ADJ' => 'Comp-off'];
?>
<div class="mx-auto max-w-6xl space-y-4">

<?php if ($userId): ?>
  <div class="flex flex-wrap items-center gap-3">
    <a href="<?= url('hrms-attendance.php') ?>" class="text-[12px] text-zinc-500 hover:text-zinc-900">← All employees</a>
    <div>
      <p class="text-[15px] font-semibold text-zinc-900"><?= e($person['name']) ?></p>
      <p class="text-[12px] text-zinc-500"><?= e(implode(' · ', array_filter([$person['employee_id'], $person['dept_name'], $person['location'], $person['week_off'] ? 'Week off ' . $person['week_off'] : '']))) ?></p>
    </div>
    <form class="ml-auto"><input type="hidden" name="user" value="<?= $userId ?>">
      <input type="month" name="month" value="<?= e($month) ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()" class="<?= ATT_FIELD ?> !w-44"></form>
  </div>
  <div class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
    <?php foreach ($stats as $k => $l): ?>
      <div class="rounded-lg border border-zinc-200 bg-white p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= $count[$k] ?? 0 ?></p><p class="text-[11px] text-zinc-500"><?= $l ?></p></div>
    <?php endforeach; ?>
  </div>
  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <table class="w-full text-left text-[13px]"><tbody class="divide-y divide-zinc-100">
      <?php foreach (array_reverse($grid, true) as $d => $g): ?>
        <tr><td class="w-32 px-4 py-2 whitespace-nowrap font-medium text-zinc-900"><?= date('D, j M', strtotime($d)) ?></td>
          <td class="w-14 py-2"><?= $chip($g['code']) ?></td>
          <td class="px-3 py-2 space-y-1"><?php foreach ($punches[$d] ?? [] as $p) echo $punchRow($p); ?><?= $g['co'] ? '<span class="text-[12px] text-violet-700">' . e($g['co']) . '</span>' : '' ?></td>
          <td class="px-4 py-2 text-right tabular-nums"><?= att_hm($g['secs']) ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>

<?php else: ?>
  <form class="flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 bg-white p-3 shadow-sm">
    <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>" class="<?= ATT_FIELD ?> !w-40">
    <div class="w-40"><?= att_select('dept', array_column(all_departments(), 'name', 'id'), (string) $f['dept'], 'All departments') ?></div>
    <div class="w-40"><?= att_select('company', att_companies(), $f['company'], 'All companies') ?></div>
    <div class="w-36"><?= att_select('location', att_locations(), $f['location'], 'All locations') ?></div>
    <div class="w-32"><?= att_select('show', ['all' => 'Everyone', 'present' => 'Present', 'absent' => 'Absent'], $f['show']) ?></div>
    <button class="<?= ATT_BTN2 ?>">Show</button>
  </form>
  <div class="grid grid-cols-3 gap-2 text-center sm:grid-cols-6">
    <?php foreach ($stats as $k => $l): ?>
      <div class="rounded-lg border border-zinc-200 bg-white p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= $count[$k] ?? 0 ?></p><p class="text-[11px] text-zinc-500"><?= $l ?></p></div>
    <?php endforeach; ?>
  </div>
  <p class="text-[11px] text-zinc-500">
    <b><?= date('D, j M Y', strtotime($date)) ?></b> runs 6:00 AM to 6:00 AM the next morning — a night shift that ends after midnight counts as this one day.
    <b>Absent</b> is the fallback: no punch, no week off, no on-duty, no comp-off, no approved leave and no holiday. Days before someone joined are left blank.
  </p>
  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="overflow-x-auto">
    <table class="w-full text-left text-[13px]">
      <thead class="text-[11px] uppercase tracking-wider text-zinc-400"><tr><th class="px-4 py-2 font-medium">Employee</th><th class="px-2 py-2 font-medium"></th>
        <th class="px-3 py-2 font-medium">First in</th><th class="px-3 py-2 font-medium">Last out</th><th class="px-3 py-2 font-medium">Punches</th><th class="px-4 py-2 text-right font-medium">Hours</th></tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php foreach ($people as $p): $g = $grid[$p['id']][$date]; ?>
        <tr class="align-top">
          <td class="px-4 py-2"><a href="<?= url('hrms-attendance.php?user=' . $p['id'] . '&month=' . substr($date, 0, 7)) ?>" class="font-medium text-zinc-900 hover:text-brand-600"><?= e($p['name']) ?></a>
            <p class="text-[11px] text-zinc-400"><?= e(implode(' · ', array_filter([$p['employee_id'], $p['dept_name']]))) ?></p></td>
          <td class="px-2 py-2"><?= $chip($g['code']) ?></td>
          <td class="px-3 py-2 tabular-nums"><?= att_time($g['in']) ?></td>
          <td class="px-3 py-2 tabular-nums"><?= att_time($g['out']) ?></td>
          <td class="px-3 py-2 space-y-1"><?php foreach ($punches[$p['id']] ?? [] as $x) echo $punchRow($x); ?></td>
          <td class="px-4 py-2 text-right tabular-nums"><?= att_hm($g['secs']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$people): ?><tr><td colspan="6" class="px-4 py-8 text-center text-zinc-400">Nobody matches.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-between border-t border-zinc-100 px-4 py-2 text-[12px] text-zinc-500">
        <span><?= ($page - 1) * $per + 1 ?>–<?= min($page * $per, $total) ?> of <?= $total ?> employees</span>
        <span class="flex items-center gap-1">
          <?php if ($page > 1): ?><a href="?<?= $qs(['page' => $page - 1]) ?>" class="<?= ATT_BTN2 ?> !py-1">Previous</a><?php endif; ?>
          <span class="px-2">Page <?= $page ?> of <?= $pages ?></span>
          <?php if ($page < $pages): ?><a href="?<?= $qs(['page' => $page + 1]) ?>" class="<?= ATT_BTN2 ?> !py-1">Next</a><?php endif; ?>
        </span>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
