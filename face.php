<?php
/**
 * The face attendance kiosk. Leave it running on a tablet or PC at the entrance:
 * a recognised face punches that person in, and the next sighting punches them out.
 * Matching runs in the browser (face-api.js); the server records the punch with its own clock.
 */
require_once __DIR__ . '/includes/attendance.php';
require_login();
if (!can_run_kiosk()) { http_response_code(403); die('403 — Face attendance is for the kiosk operator.'); }

$pageTitle = 'Face Attendance';
require __DIR__ . '/layout/header.php';
?>
<div class="mx-auto grid max-w-6xl gap-4 lg:grid-cols-[1fr_22rem]">
  <section class="rounded-lg border border-zinc-200 bg-white p-4 shadow-card">
    <div class="relative aspect-video overflow-hidden rounded-md bg-zinc-900">
      <video id="video" playsinline muted class="h-full w-full object-cover"></video>
      <div id="banner" class="pointer-events-none absolute inset-x-4 bottom-4 hidden rounded-md px-4 py-3 text-center text-lg font-semibold text-white shadow-lg"></div>
    </div>
    <div class="mt-3 flex flex-wrap items-center gap-3">
      <button id="startBtn" onclick="start()" disabled class="<?= ATT_BTN ?> disabled:opacity-50">Start camera</button>
      <button id="stopBtn" onclick="stop()" class="<?= ATT_BTN2 ?> hidden">Stop</button>
      <p id="status" class="text-[13px] text-zinc-500">Loading face models…</p>
    </div>
    <p class="mt-2 text-[11px] text-zinc-400">Only people with a face photo on their employee record are recognised. The same face is ignored for 2 minutes after a punch.</p>
  </section>

  <aside class="rounded-lg border border-zinc-200 bg-white shadow-card">
    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Today</h2>
      <span class="text-[11px] text-zinc-500"><span id="known">0</span> faces enrolled</span>
    </div>
    <ul id="log" class="max-h-[32rem] divide-y divide-zinc-100 overflow-y-auto text-[13px]">
      <li class="px-4 py-6 text-center text-zinc-400">No punches yet today.</li>
    </ul>
  </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>
<script>
const API = <?= json_encode(url('att-api.php')) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
const MODELS = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model/';
const THRESHOLD = 0.45;      // Euclidean distance; lower is stricter (same as the old kiosk)
const LOCAL_COOLDOWN = 6000; // ms before the same face is sent to the server again
const $ = id => document.getElementById(id);

let people = [], stream = null, running = false, busy = false, lastSeen = {};

function status(t) { $('status').textContent = t; }
function post(action, data) {
  return fetch(API + '?action=' + action, {method: 'POST', headers: {'X-CSRF': CSRF}, body: new URLSearchParams(data)}).then(r => r.json());
}
function banner(text, ok) {
  const b = $('banner');
  b.textContent = text;
  b.className = 'pointer-events-none absolute inset-x-4 bottom-4 rounded-md px-4 py-3 text-center text-lg font-semibold text-white shadow-lg ' + (ok ? 'bg-emerald-600/90' : 'bg-rose-600/90');
  clearTimeout(banner.t); banner.t = setTimeout(() => b.classList.add('hidden'), 3500);
}
async function loadToday() {
  const r = await fetch(API + '?action=today').then(r => r.json());
  if (!r.success || !r.records.length) return;
  $('log').innerHTML = r.records.map(x => `<li class="px-4 py-2"><p class="font-medium text-zinc-900">${esc(x.name)} <span class="font-normal text-zinc-400">${esc(x.employee_id || '')}</span></p>
    <p class="text-[12px] tabular-nums text-zinc-500">In ${x.in}${x.out ? ' · Out ' + x.out : ' · <span class="text-emerald-600">in office</span>'}</p></li>`).join('');
}
function esc(s) { return String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

async function init() {
  await faceapi.nets.tinyFaceDetector.loadFromUri(MODELS);
  await faceapi.nets.faceLandmark68Net.loadFromUri(MODELS);
  await faceapi.nets.faceRecognitionNet.loadFromUri(MODELS);
  status('Loading employees…');
  const r = await fetch(API + '?action=people').then(r => r.json());
  if (!r.success) { status(r.message); return; }

  // People with only a photo get their descriptor computed once, then stored.
  let i = 0;
  for (const p of r.people) {
    if (!p.descriptor && p.photo) {
      status(`Reading face photos… ${++i}`);
      try {
        const img = await faceapi.fetchImage(p.photo);
        const d = await faceapi.detectSingleFace(img, new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceDescriptor();
        if (d) { p.descriptor = Array.from(d.descriptor); post('save_descriptor', {id: p.id, descriptor: JSON.stringify(p.descriptor)}); }
      } catch (e) { console.warn('Photo for', p.name, e); }
    }
    if (p.descriptor) people.push(p);
  }
  $('known').textContent = people.length;
  status(people.length ? 'Ready. Start the camera.' : 'No employee has a face photo yet — add one from Employees.');
  $('startBtn').disabled = !people.length;
  loadToday();
}

async function start() {
  try {
    stream = await navigator.mediaDevices.getUserMedia({video: {width: 1280, height: 720}, audio: false});
  } catch (e) { status('Camera unavailable: ' + e.message + ' (needs HTTPS or localhost).'); return; }
  $('video').srcObject = stream; await $('video').play();
  running = true; $('startBtn').classList.add('hidden'); $('stopBtn').classList.remove('hidden');
  status('Watching for faces…'); loop();
}
function stop() {
  running = false; if (stream) stream.getTracks().forEach(t => t.stop());
  $('startBtn').classList.remove('hidden'); $('stopBtn').classList.add('hidden'); status('Stopped.');
}

function distance(a, b) { let s = 0; for (let i = 0; i < a.length; i++) { const d = a[i] - b[i]; s += d * d; } return Math.sqrt(s); }

async function loop() {
  if (!running) return;
  if (!busy) {
    busy = true;
    try {
      const d = await faceapi.detectSingleFace($('video'), new faceapi.TinyFaceDetectorOptions()).withFaceLandmarks().withFaceDescriptor();
      if (d) {
        let best = null, bestD = THRESHOLD;
        for (const p of people) { const x = distance(d.descriptor, p.descriptor); if (x < bestD) { bestD = x; best = p; } }
        if (best && Date.now() - (lastSeen[best.id] || 0) > LOCAL_COOLDOWN) {
          lastSeen[best.id] = Date.now();
          const r = await post('punch', {id: best.id});
          if (!r.repeat) { banner(r.message + (r.time ? ' · ' + r.time : ''), r.success); loadToday(); }
        }
      }
    } catch (e) { console.error(e); }
    busy = false;
  }
  setTimeout(loop, 150);
}

// Keep the session alive and the list fresh on an all-day kiosk.
setInterval(loadToday, 60000);
(function wait() { typeof faceapi === 'undefined' ? setTimeout(wait, 300) : init().catch(e => status('Could not start: ' + e.message)); })();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
