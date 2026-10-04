<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * GET ajax/poll.php?since=<last order id seen by this tab>
 * Polled every 15 s by admin.js. Returns new kitchen-visible orders and badge counts.
 */
require_permission('orders.view');

$since = max(0, (int) ($_GET['since'] ?? 0));

$new = db_all(
    "SELECT o.id, o.order_number, o.total, o.order_type, o.customer_name
       FROM orders o
      WHERE o.id > ? AND o.status <> 'cancelled' AND " . ORDER_VISIBLE_SQL . '
      ORDER BY o.id ASC LIMIT 10',
    [$since]
);

json_response([
    'latest_id'  => latest_visible_order_id(),
    'unseen'     => unseen_order_count(),
    'pending'    => (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.status = 'pending' AND " . ORDER_VISIBLE_SQL),
    'new_orders' => array_map(static fn ($o) => [
        'id'           => (int) $o['id'],
        'order_number' => $o['order_number'],
        'total'        => (float) $o['total'],
        'type_label'   => strtolower(order_type_label($o['order_type'])),
        'customer'     => $o['customer_name'],
    ], $new),
]);
