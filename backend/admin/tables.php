<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Table List: every table by area, its status, active orders and today's next booking.
 * Click a table for details and actions. Refreshes every 20 s and on new orders.
 */
require_permission('tables.view');
$canManage = can('tables.manage');

/** All tables with active orders and next booking, grouped by area. */
function load_floor(): array
{
    $tables = db_all('SELECT * FROM restaurant_tables ORDER BY is_active DESC, location, CAST(SUBSTRING(table_number, 2) AS UNSIGNED), table_number');

    $ph = implode(',', array_fill(0, count(ACTIVE_ORDER_STATUSES), '?'));
    $orders = db_all(
        "SELECT o.id, o.table_id, o.order_number, o.status, o.total, o.created_at, o.is_seen
           FROM orders o
          WHERE o.table_id IS NOT NULL AND o.status IN ($ph) AND " . ORDER_VISIBLE_SQL . '
          ORDER BY o.created_at',
        ACTIVE_ORDER_STATUSES
    );
    $byTable = [];
    foreach ($orders as $o) {
        $byTable[(int) $o['table_id']][] = $o;
    }

    $bookings = [];
    foreach (db_all(
        "SELECT table_id, name, guests, reservation_time, status FROM reservations
          WHERE reservation_date = CURDATE() AND status IN ('pending','confirmed') AND table_id IS NOT NULL
            AND reservation_time >= SUBTIME(CURTIME(), '01:00:00')
          ORDER BY reservation_time"
    ) as $b) {
        $bookings[(int) $b['table_id']] ??= $b;           // next booking only
    }

    $areas = [];
    foreach ($tables as $t) {
        $t['orders']  = $byTable[(int) $t['id']] ?? [];
        $t['booking'] = $bookings[(int) $t['id']] ?? null;
        $areas[$t['location'] ?: 'Other'][] = $t;
    }
    return $areas;
}

function state_pill(string $status): string
{
    $icon = ['available' => 'bi-check-circle', 'occupied' => 'bi-people-fill', 'reserved' => 'bi-bookmark-fill'][$status] ?? 'bi-circle';
    return '<span class="state-pill state-' . e($status) . '"><i class="bi ' . $icon . '"></i>' . e(ucfirst($status)) . '</span>';
}

function floor_html(array $areas): string
{
    ob_start();
    if (!$areas): ?>
      <div class="panel empty-state"><i class="bi bi-grid-3x3-gap"></i>No tables yet.</div>
    <?php endif;
    foreach ($areas as $area => $tables):
        $seats = array_sum(array_map(static fn ($t) => (int) $t['is_active'] ? (int) $t['capacity'] : 0, $tables)); ?>
      <section aria-label="<?= e($area) ?>">
        <h2 class="area-title"><?= e($area) ?> <small><?= count($tables) ?> tables · <?= $seats ?> seats</small></h2>
        <div class="table-grid">
          <?php foreach ($tables as $t):
              $cls = (int) $t['is_active'] ? 'is-' . $t['status'] : 'is-inactive'; ?>
            <button type="button" class="dining-table <?= e($cls) ?>" data-table="<?= (int) $t['id'] ?>"
                    aria-label="Table <?= e($t['table_number']) ?>, <?= e($t['status']) ?>, <?= count($t['orders']) ?> active orders">
              <span class="t-head">
                <span class="t-no"><?= e($t['table_number']) ?></span>
                <?= (int) $t['is_active'] ? state_pill($t['status']) : '<span class="state-pill bg-light text-muted">Out of use</span>' ?>
              </span>
              <span class="t-cap"><i class="bi bi-person"></i><?= (int) $t['capacity'] ?> seats</span>
              <?php if ($t['orders']): ?>
                <span class="t-orders">
                  <?php foreach (array_slice($t['orders'], 0, 3) as $o): ?>
                    <span class="t-order"><span class="tabular"><?= e(substr($o['order_number'], -4)) ?> · <?= money($o['total']) ?></span><?= status_badge($o['status']) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($t['orders']) > 3): ?><span class="t-meta">+<?= count($t['orders']) - 3 ?> more</span><?php endif; ?>
                </span>
              <?php elseif ((int) $t['is_active'] && $t['status'] === 'occupied'): ?>
                <span class="t-meta">Seated, no order yet</span>
              <?php endif; ?>
              <?php if ($t['booking']): ?>
                <span class="t-meta"><i class="bi bi-calendar-event"></i> <?= e(date('g:i A', strtotime($t['booking']['reservation_time']))) ?> · <?= e($t['booking']['name']) ?> (<?= (int) $t['booking']['guests'] ?>)</span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach;
    return (string) ob_get_clean();
}

