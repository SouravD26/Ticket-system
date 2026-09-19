<?php
/**
 * System accounts: Super Admins, Admins and the face-kiosk logins. They run the
 * system rather than work in it, so they are kept apart from the staff lists
 * (Users -> Accounts, HRMS -> Employees) and never record attendance.
 * Super Admin only.
 */
require_once __DIR__ . '/includes/attendance.php';
require_super();
$me = user();

const SYSTEM_ROLES = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'hod' => 'HOD', 'face_operator' => 'Face Operator'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    csrf_check();
    $id   = (int) post('id');
    $old  = $id ? q('SELECT * FROM users WHERE id = ? AND role IN ("superadmin","admin","hod","face_operator")', [$id])->fetch() : null;
    if ($id && !$old) redirect('system-accounts.php');
    $self = $old && (int) $old['id'] === (int) $me['id'];

    $v = [
        'name'      => post('name'),
        'username'  => post('username'),
        'email'     => post('email') ?: null,
        'phone'     => preg_replace('/\D/', '', post('phone')) ?: null,
        // Your own role and switch stay put: locking out the only Super Admin is unrecoverable.
        'role'      => $self ? $old['role'] : (isset(SYSTEM_ROLES[post('role')]) ? post('role') : 'admin'),
        'is_active' => $self ? 1 : (post('is_active') ? 1 : 0),
    ];
    $picked = array_values(array_intersect((array) ($_POST['rights'] ?? []), array_keys(ATT_RIGHTS)));
    $v['rights'] = $v['role'] === 'admin' && $picked ? json_encode($picked) : null;
    $pass = (string) ($_POST['password'] ?? '');

    $err = null;
    if ($v['name'] === '') $err = 'Enter a name.';
    elseif ($v['username'] === '') $err = 'Enter a login ID.';
    elseif (mb_strlen($v['username']) > 60) $err = 'The login ID can be at most 60 characters.';
    elseif (!$old && $pass === '') $err = 'Set a password for the new account.';
    elseif ($pass !== '' && ($pwErr = password_problem($pass))) $err = $pwErr;
    elseif ($v['email'] && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'That email address is not valid.';
    elseif ($v['phone'] !== null && strlen($v['phone']) < 10) $err = 'The phone number must have 10 digits.';
    elseif (q('SELECT id FROM users WHERE (username = ? OR phone = ?) AND id <> ?', [$v['username'], $v['username'], $id])->fetch()) $err = 'That login ID is already used by another account.';
    elseif ($v['phone'] && q('SELECT id FROM users WHERE (phone = ? OR username = ?) AND id <> ?', [$v['phone'], $v['phone'], $id])->fetch()) $err = 'That phone number is already used by another account.';
    elseif ($v['email'] && q('SELECT id FROM users WHERE email = ? AND id <> ?', [$v['email'], $id])->fetch()) $err = 'That email is already used by another account.';

    if ($err) {
        // Put back what was typed (never the password) so nothing has to be entered twice.
        $_SESSION['sys_old'] = ['id' => $id] + array_intersect_key($_POST, array_flip(['name', 'username', 'phone', 'email', 'role', 'is_active', 'rights']));
        flash($err, 'error');
        redirect('system-accounts.php?' . ($id ? "edit=$id" : 'new=1'));
    }

    if ($pass !== '') { $v['password'] = password_hash($pass, PASSWORD_DEFAULT); $v['password_set'] = 1; }
    if ($old) {
        q('UPDATE users SET ' . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v))) . ' WHERE id = ?', [...array_values($v), $id]);
    } else {
        $v['status'] = 'Working';
        q('INSERT INTO users (' . implode(', ', array_map(fn($k) => "`$k`", array_keys($v))) . ') VALUES ('
          . rtrim(str_repeat('?,', count($v)), ',') . ')', array_values($v));
    }
    flash('Saved ' . $v['name'] . '.');
    redirect('system-accounts.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    csrf_check();
    $id  = (int) post('id');
    $acc = q('SELECT * FROM users WHERE id = ? AND role IN ("superadmin","admin","hod","face_operator")', [$id])->fetch();
    if (!$acc) {
        flash('That account no longer exists.', 'error');
    } elseif ((int) $acc['id'] === (int) $me['id']) {
        flash('You cannot delete your own account.', 'error');
    } elseif ($acc['role'] === 'superadmin'
              && (int) q('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND is_active = 1 AND id <> ?', [$id])->fetchColumn() === 0) {
        flash('This is the last active Super Admin and cannot be deleted.', 'error');
    } else {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Deleted ' . $acc['name'] . '.');
    }
    redirect('system-accounts.php');
}

