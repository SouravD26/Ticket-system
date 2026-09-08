<?php
require_once __DIR__ . '/includes/functions.php';
require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int) post('id');
    $name   = post('name');

    if ($action === 'create') {
        if ($name === '') {
            flash('Department name is required.', 'error');
        } elseif (q('SELECT id FROM departments WHERE name = ?', [$name])->fetch()) {
            flash('That department already exists.', 'error');
        } else {
            q('INSERT INTO departments (name) VALUES (?)', [$name]);
            flash('Department added.');
        }
    }

    if ($action === 'update' && $id) {
        if ($name === '') {
            flash('Department name is required.', 'error');
        } elseif (q('SELECT id FROM departments WHERE name = ? AND id <> ?', [$name, $id])->fetch()) {
            flash('Another department already uses that name.', 'error');
        } else {
            q('UPDATE departments SET name = ? WHERE id = ?', [$name, $id]);
            flash('Department renamed.');
        }
    }

    if ($action === 'delete' && $id) {
        q('DELETE FROM departments WHERE id = ?', [$id]);
        flash('Department removed. Its tickets are now unassigned.');
    }

    redirect('departments.php');
}

// One row is put into edit mode at a time, by ?edit=<id>.
$editing = (int) get_('edit', '0');

$departments = q('SELECT d.id, d.name,
                         (SELECT COUNT(*) FROM tickets t WHERE t.department_id = d.id) AS ticket_count
                  FROM departments d ORDER BY d.name')->fetchAll();

$pageTitle = 'Departments';
require __DIR__ . '/layout/header.php';
?>
<div class="mx-auto max-w-3xl">

  <!-- Add: a name is all a department is. -->
  <form method="post" class="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-white p-3 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <input name="name" required placeholder="New department name…" autocomplete="off"
           class="min-w-0 flex-1 rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400">
    <button class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">Add</button>
  </form>

  <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Departments</h2>
      <span class="rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 tabular-nums"><?= count($departments) ?></span>
    </div>

    <?php if (!$departments): ?>
      <p class="p-10 text-center text-[13px] text-zinc-500">No departments yet. Add the first one above.</p>
    <?php else: ?>
      <ul class="divide-y divide-zinc-100">
        <?php foreach ($departments as $d): ?>
          <li class="px-4 py-2.5">
            <?php if ($editing === (int) $d['id']): ?>

              <!-- Edit mode: the same row, with the name made writable. -->
              <form method="post" class="flex flex-wrap items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input name="name" value="<?= e($d['name']) ?>" required autofocus
                       class="min-w-0 flex-1 rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-[13px] font-medium text-zinc-900 outline-none focus:border-brand-400">
                <button class="rounded-md bg-brand-500 px-3 py-1.5 text-[12px] font-medium text-white shadow-sm transition hover:bg-brand-600">Save</button>
                <a href="<?= url('departments.php') ?>" class="rounded-md border border-zinc-200 px-3 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">Cancel</a>
              </form>

            <?php else: ?>

              <div class="flex items-center gap-3">
                <p class="min-w-0 flex-1 truncate text-[13px] font-medium text-zinc-900"><?= e($d['name']) ?></p>

                <div class="flex shrink-0 items-center gap-1.5">
                  <a href="?edit=<?= $d['id'] ?>"
                     class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.9 3.8a2.1 2.1 0 013 3L7.5 19.2l-4 1 1-4L16.9 3.8z"/>
                    </svg>
                    Edit
                  </a>

                  <form method="post" class="inline"
                        onsubmit="return confirm('Delete <?= e(addslashes($d['name'])) ?>?<?= (int) $d['ticket_count'] ? ' Its ' . (int) $d['ticket_count'] . ' ticket(s) will lose their department.' : '' ?>');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
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
