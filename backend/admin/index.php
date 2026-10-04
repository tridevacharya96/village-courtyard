<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Dashboard: today at a glance, things that need attention, recent orders.
 * Revenue figures only for roles with dashboard.revenue (Super Admin by default).
 */
$user = require_permission('dashboard.view');
$showRevenue = can('dashboard.revenue');

$V = ORDER_VISIBLE_SQL;

/** Recent orders panel body (also served alone for live refresh). */
function recent_orders_html(): string
{
    $rows = db_all('SELECT o.*, t.table_number FROM orders o LEFT JOIN restaurant_tables t ON t.id = o.table_id
                     WHERE ' . ORDER_VISIBLE_SQL . ' ORDER BY o.id DESC LIMIT 8');
    if (!$rows) {
        return '<div class="empty-state"><i class="bi bi-receipt"></i>No orders yet today. New orders appear here automatically.</div>';
    }
    ob_start(); ?>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>Order</th><th>Customer</th><th>Type</th><th>Status</th><th class="text-end">Total</th><th>Placed</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $o): ?>
          <tr class="<?= (int) $o['is_seen'] === 0 ? 'unseen' : '' ?>">
            <td><a class="order-no" href="<?= e(admin_url('order.php?id=' . $o['id'])) ?>"><?= e($o['order_number']) ?></a></td>
            <td><?= e($o['customer_name']) ?></td>
            <td><span class="type-chip"><i class="bi <?= order_type_icon($o['order_type']) ?>"></i><?= e(order_type_label($o['order_type'])) ?><?= $o['table_number'] ? ' · ' . e($o['table_number']) : '' ?></span></td>
            <td><?= status_badge($o['status']) ?></td>
            <td class="text-end tabular"><?= money($o['total']) ?></td>
            <td class="text-muted small text-nowrap"><?= e(time_ago($o['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php return (string) ob_get_clean();
}

if (($_GET['partial'] ?? '') === 'recent') {
    header('Content-Type: text/html; charset=utf-8');
    echo recent_orders_html();
    exit;
}

// ---------------- Today's numbers ----------------
$today = date('Y-m-d');
$k = db_one(
    "SELECT
        SUM(o.status <> 'cancelled')                                         AS orders_today,
        SUM(o.status = 'pending')                                            AS pending,
        SUM(o.status IN ('confirmed','preparing'))                           AS in_kitchen,
        SUM(o.status IN ('ready','out_for_delivery'))                        AS ready,
        COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.total END), 0)        AS revenue_today
       FROM orders o WHERE DATE(o.created_at) = ? AND $V",
    [$today]
);
$ordersYesterday = (int) db_value(
    "SELECT COUNT(*) FROM orders o WHERE DATE(o.created_at) = ? AND TIME(o.created_at) <= CURTIME() AND o.status <> 'cancelled' AND $V",
    [date('Y-m-d', strtotime('-1 day'))]
);
$pendingAll = (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.status = 'pending' AND $V");
$res = db_one(
    "SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending FROM reservations
      WHERE reservation_date = ? AND status IN ('pending','confirmed')",
    [$today]
);
$tables = db_one("SELECT COUNT(*) AS total, SUM(status = 'occupied') AS occupied, SUM(status = 'reserved') AS reserved FROM restaurant_tables WHERE is_active = 1");

// ---------------- Alerts ----------------
$alerts = [];
$stale = db_all("SELECT o.id, o.order_number, o.created_at FROM orders o
                  WHERE o.status = 'pending' AND o.created_at < NOW() - INTERVAL 10 MINUTE AND $V ORDER BY o.id LIMIT 5");
if ($stale) {
    $alerts[] = ['danger', 'bi-hourglass-split', count($stale) . ' order' . (count($stale) > 1 ? 's have' : ' has') . ' waited over 10 minutes for confirmation',
                 'orders.php?status=pending', 'Review'];
}
if (can('reservations.view')) {
    $pr = (int) db_value("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND reservation_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 1 DAY");
    if ($pr) {
        $alerts[] = ['warning', 'bi-calendar-event', "$pr table booking" . ($pr > 1 ? 's' : '') . ' for today or tomorrow ' . ($pr > 1 ? 'need' : 'needs') . ' confirming', 'reservations.php?status=pending', 'Confirm'];
    }
}
if (can('payments.view')) {
    $refunds = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND payment_method = 'razorpay' AND payment_status = 'paid'");
    if ($refunds) {
        $alerts[] = ['danger', 'bi-arrow-counterclockwise', "$refunds cancelled online order" . ($refunds > 1 ? 's need' : ' needs') . ' a refund', 'payments.php?filter=refund_needed', 'Refund'];
    }
}
if (can('contacts.view')) {
    $unread = (int) db_value('SELECT COUNT(*) FROM contacts WHERE is_read = 0');
    if ($unread) {
        $alerts[] = ['info', 'bi-envelope', "$unread unread message" . ($unread > 1 ? 's' : '') . ' from the website', 'contacts.php', 'Read'];
    }
}
if (can('menu.view')) {
    $off = (int) db_value('SELECT COUNT(*) FROM menu_items WHERE is_available = 0');
    if ($off) {
        $alerts[] = ['secondary', 'bi-slash-circle', "$off dish" . ($off > 1 ? 'es are' : ' is') . ' marked unavailable and hidden from the menu', 'menu-items.php?available=0', 'View'];
    }
}
// Low activity: no orders for 3 hours during service time
$hour = (int) date('G');
if ($hour >= 13 && $hour < 22) {
    $recent = (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.created_at > NOW() - INTERVAL 3 HOUR AND $V");
    if ($recent === 0) {
        $alerts[] = ['info', 'bi-moon-stars', 'No orders in the last 3 hours. Check that online ordering is switched on, or run an offer.', 'settings.php', 'Settings'];
    }
}

// ---------------- Last 14 days ----------------
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[date('Y-m-d', strtotime("-$i day"))] = ['orders' => 0, 'revenue' => 0.0];
}
foreach (db_all(
    "SELECT DATE(o.created_at) AS d, SUM(o.status <> 'cancelled') AS orders,
            COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.total END), 0) AS revenue
       FROM orders o WHERE o.created_at >= CURDATE() - INTERVAL 13 DAY AND $V GROUP BY DATE(o.created_at)"
) as $r) {
    $days[$r['d']] = ['orders' => (int) $r['orders'], 'revenue' => (float) $r['revenue']];
}
$chart = [
    'labels'  => array_map(static fn ($d) => date('j M', strtotime($d)), array_keys($days)),
    'values'  => array_column(array_values($days), $showRevenue ? 'revenue' : 'orders'),
    'money'   => $showRevenue,
    'name'    => $showRevenue ? 'Revenue' : 'Orders',
];
$periodTotal = array_sum($chart['values']);

// ---------------- Today's reservations ----------------
$todayRes = can('reservations.view') ? db_all(
    "SELECT r.*, t.table_number FROM reservations r LEFT JOIN restaurant_tables t ON t.id = r.table_id
      WHERE r.reservation_date = ? AND r.status IN ('pending','confirmed') ORDER BY r.reservation_time LIMIT 8",
    [$today]
) : [];

$hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
admin_header('Dashboard', 'index.php');
?>
<div class="d-flex justify-content-between align-items-end flex-wrap gap-2">
  <div>
    <div class="eyebrow"><?= e(date('l, j F Y')) ?></div>
    <h2 class="h3 mb-0"><?= e($hello) ?>, <?= e(explode(' ', $user['name'])[0]) ?></h2>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary btn-sm" href="tables.php"><i class="bi bi-grid-3x3-gap me-1"></i>Table list</a>
    <a class="btn btn-primary btn-sm" href="orders.php?status=pending"><i class="bi bi-receipt me-1"></i>Pending orders</a>
  </div>
</div>

<section class="kpis" aria-label="Today">
  <a class="kpi" href="orders.php?date=today">
    <span class="eyebrow">Orders today</span>
    <span class="kpi-value"><?= (int) $k['orders_today'] ?></span>
    <span class="kpi-sub"><?= $ordersYesterday ?> by this time yesterday</span>
  </a>
  <?php if ($showRevenue): ?>
  <div class="kpi">
    <span class="eyebrow">Revenue today</span>
    <span class="kpi-value"><?= money($k['revenue_today']) ?></span>
    <span class="kpi-sub">Paid orders, incl. GST</span>
  </div>
  <?php endif; ?>
  <a class="kpi<?= $pendingAll ? ' attention' : '' ?>" href="orders.php?status=pending">
    <span class="eyebrow">Waiting to confirm</span>
    <span class="kpi-value" id="kpiPending"><?= $pendingAll ?></span>
    <span class="kpi-sub"><?= (int) $k['in_kitchen'] ?> in the kitchen · <?= (int) $k['ready'] ?> ready</span>
  </a>
  <a class="kpi" href="tables.php">
    <span class="eyebrow">Tables in use</span>
    <span class="kpi-value"><?= (int) $tables['occupied'] ?><small class="fs-5 text-muted"> / <?= (int) $tables['total'] ?></small></span>
    <span class="kpi-sub"><?= (int) $tables['reserved'] ?> reserved</span>
  </a>
  <?php if (can('reservations.view')): ?>
  <a class="kpi" href="reservations.php?date=today">
    <span class="eyebrow">Bookings today</span>
    <span class="kpi-value"><?= (int) $res['total'] ?></span>
    <span class="kpi-sub"><?= (int) $res['pending'] ?> awaiting confirmation</span>
  </a>
  <?php endif; ?>
</section>

<?php if ($alerts): ?>
<section class="panel" aria-labelledby="alertsTitle">
  <div class="panel-head"><h2 id="alertsTitle">Needs attention</h2></div>
  <ul class="list-group list-group-flush">
    <?php foreach ($alerts as [$tone, $icon, $text, $url, $cta]): ?>
      <li class="list-group-item d-flex align-items-center gap-3 py-2">
        <span class="badge rounded-pill text-bg-<?= e($tone) ?>"><i class="bi <?= e($icon) ?>"></i></span>
        <span class="flex-grow-1"><?= e($text) ?></span>
        <?php if (is_file(__DIR__ . '/' . strtok($url, '?'))): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= e($url) ?>"><?= e($cta) ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<div class="analytics-grid">
  <section class="panel span-8">
    <div class="panel-head">
      <h2><?= $showRevenue ? 'Revenue' : 'Orders' ?>, last 14 days</h2>
      <span class="text-muted small tabular"><?= $showRevenue ? money($periodTotal) : $periodTotal . ' orders' ?> in total</span>
    </div>
    <div class="panel-body"><div class="chart-box short"><canvas id="dayChart" role="img" aria-label="<?= e($chart['name']) ?> per day for the last 14 days"></canvas></div></div>
  </section>

  <section class="panel span-4">
    <div class="panel-head"><h2>Today's bookings</h2><?php if (is_file(__DIR__ . '/reservations.php')): ?><a class="small" href="reservations.php">All</a><?php endif; ?></div>
    <?php if (!$todayRes): ?>
      <div class="empty-state"><i class="bi bi-calendar2"></i>No table bookings for today.</div>
    <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($todayRes as $r): ?>
          <li class="list-group-item d-flex gap-3 align-items-center">
            <span class="fw-bold tabular" style="width:3.2rem"><?= e(date('g:i', strtotime($r['reservation_time']))) ?><small class="d-block text-muted fw-normal"><?= e(date('A', strtotime($r['reservation_time']))) ?></small></span>
            <span class="flex-grow-1 min-w-0"><b><?= e($r['name']) ?></b><small class="d-block text-muted"><?= (int) $r['guests'] ?> guests · <?= e($r['table_number'] ?: 'No table yet') ?></small></span>
            <?= status_badge($r['status'], ucfirst($r['status'])) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <div class="panel-head"><h2>Latest orders</h2><a class="small" href="orders.php">All orders</a></div>
  <div id="recentOrders"><?= recent_orders_html() ?></div>
</section>

<script>window.DASH = <?= ejs($chart) ?>;</script>
<?php admin_footer(['vendor/chartjs/chart.umd.min.js', 'js/charts.js', 'js/dashboard.js']);
