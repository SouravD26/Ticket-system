<?php
/**
 * Offboarding — the mirror of onboarding: who is leaving, when, what comes back
 * and what still has to be shut down.
 */
require_once __DIR__ . '/includes/functions.php';
require_people();
$me = user();

$departments = all_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int) post('id');

    if ($action === 'create' || $action === 'update') {
        $name = post('employee_name');
        $last = post('last_working_day');
        $mail = post('email');
        $dept = (int) post('department_id');
        $stat = post('status', 'pending');

        if ($name === '' || $last === '') {
            flash('Employee name and last working day are required.', 'error');
        } elseif ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'error');
        } elseif (!isset(OFFBOARD_STATUSES[$stat])) {
            flash('Invalid status.', 'error');
        } elseif ($dept && !q('SELECT id FROM departments WHERE id = ?', [$dept])->fetch()) {
            flash('That department no longer exists.', 'error');
        } elseif ($action === 'create') {
            q('INSERT INTO offboarding (employee_name, department_id, last_working_day, email, assets_returned, exit_notes, status, created_by)
               VALUES (?,?,?,?,?,?,?,?)',
              [$name, $dept ?: null, $last, $mail ?: null,
               post('assets_returned') ?: null, post('exit_notes') ?: null, $stat, $me['id']]);
            flash($name . ' added to offboarding and sent to the Super Admin.');
        } elseif ($id) {
            q('UPDATE offboarding SET employee_name = ?, department_id = ?, last_working_day = ?, email = ?,
                                      assets_returned = ?, exit_notes = ?, status = ?
               WHERE id = ?',
              [$name, $dept ?: null, $last, $mail ?: null,
               post('assets_returned') ?: null, post('exit_notes') ?: null, $stat, $id]);
            flash('Offboarding record updated.');
        }
    }

    if ($action === 'delete' && $id) {
        q('DELETE FROM offboarding WHERE id = ?', [$id]);
        flash('Offboarding record removed.');
    }

    redirect('offboarding.php');
}

$editing = (int) get_('edit', '0');
$adding  = get_('add') === '1';

$status = get_('status');
$where  = isset(OFFBOARD_STATUSES[$status]) ? 'WHERE o.status = ?' : '';
$args   = $where ? [$status] : [];

