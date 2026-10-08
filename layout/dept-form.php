<?php
/**
 * The Daily Tasks page for departments that report through a Google Form.
 * Expects $deptForm (from DEPT_FORMS), $me and $myDept. Posts back to tasks.php,
 * which forwards the answers to the Google Form.
 */
$old = $_SESSION['dept_form_old'] ?? [];
unset($_SESSION['dept_form_old']);

$prefill = [
    'name'  => $me['name'] ?? '',
    'today' => date('Y-m-d'),
    'now'   => date('H:i'),
    'dept'  => $myDept,
    'email' => $me['email'] ?? '',
];
$inputCls = 'w-full rounded-md border border-zinc-200 bg-white px-3 py-2.5 text-base sm:py-2 sm:text-[13px] text-zinc-800 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-100';
?>
<div class="-mx-4 mt-0 max-w-2xl sm:mx-auto sm:mt-2">
  <form method="post" class="overflow-hidden border-y border-zinc-200 sm:rounded-xl sm:border bg-white shadow-sm" id="deptForm">
    <?= csrf_field() ?>

    <div class="bg-brand-500 px-4 py-4 text-white sm:px-5">
      <p class="text-[11px] font-medium uppercase tracking-wide text-white/80">Daily Tasks · <?= e(date('D, M j, Y')) ?></p>
      <h2 class="mt-0.5 text-lg font-semibold"><?= e($deptForm['label']) ?></h2>
      <p class="mt-0.5 text-[12px] text-white/80"><?= e($deptForm['title']) ?></p>
    </div>

    <div class="divide-y divide-zinc-100">
      <?php if ($deptForm['collect_email']): ?>
        <div class="px-4 py-4 sm:px-5">
          <label class="block text-[12px] font-semibold text-zinc-800">Email <span class="text-red-500">*</span></label>
          <input type="email" name="f[email]" required value="<?= e($old['email'] ?? $prefill['email']) ?>" class="mt-1.5 <?= $inputCls ?>">
        </div>
      <?php endif; ?>

      <?php foreach ($deptForm['fields'] as $fd):
        $name = 'f[' . $fd['entry'] . ']';
        $val  = $old[$fd['entry']] ?? (isset($fd['prefill']) ? $prefill[$fd['prefill']] : '');
        $req  = $fd['required'];
      ?>
        <div class="px-4 py-4 sm:px-5">
          <label class="block text-[12px] font-semibold text-zinc-800">
            <?= e($fd['label']) ?><?php if ($req): ?> <span class="text-red-500">*</span><?php endif; ?>
          </label>
          <?php if (!empty($fd['duration'])): ?>
            <p class="text-[11px] text-zinc-400">Hours : minutes</p>
          <?php endif; ?>

          <?php if ($fd['type'] === 'textarea'): ?>
            <textarea name="<?= $name ?>" rows="3" <?= $req ? 'required' : '' ?> class="mt-1.5 <?= $inputCls ?>"><?= e($val) ?></textarea>

          <?php elseif ($fd['type'] === 'radio' || $fd['type'] === 'checkbox'):
            $isCb = $fd['type'] === 'checkbox';
            $sel  = (array) $val; ?>
            <div class="mt-2 flex flex-wrap gap-2" <?= $isCb && $req ? 'data-need-one' : '' ?>>
              <?php foreach ($fd['options'] as $i => $opt): ?>
                <label class="cursor-pointer">
                  <input type="<?= $fd['type'] ?>" name="<?= $name ?><?= $isCb ? '[]' : '' ?>" value="<?= e($opt) ?>"
                         <?= in_array($opt, $sel, true) ? 'checked' : '' ?> <?= !$isCb && $req && $i === 0 ? 'required' : '' ?> class="peer sr-only">
                  <span class="inline-block rounded-full border border-zinc-200 px-3.5 py-2 text-[13px] sm:px-3 sm:py-1.5 sm:text-[12px] text-zinc-600 transition hover:border-brand-300 peer-checked:border-brand-500 peer-checked:bg-brand-500 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-200"><?= e($opt) ?></span>
                </label>
              <?php endforeach; ?>
            </div>

          <?php else:
            $type = ['date' => 'date', 'time' => 'time'][$fd['type']] ?? 'text'; ?>
            <input type="<?= $type ?>" name="<?= $name ?>" value="<?= e($val) ?>" <?= $req ? 'required' : '' ?>
                   class="mt-1.5 <?= $inputCls ?> <?= $type !== 'text' ? 'sm:max-w-xs' : '' ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="sticky bottom-0 flex flex-col-reverse gap-2 border-t border-zinc-100 bg-zinc-50 px-4 py-3 sm:static sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-5">
      <p class="text-center text-[11px] text-zinc-400 sm:text-left">Goes straight to the <?= e($deptForm['label']) ?> Google Form.</p>
      <button type="submit" id="deptSubmit"
              class="w-full rounded-md bg-brand-500 px-4 py-3 text-sm font-semibold sm:w-auto sm:py-2 sm:text-[13px] text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-60">
        Submit
      </button>
    </div>
  </form>
</div>

<script>
/* Required checkbox groups need at least one tick; then lock the button so it can't double-submit. */
document.getElementById('deptForm').addEventListener('submit', function (ev) {
  for (const g of this.querySelectorAll('[data-need-one]')) {
    if (!g.querySelector('input:checked')) {
      ev.preventDefault();
      alert('Please choose at least one option for: ' + g.parentElement.querySelector('label').innerText.replace('*', '').trim());
      g.scrollIntoView({ block: 'center' });
      return;
    }
  }
  // The host's ModSecurity rejects posts carrying links (https://...) and some
  // words in free text ("Not Acceptable"). Send the answers as one base64 JSON
  // value instead; tasks.php unpacks it. The plain fields are a fallback only.
  const ans = {};
  for (const [k, v] of new FormData(this)) {
    const m = k.match(/^f\[([^\]]+)\](\[\])?$/);
    if (!m) continue;
    if (m[2]) (ans[m[1]] = ans[m[1]] || []).push(v); else ans[m[1]] = v;
  }
  const bytes = new TextEncoder().encode(JSON.stringify(ans));
  let bin = '';
  bytes.forEach(c => { bin += String.fromCharCode(c); });
  const packed = document.createElement('input');
  packed.type = 'hidden'; packed.name = 'fb'; packed.value = btoa(bin);
  this.appendChild(packed);
  this.querySelectorAll('[name^="f["]').forEach(el => { el.disabled = true; });

  const b = document.getElementById('deptSubmit');
  b.disabled = true; b.textContent = 'Submitting…';
});
</script>
