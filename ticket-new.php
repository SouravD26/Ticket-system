<?php
require_once __DIR__ . '/includes/functions.php';
require_can('can_raise_tickets', 'ticket creation');
require_once __DIR__ . '/includes/upload.php';

$errors      = [];
/* The department is fixed to the requester's own; it is never taken from the form. */
$myDept = q('SELECT d.id, d.name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?',
            [user()['id']])->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $subject  = post('subject');
    $body     = post('body');
    $deptId   = (int) ($myDept['id'] ?? 0);
    $location = post('location');
    $trained  = post('trained_before');

    if (mb_strlen($subject) < 5)             $errors[] = 'Subject must be at least 5 characters.';
    if (!in_array($location, ticket_locations(), true)) $errors[] = 'Please choose a location.';
    if (!in_array($trained, ['yes', 'no'], true)) $errors[] = 'Please tell us whether you have been trained to troubleshoot this issue.';
    if (mb_strlen($body) < 10)               $errors[] = 'Please describe the issue in at least 10 characters.';

    if (!$errors) {
        $code = next_ticket_code();
        q('INSERT INTO tickets (code, subject, body, user_id, department_id, location, trained_before) VALUES (?,?,?,?,?,?,?)',
          [$code, $subject, $body, user()['id'], $deptId ?: null, $location, $trained]);
        $ticketId = (int) db()->lastInsertId();

        try {
            store_uploads($ticketId, null, $_FILES['files'] ?? null);
            store_uploads($ticketId, null, $_FILES['photos'] ?? null);
        } catch (Throwable $ex) {
            flash('Ticket created, but an attachment failed: ' . $ex->getMessage(), 'error');
        }

        log_activity($ticketId, 'created', 'Ticket opened');
        flash('Ticket ' . $code . ' has been created.');
        redirect('ticket-view.php?id=' . $ticketId);
    }
}

