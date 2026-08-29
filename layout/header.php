<?php
require_once __DIR__ . '/../includes/functions.php';
$me      = user();
$current = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? APP_NAME;

$nav = [
    ['dashboard.php', 'Dashboard', 'M3 12l9-9 9 9M5 10v10h14V10'],
];
if (!is_admin()) {
    $nav[] = ['tickets.php', is_it() ? 'Assigned Tickets' : 'Tickets', 'M4 6h16v4a2 2 0 000 4v4H4v-4a2 2 0 000-4V6z'];
}
if (can_raise_tickets()) {
    $nav[] = ['ticket-new.php', 'New Ticket', 'M12 5v14M5 12h14'];
}
if (can_fill_tasks()) {
    $nav[] = ['tasks.php', 'Daily Tasks', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4'];
}
if (can_view_reports()) {
    $nav[] = ['reports.php', 'Reports', 'M9 19v-6M15 19V9M21 19V5M3 19h18'];
}
if (is_super()) {
    $nav[] = ['users.php',       'Users',       'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 100-8 4 4 0 000 8z'];
    $nav[] = ['departments.php', 'Departments', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'];
}
$nav[] = ['profile.php', 'Profile', 'M5.1 19a7 7 0 0113.8 0M12 11a4 4 0 100-8 4 4 0 000 8z'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= APP_NAME ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: { fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','sans-serif'] } } } };
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-track{background:transparent}
  ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
  ::-webkit-scrollbar-thumb:hover{background:#94a3b8}
  /* A faint teal wash so the page is not a flat sheet of white. */
  body{background-attachment:fixed;background-image:
    radial-gradient(60rem 40rem at 12% -12%, rgba(13,148,136,.10), transparent 60%),
    radial-gradient(50rem 32rem at 108% 6%, rgba(6,182,212,.08), transparent 60%)}
  /* Native controls follow the accent instead of the browser default blue. */
  input[type=checkbox],input[type=radio]{accent-color:#0d9488}
  input[type=date]::-webkit-calendar-picker-indicator{opacity:.55;cursor:pointer}
  input[type=date]::-webkit-calendar-picker-indicator:hover{opacity:1}
  :where(a,button,input,select,textarea):focus-visible{outline:2px solid #0d9488;outline-offset:2px}
</style>
</head>
<body class="min-h-screen bg-slate-50 font-sans text-slate-700 antialiased">
<?php if (is_logged_in()): ?>
<div class="flex min-h-screen">
  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full border-r border-slate-200 bg-white/85 backdrop-blur transition-transform lg:translate-x-0">
    <div class="flex h-16 items-center gap-2 px-6">
      <div class="grid h-9 w-9 place-items-center rounded-xl bg-gradient-to-br from-teal-500 to-sky-500 font-bold text-white">H</div>
      <span class="text-lg font-semibold text-slate-900"><?= APP_NAME ?></span>
    </div>
    <nav class="mt-2 space-y-1 px-3">
      <?php foreach ($nav as [$href, $label, $icon]):
        $active = $current === $href; ?>
        <a href="<?= url($href) ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition <?= $active ? 'bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-600/25' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' ?>">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
          <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="absolute inset-x-0 bottom-0 border-t border-slate-200 p-3">
      <div class="flex items-center gap-3 rounded-xl px-3 py-2">
        <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-semibold text-slate-900"><?= e(initials($me['name'])) ?></div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-medium text-slate-900"><?= e($me['name']) ?></p>
          <p class="truncate text-xs text-slate-500"><?= e(ROLE_LABELS[$me['role']] ?? $me['role']) ?></p>
        </div>
        <a href="<?= url('logout.php') ?>" title="Sign out" class="rounded-lg p-2 text-slate-500 hover:bg-slate-50 hover:text-rose-600">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 16l4-4m0 0l-4-4m4 4H9m3 8H6a2 2 0 01-2-2V6a2 2 0 012-2h6"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <div class="flex min-h-screen flex-1 flex-col lg:ml-64">
    <header class="sticky top-0 z-30 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/85 px-4 backdrop-blur sm:px-6">
      <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="rounded-lg p-2 text-slate-500 hover:bg-slate-50 lg:hidden">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <h1 class="text-base font-semibold text-slate-900 sm:text-lg"><?= e($pageTitle) ?></h1>
      <div class="ml-auto flex items-center gap-3">
        <?php if (can_raise_tickets()): ?>
          <a href="<?= url('ticket-new.php') ?>" class="hidden rounded-xl bg-teal-600 px-4 py-2 text-sm font-medium text-white hover:bg-teal-700 sm:inline-block">+ New Ticket</a>
        <?php endif; ?>
      </div>
    </header>

    <main class="flex-1 p-4 sm:p-6">
      <?php foreach ((array)flash() as $f):
        $cls = $f['type'] === 'error'
          ? 'border-rose-300 bg-rose-50 text-rose-700'
          : 'border-emerald-300 bg-emerald-50 text-emerald-700'; ?>
        <div class="mb-4 rounded-xl border <?= $cls ?> px-4 py-3 text-sm"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
<?php else: ?>
  <main class="min-h-screen">
    <?php foreach ((array)flash() as $f):
      $cls = $f['type'] === 'error' ? 'bg-rose-600' : 'bg-emerald-600'; ?>
      <div class="<?= $cls ?> px-4 py-2.5 text-center text-sm text-slate-900"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
