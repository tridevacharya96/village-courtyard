<?php
/**
 * Village Courtyard — application configuration.
 *
 * Reads backend/.env (KEY=value lines) and exposes values through env() and
 * a set of constants used across the API and admin panel.
 */

declare(strict_types=1);

define('ROOT_PATH',    dirname(__DIR__));                 // /backend
define('CONFIG_PATH',  ROOT_PATH . '/config');
define('INCLUDE_PATH', ROOT_PATH . '/includes');
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('STORAGE_PATH', ROOT_PATH . '/storage');           // logs, cache

/**
 * Minimal .env loader (no Composer dependency).
 * Supports quoted values and trailing "# comments".
 */
function load_env(string $file): void
{
    if (!is_readable($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));

        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            // Quoted: take everything up to the matching closing quote
            $quote = $value[0];
            $end   = strpos($value, $quote, 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        } else {
            // Unquoted: strip inline comment
            $value = trim(preg_replace('/\s+#.*$/', '', $value));
        }

        if (getenv($key) === false) {           // real environment wins
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

/** Read an env value with type casting for true/false/null. */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return match (strtolower((string) $value)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $value,
    };
}

load_env(ROOT_PATH . '/.env');

define('APP_NAME',  (string) env('APP_NAME', 'Village Courtyard'));
define('APP_ENV',   (string) env('APP_ENV', 'production'));
define('APP_DEBUG', (bool)   env('APP_DEBUG', false));
define('BASE_URL',  rtrim((string) env('BASE_URL', ''), '/'));
define('FRONTEND_URL', rtrim((string) env('FRONTEND_URL', ''), '/'));

define('SESSION_NAME',          (string) env('SESSION_NAME', 'vc_admin'));
define('SESSION_LIFETIME',      (int) env('SESSION_LIFETIME', 7200));
define('LOGIN_MAX_ATTEMPTS',    (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));
define('UPLOAD_MAX_BYTES',      (int) env('UPLOAD_MAX_MB', 5) * 1024 * 1024);

/** Allowed CORS origins for the React frontend. */
define('CORS_ORIGINS', array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ORIGINS', FRONTEND_URL))
))));

date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Kolkata'));

/** Order status workflow — single source of truth for API + admin. */
const ORDER_STATUSES = [
    'pending'          => 'Pending',
    'confirmed'        => 'Confirmed',
    'preparing'        => 'Preparing',
    'ready'            => 'Ready',
    'out_for_delivery' => 'Out for Delivery',
    'served'           => 'Served',
    'completed'        => 'Completed',
    'cancelled'        => 'Cancelled',
];

/** Allowed next statuses for each status (enforced server-side). */
const ORDER_TRANSITIONS = [
    'pending'          => ['confirmed', 'cancelled'],
    'confirmed'        => ['preparing', 'cancelled'],
    'preparing'        => ['ready', 'cancelled'],
    'ready'            => ['out_for_delivery', 'served', 'completed', 'cancelled'],
    'out_for_delivery' => ['completed', 'cancelled'],
    'served'           => ['completed'],
    'completed'        => [],
    'cancelled'        => [],
];

/** Statuses that still count as "active" for table occupancy and kitchen views. */
const ACTIVE_ORDER_STATUSES = ['pending', 'confirmed', 'preparing', 'ready', 'out_for_delivery', 'served'];

const RESERVATION_STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'];
const TABLE_STATUSES       = ['available', 'occupied', 'reserved'];
