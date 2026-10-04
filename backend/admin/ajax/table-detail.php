<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * GET ajax/table-detail.php?id=  → modal HTML for one table:
 * status buttons, active orders with next-step buttons, today's bookings, manage actions.
 */
require_permission('tables.view');

$t = db_one('SELECT * FROM restaurant_tables WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$t) {
    http_response_code(404);
    exit('<div class="modal-body">Table not found.</div>');
}

$ph = implode(',', array_fill(0, count(ACTIVE_ORDER_STATUSES), '?'));
$orders = db_all(
    "SELECT o.*, (SELECT GROUP_CONCAT(CONCAT(quantity, '× ', item_name) ORDER BY id SEPARATOR ', ') FROM order_items WHERE order_id = o.id) AS items
       FROM orders o WHERE o.table_id = ? AND o.status IN ($ph) AND " . ORDER_VISIBLE_SQL . ' ORDER BY o.created_at',
    [$t['id'], ...ACTIVE_ORDER_STATUSES]
);
$bookings = db_all(
    "SELECT * FROM reservations WHERE table_id = ? AND reservation_date = CURDATE() AND status IN ('pending','confirmed') ORDER BY reservation_time",
    [$t['id']]
);
$canUpdateOrders = can('orders.update');
$nextStep = static function (array $o): ?array {
    foreach (['confirmed' => 'Confirm', 'preparing' => 'Start preparing', 'ready' => 'Mark ready', 'served' => 'Served', 'completed' => 'Complete'] as $s => $label) {
        if (in_array($s, allowed_next_statuses($o), true)) {
            return [$s, $label];
        }
    }
    return null;
};

header('Content-Type: text/html; charset=utf-8');
?>
<div class="modal-header">
  <div>
    <div class="eyebrow"><?= e($t['location'] ?: 'Table') ?> · <?= (int) $t['capacity'] ?> seats</div>
    <h2 class="modal-title fs-3" id="tableModalTitle">Table <?= e($t['table_number']) ?></h2>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body d-grid gap-4" data-table="<?= (int) $t['id'] ?>">
  <?php if (can('tables.update') && (int) $t['is_active']): ?>
  <div>
    <div class="eyebrow mb-2">Table status</div>
    <div class="btn-group" role="group" aria-label="Table status">
      <?php foreach (['available' => ['Available', 'bi-check-circle'], 'occupied' => ['Occupied', 'bi-people-fill'], 'reserved' => ['Reserved', 'bi-bookmark-fill']] as $s => [$label, $icon]): ?>
        <button type="button" class="btn btn-outline-primary js-table-status<?= $t['status'] === $s ? ' active' : '' ?>" data-status="<?= $s ?>"
                aria-pressed="<?= $t['status'] === $s ? 'true' : 'false' ?>"><i class="bi <?= $icon ?> me-1"></i><?= $label ?></button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php elseif (!(int) $t['is_active']): ?>
    <div class="alert alert-secondary mb-0">This table is out of use. It is hidden from guests and can't be booked.</div>
  <?php endif; ?>

  <div>
    <div class="d-flex justify-content-between align-items-baseline">
      <div class="eyebrow mb-2">Active orders (<?= count($orders) ?>)</div>
      <a class="small" href="orders.php?table=<?= (int) $t['id'] ?>&status=all">Order history</a>
    </div>
    <?php if (!$orders): ?>
      <p class="text-muted mb-0">No active orders at this table.</p>
    <?php else: ?>
      <ul class="list-group">
        <?php foreach ($orders as $o): $n = $nextStep($o); ?>
          <li class="list-group-item d-flex gap-3 align-items-center flex-wrap">
            <div class="flex-grow-1" style="min-width:12rem">
              <a class="fw-bold" href="order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_number']) ?></a> <?= status_badge($o['status']) ?>
              <div class="small text-muted"><?= e($o['items']) ?></div>
              <div class="small text-muted"><?= e(time_ago($o['created_at'])) ?> · <span class="tabular"><?= money($o['total']) ?></span> · <?= $o['payment_status'] === 'paid' ? 'Paid' : 'Not paid yet' ?></div>
            </div>
            <?php if ($n && $canUpdateOrders): ?>
              <button type="button" class="btn btn-sm btn-primary js-order-next" data-id="<?= (int) $o['id'] ?>" data-status="<?= $n[0] ?>"><?= e($n[1]) ?></button>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div>
    <div class="eyebrow mb-2">Bookings today</div>
    <?php if (!$bookings): ?>
      <p class="text-muted mb-0">No bookings assigned to this table today.</p>
    <?php else: ?>
      <ul class="list-unstyled mb-0 d-grid gap-1">
        <?php foreach ($bookings as $b): ?>
          <li><b class="tabular"><?= e(date('g:i A', strtotime($b['reservation_time']))) ?></b> · <?= e($b['name']) ?> · <?= (int) $b['guests'] ?> guests · <?= e(ucfirst($b['status'])) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
<?php if (can('tables.manage')): ?>
<div class="modal-footer justify-content-between">
  <button type="button" class="btn btn-outline-danger btn-sm js-table-delete" data-number="<?= e($t['table_number']) ?>"><i class="bi bi-trash me-1"></i>Delete</button>
  <button type="button" class="btn btn-outline-primary btn-sm js-table-edit"
          data-json="<?= e(json_encode(['id' => (int) $t['id'], 'table_number' => $t['table_number'], 'capacity' => (int) $t['capacity'], 'location' => $t['location'], 'is_active' => (int) $t['is_active']])) ?>">
    <i class="bi bi-pencil me-1"></i>Edit table</button>
</div>
<?php endif;
