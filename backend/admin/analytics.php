<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/reports.php';

/**
 * Analytics: revenue, orders, menu performance, peak hours and website visitors
 * for a chosen date range, with CSV export.
 */
require_permission('analytics.view');

[$from, $to, $preset, $group] = report_range();
[$pFrom, $pTo] = previous_period($from, $to);

$sum      = report_summary($from, $to);
$prev     = report_summary($pFrom, $pTo);
$series   = report_series($from, $to, $group);
$statuses = report_status_counts($from, $to);
$types    = report_breakdown($from, $to, 'order_type');
$methods  = report_breakdown($from, $to, 'payment_method');
$items    = report_top_items($from, $to, 10);
$cats     = report_top_categories($from, $to);
$hours    = report_hours($from, $to);
$pages    = report_top_pages($from, $to);
$devices  = report_devices($from, $to);

$rangeLabel = $from === $to ? date('j M Y', strtotime($from)) : date('j M', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
$prevLabel  = date('j M', strtotime($pFrom)) . ' – ' . date('j M', strtotime($pTo));
$peakHour   = max($hours) > 0 ? array_search(max($hours), $hours, true) : null;
$hourLabel  = static fn (int $h) => date('g A', mktime($h, 0));

/** "▲ 12.5% vs previous period" with direction shown by icon and words, not colour alone. */
function delta_html(?float $pct, bool $higherIsBetter = true): string
{
    if ($pct === null) {
        return '<span class="kpi-sub">No data for the previous period</span>';
    }
    if ($pct == 0.0) {
        return '<span class="kpi-sub">Same as previous period</span>';
    }
    $up   = $pct > 0;
    $good = $up === $higherIsBetter;
    return '<span class="kpi-sub"><span class="' . ($good ? 'text-success' : 'text-danger') . ' fw-bold"><i class="bi bi-arrow-' . ($up ? 'up' : 'down') . '-right"></i> '
        . ($up ? '+' : '') . number_format($pct, 1) . '%</span> vs previous period</span>';
}

/** Part-to-whole bar with a full legend (labels + values + share), for 2–3 parts. */
function split_html(array $parts, string $unit = ''): string
{
    $total = array_sum(array_column($parts, 'value'));
    if ($total <= 0) {
        return '<p class="text-muted mb-0">No data in this period.</p>';
    }
    $colors = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)'];
    $bar = $legend = '';
    foreach (array_values($parts) as $i => $p) {
        $share = $p['value'] / $total * 100;
        $c = $colors[$i] ?? 'var(--vc-muted)';
        $bar .= '<span style="width:' . round($share, 2) . '%;background:' . $c . '" title="' . e($p['label']) . ': ' . e((string) $p['value']) . '"></span>';
        $legend .= '<div><span class="swatch" style="background:' . $c . '"></span>' . e($p['label'])
            . '<b>' . e($p['display'] ?? number_format($p['value']) . $unit) . '</b><small>' . number_format($share, 0) . '%</small></div>';
    }
    return '<div class="split-bar" role="img" aria-label="Share by category">' . $bar . '</div><div class="split-legend">' . $legend . '</div>';
}

/** Ranked list with inline bars scaled to the top value. */
function rank_html(array $rows, string $valueKey, callable $fmt, ?callable $sub = null, bool $numbered = true): string
{
    if (!$rows) {
        return '<p class="text-muted mb-0">No data in this period.</p>';
    }
    $max = max(array_map(static fn ($r) => (float) $r[$valueKey], $rows)) ?: 1;
    $html = '<ol class="rank-list">';
    foreach (array_values($rows) as $i => $r) {
        $w = round((float) $r[$valueKey] / $max * 100, 1);
        $html .= '<li><span class="rank">' . ($numbered ? $i + 1 : '') . '</span><span class="name" title="' . e($r['name']) . '">' . e($r['name'])
            . '<span class="bar" style="width:' . $w . '%"></span></span><span class="val">' . $fmt($r) . ($sub ? '<small>' . $sub($r) . '</small>' : '') . '</span></li>';
    }
    return $html . '</ol>';
}

