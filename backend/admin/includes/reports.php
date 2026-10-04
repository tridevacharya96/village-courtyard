<?php
/**
 * Report queries shared by analytics.php and analytics-export.php.
 * All order figures exclude unpaid online checkouts (ORDER_VISIBLE_SQL);
 * revenue counts only paid, non-cancelled orders (ORDER_REVENUE_SQL).
 */

declare(strict_types=1);

/** Resolve the date range from the query string. Returns [from, to, preset, group]. */
function report_range(): array
{
    $preset = (string) ($_GET['range'] ?? '30d');
    $today  = date('Y-m-d');
    [$from, $to] = match ($preset) {
        'today'      => [$today, $today],
        '7d'         => [date('Y-m-d', strtotime('-6 days')), $today],
        '90d'        => [date('Y-m-d', strtotime('-89 days')), $today],
        'this_month' => [date('Y-m-01'), $today],
        'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'this_year'  => [date('Y-01-01'), $today],
        'custom'     => [(string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? '')],
        default      => [date('Y-m-d', strtotime('-29 days')), $today],
    };
    $valid = static fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$valid($from) || !$valid($to)) {
        [$from, $to, $preset] = [date('Y-m-d', strtotime('-29 days')), $today, '30d'];
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $days  = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    $group = (string) ($_GET['group'] ?? '');
    if (!in_array($group, ['day', 'week', 'month'], true)) {
        $group = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    }
    return [$from, $to, $preset, $group];
}

/** SQL expression and PHP label/key functions for a grouping. */
function bucket_sql(string $group, string $column): string
{
    return match ($group) {
        'week'  => "DATE_FORMAT(DATE_SUB(DATE($column), INTERVAL WEEKDAY($column) DAY), '%Y-%m-%d')",   // Monday of the week
        'month' => "DATE_FORMAT($column, '%Y-%m-01')",
        default => "DATE_FORMAT($column, '%Y-%m-%d')",
    };
}

/** Every bucket key between from and to, so missing days show as zero. */
function bucket_keys(string $from, string $to, string $group): array
{
    $keys = [];
    $t = strtotime($from);
    if ($group === 'week') {
        $t = strtotime('monday this week', $t);
    } elseif ($group === 'month') {
        $t = strtotime(date('Y-m-01', $t));
    }
    $end = strtotime($to);
    while ($t <= $end) {
        $keys[] = date('Y-m-d', $t);
        $t = strtotime($group === 'month' ? '+1 month' : ($group === 'week' ? '+1 week' : '+1 day'), $t);
    }
    return $keys;
}

function bucket_label(string $key, string $group): string
{
    $t = strtotime($key);
    return match ($group) {
        'week'  => 'Wk of ' . date('j M', $t),
        'month' => date('M Y', $t),
        default => date('j M', $t),
    };
}

function report_where(string $from, string $to): array
{
    return ['o.created_at BETWEEN ? AND ? AND ' . ORDER_VISIBLE_SQL, ["$from 00:00:00", "$to 23:59:59"]];
}

function report_summary(string $from, string $to): array
{
    [$w, $p] = report_where($from, $to);
    $r = db_one(
        "SELECT COUNT(*) AS all_orders,
                SUM(o.status <> 'cancelled') AS orders,
                SUM(o.status = 'cancelled') AS cancelled,
                COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.total END), 0) AS revenue,
                SUM(" . ORDER_REVENUE_SQL . ") AS paid_orders,
                COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.discount END), 0) AS discounts,
                COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.tax_amount END), 0) AS tax
           FROM orders o WHERE $w",
        $p
    );
    $v = db_one('SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors FROM page_views WHERE viewed_at BETWEEN ? AND ?', $p);

    $revenue = (float) $r['revenue'];
    $paid    = (int) $r['paid_orders'];
    return [
        'revenue'     => $revenue,
        'orders'      => (int) $r['orders'],
        'paid_orders' => $paid,
        'aov'         => $paid ? round($revenue / $paid, 2) : 0.0,
        'cancelled'   => (int) $r['cancelled'],
        'cancel_rate' => (int) $r['all_orders'] ? round((int) $r['cancelled'] / (int) $r['all_orders'] * 100, 1) : 0.0,
        'discounts'   => (float) $r['discounts'],
        'tax'         => (float) $r['tax'],
        'views'       => (int) $v['views'],
        'visitors'    => (int) $v['visitors'],
        'conversion'  => (int) $v['visitors'] ? round((int) $r['orders'] / (int) $v['visitors'] * 100, 1) : 0.0,
    ];
}

