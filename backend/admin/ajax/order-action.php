<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * POST ajax/order-action.php
 *   action=status    id, status, note   (orders.update)
 *   action=mark_paid id                 (payments.mark_cod)
 *   action=seen      id                 (orders.view)
 *   action=delete    id                 (orders.delete; completed/cancelled only)
 */
require_login();
admin_require_post();

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$order  = db_one('SELECT * FROM orders WHERE id = ?', [$id]);
if (!$order) {
    json_error('Order not found.', 404);
}

try {
    switch ($action) {
        case 'status':
            $user   = require_permission('orders.update');
            $status = (string) ($_POST['status'] ?? '');
            if (!isset(ORDER_STATUSES[$status])) {
                json_error('Unknown status.', 422);
            }
            $updated = change_order_status($id, $status, (int) $user['id'], (string) ($_POST['note'] ?? ''));
            $msg = "{$updated['order_number']} is now " . strtolower(order_status_label($status)) . '.';
            if ($status === 'cancelled' && $order['payment_method'] === 'razorpay' && $order['payment_status'] === 'paid') {
                $msg .= ' The online payment needs a refund from Payments.';
            }
            json_response(['status' => $status, 'label' => order_status_label($status), 'payment_status' => $updated['payment_status']], 200, $msg);

        case 'mark_paid':
            $user = require_permission('payments.mark_cod');
            mark_cod_paid($id, (int) $user['id']);
            json_response(['payment_status' => 'paid'], 200, "{$order['order_number']} marked as paid.");

        case 'seen':
            require_permission('orders.view');
            db_update('orders', ['is_seen' => 1], 'id = ?', [$id]);
            json_response(['unseen' => unseen_order_count()]);

        case 'delete':
            $user = require_permission('orders.delete');
            if (!in_array($order['status'], ['completed', 'cancelled'], true)) {
                json_error('Only completed or cancelled orders can be deleted. Cancel it first.', 409);
            }
            db_query('DELETE FROM orders WHERE id = ?', [$id]);
            log_activity('delete', 'order', $id, "Deleted order {$order['order_number']} (" . money($order['total']) . ')', (int) $user['id']);
            flash('success', "Order {$order['order_number']} was deleted.");
            json_response(['redirect' => admin_url('orders.php')], 200, 'Order deleted.');

        default:
            json_error('Unknown action.', 400);
    }
} catch (OrderException $e) {
    json_error($e->getMessage(), $e->getCode() ?: 422);
}
