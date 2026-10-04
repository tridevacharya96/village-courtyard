<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Orders list: status tabs, filters, search, one-click next step.
 * ?partial=1 returns just the list (used for live refresh after actions/new orders).
 */
$user = require_permission('orders.view');
$canUpdate = can('orders.update');

const PER_PAGE = 20;

// ---------------- Filters ----------------
$status  = (string) ($_GET['status'] ?? 'active');
$type    = (string) ($_GET['type'] ?? '');
$payment = (string) ($_GET['payment'] ?? '');
$q       = clean_text($_GET['q'] ?? '', 60);
$date    = (string) ($_GET['date'] ?? '');
$from    = (string) ($_GET['from'] ?? '');
$to      = (string) ($_GET['to'] ?? '');
$seen    = $_GET['seen'] ?? null;
$showUnpaid = is_super_admin() && !empty($_GET['unpaid']);

$where  = [$showUnpaid ? '1=1' : ORDER_VISIBLE_SQL];
$params = [];

[$dFrom, $dTo] = match ($date) {
    'today'     => [date('Y-m-d'), date('Y-m-d')],
    'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7d'        => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')],
    'custom'    => [preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : null, preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : null],
    default     => [null, null],
};
if ($dFrom) { $where[] = 'o.created_at >= ?'; $params[] = "$dFrom 00:00:00"; }
if ($dTo)   { $where[] = 'o.created_at <= ?'; $params[] = "$dTo 23:59:59"; }

if (in_array($type, ['delivery', 'takeaway', 'dine_in'], true)) {
    $where[] = 'o.order_type = ?'; $params[] = $type;
}
if (in_array($payment, ['pending', 'paid', 'failed', 'refunded'], true)) {
    $where[] = 'o.payment_status = ?'; $params[] = $payment;
} elseif (in_array($payment, ['cod', 'razorpay'], true)) {
    $where[] = 'o.payment_method = ?'; $params[] = $payment;
}
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($seen === '0') {
    $where[] = "o.is_seen = 0 AND o.status <> 'cancelled'";
}
if (!empty($_GET['table'])) {
    $where[] = 'o.table_id = ?'; $params[] = (int) $_GET['table'];
}

// Counts per status for the tabs (respecting every other filter)
$baseWhere = implode(' AND ', $where);
$counts = array_fill_keys(array_keys(ORDER_STATUSES), 0);
foreach (db_all("SELECT o.status, COUNT(*) c FROM orders o WHERE $baseWhere GROUP BY o.status", $params) as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
$activeCount = array_sum(array_intersect_key($counts, array_flip(ACTIVE_ORDER_STATUSES)));
$allCount    = array_sum($counts);

if ($status === 'active') {
    $where[] = "o.status IN ('" . implode("','", ACTIVE_ORDER_STATUSES) . "')";
} elseif (isset(ORDER_STATUSES[$status])) {
    $where[] = 'o.status = ?'; $params[] = $status;
} else {
    $status = 'all';
}

$whereSql = implode(' AND ', $where);
$total    = (int) db_value("SELECT COUNT(*) FROM orders o WHERE $whereSql", $params);
$page     = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / PER_PAGE))));
$orders   = db_all(
    "SELECT o.*, t.table_number,
            (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS item_count
       FROM orders o LEFT JOIN restaurant_tables t ON t.id = o.table_id
      WHERE $whereSql
      ORDER BY (o.status = 'pending') DESC, o.created_at DESC
      LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE),
    $params
);

/** The single most likely next step for the quick-action button. */
function primary_next(array $o): ?array
{
    $map = [
        'pending'          => ['confirmed', 'Confirm'],
        'confirmed'        => ['preparing', 'Start preparing'],
        'preparing'        => ['ready', 'Mark ready'],
        'ready'            => match ($o['order_type']) {
            'delivery' => ['out_for_delivery', 'Send out'],
            'dine_in'  => ['served', 'Served'],
            default    => ['completed', 'Picked up'],
        },
        'out_for_delivery' => ['completed', 'Delivered'],
        'served'           => ['completed', 'Complete'],
    ];
    $next = $map[$o['status']] ?? null;
    if (!$next || ($o['payment_method'] === 'razorpay' && $o['payment_status'] === 'pending')) {
        return null;
    }
    return $next;
}

