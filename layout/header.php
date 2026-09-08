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
if (can_manage_people()) {
    $nav[] = ['onboarding.php',  'Onboarding',  'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM19 8v6M22 11h-6'];
    $nav[] = ['offboarding.php', 'Offboarding', 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM22 11h-6'];
}
if (can_view_reports()) {
    $nav[] = ['reports.php', 'Reports', 'M9 19v-6M15 19V9M21 19V5M3 19h18'];
}
if (is_super()) {
    $nav[] = ['users.php',       'Users',       'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 100-8 4 4 0 000 8z'];
    $nav[] = ['departments.php', 'Departments', 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'];
}
if (is_super()) {
    // Sits directly above Profile, carrying the count of what HR is waiting on.
    $nav[] = ['hr-requests.php', 'HR Requests', 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', pending_people_count()];
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
/* Linear-style corporate palette: neutral surfaces, one teal accent. */
tailwind.config = {
  theme: {
    extend: {
      fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','sans-serif'] },
      colors: {
        /* The house accent: teal. Every button, link and focus ring reads from
           this one scale, so changing it here recolours the whole app. */
        brand: {
          50:'#f0fdfa', 100:'#ccfbf1', 200:'#99f6e4', 300:'#5eead4',
          400:'#2dd4bf', 500:'#0d9488', 600:'#0f766e', 700:'#115e59',
          800:'#134e4a', 900:'#134e4a',
        },
      },
      borderRadius: { xl: '0.5rem', '2xl': '0.625rem' },
      boxShadow: {
        sm: '0 1px 2px 0 rgba(16,18,32,.04)',
        card: '0 1px 2px 0 rgba(16,18,32,.05), 0 1px 3px 0 rgba(16,18,32,.04)',
      },
    },
  },
};
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  /* Linear leans on tight tracking and a flat, quiet ground - no washes, no glow. */
  body{ letter-spacing:-.011em; background:#fbfbfc; }
  h1,h2,h3{ letter-spacing:-.018em; }
  ::-webkit-scrollbar{width:10px;height:10px}
  ::-webkit-scrollbar-track{background:transparent}
  ::-webkit-scrollbar-thumb{background:#d4d4d8;border-radius:99px;border:2px solid transparent;background-clip:content-box}
  ::-webkit-scrollbar-thumb:hover{background:#a1a1aa;background-clip:content-box;border:2px solid transparent}
  input[type=checkbox],input[type=radio]{accent-color:#0d9488}
  input[type=date]::-webkit-calendar-picker-indicator{opacity:.5;cursor:pointer}
  input[type=date]::-webkit-calendar-picker-indicator:hover{opacity:1}
  :where(a,button,input,select,textarea):focus-visible{outline:2px solid #0d9488;outline-offset:1px;border-radius:6px}
  /* Hovering a bordered control shows the accent the buttons are painted in.
     Controls that carry their own meaning - destructive rose, positive emerald,
     warning amber - keep theirs. */
  :where(a,button,input,select,textarea){transition:border-color .12s ease}
  :where(a,button,input,select,textarea):not([class*="rose"]):not([class*="emerald"]):not([class*="amber"]):hover{
    border-color:#0d9488;
  }
</style>
</head>
<body class="min-h-screen bg-[#fbfbfc] font-sans text-[13px] text-zinc-600 antialiased">
<?php if (is_logged_in()): ?>
<div class="flex min-h-screen">
  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-60 -translate-x-full border-r border-zinc-200 bg-white transition-transform lg:translate-x-0">
    <div class="flex h-14 items-center gap-2.5 border-b border-zinc-200 px-4">
      <div class="grid h-7 w-7 place-items-center rounded-md bg-brand-500 text-[13px] font-semibold text-white">H</div>
      <span class="text-sm font-semibold tracking-tight text-zinc-900"><?= APP_NAME ?></span>
    </div>
    <nav class="mt-3 space-y-0.5 px-2">
      <p class="px-2 pb-1.5 text-[11px] font-medium uppercase tracking-wider text-zinc-400">Workspace</p>
      <?php foreach ($nav as $item):
        [$href, $label, $icon] = $item;
        $badge  = $item[3] ?? 0;
        $active = $current === $href; ?>
        <a href="<?= url($href) ?>" class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-[13px] font-medium transition <?= $active ? 'bg-zinc-100 text-zinc-900' : 'text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900' ?>">
          <svg class="h-4 w-4 <?= $active ? 'text-brand-500' : 'text-zinc-400' ?>" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $icon ?>"/></svg>
          <?= e($label) ?>
          <?php if ($badge): ?>
            <span class="ml-auto grid h-4 min-w-[1rem] place-items-center rounded-full bg-rose-600 px-1 text-[10px] font-semibold text-white tabular-nums"><?= $badge ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="absolute inset-x-0 bottom-0 border-t border-zinc-200 p-2">
      <div class="flex items-center gap-2.5 rounded-md px-2 py-1.5">
        <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700"><?= e(initials($me['name'])) ?></div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-[13px] font-medium text-zinc-900"><?= e($me['name']) ?></p>
          <p class="truncate text-[11px] text-zinc-500"><?= e(ROLE_LABELS[$me['role']] ?? $me['role']) ?></p>
        </div>
        <a href="<?= url('logout.php') ?>" title="Sign out" class="rounded-md p-1.5 text-zinc-400 hover:bg-zinc-50 hover:text-rose-600">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 16l4-4m0 0l-4-4m4 4H9m3 8H6a2 2 0 01-2-2V6a2 2 0 012-2h6"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <div class="flex min-h-screen flex-1 flex-col lg:ml-60">
    <header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-zinc-200 bg-white/90 px-4 backdrop-blur sm:px-6">
      <button onclick="document.getElementById('sidebar').classList.toggle('-translate-x-full')" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-50 lg:hidden">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <h1 class="text-[13px] font-semibold text-zinc-900"><?= e($pageTitle) ?></h1>
      <div class="ml-auto flex items-center gap-2">
        <?php if (can_raise_tickets()): ?>
          <a href="<?= url('ticket-new.php') ?>" class="hidden rounded-md bg-brand-500 px-3 py-1.5 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600 sm:inline-block">New Ticket</a>
        <?php endif; ?>
      </div>
    </header>

    <main class="flex-1 p-4 sm:p-6">
      <?php foreach ((array)flash() as $f):
        $cls = $f['type'] === 'error'
          ? 'border-rose-200 bg-rose-50 text-rose-700'
          : 'border-emerald-200 bg-emerald-50 text-emerald-700'; ?>
        <div class="mb-4 rounded-md border <?= $cls ?> px-3.5 py-2.5 text-[13px]"><?= e($f['msg']) ?></div>
      <?php endforeach; ?>
<?php else: ?>
  <main class="min-h-screen">
    <?php foreach ((array)flash() as $f):
      $cls = $f['type'] === 'error' ? 'bg-rose-600' : 'bg-emerald-600'; ?>
      <div class="<?= $cls ?> px-4 py-2 text-center text-[13px] text-white"><?= e($f['msg']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
