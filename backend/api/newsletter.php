<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/newsletter.php
 * Body: { email, website }
 * Re-subscribing an unsubscribed address reactivates it. The response is the
 * same whether or not the address already existed (no email enumeration).
 */
api_endpoint('POST');

$body = request_body();
reject_if_honeypot($body);
rate_limit('newsletter', 5, 3600);

$v = Validator::make($body, ['email' => 'required|email|max:150']);
if ($v->fails()) {
    json_validation_error($v->errors());
}
$email = $v->validated()['email'];

db_query(
    'INSERT INTO newsletter (email, is_active, ip_address) VALUES (?, 1, ?)
     ON DUPLICATE KEY UPDATE is_active = 1, unsubscribed_at = NULL',
    [$email, client_ip()]
);

json_response(null, 201, 'You are on the list. Watch your inbox for seasonal menus and offers.');
