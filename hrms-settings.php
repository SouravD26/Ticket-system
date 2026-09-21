<?php
/**
 * The HRMS master lists: departments, companies, locations, shifts and per-employee GPS restriction.
 * Departments are the ticket system's own list (departments.php).
 * Replaces the old companies.php, locations.php, shifts.php and geo_restriction.php.
 */
require_once __DIR__ . '/includes/attendance.php';
require_login();
$can = ['companies' => att_can('manage_companies'), 'locations' => att_can('manage_locations'),
        'shifts' => att_can('manage_shifts'), 'office' => att_can('gps_restriction'),
        'leave' => att_can('leave_approval'), 'depts' => is_super()];
if (!array_filter($can)) { http_response_code(403); die('403'); }

/**
 * The GPS-restriction panel's filters, read from GET (the list) or POST (the bulk buttons),
 * so "restrict all" always means exactly the rows on screen.
 * @return array{0:string,1:array}
 */
function gps_filter(callable $src): array
{
    $where = ["u.role NOT IN ('superadmin','admin','hod','face_operator')", 'u.phone IS NOT NULL', "u.status = 'Working'"];
    $args  = [];
    if (($s = trim((string) $src('gq'))) !== '') {
        $where[] = '(u.name LIKE ? OR u.employee_id LIKE ?)';
        array_push($args, "%$s%", "%$s%");
    }
    if (($s = trim((string) $src('gloc'))) !== '')  { $where[] = 'u.location = ?';      $args[] = $s; }
    if ((int) $src('gdept'))                        { $where[] = 'u.department_id = ?'; $args[] = (int) $src('gdept'); }
    return [implode(' AND ', $where), $args];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    $num = fn($k) => is_numeric(post($k)) ? (float) post($k) : null;
    try {
        switch ($a) {
            case 'add_company':  if ($can['companies'] && post('name') !== '') q('INSERT INTO companies (name) VALUES (?)', [post('name')]); break;
            case 'del_company':  if ($can['companies']) q('DELETE FROM companies WHERE id = ?', [(int) post('id')]); break;
            case 'add_location':
            case 'update_location':
                if (!$can['locations'] || post('name') === '') break;
                // "22.5448, 88.3984" pasted into the latitude box fills both.
                if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', post('latitude'), $m)) { $lat = (float) $m[1]; $lng = (float) $m[2]; }
                else { $lat = $num('latitude'); $lng = $num('longitude'); }
                if (($lat === null) !== ($lng === null) || ($lat !== null && (abs($lat) > 90 || abs($lng) > 180))) {
                    flash('Enter both latitude (-90 to 90) and longitude (-180 to 180), or leave both empty.', 'error');
                    redirect('hrms-settings.php');
                }
                $radius = post('radius_meters') === '' ? null : max(50, min(100000, (int) post('radius_meters')));
                if ($a === 'add_location') {
                    q('INSERT INTO locations (name, latitude, longitude, radius_meters) VALUES (?,?,?,?)', [post('name'), $lat, $lng, $radius]);
                    break;
                }
                $old = q('SELECT name FROM locations WHERE id = ?', [(int) post('id')])->fetchColumn();
                if ($old === false) break;
                q('UPDATE locations SET name = ?, latitude = ?, longitude = ?, radius_meters = ? WHERE id = ?',
                  [post('name'), $lat, $lng, $radius, (int) post('id')]);
                // Staff hold the location by name, so a rename carries them across.
                if ($old !== post('name')) q('UPDATE users SET location = ? WHERE location = ?', [post('name'), $old]);
                break;
            case 'del_location': if ($can['locations']) q('DELETE FROM locations WHERE id = ?', [(int) post('id')]); break;
            case 'add_shift':    if ($can['shifts'] && post('start_time') && post('end_time'))
                                     q('INSERT INTO shifts (start_time, end_time) VALUES (?,?)', [post('start_time'), post('end_time')]); break;
            case 'del_shift':    if ($can['shifts']) q('DELETE FROM shifts WHERE id = ?', [(int) post('id')]); break;
            // Departments are the ticket system's list, shared with HRMS; see departments.php.
            case 'add_department': if ($can['depts'] && post('name') !== '') q('INSERT INTO departments (name) VALUES (?)', [post('name')]); break;
            case 'del_department': if ($can['depts']) q('DELETE FROM departments WHERE id = ?', [(int) post('id')]); break;
            // Per-employee GPS restriction: the old geo_restriction.php list, one row per person.
            case 'geo_toggle':
                if (!$can['office']) break;
                q('UPDATE users SET geo_restricted = ? WHERE id = ?', [post('on') ? 1 : 0, (int) post('id')]);
                break;
            case 'geo_all':
                if (!$can['office']) break;
                // Applies to the filtered set only, so it matches what the user is looking at.
                [$w, $wArgs] = gps_filter(fn($k) => post($k));
                q("UPDATE users u SET u.geo_restricted = ? WHERE $w", array_merge([post('on') ? 1 : 0], $wArgs));
                break;
        }
        flash('Saved.');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'That name already exists.' : 'Could not save.', 'error');
    }
    $keep = array_filter(['gq' => post('gq'), 'gloc' => post('gloc'), 'gdept' => post('gdept')],
                         fn($v) => $v !== '' && $v !== 0);
    redirect('hrms-settings.php?' . http_build_query($keep) . '#gps');
}

