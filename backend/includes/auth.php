<?php
/**
 * Admin authentication: secure sessions, login with lockout, logout,
 * inactivity timeout and forced password change.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Session
// ---------------------------------------------------------------------

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(BASE_URL, 'https://');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Log out users who have been inactive longer than SESSION_LIFETIME. */
function enforce_session_timeout(): void
{
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    $last = $_SESSION['last_activity'] ?? time();
    if (time() - $last > SESSION_LIFETIME) {
        logout_user('timeout');
        start_secure_session();
        flash('warning', 'You were signed out after a period of inactivity.');
        return;
    }
    $_SESSION['last_activity'] = time();
}

// ---------------------------------------------------------------------
//  Login / logout
// ---------------------------------------------------------------------

/**
 * Attempt a login. Returns ['ok' => bool, 'error' => ?string].
 * Locks out an email or IP after LOGIN_MAX_ATTEMPTS failures in the window.
 */
function attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    $ip    = client_ip();

    if (is_login_locked($email, $ip)) {
        return ['ok' => false, 'error' => sprintf(
            'Too many failed attempts. Please try again in %d minutes.',
            LOGIN_LOCKOUT_MINUTES
        )];
    }

    $user = db_one(
        'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = ? LIMIT 1',
        [$email]
    );

    // Always run password_verify to keep timing similar for unknown emails
    $hash  = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaltfakehashfakehashfakehashfa';
    $valid = password_verify($password, $hash);

    if (!$user || !$valid) {
        record_login_attempt($email, $ip, false);
        log_activity('login_failed', 'user', $user['id'] ?? null, "Failed login for $email", $user['id'] ?? null);
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    if (!(int) $user['is_active']) {
        record_login_attempt($email, $ip, false);
        return ['ok' => false, 'error' => 'Your account has been deactivated. Please contact the administrator.'];
    }

    // Upgrade hash if PHP's default algorithm/cost changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
    }

    login_user($user);
    record_login_attempt($email, $ip, true);
    return ['ok' => true, 'error' => null];
}

/** Put the user in the session (prevents session fixation). */
function login_user(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    regenerate_csrf_token();

    $_SESSION['user_id']              = (int) $user['id'];
    $_SESSION['last_activity']         = time();
    $_SESSION['force_password_change'] = (int) $user['force_password_change'];
    $_SESSION['ua_hash']               = hash('sha256', user_agent());
    unset($_SESSION['_permissions']);
    current_user(true);                    // reset per-request cache to the new user

    db_update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = ?', [$user['id']]);
    log_activity('login', 'user', (int) $user['id'], 'Signed in', (int) $user['id']);
}

function logout_user(string $reason = 'logout'): void
{
    if ($id = current_user_id()) {
        log_activity($reason, 'user', $id, $reason === 'timeout' ? 'Session timed out' : 'Signed out', $id);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function record_login_attempt(string $email, string $ip, bool $success): void
{
    db_insert('login_attempts', ['email' => mb_substr($email, 0, 150), 'ip_address' => $ip, 'success' => (int) $success]);

    if ($success) {
        // A successful login clears the failure counter for this email
        db_query('DELETE FROM login_attempts WHERE email = ? AND success = 0', [$email]);
    }
}

function is_login_locked(string $email, string $ip): bool
{
    $failures = (int) db_value(
        'SELECT COUNT(*) FROM login_attempts
          WHERE success = 0 AND (email = ? OR ip_address = ?)
            AND attempted_at > NOW() - INTERVAL ? MINUTE',
        [$email, $ip, LOGIN_LOCKOUT_MINUTES]
    );
    // IP limit is higher so one office IP isn't locked by a single user's typos
    return $failures >= LOGIN_MAX_ATTEMPTS * 2
        || (int) db_value(
            'SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND email = ? AND attempted_at > NOW() - INTERVAL ? MINUTE',
            [$email, LOGIN_LOCKOUT_MINUTES]
        ) >= LOGIN_MAX_ATTEMPTS;
}

// ---------------------------------------------------------------------
//  Current user
// ---------------------------------------------------------------------

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/** Current user row with role info (cached per request). Null if logged out. */
function current_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$refresh) {
        return $user;
    }
    $loaded = true;

    $id = current_user_id();
    if (!$id) {
        return $user = null;
    }

    $user = db_one(
        'SELECT u.id, u.name, u.email, u.phone, u.avatar, u.is_active, u.force_password_change,
                u.last_login_at, r.id AS role_id, r.slug AS role_slug, r.name AS role_name
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.id = ? LIMIT 1',
        [$id]
    );

    // Account deleted/deactivated mid-session, or session hijack (UA changed)
    $uaChanged = isset($_SESSION['ua_hash']) && !hash_equals($_SESSION['ua_hash'], hash('sha256', user_agent()));
    if (!$user || !(int) $user['is_active'] || $uaChanged) {
        logout_user('forced_logout');
        return $user = null;
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_super_admin(): bool
{
    return (current_user()['role_slug'] ?? null) === 'super_admin';
}

/**
 * Guard for every admin page/endpoint. Redirects (or 401 JSON) when logged
 * out, and sends users with a temporary password to change-password.php.
 */
function require_login(): array
{
    $user = current_user();

    if (!$user) {
        if (is_ajax()) {
            json_error('Your session has ended. Please sign in again.', 401);
        }
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect(admin_url('login.php'));
    }

    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ((int) $user['force_password_change'] === 1 && !in_array($page, ['change-password.php', 'logout.php'], true)) {
        if (is_ajax()) {
            json_error('Please change your password before continuing.', 403);
        }
        flash('warning', 'For security, please set a new password before continuing.');
        redirect(admin_url('change-password.php'));
    }

    return $user;
}

/** Change the logged-in user's password after verifying the current one. */
function change_password(int $userId, string $current, string $new): array
{
    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
    if (!password_verify($current, $hash)) {
        return ['ok' => false, 'error' => 'Your current password is incorrect.'];
    }
    if (password_verify($new, $hash)) {
        return ['ok' => false, 'error' => 'Your new password must be different from the current one.'];
    }
    db_update('users', [
        'password_hash'         => password_hash($new, PASSWORD_DEFAULT),
        'force_password_change' => 0,
    ], 'id = ?', [$userId]);

    $_SESSION['force_password_change'] = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    current_user(true);
    log_activity('password_change', 'user', $userId, 'Changed own password');
    return ['ok' => true, 'error' => null];
}
