<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/razorpay-webhook.php — server-to-server notifications from Razorpay.
 *
 * Set up in Razorpay Dashboard → Settings → Webhooks:
 *   URL:    {BASE_URL}/api/razorpay-webhook.php
 *   Secret: same value as RAZORPAY_WEBHOOK_SECRET / Admin → Settings → Payments
 *   Events: payment.captured, payment.failed, order.paid, refund.processed
 *
 * This is the safety net when a customer pays but closes the browser before
 * /api/payment-verify.php runs. All handlers are idempotent; Razorpay retries
 * on any non-2xx response.
 */
require_method('POST');

$raw       = raw_request_body();
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!razorpay_verify_webhook_signature($raw, $signature)) {
    app_log('webhook', 'Rejected webhook with bad signature', ['ip' => client_ip()]);
    json_error('Invalid signature.', 400);
}

$event = json_decode($raw, true);
if (!is_array($event) || empty($event['event'])) {
    json_error('Invalid payload.', 400);
}

$type    = (string) $event['event'];
$payment = $event['payload']['payment']['entity'] ?? null;
$refund  = $event['payload']['refund']['entity'] ?? null;

app_log('webhook', "Received $type", ['id' => $payment['id'] ?? $refund['id'] ?? null]);

try {
    switch ($type) {
        case 'payment.captured':
        case 'order.paid':
            if (!empty($payment['order_id']) && !empty($payment['id'])) {
                mark_razorpay_paid($payment['order_id'], $payment['id'], null, $payment);
            }
            break;

        case 'payment.failed':
            if (!empty($payment['order_id'])) {
                mark_razorpay_failed($payment['order_id'], $payment['id'] ?? null, $payment['error_description'] ?? null, $payment);
            }
            break;

        case 'refund.processed':
            if (!empty($refund['payment_id']) && !empty($refund['id'])) {
                mark_refunded($refund['payment_id'], $refund['id'], ((int) ($refund['amount'] ?? 0)) / 100);
            }
            break;

        default:
            // Acknowledge events we don't use so Razorpay stops retrying them
            break;
    }
} catch (OrderException $e) {
    // Unknown Razorpay order (e.g. from another site on the same account): acknowledge
    app_log('webhook', "Ignored $type: " . $e->getMessage());
}

json_response(['received' => true]);
