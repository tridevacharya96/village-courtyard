<?php
/**
 * Bootstrap — include this first in every API endpoint and admin page.
 *
 *   // API endpoint (backend/api/menu.php)
 *   define('APP_CONTEXT', 'api');
 *   require __DIR__ . '/../includes/bootstrap.php';
 *
 *   // Admin page (backend/admin/orders.php)
 *   define('APP_CONTEXT', 'admin');
 *   require __DIR__ . '/../includes/bootstrap.php';
 *   require_permission('orders.view');
 */

declare(strict_types=1);

if (!defined('APP_CONTEXT')) {
    define('APP_CONTEXT', 'admin');
}

require_once dirname(__DIR__) . '/config/config.php';
require_once CONFIG_PATH . '/db.php';

require_once INCLUDE_PATH . '/helpers.php';
require_once INCLUDE_PATH . '/response.php';
require_once INCLUDE_PATH . '/validation.php';
require_once INCLUDE_PATH . '/csrf.php';
require_once INCLUDE_PATH . '/logger.php';
require_once INCLUDE_PATH . '/rate_limit.php';
require_once INCLUDE_PATH . '/upload.php';
require_once INCLUDE_PATH . '/auth.php';
require_once INCLUDE_PATH . '/permissions.php';
require_once INCLUDE_PATH . '/orders.php';
require_once INCLUDE_PATH . '/razorpay.php';
require_once INCLUDE_PATH . '/reservations.php';
require_once INCLUDE_PATH . '/api.php';

// ---------------------------------------------------------------------
//  Error handling
// ---------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(STORAGE_PATH . '/logs')) {
    @mkdir(STORAGE_PATH . '/logs', 0775, true);
}
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');

// Promote warnings/notices to exceptions so nothing fails silently
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    $publicMessage = APP_DEBUG ? $e->getMessage() : 'Something went wrong. Please try again.';

    if (APP_CONTEXT === 'api' || is_ajax()) {
        json_error($publicMessage, 500, APP_DEBUG ? ['trace' => explode("\n", $e->getTraceAsString())] : []);
    }

    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
       . '<div style="font-family:system-ui;max-width:640px;margin:10vh auto;padding:24px">'
       . '<h1 style="color:#1F3D2B">Something went wrong</h1><p>' . e($publicMessage) . '</p></div>';
    exit;
});

// ---------------------------------------------------------------------
//  Security headers (common to API and admin)
// ---------------------------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
}

// ---------------------------------------------------------------------
//  Context-specific setup
// ---------------------------------------------------------------------
if (APP_CONTEXT === 'api') {
    send_cors_headers();          // also answers OPTIONS preflight and exits
    header('Content-Type: application/json; charset=utf-8');
} else {
    start_secure_session();
    enforce_session_timeout();
}
