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
        <label class="mb-1.5 block text-[13px] text-zinc-600">Description</label>
        <textarea name="body" rows="8" required placeholder="What happened? What did you expect? Any steps to reproduce?"
                  class="w-full resize-y rounded-md border border-zinc-200 bg-white px-3 py-2 text-[13px] outline-none focus:border-brand-400 focus:ring-2 focus:ring-brand-400/25"><?= e($_POST['body'] ?? '') ?></textarea>
      </div>

      <div>
        <label class="mb-1.5 block text-[13px] text-zinc-600">Attachments <span class="text-zinc-500">(optional, max 5 MB each)</span></label>
        <input type="file" name="files[]" multiple
               class="w-full rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-3 py-2 text-[13px] text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-brand-500 file:px-4 file:py-2 file:text-[13px] file:font-medium file:text-white hover:file:bg-brand-600">
      </div>
    </div>

    <div class="mt-7 flex gap-3">
      <button class="rounded-md bg-brand-500 px-3 py-2 text-[13px] font-semibold text-white hover:bg-brand-600">Submit ticket</button>
      <a href="<?= url('tickets.php') ?>" class="rounded-md border border-zinc-200 px-3 py-2 text-[13px] text-zinc-600 hover:bg-zinc-50">Cancel</a>
    </div>
  </form>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
