<?php
/**
 * Excel downloads, in the layout the old HRMS exports used:
 *  - Monthly attendance: one sheet per location, grouped by department, and for each
 *    employee a Status / In / Out / Total row across the days (first in, last out).
 * Filtered by the ticked companies and/or locations (nothing ticked = everyone).
 * Replaces export_monthly, export_all_departments, export_employee_excel and
 * export_location_excel.
 */
require_once __DIR__ . '/includes/attendance.php';
require_att('export_reports');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate as C;
use PhpOffice\PhpSpreadsheet\Style\{Alignment, Border, Fill};

/** Applies the filters the form sent and returns the matching people. */
function export_people(array $in, bool $includeResigned): array
{
    $w = ["u.phone IS NOT NULL", "u.role NOT IN ('superadmin','admin','hod','face_operator')"]; $a = [];
    if (!$includeResigned) $w[] = "u.status = 'Working'";
    if (!empty($in['dept']))     { $w[] = 'u.department_id = ?'; $a[] = (int) $in['dept']; }
    if (!empty($in['user']))     { $w[] = 'u.id = ?'; $a[] = (int) $in['user']; }
    // Ticked companies and ticked locations narrow the list together; nothing ticked means all.
    foreach (['companies' => 'u.company', 'locations' => 'u.location'] as $key => $col) {
        $vals = array_values(array_filter((array) ($in[$key] ?? []), 'is_string'));
        if ($vals) { $w[] = "$col IN (" . rtrim(str_repeat('?,', count($vals)), ',') . ')'; $a = array_merge($a, $vals); }
    }
    return q('SELECT u.*, d.name dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
              WHERE ' . implode(' AND ', $w) . ' ORDER BY u.location, d.name, u.name', $a)->fetchAll();
}

function send_xlsx(Spreadsheet $book, string $name): void
{
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]+/', '_', $name) . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($book))->save('php://output');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    set_time_limit(600);
    ini_set('memory_limit', '512M');
    require_once __DIR__ . '/vendor/autoload.php';

    // Monthly attendance
    $from = post('from'); $to = post('to');
    if (!strtotime($from) || !strtotime($to) || $to < $from || (strtotime($to) - strtotime($from)) > 62 * 86400) {
        flash('Choose a date range of up to two months.', 'error'); redirect('hrms-export.php');
    }
    // A resigned person still appears for the month they left in.
    $people = array_filter(export_people($_POST, true),
        fn($p) => $p['status'] === 'Working' || ($p['date_of_exit'] && date('Y-m-t', strtotime($p['date_of_exit'])) >= $from));
    if (!$people) { flash('Nobody matches those filters.', 'error'); redirect('hrms-export.php'); }

    $grid = att_grid(array_values($people), $from, $to);
    $days = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $days[] = $d;
    $lastCol = C::stringFromColumnIndex(count($days) + 1);
    $period = date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to));

    $byLoc = [];
    foreach ($people as $p) $byLoc[$p['location'] ?: 'Unassigned'][$p['dept_name'] ?: 'Unassigned'][] = $p;
    ksort($byLoc);

    $book = new Spreadsheet(); $book->removeSheetByIndex(0);
    $colours = ['A' => 'FDE2E2', 'WO' => 'EEEEEE', 'OD' => 'DBEAFE', 'ADJ' => 'EDE9FE', 'L' => 'FEF3C7', 'H' => 'EEEEEE'];
    $titles = [];
    foreach ($byLoc as $loc => $depts) {
        $s = $book->createSheet();
        $t = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $loc), 0, 28);
        $s->setTitle(isset($titles[$t]) ? $t . ' ' . ++$titles[$t] : $t); $titles[$t] = $titles[$t] ?? 1;

        $s->setCellValue('A1', "Attendance Report - $loc ($period)");
        $s->mergeCells("A1:{$lastCol}1");
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $r = 3;
        foreach ($days as $i => $d) $s->setCellValue(C::stringFromColumnIndex($i + 2) . $r, date('d-D', strtotime($d)));
        $s->getStyle("A$r:$lastCol$r")->getFont()->setBold(true);
        $r++;

        foreach ($depts as $dept => $emps) {
            $s->setCellValue("A$r", "DEPARTMENT: $dept");
            $s->getStyle("A$r")->getFont()->setBold(true)->setSize(12);
            $r++;
            foreach ($emps as $p) {
                $g = $grid[$p['id']];
                $s->setCellValue("A$r", trim(($p['employee_id'] ? $p['employee_id'] . ' - ' : '') . $p['name']));
                $s->getStyle("A$r")->getFont()->setBold(true);
                $r++;
                foreach (['Status', 'In', 'Out', 'Total'] as $row) {
                    $s->setCellValue("A$r", $row);
                    $s->getStyle("A$r")->getFont()->setBold(true);
                    foreach ($days as $i => $d) {
                        $c = $g[$d]; $col = C::stringFromColumnIndex($i + 2);
                        $v = match ($row) {
                            'Status' => $c['code'],
                            'In'     => $c['in'] ? substr($c['in'], 0, 5) : '-',
                            'Out'    => $c['out'] ? substr($c['out'], 0, 5) : '-',
                            'Total'  => $c['co'] ?? ($c['secs'] ? sprintf('%d:%02d', intdiv($c['secs'], 3600), intdiv($c['secs'] % 3600, 60)) : '-'),
                        };
                        $s->setCellValueExplicit("$col$r", $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        if ($row === 'Status' && isset($colours[$v])) {
                            $s->getStyle("$col$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colours[$v]);
                        }
                    }
                    $r++;
                }
                $r++;
            }
            $r++;
        }
        $s->getColumnDimension('A')->setWidth(28);
        foreach ($days as $i => $d) $s->getColumnDimension(C::stringFromColumnIndex($i + 2))->setWidth(7);
        $s->getStyle("B3:$lastCol" . ($r - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $s->getStyle("A3:$lastCol" . ($r - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $s->freezePane('B4');
    }
    // The file name says what was picked: one person, the ticked companies/locations, or everyone.
    $picked = array_merge(array_filter((array) ($_POST['companies'] ?? [])), array_filter((array) ($_POST['locations'] ?? [])));
    if (post('user'))  $label = reset($people)['name'];
    elseif ($picked)   $label = implode('-', array_slice($picked, 0, 3)) . (count($picked) > 3 ? '-etc' : '');
    else               $label = 'AllEmployees';
    send_xlsx($book, 'Attendance_' . $label . '_' . str_replace(' ', '', $period) . '.xlsx');
}

$employees = q("SELECT id, name, employee_id FROM users WHERE phone IS NOT NULL AND role NOT IN ('superadmin','admin','hod','face_operator') ORDER BY name")->fetchAll();
$pageTitle = 'Excel Reports';
require __DIR__ . '/layout/header.php';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
/** A scrollable list of tick boxes; nothing ticked means "all". */
$box = function (string $name, array $items) { ob_start(); ?>
  <div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-zinc-200 p-2">
    <?php foreach ($items as $v): ?>
      <label class="flex items-center gap-2 text-[12px] text-zinc-700"><input type="checkbox" name="<?= $name ?>[]" value="<?= e($v) ?>" onchange="summary()"> <?= e($v) ?></label>
    <?php endforeach; ?>
  </div>
<?php return ob_get_clean(); };
?>
<div class="mx-auto max-w-4xl">
  <form method="post" id="exp" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-card">
    <?= csrf_field() ?><input type="hidden" name="type" value="attendance">
    <h2 class="text-[13px] font-semibold text-zinc-900">Attendance Excel</h2>
    <p class="mt-1 text-[12px] text-zinc-500">One sheet per location, grouped by department. Each day shows the status (P, A, WO, OD, ADJ, L = leave, H = holiday), first punch in, last punch out and total hours.</p>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
      <div><label class="<?= $lbl ?>">From</label><input type="date" name="from" required value="<?= date('Y-m-01') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">To</label><input type="date" name="to" required value="<?= date('Y-m-d') ?>" class="<?= ATT_FIELD ?>"></div>

      <div><div class="flex items-center justify-between"><label class="<?= $lbl ?>">Companies</label>
          <button type="button" onclick="clearTicks('companies')" class="text-[11px] text-zinc-400 hover:text-zinc-700">Clear</button></div>
        <?= $box('companies', att_companies()) ?></div>
      <div><div class="flex items-center justify-between"><label class="<?= $lbl ?>">Locations</label>
          <button type="button" onclick="clearTicks('locations')" class="text-[11px] text-zinc-400 hover:text-zinc-700">Clear</button></div>
        <?= $box('locations', att_locations()) ?></div>

      <div><label class="<?= $lbl ?>">Department (optional)</label><?= att_select('dept', array_column(all_departments(), 'name', 'id'), '', 'All departments', 'onchange="summary()"') ?></div>
      <div><label class="<?= $lbl ?>">Single employee (optional)</label>
        <select name="user" onchange="summary()" class="<?= ATT_FIELD ?>"><option value="">Everyone matching the filters</option>
          <?php foreach ($employees as $x): ?><option value="<?= $x['id'] ?>"><?= e($x['name'] . ($x['employee_id'] ? " ({$x['employee_id']})" : '')) ?></option><?php endforeach; ?></select></div>
    </div>

    <p id="sum" class="mt-4 rounded-md bg-zinc-50 px-3 py-2 text-[12px] text-zinc-600"></p>
    <button class="<?= ATT_BTN ?> mt-3">Download Excel</button>
  </form>
</div>
<script>
const form = document.getElementById('exp');
const ticked = n => [...form.querySelectorAll(`input[name="${n}[]"]:checked`)].map(i => i.value);
function clearTicks(n) { form.querySelectorAll(`input[name="${n}[]"]`).forEach(i => { i.checked = false; }); summary(); }
/* Says in plain words which employees the download will contain. */
function summary() {
  const c = ticked('companies'), l = ticked('locations');
  let t;
  if (form.user.value) {
    t = 'only ' + form.user.selectedOptions[0].text;
  } else if (!c.length && !l.length && !form.dept.value) {
    t = 'all employees';
  } else {
    t = (c.length ? 'companies: ' + c.join(', ') : 'all companies') + ' · ' + (l.length ? 'locations: ' + l.join(', ') : 'all locations');
    if (form.dept.value) t += ' · ' + form.dept.selectedOptions[0].text;
  }
  document.getElementById('sum').textContent = 'Will download ' + t + '.';
}
summary();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
