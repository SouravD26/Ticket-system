<?php
require_once __DIR__ . '/includes/attendance.php';
require_login();
if (!can_punch()) redirect('dashboard.php');

$me = user();

/*
 * One file, three screens in the menu:
 *   attendance.php     Punch In / Out - selfie + location
 *   my-attendance.php  My Attendance  - every punch, month by month
 *   leave.php          Apply Leave    - requests and their status
 * The two small files set $TAB and include this one.
 */
$TAB = $TAB ?? 'punch';
$SELF = ['punch' => 'attendance.php', 'records' => 'my-attendance.php', 'leave' => 'leave.php'][$TAB];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'leave') {
    // The same rules as the app's leave/apply.
    csrf_check();
    $t = post('leave_type'); $a = post('start_date'); $b = post('end_date') ?: $a;
    if (!in_array($t, ATT_LEAVE_TYPES, true) || !strtotime($a) || !strtotime($b) || $b < $a || post('reason') === '') {
        flash('Choose a leave type, valid dates and give a reason.', 'error');
    } elseif ($c = q("SELECT start_date, end_date FROM leave_applications WHERE user_id = ? AND status IN ('Pending','Approved')
                       AND start_date <= ? AND end_date >= ? LIMIT 1", [$me['id'], $b, $a])->fetch()) {
        flash("You already have leave covering {$c['start_date']} to {$c['end_date']}.", 'error');
    } else {
        q('INSERT INTO leave_applications (user_id, leave_type, start_date, end_date, days_count, reason) VALUES (?,?,?,?,?,?)',
          [$me['id'], $t, $a, $b, (int) ((strtotime($b) - strtotime($a)) / 86400) + 1, mb_substr(post('reason'), 0, 2000)]);
        flash('Leave request sent for approval.');
    }
    redirect($SELF);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'cancel_leave') {
    csrf_check();
    q("DELETE FROM leave_applications WHERE id = ? AND user_id = ? AND status = 'Pending'", [(int) post('id'), $me['id']]);
    flash('Leave request cancelled.');
    redirect($SELF);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = post('type') === 'out' ? 'out' : 'in';
    $num  = fn($k) => is_numeric($_POST[$k] ?? null) ? (float) $_POST[$k] : null;
    [$ok, $msg] = att_punch($me, $type, 'self', [
        'lat' => $num('lat'), 'lng' => $num('lng'), 'accuracy' => $num('accuracy'),
        'selfie' => (string) ($_POST['selfie'] ?? ''),
    ]);
    flash($msg, $ok ? 'success' : 'error');
    redirect('attendance.php');
}

$open  = att_open_punch((int) $me['id']);
$today = q('SELECT * FROM attendance WHERE user_id = ? AND date = ? ORDER BY id', [$me['id'], att_workday()])->fetchAll();

// Month view
$month = preg_match('/^\d{4}-\d{2}$/', get_('month')) ? get_('month') : date('Y-m');
$from  = "$month-01";
$to    = date('Y-m-t', strtotime($from));
$rows  = q('SELECT * FROM attendance WHERE user_id = ? AND date BETWEEN ? AND ? ORDER BY date DESC, id',
           [$me['id'], $from, $to])->fetchAll();
$days = [];
foreach ($rows as $r) $days[$r['date']][] = $r;

$comp = q('SELECT comp_off_date, earned_date FROM comp_off_requests WHERE user_id = ? AND comp_off_date BETWEEN ? AND ?',
          [$me['id'], $from, $to])->fetchAll(PDO::FETCH_KEY_PAIR);
$od   = array_flip(q('SELECT od_date FROM od_records WHERE user_id = ? AND od_date BETWEEN ? AND ?',
          [$me['id'], $from, $to])->fetchAll(PDO::FETCH_COLUMN));

$myLeave = q('SELECT * FROM leave_applications WHERE user_id = ? AND end_date >= ? ORDER BY start_date DESC LIMIT 10',
              [$me['id'], date('Y-01-01')])->fetchAll();
$presentDays = count($days);
$totalSecs = 0;
foreach ($rows as $r) $totalSecs += att_seconds($r['punch_in'], $r['punch_out']);

$pageTitle = ['punch' => 'Punch In / Out', 'records' => 'My Attendance', 'leave' => 'Apply Leave'][$TAB];
require __DIR__ . '/layout/header.php';
?>
<div class="mx-auto max-w-5xl space-y-6">

<?php if ($TAB === 'punch'): ?>
  <!-- Today -->
  <section class="rounded-lg border border-zinc-200 bg-white p-5 shadow-card">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-[11px] font-medium uppercase tracking-wider text-zinc-400">Today</p>
        <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900" id="clock"><?= date('h:i:s A') ?></p>
        <p class="text-[13px] text-zinc-500"><?= date('l, j F Y') ?></p>
      </div>
      <div class="text-right">
        <?php if ($open): ?>
          <span class="inline-flex items-center gap-1.5 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>In since <?= att_time($open['punch_in']) ?></span>
        <?php else: ?>
          <span class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] font-medium text-zinc-500">
            <span class="h-1.5 w-1.5 rounded-full bg-zinc-400"></span><?= $today ? 'Punched out' : 'Not punched in' ?></span>
        <?php endif; ?>
        <div class="mt-3">
          <button type="button" onclick="openPunch()" class="rounded-md px-4 py-2 text-[13px] font-medium text-white shadow-sm transition <?= $open ? 'bg-rose-600 hover:bg-rose-700' : 'bg-brand-500 hover:bg-brand-600' ?>">
            <?= $open ? 'Punch Out' : 'Punch In' ?>
          </button>
        </div>
      </div>
    </div>
    <?php if ($today): ?>
      <div class="mt-4 flex flex-wrap gap-2 border-t border-zinc-100 pt-4">
        <?php foreach ($today as $t): ?>
          <span class="rounded-md border border-zinc-200 px-2 py-1 text-[12px] tabular-nums text-zinc-600">
            <?= att_time($t['punch_in']) ?> → <?= att_time($t['punch_out']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Punch panel -->
  <section id="punchPanel" class="hidden rounded-lg border border-zinc-200 bg-white p-5 shadow-card">
    <form method="post" id="punchForm">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="<?= $open ? 'out' : 'in' ?>">
      <input type="hidden" name="selfie" id="selfie">
      <input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng"><input type="hidden" name="accuracy" id="accuracy">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <div class="relative aspect-[4/3] overflow-hidden rounded-md bg-zinc-900">
            <video id="video" playsinline muted class="h-full w-full object-cover"></video>
            <img id="shot" class="absolute inset-0 hidden h-full w-full object-cover" alt="">
          </div>
          <canvas id="canvas" class="hidden"></canvas>
          <div class="mt-2 flex gap-2">
            <button type="button" id="captureBtn" onclick="capture()" class="flex-1 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-[13px] font-medium text-zinc-700">Capture selfie</button>
            <button type="button" id="retakeBtn" onclick="retake()" class="hidden flex-1 rounded-md border border-zinc-200 bg-white px-3 py-1.5 text-[13px] font-medium text-zinc-700">Retake</button>
          </div>
        </div>
        <div class="flex flex-col">
          <p class="text-[13px] font-medium text-zinc-900"><?= $open ? 'Punch out' : 'Punch in' ?></p>
          <p class="mt-1 text-[12px] text-zinc-500">The time is taken from the server clock, not your device.</p>
          <p id="gps" class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-[12px] text-amber-700">Getting your location…</p>
          <?php if (!empty($me['geo_restricted'])): ?>
            <p class="mt-2 text-[12px] text-zinc-500">You can only punch in from within the office area.</p>
          <?php endif; ?>
          <div class="mt-auto flex gap-2 pt-4">
            <button type="button" onclick="closePunch()" class="rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] font-medium text-zinc-700">Cancel</button>
            <button id="submitBtn" disabled class="flex-1 rounded-md bg-brand-500 px-3 py-2 text-[13px] font-medium text-white shadow-sm transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50">
              Confirm <?= $open ? 'Punch Out' : 'Punch In' ?>
            </button>
          </div>
        </div>
      </div>
    </form>
  </section>

<?php endif; ?>

<?php if ($TAB === 'leave'): ?>
  <!-- Leave -->
  <section class="rounded-lg border border-zinc-200 bg-white shadow-card">
    <div>
      <h2 class="px-5 py-3 text-[13px] font-semibold text-zinc-900">Apply for leave</h2>
      <form method="post" class="grid gap-2 border-t border-zinc-100 p-5 sm:grid-cols-4 sm:items-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="leave">
        <?= att_select('leave_type', ATT_LEAVE_TYPES, '', 'Leave type…', 'required') ?>
        <input type="date" name="start_date" required class="<?= ATT_FIELD ?>" title="From">
        <input type="date" name="end_date" class="<?= ATT_FIELD ?>" title="To (leave blank for one day)">
        <button class="<?= ATT_BTN ?>">Send request</button>
        <input name="reason" required placeholder="Reason" class="<?= ATT_FIELD ?> sm:col-span-4">
      </form>
    </div>
    <?php if ($myLeave): ?>
      <ul class="divide-y divide-zinc-100 border-t border-zinc-200 text-[13px]">
        <?php foreach ($myLeave as $l):
          $cls = ['Pending' => 'text-amber-700', 'Approved' => 'text-emerald-700', 'Rejected' => 'text-rose-700'][$l['status']] ?? ''; ?>
          <li class="flex flex-wrap items-center gap-3 px-5 py-2">
            <span class="w-20 text-[12px] font-medium <?= $cls ?>"><?= e($l['status']) ?></span>
            <span class="flex-1"><?= e($l['leave_type']) ?> · <?= date('j M', strtotime($l['start_date'])) ?><?= $l['end_date'] !== $l['start_date'] ? ' – ' . date('j M', strtotime($l['end_date'])) : '' ?>
              <span class="text-zinc-400">(<?= (int) $l['days_count'] ?>d)</span><?= $l['admin_notes'] ? ' <span class="text-zinc-500">— ' . e($l['admin_notes']) . '</span>' : '' ?></span>
            <?php if ($l['status'] === 'Pending'): ?>
              <form method="post" onsubmit="return confirm('Cancel this request?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_leave"><input type="hidden" name="id" value="<?= $l['id'] ?>">
                <button class="text-[12px] text-rose-600 hover:underline">Cancel</button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

<?php endif; ?>

<?php if ($TAB === 'records'): ?>
  <!-- Month -->
  <section class="rounded-lg border border-zinc-200 bg-white shadow-card">
    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 px-5 py-3">
      <h2 class="text-[13px] font-semibold text-zinc-900">Attendance for <?= date('F Y', strtotime($from)) ?></h2>
      <form class="ml-auto flex items-center gap-2">
        <input type="month" name="month" value="<?= e($month) ?>" max="<?= date('Y-m') ?>" onchange="this.form.submit()"
               class="rounded-md border border-zinc-200 px-2 py-1 text-[13px]">
      </form>
    </div>
    <div class="grid grid-cols-3 divide-x divide-zinc-100 border-b border-zinc-200 text-center">
      <div class="p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= $presentDays ?></p><p class="text-[11px] text-zinc-500">Days present</p></div>
      <div class="p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= att_hm($totalSecs) ?></p><p class="text-[11px] text-zinc-500">Hours worked</p></div>
      <div class="p-3"><p class="text-lg font-semibold tabular-nums text-zinc-900"><?= count($comp) + count($od) ?></p><p class="text-[11px] text-zinc-500">Comp-off / On duty</p></div>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left text-[13px]">
        <thead class="text-[11px] uppercase tracking-wider text-zinc-400">
          <tr><th class="px-5 py-2 font-medium">Date</th><th class="px-3 py-2 font-medium">In</th><th class="px-3 py-2 font-medium">Out</th>
              <th class="px-3 py-2 font-medium">Location</th><th class="px-5 py-2 text-right font-medium">Hours</th></tr>
        </thead>
        <tbody class="divide-y divide-zinc-100">
        <?php if (!$days && !$comp && !$od): ?>
          <tr><td colspan="5" class="px-5 py-8 text-center text-zinc-400">No attendance recorded this month.</td></tr>
        <?php endif; ?>
        <?php foreach ($days as $date => $punches):
          $secs = 0; foreach ($punches as $p) $secs += att_seconds($p['punch_in'], $p['punch_out']); ?>
          <?php foreach ($punches as $i => $p): ?>
            <tr class="<?= $i ? '' : 'border-t border-zinc-200' ?>">
              <td class="px-5 py-2 whitespace-nowrap">
                <?php if (!$i): ?>
                  <span class="font-medium text-zinc-900"><?= date('D, j M', strtotime($date)) ?></span>
                  <?php if (isset($od[$date])): ?><span class="ml-1 rounded bg-sky-50 px-1.5 text-[10px] font-medium text-sky-700">OD</span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 tabular-nums"><?= att_time($p['punch_in']) ?>
                <?php if ($p['selfie_punchin']): ?><a target="_blank" href="<?= url('att-file.php?selfie=' . urlencode($p['selfie_punchin'])) ?>" class="ml-2 inline-block align-middle"><img loading="lazy" alt="" src="<?= url('att-file.php?selfie=' . urlencode($p['selfie_punchin'])) ?>" class="h-10 w-10 rounded-md border border-zinc-200 object-cover hover:ring-2 hover:ring-brand-400"></a><?php endif; ?></td>
              <td class="px-3 py-2 tabular-nums"><?= att_time($p['punch_out']) ?>
                <?php if ($p['selfie_punchout']): ?><a target="_blank" href="<?= url('att-file.php?selfie=' . urlencode($p['selfie_punchout'])) ?>" class="ml-2 inline-block align-middle"><img loading="lazy" alt="" src="<?= url('att-file.php?selfie=' . urlencode($p['selfie_punchout'])) ?>" class="h-10 w-10 rounded-md border border-zinc-200 object-cover hover:ring-2 hover:ring-brand-400"></a><?php endif; ?></td>
              <td class="max-w-[18rem] px-3 py-2 text-[12px] text-zinc-500"><?= att_place_html($p, 'in') ?: '—' ?><?= $p['punch_out_location'] ? '<br>→ ' . att_place_html($p, 'out') : '' ?></td>
              <td class="px-5 py-2 text-right tabular-nums <?= $i ? 'text-zinc-400' : 'font-medium text-zinc-900' ?>">
                <?= $i ? att_hm(att_seconds($p['punch_in'], $p['punch_out'])) : att_hm($secs) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php foreach ($comp as $d => $earned): ?>
          <tr class="border-t border-zinc-200"><td class="px-5 py-2 font-medium text-zinc-900"><?= date('D, j M', strtotime($d)) ?>
            <span class="ml-1 rounded bg-violet-50 px-1.5 text-[10px] font-medium text-violet-700">Comp-off</span></td>
            <td colspan="4" class="px-3 py-2 text-zinc-500"><?= $earned ? 'Earned on ' . date('j M Y', strtotime($earned)) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
</div>

<?php if ($TAB === 'punch'): ?>
<script>
// Tick from the server's clock (the one punches are stamped with), not the device's,
// so a phone that is a few minutes off still shows the time that will be recorded.
const clockSkew = <?= (int) round(microtime(true) * 1000) ?> - Date.now();
setInterval(() => { document.getElementById('clock').textContent =
  new Date(Date.now() + clockSkew).toLocaleTimeString('en-US', {timeZone:'Asia/Kolkata', hour:'2-digit', minute:'2-digit', second:'2-digit'}); }, 1000);

let stream = null, haveSelfie = false, haveGps = false;
const geoRequired = true; // every punch records where it was made
const $ = id => document.getElementById(id);

function ready() { $('submitBtn').disabled = !(haveSelfie && (haveGps || !geoRequired)); }

async function openPunch() {
  $('punchPanel').classList.remove('hidden');
  $('punchPanel').scrollIntoView({behavior: 'smooth'});
  try {
    stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user'}, audio: false});
    $('video').srcObject = stream; $('video').play();
  } catch (e) {
    alert('Camera unavailable: ' + e.message + '\nThe camera needs HTTPS (or localhost) and permission.');
  }
  ensureLocation();
}
function closePunch() {
  stopWatch();
  if (stream) stream.getTracks().forEach(t => t.stop());
  $('punchPanel').classList.add('hidden');
}
function capture() {
  const v = $('video'), c = $('canvas');
  if (!v.videoWidth) return;
  c.width = 640; c.height = Math.round(640 * v.videoHeight / v.videoWidth);
  const ctx = c.getContext('2d');
  ctx.clearRect(0, 0, c.width, c.height);
  ctx.save();
  ctx.translate(c.width, 0);
  ctx.scale(-1, 1);
  ctx.drawImage(v, 0, 0, c.width, c.height);
  ctx.restore();
  const data = c.toDataURL('image/jpeg', 0.8);
  $('selfie').value = data; $('shot').src = data; $('shot').classList.remove('hidden');
  $('captureBtn').classList.add('hidden'); $('retakeBtn').classList.remove('hidden');
  haveSelfie = true; ready();
}
function retake() {
  $('selfie').value = ''; $('shot').classList.add('hidden');
  $('captureBtn').classList.remove('hidden'); $('retakeBtn').classList.add('hidden');
  haveSelfie = false; ready();
}
/*
 * Precise location. The first fix a browser returns is often a rough network/IP
 * guess (1-5 km). GPS starts as soon as the page opens, so it is warm by the time
 * the selfie is taken; we keep the most accurate reading and never give up - the
 * button unlocks the moment a reading within MAX_M (the server's limit) arrives,
 * and watching stops once one within GOOD_M does.
 */
const GOOD_M = 20, MAX_M = <?= ATT_MAX_ACCURACY_M ?>, HINT_MS = 20000, FRESH_MS = 120000;
let watchId = null, best = null, gpsTimer = null, gpsStart = 0;
function gpsBox(cls, html) {
  const tone = {ok: 'border-emerald-200 bg-emerald-50 text-emerald-700', wait: 'border-amber-200 bg-amber-50 text-amber-700', bad: 'border-rose-200 bg-rose-50 text-rose-700'}[cls];
  $('gps').className = 'mt-4 rounded-md border px-3 py-2 text-[12px] ' + tone; $('gps').innerHTML = html;
}
function stopWatch() { if (watchId !== null) navigator.geolocation.clearWatch(watchId); watchId = null; clearInterval(gpsTimer); }
function useFix(p) {
  $('lat').value = p.coords.latitude; $('lng').value = p.coords.longitude; $('accuracy').value = p.coords.accuracy;
  haveGps = p.coords.accuracy <= MAX_M; ready();
}
function gpsStatus() {
  const secs = Math.round((Date.now() - gpsStart) / 1000);
  const tip = Date.now() - gpsStart > HINT_MS ? ' Turn on GPS / high-accuracy location, or move near a window.' : '';
  if (!best) { gpsBox('wait', `Getting a precise GPS fix… ${secs}s.${tip}`); return; }
  const acc = Math.round(best.coords.accuracy);
  const map = `<a class="underline" target="_blank" href="https://www.google.com/maps?q=${best.coords.latitude},${best.coords.longitude}">view on map</a>`;
  if (acc <= GOOD_M) gpsBox('ok', `Precise location captured (±${acc} m) · ${map}`);
  else if (acc <= MAX_M) gpsBox('ok', `Location ready (±${acc} m) · ${map} - still sharpening, you can punch now.`);
  else gpsBox('wait', `Improving accuracy… now ±${acc} m, need ±${MAX_M} m · ${secs}s · ${map}.${tip}`);
}
function locate() {
  if (!navigator.geolocation) { gpsBox('bad', 'This browser cannot share location.'); return; }
  stopWatch(); best = null; haveGps = false; ready(); gpsStart = Date.now(); gpsStatus();
  watchId = navigator.geolocation.watchPosition(p => {
    if (best && p.coords.accuracy >= best.coords.accuracy) return;
    best = p; useFix(p); gpsStatus();
    if (p.coords.accuracy <= GOOD_M) stopWatch();
  }, e => {
    if (e.code === e.TIMEOUT) return; // keep watching; GPS may still lock
    stopWatch();
    gpsBox('bad', 'Location not available: ' + e.message + '. Turn on GPS / location and allow it for this site. '
      + '<button type="button" class="underline" onclick="locate()">Try again</button>');
  }, {enableHighAccuracy: true, maximumAge: 0});
  gpsTimer = setInterval(() => { if (!best || best.coords.accuracy > GOOD_M) gpsStatus(); }, 1000);
}
// A fix taken minutes ago may no longer be where the person is now.
function ensureLocation() { if (watchId === null && (!best || Date.now() - best.timestamp > FRESH_MS)) locate(); }
ensureLocation(); // warm GPS up while the page is open
$('punchForm').addEventListener('submit', () => { $('submitBtn').disabled = true; $('submitBtn').textContent = 'Saving…'; });
</script>
<?php endif; ?>
<?php require __DIR__ . '/layout/footer.php'; ?>
