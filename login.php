<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) redirect('dashboard.php');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = post('login');
    $pass  = post('password');

    if ($login === '' || $pass === '') {
        $errors[] = 'User ID and password are required.';
    } else {
        $u = q('SELECT * FROM users WHERE username = ? OR email = ?', [$login, $login])->fetch();
        if (!$u || !password_verify($pass, $u['password'])) {
            $errors[] = 'Those credentials do not match our records.';
        } elseif (!$u['is_active']) {
            $errors[] = 'This account has been deactivated.';
        } else {
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['id'];
            flash('Welcome back, ' . $u['name'] . '!');
            redirect('dashboard.php');
        }
    }
}
$pageTitle = 'Sign in';
require __DIR__ . '/layout/header.php';
?>
<div class="grid min-h-screen lg:grid-cols-2">
  <!-- Flat, quiet, and the same ground as the rest of the app. -->
  <div class="hidden flex-col justify-between border-r border-zinc-200 bg-white p-12 lg:flex">
    <div class="flex items-center gap-2.5">
      <div class="grid h-7 w-7 place-items-center rounded-md bg-brand-500 text-[13px] font-semibold text-white">H</div>
      <span class="text-sm font-semibold tracking-tight text-zinc-900"><?= APP_NAME ?></span>
    </div>
    <div>
      <h2 class="max-w-sm text-3xl font-semibold leading-tight tracking-tight text-zinc-900">Support that never drops a thread.</h2>
      <p class="mt-3 max-w-md text-[13px] leading-relaxed text-zinc-500">Raise it, assign it, complete it — then the person who raised it signs it off. Priorities, departments and a full activity trail, in one workspace.</p>
      <ul class="mt-6 space-y-2">
        <?php foreach (['Every ticket assigned by one person', 'The requester signs off the fix', 'Daily task sheets feed the reports'] as $line): ?>
          <li class="flex items-center gap-2 text-[13px] text-zinc-600">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span><?= $line ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <p class="text-[11px] text-zinc-400">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
  </div>

  <div class="flex items-center justify-center p-6">
    <div class="w-full max-w-md">
      <h1 class="text-xl font-semibold tracking-tight text-zinc-900">Sign in</h1>
      <p class="mt-1 text-[13px] text-zinc-500">Sign in with the User Name issued to you.</p>

      <?php foreach ($errors as $er): ?>
        <div class="mt-5 rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-[13px] text-rose-700"><?= e($er) ?></div>
      <?php endforeach; ?>

      <form method="post" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400">User Name</label>
          <input name="login" required autofocus placeholder="Your user ID" value="<?= e($_POST['login'] ?? '') ?>" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
        </div>
        <div>
          <label class="mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400">Password</label>
          <input name="password" type="password" required class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
        </div>
        <button class="w-full rounded-md bg-brand-500 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">Sign in</button>
      </form>
      <p class="mt-6 text-center text-[11px] text-zinc-500">Accounts are issued by the Super Admin.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