$pageTitle = 'New Ticket';
require __DIR__ . '/layout/header.php';
?>
<div class="mx-auto max-w-3xl">
  <?php foreach ($errors as $er): ?>
    <div class="mb-3 rounded-md border border-rose-300 bg-rose-50 px-3 py-2 text-[13px] text-rose-700"><?= e($er) ?></div>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" class="rounded-lg border border-zinc-200 bg-white shadow-sm p-6">
    <?= csrf_field() ?>
    <h2 class="text-lg font-semibold text-zinc-900">Open a support ticket</h2>
    <p class="mt-1 text-[13px] text-zinc-500">Give us as much detail as you can — it speeds things up.</p>

    <div class="mt-6 space-y-5">
      <div>
        <label class="mb-1.5 block text-[13px] text-zinc-600">Subject</label>
        <input name="subject" required value="<?= e($_POST['subject'] ?? '') ?>" placeholder="Short summary of the problem"
               class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
      </div>

      <div>
        <label class="mb-1.5 block text-[13px] text-zinc-600">Department</label>
        <input type="text" value="<?= e($myDept['name'] ?? 'Not set') ?>" disabled
               class="w-full cursor-not-allowed rounded-md border border-zinc-200 bg-zinc-100 px-3 py-2 text-[13px] text-zinc-500">
        <p class="mt-1.5 text-[11px] text-zinc-500">Priority is set by the Super Admin when the ticket is assigned.</p>
      </div>

      <div>
        <label for="location" class="mb-1.5 block text-[13px] text-zinc-600">Location</label>
        <select id="location" name="location" required
                class="w-full rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
          <option value="">Select location…</option>
          <?php foreach (ticket_locations() as $loc): ?>
            <option value="<?= e($loc) ?>" <?= ($_POST['location'] ?? '') === $loc ? 'selected' : '' ?>><?= e($loc) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <span class="mb-1.5 block text-[13px] text-zinc-600">Have you been trained to troubleshoot this issue before?</span>
        <div class="flex gap-6">
          <?php foreach (['yes' => 'Yes', 'no' => 'No'] as $val => $lbl): ?>
            <label class="inline-flex items-center gap-2 text-[13px] text-zinc-700">
              <input type="radio" name="trained_before" value="<?= $val ?>" required
                     <?= ($_POST['trained_before'] ?? '') === $val ? 'checked' : '' ?>
                     class="h-4 w-4 border-zinc-300 text-brand-500 focus:ring-brand-400/25">
              <?= $lbl ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div>
        <label class="mb-1.5 block text-[13px] text-zinc-600">Description</label>
        <textarea name="body" rows="8" required placeholder="What happened? What did you expect? Any steps to reproduce?"
                  class="w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"><?= e($_POST['body'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="mb-1.5 block text-[13px] text-zinc-600">Attachments / Photo <span class="text-zinc-500">(optional, max 5 MB each)</span></label>
        <input type="file" id="photoInput" name="photos[]" accept="image/*" capture="environment" class="hidden">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch">
          <input type="file" name="files[]" multiple
                 class="min-w-0 flex-1 rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-3 py-2 text-[13px] text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-brand-500 file:px-4 file:py-2 file:text-[13px] file:font-medium file:text-white hover:file:bg-brand-600">
          <button type="button" id="photoBtn" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] text-zinc-700 hover:bg-zinc-50">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 012-2h2l1.5-2h7L17 6h2a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V8z"/><circle cx="12" cy="13" r="3.5"/></svg>
            Capture photo
          </button>
        </div>
        <div id="photoWrap" class="relative mt-3 hidden w-fit">
          <img id="photoPreview" alt="Captured photo" class="max-h-56 rounded-md border border-zinc-200">
          <button type="button" id="photoClear" class="absolute right-1.5 top-1.5 rounded-md bg-white/90 px-2 py-1 text-[12px] text-zinc-700 shadow hover:bg-white">Remove</button>
        </div>
        <p class="mt-1.5 text-[11px] text-zinc-500">Choose files, or capture a photo with the phone camera or webcam.</p>
      </div>
    </div>

    <!-- Webcam capture for desktops; phones use the native camera through the input above. -->
    <div id="camModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-zinc-900/60 p-4">
      <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-lg">
        <video id="camVideo" autoplay playsinline class="w-full rounded-md bg-black"></video>
        <div class="mt-3 flex justify-end gap-3">
          <button type="button" id="camCancel" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-600 hover:bg-zinc-100">Cancel</button>
          <button type="button" id="camSnap" class="rounded-md bg-brand-500 px-4 py-2 text-[13px] font-semibold text-white hover:bg-brand-600">Take photo</button>
        </div>
      </div>
    </div>

    <div class="mt-7 flex gap-3">
      <button class="rounded-md bg-brand-500 px-3 py-2 text-[13px] font-semibold text-white hover:bg-brand-600">Submit ticket</button>
      <a href="<?= url('tickets.php') ?>" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-600 hover:bg-zinc-50">Cancel</a>
    </div>
  </form>
</div>

<script>
(function () {
  var input   = document.getElementById('photoInput'),
      btn     = document.getElementById('photoBtn'),
      clear   = document.getElementById('photoClear'),
      preview = document.getElementById('photoPreview'),
      wrap    = document.getElementById('photoWrap'),
      modal   = document.getElementById('camModal'),
      video   = document.getElementById('camVideo'),
      stream  = null;
  var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

  function showPreview(file) {
    preview.src = URL.createObjectURL(file);
    wrap.classList.remove('hidden');
  }

  function stopCam() {
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    stream = null;
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  btn.addEventListener('click', function () {
    if (isMobile || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { input.click(); return; }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s;
      video.srcObject = s;
      modal.classList.remove('hidden');
      modal.classList.add('flex');
    }).catch(function () { input.click(); });   // no webcam or permission denied
  });

  input.addEventListener('change', function () { if (input.files[0]) showPreview(input.files[0]); });

  document.getElementById('camSnap').addEventListener('click', function () {
    var c = document.createElement('canvas');
    c.width = video.videoWidth;
    c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    c.toBlob(function (blob) {
      var file = new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' });
      var dt = new DataTransfer();
      dt.items.add(file);
      input.files = dt.files;
      showPreview(file);
      stopCam();
    }, 'image/jpeg', 0.85);
  });
  document.getElementById('camCancel').addEventListener('click', stopCam);

  clear.addEventListener('click', function () {
    input.value = '';
    wrap.classList.add('hidden');
    preview.removeAttribute('src');
  });
})();
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