$typeNames   = ['dine_in' => 'Dine-in', 'delivery' => 'Delivery', 'takeaway' => 'Takeaway'];
$deviceNames = ['mobile' => 'Mobile', 'desktop' => 'Desktop', 'tablet' => 'Tablet', 'other' => 'Other'];

$chartData = [
    'labels'   => array_column($series, 'label'),
    'revenue'  => array_column($series, 'revenue'),
    'orders'   => array_column($series, 'orders'),
    'views'    => array_column($series, 'views'),
    'visitors' => array_column($series, 'visitors'),
    'hours'    => $hours,
    'hourLabels' => array_map($hourLabel, range(0, 23)),
];

admin_header('Analytics', 'analytics.php');
?>
<section class="panel">
  <form class="panel-body filter-bar" method="get" id="rangeForm">
    <div>
      <label class="form-label small mb-1" for="range">Period</label>
      <select class="form-select form-select-sm" id="range" name="range">
        <?php foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_year' => 'This year', 'custom' => 'Custom range'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $preset === $k ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="custom-range"<?= $preset === 'custom' ? '' : ' hidden' ?>>
      <label class="form-label small mb-1" for="from">From</label>
      <input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div class="custom-range"<?= $preset === 'custom' ? '' : ' hidden' ?>>
      <label class="form-label small mb-1" for="to">To</label>
      <input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <div>
      <label class="form-label small mb-1" for="group">Group by</label>
      <select class="form-select form-select-sm" id="group" name="group">
        <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $k => $l): ?>
          <option value="<?= $k ?>"<?= $group === $k ? ' selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="actions">
      <button class="btn btn-sm btn-primary" type="submit">Apply</button>
      <?php if (can('analytics.export')): ?>
        <div class="dropdown">
          <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download me-1"></i>Export CSV</button>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach (['summary' => 'Summary', 'revenue' => 'Revenue by ' . $group, 'items' => 'Dish sales', 'orders' => 'All orders (detailed)'] as $k => $l): ?>
              <li><a class="dropdown-item" href="analytics-export.php?<?= e(http_build_query(['report' => $k, 'range' => 'custom', 'from' => $from, 'to' => $to, 'group' => $group])) ?>"><?= e($l) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </form>
  <div class="panel-foot"><span><b><?= e($rangeLabel) ?></b> · compared with <?= e($prevLabel) ?></span><span>Revenue = paid orders incl. GST, excluding cancelled</span></div>
</section>

<section class="kpis" aria-label="Summary">
  <div class="kpi"><span class="eyebrow">Revenue</span><span class="kpi-value"><?= money($sum['revenue']) ?></span><?= delta_html(pct_change($sum['revenue'], $prev['revenue'])) ?></div>
  <div class="kpi"><span class="eyebrow">Orders</span><span class="kpi-value"><?= number_format($sum['orders']) ?></span><?= delta_html(pct_change($sum['orders'], $prev['orders'])) ?></div>
  <div class="kpi"><span class="eyebrow">Average order</span><span class="kpi-value"><?= money($sum['aov']) ?></span><?= delta_html(pct_change($sum['aov'], $prev['aov'])) ?></div>
  <div class="kpi"><span class="eyebrow">Cancelled</span><span class="kpi-value"><?= $sum['cancel_rate'] ?>%</span><span class="kpi-sub"><?= $sum['cancelled'] ?> orders · <?= $prev['cancel_rate'] ?>% previous period</span></div>
  <div class="kpi"><span class="eyebrow">Website visitors</span><span class="kpi-value"><?= number_format($sum['visitors']) ?></span><span class="kpi-sub"><?= number_format($sum['views']) ?> page views · <?= $sum['conversion'] ?>% ordered</span></div>
</section>

