<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Reservations: by day or upcoming, status tabs, confirm / cancel / seat,
 * assign a table that fits and is free at that time.
 */
require_permission('reservations.view');
$canManage = can('reservations.manage');
const RES_PER_PAGE = 25;

$date   = (string) ($_GET['date'] ?? 'upcoming');
$status = (string) ($_GET['status'] ?? '');
$q      = clean_text($_GET['q'] ?? '', 60);

$where = ['1=1'];
$params = [];
$dayLabel = 'Upcoming';
if ($date === 'today') {
    $where[] = 'r.reservation_date = CURDATE()'; $dayLabel = 'Today';
} elseif ($date === 'tomorrow') {
    $where[] = 'r.reservation_date = CURDATE() + INTERVAL 1 DAY'; $dayLabel = 'Tomorrow';
} elseif ($date === 'past') {
    $where[] = 'r.reservation_date < CURDATE()'; $dayLabel = 'Past';
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'r.reservation_date = ?'; $params[] = $date; $dayLabel = date('D, j M', strtotime($date));
} else {
    $date = 'upcoming';
    $where[] = 'r.reservation_date >= CURDATE()';
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(r.name LIKE ? OR r.phone LIKE ? OR r.reference LIKE ?)';
    array_push($params, $like, $like, $like);
}
$baseWhere = implode(' AND ', $where);
$counts = array_fill_keys(RESERVATION_STATUSES, 0);
foreach (db_all("SELECT r.status, COUNT(*) c FROM reservations r WHERE $baseWhere GROUP BY r.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
if (in_array($status, RESERVATION_STATUSES, true)) {
    $where[] = 'r.status = ?';
    $params[] = $status;
}
$w = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM reservations r WHERE $w", $params);
$page  = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / RES_PER_PAGE))));
$order = $date === 'past' ? 'r.reservation_date DESC, r.reservation_time DESC' : 'r.reservation_date, r.reservation_time';
$rows  = db_all(
    "SELECT r.*, t.table_number, t.capacity AS table_capacity, u.name AS handled_name
       FROM reservations r LEFT JOIN restaurant_tables t ON t.id = r.table_id LEFT JOIN users u ON u.id = r.handled_by
      WHERE $w ORDER BY $order LIMIT " . RES_PER_PAGE . ' OFFSET ' . (($page - 1) * RES_PER_PAGE),
    $params
);
$guests = (int) db_value("SELECT COALESCE(SUM(r.guests), 0) FROM reservations r WHERE $baseWhere AND r.status IN ('pending','confirmed')", array_slice($params, 0, count($params) - (in_array($status, RESERVATION_STATUSES, true) ? 1 : 0)));

$statusLabels = ['pending' => 'Awaiting confirmation', 'confirmed' => 'Confirmed', 'completed' => 'Seated / done', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];

admin_header('Reservations', 'reservations.php');
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <nav class="status-tabs" aria-label="Day">
    <?php foreach (['upcoming' => 'Upcoming', 'today' => 'Today', 'tomorrow' => 'Tomorrow', 'past' => 'Past'] as $k => $l): ?>
      <a href="<?= e(qs(['date' => $k, 'page' => null, 'status' => null])) ?>" class="<?= $date === $k ? 'active' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="d-flex gap-2 align-items-center">
    <form method="get" class="d-flex gap-2" role="search">
      <input class="form-control form-control-sm" type="date" name="date" value="<?= preg_match('/^\d{4}-/', $date) ? e($date) : '' ?>" aria-label="Pick a date" onchange="this.form.submit()">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, phone, ref." aria-label="Search">
    </form>
    <?php if ($canManage): ?><a class="btn btn-primary btn-sm text-nowrap" href="reservation-edit.php"><i class="bi bi-plus-lg me-1"></i>Phone booking</a><?php endif; ?>
  </div>
</div>

