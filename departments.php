<?php
require_once __DIR__ . '/includes/functions.php';
require_super();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int) post('id');
    $name   = post('name');
    $desc   = post('description');

    if ($action === 'create') {
        if ($name === '') {
            flash('Department name is required.', 'error');
        } elseif (q('SELECT id FROM departments WHERE name = ?', [$name])->fetch()) {
            flash('That department already exists.', 'error');
        } else {
            q('INSERT INTO departments (name, description) VALUES (?,?)', [$name, $desc ?: null]);
            flash('Department added.');
        }
    }

    if ($action === 'update' && $id && $name !== '') {
        q('UPDATE departments SET name = ?, description = ? WHERE id = ?', [$name, $desc ?: null, $id]);
        flash('Department updated.');
    }

    if ($action === 'delete' && $id) {
        q('DELETE FROM departments WHERE id = ?', [$id]);
        flash('Department removed. Its tickets are now unassigned.');
    }

    redirect('departments.php');
}

$departments = q('SELECT d.*, (SELECT COUNT(*) FROM tickets t WHERE t.department_id = d.id) AS ticket_count
                  FROM departments d ORDER BY d.name')->fetchAll();

$pageTitle = 'Departments';
require __DIR__ . '/layout/header.php';
?>
<div class="grid gap-4 lg:grid-cols-3">
  <form method="post" class="h-fit rounded-2xl border border-slate-200 bg-white shadow-sm p-5 lg:order-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <h3 class="text-sm font-semibold text-slate-900">Add a department</h3>
    <div class="mt-4 space-y-3">
      <input name="name" required placeholder="e.g. Billing" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <textarea name="description" rows="3" placeholder="What this queue handles (optional)" class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500"></textarea>
      <button class="w-full rounded-xl bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Add department</button>
    </div>
  </form>

  <div class="space-y-3 lg:col-span-2">
    <?php if (!$departments): ?>
      <p class="rounded-2xl border border-slate-200 bg-white shadow-sm p-10 text-center text-slate-500">No departments yet.</p>
    <?php endif; ?>
    <?php foreach ($departments as $d): ?>
      <form method="post" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $d['id'] ?>">
        <div class="flex items-center justify-between gap-3">
          <span class="rounded-full bg-teal-50 px-2.5 py-1 text-xs text-teal-700 ring-1 ring-inset ring-teal-600/25"><?= (int)$d['ticket_count'] ?> tickets</span>
          <div class="flex gap-2">
            <button name="action" value="update" class="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Save</button>
            <button name="action" value="delete" onclick="return confirm('Delete this department?');" class="rounded-lg border border-rose-300 px-3 py-1.5 text-xs text-rose-700 hover:bg-rose-100">Delete</button>
          </div>
        </div>
        <input name="name" value="<?= e($d['name']) ?>" class="mt-3 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-900 outline-none focus:border-teal-500">
        <textarea name="description" rows="2" placeholder="No description" class="mt-2 w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500"><?= e($d['description'] ?? '') ?></textarea>
      </form>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
