<?php
/**
 * IP-based rate limiting for public endpoints, backed by the rate_limits table.
 *
 *   rate_limit('contact', 5, 3600);      // max 5 contact submissions / hour / IP
 *   rate_limit('order', 10, 600);        // max 10 orders / 10 min / IP
 */

declare(strict_types=1);

/**
 * Record a hit and stop with HTTP 429 if the limit is exceeded.
 */
function rate_limit(string $bucket, int $maxHits, int $windowSeconds): void
{
    if (!rate_limit_check($bucket, $maxHits, $windowSeconds)) {
        header('Retry-After: ' . $windowSeconds);
        json_error('Too many requests. Please wait a little and try again.', 429);
    }
}

/** Returns false when over the limit; otherwise records the hit and returns true. */
function rate_limit_check(string $bucket, int $maxHits, int $windowSeconds): bool
{
    $ip = client_ip();

    // Occasionally purge old rows (≈1 in 50 requests) to keep the table small
    if (random_int(1, 50) === 1) {
        db_query('DELETE FROM rate_limits WHERE hit_at < NOW() - INTERVAL 1 DAY');
    }

    $hits = (int) db_value(
        'SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND ip_address = ? AND hit_at > NOW() - INTERVAL ? SECOND',
        [$bucket, $ip, $windowSeconds]
    );

    if ($hits >= $maxHits) {
        return false;
    }

    db_insert('rate_limits', ['bucket' => mb_substr($bucket, 0, 60), 'ip_address' => $ip]);
    return true;
}

/**
 * Honeypot check for public forms: the React form includes a hidden "website"
 * field that real users never fill in. Bots usually do.
 */
function reject_if_honeypot(array $body): void
{
    if (!empty($body['website'])) {
        // Pretend success so bots don't learn they were caught
        json_response(null, 200, 'Thank you!');
    }
}
