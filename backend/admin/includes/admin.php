<?php
/**
 * Admin panel helpers and page layout.
 *
 *   define('APP_CONTEXT', 'admin');
 *   require __DIR__ . '/../includes/bootstrap.php';
 *   require __DIR__ . '/includes/admin.php';
 *   $user = require_permission('orders.view');
 *
 *   admin_header('Orders', 'orders.php');
 *   …page HTML…
 *   admin_footer(['js/orders.js']);
 */

declare(strict_types=1);

/** Permissions that can never be given to the Staff role. */
const SUPER_ADMIN_ONLY_PERMISSIONS = [
    'dashboard.revenue', 'analytics.view', 'analytics.export',
    'orders.delete', 'tables.manage', 'menu.delete', 'reservations.delete', 'contacts.delete',
    'payments.refund', 'payments.config', 'settings.manage', 'users.manage', 'logs.view',
];

/** Versioned URL for an admin asset (cache-busting by file time). */
function admin_asset(string $path): string
{
    $file = __DIR__ . '/../assets/' . ltrim($path, '/');
    $ver  = is_file($file) ? filemtime($file) : 1;
    return admin_url('assets/' . ltrim($path, '/')) . '?v=' . $ver;
}

/** Require POST + valid CSRF token (for AJAX endpoints and form handlers). */
function admin_require_post(): void
{
    if (!is_post()) {
        if (is_ajax()) {
            json_error('Method not allowed.', 405);
        }
        redirect(admin_url('index.php'));
    }
    verify_csrf();
}

/** Current query string with some values replaced (null removes a key). */
function qs(array $replace = []): string
{
    $q = array_merge($_GET, ['partial' => null], $replace);
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '');
    return $q ? '?' . http_build_query($q) : '?';
}

function status_badge(string $status, ?string $label = null): string
{
    $label ??= match ($status) {
        'cod'      => 'Cash',
        'razorpay' => 'Online',
        'dine_in'  => 'Dine-in',
        default    => order_status_label($status),
    };
    return '<span class="badge status-badge ' . e(status_badge_class($status)) . '">' . e($label) . '</span>';
}

function order_type_label(string $type): string
{
    return ['delivery' => 'Delivery', 'takeaway' => 'Takeaway', 'dine_in' => 'Dine-in'][$type] ?? $type;
}

function order_type_icon(string $type): string
{
    return ['delivery' => 'bi-bicycle', 'takeaway' => 'bi-bag', 'dine_in' => 'bi-cup-hot'][$type] ?? 'bi-receipt';
}

/** Bootstrap pagination that keeps the current filters. */
function render_pagination(int $total, int $page, int $perPage): string
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) {
        return '';
    }
    $html = '<nav aria-label="Pages"><ul class="pagination pagination-sm mb-0">';
    $link = static function (int $p, string $label, bool $disabled = false, bool $active = false): string {
        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
        return '<li class="' . $cls . '"><a class="page-link" href="' . e(qs(['page' => $p])) . '">' . $label . '</a></li>';
    };
    $html .= $link(max(1, $page - 1), '&lsaquo;', $page <= 1);
    $start = max(1, $page - 2);
    $end   = min($pages, $page + 2);
    if ($start > 1) {
        $html .= $link(1, '1') . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
    }
    for ($p = $start; $p <= $end; $p++) {
        $html .= $link($p, (string) $p, false, $p === $page);
    }
    if ($end < $pages) {
        $html .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $link($pages, (string) $pages);
    }
    $html .= $link(min($pages, $page + 1), '&rsaquo;', $page >= $pages);
    return $html . '</ul></nav>';
}

/** Short summary like "Showing 21–40 of 132". */
function pagination_summary(int $total, int $page, int $perPage): string
{
    if ($total === 0) {
        return 'No results';
    }
    $from = ($page - 1) * $perPage + 1;
    return sprintf('Showing %d–%d of %d', $from, min($total, $page * $perPage), $total);
}

/** Latest visible order id, so the poller knows where to start. */
function latest_visible_order_id(): int
{
    return (int) db_value('SELECT COALESCE(MAX(o.id), 0) FROM orders o WHERE ' . ORDER_VISIBLE_SQL);
}