<section class="panel">
  <div class="panel-head">
    <h2><?= e($dayLabel) ?></h2>
    <span class="small text-muted"><?= $guests ?> guests expected (pending + confirmed)</span>
  </div>
  <div class="panel-body pb-0">
    <nav class="status-tabs" aria-label="Status">
      <a href="<?= e(qs(['status' => null, 'page' => null])) ?>" class="<?= $status === '' ? 'active' : '' ?>">All<span class="count"><?= array_sum($counts) ?></span></a>
      <?php foreach ($statusLabels as $k => $l): ?>
        <a href="<?= e(qs(['status' => $k, 'page' => null])) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($l) ?><span class="count"><?= $counts[$k] ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-calendar2"></i>No bookings here.</div>
  <?php else: ?>
  <div class="table-responsive mt-3">
    <table class="table align-middle">
      <thead><tr><th>When</th><th>Guest</th><th class="text-end">Guests</th><th>Table</th><th>Status</th><th>Notes</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $isPast = $r['reservation_date'] < date('Y-m-d'); ?>
        <tr data-row data-id="<?= (int) $r['id'] ?>">
          <td class="text-nowrap"><b class="tabular"><?= e(date('g:i A', strtotime($r['reservation_time']))) ?></b>
            <small class="d-block text-muted"><?= e(date('D, j M', strtotime($r['reservation_date']))) ?></small></td>
          <td><b><?= e($r['name']) ?></b><small class="d-block text-muted tabular"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a> · <?= e($r['reference']) ?></small></td>
          <td class="text-end tabular fw-bold"><?= (int) $r['guests'] ?></td>
          <td>
            <?php if ($r['table_number']): ?><b><?= e($r['table_number']) ?></b><small class="text-muted"> · <?= (int) $r['table_capacity'] ?> seats</small>
            <?php else: ?><span class="text-muted small">Not assigned<?= $r['table_preference'] && $r['table_preference'] !== 'Any' ? ' · prefers ' . e($r['table_preference']) : '' ?></span><?php endif; ?>
          </td>
          <td><?= status_badge($r['status'], $statusLabels[$r['status']] ?? $r['status']) ?></td>
          <td class="small" style="max-width:16rem"><?= e($r['special_requests'] ?? '') ?></td>
          <?php if ($canManage): ?>
          <td class="text-end text-nowrap">
            <?php if ($r['status'] === 'pending'): ?>
              <button class="btn btn-sm btn-primary js-res" data-action="confirmed" type="button">Confirm</button>
            <?php elseif ($r['status'] === 'confirmed'): ?>
              <button class="btn btn-sm btn-success js-res" data-action="completed" type="button">Seated</button>
            <?php endif; ?>
            <div class="btn-group">
              <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" type="button" aria-expanded="false">More</button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="reservation-edit.php?id=<?= (int) $r['id'] ?>"><i class="bi bi-pencil me-2"></i>Edit / assign table</a></li>
                <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
                  <?php if ($isPast || $r['status'] === 'confirmed'): ?><li><button class="dropdown-item js-res" data-action="no_show" type="button"><i class="bi bi-person-x me-2"></i>Mark no-show</button></li><?php endif; ?>
                  <li><button class="dropdown-item text-danger js-res" data-action="cancelled" type="button"><i class="bi bi-x-circle me-2"></i>Cancel booking</button></li>
                <?php elseif (!$isPast): ?>
                  <li><button class="dropdown-item js-res" data-action="pending" type="button"><i class="bi bi-arrow-counterclockwise me-2"></i>Reopen</button></li>
                <?php endif; ?>
                <?php if (can('reservations.delete')): ?>
                  <li><hr class="dropdown-divider"></li>
                  <li><button class="dropdown-item text-danger js-res" data-action="delete" type="button"><i class="bi bi-trash me-2"></i>Delete</button></li>
                <?php endif; ?>
              </ul>
            </div>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="panel-foot"><span><?= e(pagination_summary($total, $page, RES_PER_PAGE)) ?></span><?= render_pagination($total, $page, RES_PER_PAGE) ?></div>
</section>
<script>
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.js-res');
  if (!btn) return;
  const id = btn.closest('[data-id]').dataset.id;
  const action = btn.dataset.action;
  if (['cancelled', 'delete', 'no_show'].includes(action)) {
    const text = { cancelled: ['Cancel this booking?', 'Cancel booking'], no_show: ['Mark as no-show?', 'Mark no-show'], delete: ['Delete this booking?', 'Delete'] }[action];
    if (!(await VC.confirm({ title: text[0], body: action === 'delete' ? 'It disappears from all lists. This cannot be undone.' : 'The table becomes free for other guests at that time.', confirmText: text[1], danger: action !== 'no_show' }))) return;
  }
  VC.post('ajax/reservation-action.php', { id, action }).then((res) => { VC.toast(res.message); setTimeout(() => location.reload(), 500); });
});
</script>
<?php admin_footer();