/** The same-length period immediately before [from, to]. */
function previous_period(string $from, string $to): array
{
    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    return [date('Y-m-d', strtotime("$from -$days days")), date('Y-m-d', strtotime("$from -1 day"))];
}

function report_series(string $from, string $to, string $group): array
{
    [$w, $p] = report_where($from, $to);
    $b = bucket_sql($group, 'o.created_at');
    $rows = db_all(
        "SELECT $b AS k, SUM(o.status <> 'cancelled') AS orders,
                COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.total END), 0) AS revenue
           FROM orders o WHERE $w GROUP BY k",
        $p
    );
    $byKey = array_column($rows, null, 'k');

    $vb = bucket_sql($group, 'viewed_at');
    $vrows = db_all("SELECT $vb AS k, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY k", $p);
    $vByKey = array_column($vrows, null, 'k');

    $out = [];
    foreach (bucket_keys($from, $to, $group) as $k) {
        $out[] = [
            'key'      => $k,
            'label'    => bucket_label($k, $group),
            'orders'   => (int) ($byKey[$k]['orders'] ?? 0),
            'revenue'  => (float) ($byKey[$k]['revenue'] ?? 0),
            'views'    => (int) ($vByKey[$k]['views'] ?? 0),
            'visitors' => (int) ($vByKey[$k]['visitors'] ?? 0),
        ];
    }
    return $out;
}

function report_breakdown(string $from, string $to, string $column): array
{
    assert_identifier($column);
    [$w, $p] = report_where($from, $to);
    return db_all(
        "SELECT o.$column AS k, COUNT(*) AS orders,
                COALESCE(SUM(CASE WHEN " . ORDER_REVENUE_SQL . " THEN o.total END), 0) AS revenue
           FROM orders o WHERE $w AND o.status <> 'cancelled' GROUP BY o.$column ORDER BY orders DESC",
        $p
    );
}

function report_status_counts(string $from, string $to): array
{
    [$w, $p] = report_where($from, $to);
    $counts = array_fill_keys(array_keys(ORDER_STATUSES), 0);
    foreach (db_all("SELECT o.status, COUNT(*) c FROM orders o WHERE $w GROUP BY o.status", $p) as $r) {
        $counts[$r['status']] = (int) $r['c'];
    }
    return $counts;
}

function report_top_items(string $from, string $to, int $limit = 10): array
{
    [$w, $p] = report_where($from, $to);
    return db_all(
        "SELECT oi.item_name AS name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue, COUNT(DISTINCT o.id) AS orders
           FROM order_items oi JOIN orders o ON o.id = oi.order_id
          WHERE $w AND o.status <> 'cancelled'
          GROUP BY oi.item_name ORDER BY qty DESC, revenue DESC" . ($limit ? " LIMIT $limit" : ''),
        $p
    );
}

function report_top_categories(string $from, string $to): array
{
    [$w, $p] = report_where($from, $to);
    return db_all(
        "SELECT COALESCE(c.name, 'Removed dishes') AS name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
           FROM order_items oi JOIN orders o ON o.id = oi.order_id
           LEFT JOIN menu_items m ON m.id = oi.menu_item_id LEFT JOIN categories c ON c.id = m.category_id
          WHERE $w AND o.status <> 'cancelled'
          GROUP BY name ORDER BY revenue DESC",
        $p
    );
}

function report_hours(string $from, string $to): array
{
    [$w, $p] = report_where($from, $to);
    $hours = array_fill(0, 24, 0);
    foreach (db_all("SELECT HOUR(o.created_at) h, COUNT(*) c FROM orders o WHERE $w AND o.status <> 'cancelled' GROUP BY h", $p) as $r) {
        $hours[(int) $r['h']] = (int) $r['c'];
    }
    return $hours;
}

function report_top_pages(string $from, string $to, int $limit = 8): array
{
    return db_all(
        "SELECT path, COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors
           FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY path ORDER BY views DESC LIMIT $limit",
        ["$from 00:00:00", "$to 23:59:59"]
    );
}

function report_devices(string $from, string $to): array
{
    return db_all(
        'SELECT device AS k, COUNT(DISTINCT visitor_id) AS visitors FROM page_views WHERE viewed_at BETWEEN ? AND ? GROUP BY device ORDER BY visitors DESC',
        ["$from 00:00:00", "$to 23:59:59"]
    );
}

function pct_change(float $now, float $before): ?float
{
    return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
}
