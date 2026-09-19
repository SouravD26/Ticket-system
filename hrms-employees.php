<?php
/**
 * HRMS employee records: the same `users` rows the ticket system signs in with,
 * seen through their HR fields. Replaces the old admin/employees.php, admin.php
 * (admins and their rights), manage_passwords, manage_employee_photos and import_employees.
 */
require_once __DIR__ . '/includes/attendance.php';
require_att('manage_employees');
$me = user();

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
// Only the Super Admin hands out roles above Employee; admins with the right manage staff records.
$roles = is_super()
    ? ['employee' => 'Employee', 'hr' => 'HR', 'it' => 'IT'] // system roles are given on System Accounts
    : ['employee' => 'Employee'];

/** Saves an uploaded face photo as employee_photos/{id}.jpg|png and forgets the old descriptor. */
function save_face_photo(int $id, array $f): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return 'The photo could not be uploaded (max 5 MB).';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
    if (!$ext) return 'The photo must be a JPG or PNG.';
    $dir = ATT_DIR . '/employee_photos';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    foreach (glob("$dir/$id.*") as $old) @unlink($old);
    move_uploaded_file($f['tmp_name'], "$dir/$id.$ext");
    q('UPDATE users SET face_descriptor = NULL WHERE id = ?', [$id]);
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'save') {
        $id = (int) post('id');
        $old = $id ? q('SELECT * FROM users WHERE id = ?', [$id])->fetch() : null;
        if ($id && !$old) redirect('hrms-employees.php');
        // An admin with the right edits employees; only the Super Admin touches other admins.
        if ($old && !is_super() && $old['role'] !== 'employee') { http_response_code(403); die('403'); }
        if ($old && in_array($old['role'], ATT_NO_PUNCH_ROLES, true)) {
            flash('That is a system account - manage it under System Accounts.', 'error');
            redirect('hrms-employees.php');
        }

        $v = [
            'name' => post('name'), 'employee_id' => post('employee_id'), 'phone' => preg_replace('/\D/', '', post('phone')),
            'email' => post('email') ?: null, 'department_id' => (int) post('department_id') ?: null,
            'designation' => post('designation') ?: null, 'company' => post('company') ?: null,
            'location' => post('location') ?: null, 'shift_time' => post('shift_time') ?: null,
            'date_of_joining' => post('date_of_joining') ?: null, 'status' => post('status') === 'Resign' ? 'Resign' : 'Working',
            'date_of_exit' => post('date_of_exit') ?: null, 'sex' => in_array(post('sex'), ['Male', 'Female', 'Other'], true) ? post('sex') : null,
            'week_off' => in_array(post('week_off'), WEEKDAYS, true) ? post('week_off') : null,
            'geo_restricted' => post('geo_restricted') ? 1 : 0,
            'role' => isset($roles[post('role')]) ? post('role') : ($old['role'] ?? 'employee'),
        ];
        if ($v['status'] === 'Working') $v['date_of_exit'] = null;
        $v['is_active'] = $v['status'] === 'Working' ? 1 : 0;
        if ($old && (int) $old['id'] === (int) $me['id']) { $v['role'] = $old['role']; $v['is_active'] = 1; }
        if (is_super() && $v['role'] === 'admin') {
            $r = array_values(array_intersect((array) ($_POST['rights'] ?? []), array_keys(ATT_RIGHTS)));
            $v['rights'] = $r ? json_encode($r) : null;
        }

        $err = null;
        if ($v['name'] === '' || strlen($v['phone']) < 10) $err = 'Name and a 10-digit phone number are required.';
        elseif (!$v['date_of_joining']) $err = 'Date of joining is required.';
        elseif ($v['status'] === 'Resign' && !$v['date_of_exit']) $err = 'Date of exit is required for a resigned employee.';
        elseif ($v['email'] && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err = 'That email address is not valid.';
        elseif (q('SELECT id FROM users WHERE (phone = ? OR username = ?) AND id <> ?', [$v['phone'], $v['phone'], $id])->fetch()) $err = 'Another account already uses this phone number.';
        elseif ($v['email'] && q('SELECT id FROM users WHERE email = ? AND id <> ?', [$v['email'], $id])->fetch()) $err = 'Another account already uses this email.';
        elseif (($_POST['password'] ?? '') !== '' && ($pwErr = password_problem((string) $_POST['password']))) $err = $pwErr;

        if ($err) {
            flash($err, 'error');
            redirect('hrms-employees.php?' . ($id ? "edit=$id" : 'new=1'));
        }

        $pass = (string) ($_POST['password'] ?? '');
        if ($old) {
            // The phone is the login; keep the user name in step when it was the phone.
            if ($old['username'] === $old['phone']) $v['username'] = $v['phone'];
            if ($pass !== '') { $v['password'] = password_hash($pass, PASSWORD_DEFAULT); $v['password_set'] = 1; }
            $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($v)));
            q("UPDATE users SET $sets WHERE id = ?", [...array_values($v), $id]);
        } else {
            $v['username'] = $v['phone'];
            $v['password'] = password_hash($pass !== '' ? $pass : bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            $v['password_set'] = $pass !== '' ? 1 : 0;
            $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($v)));
            q("INSERT INTO users ($cols) VALUES (" . rtrim(str_repeat('?,', count($v)), ',') . ')', array_values($v));
            $id = (int) db()->lastInsertId();
        }
        if ($e = save_face_photo($id, $_FILES['photo'] ?? [])) flash($e, 'error');
        flash('Saved ' . $v['name'] . '.' . ($pass === '' && !$old ? ' Set a password before they can sign in.' : ''));
        redirect('hrms-employees.php' . (post('back') !== '' && preg_match('/^[\w=&%.+-]*$/', post('back')) ? '?' . post('back') : ''));
    }

    if ($action === 'remove_photo') {
        $id = (int) post('id');
        foreach (glob(ATT_DIR . "/employee_photos/$id.*") as $f) @unlink($f);
        q('UPDATE users SET face_descriptor = NULL WHERE id = ?', [$id]);
        flash('Face photo removed.');
        redirect("hrms-employees.php?edit=$id");
    }

    if ($action === 'import') {
        require_once __DIR__ . '/vendor/autoload.php';
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !preg_match('/\.xlsx?$/i', $f['name'])) {
            flash('Choose an .xlsx or .xls file.', 'error'); redirect('hrms-employees.php');
        }
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($f['tmp_name'])->getActiveSheet()->toArray(null, true, false);
        $depts = array_column(all_departments(), 'id', 'name');
        $added = $skipped = 0;
        foreach (array_slice($rows, 1) as $i => $r) {
            // Sl.No | Name | Department | Location | Designation | Company | Date of Joining | Phone | Week Off
            [$sl, $name, $dept, $loc, $desig, $comp, $doj, $phone, $wo] = array_map(fn($x) => trim((string) $x), array_pad($r, 9, ''));
            $phone = preg_replace('/\D/', '', $phone);
            if ($name === '' || strlen($phone) < 10 || q('SELECT id FROM users WHERE phone = ? OR username = ?', [$phone, $phone])->fetch()) { $skipped++; continue; }
            if ($doj !== '') {
                $doj = is_numeric($doj) ? \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $doj)->format('Y-m-d')
                                        : (($d = date_create($doj)) ? $d->format('Y-m-d') : null);
            }
            if ($dept !== '' && !isset($depts[$dept])) { q('INSERT INTO departments (name) VALUES (?)', [$dept]); $depts[$dept] = (int) db()->lastInsertId(); }
            q('INSERT INTO users (name, username, phone, password, role, employee_id, department_id, location, designation, company,
                                  date_of_joining, week_off, status, password_set, is_active)
               VALUES (?,?,?,?,"employee",?,?,?,?,?,?,?,"Working",0,1)',
              [$name, $phone, $phone, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
               is_numeric($sl) ? 'EMP' . str_pad($sl, 4, '0', STR_PAD_LEFT) : null, $depts[$dept] ?? null,
               $loc ?: null, $desig ?: null, $comp ?: null, $doj ?: null, in_array($wo, WEEKDAYS, true) ? $wo : null]);
            $added++;
        }
        flash("Import finished: $added added, $skipped skipped (blank, bad phone or already present). New employees need a password set.");
        redirect('hrms-employees.php');
    }
}

