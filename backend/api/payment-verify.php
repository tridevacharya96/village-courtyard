<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/payment-verify.php
 * Called by the Razorpay Checkout `handler` in the browser after payment.
 *
 * Body: {
 *   "order_number": "VC-20261004-0003",
 *   "razorpay_order_id": "order_…", "razorpay_payment_id": "pay_…", "razorpay_signature": "…"
 * }
 *
 * The signature proves the payment came from Razorpay for this exact order.
 * Safe to call more than once; the webhook may already have confirmed it.
 * Works in maintenance mode so in-flight payments can complete.
 */
api_endpoint('POST', maintenance: false);
rate_limit('payment_verify', 30, 600);

api_handle(static function (): void {
    $body = request_body();

    $orderNumber = clean_text($body['order_number'] ?? '', 20);
    $rzpOrderId  = clean_text($body['razorpay_order_id'] ?? '', 60);
    $paymentId   = clean_text($body['razorpay_payment_id'] ?? '', 60);
    $signature   = clean_text($body['razorpay_signature'] ?? '', 255);

    if ($orderNumber === '' || $rzpOrderId === '' || $paymentId === '' || $signature === '') {
        json_error('Payment details are incomplete.', 422);
    }

    // The Razorpay order must belong to this order number
    $match = db_one(
        'SELECT o.* FROM payments p JOIN orders o ON o.id = p.order_id
          WHERE p.razorpay_order_id = ? AND o.order_number = ? LIMIT 1',
        [$rzpOrderId, $orderNumber]
    );
    if (!$match) {
        json_error('We could not match this payment to your order.', 404);
    }

    if (!razorpay_verify_payment_signature($rzpOrderId, $paymentId, $signature)) {
        app_log('payments', 'Invalid payment signature', ['order' => $orderNumber, 'rzp_order' => $rzpOrderId, 'ip' => client_ip()]);
        mark_razorpay_failed($rzpOrderId, $paymentId, 'Signature verification failed');
        json_error('Payment could not be verified. If money was deducted, it will be refunded automatically by your bank within 5–7 days.', 400);
    }

    mark_razorpay_paid($rzpOrderId, $paymentId, $signature);

    $order = db_one('SELECT * FROM orders WHERE id = ?', [$match['id']]);
    json_response(['order' => public_order($order)], 200, 'Payment received. Your order is confirmed.');
});
