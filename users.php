<?php
/**
 * Account management — Super Admin only.
 * Create Admin / IT / Employee accounts, change their email, reset passwords.
 */
require_once __DIR__ . '/includes/functions.php';
require_super();
$me = user();

$assignable  = ['employee' => 'Employee', 'it' => 'IT', 'admin' => 'Admin', 'superadmin' => 'Super Admin'];
$departments = all_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int) post('id');

    if ($action === 'create') {
        $name     = post('name');
        $username = post('username');
        $email    = post('email');
        $pass     = post('password');
        $role     = post('role', 'employee');

        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) || strlen($pass) < 6) {
            flash('Name, a user ID (3+ chars, letters/numbers/._-) and a 6+ character password are required.', 'error');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'error');
        } elseif (!isset($assignable[$role])) {
            flash('Invalid role.', 'error');
        } elseif (q('SELECT id FROM users WHERE username = ?', [$username])->fetch()) {
            flash('That user ID is already taken.', 'error');
        } elseif ($email !== '' && q('SELECT id FROM users WHERE email = ?', [$email])->fetch()) {
            flash('That email is already registered.', 'error');
        } else {
            q('INSERT INTO users (name, username, email, password, role, phone, department_id) VALUES (?,?,?,?,?,?,?)',
              [$name, $username, $email ?: $username . '@local', password_hash($pass, PASSWORD_DEFAULT), $role,
               post('phone') ?: null, (int) post('department_id') ?: null]);
            flash($assignable[$role] . ' account "' . $username . '" created.');
        }
    }

    if ($action === 'email') {
        $email = post('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'error');
        } elseif (q('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch()) {
            flash('Another account already uses that email.', 'error');
        } else {
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $id]);
            flash('Email updated.');
        }
    }

    if ($action === 'role' && $id !== (int)$me['id']) {
        $role = post('role');
        if (isset($assignable[$role])) {
            q('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            flash('Role updated.');
        }
    }

    if ($action === 'department') {
        $dept = (int) post('department_id');
        if ($dept && !q('SELECT id FROM departments WHERE id = ?', [$dept])->fetch()) {
            flash('That department no longer exists.', 'error');
        } else {
            q('UPDATE users SET department_id = ? WHERE id = ?', [$dept ?: null, $id]);
            flash('Department updated.');
        }
    }

    if ($action === 'toggle' && $id !== (int)$me['id']) {
        q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('Account status changed.');
    }

    if ($action === 'reset' && strlen(post('password')) >= 6) {
        q('UPDATE users SET password = ? WHERE id = ?', [password_hash(post('password'), PASSWORD_DEFAULT), $id]);
        flash('Password reset.');
    }

    if ($action === 'delete' && $id !== (int)$me['id']) {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Account deleted along with its tickets and task entries.');
    }

    redirect('users.php');
}

