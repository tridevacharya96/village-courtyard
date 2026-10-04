<?php
/**
 * General helpers: escaping, settings, URLs, formatting, flash messages.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
//  Output escaping (XSS-safe)
// ---------------------------------------------------------------------

/** Escape for HTML output. Always use when echoing user/DB data. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape for use inside a JS string literal in an HTML attribute/script. */
function ejs(mixed $value): string
{
    return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

/**
 * Allow-list HTML sanitiser for CMS rich-text content (TinyMCE/Summernote).
 * Strips scripts, event handlers, javascript: URLs and disallowed tags.
 */
function sanitize_html(string $html): string
{
    $allowed = '<h1><h2><h3><h4><h5><h6><p><br><hr><strong><b><em><i><u><s><blockquote>'
             . '<ul><ol><li><a><img><figure><figcaption><table><thead><tbody><tr><th><td><span><div><pre><code>';

    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = strip_tags($html, $allowed);

    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $doc->getElementById('__root');
    if (!$root) {
        return '';
    }

    $allowedAttrs = ['href', 'src', 'alt', 'title', 'class', 'target', 'rel', 'width', 'height', 'colspan', 'rowspan'];
    foreach ((new DOMXPath($doc))->query('.//*', $root) as $node) {
        /** @var DOMElement $node */
        foreach (iterator_to_array($node->attributes) as $attr) {
            $name  = strtolower($attr->name);
            $value = trim($attr->value);
            $badUrl = in_array($name, ['href', 'src'], true)
                && preg_match('#^\s*(javascript|vbscript|data):#i', $value)
                && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $value);

            if (!in_array($name, $allowedAttrs, true) || $badUrl) {
                $node->removeAttribute($attr->name);
            }
        }
        if ($node->nodeName === 'a' && $node->getAttribute('target') === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

/** Trim and strip tags from plain-text input. */
function clean_text(mixed $value, int $maxLength = 0): string
{
    $value = trim(strip_tags((string) ($value ?? '')));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return $maxLength > 0 ? mb_substr($value, 0, $maxLength) : $value;
}

// ---------------------------------------------------------------------
//  Settings (cached per request)
// ---------------------------------------------------------------------

/** Load all settings as key => value (decoding JSON and casting types). */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    foreach (db_all('SELECT setting_key, value, type FROM settings') as $row) {
        $cache[$row['setting_key']] = cast_setting($row['value'], $row['type']);
    }
    return $cache;
}

function cast_setting(?string $value, string $type): mixed
{
    return match ($type) {
        'boolean' => $value === '1' || $value === 'true',
        'number'  => is_numeric($value) ? $value + 0 : 0,
        'json'    => json_decode((string) $value, true) ?? [],
        default   => $value ?? '',
    };
}

/** Get one setting. Env vars override DB for Razorpay secrets. */
function setting(string $key, mixed $default = null): mixed
{
    $envOverrides = [
        'razorpay_key_id'         => 'RAZORPAY_KEY_ID',
        'razorpay_key_secret'     => 'RAZORPAY_KEY_SECRET',
        'razorpay_webhook_secret' => 'RAZORPAY_WEBHOOK_SECRET',
    ];
    if (isset($envOverrides[$key]) && ($envValue = env($envOverrides[$key])) !== null && $envValue !== '') {
        return $envValue;
    }
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

/** Settings safe for the public API (is_public = 1, secrets never included). */
function public_settings(): array
{
    $out = [];
    foreach (db_all("SELECT setting_key, value, type FROM settings WHERE is_public = 1 AND type <> 'secret'") as $row) {
        $value = cast_setting($row['value'], $row['type']);
        if ($row['type'] === 'image' && $value) {
            $value = upload_url((string) $value);
        }
        $out[$row['setting_key']] = $value;
    }
    $out['razorpay_key_id'] = setting('razorpay_key_id');
    $out['copyright_text']  = str_replace('{year}', date('Y'), (string) ($out['copyright_text'] ?? ''));
    return $out;
}

function save_setting(string $key, mixed $value): void
{
    if (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    db_query('UPDATE settings SET value = ? WHERE setting_key = ?', [(string) $value, $key]);
    settings_all(true);
}

// ---------------------------------------------------------------------
//  URLs & request info
// ---------------------------------------------------------------------

function base_url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return base_url('admin/' . ltrim($path, '/'));
}

/** Absolute URL for a stored upload path like "uploads/menu/x.jpg". */
function upload_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return base_url(ltrim($path, '/'));
}

function redirect(string $url, int $code = 302): never
{
    header('Location: ' . $url, true, $code);
    exit;
}

function client_ip(): string
{
    // Only REMOTE_ADDR is trustworthy unless you sit behind a known proxy.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function is_post(): bool
{
    return request_method() === 'POST';
}

/** Read a value from GET/POST with a default. */
function input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

// ---------------------------------------------------------------------
//  Formatting
// ---------------------------------------------------------------------

/** ₹1,234.50 — Indian digit grouping. */
function money(float|int|string|null $amount, bool $symbol = true): string
{
    $amount   = (float) ($amount ?? 0);
    $negative = $amount < 0;
    [$int, $dec] = explode('.', number_format(abs($amount), 2, '.', ''));

    $last3 = substr($int, -3);
    $rest  = substr($int, 0, -3);
    if ($rest !== '' && $rest !== false) {
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int   = $rest . ',' . $last3;
    }
    $out = $int . '.' . $dec;
    return ($negative ? '-' : '') . ($symbol ? '₹' : '') . $out;
}

function format_date(?string $date, string $format = 'd M Y'): string
{
    return $date ? date($format, strtotime($date)) : '—';
}

function format_datetime(?string $date): string
{
    return format_date($date, 'd M Y, h:i A');
}

function time_ago(?string $date): string
{
    if (!$date) {
        return '—';
    }
    $diff = time() - strtotime($date);
    return match (true) {
        $diff < 60     => 'just now',
        $diff < 3600   => floor($diff / 60) . ' min ago',
        $diff < 86400  => floor($diff / 3600) . ' hr ago',
        $diff < 604800 => floor($diff / 86400) . ' days ago',
        default        => format_date($date),
    };
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

/** Slug guaranteed unique within a table column (appends -2, -3…). */
function unique_slug(string $table, string $text, ?int $ignoreId = null, string $column = 'slug'): string
{
    assert_identifier($table);
    assert_identifier($column);
    $base = slugify($text);
    $slug = $base;
    $i    = 2;
    while (true) {
        $sql    = "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?" . ($ignoreId ? ' AND id <> ?' : '');
        $params = $ignoreId ? [$slug, $ignoreId] : [$slug];
        if ((int) db_value($sql, $params) === 0) {
            return $slug;
        }
        $slug = $base . '-' . $i++;
    }
}

/**
 * Generate sequential human-readable numbers: VC-20261003-0007, RS-20261003-0002.
 * Uses the row count for today; the UNIQUE index is the final safety net.
 */
function generate_reference(string $prefix, string $table, string $column): string
{
    assert_identifier($table);
    assert_identifier($column);
    $datePart = date('Ymd');
    $like     = "$prefix-$datePart-%";
    $last     = db_value("SELECT `$column` FROM `$table` WHERE `$column` LIKE ? ORDER BY `$column` DESC LIMIT 1", [$like]);
    $next     = $last ? ((int) substr((string) $last, -4)) + 1 : 1;
    return sprintf('%s-%s-%04d', $prefix, $datePart, $next);
}

function generate_order_number(): string
{
    return generate_reference('VC', 'orders', 'order_number');
}

function generate_reservation_reference(): string
{
    return generate_reference('RS', 'reservations', 'reference');
}

function order_status_label(string $status): string
{
    return ORDER_STATUSES[$status] ?? ucwords(str_replace('_', ' ', $status));
}

/** Bootstrap badge colour for a status. */
function status_badge_class(string $status): string
{
    return match ($status) {
        'pending', 'created'                => 'bg-warning text-dark',
        'confirmed', 'reserved'             => 'bg-info text-dark',
        'preparing'                         => 'bg-primary',
        'ready', 'out_for_delivery'         => 'bg-secondary',
        'served', 'occupied'                => 'bg-dark',
        'completed', 'paid', 'available'    => 'bg-success',
        'cancelled', 'failed', 'no_show'    => 'bg-danger',
        'refunded'                          => 'bg-light text-dark border',
        default                             => 'bg-light text-dark',
    };
}

// ---------------------------------------------------------------------
//  Flash messages (admin)
// ---------------------------------------------------------------------

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Return and clear flash messages. */
function get_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/** Remember submitted form values to refill a form after a validation error. */
function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function set_old_input(array $data): void
{
    unset($data['password'], $data['password_confirmation'], $data['_csrf']);
    $_SESSION['_old'] = $data;
}

function clear_old_input(): void
{
    unset($_SESSION['_old']);
}
