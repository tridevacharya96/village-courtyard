<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Printable kitchen ticket (type=kot) or customer invoice (type=invoice),
 * sized for 80 mm thermal printers; also prints fine on A4.
 */
require_permission('orders.view');

$id    = (int) ($_GET['id'] ?? 0);
$type  = ($_GET['type'] ?? 'kot') === 'invoice' ? 'invoice' : 'kot';
$order = db_one('SELECT o.*, t.table_number, c.code AS coupon_code FROM orders o
                   LEFT JOIN restaurant_tables t ON t.id = o.table_id LEFT JOIN coupons c ON c.id = o.coupon_id
                  WHERE o.id = ?', [$id]);
if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}
$items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$id]);
$s = settings_all();
log_activity('print', 'order', $id, ($type === 'kot' ? 'Kitchen ticket' : 'Invoice') . " printed for {$order['order_number']}");
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $type === 'kot' ? 'KOT' : 'Invoice' ?> <?= e($order['order_number']) ?></title>
<style>
  @page { size: 80mm auto; margin: 4mm; }
  body { font: 13px/1.4 "Courier New", ui-monospace, monospace; color: #000; margin: 0 auto; width: 72mm; padding: 4mm 0; }
  h1 { font-size: 16px; text-align: center; margin: 0 0 2px; text-transform: uppercase; letter-spacing: 1px; }
  .center { text-align: center; }
  .big { font-size: 20px; font-weight: bold; }
  .muted { font-size: 11px; }
  hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; }
  td { vertical-align: top; padding: 2px 0; }
  .r { text-align: right; white-space: nowrap; }
  .qty { width: 2.6em; font-weight: bold; }
  .kot td { font-size: 15px; }
  .total td { font-weight: bold; font-size: 15px; border-top: 1px dashed #000; padding-top: 4px; }
  .note { border: 1px solid #000; padding: 4px; margin-top: 6px; font-weight: bold; }
  .actions { text-align: center; margin: 12px 0; }
  @media print { .actions { display: none; } }
</style>
</head>
<body>
<?php if ($type === 'kot'): ?>
  <h1>Kitchen ticket</h1>
  <div class="center big"><?= e($order['order_number']) ?></div>
  <div class="center"><b><?= e(strtoupper(order_type_label($order['order_type']))) ?><?= $order['table_number'] ? ' · TABLE ' . e($order['table_number']) : '' ?></b></div>
  <div class="center muted"><?= e(format_datetime($order['created_at'])) ?></div>
  <hr>
  <table class="kot">
    <?php foreach ($items as $it): ?>
      <tr><td class="qty"><?= (int) $it['quantity'] ?> ×</td><td><?= e($it['item_name']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($order['notes']): ?><div class="note">NOTE: <?= e($order['notes']) ?></div><?php endif; ?>
  <hr>
  <div class="muted">Customer: <?= e($order['customer_name']) ?></div>
<?php else: ?>
  <h1><?= e($s['site_name'] ?? APP_NAME) ?></h1>
  <div class="center muted"><?= nl2br(e($s['address'] ?? '')) ?><br><?= e($s['phone'] ?? '') ?></div>
  <hr>
  <div class="center"><b>TAX INVOICE</b></div>
  <table class="muted">
    <tr><td>Bill no.</td><td class="r"><?= e($order['order_number']) ?></td></tr>
    <tr><td>Date</td><td class="r"><?= e(format_datetime($order['created_at'])) ?></td></tr>
    <tr><td>Type</td><td class="r"><?= e(order_type_label($order['order_type'])) ?><?= $order['table_number'] ? ' · ' . e($order['table_number']) : '' ?></td></tr>
    <tr><td>Customer</td><td class="r"><?= e($order['customer_name']) ?></td></tr>
  </table>
  <hr>
  <table>
    <?php foreach ($items as $it): ?>
      <tr><td colspan="2"><?= e($it['item_name']) ?></td></tr>
      <tr><td class="muted">&nbsp;&nbsp;<?= (int) $it['quantity'] ?> × <?= money($it['unit_price'], false) ?></td><td class="r"><?= money($it['line_total'], false) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <hr>
  <table>
    <tr><td>Subtotal</td><td class="r"><?= money($order['subtotal'], false) ?></td></tr>
    <?php if ((float) $order['discount'] > 0): ?><tr><td>Discount<?= $order['coupon_code'] ? ' (' . e($order['coupon_code']) . ')' : '' ?></td><td class="r">-<?= money($order['discount'], false) ?></td></tr><?php endif; ?>
    <?php $half = round((float) $order['tax_amount'] / 2, 2); $rate = rtrim(rtrim(number_format((float) $order['tax_percent'] / 2, 2), '0'), '.'); ?>
    <tr><td>CGST <?= $rate ?>%</td><td class="r"><?= money($half, false) ?></td></tr>
    <tr><td>SGST <?= $rate ?>%</td><td class="r"><?= money((float) $order['tax_amount'] - $half, false) ?></td></tr>
    <?php if ((float) $order['delivery_charge'] > 0): ?><tr><td>Delivery</td><td class="r"><?= money($order['delivery_charge'], false) ?></td></tr><?php endif; ?>
    <tr class="total"><td>TOTAL ₹</td><td class="r"><?= money($order['total'], false) ?></td></tr>
  </table>
  <div class="muted" style="margin-top:6px">Paid by: <?= $order['payment_method'] === 'cod' ? 'Cash' : 'Online (Razorpay)' ?> · <?= e(ucfirst($order['payment_status'])) ?></div>
  <hr>
  <div class="center muted">Thank you for dining with us!</div>
<?php endif; ?>
<div class="actions"><button onclick="window.print()">Print</button> <button onclick="window.close()">Close</button></div>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
