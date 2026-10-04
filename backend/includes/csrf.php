<?php
/**
 * CSRF protection for the admin panel (forms and jQuery AJAX).
 *
 *   <form method="post"> <?= csrf_field() ?> … </form>
 *   <meta name="csrf-token" content="<?= e(csrf_token()) ?>">   // read by admin.js for AJAX
 *
 *   if (is_post()) verify_csrf();   // at the top of the handler
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_meta(): string
{
    return '<meta name="csrf-token" content="' . e(csrf_token()) . '">';
}

/** Constant-time comparison of the submitted token with the session token. */
function csrf_valid(?string $token): bool
{
    $sessionToken = $_SESSION['_csrf_token'] ?? '';
    return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

/**
 * Verify the token from POST body or X-CSRF-Token header.
 * Fails with JSON (AJAX) or a 419 page (forms).
 */
function verify_csrf(): void
{
    $token = $_POST['_csrf']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? (request_body()['_csrf'] ?? null);

    if (csrf_valid($token)) {
        return;
    }

    log_activity('csrf_failed', null, null, 'Rejected request with invalid CSRF token');

    if (is_ajax() || APP_CONTEXT === 'api') {
        json_error('Your session has expired. Please refresh the page and try again.', 419);
    }
    http_response_code(419);
    flash('danger', 'Your session has expired. Please try again.');
    redirect($_SERVER['HTTP_REFERER'] ?? admin_url('index.php'));
}

/** Rotate the token (call after login/logout). */
function regenerate_csrf_token(): void
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