$companies = q('SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company = c.name) n FROM companies c ORDER BY name')->fetchAll();
$locations = q('SELECT l.*, (SELECT COUNT(*) FROM users u WHERE u.location = l.name) n FROM locations l ORDER BY name')->fetchAll();
$shifts    = q('SELECT * FROM shifts ORDER BY start_time')->fetchAll();

$restricted = (int) q('SELECT COUNT(*) FROM users WHERE geo_restricted = 1')->fetchColumn();

// Staff list for the per-employee GPS restriction panel (kiosk and admin logins excluded).
$gf = ['gq' => trim((string) get_('gq')), 'gloc' => get_('gloc'), 'gdept' => (int) get_('gdept')];
[$gpsWhere, $gpsArgs] = gps_filter(fn($k) => $gf[$k] ?? '');
$gpsStaff = $can['office']
    ? q("SELECT u.id, u.name, u.employee_id, u.location, u.geo_restricted, d.name AS dept_name
         FROM users u LEFT JOIN departments d ON d.id = u.department_id
         WHERE $gpsWhere ORDER BY u.name", $gpsArgs)->fetchAll()
    : [];
$gpsDepts = ($can['office'] || $can['depts'])
    ? q('SELECT d.id, d.name, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) n FROM departments d ORDER BY d.name')->fetchAll()
    : [];

$pageTitle = 'HRMS Settings';
require __DIR__ . '/layout/header.php';

$del = fn(string $action, int $id, string $what) => '<form method="post" onsubmit="return confirm(\'Delete ' . e($what) . '?\')">' . csrf_field()
    . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '"><button class="text-[12px] text-rose-600 hover:underline">Delete</button></form>';
$card = 'group overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm';
$head = 'flex cursor-pointer list-none items-center gap-2 border-zinc-200 bg-zinc-50/70 px-4 py-2.5 text-[13px] font-semibold text-zinc-900 marker:content-none [&::-webkit-details-marker]:hidden hover:bg-zinc-100 group-open:border-b';
/* Each master list is a closed accordion; the arrow in the bar opens it. */
$arrow = '<svg class="h-4 w-4 shrink-0 text-zinc-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6l6 6-6 6"/></svg>';
?>
<div class="mx-auto max-w-6xl space-y-3">

<?php if ($can['office']): ?>
  <details id="gps" class="<?= $card ?>">
    <summary class="<?= $head ?>"><?= $arrow ?><span>GPS restriction per employee</span><span class="ml-auto text-[11px] font-normal text-zinc-400"><?= $restricted ?> of <?= count($gpsStaff) ?> restricted</span></summary>
    <div class="flex flex-wrap items-center gap-3 border-b border-zinc-100 px-4 py-3">
      <form method="get" class="flex flex-wrap items-center gap-2">
        <input name="gq" value="<?= e($gf['gq']) ?>" placeholder="Name or employee ID" class="<?= ATT_FIELD ?> sm:w-56">
        <select name="gloc" class="<?= ATT_FIELD ?> sm:w-44">
          <option value="">All locations</option>
          <?php foreach ($locations as $l): ?>
            <option value="<?= e($l['name']) ?>" <?= $gf['gloc'] === $l['name'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="gdept" class="<?= ATT_FIELD ?> sm:w-44">
          <option value="">All departments</option>
          <?php foreach ($gpsDepts as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= $gf['gdept'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="<?= ATT_BTN2 ?>">Filter</button>
        <?php if ($gf['gq'] !== '' || $gf['gloc'] !== '' || $gf['gdept']): ?>
          <a href="hrms-settings.php#gps" class="<?= ATT_BTN2 ?>">Clear</a>
        <?php endif; ?>
      </form>
      <span class="ml-auto flex items-center gap-2">
        <span class="text-[12px] text-zinc-500"><?= count($gpsStaff) ?> shown</span>
        <?php foreach ([1 => 'Restrict all', 0 => 'Unrestrict all'] as $on => $lbl): ?>
          <form method="post" onsubmit="return confirm('<?= $lbl ?> — <?= count($gpsStaff) ?> employee(s) currently listed?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="geo_all">
            <input type="hidden" name="on" value="<?= $on ?>">
            <input type="hidden" name="gq" value="<?= e($gf['gq']) ?>">
            <input type="hidden" name="gloc" value="<?= e($gf['gloc']) ?>">
            <input type="hidden" name="gdept" value="<?= $gf['gdept'] ?: '' ?>">
            <button class="<?= ATT_BTN2 ?>"><?= $lbl ?></button>
          </form>
        <?php endforeach; ?>
      </span>
    </div>
    <div class="max-h-[420px] overflow-auto">
      <table class="w-full text-left text-[13px]">
        <thead class="sticky top-0 bg-white text-[11px] uppercase tracking-wider text-zinc-400"><tr>
          <th class="px-4 py-2 font-medium">Employee</th><th class="px-2 py-2 font-medium">Emp ID</th>
          <th class="px-2 py-2 font-medium">Department</th><th class="px-2 py-2 font-medium">Location</th>
          <th class="px-4 py-2 text-right font-medium">GPS restriction</th>
        </tr></thead>
        <tbody class="divide-y divide-zinc-100">
        <?php foreach ($gpsStaff as $s): $on = (int) $s['geo_restricted'] === 1; ?>
          <tr>
            <td class="px-4 py-2 text-zinc-800"><?= e($s['name']) ?></td>
            <td class="px-2 py-2 text-zinc-500"><?= e($s['employee_id'] ?: '—') ?></td>
            <td class="px-2 py-2 text-zinc-500"><?= e($s['dept_name'] ?: '—') ?></td>
            <td class="px-2 py-2 text-zinc-500"><?= e($s['location'] ?: '—') ?></td>
            <td class="px-4 py-2 text-right">
              <form method="post" class="inline-flex items-center gap-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="geo_toggle">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <input type="hidden" name="on" value="<?= $on ? 0 : 1 ?>">
                <input type="hidden" name="gq" value="<?= e($gq) ?>">
                <span class="text-[12px] <?= $on ? 'text-emerald-600' : 'text-zinc-400' ?>"><?= $on ? 'On' : 'Off' ?></span>
                <button title="<?= $on ? 'Turn off' : 'Turn on' ?>"
                        class="relative h-5 w-9 rounded-full transition <?= $on ? 'bg-emerald-500' : 'bg-zinc-300' ?>">
                  <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all <?= $on ? 'left-[18px]' : 'left-0.5' ?>"></span>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$gpsStaff): ?>
          <tr><td colspan="5" class="px-4 py-6 text-center text-zinc-400">No employees found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="border-t border-zinc-100 px-4 py-2 text-[11px] text-zinc-500">Employees switched <b>Off</b> can punch from anywhere. <b>On</b> means they may punch only within their location's radius, set under Locations.</p>
  </details>
<?php endif; ?>

<?php if ($can['depts']): ?>
  <details class="<?= $card ?>">
    <summary class="<?= $head ?>"><?= $arrow ?><span>Departments</span><span class="ml-auto text-[11px] font-normal text-zinc-400"><?= count($gpsDepts) ?></span></summary>
    <form method="post" class="flex gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_department">
      <input name="name" required placeholder="New department" class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($gpsDepts as $d): ?><li class="flex items-center gap-2 px-4 py-2"><span class="flex-1"><?= e($d['name']) ?></span>
        <span class="text-[11px] text-zinc-400"><?= $d['n'] ?> staff</span><?= $del('del_department', (int) $d['id'], $d['name']) ?></li><?php endforeach; ?>
    </ul>
    <p class="border-t border-zinc-100 px-4 py-2 text-[11px] text-zinc-500">Shared with tickets. To rename one, use <a href="departments.php" class="text-brand-600 hover:underline">Departments</a>.</p>
  </details>
<?php endif; ?>

<?php if ($can['companies']): ?>
  <details class="<?= $card ?>">
    <summary class="<?= $head ?>"><?= $arrow ?><span>Companies</span><span class="ml-auto text-[11px] font-normal text-zinc-400"><?= count($companies) ?></span></summary>
    <form method="post" class="flex gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_company">
      <input name="name" required placeholder="New company" class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($companies as $c): ?><li class="flex items-center gap-2 px-4 py-2"><span class="flex-1"><?= e($c['name']) ?></span>
        <span class="text-[11px] text-zinc-400"><?= $c['n'] ?> staff</span><?= $del('del_company', (int) $c['id'], $c['name']) ?></li><?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>

<?php if ($can['shifts']): ?>
  <details class="<?= $card ?>">
    <summary class="<?= $head ?>"><?= $arrow ?><span>Shifts</span><span class="ml-auto text-[11px] font-normal text-zinc-400"><?= count($shifts) ?></span></summary>
    <form method="post" class="flex items-center gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_shift">
      <input type="time" name="start_time" required class="<?= ATT_FIELD ?>"><span class="text-zinc-400">to</span>
      <input type="time" name="end_time" required class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($shifts as $s): $label = date('h:i A', strtotime($s['start_time'])) . ' - ' . date('h:i A', strtotime($s['end_time'])); ?>
        <li class="flex items-center gap-2 px-4 py-2"><span class="flex-1 tabular-nums"><?= $label ?></span><?= $del('del_shift', (int) $s['id'], $label) ?></li>
      <?php endforeach; ?>
    </ul>
  </details>
<?php endif; ?>

<?php if ($can['locations']): ?>
  <details class="<?= $card ?>">
    <summary class="<?= $head ?>"><?= $arrow ?><span>Locations</span><span class="ml-auto text-[11px] font-normal text-zinc-400"><?= count($locations) ?></span></summary>
    <?php $pin = '<button type="button" title="Use my current location" onclick="fillHere(this.form)" class="rounded-md border border-zinc-200 px-2 text-zinc-500 hover:bg-zinc-50">'
         . '<svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11a7 7 0 1114 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/></svg></button>'; ?>
    <form method="post" class="grid gap-2 border-b border-zinc-100 p-3 sm:grid-cols-[1fr_8rem_8rem_7rem_auto_auto]"><?= csrf_field() ?><input type="hidden" name="action" value="add_location">
      <input name="name" required placeholder="New location" class="<?= ATT_FIELD ?>">
      <input name="latitude" inputmode="decimal" placeholder="Latitude" class="<?= ATT_FIELD ?>">
      <input name="longitude" inputmode="decimal" placeholder="Longitude" class="<?= ATT_FIELD ?>">
      <input name="radius_meters" type="number" min="50" max="100000" step="10" placeholder="Distance m" class="<?= ATT_FIELD ?>">
      <?= $pin ?><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-[32rem] divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($locations as $l): $hasGps = $l['latitude'] !== null; ?>
        <li class="px-4 py-2.5" id="loc<?= $l['id'] ?>">
          <!-- Read-only row -->
          <div class="loc-view flex flex-wrap items-center gap-3">
            <span class="min-w-[9rem] flex-1 font-medium text-zinc-900"><?= e($l['name']) ?></span>
            <?php if ($hasGps): ?>
              <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= (float) $l['latitude'] ?>,<?= (float) $l['longitude'] ?>"
                 class="text-[12px] tabular-nums text-zinc-500 hover:text-brand-600 hover:underline"><?= (float) $l['latitude'] ?>, <?= (float) $l['longitude'] ?></a>
            <?php else: ?><span class="text-[12px] text-amber-600">No latitude / longitude</span><?php endif; ?>
            <span class="w-28 text-[12px] <?= $l['radius_meters'] ? 'text-zinc-500' : 'text-zinc-300' ?>"><?= $l['radius_meters'] ? 'Distance ' . (int) $l['radius_meters'] . ' m' : 'No distance' ?></span>
            <span class="w-14 text-right text-[11px] text-zinc-400"><?= $l['n'] ?> staff</span>
            <button type="button" onclick="editLoc(<?= $l['id'] ?>, true)" class="rounded-md border border-zinc-200 px-3 py-1 text-[12px] font-medium text-zinc-700 hover:bg-zinc-50">Edit</button>
            <?= $del('del_location', (int) $l['id'], $l['name']) ?>
          </div>
          <!-- Edit form, opened by the Edit button -->
          <form method="post" class="loc-edit hidden grid gap-2 sm:grid-cols-[1fr_8rem_8rem_7rem_auto_auto_auto] sm:items-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="update_location"><input type="hidden" name="id" value="<?= $l['id'] ?>">
            <label class="text-[11px] text-zinc-500">Name<input name="name" required value="<?= e($l['name']) ?>" class="<?= ATT_FIELD ?> !py-1.5"></label>
            <label class="text-[11px] text-zinc-500">Latitude<input name="latitude" inputmode="decimal" value="<?= $hasGps ? (float) $l['latitude'] : '' ?>" class="<?= ATT_FIELD ?> !py-1.5 tabular-nums"></label>
            <label class="text-[11px] text-zinc-500">Longitude<input name="longitude" inputmode="decimal" value="<?= $hasGps ? (float) $l['longitude'] : '' ?>" class="<?= ATT_FIELD ?> !py-1.5 tabular-nums"></label>
            <label class="text-[11px] text-zinc-500">Distance (m)<input name="radius_meters" type="number" min="50" max="100000" step="10" placeholder="e.g. 200" value="<?= $l['radius_meters'] ? (int) $l['radius_meters'] : '' ?>" class="<?= ATT_FIELD ?> !py-1.5 tabular-nums"></label>
            <?= $pin ?>
            <button class="<?= ATT_BTN ?> !py-1.5">Save</button>
            <button type="button" onclick="editLoc(<?= $l['id'] ?>, false)" class="<?= ATT_BTN2 ?> !py-1.5">Cancel</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="border-t border-zinc-100 px-4 py-2 text-[11px] text-zinc-500"><b>Distance</b> is how far from this location (in metres) its GPS-restricted staff may punch.
      Renaming a location also moves its staff to the new name. Tip: in Google Maps, right-click a spot and click the numbers to copy its latitude, longitude, then paste both into Latitude.</p>
    <script>
      function editLoc(id, on) {
        const li = document.getElementById('loc' + id);
        li.querySelector('.loc-view').classList.toggle('hidden', on);
        li.querySelector('.loc-edit').classList.toggle('hidden', !on);
        if (on) li.querySelector('input[name=latitude]').focus();
      }
      function fillHere(f) {
        navigator.geolocation.getCurrentPosition(p => {
          f.latitude.value = p.coords.latitude.toFixed(7); f.longitude.value = p.coords.longitude.toFixed(7);
        }, e => alert('Location not available: ' + e.message), {enableHighAccuracy: true, timeout: 20000, maximumAge: 0});
      }
    </script>
  </details>
<?php endif; ?>

</div>
<script>
  // Every panel starts closed; a link or redirect to #gps opens just that one.
  function openHash() {
    var d = location.hash && document.querySelector(location.hash);
    if (d && d.tagName === 'DETAILS') { d.open = true; d.scrollIntoView(); }
  }
  openHash();
  window.addEventListener('hashchange', openHash);
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
