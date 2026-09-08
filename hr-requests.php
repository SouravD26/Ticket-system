<?php
/**
 * HR Requests — what HR has filed, waiting on the Super Admin.
 * He opens a record, reads the detail, and marks it done; HR then sees it as
 * done on their own onboarding / offboarding list.
 */
require_once __DIR__ . '/includes/functions.php';
require_super();
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id   = (int) post('id');
    $kind = post('kind') === 'offboarding' ? 'offboarding' : 'onboarding';

    if (post('action') === 'done' && $id) {
        q("UPDATE `$kind` SET admin_done_at = NOW(), admin_done_by = ?, admin_note = ?, status = 'completed'
           WHERE id = ? AND admin_done_at IS NULL", [$me['id'], post('note') ?: null, $id]);
        flash('Marked done. HR can see it on their list.');
    }

    if (post('action') === 'reopen' && $id) {
        q("UPDATE `$kind` SET admin_done_at = NULL, admin_done_by = NULL, admin_note = NULL, status = 'in_progress'
           WHERE id = ?", [$id]);
        flash('Reopened — it is back on your desk.');
    }

    redirect('hr-requests.php' . (get_('show') === 'done' ? '?show=done' : ''));
}

$done = get_('show') === 'done';
$open = (int) get_('open', '0');           // the record whose detail is expanded
$openKind = get_('kind') === 'offboarding' ? 'offboarding' : 'onboarding';

$cond = $done ? 'IS NOT NULL' : 'IS NULL';

$onboard = q("SELECT o.*, d.name AS dept_name, u.name AS filed_by
              FROM onboarding o
              LEFT JOIN departments d ON d.id = o.department_id
              LEFT JOIN users u ON u.id = o.created_by
              WHERE o.admin_done_at $cond
              ORDER BY o.join_date ASC, o.id DESC")->fetchAll();

$offboard = q("SELECT o.*, d.name AS dept_name, u.name AS filed_by
               FROM offboarding o
               LEFT JOIN departments d ON d.id = o.department_id
               LEFT JOIN users u ON u.id = o.created_by
               WHERE o.admin_done_at $cond
               ORDER BY o.last_working_day ASC, o.id DESC")->fetchAll();

$waiting = pending_people_count();

$pageTitle = 'HR Requests';
require __DIR__ . '/layout/header.php';

/**
 * One request. Collapsed it is a line; expanded it shows everything HR filled
 * in and the button that closes it off.
 */
$card = function (array $r, string $kind) use ($open, $openKind, $done) {
    $isOpen  = $open === (int) $r['id'] && $openKind === $kind;
    $isJoin  = $kind === 'onboarding';
    $date    = $isJoin ? $r['join_date'] : $r['last_working_day'];
    $dateLbl = $isJoin ? 'joins' : 'last day';
    $panels  = $isJoin
        ? ['System specification' => $r['system_spec'], 'Assets list' => $r['assets']]
        : ['Assets returned' => $r['assets_returned'], 'Exit notes' => $r['exit_notes']];
    ?>
    <li class="px-4 py-3">
      <div class="flex items-start gap-3">
        <span class="mt-1 grid h-6 w-6 shrink-0 place-items-center rounded-md border <?= $isJoin ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' ?> text-[10px] font-semibold">
          <?= $isJoin ? 'IN' : 'EX' ?>
        </span>

        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($r['employee_name']) ?></p>
            <?php if ($r['admin_done_at']): ?>
              <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-0.5 text-[11px] font-medium text-zinc-700">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Done
              </span>
            <?php endif; ?>
          </div>
          <p class="mt-0.5 text-[11px] text-zinc-500">
            <?= $isJoin ? 'Onboarding' : 'Offboarding' ?>
            · <?= e($r['dept_name'] ?? 'No department') ?>
            · <?= $dateLbl ?> <?= date('M j, Y', strtotime($date)) ?>
            <?= $r['filed_by'] ? ' · filed by ' . e($r['filed_by']) : '' ?>
          </p>

          <?php if ($isOpen): ?>
            <div class="mt-2.5 space-y-2">
              <div class="grid gap-2 sm:grid-cols-2">
                <div class="rounded-md border border-zinc-200 bg-zinc-50/60 px-2.5 py-2">
                  <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">Email ID</p>
                  <p class="mt-0.5 text-[12px] text-zinc-600"><?= e($r['email'] ?: '—') ?></p>
                </div>
                <div class="rounded-md border border-zinc-200 bg-zinc-50/60 px-2.5 py-2">
                  <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= $isJoin ? 'Date of joining' : 'Last working day' ?></p>
                  <p class="mt-0.5 text-[12px] text-zinc-600"><?= date('M j, Y', strtotime($date)) ?></p>
                </div>
                <?php foreach ($panels as $title => $body): ?>
                  <div class="rounded-md border border-zinc-200 bg-zinc-50/60 px-2.5 py-2">
                    <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400"><?= $title ?></p>
                    <p class="mt-0.5 whitespace-pre-line text-[12px] text-zinc-600"><?= e($body ?: '—') ?></p>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if ($r['admin_done_at']): ?>
                <p class="text-[11px] text-zinc-500">
                  Marked done <?= date('M j, Y g:i a', strtotime($r['admin_done_at'])) ?>
                  <?= $r['admin_note'] ? ' — ' . e($r['admin_note']) : '' ?>
                </p>
                <form method="post" class="flex items-center gap-1.5">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="reopen">
                  <input type="hidden" name="kind" value="<?= $kind ?>">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="rounded-md border border-zinc-200 px-3 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">Reopen</button>
                </form>
              <?php else: ?>
                <form method="post" class="flex flex-wrap items-center gap-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="done">
                  <input type="hidden" name="kind" value="<?= $kind ?>">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <input name="note" placeholder="Note back to HR (optional)"
                         class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-[12px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400">
                  <button class="rounded-md bg-brand-500 px-3 py-1.5 text-[12px] font-medium text-white shadow-sm transition hover:bg-brand-600">Mark done</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <a href="<?= $isOpen ? '?' . ($done ? 'show=done' : '') : '?open=' . $r['id'] . '&kind=' . $kind . ($done ? '&show=done' : '') ?>"
           class="shrink-0 rounded-md border border-zinc-200 px-2.5 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">
          <?= $isOpen ? 'Close' : 'Open' ?>
        </a>
      </div>
    </li>
<?php };
?>
<div class="mx-auto max-w-4xl">

  <div class="flex flex-wrap items-center gap-2">
    <p class="text-[13px] text-zinc-600">
      <?php if ($waiting): ?>
        <span class="font-semibold text-zinc-900"><?= $waiting ?></span> request<?= $waiting === 1 ? '' : 's' ?> waiting on you
      <?php else: ?>
        Nothing waiting on you.
      <?php endif; ?>
    </p>
    <div class="ml-auto flex items-center gap-1.5 text-[12px]">
      <a href="<?= url('hr-requests.php') ?>"
         class="rounded-md px-2.5 py-1.5 font-medium <?= !$done ? 'bg-zinc-900 text-white' : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' ?>">Waiting</a>
      <a href="?show=done"
         class="rounded-md px-2.5 py-1.5 font-medium <?= $done ? 'bg-zinc-900 text-white' : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' ?>">Done</a>
    </div>
  </div>

  <?php foreach ([['Onboarding', $onboard, 'onboarding'], ['Offboarding', $offboard, 'offboarding']] as [$title, $rows, $kind]): ?>
    <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
        <h2 class="text-[13px] font-semibold text-zinc-900"><?= $title ?></h2>
        <span class="rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 tabular-nums"><?= count($rows) ?></span>
      </div>
      <?php if (!$rows): ?>
        <p class="p-8 text-center text-[13px] text-zinc-500">
          <?= $done ? 'Nothing marked done yet.' : 'Nothing from HR waiting here.' ?>
        </p>
      <?php else: ?>
        <ul class="divide-y divide-zinc-100">
          <?php foreach ($rows as $r) { $card($r, $kind); } ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
