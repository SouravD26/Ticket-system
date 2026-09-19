<?php
/**
 * The HRMS master lists: companies, locations, shifts, and the office geofence.
 * Departments are the ticket system's own list (departments.php).
 * Replaces the old companies.php, locations.php, shifts.php and geo_restriction.php.
 */
require_once __DIR__ . '/includes/attendance.php';
require_login();
$can = ['companies' => att_can('manage_companies'), 'locations' => att_can('manage_locations'),
        'shifts' => att_can('manage_shifts'), 'office' => att_can('gps_restriction'),
        'leave' => att_can('leave_approval')];
$year = (int) (get_('year') ?: date('Y'));
if (!array_filter($can)) { http_response_code(403); die('403'); }

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
            case 'add_holiday': if ($can['leave'] && strtotime(post('holiday_date')) && post('title') !== '')
                                     q('INSERT INTO project_holidays (holiday_date, title, project) VALUES (?,?,?)', [post('holiday_date'), post('title'), post('project') ?: null]); break;
            case 'del_holiday': if ($can['leave']) q('DELETE FROM project_holidays WHERE id = ?', [(int) post('id')]); break;
            case 'office':
                if (!$can['office']) break;
                $v = [post('office_name') ?: 'Head Office', $num('latitude'), $num('longitude'), max(50, (int) post('radius_meters'))];
                if (q('SELECT id FROM office_settings LIMIT 1')->fetch()) {
                    q('UPDATE office_settings SET office_name = ?, latitude = ?, longitude = ?, radius_meters = ? ORDER BY id LIMIT 1', $v);
                } else {
                    q('INSERT INTO office_settings (office_name, latitude, longitude, radius_meters) VALUES (?,?,?,?)', $v);
                }
                break;
        }
        flash('Saved.');
    } catch (PDOException $e) {
        flash($e->getCode() === '23000' ? 'That name already exists.' : 'Could not save.', 'error');
    }
    redirect('hrms-settings.php?year=' . (int) (post('year') ?: $year));
}

$companies = q('SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.company = c.name) n FROM companies c ORDER BY name')->fetchAll();
$locations = q('SELECT l.*, (SELECT COUNT(*) FROM users u WHERE u.location = l.name) n FROM locations l ORDER BY name')->fetchAll();
$shifts    = q('SELECT * FROM shifts ORDER BY start_time')->fetchAll();
$office    = q('SELECT * FROM office_settings ORDER BY id LIMIT 1')->fetch() ?: [];
$holidays  = q('SELECT * FROM project_holidays WHERE YEAR(holiday_date) = ? ORDER BY holiday_date', [$year])->fetchAll();
$restricted = (int) q('SELECT COUNT(*) FROM users WHERE geo_restricted = 1')->fetchColumn();

$pageTitle = 'HRMS Settings';
require __DIR__ . '/layout/header.php';

$del = fn(string $action, int $id, string $what) => '<form method="post" onsubmit="return confirm(\'Delete ' . e($what) . '?\')">' . csrf_field()
    . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '"><button class="text-[12px] text-rose-600 hover:underline">Delete</button></form>';
$card = 'overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm';
$head = 'border-b border-zinc-200 bg-zinc-50/70 px-4 py-2.5 text-[13px] font-semibold text-zinc-900';
?>
<div class="mx-auto grid max-w-6xl gap-4 lg:grid-cols-2">

