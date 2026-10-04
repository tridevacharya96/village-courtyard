<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Add a phone/walk-in booking or edit one: change details, time, and assign a table.
 * Staff may book outside online hours (e.g. a late table), but never double-book a table.
 */
$user = require_permission('reservations.manage');
$id   = (int) ($_GET['id'] ?? 0);
$res  = $id ? db_one('SELECT * FROM reservations WHERE id = ?', [$id]) : null;
if ($id && !$res) {
    redirect(admin_url('reservations.php'));
}
$res ??= ['name' => '', 'phone' => '', 'email' => '', 'reservation_date' => date('Y-m-d'), 'reservation_time' => '20:00:00', 'guests' => 2, 'table_preference' => 'Any', 'table_id' => null, 'special_requests' => '', 'status' => 'confirmed'];
$errors = [];
$dining = reservation_config()['dining_minutes'];

// "Update list" reloads with the new date/time/guests in the URL
if (!is_post()) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['reservation_date'] ?? ''))) {
        $res['reservation_date'] = $_GET['reservation_date'];
    }
    if (preg_match('/^\d{2}:\d{2}$/', (string) ($_GET['reservation_time'] ?? ''))) {
        $res['reservation_time'] = $_GET['reservation_time'] . ':00';
    }
    if (isset($_GET['guests'])) {
        $res['guests'] = max(1, min(60, (int) $_GET['guests']));
    }
}

/** Tables big enough and free for the date/time (excluding this booking). */
function free_tables(string $date, string $time, int $guests, int $ignoreId, int $dining): array
{
    return db_all(
        "SELECT t.* FROM restaurant_tables t
          WHERE t.is_active = 1 AND t.capacity >= ?
            AND NOT EXISTS (
              SELECT 1 FROM reservations r
               WHERE r.table_id = t.id AND r.id <> ? AND r.reservation_date = ? AND r.status IN ('pending','confirmed')
                 AND ABS(TIMESTAMPDIFF(MINUTE, CONCAT(r.reservation_date, ' ', r.reservation_time), CONCAT(?, ' ', ?))) < ?)
          ORDER BY t.capacity, t.location, t.id",
        [$guests, $ignoreId, $date, $date, $time, $dining]
    );
}

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'name'             => 'required|string|min:2|max:100',
        'phone'            => 'required|phone',
        'email'            => 'nullable|email|max:150',
        'reservation_date' => 'required|date',
        'reservation_time' => 'required|time',
        'guests'           => 'required|integer|between:1,60',
        'table_preference' => 'nullable|in:Any,Courtyard,Indoor,Terrace',
        'table_id'         => 'nullable|integer',
        'special_requests' => 'nullable|string|max:500',
        'status'           => 'required|in:' . implode(',', RESERVATION_STATUSES),
    ], ['reservation_date' => 'Date', 'reservation_time' => 'Time', 'table_id' => 'Table']);
    $errors = $v->errors();
    $d = $v->validated();
    if (!$id && !isset($errors['reservation_date']) && $d['reservation_date'] < date('Y-m-d')) {
        $errors['reservation_date'] = 'New bookings cannot be in the past.';
    }
    $time = substr((string) ($d['reservation_time'] ?? ''), 0, 5) . ':00';

    if (!$errors && $d['table_id']) {
        $ok = array_filter(free_tables($d['reservation_date'], $time, (int) $d['guests'], $id, $dining), static fn ($t) => (int) $t['id'] === (int) $d['table_id']);
        if (!$ok) {
            $errors['table_id'] = 'That table is too small or already booked at this time. Pick one from the list.';
        }
    }
    if (!$errors) {
        $data = [
            'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'] ?: null,
            'reservation_date' => $d['reservation_date'], 'reservation_time' => $time, 'guests' => $d['guests'],
            'table_preference' => $d['table_preference'] ?: 'Any', 'table_id' => $d['table_id'] ?: null,
            'special_requests' => $d['special_requests'] ?: null, 'status' => $d['status'], 'handled_by' => $user['id'],
        ];
        if ($id) {
            db_update('reservations', $data, 'id = ?', [$id]);
            log_activity('update', 'reservation', $id, "Edited booking {$res['reference']} ({$d['name']})", (int) $user['id']);
            flash('success', 'Booking updated.');
        } else {
            $data['reference'] = generate_reservation_reference();
            $id = db_insert('reservations', $data);
            log_activity('create', 'reservation', $id, "Phone booking {$data['reference']}: {$d['name']}, {$d['guests']} guests", (int) $user['id']);
            flash('success', "Booking {$data['reference']} added for {$d['name']}.");
        }
        redirect(admin_url('reservations.php?date=' . $d['reservation_date']));
    }
}