function unseen_order_count(): int
{
    return (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.is_seen = 0 AND o.status <> 'cancelled' AND " . ORDER_VISIBLE_SQL);
}

// ---------------------------------------------------------------------
//  Layout
// ---------------------------------------------------------------------

function admin_header(string $title, string $activeUrl = '', array $options = []): void
{
    $user     = current_user();
    $siteName = (string) setting('site_name', APP_NAME);
    $menu     = $user ? admin_menu() : [];
    $bare     = !empty($options['bare']);           // login / change-password pages
    $canPoll  = $user && !$bare && !(int) $user['force_password_change'] && can('orders.view');
    $favicon  = (string) setting('favicon', '');
    $GLOBALS['__admin_bare'] = $bare;
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <?= csrf_meta() ?>
  <title><?= e($title) ?> · <?= e($siteName) ?> Admin</title>
  <?php if ($favicon !== '' && is_file(ROOT_PATH . '/' . $favicon)): ?><link rel="icon" href="<?= e(upload_url($favicon)) ?>"><?php endif; ?>
  <link rel="stylesheet" href="<?= e(admin_asset('vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(admin_asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(admin_asset('css/admin.css')) ?>">
</head>
<body class="<?= $bare ? 'admin-bare' : 'admin' ?>"
      data-base="<?= e(admin_url('')) ?>"
      <?php if ($canPoll): ?>data-poll="1" data-last-order="<?= latest_visible_order_id() ?>"<?php endif; ?>>
<?php if ($bare): ?>
<main class="bare-main">
<?php else: ?>
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>
<div class="admin-shell">
  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar" aria-label="Admin navigation">
    <div class="sidebar-brand">
      <a href="<?= e(admin_url('index.php')) ?>" class="brand-link">
        <?= admin_logo_svg() ?>
        <span><b><?= e($siteName) ?></b><small>Admin</small></span>
      </a>
      <button type="button" class="btn-close btn-close-white d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close menu"></button>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($menu as $item): ?>
        <?php if (isset($item['heading'])): ?>
          <div class="nav-heading"><?= e($item['heading']) ?></div>
        <?php else:
            $exists = is_file(__DIR__ . '/../' . $item['url']);
            $active = $item['url'] === $activeUrl; ?>
          <?php if ($exists): ?>
            <a class="nav-item<?= $active ? ' active' : '' ?>" href="<?= e(admin_url($item['url'])) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
              <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span>
              <?php if ($item['url'] === 'orders.php'): ?><span class="nav-count" id="navOrderCount" hidden></span><?php endif; ?>
            </a>
          <?php else: ?>
            <span class="nav-item disabled" title="Built in the next stage">
              <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i><span><?= e($item['label']) ?></span><span class="nav-soon">Soon</span>
            </span>
          <?php endif; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <a href="<?= e(FRONTEND_URL ?: '/') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> View website</a>
    </div>
  </aside>

  <div class="admin-main">
    <header class="topbar">
      <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="Open menu"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="topbar-actions">
        <?php if ($canPoll): ?>
          <button class="btn btn-icon" type="button" id="soundToggle" aria-pressed="false" title="New-order sound is off. Click to turn on.">
            <i class="bi bi-volume-mute"></i><span class="visually-hidden">Toggle new-order sound</span>
          </button>
          <a class="btn btn-icon position-relative" href="<?= e(admin_url('orders.php?seen=0')) ?>" title="New orders">
            <i class="bi bi-bell"></i><span class="bell-dot" id="bellCount" hidden></span><span class="visually-hidden">New orders</span>
          </a>
        <?php endif; ?>
        <div class="dropdown">
          <button class="btn user-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1))) ?></span>
            <span class="d-none d-sm-inline user-meta"><b><?= e($user['name'] ?? '') ?></b><small><?= e($user['role_name'] ?? '') ?></small></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= e($user['email'] ?? '') ?></span></li>
            <li><a class="dropdown-item" href="<?= e(admin_url('change-password.php')) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <form method="post" action="<?= e(admin_url('logout.php')) ?>"><?= csrf_field() ?>
                <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign out</button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>
    <main id="main" class="content">
<?php endif; ?>
<?php render_flashes(); ?>
    <?php
}

function admin_footer(array $scripts = []): void
{
    $bare = !empty($GLOBALS['__admin_bare']);
    ?>
<?php if (!$bare): ?>
    </main>
  </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastArea" aria-live="polite"></div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= e(admin_asset('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(admin_asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= e(str_starts_with($src, 'vendor/') || str_starts_with($src, 'js/') ? admin_asset($src) : $src) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(admin_asset('js/admin.js')) ?>"></script>
</body>
</html>
    <?php
}

function render_flashes(): void
{
    foreach (get_flashes() as $f) {
        $type = in_array($f['type'], ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        echo '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert">' . e($f['message'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
}

/** Courtyard-arch mark (same drawing as branding/logo-icon.svg), used in the sidebar and login page. */
function admin_logo_svg(int $size = 38): string
{
    return '<svg class="brand-mark" width="' . $size . '" height="' . $size . '" viewBox="0 0 240 240" aria-hidden="true">'
        . '<path d="M48 212V112a72 72 0 0 1 144 0v100" fill="none" stroke="#C9A24D" stroke-width="12" stroke-linecap="round"/>'
        . '<path d="M76 212V120a44 44 0 0 1 88 0v92" fill="none" stroke="#D9774A" stroke-width="8" stroke-linecap="round"/>'
        . '<path d="M24 214h192" stroke="#C9A24D" stroke-width="10" stroke-linecap="round"/>'
        . '<path d="M120 4c15 9 17 24 0 34c-17-10-15-25 0-34z" fill="#C9A24D"/>'
        . '<path d="M120 76v20" stroke="#C9A24D" stroke-width="4" stroke-linecap="round"/><path d="M110 104l4-8h12l4 8z" fill="#C9A24D"/>'
        . '<rect x="105" y="104" width="30" height="38" rx="7" fill="#C9A24D"/><rect x="112" y="111" width="16" height="24" rx="4" fill="#F6F0E4"/></svg>';
}