function orders_list_html(array $orders, int $total, int $page, bool $canUpdate): string
{
    ob_start();
    if (!$orders): ?>
      <div class="empty-state"><i class="bi bi-inboxes"></i>No orders match these filters.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead><tr>
            <th>Order</th><th>Customer</th><th>Type</th><th class="text-end">Items</th><th class="text-end">Total</th>
            <th>Payment</th><th>Status</th><th>Placed</th><?php if ($canUpdate): ?><th class="text-end">Next step</th><?php endif; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($orders as $o): $next = primary_next($o); ?>
            <tr class="<?= (int) $o['is_seen'] === 0 && $o['status'] !== 'cancelled' ? 'unseen' : '' ?>" data-id="<?= (int) $o['id'] ?>">
              <td><a class="order-no" href="order.php?id=<?= (int) $o['id'] ?>"><?= e($o['order_number']) ?></a></td>
              <td><?= e($o['customer_name']) ?><small class="d-block text-muted tabular"><?= e($o['customer_phone']) ?></small></td>
              <td><span class="type-chip"><i class="bi <?= order_type_icon($o['order_type']) ?>"></i><?= e(order_type_label($o['order_type'])) ?><?= $o['table_number'] ? ' · <b>' . e($o['table_number']) . '</b>' : '' ?></span></td>
              <td class="text-end tabular"><?= (int) $o['item_count'] ?></td>
              <td class="text-end tabular fw-bold"><?= money($o['total']) ?></td>
              <td class="text-nowrap"><?= status_badge($o['payment_method']) ?> <?= status_badge($o['payment_status'], ucfirst($o['payment_status'])) ?></td>
              <td><?= status_badge($o['status']) ?></td>
              <td class="small text-muted text-nowrap" title="<?= e(format_datetime($o['created_at'])) ?>"><?= e(time_ago($o['created_at'])) ?></td>
              <?php if ($canUpdate): ?>
              <td class="text-end">
                <?php if ($next): ?>
                  <button class="btn btn-sm btn-primary text-nowrap js-next" type="button" data-id="<?= (int) $o['id'] ?>" data-status="<?= e($next[0]) ?>"><?= e($next[1]) ?></button>
                <?php elseif ($o['payment_method'] === 'razorpay' && $o['payment_status'] === 'pending'): ?>
                  <span class="small text-muted">Awaiting payment</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="panel-foot">
      <span><?= e(pagination_summary($total, $page, PER_PAGE)) ?></span>
      <?= render_pagination($total, $page, PER_PAGE) ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

if (!empty($_GET['partial'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo orders_list_html($orders, $total, $page, $canUpdate);
    exit;
}

$tabs = ['active' => ['Active', $activeCount]];
foreach (ORDER_STATUSES as $key => $label) {
    $tabs[$key] = [$label, $counts[$key]];
}
$tabs['all'] = ['All', $allCount];

admin_header('Orders', 'orders.php');
?>
<section class="panel">
  <div class="panel-body d-grid gap-3">
    <nav class="status-tabs" aria-label="Filter by status">
      <?php foreach ($tabs as $key => [$label, $count]): ?>
        <a href="<?= e(qs(['status' => $key, 'page' => null])) ?>" class="<?= $status === $key ? 'active' : '' ?>"<?= $status === $key ? ' aria-current="page"' : '' ?>>
          <?= e($label) ?><span class="count"><?= $count ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <form class="filter-bar" method="get" role="search">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <div>
        <label class="form-label small mb-1" for="q">Search</label>
        <input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Order no., name or phone">
      </div>
      <div>
        <label class="form-label small mb-1" for="date">Date</label>
        <select class="form-select form-select-sm" id="date" name="date">
          <?php foreach (['' => 'Any time', 'today' => 'Today', 'yesterday' => 'Yesterday', '7d' => 'Last 7 days', 'custom' => 'Custom range'] as $k => $l): ?>
            <option value="<?= $k ?>"<?= $date === $k ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="custom-range"<?= $date === 'custom' ? '' : ' hidden' ?>>
        <label class="form-label small mb-1" for="from">From</label>
        <input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>">
      </div>
      <div class="custom-range"<?= $date === 'custom' ? '' : ' hidden' ?>>
        <label class="form-label small mb-1" for="to">To</label>
        <input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>">
      </div>
      <div>
        <label class="form-label small mb-1" for="type">Type</label>
        <select class="form-select form-select-sm" id="type" name="type">
          <?php foreach (['' => 'All types', 'delivery' => 'Delivery', 'takeaway' => 'Takeaway', 'dine_in' => 'Dine-in'] as $k => $l): ?>
            <option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label small mb-1" for="payment">Payment</label>
        <select class="form-select form-select-sm" id="payment" name="payment">
          <?php foreach (['' => 'Any payment', 'paid' => 'Paid', 'pending' => 'Not paid yet', 'refunded' => 'Refunded', 'failed' => 'Failed', 'cod' => 'Cash on delivery', 'razorpay' => 'Online (Razorpay)'] as $k => $l): ?>
            <option value="<?= $k ?>"<?= $payment === $k ? ' selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="actions">
        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        <a class="btn btn-sm btn-light" href="orders.php">Reset</a>
      </div>
    </form>
    <?php if (is_super_admin()): ?>
      <div class="form-check form-switch small mb-0">
        <input class="form-check-input" type="checkbox" role="switch" id="unpaidToggle" <?= $showUnpaid ? 'checked' : '' ?>
               onchange="location.href=<?= e(ejs(qs(['unpaid' => $showUnpaid ? null : 1, 'page' => null]))) ?>">
        <label class="form-check-label text-muted" for="unpaidToggle">Include online checkouts that were never paid (hidden from the kitchen)</label>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="panel" id="ordersList" data-page="<?= $page ?>">
  <?= orders_list_html($orders, $total, $page, $canUpdate) ?>
</section>

<?php admin_footer(['js/orders.js']);
