<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * POST /api/track.php — page-view analytics, sent by the React router on each navigation.
 * Body: { "path": "/menu", "referrer": "https://google.com/" }
 *
 * Privacy: no IP or user agent is stored. visitor_id is a daily-rotating hash
 * of IP + user agent + date + a server salt, so a visitor counts once per day
 * and can't be followed across days.
 */
require_method('POST');

if (!rate_limit_check('pageview', 120, 60)) {
    json_response(null, 202);                      // silently drop, never break the app
}

$body = request_body();
$path = (string) ($body['path'] ?? '/');
$path = '/' . ltrim(substr(parse_url($path, PHP_URL_PATH) ?: '/', 0, 255), '/');

// Ignore bots and admin URLs
$ua = user_agent();
if (preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse/i', $ua) || str_starts_with($path, '/admin')) {
    json_response(null, 202);
}

$referrer = null;
if (!empty($body['referrer']) && filter_var($body['referrer'], FILTER_VALIDATE_URL)) {
    $host = parse_url((string) $body['referrer'], PHP_URL_HOST);
    $own  = parse_url(FRONTEND_URL, PHP_URL_HOST);
    $referrer = $host && $host !== $own ? mb_substr((string) $body['referrer'], 0, 255) : null;
}

$device = match (true) {
    (bool) preg_match('/ipad|tablet|kindle|playbook|silk|(android(?!.*mobile))/i', $ua) => 'tablet',
    (bool) preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/i', $ua) => 'mobile',
    (bool) preg_match('/windows|macintosh|linux|cros/i', $ua) => 'desktop',
    default => 'other',
};

$salt = (string) env('ANALYTICS_SALT', env('DB_PASS', '') . APP_NAME);

db_insert('page_views', [
    'path'       => $path,
    'visitor_id' => md5(client_ip() . '|' . $ua . '|' . date('Y-m-d') . '|' . $salt),
    'referrer'   => $referrer,
    'device'     => $device,
]);

json_response(null, 201);
