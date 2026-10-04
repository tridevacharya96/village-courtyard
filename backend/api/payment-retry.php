<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/payment-retry.php
 * Body: { "order_number": "VC-20261004-0003", "phone": "9437000005" }
 *
 * For a customer who closed the payment window or whose payment failed:
 * issues a fresh Razorpay order for the same (unpaid) order and returns new
 * Checkout options. The phone number must match the order.
 */
api_endpoint('POST');
rate_limit('payment_retry', 10, 600);

api_handle(static function (): void {
    $body  = request_body();
    $order = db_one('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [clean_text($body['order_number'] ?? '', 20)]);

    if (!$order || !phones_match($order['customer_phone'], (string) ($body['phone'] ?? ''))) {
        json_error('We could not find an order with that number and phone.', 404);
    }
    if ($order['payment_method'] !== 'razorpay') {
        json_error('This order is paid on delivery.', 409);
    }
    if ($order['payment_status'] === 'paid') {
        json_response(['order' => public_order($order), 'payment' => null], 200, 'This order is already paid.');
    }
    if ($order['payment_status'] === 'refunded') {
        json_error('This order was refunded. Please place a new order.', 409);
    }
    if ($order['status'] === 'cancelled') {
        json_error('This order was cancelled. Please place a new order.', 409);
    }
    if (strtotime($order['created_at']) < time() - 2 * 3600) {
        json_error('This order is too old to pay for. Please place a new order.', 409);
    }
    if (!razorpay_is_configured()) {
        json_error('Online payment is not available right now.', 503);
    }

    db_query("UPDATE orders SET payment_status = 'pending' WHERE id = ? AND payment_status = 'failed'", [$order['id']]);
    $payment = razorpay_create_order($order);

    json_response(['order' => public_order($order), 'payment' => $payment]);
});
