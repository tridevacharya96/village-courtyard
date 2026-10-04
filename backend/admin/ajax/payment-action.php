<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * POST ajax/payment-action.php  action=refund, id (payments.id), amount, reason   (payments.refund)
 * Calls Razorpay first; our records change only when Razorpay accepts the refund.
 */
$user = require_permission('payments.refund');
admin_require_post();

if (($_POST['action'] ?? '') !== 'refund') {
    json_error('Unknown action.', 400);
}
$p = db_one('SELECT p.*, o.order_number FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ?', [(int) ($_POST['id'] ?? 0)]);
if (!$p || $p['method'] !== 'razorpay' || $p['status'] !== 'paid' || !$p['razorpay_payment_id']) {
    json_error('Only paid online payments can be refunded.', 409);
}
$amount = round((float) ($_POST['amount'] ?? 0), 2);
if ($amount < 1 || $amount > (float) $p['amount']) {
    json_validation_error(['amount' => 'Enter an amount between ₹1 and ' . money($p['amount']) . '.']);
}
if (!razorpay_is_configured()) {
    json_error('Razorpay keys are not set up, so refunds cannot be issued from here.', 503);
}
$reason = clean_text($_POST['reason'] ?? '', 200);

try {
    $refund = razorpay_refund($p['razorpay_payment_id'], $amount, $reason ?: "Refund for {$p['order_number']}");
} catch (RazorpayException $e) {
    log_activity('refund_failed', 'order', (int) $p['order_id'], "Refund of " . money($amount) . " for {$p['order_number']} failed: " . $e->getMessage(), (int) $user['id']);
    json_error('Razorpay did not accept the refund: ' . $e->getMessage(), 502);
}

mark_refunded($p['razorpay_payment_id'], (string) $refund['id'], $amount);
log_activity('refund', 'order', (int) $p['order_id'], "Refunded " . money($amount) . " for {$p['order_number']} ({$refund['id']})" . ($reason ? ": $reason" : ''), (int) $user['id']);
json_response(['refund_id' => $refund['id']], 200, 'Refund of ' . money($amount) . ' issued. The customer usually sees it in 5–7 working days.');