$rows = q("SELECT o.*, d.name AS dept_name
           FROM offboarding o
           LEFT JOIN departments d ON d.id = o.department_id
           $where
           ORDER BY o.last_working_day DESC, o.id DESC", $args)->fetchAll();

$counts   = q('SELECT status, COUNT(*) c FROM offboarding GROUP BY status')->fetchAll();
$byStatus = array_column($counts, 'c', 'status');

$pageTitle = 'Offboarding';
require __DIR__ . '/layout/header.php';

$field = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400';
$label = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';

/** The leaver form, used both for a new record and for editing one. */
$form = function (array $r, bool $isNew) use ($field, $label, $departments) { ?>
  <form method="post" class="space-y-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'update' ?>">
    <?php if (!$isNew): ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><?php endif; ?>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <div>
        <label class="<?= $label ?>">Employee name</label>
        <input name="employee_name" required value="<?= e($r['employee_name'] ?? '') ?>" placeholder="Full name" class="<?= $field ?>">
      </div>
      <div>
        <label class="<?= $label ?>">Department</label>
        <select name="department_id" class="<?= $field ?>">
          <option value="">No department</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= (int)($r['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="<?= $label ?>">Last working day</label>
        <input name="last_working_day" type="date" required value="<?= e($r['last_working_day'] ?? date('Y-m-d')) ?>" class="<?= $field ?>">
      </div>
      <div>
        <label class="<?= $label ?>">Email ID</label>
        <input name="email" type="email" value="<?= e($r['email'] ?? '') ?>" placeholder="name@company.com" class="<?= $field ?>">
      </div>
      <div>
        <label class="<?= $label ?>">Status</label>
        <select name="status" class="<?= $field ?>">
          <?php foreach (OFFBOARD_STATUSES as $k => $l): ?>
            <option value="<?= $k ?>" <?= ($r['status'] ?? 'pending') === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label class="<?= $label ?>">Assets returned</label>
        <textarea name="assets_returned" rows="3" placeholder="One per line — laptop, mouse, headset, ID card, SIM…"
                  class="<?= $field ?> resize-y"><?= e($r['assets_returned'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="<?= $label ?>">Exit notes</label>
        <textarea name="exit_notes" rows="3" placeholder="Accounts to disable, handover, final settlement…"
                  class="<?= $field ?> resize-y"><?= e($r['exit_notes'] ?? '') ?></textarea>
      </div>
    </div>

    <p class="text-[11px] text-zinc-500">Saving sends this to the Super Admin, who marks it done.</p>
    <div class="flex items-center gap-1.5">
      <button class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">
        <?= $isNew ? 'Add leaver' : 'Save' ?>
      </button>
      <a href="<?= url('offboarding.php') ?>" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] font-medium text-zinc-600 transition hover:bg-zinc-50">Cancel</a>
    </div>
  </form>
<?php };
?>
<div class="mx-auto max-w-4xl">

  <?php if ($adding): ?>
    <div class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
      <h2 class="mb-3 text-[13px] font-semibold text-zinc-900">New leaver</h2>
      <?php $form([], true); ?>
    </div>
  <?php else: ?>
    <div class="flex flex-wrap items-center gap-2">
      <a href="?add=1" class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">Add leaver</a>
      <div class="ml-auto flex flex-wrap items-center gap-1.5 text-[12px]">
        <a href="<?= url('offboarding.php') ?>"
           class="rounded-md px-2.5 py-1.5 font-medium <?= $status === '' ? 'bg-zinc-900 text-white' : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' ?>">All</a>
        <?php foreach (OFFBOARD_STATUSES as $k => $l): ?>
          <a href="?status=<?= $k ?>"
             class="rounded-md px-2.5 py-1.5 font-medium <?= $status === $k ? 'bg-zinc-900 text-white' : 'border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50' ?>">
            <?= $l ?> <span class="tabular-nums opacity-60"><?= (int)($byStatus[$k] ?? 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Leavers</h2>
      <span class="rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 tabular-nums"><?= count($rows) ?></span>
    </div>

    <?php if (!$rows): ?>
      <p class="p-10 text-center text-[13px] text-zinc-500">No offboarding records yet.</p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-100">
        <?php foreach ($rows as $r): ?>
          <li class="px-4 py-3">
            <?php if ($editing === (int) $r['id']): ?>
              <?php $form($r, false); ?>
            <?php else: ?>

              <div class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($r['employee_name']) ?></p>
                    <?= people_status_badge($r['status']) ?>
                    <?php if ($r['admin_done_at']): ?>
                      <span class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700"
                            title="Marked done by the Super Admin on <?= date('M j, Y g:i a', strtotime($r['admin_done_at'])) ?>">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Done by Super Admin
                      </span>
                    <?php else: ?>
                      <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2 py-0.5 text-[11px] font-medium text-zinc-500">
                        <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>With Super Admin
                      </span>
                    <?php endif; ?>
                  </div>
                  <p class="mt-0.5 text-[11px] text-zinc-500">
                    <?= e($r['dept_name'] ?? 'No department') ?>
                    · last day <?= date('M j, Y', strtotime($r['last_working_day'])) ?>
                    <?= $r['email'] ? ' · ' . e($r['email']) : '' ?>
                  </p>
                  <?php if ($r['admin_done_at'] && $r['admin_note']): ?>
                    <p class="mt-1 rounded-md border border-emerald-200 bg-emerald-50/60 px-2.5 py-1.5 text-[12px] text-emerald-800">
                      Super Admin: <?= e($r['admin_note']) ?>
                    </p>
                  <?php endif; ?>

                  <?php if ($r['assets_returned'] || $r['exit_notes']): ?>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                      <?php if ($r['assets_returned']): ?>
                        <div class="rounded-md border border-zinc-200 bg-zinc-50/60 px-2.5 py-2">
                          <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">Assets returned</p>
                          <p class="mt-0.5 whitespace-pre-line text-[12px] text-zinc-600"><?= e($r['assets_returned']) ?></p>
                        </div>
                      <?php endif; ?>
                      <?php if ($r['exit_notes']): ?>
                        <div class="rounded-md border border-zinc-200 bg-zinc-50/60 px-2.5 py-2">
                          <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">Exit notes</p>
                          <p class="mt-0.5 whitespace-pre-line text-[12px] text-zinc-600"><?= e($r['exit_notes']) ?></p>
                        </div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="flex shrink-0 items-center gap-1.5">
                  <a href="?edit=<?= $r['id'] ?>"
                     class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.9 3.8a2.1 2.1 0 013 3L7.5 19.2l-4 1 1-4L16.9 3.8z"/>
                    </svg>
                    Edit
                  </a>
                  <form method="post" class="inline"
                        onsubmit="return confirm('Delete the offboarding record for <?= e(addslashes($r['employee_name'])) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 px-2.5 py-1.5 text-[12px] font-medium text-rose-700 transition hover:bg-rose-50">
                      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>
                      </svg>
                      Delete
                    </button>
                  </form>
                </div>
              </div>

            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
