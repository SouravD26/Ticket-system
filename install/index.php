<?php
/**
 * One-click installer: creates ticket_db, loads the schema, seeds an admin.
 * DELETE THIS FOLDER after installing on a live server.
 */
require_once __DIR__ . '/../includes/config.php';

$log = [];
$done = false;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminName  = trim($_POST['name'] ?? 'Administrator');
    $adminUser  = trim($_POST['username'] ?? 'System');
    $adminEmail = trim($_POST['email'] ?? '');
    $adminPass  = (string)($_POST['password'] ?? '');

    try {
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) throw new Exception('Enter a valid admin email.');
        if (strlen($adminPass) < 6) throw new Exception('Admin password must be at least 6 characters.');

        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $log[] = 'Database `' . DB_NAME . '` ready.';

        $pdo->exec('USE `' . DB_NAME . '`');
        // Normalise line endings first: the file is often saved CRLF, and splitting a
        // CRLF file on ";\n" matches nothing, which silently skips the entire schema.
        $sql = str_replace(["\r\n", "\r"], "\n", file_get_contents(__DIR__ . '/schema.sql'));
        foreach (explode(";\n", $sql) as $stmt) {
            // Drop the leading comment lines so a commented statement still runs.
            $lines = [];
            foreach (explode("\n", $stmt) as $ln) {
                if (!$lines && (trim($ln) === '' || str_starts_with(trim($ln), '--'))) continue;
                $lines[] = $ln;
            }
            $stmt = trim(implode("\n", $lines), " \t\n;");
            if ($stmt === '') continue;
            try {
                $pdo->exec($stmt);
            } catch (PDOException $pe) {
                // Re-running the installer re-adds the named foreign keys; that is harmless.
                if (!preg_match('/Duplicate (key|foreign key constraint) name|errno: 121/i', $pe->getMessage())) {
                    throw $pe;
                }
            }
        }
        $log[] = 'Tables created.';

        $seedDepts = ['General Support', 'Billing', 'Technical', 'Sales'];
        foreach ($seedDepts as $d) {
            $pdo->prepare('INSERT IGNORE INTO departments (name) VALUES (?)')->execute([$d]);
        }
        $log[] = 'Default departments seeded.';

        $st = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $st->execute([$adminUser, $adminEmail]);
        $existingId = (int) ($st->fetchColumn() ?: 0);
        if ($existingId) {
            $pdo->prepare('UPDATE users SET name=?, username=?, email=?, password=?, role="superadmin", is_active=1 WHERE id=?')
                ->execute([$adminName, $adminUser, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT), $existingId]);
            $log[] = 'Existing Super Admin updated.';
        } else {
            $pdo->prepare('INSERT INTO users (name, username, email, password, role) VALUES (?,?,?,?,"superadmin")')
                ->execute([$adminName, $adminUser, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);
            $log[] = 'Super Admin account created.';
        }

        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
        $done = true;
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · <?= APP_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-50 text-slate-700 antialiased flex items-center justify-center p-6">
<div class="w-full max-w-lg">
  <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-2xl backdrop-blur">
    <h1 class="text-2xl font-semibold text-slate-900">Install <?= APP_NAME ?></h1>
    <p class="mt-1 text-sm text-slate-500">Creates <code class="text-teal-700"><?= DB_NAME ?></code>, loads the schema and your admin login.</p>

    <?php if ($error): ?>
      <div class="mt-5 rounded-lg border border-rose-300 bg-rose-50 p-3 text-sm text-rose-700"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
      <ul class="mt-5 space-y-1 text-sm text-emerald-700">
        <?php foreach ($log as $l): ?><li>✓ <?= htmlspecialchars($l) ?></li><?php endforeach; ?>
      </ul>
      <div class="mt-5 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
        Now delete the <code>install/</code> folder.
      </div>
      <a href="<?= BASE_URL ?>/login.php" class="mt-6 block rounded-xl bg-teal-600 py-3 text-center font-medium text-white hover:bg-teal-700">Go to login →</a>
    <?php else: ?>
      <form method="post" class="mt-6 space-y-4">
        <div>
          <label class="block text-sm text-slate-600">Admin name</label>
          <input name="name" value="Administrator" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 outline-none focus:border-teal-500">
        </div>
        <div>
          <label class="block text-sm text-slate-600">Admin user ID</label>
          <input name="username" value="System" required pattern="[A-Za-z0-9._-]{3,60}" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 outline-none focus:border-teal-500">
        </div>
        <div>
          <label class="block text-sm text-slate-600">Admin email</label>
          <input name="email" type="email" required placeholder="admin@example.com" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 outline-none focus:border-teal-500">
        </div>
        <div>
          <label class="block text-sm text-slate-600">Admin password</label>
          <input name="password" type="password" required minlength="6" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-4 py-2.5 outline-none focus:border-teal-500">
        </div>
        <button class="w-full rounded-xl bg-teal-600 py-3 font-medium text-white hover:bg-teal-700">Run installer</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
