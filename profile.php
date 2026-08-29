<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$myDept = q('SELECT d.name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?',
            [user()['id']])->fetch()['name'] ?? null;
$me = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'profile') {
        $name = post('name');
        // Only the Super Admin changes email addresses.
        $email = is_super() ? post('email') : $me['email'];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Name and a valid email are required.', 'error');
        } elseif (q('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $me['id']])->fetch()) {
            flash('That email belongs to another account.', 'error');
        } else {
            q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?',
              [$name, $email, post('phone') ?: null, $me['id']]);
            flash('Profile updated.');
        }
    }

    if ($action === 'password') {
        $current = post('current_password');
        $new     = post('new_password');
        if (!password_verify($current, $me['password'])) {
            flash('Your current password is incorrect.', 'error');
        } elseif (strlen($new) < 6) {
            flash('New password must be at least 6 characters.', 'error');
        } elseif ($new !== post('confirm_password')) {
            flash('New passwords do not match.', 'error');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
            flash('Password changed.');
        }
    }

    redirect('profile.php');
}

$stats = q('SELECT COUNT(*) total, SUM(status IN ("open","pending")) active FROM tickets WHERE user_id = ?', [$me['id']])->fetch();

$pageTitle = 'Profile';
require __DIR__ . '/layout/header.php';
?>
<div class="mx-auto grid max-w-4xl gap-4 md:grid-cols-2">
  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6 md:col-span-2">
    <div class="flex items-center gap-4">
      <div class="grid h-16 w-16 place-items-center rounded-2xl bg-gradient-to-br from-teal-500 to-sky-500 text-xl font-bold text-white"><?= e(initials($me['name'])) ?></div>
      <div>
        <h2 class="text-xl font-semibold text-slate-900"><?= e($me['name']) ?></h2>
        <p class="text-sm text-slate-500">ID: <?= e($me['username']) ?> · <?= e($me['email']) ?> · <?= e(ROLE_LABELS[$me['role']] ?? $me['role']) ?></p>
        <p class="mt-1 text-xs text-slate-500">Member since <?= date('M Y', strtotime($me['created_at'])) ?> · <?= (int)$stats['total'] ?> tickets opened, <?= (int)$stats['active'] ?> still active</p>
      </div>
    </div>
  </div>

  <form method="post" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="profile">
    <h3 class="text-sm font-semibold text-slate-900">Account details</h3>
    <div class="mt-4 space-y-3">
      <div>
        <label class="mb-1 block text-xs text-slate-500">Full name</label>
        <input name="name" required value="<?= e($me['name']) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      </div>
      <div>
        <label class="mb-1 block text-xs text-slate-500">Email</label>
        <?php if (is_super()): ?>
          <input name="email" type="email" required value="<?= e($me['email']) ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
        <?php else: ?>
          <input type="email" value="<?= e($me['email']) ?>" disabled class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm text-slate-500">
        </div>
        <div>
          <label class="mb-1 block text-sm text-slate-600">Department</label>
          <input type="text" value="<?= e($myDept ?? 'Not set') ?>" disabled class="w-full cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 text-sm text-slate-500">
          <p class="mt-1 text-xs text-slate-500">Ask the Super Admin to change your email address.</p>
        <?php endif; ?>
      </div>
      <div>
        <label class="mb-1 block text-xs text-slate-500">Phone</label>
        <input name="phone" value="<?= e($me['phone'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
      </div>
      <button class="w-full rounded-xl bg-teal-600 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Save profile</button>
    </div>
  </form>

  <form method="post" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="password">
    <h3 class="text-sm font-semibold text-slate-900">Change password</h3>
    <div class="mt-4 space-y-3">
      <?php foreach ([['current_password','Current password'],['new_password','New password'],['confirm_password','Confirm new password']] as [$n,$l]): ?>
        <div>
          <label class="mb-1 block text-xs text-slate-500"><?= $l ?></label>
          <input name="<?= $n ?>" type="password" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm outline-none focus:border-teal-500">
        </div>
      <?php endforeach; ?>
      <button class="w-full rounded-xl border border-slate-200 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Update password</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
