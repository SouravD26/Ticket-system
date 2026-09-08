<?php
/**
 * Daily task sheet — employees and IT log what they worked on each day.
 * Super Admin and Admin do not keep one; they read everyone's in reports.php.
 *
 * The page works around one selected date. Quick Add drops a task straight into the
 * day's list as a draft (held in the browser so it survives a refresh), and
 * "Submit All Tasks" writes the drafts to the database in one go. Anything already
 * saved is edited in place from the same list.
 */
require_once __DIR__ . '/includes/functions.php';
// The Super Admin keeps no sheet of his own — he reads everyone else's.
if (is_super()) { flash('Super Admins read the task sheets in Reports.', 'error'); redirect('reports.php'); }
require_can('can_fill_tasks', 'the daily task sheet');
$me = user();

/** Clamp a posted hours value to something sane. */
function task_hours($v): float { return max(0, min(24, round((float) $v, 2))); }

/** The date the whole page works against. */
$day = get_('day');
if (!$day || !strtotime($day) || $day > date('Y-m-d')) $day = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $back   = 'tasks.php?day=' . urlencode(post('day') ?: $day);

    if ($action === 'delete') {
        q('DELETE FROM daily_tasks WHERE id = ? AND user_id = ?', [(int) post('id'), $me['id']]);
        flash('Task removed.');
        redirect($back);
    }

    /* Status changed straight from the row, without opening the editor. */
    if ($action === 'status') {
        $status = post('status');
        if (isset(TASK_STATUSES[$status])) {
            q('UPDATE daily_tasks SET status = ? WHERE id = ? AND user_id = ?',
              [$status, (int) post('id'), $me['id']]);
            flash('Status updated.');
        }
        redirect($back);
    }

    /* Inline edit of a task that is already saved. */
    if ($action === 'edit') {
        $title  = post('title');
        $status = post('status');
        $date   = post('task_date') ?: $day;

        if (mb_strlen($title) < 3) {
            flash('Give the task a title of at least 3 characters.', 'error');
        } elseif (!isset(TASK_STATUSES[$status])) {
            flash('Invalid status.', 'error');
        } elseif (!strtotime($date) || $date > date('Y-m-d')) {
            flash('Pick a valid date — future dates are not allowed.', 'error');
        } else {
            q('UPDATE daily_tasks SET title = ?, description = ?, hours = ?, status = ?, category = ?, task_date = ?
               WHERE id = ? AND user_id = ?',
              [$title, post('description') ?: null, task_hours(post('hours', '0')), $status,
               post('category') ?: null, $date, (int) post('id'), $me['id']]);
            flash('Task updated.');
        }
        redirect($back);
    }

    /* "Submit All Tasks" — the whole draft batch in one request. */
    $rows = $_POST['entries'] ?? null;
    if (!is_array($rows) || !$rows) {
        $rows = [[
            'task_date' => post('task_date') ?: $day,
            'title'     => post('title'),
            'hours'     => post('hours', '0'),
            'status'    => post('status', 'completed'),
        ]];
    }

    $saved  = 0;
    $errors = [];
    foreach ($rows as $i => $r) {
        $label  = 'Task ' . ($i + 1) . ': ';
        $title  = trim((string) ($r['title'] ?? ''));
        $desc   = trim((string) ($r['description'] ?? ''));
        $status = trim((string) ($r['status'] ?? 'completed'));
        $cat    = trim((string) ($r['category'] ?? ''));
        $date   = trim((string) ($r['task_date'] ?? '')) ?: $day;

        if ($title === '' && $desc === '') continue;          // nothing typed, nothing to save

        if (mb_strlen($title) < 3) {
            $errors[] = $label . 'give it a title of at least 3 characters.';
        } elseif (!isset(TASK_STATUSES[$status])) {
            $errors[] = $label . 'invalid status.';
        } elseif (!strtotime($date) || $date > date('Y-m-d')) {
            $errors[] = $label . 'pick a valid date — future dates are not allowed.';
        } else {
            q('INSERT INTO daily_tasks (user_id, task_date, title, description, hours, status, category)
               VALUES (?,?,?,?,?,?,?)',
              [$me['id'], $date, $title, $desc ?: null, task_hours($r['hours'] ?? 0), $status,
               mb_substr($cat, 0, 60) ?: null]);
            $saved++;
        }
    }

    if ($saved) flash($saved . ' task' . ($saved === 1 ? '' : 's') . ' submitted.');
    foreach ($errors as $er) flash($er, 'error');
    if (!$saved && !$errors) flash('Nothing to submit — add a task first.', 'error');
    redirect($back);
}

