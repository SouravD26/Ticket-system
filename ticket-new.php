<?php
require_once __DIR__ . '/includes/functions.php';
require_can('can_raise_tickets', 'ticket creation');
require_once __DIR__ . '/includes/upload.php';

$errors      = [];
$departments = all_departments();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $subject  = post('subject');
    $body     = post('body');
    $deptId   = (int) post('department_id');

    if (mb_strlen($subject) < 5)             $errors[] = 'Subject must be at least 5 characters.';
    if (mb_strlen($body) < 10)               $errors[] = 'Please describe the issue in at least 10 characters.';

    if (!$errors) {
        $code = next_ticket_code();
        q('INSERT INTO tickets (code, subject, body, user_id, department_id) VALUES (?,?,?,?,?)',
          [$code, $subject, $body, user()['id'], $deptId ?: null]);
        $ticketId = (int) db()->lastInsertId();

        try {
            store_uploads($ticketId, null, $_FILES['files'] ?? null);
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
    <div class="mb-3 rounded-xl border border-rose-300 bg-rose-50 px-4 py-2.5 text-sm text-rose-700"><?= e($er) ?></div>
  <?php endforeach; ?>

  <form method="post" enctype="multipart/form-data" class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
    <?= csrf_field() ?>
    <h2 class="text-lg font-semibold text-slate-900">Open a support ticket</h2>
    <p class="mt-1 text-sm text-slate-500">Give us as much detail as you can — it speeds things up.</p>

    <div class="mt-6 space-y-5">
      <div>
        <label class="mb-1.5 block text-sm text-slate-600">Subject</label>
        <input name="subject" required value="<?= e($_POST['subject'] ?? '') ?>" placeholder="Short summary of the problem"
               class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25">
      </div>

      <div>
        <label class="mb-1.5 block text-sm text-slate-600">Department</label>
        <select name="department_id" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-brand-400">
          <option value="">— Not sure —</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= (int)($_POST['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="mt-1.5 text-xs text-slate-500">Priority is set by the Super Admin when the ticket is assigned.</p>
      </div>

      <div>
        <label class="mb-1.5 block text-sm text-slate-600">Description</label>
        <textarea name="body" rows="8" required placeholder="What happened? What did you expect? Any steps to reproduce?"
                  class="w-full resize-y rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"><?= e($_POST['body'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="mb-1.5 block text-sm text-slate-600">Attachments <span class="text-slate-500">(optional, max 5 MB each)</span></label>
        <input type="file" name="files[]" multiple
               class="w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-500 file:mr-4 file:rounded-lg file:border-0 file:bg-brand-500 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-brand-600">
      </div>
    </div>

    <div class="mt-7 flex gap-3">
      <button class="rounded-xl bg-brand-500 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-600">Submit ticket</button>
      <a href="<?= url('tickets.php') ?>" class="rounded-xl border border-slate-200 px-5 py-3 text-sm text-slate-600 hover:bg-slate-50">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
