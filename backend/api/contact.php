<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/contact.php
 * Body: { name, email, phone, subject, message, website }
 */
api_endpoint('POST');

$body = request_body();
reject_if_honeypot($body);
rate_limit('contact', 5, 3600);

$v = Validator::make($body, [
    'name'    => 'required|string|min:2|max:100',
    'email'   => 'required|email|max:150',
    'phone'   => 'nullable|phone',
    'subject' => 'nullable|string|max:150',
    'message' => 'required|string|min:10|max:3000',
]);
if ($v->fails()) {
    json_validation_error($v->errors());
}
$d = $v->validated();

db_insert('contacts', [
    'name'       => $d['name'],
    'email'      => $d['email'],
    'phone'      => $d['phone'] ?: null,
    'subject'    => $d['subject'] ?: null,
    'message'    => $d['message'],
    'ip_address' => client_ip(),
]);

json_response(null, 201, 'Thank you for writing to us. We usually reply within a day.');
