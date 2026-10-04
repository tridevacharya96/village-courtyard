<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/reports.php';

/**
 * CSV export: ?report=summary|revenue|items|orders&from=&to=&group=
 * Opens cleanly in Excel (UTF-8 BOM, formula-injection safe).
 */
$user = require_permission('analytics.export');
[$from, $to, , $group] = report_range();
$report = (string) ($_GET['report'] ?? 'summary');

/** Prefix values Excel would treat as formulas. */
function csv_safe(mixed $v): mixed
{
    return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($v) ? "'" . $v : $v;
}

$rows = match ($report) {
    'revenue' => array_merge(
        [['Period', 'Orders', 'Revenue (INR)', 'Page views', 'Unique visitors']],
        array_map(static fn ($r) => [$r['label'], $r['orders'], number_format($r['revenue'], 2, '.', ''), $r['views'], $r['visitors']], report_series($from, $to, $group))
    ),
    'items' => array_merge(
        [['Dish', 'Quantity sold', 'Orders', 'Revenue before tax (INR)']],
        array_map(static fn ($r) => [$r['name'], (int) $r['qty'], (int) $r['orders'], number_format((float) $r['revenue'], 2, '.', '')], report_top_items($from, $to, 0))
    ),
    'orders' => array_merge(
        [['Order no.', 'Placed at', 'Customer', 'Phone', 'Type', 'Table', 'Status', 'Payment method', 'Payment status', 'Subtotal', 'Discount', 'Coupon', 'GST', 'Delivery', 'Total']],
        array_map(static fn ($o) => [
            $o['order_number'], $o['created_at'], $o['customer_name'], $o['customer_phone'], order_type_label($o['order_type']), $o['table_number'] ?? '',
            order_status_label($o['status']), $o['payment_method'] === 'cod' ? 'Cash' : 'Online', ucfirst($o['payment_status']),
            $o['subtotal'], $o['discount'], $o['coupon_code'] ?? '', $o['tax_amount'], $o['delivery_charge'], $o['total'],
        ], db_all(
            'SELECT o.*, t.table_number, c.code AS coupon_code FROM orders o
               LEFT JOIN restaurant_tables t ON t.id = o.table_id LEFT JOIN coupons c ON c.id = o.coupon_id
              WHERE o.created_at BETWEEN ? AND ? AND ' . ORDER_VISIBLE_SQL . ' ORDER BY o.created_at',
            ["$from 00:00:00", "$to 23:59:59"]
        ))
    ),
    default => (static function () use ($from, $to) {
        $s = report_summary($from, $to);
        return [
            ['Metric', 'Value'],
            ['Period', "$from to $to"],
            ['Revenue (INR, paid, incl. GST)', number_format($s['revenue'], 2, '.', '')],
            ['GST collected (INR)', number_format($s['tax'], 2, '.', '')],
            ['Discounts given (INR)', number_format($s['discounts'], 2, '.', '')],
            ['Orders (excl. cancelled)', $s['orders']],
            ['Paid orders', $s['paid_orders']],
            ['Average order value (INR)', number_format($s['aov'], 2, '.', '')],
            ['Cancelled orders', $s['cancelled']],
            ['Cancellation rate (%)', $s['cancel_rate']],
            ['Unique visitors', $s['visitors']],
            ['Page views', $s['views']],
            ['Visitors who ordered (%)', $s['conversion']],
        ];
    })(),
};
$report = in_array($report, ['revenue', 'items', 'orders'], true) ? $report : 'summary';

log_activity('export', 'analytics', null, "Exported $report report ($from to $to)", (int) $user['id']);

$filename = sprintf('village-courtyard-%s-%s-to-%s.csv', $report, $from, $to);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");                 // BOM so Excel reads ₹ and Odia names correctly
foreach ($rows as $row) {
    fputcsv($out, array_map('csv_safe', $row), ',', '"', '');
}
fclose($out);