$users = q('SELECT u.*, d.name AS dept_name,
                   (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id)     AS opened,
                   (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id) AS assigned,
                   (SELECT COUNT(*) FROM daily_tasks dt WHERE dt.user_id = u.id) AS tasks
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            ORDER BY FIELD(u.role,"superadmin","admin","it","employee"), u.name')->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/layout/header.php';
?>
<div class="grid gap-4 lg:grid-cols-3">
  <form method="post" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-5 lg:order-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <h3 class="text-sm font-semibold text-slate-900">Add an account</h3>
    <p class="mt-1 text-xs text-slate-500">Users cannot register themselves — every account is created here.</p>
    <div class="mt-4 space-y-3">
      <input name="name" required placeholder="Full name" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <input name="username" required pattern="[A-Za-z0-9._-]{3,60}" placeholder="User ID (used to sign in)" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <input name="email" type="email" placeholder="Email (optional)" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <input name="phone" placeholder="Phone (optional)" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <input name="password" type="password" required minlength="6" placeholder="Temporary password" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      <select name="role" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
        <?php foreach ($assignable as $k => $l): ?>
          <option value="<?= $k ?>"><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="department_id" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
        <option value="">— No department —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="w-full rounded-xl bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Create account</button>
    </div>

    <div class="mt-5 space-y-1 border-t border-slate-200 pt-4 text-xs leading-relaxed text-slate-500">
      <p><span class="text-slate-600">Super Admin</span> — full access, creates accounts, assigns tickets to IT.</p>
      <p><span class="text-slate-600">Admin</span> — reports on every employee, read only.</p>
      <p><span class="text-slate-600">IT</span> — assigned tickets + daily tasks.</p>
      <p><span class="text-slate-600">Employee</span> — raise tickets, track status, daily tasks.</p>
      <p class="pt-1">The department groups people in the daily-task reports. Super Admin and Admin do not keep a task sheet.</p>
    </div>
  </form>

  <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
    <div class="border-b border-slate-200 px-5 py-3.5">
      <h3 class="text-sm font-semibold text-slate-900"><?= count($users) ?> accounts</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[1040px] text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th class="px-5 py-3 font-medium">User</th>
            <th class="px-5 py-3 font-medium">Role</th>
            <th class="px-5 py-3 font-medium">Department</th>
            <th class="px-5 py-3 font-medium">Activity</th>
            <th class="px-5 py-3 font-medium">Status</th>
            <th class="px-5 py-3 font-medium text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
          <?php foreach ($users as $u): $self = (int)$u['id'] === (int)$me['id']; ?>
            <tr class="hover:bg-slate-50">
              <td class="px-5 py-3.5">
                <div class="flex items-center gap-3">
                  <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-900"><?= e(initials($u['name'])) ?></div>
                  <div class="min-w-0">
                    <p class="truncate font-medium text-slate-900"><?= e($u['name']) ?><?= $self ? ' <span class="text-xs text-slate-500">(you)</span>' : '' ?></p>
                    <p class="truncate text-xs text-slate-500">ID: <span class="text-slate-500"><?= e($u['username']) ?></span> · <?= e($u['email']) ?></p>
                  </div>
                </div>
              </td>
              <td class="px-5 py-3.5">
                <?php if ($self): ?>
                  <span class="text-slate-500"><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></span>
                <?php else: ?>
                  <form method="post" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="role">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <select name="role" onchange="this.form.submit()" class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs outline-none focus:border-teal-500">
                      <?php foreach ($assignable as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3.5">
                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="department">
                  <input type="hidden" name="id" value="<?= $u['id'] ?>">
                  <select name="department_id" onchange="this.form.submit()"
                          class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs outline-none focus:border-teal-500">
                    <option value="">— None —</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?= $d['id'] ?>" <?= (int)$u['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              </td>
              <td class="whitespace-nowrap px-5 py-3.5 text-xs text-slate-500">
                <?= (int)$u['opened'] ?> raised · <?= (int)$u['assigned'] ?> assigned · <?= (int)$u['tasks'] ?> tasks
              </td>
              <td class="px-5 py-3.5">
                <?php if ($u['is_active']): ?>
                  <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                <?php else: ?>
                  <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500 ring-1 ring-inset ring-slate-500/20">Disabled</span>
                <?php endif; ?>
              </td>
              <td class="px-5 py-3.5">
                <div class="flex flex-wrap justify-end gap-2">
                  <form method="post" onsubmit="var m=prompt('New email address', this.email.value);if(!m)return false;this.email.value=m;"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="email"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="email" value="<?= e($u['email']) ?>">
                    <button class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Email</button>
                  </form>
                  <form method="post" onsubmit="var p=prompt('New password (min 6 chars)');if(!p||p.length<6)return false;this.password.value=p;"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="password" value="">
                    <button class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100">Reset PW</button>
                  </form>
                  <?php if (!$self): ?>
                    <form method="post"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                      <button class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-100"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete <?= e($u['name']) ?> along with their tickets and tasks?');"><?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                      <button class="rounded-lg border border-rose-300 px-2.5 py-1.5 text-xs text-rose-700 hover:bg-rose-100">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