<?php if ($can['office']): ?>
  <section class="<?= $card ?> lg:col-span-2">
    <h2 class="<?= $head ?>">Office geofence</h2>
    <form method="post" class="grid gap-3 p-4 sm:grid-cols-5 sm:items-end">
      <?= csrf_field() ?><input type="hidden" name="action" value="office">
      <input name="office_name" value="<?= e($office['office_name'] ?? 'Head Office') ?>" placeholder="Office name" class="<?= ATT_FIELD ?>">
      <input name="latitude" id="lat" value="<?= e($office['latitude'] ?? '') ?>" placeholder="Latitude" class="<?= ATT_FIELD ?>">
      <input name="longitude" id="lng" value="<?= e($office['longitude'] ?? '') ?>" placeholder="Longitude" class="<?= ATT_FIELD ?>">
      <input name="radius_meters" type="number" min="50" value="<?= (int) ($office['radius_meters'] ?? 100) ?>" placeholder="Radius (m)" class="<?= ATT_FIELD ?>">
      <div class="flex gap-2"><button type="button" onclick="here()" class="<?= ATT_BTN2 ?>">Use my location</button><button class="<?= ATT_BTN ?>">Save</button></div>
    </form>
    <p class="px-4 pb-4 text-[12px] text-zinc-500"><?= $restricted ?> employee(s) can only punch within this radius. Turn it on per person under Employees → Edit.</p>
    <script>function here(){navigator.geolocation.getCurrentPosition(p=>{lat.value=p.coords.latitude.toFixed(7);lng.value=p.coords.longitude.toFixed(7);},e=>alert(e.message),{enableHighAccuracy:true});}</script>
  </section>
<?php endif; ?>

<?php if ($can['companies']): ?>
  <section class="<?= $card ?>">
    <h2 class="<?= $head ?>">Companies</h2>
    <form method="post" class="flex gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_company">
      <input name="name" required placeholder="New company" class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($companies as $c): ?><li class="flex items-center gap-2 px-4 py-2"><span class="flex-1"><?= e($c['name']) ?></span>
        <span class="text-[11px] text-zinc-400"><?= $c['n'] ?> staff</span><?= $del('del_company', (int) $c['id'], $c['name']) ?></li><?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($can['shifts']): ?>
  <section class="<?= $card ?>">
    <h2 class="<?= $head ?>">Shifts</h2>
    <form method="post" class="flex items-center gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_shift">
      <input type="time" name="start_time" required class="<?= ATT_FIELD ?>"><span class="text-zinc-400">to</span>
      <input type="time" name="end_time" required class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($shifts as $s): $label = date('h:i A', strtotime($s['start_time'])) . ' - ' . date('h:i A', strtotime($s['end_time'])); ?>
        <li class="flex items-center gap-2 px-4 py-2"><span class="flex-1 tabular-nums"><?= $label ?></span><?= $del('del_shift', (int) $s['id'], $label) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if ($can['locations']): ?>
  <section class="<?= $card ?> lg:col-span-2">
    <h2 class="<?= $head ?>">Locations</h2>
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
  </section>
<?php endif; ?>

<?php if ($can['leave']): ?>
  <section class="<?= $card ?>">
    <div class="flex items-center justify-between border-b border-zinc-200 bg-zinc-50/70 px-4 py-2">
      <h2 class="text-[13px] font-semibold text-zinc-900">Holidays <?= $year ?></h2>
      <form><select name="year" onchange="this.form.submit()" class="rounded border border-zinc-200 px-1 py-0.5 text-[12px]">
        <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></form>
    </div>
    <form method="post" class="grid grid-cols-[8.5rem_1fr_auto] gap-2 border-b border-zinc-100 p-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_holiday"><input type="hidden" name="year" value="<?= $year ?>">
      <input type="date" name="holiday_date" required class="<?= ATT_FIELD ?>"><input name="title" required placeholder="Holiday name" class="<?= ATT_FIELD ?>"><button class="<?= ATT_BTN ?>">Add</button></form>
    <ul class="max-h-80 divide-y divide-zinc-100 overflow-y-auto">
      <?php foreach ($holidays as $h): ?><li class="flex items-center gap-2 px-4 py-2"><span class="w-24 tabular-nums text-zinc-500"><?= date('D j M', strtotime($h['holiday_date'])) ?></span>
        <span class="flex-1"><?= e($h['title']) ?></span><?= $del('del_holiday', (int) $h['id'], $h['title']) ?></li><?php endforeach; ?>
      <?php if (!$holidays): ?><li class="px-4 py-4 text-center text-zinc-400">No holidays for <?= $year ?>.</li><?php endif; ?>
    </ul>
  </section>
<?php endif; ?>

<?php if (is_super()): ?>
  <p class="text-[12px] text-zinc-500 lg:col-span-2">Departments are shared with tickets — manage them under <a class="text-brand-600 hover:underline" href="<?= url('departments.php') ?>">Departments</a>.</p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/layout/footer.php'; ?>
