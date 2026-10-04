<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Order detail: items, customer, payment, status actions, history, printing.
 */
$user  = require_permission('orders.view');
$id    = (int) ($_GET['id'] ?? 0);
$order = db_one('SELECT o.*, t.table_number, c.code AS coupon_code FROM orders o
                   LEFT JOIN restaurant_tables t ON t.id = o.table_id
                   LEFT JOIN coupons c ON c.id = o.coupon_id
                  WHERE o.id = ?', [$id]);
if (!$order) {
    flash('warning', 'That order could not be found.');
    redirect(admin_url('orders.php'));
}

// Opening the order counts as seeing it
if ((int) $order['is_seen'] === 0) {
    db_update('orders', ['is_seen' => 1], 'id = ?', [$id]);
}

$items    = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
$history  = db_all('SELECT h.*, u.name AS user_name FROM order_status_history h LEFT JOIN users u ON u.id = h.changed_by
                     WHERE h.order_id = ? ORDER BY h.id', [$id]);
$payments = can('payments.view') ? db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id', [$id]) : [];
$next     = can('orders.update') ? allowed_next_statuses($order) : [];
$awaitingOnline = $order['payment_method'] === 'razorpay' && $order['payment_status'] === 'pending';

$nextLabels = [
    'confirmed' => ['Confirm order', 'bi-check2', 'primary'],
    'preparing' => ['Start preparing', 'bi-fire', 'primary'],
    'ready' => ['Mark ready', 'bi-bell', 'primary'],
    'out_for_delivery' => ['Out for delivery', 'bi-bicycle', 'primary'],
    'served' => ['Served at table', 'bi-cup-hot', 'primary'],
    'completed' => [$order['order_type'] === 'takeaway' ? 'Picked up' : 'Complete', 'bi-check2-all', 'success'],
    'cancelled' => ['Cancel order', 'bi-x-circle', 'outline-danger'],
];

admin_header('Order ' . $order['order_number'], 'orders.php');
?>
<div class="d-flex flex-wrap gap-2 align-items-center justify-content-between no-print">
  <a href="orders.php" class="small"><i class="bi bi-arrow-left"></i> All orders</a>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-primary" href="order-print.php?id=<?= $id ?>&type=kot" target="_blank"><i class="bi bi-printer me-1"></i>Kitchen ticket</a>
    <a class="btn btn-sm btn-outline-primary" href="order-print.php?id=<?= $id ?>&type=invoice" target="_blank"><i class="bi bi-receipt me-1"></i>Invoice</a>
    <?php if (can('orders.delete') && in_array($order['status'], ['completed', 'cancelled'], true)): ?>
      <button class="btn btn-sm btn-outline-danger" type="button" id="deleteOrder"><i class="bi bi-trash me-1"></i>Delete</button>
    <?php endif; ?>
  </div>
</div>

<div class="detail-grid" id="orderDetail" data-id="<?= $id ?>" data-number="<?= e($order['order_number']) ?>">
  <div class="d-grid gap-3">
    <section class="panel">
      <div class="panel-head">
        <div>
          <div class="eyebrow"><?= e(order_type_label($order['order_type'])) ?><?= $order['table_number'] ? ' · Table ' . e($order['table_number']) : '' ?></div>
          <h2 class="d-flex align-items-center gap-2"><?= e($order['order_number']) ?> <?= status_badge($order['status']) ?></h2>
        </div>
        <span class="text-muted small">Placed <?= e(format_datetime($order['created_at'])) ?></span>
      </div>

      <?php if ($next): ?>
      <div class="panel-body border-bottom">
        <?php if ($awaitingOnline): ?>
          <div class="alert alert-warning mb-3 py-2"><i class="bi bi-hourglass me-1"></i>The customer has not finished paying online. You can only cancel this order for now.</div>
        <?php endif; ?>
        <div class="status-actions">
          <?php foreach ($next as $s): [$label, $icon, $tone] = $nextLabels[$s];
              if ($awaitingOnline && $s !== 'cancelled') continue; ?>
            <button class="btn btn-<?= $tone ?> js-status" type="button" data-status="<?= e($s) ?>"><i class="bi <?= $icon ?> me-1"></i><?= e($label) ?></button>
          <?php endforeach; ?>
        </div>
        <label class="form-label small text-muted mt-3 mb-1" for="statusNote">Note for the history (optional, staff only)</label>
        <input class="form-control form-control-sm" id="statusNote" maxlength="200" placeholder="e.g. Rider Suresh, ETA 20 min">
      </div>
      <?php endif; ?>

      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>Dish</th><th class="text-end">Price</th><th class="text-end">Qty</th><th class="text-end">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['item_name']) ?></td><td class="text-end tabular"><?= money($it['unit_price']) ?></td>
                <td class="text-end tabular fw-bold">× <?= (int) $it['quantity'] ?></td><td class="text-end tabular"><?= money($it['line_total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="panel-body d-flex justify-content-end">
        <table class="totals-table tabular" style="min-width:260px">
          <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= money($order['subtotal']) ?></td></tr>
          <?php if ((float) $order['discount'] > 0): ?>
            <tr><td class="text-muted">Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></td><td class="text-end text-success">−<?= money($order['discount']) ?></td></tr>
          <?php endif; ?>
          <tr><td class="text-muted">GST <?= rtrim(rtrim(number_format((float) $order['tax_percent'], 2), '0'), '.') ?>%</td><td class="text-end"><?= money($order['tax_amount']) ?></td></tr>
          <?php if ((float) $order['delivery_charge'] > 0): ?>
            <tr><td class="text-muted">Delivery</td><td class="text-end"><?= money($order['delivery_charge']) ?></td></tr>
          <?php endif; ?>
          <tr class="grand"><td>Total</td><td class="text-end"><?= money($order['total']) ?></td></tr>
        </table>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>History</h2></div>
      <div class="panel-body">
        <ol class="timeline">
          <?php foreach ($history as $h): ?>
            <li>
              <b><?= e($h['new_status'] === 'refund' ? 'Refund' : order_status_label($h['new_status'])) ?></b>
              <?php if ($h['note']): ?><span class="text-muted"> · <?= e($h['note']) ?></span><?php endif; ?>
              <div class="when"><?= e(format_datetime($h['created_at'])) ?> · <?= e($h['user_name'] ?? 'Customer / system') ?></div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </section>
  </div>

  <div class="d-grid gap-3">
    <section class="panel">
      <div class="panel-head"><h2>Customer</h2></div>
      <div class="panel-body">
        <dl class="dl-grid">
          <dt>Name</dt><dd><?= e($order['customer_name']) ?></dd>
          <dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $order['customer_phone'])) ?>"><?= e($order['customer_phone']) ?></a></dd>
          <?php if ($order['customer_email']): ?><dt>Email</dt><dd><?= e($order['customer_email']) ?></dd><?php endif; ?>
          <?php if ($order['delivery_address']): ?><dt>Address</dt><dd><?= nl2br(e($order['delivery_address'])) ?></dd><?php endif; ?>
          <?php if ($order['table_number']): ?><dt>Table</dt><dd><a href="tables.php"><?= e($order['table_number']) ?></a></dd><?php endif; ?>
        </dl>
        <?php if ($order['notes']): ?>
          <div class="alert alert-warning mt-3 mb-0 py-2"><i class="bi bi-chat-left-text me-1"></i><b>Customer note:</b> <?= e($order['notes']) ?></div>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Payment</h2><?= status_badge($order['payment_status'], ucfirst($order['payment_status'])) ?></div>
      <div class="panel-body">
        <dl class="dl-grid">
          <dt>Method</dt><dd><?= $order['payment_method'] === 'cod' ? 'Cash on delivery / at counter' : 'Online (Razorpay)' ?></dd>
          <dt>Amount</dt><dd class="tabular fw-bold"><?= money($order['total']) ?></dd>
          <?php foreach ($payments as $p): if ($p['method'] !== 'razorpay') continue; ?>
            <dt>Attempt</dt><dd class="small"><?= status_badge($p['status'], ucfirst($p['status'])) ?>
              <span class="text-muted d-block text-break"><?= e($p['razorpay_payment_id'] ?: $p['razorpay_order_id']) ?></span>
              <?php if ($p['refund_id']): ?><span class="text-muted d-block">Refund <?= e($p['refund_id']) ?> · <?= money($p['refund_amount']) ?></span><?php endif; ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php if ($order['payment_method'] === 'cod' && $order['payment_status'] === 'pending' && $order['status'] !== 'cancelled' && can('payments.mark_cod')): ?>
          <button class="btn btn-outline-success w-100 mt-3" type="button" id="markPaid"><i class="bi bi-cash-coin me-1"></i>Cash received</button>
          <p class="small text-muted mt-2 mb-0">Completing the order also records the cash as received.</p>
        <?php endif; ?>
        <?php if ($order['status'] === 'cancelled' && $order['payment_method'] === 'razorpay' && $order['payment_status'] === 'paid'): ?>
          <div class="alert alert-danger mt-3 mb-0 py-2"><i class="bi bi-arrow-counterclockwise me-1"></i>This order was cancelled after online payment. Refund it from Payments.</div>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
<?php admin_footer(['js/order.js']);
