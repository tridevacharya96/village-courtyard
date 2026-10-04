<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/orders.php — place an order.
 *
 * Body:
 * {
 *   "name": "Priya Nayak", "phone": "9437000005", "email": "priya@example.com",
 *   "order_type": "delivery" | "takeaway" | "dine_in",
 *   "table_number": "T3",                 // dine_in only
 *   "delivery_address": "Flat 3B, …",     // delivery only
 *   "notes": "Less spicy",
 *   "items": [{"id": 9, "qty": 2}],
 *   "coupon_code": "WELCOME100",
 *   "payment_method": "razorpay" | "cod",
 *   "website": ""                         // honeypot, must stay empty
 * }
 *
 * 201 → { order: {...}, payment: null | {Razorpay Checkout options} }
 * COD orders are complete at this point. Razorpay orders become visible to the
 * kitchen only after /api/payment-verify.php (or the webhook) confirms payment.
 */
api_endpoint('POST');

api_handle(static function (): void {
    $body = request_body();
    reject_if_honeypot($body);
    rate_limit('order', 10, 600);

    $order = create_order($body);

    $payment = null;
    if ($order['payment_method'] === 'razorpay') {
        try {
            $payment = razorpay_create_order($order);
        } catch (RazorpayException $e) {
            cancel_unpaid_order((int) $order['id'], 'Payment gateway unavailable: ' . $e->getMessage());
            throw $e;
        }
    }

    log_activity('order_placed', 'order', (int) $order['id'], "{$order['order_number']} · " . money($order['total']) . " · {$order['payment_method']}", null);

    json_response([
        'order'   => public_order($order),
        'payment' => $payment,
    ], 201, $payment ? 'Order created. Complete the payment to confirm it.' : 'Order placed! We have started on it.');
});
