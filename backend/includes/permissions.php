<?php
/**
 * Role-based permissions.
 *
 * Permissions are stored in the DB (permissions + role_permissions) and loaded
 * once per session. Every admin page AND every admin AJAX endpoint must call
 * require_permission() — hiding a menu link is never enough.
 *
 *   require_permission('orders.update');            // page or AJAX guard
 *   if (can('dashboard.revenue')) { … }             // conditional UI
 *   if (can_any('menu.manage', 'menu.delete')) { … }
 */

declare(strict_types=1);

/** All permission slugs for the current user's role. */
function user_permissions(bool $refresh = false): array
{
    $user = current_user();
    if (!$user) {
        return [];
    }

    // Cached for this request only, so permission changes apply on the next page load
    static $cache = [];
    $roleId = (int) $user['role_id'];
    if (!$refresh && isset($cache[$roleId])) {
        return $cache[$roleId];
    }

    return $cache[$roleId] = db_query(
        'SELECT p.slug FROM permissions p
           JOIN role_permissions rp ON rp.permission_id = p.id
          WHERE rp.role_id = ?',
        [$roleId]
    )->fetchAll(PDO::FETCH_COLUMN);
}

function can(string $permission): bool
{
    if (is_super_admin()) {
        return true;                       // Super Admin always has full access
    }
    return in_array($permission, user_permissions(), true);
}

function can_any(string ...$permissions): bool
{
    foreach ($permissions as $p) {
        if (can($p)) {
            return true;
        }
    }
    return false;
}

function can_all(string ...$permissions): bool
{
    foreach ($permissions as $p) {
        if (!can($p)) {
            return false;
        }
    }
    return true;
}

/**
 * Require login + permission. Responds 403 (JSON for AJAX, page otherwise)
 * and records the attempt in the activity log.
 */
function require_permission(string ...$permissions): array
{
    $user = require_login();

    if (!can_all(...$permissions)) {
        log_activity('access_denied', null, null, 'Denied: ' . implode(', ', $permissions) . ' at ' . ($_SERVER['REQUEST_URI'] ?? ''));

        if (is_ajax()) {
            json_error('You do not have permission to perform this action.', 403);
        }
        http_response_code(403);
        $page = ROOT_PATH . '/admin/403.php';
        if (is_file($page)) {
            require $page;
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Access denied</title>'
               . '<div style="font-family:system-ui;max-width:560px;margin:12vh auto;text-align:center">'
               . '<h1 style="color:#B5582E">Access denied</h1>'
               . '<p>You do not have permission to view this page.</p>'
               . '<a href="' . e(admin_url('index.php')) . '">Back to dashboard</a></div>';
        }
        exit;
    }

    return $user;
}

/** Require the Super Admin role specifically (users, settings, payments config). */
function require_super_admin(): array
{
    $user = require_login();
    if (!is_super_admin()) {
        log_activity('access_denied', null, null, 'Super Admin area: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        if (is_ajax()) {
            json_error('Only the Super Admin can perform this action.', 403);
        }
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Access denied</title>'
           . '<p style="font-family:system-ui;text-align:center;margin-top:15vh">Only the Super Admin can access this page. '
           . '<a href="' . e(admin_url('index.php')) . '">Back to dashboard</a></p>';
        exit;
    }
    return $user;
}

/**
 * Sidebar definition. Each item is shown only if the user holds its permission.
 * Used by the admin layout (Stage 3).
 */
function admin_menu(): array
{
    $items = [
        ['label' => 'Dashboard',      'icon' => 'bi-speedometer2',   'url' => 'index.php',          'perm' => 'dashboard.view'],
        ['label' => 'Orders',         'icon' => 'bi-receipt',        'url' => 'orders.php',         'perm' => 'orders.view'],
        ['label' => 'Table List',     'icon' => 'bi-grid-3x3-gap',   'url' => 'tables.php',         'perm' => 'tables.view'],
        ['label' => 'Reservations',   'icon' => 'bi-calendar-check', 'url' => 'reservations.php',   'perm' => 'reservations.view'],
        ['label' => 'Analytics',      'icon' => 'bi-graph-up-arrow', 'url' => 'analytics.php',      'perm' => 'analytics.view'],
        ['label' => 'Payments',       'icon' => 'bi-credit-card',    'url' => 'payments.php',       'perm' => 'payments.view'],
        ['heading' => 'Content'],
        ['label' => 'Menu',           'icon' => 'bi-journal-text',   'url' => 'menu-items.php',     'perm' => 'menu.view'],
        ['label' => 'Categories',     'icon' => 'bi-tags',           'url' => 'categories.php',     'perm' => 'menu.manage'],
        ['label' => 'Homepage',       'icon' => 'bi-house-gear',     'url' => 'homepage.php',       'perm' => 'homepage.manage'],
        ['label' => 'Gallery',        'icon' => 'bi-images',         'url' => 'gallery.php',        'perm' => 'gallery.manage'],
        ['label' => 'Pages',          'icon' => 'bi-file-earmark-richtext', 'url' => 'pages.php',   'perm' => 'pages.manage'],
        ['label' => 'Testimonials',   'icon' => 'bi-chat-quote',     'url' => 'testimonials.php',   'perm' => 'testimonials.manage'],
        ['label' => 'Coupons',        'icon' => 'bi-ticket-perforated', 'url' => 'coupons.php',     'perm' => 'coupons.manage'],
        ['label' => 'Messages',       'icon' => 'bi-envelope',       'url' => 'contacts.php',       'perm' => 'contacts.view'],
        ['label' => 'Subscribers',    'icon' => 'bi-people',         'url' => 'newsletter.php',     'perm' => 'newsletter.view'],
        ['heading' => 'System'],
        ['label' => 'Settings',       'icon' => 'bi-gear',           'url' => 'settings.php',       'perm' => 'settings.manage'],
        ['label' => 'Users & Roles',  'icon' => 'bi-person-badge',   'url' => 'users.php',          'perm' => 'users.manage'],
        ['label' => 'Activity Log',   'icon' => 'bi-clock-history',  'url' => 'activity-log.php',   'perm' => 'logs.view'],
    ];

    // Drop items the user can't access, then drop headings with no items under them
    $visible = array_values(array_filter($items, static fn ($i) => isset($i['heading']) || can($i['perm'])));
    $result  = [];
    foreach ($visible as $idx => $item) {
        if (isset($item['heading'])) {
            $next = $visible[$idx + 1] ?? null;
            if ($next === null || isset($next['heading'])) {
                continue;
            }
        }
        $result[] = $item;
    }
    return $result;
}