$cur = [
    'date'   => (string) fv('reservation_date', $res['reservation_date']),
    'time'   => substr((string) fv('reservation_time', $res['reservation_time']), 0, 5),
    'guests' => max(1, (int) fv('guests', $res['guests'])),
];
$tables = preg_match('/^\d{4}-\d{2}-\d{2}$/', $cur['date']) && preg_match('/^\d{2}:\d{2}$/', $cur['time'])
    ? free_tables($cur['date'], $cur['time'] . ':00', $cur['guests'], $id, $dining) : [];
$tableOptions = ['' => 'Not assigned yet'];
foreach ($tables as $t) {
    $tableOptions[$t['id']] = "{$t['table_number']} · {$t['capacity']} seats · {$t['location']}";
}
if ($res['table_id'] && !isset($tableOptions[$res['table_id']])) {
    $t = db_one('SELECT * FROM restaurant_tables WHERE id = ?', [$res['table_id']]);
    $tableOptions[$res['table_id']] = "{$t['table_number']} (no longer free at this time)";
}

admin_header($id ? 'Edit booking ' . $res['reference'] : 'New booking', 'reservations.php');
?>
<a class="small" href="reservations.php"><i class="bi bi-arrow-left"></i> Reservations</a>
<form method="post" novalidate class="form-layout" id="resForm">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-body">
      <div class="row g-3">
        <div class="col-sm-6"><?= field_input('name', 'Guest name', $res['name'], $errors, ['required' => true, 'maxlength' => 100, 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('phone', 'Phone', $res['phone'], $errors, ['required' => true, 'type' => 'tel', 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('email', 'Email', $res['email'], $errors, ['type' => 'email', 'optional' => true, 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('guests', 'Guests', $res['guests'], $errors, ['type' => 'number', 'min' => 1, 'max' => 60, 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('reservation_date', 'Date', $res['reservation_date'], $errors, ['type' => 'date', 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('reservation_time', 'Time', substr((string) $res['reservation_time'], 0, 5), $errors, ['type' => 'time', 'step' => 900, 'wrap' => '']) ?></div>
        <div class="col-12"><?= field_textarea('special_requests', 'Special requests', $res['special_requests'], $errors, ['rows' => 2, 'maxlength' => 500, 'optional' => true, 'wrap' => '']) ?></div>
      </div>
    </div>
    <?= form_actions('reservations.php', $id ? 'Save booking' : 'Add booking') ?>
  </section>
  <aside class="d-grid gap-3">
    <section class="panel"><div class="panel-head"><h2>Table</h2></div><div class="panel-body">
      <?= field_select('table_preference', 'Guest prefers', $res['table_preference'] ?: 'Any', ['Any' => 'No preference', 'Courtyard' => 'Courtyard', 'Indoor' => 'Indoor', 'Terrace' => 'Terrace'], $errors) ?>
      <?= field_select('table_id', 'Assign table', (string) ($res['table_id'] ?? ''), $tableOptions, $errors, ['help' => 'Only tables with enough seats that are free from ' . date('g:i A', strtotime($cur['time'] ?: '20:00')) . ' for ' . round($dining / 60, 1) . ' hours are listed.']) ?>
      <button type="button" class="btn btn-sm btn-outline-primary" id="refreshTables"><i class="bi bi-arrow-repeat me-1"></i>Update list for the new date, time or party size</button>
    </div></section>
    <section class="panel"><div class="panel-body">
      <?= field_select('status', 'Status', $res['status'], ['pending' => 'Awaiting confirmation', 'confirmed' => 'Confirmed', 'completed' => 'Seated / done', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'], $errors, ['wrap' => 'mb-0']) ?>
    </div></section>
  </aside>
</form>
<script>
// Re-render with the new date/time/guests so the table list is accurate (keeps typed values)
document.getElementById('refreshTables').addEventListener('click', () => {
  const f = document.getElementById('resForm');
  const p = new URLSearchParams(location.search);
  ['reservation_date', 'reservation_time', 'guests'].forEach((n) => p.set(n, f.elements[n].value));
  ['name', 'phone', 'email', 'special_requests'].forEach((n) => { try { sessionStorage.setItem('res_' + n, f.elements[n].value); } catch (e) {} });
  location.search = p.toString();
});
['name', 'phone', 'email', 'special_requests'].forEach((n) => {
  try { const v = sessionStorage.getItem('res_' + n); if (v !== null) { document.getElementById('resForm').elements[n].value = v; sessionStorage.removeItem('res_' + n); } } catch (e) {}
});
</script>
<?php admin_footer();
