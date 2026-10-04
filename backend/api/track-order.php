<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/track-order.php
 * Body: { "order_number": "VC-20261004-0003", "phone": "9437000005" }
 *
 * Returns live status, progress steps and history. POST (not GET) keeps the
 * phone number out of URLs and server logs. The tracking page polls this
 * every 20–30 seconds.
 */
api_endpoint('POST');
rate_limit('track_order', 60, 600);

$body        = request_body();
$orderNumber = strtoupper(clean_text($body['order_number'] ?? '', 20));
$phone       = (string) ($body['phone'] ?? '');

if ($orderNumber === '' || $phone === '') {
    json_validation_error(array_filter([
        'order_number' => $orderNumber === '' ? 'Enter your order number.' : null,
        'phone'        => $phone === '' ? 'Enter the phone number used for the order.' : null,
    ]));
}

$order = db_one('SELECT * FROM orders WHERE order_number = ? LIMIT 1', [$orderNumber]);

// Same message for "no such order" and "wrong phone" so order numbers can't be probed
if (!$order || !phones_match($order['customer_phone'], $phone)) {
    json_error('We could not find an order with that number and phone.', 404);
}

json_response(['order' => public_order($order, true)]);
