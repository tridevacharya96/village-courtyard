<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/** POST ajax/settings-test.php — check the Razorpay keys with a harmless read-only API call. */
require_permission('payments.config');
admin_require_post();

if (!razorpay_is_configured()) {
    json_error('Add the Razorpay key ID and secret first.', 422);
}
try {
    razorpay_request('GET', '/orders?count=1');
} catch (RazorpayException $e) {
    $msg = $e->getCode() === 401 ? 'Razorpay rejected these keys. Check the key ID and secret match (test with test, live with live).' : 'Could not reach Razorpay: ' . $e->getMessage();
    json_error($msg, 502);
}
$mode = str_contains((string) setting('razorpay_key_id'), '_live_') ? 'LIVE mode — real money' : 'test mode';
json_response(null, 200, "Connected to Razorpay ($mode).");