<div class="analytics-grid">
  <section class="panel span-12">
    <div class="panel-head">
      <h2>Revenue by <?= e($group) ?></h2>
      <div class="btn-group btn-group-sm" role="group" aria-label="Measure">
        <button type="button" class="btn btn-outline-primary active" data-measure="revenue" aria-pressed="true">Revenue</button>
        <button type="button" class="btn btn-outline-primary" data-measure="orders" aria-pressed="false">Orders</button>
      </div>
    </div>
    <div class="panel-body"><div class="chart-box tall"><canvas id="trendChart" role="img" aria-label="Revenue per <?= e($group) ?> for the selected period"></canvas></div></div>
  </section>

  <section class="panel span-4">
    <div class="panel-head"><h2>Order type</h2></div>
    <div class="panel-body"><?= split_html(array_map(static fn ($r) => ['label' => $typeNames[$r['k']] ?? $r['k'], 'value' => (int) $r['orders'], 'display' => $r['orders'] . ' · ' . money($r['revenue'])], $types)) ?></div>
  </section>
  <section class="panel span-4">
    <div class="panel-head"><h2>Payment method</h2></div>
    <div class="panel-body"><?= split_html(array_map(static fn ($r) => ['label' => $r['k'] === 'cod' ? 'Cash' : 'Online', 'value' => (int) $r['orders'], 'display' => $r['orders'] . ' · ' . money($r['revenue'])], $methods)) ?></div>
  </section>
  <section class="panel span-4">
    <div class="panel-head"><h2>Orders by status</h2></div>
    <div class="panel-body">
      <?= rank_html(
          array_map(static fn ($k, $c) => ['name' => order_status_label($k), 'c' => $c], array_keys(array_filter($statuses)), array_filter($statuses)),
          'c', static fn ($r) => number_format($r['c']), null, false
      ) ?>
    </div>
  </section>

  <section class="panel span-8">
    <div class="panel-head"><h2>Busiest hours</h2>
      <span class="small text-muted"><?= $peakHour !== null ? 'Peak: ' . e($hourLabel($peakHour)) . ' – ' . e($hourLabel(($peakHour + 1) % 24)) . ' (' . $hours[$peakHour] . ' orders)' : 'No orders yet' ?></span></div>
    <div class="panel-body"><div class="chart-box"><canvas id="hourChart" role="img" aria-label="Orders by hour of day"></canvas></div></div>
  </section>
  <section class="panel span-4">
    <div class="panel-head"><h2>Categories</h2><span class="small text-muted">by revenue</span></div>
    <div class="panel-body"><?= rank_html($cats, 'revenue', static fn ($r) => money($r['revenue']), static fn ($r) => number_format((int) $r['qty']) . ' sold') ?></div>
  </section>

  <section class="panel span-6">
    <div class="panel-head"><h2>Top dishes</h2><span class="small text-muted">by quantity sold</span></div>
    <div class="panel-body"><?= rank_html($items, 'qty', static fn ($r) => number_format((int) $r['qty']) . ' sold', static fn ($r) => money($r['revenue'])) ?></div>
  </section>
  <section class="panel span-6">
    <div class="panel-head"><h2>Website visitors</h2><span class="small text-muted">Ordered: <?= $sum['conversion'] ?>% of visitors</span></div>
    <div class="panel-body"><div class="chart-box short"><canvas id="visitChart" role="img" aria-label="Page views and unique visitors per <?= e($group) ?>"></canvas></div></div>
  </section>

  <section class="panel span-6">
    <div class="panel-head"><h2>Most visited pages</h2></div>
    <div class="panel-body"><?= rank_html(array_map(static fn ($r) => $r + ['name' => $r['path']], $pages), 'views', static fn ($r) => number_format((int) $r['views']) . ' views', static fn ($r) => number_format((int) $r['visitors']) . ' visitors') ?></div>
  </section>
  <section class="panel span-6">
    <div class="panel-head"><h2>Devices</h2><span class="small text-muted">unique visitors</span></div>
    <div class="panel-body"><?= split_html(array_map(static fn ($r) => ['label' => $deviceNames[$r['k']] ?? $r['k'], 'value' => (int) $r['visitors']], array_slice($devices, 0, 3))) ?></div>
  </section>
</div>

<script>window.ANALYTICS = <?= ejs($chartData) ?>;</script>
<?php admin_footer(['vendor/chartjs/chart.umd.min.js', 'js/charts.js', 'js/analytics.js']);
