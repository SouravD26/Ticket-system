<?php
/**
 * Account management — Super Admin only.
 * Create Admin / IT / Employee accounts, edit them, reset passwords.
 */
require_once __DIR__ . '/includes/functions.php';
require_super();
$me = user();

/* Staff accounts only. Super Admins, Admins and kiosk logins are system accounts,
   listed and created on system-accounts.php instead. */
$assignable = ['employee' => 'Employee', 'hr' => 'HR', 'it' => 'IT'];
/* Role and department are two separate things: what the account may do, and
   which department's queue it belongs to. The list comes from the one source
   every other page reads. */
$departments = all_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id     = (int) post('id');
    $self   = $id === (int) $me['id'];

    // System accounts are managed on their own page, never from here.
    if ($id && q('SELECT 1 FROM users WHERE id = ? AND role IN ("superadmin","admin","hod","face_operator")', [$id])->fetch()) {
        flash('That is a system account - manage it under System Accounts.', 'error');
        redirect('users.php');
    }

    if ($action === 'create') {
        $name     = post('name');
        $username = post('username');
        $email    = post('email');
        $pass     = post('password');
        $role     = post('role', 'employee');
        $dept     = (int) post('department_id');

        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) || strlen($pass) < 6) {
            flash('Name, a user ID (3+ chars, letters/numbers/._-) and a 6+ character password are required.', 'error');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'error');
        } elseif (!isset($assignable[$role])) {
            flash('Invalid role.', 'error');
        } elseif ($dept && !q('SELECT id FROM departments WHERE id = ?', [$dept])->fetch()) {
            flash('That department no longer exists.', 'error');
        } elseif (q('SELECT id FROM users WHERE username = ?', [$username])->fetch()) {
            flash('That user ID is already taken.', 'error');
        } elseif ($email !== '' && q('SELECT id FROM users WHERE email = ?', [$email])->fetch()) {
            flash('That email is already registered.', 'error');
        } else {
            q('INSERT INTO users (name, username, email, password, role, phone, department_id) VALUES (?,?,?,?,?,?,?)',
            // email is NOT NULL, so an account created without one gets a placeholder. It has
            // to be a *valid* address or every later filter_var() check on this row fails and
            // the account can no longer be edited — .invalid is reserved for exactly this.
              [$name, $username, $email ?: $username . '@local.invalid', password_hash($pass, PASSWORD_DEFAULT), $role,
               post('phone') ?: null, $dept ?: null]);
            flash($assignable[$role] . ' account "' . $username . '" created.');
        }
    }

    /* One save for the whole row: details, role, status, password. */
    if ($action === 'update' && $id) {
        $name  = post('name');
        $email = post('email');
        $pass  = post('password');
        $role  = post('role');
        $dept  = (int) post('department_id');

        // The form is prefilled with the stored address. Accounts created before the
        // placeholder was made a valid address hold things like "bob@local", which the
        // filter rejects — so only check the format when the address is actually changed.
        $emailUnchanged = $email !== '' && $email === (q('SELECT email FROM users WHERE id = ?', [$id])->fetch()['email'] ?? null);

        if ($name === '') {
            flash('Name is required.', 'error');
        } elseif ($email !== '' && !$emailUnchanged && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('That email address is not valid.', 'error');
        } elseif ($email !== '' && q('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $id])->fetch()) {
            flash('Another account already uses that email.', 'error');
        } elseif ($pass !== '' && strlen($pass) < 6) {
            flash('A new password must be at least 6 characters.', 'error');
        } elseif ($dept && !q('SELECT id FROM departments WHERE id = ?', [$dept])->fetch()) {
            flash('That department no longer exists.', 'error');
        } else {
            // Your own role and your own switch stay out of reach - locking
            // yourself out of the only Super Admin account is unrecoverable.
            $role   = (!$self && isset($assignable[$role])) ? $role : null;
            $active = $self ? null : (post('is_active') === '1' ? 1 : 0);

            // email is NOT NULL in the schema: an empty box means "leave it alone".
            // The phone is also an HRMS login, so a blank box leaves it alone too.
            q('UPDATE users SET name = ?, email = COALESCE(?, email), phone = COALESCE(?, phone), department_id = ?,
                                role = COALESCE(?, role), is_active = COALESCE(?, is_active)
               WHERE id = ?',
              [$name, $email ?: null, post('phone') ?: null, $dept ?: null, $role, $active, $id]);

            if ($pass !== '') {
                q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            }
            flash('Account updated.' . ($pass !== '' ? ' Password reset.' : ''));
        }
        redirect('users.php');
    }

    if ($action === 'delete' && !$self && q('SELECT 1 FROM attendance WHERE user_id = ? LIMIT 1', [$id])->fetch()) {
        // Deleting would cascade away their attendance history; resigning keeps it.
        flash('This person has attendance records. Mark them as resigned under Employees instead of deleting.', 'error');
        redirect('users.php');
    }
    if ($action === 'delete' && !$self) {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Account deleted along with its tickets and task entries.');
    }

    redirect('users.php');
}

// One row is put into edit mode at a time, by ?edit=<id>.
$editing = (int) get_('edit', '0');

