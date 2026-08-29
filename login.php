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
  <div class="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-teal-600 via-teal-700 to-cyan-800 p-12 lg:flex">
    <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-32 -left-16 h-80 w-80 rounded-full bg-cyan-300/10 blur-3xl"></div>
    <div class="relative flex items-center gap-2">
      <div class="grid h-10 w-10 place-items-center rounded-xl bg-white/20 text-lg font-bold text-white ring-1 ring-inset ring-white/30">H</div>
      <span class="text-xl font-semibold text-white"><?= APP_NAME ?></span>
    </div>
    <div class="relative">
      <h2 class="text-4xl font-bold leading-tight text-white">Support that<br>never drops a thread.</h2>
      <p class="mt-4 max-w-md text-teal-50/90">Raise it, assign it, complete it — then the person who raised it signs it off. Priorities, departments and a full activity trail, in one clean workspace.</p>
    </div>
    <p class="relative text-sm text-teal-100/70">&copy; <?= date('Y') ?> <?= APP_NAME ?></p>
  </div>

  <div class="flex items-center justify-center p-6">
    <div class="w-full max-w-md">
      <h1 class="text-2xl font-semibold text-slate-900">Sign in</h1>
      <p class="mt-1 text-sm text-slate-500">Sign in with the user ID issued to you.</p>

      <?php foreach ($errors as $er): ?>
        <div class="mt-5 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= e($er) ?></div>
      <?php endforeach; ?>

      <form method="post" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="mb-1 block text-sm text-slate-600">User ID</label>
          <input name="login" required autofocus placeholder="Your user ID" value="<?= e($_POST['login'] ?? '') ?>" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25">
        </div>
        <div>
          <label class="mb-1 block text-sm text-slate-600">Password</label>
          <input name="password" type="password" required class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-teal-500 focus:ring-2 focus:ring-teal-500/25">
        </div>
        <button class="w-full rounded-xl bg-teal-600 py-3 text-sm font-semibold text-white transition hover:bg-teal-700">Sign in</button>
      </form>
      <p class="mt-6 text-center text-xs text-slate-500">Accounts are issued by the Super Admin.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
