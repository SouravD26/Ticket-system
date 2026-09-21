<?php
require_once __DIR__ . '/../includes/attendance.php';
$me      = user();
$current = basename($_SERVER['PHP_SELF']);
$pageTitle = $pageTitle ?? APP_NAME;

$nav = [
    [is_hod() ? 'hod-dashboard.php' : 'dashboard.php', 'Dashboard', 'M3 12l9-9 9 9M5 10v10h14V10'],
];
if (can_punch()) {
    $nav[] = ['attendance.php', 'Punch In / Out', 'M3 9a2 2 0 012-2h1l2-3h8l2 3h1a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9zM12 17a4 4 0 100-8 4 4 0 000 8z'];
    $nav[] = ['my-attendance.php', 'My Attendance', 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z'];
    $nav[] = ['leave.php', 'Apply Leave', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'];
}
if (can_view_all_tasks()) {
    $nav[] = ['task-view.php', 'Employee Daily Tasks', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'];
}
if (!is_admin() && !in_array(role(), ['face_operator', 'hod'], true)) {
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
    $nav[] = ['system-accounts.php', 'System Accounts', 'M12 3l7 4v5c0 4.5-3 8-7 9-4-1-7-4.5-7-9V7l7-4zM9.5 12l2 2 3.5-4'];
}
if (is_super()) {
    // Sits directly above Profile, carrying the count of what HR is waiting on.
    $nav[] = ['hr-requests.php', 'HR Requests', 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2c0 .5-.2 1-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', pending_people_count()];
}
$hrmsNav = [];
if (can_run_kiosk()) $hrmsNav[] = ['face.php', 'Face Attendance', 'M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2M9 10h.01M15 10h.01M9.5 15a3.5 3.5 0 005 0'];
if (att_can('view_attendance')) $hrmsNav[] = ['hrms-attendance.php', 'Attendance', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'];
if (att_can('manual_attendance')) $hrmsNav[] = ['hrms-manual.php', 'Manual Attendance', 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.4-9.6a2 2 0 112.8 2.8L11.8 15H9v-2.8l8.6-8.6z'];
if (att_can('manage_employees')) $hrmsNav[] = ['hrms-employees.php', 'Employees', 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 100-8 4 4 0 000 8z'];
if (att_can('comp_off') || att_can('od_management') || att_can('leave_approval')) $hrmsNav[] = ['hrms-leave.php', 'Leave, Comp-off & OD', 'M5 13l4 4L19 7'];
if (att_can('export_reports')) $hrmsNav[] = ['hrms-export.php', 'Excel Reports', 'M12 10v6m0 0l-3-3m3 3l3-3M6 20h12a2 2 0 002-2V8l-6-6H6a2 2 0 00-2 2v14a2 2 0 002 2z'];
if (att_can('manage_companies') || att_can('manage_shifts') || att_can('manage_locations') || att_can('gps_restriction') || att_can('leave_approval'))
    $hrmsNav[] = ['hrms-settings.php', 'HRMS Settings', 'M10.3 4.3a1.7 1.7 0 013.4 0 1.7 1.7 0 002.6 1.1 1.7 1.7 0 012.4 2.4 1.7 1.7 0 001 2.6 1.7 1.7 0 010 3.4 1.7 1.7 0 00-1 2.6 1.7 1.7 0 01-2.4 2.4 1.7 1.7 0 00-2.6 1 1.7 1.7 0 01-3.4 0 1.7 1.7 0 00-2.6-1 1.7 1.7 0 01-2.4-2.4 1.7 1.7 0 00-1-2.6 1.7 1.7 0 010-3.4 1.7 1.7 0 001-2.6 1.7 1.7 0 012.4-2.4 1.7 1.7 0 002.6-1.1zM12 15a3 3 0 100-6 3 3 0 000 6z'];
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
    <nav class="h-[calc(100vh-7.5rem)] space-y-0.5 overflow-y-auto px-2 py-3">
      <?php foreach (['Workspace' => $nav, 'HRMS' => $hrmsNav] as $section => $items): if (!$items) continue; ?>
      <p class="px-2 pb-1.5 pt-3 text-[11px] font-medium uppercase tracking-wider text-zinc-400 first:pt-0"><?= $section ?></p>
      <?php foreach ($items as $item):
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
      <?php endforeach; endforeach; ?>
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