$editing = (int) get_('edit');
$emp = $editing ? q('SELECT * FROM users WHERE id = ?', [$editing])->fetch() : null;
$showForm = $emp || get_('new');

// List
$f = ['q' => get_('q'), 'dept' => (int) get_('dept'), 'company' => get_('company'), 'location' => get_('location'),
      'status' => get_('status', 'Working'), 'photo' => get_('photo')];
// Staff only: Super Admins, Admins and kiosk logins live under System Accounts.
$where = ["u.role NOT IN ('superadmin','admin','hod','face_operator')", "u.phone IS NOT NULL"];
$args = [];
if ($f['q'] !== '') { $where[] = '(u.name LIKE ? OR u.employee_id LIKE ? OR u.phone LIKE ?)'; array_push($args, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['dept'])     { $where[] = 'u.department_id = ?'; $args[] = $f['dept']; }
if ($f['company'])  { $where[] = 'u.company = ?';  $args[] = $f['company']; }
if ($f['location']) { $where[] = 'u.location = ?'; $args[] = $f['location']; }
if (in_array($f['status'], ['Working', 'Resign'], true)) { $where[] = 'u.status = ?'; $args[] = $f['status']; }
$sqlWhere = implode(' AND ', $where);

$total = (int) q("SELECT COUNT(*) FROM users u WHERE $sqlWhere", $args)->fetchColumn();
$per = 50; $page = max(1, (int) get_('page', 1)); $pages = max(1, (int) ceil($total / $per));
$list = q("SELECT u.*, d.name AS dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id
           WHERE $sqlWhere ORDER BY u.name LIMIT $per OFFSET " . (($page - 1) * $per), $args)->fetchAll();

$photoOf = fn(int $id) => (bool) glob(ATT_DIR . "/employee_photos/$id.{jpg,jpeg,png}", GLOB_BRACE);
$qs = fn(array $o) => http_build_query(array_filter(array_merge($f, $o), fn($x) => $x !== '' && $x !== 0));

$pageTitle = 'Employees';
// With the pop-up open, its messages belong inside it rather than behind the backdrop.
$modalFlash = $showForm ? (array) flash() : [];
require __DIR__ . '/layout/header.php';
$lbl = 'mb-1 block text-[11px] font-medium uppercase tracking-wider text-zinc-400';
?>
<div class="mx-auto max-w-6xl space-y-4">

<?php if ($showForm): $e = $emp ?: ['id' => 0, 'status' => 'Working', 'role' => 'employee'];
  // Closing the pop-up returns to the list exactly as it was filtered and paged.
  $closeUrl = url('hrms-employees.php?' . $qs(['page' => $page])); ?>
  <div id="empModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-zinc-900/50 p-4 sm:p-8" role="dialog" aria-modal="true"
       onclick="if (event.target === this) location.href = <?= e(json_encode($closeUrl)) ?>">
  <div class="w-full max-w-4xl">
  <form method="post" enctype="multipart/form-data" class="rounded-lg border border-zinc-200 bg-white p-5 shadow-xl">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>">
    <input type="hidden" name="back" value="<?= e($qs(['page' => $page])) ?>">
    <div class="flex items-center justify-between">
      <h2 class="text-[13px] font-semibold text-zinc-900"><?= $emp ? 'Edit ' . e($emp['name']) : 'New employee' ?></h2>
      <a href="<?= e($closeUrl) ?>" title="Close (Esc)" class="rounded-md p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-900">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg></a>
    </div>

    <?php foreach ($modalFlash as $fl): ?>
      <div class="mt-3 rounded-md border px-3 py-2 text-[13px] <?= $fl['type'] === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><?= e($fl['msg']) ?></div>
    <?php endforeach; ?>
    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <div class="sm:col-span-2"><label class="<?= $lbl ?>">Full name *</label><input name="name" required value="<?= e($e['name'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Employee ID</label><input name="employee_id" value="<?= e($e['employee_id'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Phone (login) *</label><input name="phone" required inputmode="numeric" value="<?= e($e['phone'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Email</label><input name="email" type="email" value="<?= e($e['email'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Department</label><?= att_select('department_id', array_column(all_departments(), 'name', 'id'), (string) ($e['department_id'] ?? ''), 'No department') ?></div>
      <div><label class="<?= $lbl ?>">Designation</label><input name="designation" value="<?= e($e['designation'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Company</label><?= att_select('company', att_companies(), $e['company'] ?? '', '—') ?></div>
      <div><label class="<?= $lbl ?>">Location</label><?= att_select('location', att_locations(), $e['location'] ?? '', '—') ?></div>
      <div><label class="<?= $lbl ?>">Shift</label>
        <?php $sh = att_shifts(); if (!empty($e['shift_time']) && !in_array($e['shift_time'], $sh, true)) $sh[] = $e['shift_time']; ?>
        <?= att_select('shift_time', $sh, $e['shift_time'] ?? '', '—') ?></div>
      <div><label class="<?= $lbl ?>">Week off</label><?= att_select('week_off', WEEKDAYS, $e['week_off'] ?? '', 'None') ?></div>
      <div><label class="<?= $lbl ?>">Sex</label><?= att_select('sex', ['Male', 'Female', 'Other'], $e['sex'] ?? '', '—') ?></div>
      <div><label class="<?= $lbl ?>">Date of joining *</label><input type="date" name="date_of_joining" required value="<?= e($e['date_of_joining'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Status *</label><?= att_select('status', ['Working', 'Resign'], $e['status'], '', 'onchange="exitField()" id="status"') ?></div>
      <div id="exitBox"><label class="<?= $lbl ?>">Date of exit *</label><input type="date" name="date_of_exit" id="exitDate" value="<?= e($e['date_of_exit'] ?? '') ?>" class="<?= ATT_FIELD ?>"></div>
      <div><label class="<?= $lbl ?>">Role</label>
        <?php if ($emp && (int) $emp['id'] === (int) $me['id']): ?><p class="py-2 text-[13px] text-zinc-700"><?= e(ROLE_LABELS[$emp['role']]) ?> (your own)</p>
        <?php else: ?><?= att_select('role', $roles, $e['role'], '', 'onchange="rightsBox()" id="role"') ?><?php endif; ?></div>
      <div><label class="<?= $lbl ?>"><?= $emp ? 'New password' : 'Password' ?></label>
        <?= password_field('password', $emp ? 'Leave blank to keep' : 'Can be set later', false, ATT_FIELD) ?>
        <?php if ($emp && !$emp['password_set']): ?><p class="mt-1 text-[11px] text-amber-600">No password set yet — they cannot sign in.</p><?php endif; ?></div>
    </div>

    <?php if (is_super()): $have = json_decode((string) ($e['rights'] ?? ''), true) ?: []; ?>
      <fieldset id="rights" class="mt-4 rounded-md border border-zinc-200 p-3">
        <legend class="px-1 text-[11px] font-medium uppercase tracking-wider text-zinc-400">HRMS rights for this admin</legend>
        <div class="grid gap-1.5 sm:grid-cols-3 lg:grid-cols-4">
          <?php foreach (ATT_RIGHTS as $k => $l): ?>
            <label class="inline-flex items-center gap-2 text-[12px] text-zinc-700"><input type="checkbox" name="rights[]" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= e($l) ?></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    <?php endif; ?>

    <div class="mt-4 grid gap-4 border-t border-zinc-100 pt-4 sm:grid-cols-[auto_1fr]">
      <div class="h-28 w-28 overflow-hidden rounded-md border border-zinc-200 bg-zinc-50">
        <?php if ($emp && $photoOf((int) $emp['id'])): ?>
          <img src="<?= url('att-file.php?photo=' . $emp['id'] . '&v=' . time()) ?>" class="h-full w-full object-cover" alt="">
        <?php else: ?><p class="grid h-full place-items-center text-[11px] text-zinc-400">No face photo</p><?php endif; ?>
      </div>
      <div>
        <label class="<?= $lbl ?>">Face photo for the kiosk</label>
        <input type="file" name="photo" accept="image/jpeg,image/png" class="text-[13px]">
        <p class="mt-1 text-[11px] text-zinc-500">A clear, front-facing photo with one face. JPG or PNG, up to 5 MB.</p>
        <label class="mt-3 inline-flex items-center gap-2 text-[12px] text-zinc-700">
          <input type="checkbox" name="geo_restricted" value="1" <?= !empty($e['geo_restricted']) ? 'checked' : '' ?>>
          Only allow punching from the office area (GPS restriction)</label>
      </div>
    </div>

    <div class="mt-4 flex items-center gap-2">
      <button class="<?= ATT_BTN ?>">Save employee</button>
      <a href="<?= e($closeUrl) ?>" class="<?= ATT_BTN2 ?>">Cancel</a>
    </div>
  </form>
  <?php if ($emp && $photoOf((int) $emp['id'])): ?>
    <form method="post" onsubmit="return confirm('Remove the face photo?')" class="mt-2 text-right">
      <?= csrf_field() ?><input type="hidden" name="action" value="remove_photo"><input type="hidden" name="id" value="<?= $emp['id'] ?>">
      <button class="rounded bg-white px-2 py-1 text-[12px] text-rose-600 hover:underline">Remove face photo</button>
    </form>
  <?php endif; ?>
  </div>
  </div>
  <script>
    function exitField() { const r = document.getElementById('status').value === 'Resign';
      document.getElementById('exitBox').style.display = r ? '' : 'none'; document.getElementById('exitDate').required = r; }
    function rightsBox() { const b = document.getElementById('rights'), r = document.getElementById('role');
      if (b) b.style.display = (r ? r.value : <?= json_encode($e['role']) ?>) === 'admin' ? '' : 'none'; }
    exitField(); rightsBox();
    // Pop-up: freeze the page behind it, focus the first field, Esc closes.
    document.body.style.overflow = 'hidden';
    document.querySelector('#empModal input[name=name]').focus();
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') location.href = <?= json_encode($closeUrl) ?>; });
  </script>
<?php endif; ?>

  <!-- Filters -->
  <form class="flex flex-wrap items-end gap-2 rounded-lg border border-zinc-200 bg-white p-3 shadow-sm">
    <input name="q" value="<?= e($f['q']) ?>" placeholder="Name, ID or phone" class="<?= ATT_FIELD ?> !w-48">
    <div class="w-40"><?= att_select('dept', array_column(all_departments(), 'name', 'id'), (string) $f['dept'], 'All departments') ?></div>
    <div class="w-40"><?= att_select('company', att_companies(), $f['company'], 'All companies') ?></div>
    <div class="w-36"><?= att_select('location', att_locations(), $f['location'], 'All locations') ?></div>
    <div class="w-32"><?= att_select('status', ['Working' => 'Working', 'Resign' => 'Resigned', 'all' => 'Everyone'], $f['status']) ?></div>
    <button class="<?= ATT_BTN2 ?>">Filter</button>
    <div class="ml-auto flex gap-2">
      <button type="button" onclick="document.getElementById('imp').classList.toggle('hidden')" class="<?= ATT_BTN2 ?>">Import</button>
      <a href="<?= url('hrms-employees.php?new=1') ?>" class="<?= ATT_BTN ?>">Add employee</a>
    </div>
  </form>

  <form id="imp" method="post" enctype="multipart/form-data" class="hidden rounded-lg border border-zinc-200 bg-white p-3 text-[12px] shadow-sm">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <p class="text-zinc-600">Excel columns, first row is a header: <b>Sl.No, Name, Department, Location, Designation, Company, Date of Joining, Phone, Week Off</b>.
      Rows whose phone already exists are skipped.</p>
    <div class="mt-2 flex items-center gap-2"><input type="file" name="file" accept=".xlsx,.xls" required><button class="<?= ATT_BTN ?>">Import</button></div>
  </form>

  <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5">
      <h2 class="text-[13px] font-semibold text-zinc-900">Employees</h2>
      <span class="text-[11px] tabular-nums text-zinc-500"><?= $total ?> found</span>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-left text-[13px]">
      <thead class="text-[11px] uppercase tracking-wider text-zinc-400"><tr>
        <th class="px-4 py-2 font-medium">Name</th><th class="px-3 py-2 font-medium">ID</th><th class="px-3 py-2 font-medium">Phone</th>
        <th class="px-3 py-2 font-medium">Department</th><th class="px-3 py-2 font-medium">Company / Location</th>
        <th class="px-3 py-2 font-medium">Joined</th><th class="px-3 py-2 font-medium">Face</th><th class="px-4 py-2"></th></tr></thead>
      <tbody class="divide-y divide-zinc-100">
      <?php foreach ($list as $u): ?>
        <tr class="<?= $u['status'] === 'Resign' ? 'text-zinc-400' : '' ?>">
          <td class="px-4 py-2"><p class="font-medium <?= $u['status'] === 'Resign' ? '' : 'text-zinc-900' ?>"><?= e($u['name']) ?></p>
            <p class="text-[11px] text-zinc-400"><?= e($u['designation'] ?: (ROLE_LABELS[$u['role']] ?? '')) ?>
              <?= $u['status'] === 'Resign' ? ' · Resigned ' . e($u['date_of_exit'] ?? '') : '' ?>
              <?= !$u['password_set'] ? ' · <span class="text-amber-600">no password</span>' : '' ?>
              <?= $u['geo_restricted'] ? ' · GPS locked' : '' ?></p></td>
          <td class="px-3 py-2 tabular-nums"><?= e($u['employee_id'] ?: '—') ?></td>
          <td class="px-3 py-2 tabular-nums"><?= e($u['phone']) ?></td>
          <td class="px-3 py-2"><?= e($u['dept_name'] ?: '—') ?></td>
          <td class="px-3 py-2"><?= e($u['company'] ?: '—') ?><p class="text-[11px] text-zinc-400"><?= e($u['location'] ?? '') ?></p></td>
          <td class="px-3 py-2 whitespace-nowrap tabular-nums"><?= $u['date_of_joining'] ? date('j M Y', strtotime($u['date_of_joining'])) : '—' ?></td>
          <td class="px-3 py-2"><?= $photoOf((int) $u['id']) ? '<span class="text-emerald-600">Yes</span>' : '<span class="text-zinc-400">—</span>' ?></td>
          <td class="px-4 py-2 text-right">
            <?php if (is_super() || $u['role'] === 'employee'): ?><a href="<?= url('hrms-employees.php?' . $qs(['edit' => $u['id'], 'page' => $page])) ?>" class="text-[12px] font-medium text-brand-600 hover:underline">Edit</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$list): ?><tr><td colspan="8" class="px-4 py-8 text-center text-zinc-400">No employees match.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
    <?php if ($pages > 1): ?>
      <div class="flex items-center justify-between border-t border-zinc-200 px-4 py-2 text-[12px]">
        <span class="text-zinc-500">Page <?= $page ?> of <?= $pages ?></span>
        <div class="flex gap-1">
          <?php if ($page > 1): ?><a class="<?= ATT_BTN2 ?> !py-1" href="?<?= $qs(['page' => $page - 1]) ?>">Previous</a><?php endif; ?>
          <?php if ($page < $pages): ?><a class="<?= ATT_BTN2 ?> !py-1" href="?<?= $qs(['page' => $page + 1]) ?>">Next</a><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