$accounts = q('SELECT * FROM users WHERE role IN ("superadmin","admin","hod","face_operator")
               ORDER BY FIELD(role,"superadmin","admin","hod","face_operator"), name')->fetchAll();
$editing = (int) get_('edit');
$acc = null;
foreach ($accounts as $a) if ((int) $a['id'] === $editing) $acc = $a;
$showForm = $acc || get_('new');
$modalFlash = $showForm ? (array) flash() : [];

$pageTitle = 'System Accounts';
require __DIR__ . '/layout/header.php';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
$roleCls = ['superadmin' => 'border-violet-200 bg-violet-50 text-violet-700', 'admin' => 'border-sky-200 bg-sky-50 text-sky-700',
            'hod' => 'border-amber-200 bg-amber-50 text-amber-700', 'face_operator' => 'border-zinc-200 bg-zinc-50 text-zinc-600'];
?>
<div class="mx-auto max-w-4xl">
  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <div>
        <h2 class="text-[13px] font-semibold text-zinc-900">System accounts</h2>
        <p class="text-[11px] text-zinc-500">Super Admins, Admins and face-kiosk logins. They are not employees: no attendance, not in the staff lists.</p>
      </div>
      <a href="<?= url('system-accounts.php?new=1') ?>" class="<?= ATT_BTN ?> shrink-0">Add account</a>
    </div>
    <ul class="divide-y divide-zinc-100">
      <?php foreach ($accounts as $a): $rights = json_decode((string) $a['rights'], true) ?: []; ?>
        <li class="flex items-center gap-3 px-4 py-2.5">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-zinc-100 text-[11px] font-semibold text-zinc-600"><?= e(initials($a['name'])) ?></span>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($a['name']) ?>
              <?= (int) $a['id'] === (int) $me['id'] ? '<span class="font-normal text-zinc-400">(you)</span>' : '' ?>
              <?php if (!$a['is_active']): ?><span class="ml-1 rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[11px] font-medium text-zinc-500">Disabled</span><?php endif; ?></p>
            <p class="truncate text-[11px] text-zinc-500">Login: <?= e($a['username']) ?><?= $a['phone'] && $a['phone'] !== $a['username'] ? ' · ' . e($a['phone']) : '' ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?>
              <?php if ($a['role'] === 'admin'): ?> · <?= $rights ? count($rights) . ' HRMS right' . (count($rights) === 1 ? '' : 's') : 'no HRMS rights' ?><?php endif; ?></p>
          </div>
          <span class="rounded-md border px-2 py-0.5 text-[11px] font-medium <?= $roleCls[$a['role']] ?>"><?= SYSTEM_ROLES[$a['role']] ?></span>
          <a href="<?= url('system-accounts.php?edit=' . $a['id']) ?>" class="text-[12px] font-medium text-brand-600 hover:underline">Edit</a>
          <?php if ((int) $a['id'] !== (int) $me['id']): ?>
            <form method="post" onsubmit="return confirm(<?= e(json_encode('Delete ' . $a['name'] . '? Any tickets they raised are deleted with the account. This cannot be undone.')) ?>)">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
              <button class="text-[12px] font-medium text-rose-600 hover:underline">Delete</button>
            </form>
          <?php else: ?><span class="w-[38px]"></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php if ($showForm): $a = $acc ?: ['id' => 0, 'role' => 'admin', 'is_active' => 1]; $self = (int) $a['id'] === (int) $me['id'];
  // After a failed save, show what was typed rather than the stored values.
  $typed = $_SESSION['sys_old'] ?? null; unset($_SESSION['sys_old']);
  if ($typed && (int) $typed['id'] === (int) $a['id']) {
      $a = array_merge($a, array_intersect_key($typed, array_flip(['name', 'username', 'phone', 'email'])),
                       ['role' => $self ? $a['role'] : ($typed['role'] ?? $a['role']), 'is_active' => !empty($typed['is_active']) ? 1 : 0,
                        'rights' => json_encode((array) ($typed['rights'] ?? []))]);
  }
  $have = json_decode((string) ($a['rights'] ?? ''), true) ?: []; $close = url('system-accounts.php'); ?>
  <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-zinc-900/50 p-4 sm:p-8"
       onclick="if (event.target === this) location.href = <?= e(json_encode($close)) ?>">
    <form method="post" class="w-full max-w-2xl rounded-lg border border-zinc-200 bg-white p-5 shadow-xl">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <div class="flex items-center justify-between">
        <h2 class="text-[13px] font-semibold text-zinc-900"><?= $acc ? 'Edit ' . e($acc['name']) : 'New system account' ?></h2>
        <a href="<?= e($close) ?>" title="Close (Esc)" class="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-900">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg></a>
      </div>
      <?php foreach ($modalFlash as $fl): ?>
        <div class="mt-3 rounded-md border px-3 py-2 text-[13px] <?= $fl['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><?= e($fl['msg']) ?></div>
      <?php endforeach; ?>

      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div><label class="<?= $lbl ?>">Name *</label><input name="name" required value="<?= e($a['name'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
        <div><label class="<?= $lbl ?>">Login ID *</label>
          <input name="username" required maxlength="60" autocomplete="off" value="<?= e($a['username'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
        <div><label class="<?= $lbl ?>">Phone</label><input name="phone" inputmode="numeric" value="<?= e($a['phone'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
        <div><label class="<?= $lbl ?>">Email</label><input name="email" type="email" value="<?= e($a['email'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
        <div><label class="<?= $lbl ?>">Role</label>
          <?php if ($self): ?><p class="py-2 text-[13px] text-zinc-700"><?= SYSTEM_ROLES[$a['role']] ?> (your own)</p>
          <?php else: ?><?= att_select('role', SYSTEM_ROLES, $a['role'], '', 'id="role" onchange="rightsBox()"') ?><?php endif; ?></div>
        <div><label class="<?= $lbl ?>"><?= $acc ? 'New password' : 'Password *' ?></label>
          <?= password_field('password', $acc ? 'Leave blank to keep' : 'e.g. Sanmarg@2026', !$acc, ATT_FIELD) ?></div>
      </div>

      <fieldset id="rights" class="mt-4 rounded-md border border-zinc-200 p-3">
        <legend class="px-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">HRMS rights for this admin</legend>
        <div class="grid gap-1.5 sm:grid-cols-2">
          <?php foreach (ATT_RIGHTS as $k => $l): ?>
            <label class="inline-flex items-center gap-2 text-[12px] text-zinc-700"><input type="checkbox" name="rights[]" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <?php if (!$self): ?>
          <label class="mr-auto inline-flex items-center gap-2 text-[12px] text-zinc-600"><input type="checkbox" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>> Account active</label>
        <?php else: ?><span class="mr-auto"></span><?php endif; ?>
        <button class="<?= ATT_BTN ?>">Save</button>
        <a href="<?= e($close) ?>" class="<?= ATT_BTN2 ?>">Cancel</a>
      </div>
    </form>
  </div>
  <script>
    function rightsBox() { const r = document.getElementById('role');
      document.getElementById('rights').style.display = (r ? r.value : <?= json_encode($a['role']) ?>) === 'admin' ? '' : 'none'; }
    rightsBox();
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') location.href = <?= json_encode($close) ?>; });
  </script>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>
