<?php
require_once __DIR__ . '/includes/functions.php';
if (is_logged_in()) redirect('dashboard.php');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login = post('login');
    $pass  = post('password');

    if ($login === '' || $pass === '') {
        $errors[] = 'Employee ID (or mobile number) and password are required.';
    } else {
        /* Employee ID, mobile number, user ID or email all sign in. HRMS employee IDs
           are not unique (the same number is held by several people), so when one
           matches more than one account the password decides which. */
        $candidates = q('SELECT * FROM users WHERE username = ? OR email = ? OR phone = ? OR employee_id = ?
                         ORDER BY is_active DESC, id', [$login, $login, $login, $login])->fetchAll();
        $matches = array_values(array_filter($candidates, fn($c) => password_verify($pass, $c['password'])));
        $u = $matches[0] ?? null;

        if (count($matches) > 1) {
            $errors[] = 'More than one account matches that employee ID and password. Please sign in with your mobile number, and ask HR to correct the duplicate employee ID.';
        } elseif (!$u) {
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
      <p class="mt-1 text-[13px] text-zinc-500">Sign in with your employee ID, mobile number or user ID.</p>

      <?php foreach ($errors as $er): ?>
        <div class="mt-5 rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-[13px] text-rose-700"><?= e($er) ?></div>
      <?php endforeach; ?>

      <form method="post" class="mt-6 space-y-4">
        <?= csrf_field() ?>
        <div>
          <label class="mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400">Employee ID or Phone</label>
          <input name="login" required autofocus placeholder="Employee ID or mobile number" value="<?= e($_POST['login'] ?? '') ?>" class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
        </div>
        <div>
          <label class="mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400">Password</label>
          <div class="relative">
            <input name="password" id="loginPass" type="password" required autocomplete="current-password" class="w-full rounded-md border border-zinc-200 bg-white py-2 pl-3 pr-10 text-[13px] outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
            <!-- Show / hide the password -->
            <button type="button" tabindex="-1" title="Show password" aria-label="Show password"
                    onclick="const p = document.getElementById('loginPass'), show = p.type === 'password';
                             p.type = show ? 'text' : 'password';
                             this.querySelector('.eye-open').classList.toggle('hidden', show);
                             this.querySelector('.eye-shut').classList.toggle('hidden', !show);
                             this.title = this.ariaLabel = show ? 'Hide password' : 'Show password';"
                    class="absolute inset-y-0 right-0 grid w-10 place-items-center text-zinc-400 hover:text-zinc-700">
              <svg class="eye-open h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-shut hidden h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A10 10 0 0112 5c6.5 0 10 7 10 7a17 17 0 01-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 005.4-1.6"/></svg>
            </button>
          </div>
        </div>
        <button class="w-full rounded-md bg-brand-500 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600">Sign in</button>
      </form>
      <p class="mt-6 text-center text-[11px] text-zinc-500">Accounts come from the HRMS employee record.</p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