/* ---------- the selected day ---------- */
$todays = q('SELECT d.*, t.code AS ticket_code
             FROM daily_tasks d
             LEFT JOIN tickets t ON t.id = d.ticket_id
             WHERE d.user_id = ? AND d.task_date = ?
             ORDER BY d.id DESC', [$me['id'], $day])->fetchAll();

/* ---------- the history strip underneath ---------- */
$from = get_('from') ?: date('Y-m-d', strtotime($day . ' -13 days'));
$to   = get_('to')   ?: $day;
if (!strtotime($from)) $from = date('Y-m-d', strtotime('-13 days'));
if (!strtotime($to))   $to   = date('Y-m-d');

$history = q('SELECT d.*, t.code AS ticket_code
              FROM daily_tasks d
              LEFT JOIN tickets t ON t.id = d.ticket_id
              WHERE d.user_id = ? AND d.task_date BETWEEN ? AND ?
              ORDER BY d.task_date DESC, d.id DESC', [$me['id'], $from, $to])->fetchAll();

$histCount = count($history);
$histHours = array_sum(array_map('floatval', array_column($history, 'hours')));
$histByDay = [];
foreach ($history as $h) { $histByDay[$h['task_date']][] = $h; }

$isToday  = $day === date('Y-m-d');
$dayLabel = $isToday ? 'Today · ' . date('D, M j, Y') : date('D, M j, Y', strtotime($day));

$pageTitle = 'Daily Tasks';
require __DIR__ . '/layout/header.php';

/** The coloured dot used in rows, dropdowns and the history strip. */
function status_dot(string $s): string
{
    $c = TASK_STATUS_STYLES[$s]['dot'] ?? 'bg-slate-400';
    return '<span class="inline-block h-2 w-2 shrink-0 rounded-full ' . $c . '"></span>';
}
?>

<!-- 1. PAGE HEADER -->
<div class="flex flex-wrap items-end justify-between gap-4">
  <div>
    <h2 class="text-xl font-semibold text-slate-900">Daily Tasks</h2>
    <p class="mt-0.5 text-sm text-slate-500">Quickly log what you worked on today.</p>
  </div>
  <div class="flex items-center gap-2">
    <form method="get">
      <label class="sr-only" for="dayPick">Date</label>
      <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
        <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 21h14a1 1 0 001-1V7a1 1 0 00-1-1H5a1 1 0 00-1 1v13a1 1 0 001 1z"/>
        </svg>
        <input id="dayPick" type="date" name="day" value="<?= e($day) ?>" max="<?= date('Y-m-d') ?>"
               onchange="this.form.submit()" class="bg-transparent text-sm text-slate-800 outline-none">
      </div>
    </form>
    <button type="button" id="jumpAdd"
            class="rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-600">
      + Add Task
    </button>
  </div>
</div>

<!-- 2. QUICK ADD -->
<div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
  <h3 class="text-sm font-semibold text-slate-900">Quick Add Task</h3>
  <label for="qaTitle" class="mt-3 block text-xs text-slate-500">What did you work on?</label>

  <div class="mt-1.5 flex flex-wrap items-center gap-2">
    <input id="qaTitle" type="text" placeholder="e.g. Fixed login issue" autocomplete="off"
           class="min-w-[16rem] flex-1 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">

    <!-- 9. HOURS -->
    <select id="qaHours" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-400">
      <?php foreach (TASK_HOUR_STEPS as $h): ?>
        <option value="<?= $h ?>" <?= $h === '0.5' ? 'selected' : '' ?>><?= $h ?> hr</option>
      <?php endforeach; ?>
      <option value="custom">Custom…</option>
    </select>
    <input id="qaHoursCustom" type="number" step="0.25" min="0" max="24" placeholder="hrs"
           class="hidden w-24 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:border-brand-400">

    <!-- 10. STATUS -->
    <select id="qaStatus" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-brand-400">
      <?php foreach (TASK_STATUSES as $k => $l): ?>
        <option value="<?= $k ?>"><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>

    <button type="button" id="qaAdd"
            class="rounded-xl bg-brand-500 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">
      Add Task
    </button>
  </div>

  <!-- 7. details stay optional, behind a toggle -->
  <button type="button" id="qaMore" class="mt-3 text-xs font-medium text-brand-600 hover:text-brand-700">+ Add details</button>
  <div id="qaMoreBox" class="mt-2 hidden">
    <textarea id="qaDetails" rows="3" placeholder="Anything worth recording (optional)"
              class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"></textarea>
    <input id="qaCategory" type="text" placeholder="Category (optional)"
           class="mt-2 w-56 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
  </div>
</div>

<!-- 4. SUMMARY CARDS -->
<div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
  <?php
  $cards = [
    ['completed',   'Completed',   'text-emerald-600', 'M5 13l4 4L19 7'],
    ['in_progress', 'In Progress', 'text-sky-600',     'M12 8v4l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['pending',     'Pending',     'text-amber-600',   'M12 8v5M12 16h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
  ];
  foreach ($cards as [$key, $label, $tint, $path]): ?>
    <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
      <div class="flex items-center gap-2">
        <svg class="h-4 w-4 <?= $tint ?>" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="<?= $path ?>"/>
        </svg>
        <p class="text-xs font-medium text-slate-500"><?= e($label) ?></p>
      </div>
      <p class="mt-1 text-2xl font-semibold text-slate-900" data-count="<?= $key ?>">0</p>
      <p class="text-[11px] text-slate-400"><?= e($label) ?> Tasks</p>
    </div>
  <?php endforeach; ?>
  <div class="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
    <div class="flex items-center gap-2">
      <svg class="h-4 w-4 text-brand-500" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      <p class="text-xs font-medium text-slate-500">Total Logged</p>
    </div>
    <p class="mt-1 text-2xl font-semibold text-slate-900"><span data-count="hours">0</span> hrs</p>
    <p class="text-[11px] text-slate-400">Total Logged Hours</p>
  </div>
</div>

<!-- 5. TODAY'S TASKS -->
<form method="post" id="submitForm" class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm">
  <?= csrf_field() ?>
  <input type="hidden" name="day" value="<?= e($day) ?>">
  <div id="draftFields"></div>

  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5">
    <h3 class="flex flex-wrap items-center gap-2 text-sm font-semibold text-slate-900">
      Daily Tasks
      <span class="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-600 ring-1 ring-inset ring-brand-500/20">
        <?= e($dayLabel) ?>
      </span>
      <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600" data-count="all">0</span>
    </h3>

    <div class="flex flex-wrap items-center gap-2">
      <!-- exports for the day on screen, not the history range -->
      <?php $dayDl = http_build_query(['from' => $day, 'to' => $day]); ?>
      <a href="<?= url('download-tasks.php?format=csv&' . $dayDl) ?>"
         title="Download <?= e($dayLabel) ?> as a CSV spreadsheet"
         class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>
        </svg>
        Excel (CSV)
      </a>
      <a href="<?= url('download-tasks.php?format=pdf&' . $dayDl) ?>"
         title="Download <?= e($dayLabel) ?> as a PDF"
         class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 transition hover:bg-slate-100">
        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>
        </svg>
        PDF
      </a>

      <label class="flex items-center gap-2 text-xs text-slate-500">
        Sort by:
        <select id="sortBy" class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 outline-none focus:border-brand-400">
          <option value="latest">Latest</option>
          <option value="oldest">Oldest</option>
          <option value="hours">Hours</option>
          <option value="status">Status</option>
        </select>
      </label>
    </div>
  </div>

  <ul id="taskList" class="divide-y divide-slate-200">
    <?php foreach ($todays as $t): $st = TASK_STATUS_STYLES[$t['status']] ?? TASK_STATUS_STYLES['pending']; ?>
      <li data-row data-id="<?= $t['id'] ?>" data-status="<?= e($t['status']) ?>"
          data-hours="<?= (float) $t['hours'] ?>" data-seq="<?= $t['id'] ?>">

        <div class="flex flex-wrap items-center gap-3 px-5 py-3">
          <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full <?= explode(' ', $st['chip'])[0] ?>">
            <?= status_dot($t['status']) ?>
          </span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-slate-900"><?= e($t['title']) ?></p>
            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">
              <?php if ($t['category']): ?>
                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600"><?= e($t['category']) ?></span>
              <?php endif; ?>
              <?php if ($t['ticket_code']): ?>
                <a href="<?= url('ticket-view.php?id=' . (int) $t['ticket_id']) ?>"
                   class="rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-semibold text-brand-600 ring-1 ring-inset ring-brand-500/20">From ticket</a>
              <?php endif; ?>
              <?php if ($t['description']): ?><span class="truncate"><?= e($t['description']) ?></span><?php endif; ?>
            </p>
          </div>

          <span class="shrink-0 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700"><?= (float) $t['hours'] ?> hr</span>

          <span class="shrink-0">
            <select data-status-select
                    class="rounded-lg border px-2 py-1.5 text-xs font-medium outline-none focus:border-brand-400 <?= $st['chip'] ?>">
              <?php foreach (TASK_STATUSES as $k => $l): ?>
                <option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </span>

          <div class="relative shrink-0">
            <button type="button" data-menu-btn title="More"
                    class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700">
              <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>
            </button>
            <div data-menu class="absolute right-0 z-30 mt-1 hidden w-40 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
              <button type="button" data-act="edit"    class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Edit</button>
              <button type="button" data-act="details" class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Add Details</button>
              <button type="button" data-act="dupe"    class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Duplicate</button>
              <button type="button" data-act="del"     class="block w-full px-3 py-2 text-left text-xs text-rose-700 hover:bg-rose-50">Delete</button>
            </div>
          </div>
        </div>

        <!-- 6. INLINE EDITING -->
        <div data-edit class="hidden border-t border-slate-200 bg-slate-50/70 px-5 py-4">
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs text-slate-500">Task</label>
              <input data-f="title" value="<?= e($t['title']) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="sm:col-span-2">
              <label class="mb-1 block text-xs text-slate-500">Details</label>
              <textarea data-f="description" rows="2" class="w-full resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400"><?= e($t['description'] ?? '') ?></textarea>
            </div>
            <div>
              <label class="mb-1 block text-xs text-slate-500">Category</label>
              <input data-f="category" value="<?= e($t['category'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div>
              <label class="mb-1 block text-xs text-slate-500">Date</label>
              <input data-f="task_date" type="date" max="<?= date('Y-m-d') ?>" value="<?= e($t['task_date']) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div>
              <label class="mb-1 block text-xs text-slate-500">Hours</label>
              <input data-f="hours" type="number" step="0.25" min="0" max="24" value="<?= (float) $t['hours'] ?>" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div>
              <label class="mb-1 block text-xs text-slate-500">Status</label>
              <select data-f="status" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
                <?php foreach (TASK_STATUSES as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mt-3 flex gap-2">
            <button type="button" data-act="save"   class="rounded-lg bg-brand-500 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-600">Save</button>
            <button type="button" data-act="cancel" class="rounded-lg border border-slate-200 px-4 py-2 text-xs text-slate-600 hover:bg-slate-100">Cancel</button>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>

  <!-- 14. EMPTY STATE -->
  <div id="emptyState" class="hidden px-5 py-12 text-center">
    <p class="text-sm font-medium text-slate-700">No tasks logged yet</p>
    <p class="mt-1 text-sm text-slate-500">Add your first task above. It only takes a few seconds.</p>
    <button type="button" id="emptyAdd" class="mt-4 rounded-xl bg-brand-500 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-600">+ Add Task</button>
  </div>

  <!-- 11 + 12. TOTAL AND SUBMIT -->
  <div class="border-t border-slate-200 px-5 py-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-slate-600">Total Logged: <span class="font-semibold text-slate-900" data-count="hours">0</span> hrs</p>
      <p id="draftNote" class="text-xs text-slate-500">Your entries are saved and can be edited before submission.</p>
    </div>
    <button type="submit" id="submitAll" disabled
            class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
      Submit All Tasks
    </button>
  </div>
</form>

<!-- row actions that need the server; kept outside the draft form so they post alone -->
<form method="post" id="rowForm" class="hidden">
  <?= csrf_field() ?>
  <input type="hidden" name="day" value="<?= e($day) ?>">
  <input type="hidden" name="action"      id="rf_action">
  <input type="hidden" name="id"          id="rf_id">
  <input type="hidden" name="title"       id="rf_title">
  <input type="hidden" name="description" id="rf_description">
  <input type="hidden" name="category"    id="rf_category">
  <input type="hidden" name="hours"       id="rf_hours">
  <input type="hidden" name="status"      id="rf_status">
  <input type="hidden" name="task_date"   id="rf_task_date">
</form>

<!-- 13. HISTORY & EXPORT — deliberately secondary -->
<details class="mt-4 rounded-2xl border border-slate-200 bg-slate-50/60">
  <summary class="cursor-pointer list-none px-5 py-3 text-sm font-medium text-slate-600 hover:text-slate-900">
    <span class="inline-flex items-center gap-2">
      <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
      Earlier entries &amp; export
      <span class="text-xs text-slate-400"><?= (int) $histCount ?> in range · <?= (float) $histHours ?> hrs</span>
    </span>
  </summary>

  <div class="border-t border-slate-200 px-5 py-4">
    <form method="get" class="flex flex-wrap items-end gap-3">
      <input type="hidden" name="day" value="<?= e($day) ?>">
      <div>
        <label class="mb-1 block text-xs text-slate-500">From</label>
        <input name="from" type="date" value="<?= e($from) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
      </div>
      <div>
        <label class="mb-1 block text-xs text-slate-500">To</label>
        <input name="to" type="date" value="<?= e($to) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400">
      </div>
      <button class="rounded-xl bg-slate-100 px-4 py-2 text-sm text-slate-900 hover:bg-slate-200">Filter</button>
      <?php $dl = http_build_query(['from' => $from, 'to' => $to]); ?>
      <a href="<?= url('download-tasks.php?format=csv&' . $dl) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">Excel (CSV)</a>
      <a href="<?= url('download-tasks.php?format=pdf&' . $dl) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">PDF</a>
    </form>

    <div class="mt-4 space-y-3">
      <?php foreach ($histByDay as $d => $rows): ?>
        <div>
          <div class="flex items-baseline justify-between">
            <a href="?day=<?= e($d) ?>" class="text-xs font-semibold text-slate-700 hover:text-brand-600"><?= date('D, M j, Y', strtotime($d)) ?></a>
            <span class="text-[11px] text-slate-400"><?= array_sum(array_map('floatval', array_column($rows, 'hours'))) ?> hrs</span>
          </div>
          <ul class="mt-1 divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white">
            <?php foreach ($rows as $h): ?>
              <li class="flex items-center gap-3 px-3 py-2">
                <?= status_dot($h['status']) ?>
                <span class="min-w-0 flex-1 truncate text-xs text-slate-700"><?= e($h['title']) ?></span>
                <span class="shrink-0 text-[11px] text-slate-400"><?= e(TASK_STATUSES[$h['status']] ?? $h['status']) ?></span>
                <span class="shrink-0 text-[11px] text-slate-500"><?= (float) $h['hours'] ?>h</span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
      <?php if (!$histByDay): ?>
        <p class="py-6 text-center text-sm text-slate-500">No entries in this range.</p>
      <?php endif; ?>
    </div>
  </div>
</details>

<script>
(function () {
  var DAY    = <?= json_encode($day) ?>;
  var LABELS = <?= json_encode(TASK_STATUSES) ?>;
  var STYLES = <?= json_encode(TASK_STATUS_STYLES) ?>;
  var KEY    = 'tasks.drafts.' + <?= json_encode((string) $me['id']) ?> + '.' + DAY;

  var list    = document.getElementById('taskList');
  var empty   = document.getElementById('emptyState');
  var fields  = document.getElementById('draftFields');
  var submit  = document.getElementById('submitAll');
  var note    = document.getElementById('draftNote');
  var rowForm = document.getElementById('rowForm');

  var drafts = [];
  try { drafts = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { drafts = []; }

  function persist() { try { localStorage.setItem(KEY, JSON.stringify(drafts)); } catch (e) {} }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }
  function opts(sel) {
    return Object.keys(LABELS).map(function (k) {
      return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(LABELS[k]) + '</option>';
    }).join('');
  }
  var INP = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-400';

  /* ---------- a draft row, drawn to match the saved ones ---------- */
  function draftRow(d, i) {
    var st = STYLES[d.status] || STYLES.pending;
    var li = document.createElement('li');
    li.setAttribute('data-row', '');
    li.dataset.draft = '1';
    li.dataset.index = i;
    li.dataset.status = d.status;
    li.dataset.hours = d.hours;
    li.dataset.seq = 1000000 + i;
    li.className = 'bg-brand-50/40';
    li.innerHTML =
      '<div class="flex flex-wrap items-center gap-3 px-5 py-3">' +
        '<span class="grid h-7 w-7 shrink-0 place-items-center rounded-full ' + st.chip.split(' ')[0] + '">' +
          '<span class="inline-block h-2 w-2 rounded-full ' + st.dot + '"></span></span>' +
        '<div class="min-w-0 flex-1">' +
          '<p class="truncate text-sm font-medium text-slate-900">' + esc(d.title) +
            ' <span class="rounded bg-amber-100 px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase tracking-wide text-amber-800">Draft</span></p>' +
          '<p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-slate-500">' +
            (d.category ? '<span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">' + esc(d.category) + '</span>' : '') +
            (d.description ? '<span class="truncate">' + esc(d.description) + '</span>' : '') +
          '</p>' +
        '</div>' +
        '<span class="shrink-0 rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">' + esc(d.hours) + ' hr</span>' +
        '<span class="shrink-0"><select data-draft-status class="rounded-lg border px-2 py-1.5 text-xs font-medium outline-none ' + st.chip + '">' +
          opts(d.status) + '</select></span>' +
        '<div class="relative shrink-0">' +
          '<button type="button" data-menu-btn class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700">' +
            '<svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></button>' +
          '<div data-menu class="absolute right-0 z-30 mt-1 hidden w-40 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">' +
            '<button type="button" data-act="edit" class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Edit</button>' +
            '<button type="button" data-act="details" class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Add Details</button>' +
            '<button type="button" data-act="dupe" class="block w-full px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50">Duplicate</button>' +
            '<button type="button" data-act="del" class="block w-full px-3 py-2 text-left text-xs text-rose-700 hover:bg-rose-50">Delete</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div data-edit class="hidden border-t border-slate-200 bg-slate-50/70 px-5 py-4">' +
        '<div class="grid gap-3 sm:grid-cols-2">' +
          '<div class="sm:col-span-2"><label class="mb-1 block text-xs text-slate-500">Task</label>' +
            '<input data-f="title" value="' + esc(d.title) + '" class="' + INP + '"></div>' +
          '<div class="sm:col-span-2"><label class="mb-1 block text-xs text-slate-500">Details</label>' +
            '<textarea data-f="description" rows="2" class="' + INP + ' resize-y">' + esc(d.description) + '</textarea></div>' +
          '<div><label class="mb-1 block text-xs text-slate-500">Category</label>' +
            '<input data-f="category" value="' + esc(d.category) + '" class="' + INP + '"></div>' +
          '<div><label class="mb-1 block text-xs text-slate-500">Date</label>' +
            '<input data-f="task_date" type="date" max="' + DAY + '" value="' + esc(d.task_date) + '" class="' + INP + '"></div>' +
          '<div><label class="mb-1 block text-xs text-slate-500">Hours</label>' +
            '<input data-f="hours" type="number" step="0.25" min="0" max="24" value="' + esc(d.hours) + '" class="' + INP + '"></div>' +
          '<div><label class="mb-1 block text-xs text-slate-500">Status</label>' +
            '<select data-f="status" class="' + INP + '">' + opts(d.status) + '</select></div>' +
        '</div>' +
        '<div class="mt-3 flex gap-2">' +
          '<button type="button" data-act="save" class="rounded-lg bg-brand-500 px-4 py-2 text-xs font-semibold text-white hover:bg-brand-600">Save</button>' +
          '<button type="button" data-act="cancel" class="rounded-lg border border-slate-200 px-4 py-2 text-xs text-slate-600 hover:bg-slate-100">Cancel</button>' +
        '</div>' +
      '</div>';
    return li;
  }

  function renderDrafts() {
    list.querySelectorAll('[data-draft]').forEach(function (n) { n.remove(); });
    drafts.forEach(function (d, i) { list.insertBefore(draftRow(d, i), list.firstChild); });

    // Mirror the drafts into hidden inputs so one submit posts the whole batch.
    fields.innerHTML = drafts.map(function (d, i) {
      return ['title', 'description', 'hours', 'status', 'category', 'task_date'].map(function (k) {
        return '<input type="hidden" name="entries[' + i + '][' + k + ']" value="' + esc(d[k]) + '">';
      }).join('');
    }).join('');

    submit.disabled = drafts.length === 0;
    note.textContent = drafts.length
      ? drafts.length + ' draft' + (drafts.length === 1 ? '' : 's') + ' ready — edit them before submitting.'
      : 'Your entries are saved and can be edited before submission.';
    sortRows();
    recount();
  }

  /* ---------- 4 + 11: counters ---------- */
  function recount() {
    var rows = list.querySelectorAll('[data-row]');
    var by = { completed: 0, in_progress: 0, pending: 0, blocked: 0 }, hours = 0;
    rows.forEach(function (r) {
      by[r.dataset.status] = (by[r.dataset.status] || 0) + 1;
      hours += parseFloat(r.dataset.hours) || 0;
    });
    document.querySelectorAll('[data-count]').forEach(function (el) {
      var k = el.dataset.count;
      if (k === 'hours')    el.textContent = (Math.round(hours * 100) / 100).toFixed(1);
      else if (k === 'all') el.textContent = rows.length;
      else                  el.textContent = by[k] || 0;
    });
    empty.classList.toggle('hidden', rows.length > 0);
  }

  /* ---------- 5: sorting ---------- */
  function sortRows() {
    var mode = document.getElementById('sortBy').value;
    var order = ['completed', 'in_progress', 'pending', 'blocked'];
    [].slice.call(list.querySelectorAll('[data-row]')).sort(function (a, b) {
      if (mode === 'hours')  return parseFloat(b.dataset.hours) - parseFloat(a.dataset.hours);
      if (mode === 'status') return order.indexOf(a.dataset.status) - order.indexOf(b.dataset.status);
      var d = parseInt(b.dataset.seq, 10) - parseInt(a.dataset.seq, 10);
      return mode === 'oldest' ? -d : d;
    }).forEach(function (r) { list.appendChild(r); });
  }
  document.getElementById('sortBy').addEventListener('change', sortRows);

  /* ---------- 2 + 9: quick add ---------- */
  var qaTitle    = document.getElementById('qaTitle'),
      qaHours    = document.getElementById('qaHours'),
      qaCustom   = document.getElementById('qaHoursCustom'),
      qaStatus   = document.getElementById('qaStatus'),
      qaDetails  = document.getElementById('qaDetails'),
      qaCategory = document.getElementById('qaCategory'),
      qaMoreBox  = document.getElementById('qaMoreBox');

  qaHours.addEventListener('change', function () {
    var custom = qaHours.value === 'custom';
    qaCustom.classList.toggle('hidden', !custom);
    if (custom) qaCustom.focus();
  });

  document.getElementById('qaMore').addEventListener('click', function () {
    qaMoreBox.classList.toggle('hidden');
    if (!qaMoreBox.classList.contains('hidden')) qaDetails.focus();
  });

  function hoursValue() {
    var v = parseFloat(qaHours.value === 'custom' ? qaCustom.value : qaHours.value);
    return isNaN(v) || v < 0 ? 0 : Math.min(24, v);
  }

  function addTask() {
    var title = qaTitle.value.trim();
    if (title.length < 3) { qaTitle.classList.add('border-rose-400'); qaTitle.focus(); return; }
    qaTitle.classList.remove('border-rose-400');
    drafts.push({
      title: title,
      description: (qaDetails.value || '').trim(),
      category: (qaCategory.value || '').trim(),
      hours: hoursValue(),
      status: qaStatus.value,
      task_date: DAY
    });
    persist();
    renderDrafts();
    qaTitle.value = ''; qaDetails.value = ''; qaCategory.value = '';
    qaMoreBox.classList.add('hidden');
    qaTitle.focus();
  }

  document.getElementById('qaAdd').addEventListener('click', addTask);
  qaTitle.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') { ev.preventDefault(); addTask(); }
  });

  function focusAdd() { qaTitle.focus(); qaTitle.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  document.getElementById('jumpAdd').addEventListener('click', focusAdd);
  document.getElementById('emptyAdd').addEventListener('click', focusAdd);

  /* ---------- saved rows go through the hidden form ---------- */
  function send(action, row) {
    document.getElementById('rf_action').value = action;
    document.getElementById('rf_id').value = row.dataset.id || '';
    if (action === 'edit') {
      ['title', 'description', 'category', 'hours', 'status', 'task_date'].forEach(function (f) {
        var el = row.querySelector('[data-f="' + f + '"]');
        document.getElementById('rf_' + f).value = el ? el.value : '';
      });
    }
    if (action === 'status') {
      document.getElementById('rf_status').value = row.querySelector('[data-status-select]').value;
    }
    rowForm.submit();
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-menu-btn]');
    document.querySelectorAll('[data-menu]').forEach(function (m) {
      if (!btn || m !== btn.nextElementSibling) m.classList.add('hidden');
    });
    if (btn) { btn.nextElementSibling.classList.toggle('hidden'); return; }

    var act = ev.target.closest('[data-act]');
    if (!act) return;
    var row = act.closest('[data-row]');
    var kind = act.dataset.act;
    var isDraft = row.dataset.draft === '1';
    var idx = parseInt(row.dataset.index, 10);

    if (kind === 'edit' || kind === 'details') {
      var menu = row.querySelector('[data-menu]');
      if (menu) menu.classList.add('hidden');
      row.querySelector('[data-edit]').classList.remove('hidden');
      var f = row.querySelector('[data-f="' + (kind === 'details' ? 'description' : 'title') + '"]');
      if (f) f.focus();
      return;
    }

    if (kind === 'cancel') { row.querySelector('[data-edit]').classList.add('hidden'); return; }

    if (kind === 'dupe') {
      var src = isDraft ? drafts[idx] : {
        title:       row.querySelector('[data-f="title"]').value,
        description: row.querySelector('[data-f="description"]').value,
        category:    row.querySelector('[data-f="category"]').value,
        hours:       row.querySelector('[data-f="hours"]').value,
        status:      row.querySelector('[data-f="status"]').value
      };
      drafts.push({
        title: src.title, description: src.description || '', category: src.category || '',
        hours: parseFloat(src.hours) || 0, status: src.status, task_date: DAY
      });
      persist(); renderDrafts();
      return;
    }

    if (kind === 'del') {
      if (isDraft) { drafts.splice(idx, 1); persist(); renderDrafts(); return; }
      if (confirm('Delete this task?')) send('delete', row);
      return;
    }

    if (kind === 'save') {
      if (!isDraft) { send('edit', row); return; }
      var d = drafts[idx];
      var t = row.querySelector('[data-f="title"]').value.trim();
      if (t.length < 3) { row.querySelector('[data-f="title"]').focus(); return; }
      d.title       = t;
      d.description = row.querySelector('[data-f="description"]').value.trim();
      d.category    = row.querySelector('[data-f="category"]').value.trim();
      d.hours       = parseFloat(row.querySelector('[data-f="hours"]').value) || 0;
      d.status      = row.querySelector('[data-f="status"]').value;
      d.task_date   = row.querySelector('[data-f="task_date"]').value || DAY;
      persist(); renderDrafts();
    }
  });

  list.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-status-select]')) {
      send('status', ev.target.closest('[data-row]'));
    } else if (ev.target.matches('[data-draft-status]')) {
      var row = ev.target.closest('[data-row]');
      drafts[parseInt(row.dataset.index, 10)].status = ev.target.value;
      persist(); renderDrafts();
    }
  });

  // Once the server has them, the browser copy must go.
  document.getElementById('submitForm').addEventListener('submit', function () {
    try { localStorage.removeItem(KEY); } catch (e) {}
  });

  renderDrafts();
})();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