$areas = load_floor();

if (!empty($_GET['partial'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo floor_html($areas);
    exit;
}

// Summary
$all = array_merge(...array_values($areas ?: [[]]));
$active = array_filter($all, static fn ($t) => (int) $t['is_active']);
$sum = ['available' => 0, 'occupied' => 0, 'reserved' => 0];
foreach ($active as $t) {
    $sum[$t['status']]++;
}
$openOrders = array_sum(array_map(static fn ($t) => count($t['orders']), $all));
$locations = db_query('SELECT DISTINCT location FROM restaurant_tables WHERE location IS NOT NULL ORDER BY location')->fetchAll(PDO::FETCH_COLUMN);

admin_header('Table List', 'tables.php');
?>
<section class="kpis" aria-label="Floor summary">
  <div class="kpi"><span class="eyebrow">Available</span><span class="kpi-value"><?= $sum['available'] ?></span><span class="kpi-sub"><?= state_pill('available') ?></span></div>
  <div class="kpi"><span class="eyebrow">Occupied</span><span class="kpi-value"><?= $sum['occupied'] ?></span><span class="kpi-sub"><?= state_pill('occupied') ?></span></div>
  <div class="kpi"><span class="eyebrow">Reserved</span><span class="kpi-value"><?= $sum['reserved'] ?></span><span class="kpi-sub"><?= state_pill('reserved') ?></span></div>
  <div class="kpi"><span class="eyebrow">Open table orders</span><span class="kpi-value"><?= $openOrders ?></span><span class="kpi-sub">Across all dine-in tables</span></div>
</section>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0"><i class="bi bi-arrow-repeat"></i> Updates automatically every 20 seconds. Click a table to change its status or move its orders along.</p>
  <?php if ($canManage): ?>
    <button class="btn btn-primary btn-sm" type="button" id="addTable"><i class="bi bi-plus-lg me-1"></i>Add table</button>
  <?php endif; ?>
</div>

<div id="floor" class="d-grid gap-4"><?= floor_html($areas) ?></div>

<!-- Table detail (filled by AJAX) -->
<div class="modal fade" id="tableModal" tabindex="-1" aria-labelledby="tableModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content" id="tableModalContent"></div></div>
</div>

<?php if ($canManage): ?>
<!-- Add / edit table -->
<div class="modal fade" id="tableFormModal" tabindex="-1" aria-labelledby="tableFormTitle" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" id="tableForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-4" id="tableFormTitle">Add table</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body d-grid gap-3">
      <input type="hidden" name="id" id="tf_id">
      <div class="row g-3">
        <div class="col-6"><label class="form-label" for="tf_number">Table number</label>
          <input class="form-control" id="tf_number" name="table_number" maxlength="10" required placeholder="T11">
          <div class="invalid-feedback"></div></div>
        <div class="col-6"><label class="form-label" for="tf_capacity">Seats</label>
          <input class="form-control" type="number" id="tf_capacity" name="capacity" min="1" max="30" required value="4">
          <div class="invalid-feedback"></div></div>
      </div>
      <div><label class="form-label" for="tf_location">Area</label>
        <input class="form-control" id="tf_location" name="location" list="areaList" maxlength="50" placeholder="Courtyard">
        <datalist id="areaList"><?php foreach ($locations as $l): ?><option value="<?= e($l) ?>"><?php endforeach; ?></datalist>
        <div class="form-text">Guests choose from these areas when booking online.</div>
        <div class="invalid-feedback"></div></div>
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tf_active" name="is_active" value="1" checked>
        <label class="form-check-label" for="tf_active">In use (bookable and shown to guests)</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn btn-primary">Save table</button></div>
  </form></div>
</div>
<?php endif; ?>

<?php admin_footer(['js/tables.js']);