$users = q('SELECT u.*, d.name AS dept_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.role NOT IN ("superadmin","admin","hod","face_operator")
            ORDER BY FIELD(u.role,"hr","it","employee"), u.name')->fetchAll();

$pageTitle = 'Users';
require __DIR__ . '/layout/header.php';

$field = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-900 outline-none placeholder:text-zinc-400 focus:border-brand-400';

/** The department this account belongs to, or none. */
function dept_select(int $dept, array $departments, string $field): string
{
    $out = '<select name="department_id" title="Department" class="' . $field . '">';
    $out .= '<option value=""' . (!$dept ? ' selected' : '') . '>No department</option>';
    foreach ($departments as $d) {
        $out .= '<option value="' . $d['id'] . '"' . ($dept === (int) $d['id'] ? ' selected' : '') . '>'
              . e($d['name']) . '</option>';
    }
    return $out . '</select>';
}

/** One dropdown, one choice: which kind of account this is. */
function role_select(string $role, array $roles, string $field): string
{
    $out = '<select name="role" title="Role" class="' . $field . '">';
    foreach ($roles as $k => $label) {
        $out .= '<option value="' . $k . '"' . ($role === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $out . '</select>';
}
?>
<div class="mx-auto max-w-4xl">

  <!-- Add an account. Users cannot register themselves. -->
  <form method="post" class="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="flex items-baseline justify-between gap-3">
      <h2 class="text-[13px] font-semibold text-zinc-900">Add an account</h2>
      <p class="text-[11px] text-zinc-500">Every account is created here.</p>
    </div>
    <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
      <input name="name" required placeholder="Full name" class="<?= $field ?>">
      <input name="username" required pattern="[A-Za-z0-9._-]{3,60}" placeholder="User ID (used to sign in)" class="<?= $field ?>">
      <input name="password" type="password" required minlength="6" placeholder="Temporary password" class="<?= $field ?>">
      <input name="email" type="email" placeholder="Email (optional)" class="<?= $field ?>">
      <input name="phone" placeholder="Phone (optional)" class="<?= $field ?>">
      <?= role_select('employee', $assignable, $field) ?>
      <?= dept_select(0, $departments, $field) ?>
    </div>
    <button class="mt-3 rounded-md bg-brand-500 px-4 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">Create account</button>
  </form>

  <div class="mt-3 overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Accounts</h2>
      <span class="rounded-md border border-zinc-200 bg-white px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 tabular-nums"><?= count($users) ?></span>
    </div>

    <ul class="divide-y divide-zinc-100">
      <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
        <li class="px-4 py-2.5">
          <?php if ($editing === (int) $u['id']): ?>

            <!-- Edit mode: the whole account, saved in one go. -->
            <form method="post" class="space-y-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">

              <div class="flex items-center gap-2 text-[11px] text-zinc-500">
                <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-600"><?= e(initials($u['name'])) ?></span>
                Editing <span class="font-medium text-zinc-700"><?= e($u['username']) ?></span> — the user ID cannot be changed<?= $self ? ', and your own role is fixed' : '' ?>.
              </div>

              <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <input name="name" value="<?= e($u['name']) ?>" required placeholder="Full name" class="<?= $field ?>">
                <input name="email" type="email" value="<?= e($u['email']) ?>" placeholder="Email" class="<?= $field ?>">
                <input name="phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="Phone" class="<?= $field ?>">

                <?= role_select($u['role'], $assignable, $field) ?>
                <?= dept_select((int) $u['department_id'], $departments, $field) ?>

                <input name="password" type="password" minlength="6" placeholder="New password (leave blank to keep)" class="<?= $field ?>">
              </div>

              <div class="flex flex-wrap items-center gap-3 pt-1">
                <?php if (!$self): ?>
                  <label class="inline-flex cursor-pointer items-center gap-2 text-[12px] text-zinc-600">
                    <input type="checkbox" name="is_active" value="1" <?= $u['is_active'] ? 'checked' : '' ?> class="h-3.5 w-3.5 rounded border-zinc-300">
                    Account active
                  </label>
                <?php endif; ?>
                <div class="ml-auto flex items-center gap-1.5">
                  <button class="rounded-md bg-brand-500 px-3 py-1.5 text-[12px] font-medium text-white shadow-sm transition hover:bg-brand-600">Save</button>
                  <a href="<?= url('users.php') ?>" class="rounded-md border border-zinc-200 px-3 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">Cancel</a>
                </div>
              </div>
            </form>

          <?php else: ?>

            <div class="flex items-center gap-3">
              <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-zinc-100 text-[11px] font-semibold text-zinc-600"><?= e(initials($u['name'])) ?></span>

              <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] font-medium text-zinc-900">
                  <?= e($u['name']) ?>
                  <?= $self ? '<span class="font-normal text-zinc-400">(you)</span>' : '' ?>
                  <?php if (!$u['is_active']): ?>
                    <span class="ml-1 rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[11px] font-medium text-zinc-500">Disabled</span>
                  <?php endif; ?>
                </p>
                <p class="truncate text-[11px] text-zinc-500">
                  <?= e($u['username']) ?>
                  · <?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?>
                  · <?= e($u['dept_name'] ?? 'No department') ?>
                </p>
              </div>

              <div class="flex shrink-0 items-center gap-1.5">
                <a href="?edit=<?= $u['id'] ?>"
                   class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 px-2.5 py-1.5 text-[12px] font-medium text-zinc-600 transition hover:bg-zinc-50">
                  <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.9 3.8a2.1 2.1 0 013 3L7.5 19.2l-4 1 1-4L16.9 3.8z"/>
                  </svg>
                  Edit
                </a>

                <?php if (!$self): ?>
                  <form method="post" class="inline"
                        onsubmit="return confirm('Delete <?= e(addslashes($u['name'])) ?> along with their tickets and tasks?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button class="inline-flex items-center gap-1.5 rounded-md border border-rose-200 px-2.5 py-1.5 text-[12px] font-medium text-rose-700 transition hover:bg-rose-50">
                      <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13M10 11v6M14 11v6"/>
                      </svg>
                      Delete
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
