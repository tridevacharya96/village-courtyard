<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Payments: every online and cash transaction, refunds through Razorpay,
 * and the list of cancelled online orders that still need refunding.
 * Gateway keys are configured in Settings → Payments.
 */
require_permission('payments.view');
$canRefund = can('payments.refund') && razorpay_is_configured();
const PAY_PER_PAGE = 25;

$filter = (string) ($_GET['filter'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$q      = clean_text($_GET['q'] ?? '', 60);
$days   = (int) ($_GET['days'] ?? 30) ?: 30;

$where = ['p.created_at >= CURDATE() - INTERVAL ? DAY'];
$params = [$days - 1];
if ($filter === 'refund_needed') {
    $where = ["o.status = 'cancelled' AND o.payment_method = 'razorpay' AND o.payment_status = 'paid' AND p.status = 'paid'"];
    $params = [];
}
if (in_array($method, ['razorpay', 'cod'], true)) { $where[] = 'p.method = ?'; $params[] = $method; }
if (in_array($status, ['created', 'paid', 'failed', 'refunded'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(o.order_number LIKE ? OR o.customer_name LIKE ? OR p.razorpay_payment_id LIKE ? OR p.razorpay_order_id LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
$w = implode(' AND ', $where);

$sum = db_one(
    "SELECT COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') THEN p.amount END), 0) AS collected,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.method = 'razorpay' THEN p.amount END), 0) AS online,
            COALESCE(SUM(CASE WHEN p.status IN ('paid','refunded') AND p.method = 'cod' THEN p.amount END), 0) AS cash,
            COALESCE(SUM(p.refund_amount), 0) AS refunded,
            SUM(p.status = 'failed') AS failed
       FROM payments p JOIN orders o ON o.id = p.order_id WHERE $w",
    $params
);
$refundNeeded = (int) db_value("SELECT COUNT(*) FROM orders WHERE status = 'cancelled' AND payment_method = 'razorpay' AND payment_status = 'paid'");

$total = (int) db_value("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE $w", $params);
$page  = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / PAY_PER_PAGE))));
$rows  = db_all(
    "SELECT p.*, o.order_number, o.customer_name, o.status AS order_status, o.total AS order_total, u.name AS marked_name
       FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.marked_by
      WHERE $w ORDER BY p.id DESC LIMIT " . PAY_PER_PAGE . ' OFFSET ' . (($page - 1) * PAY_PER_PAGE),
    $params
);

admin_header('Payments', 'payments.php');
?>
<?php if (!razorpay_is_configured()): ?>
  <div class="alert alert-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Online payments are off: Razorpay keys are missing.
    <?php if (can('payments.config')): ?><a href="settings.php?tab=payment">Add them in Settings</a>.<?php endif; ?></div>
<?php endif; ?>

<section class="kpis" aria-label="Payment summary">
  <div class="kpi"><span class="eyebrow">Collected<?= $filter ? '' : ", last $days days" ?></span><span class="kpi-value"><?= money($sum['collected']) ?></span><span class="kpi-sub">Online <?= money($sum['online']) ?> · Cash <?= money($sum['cash']) ?></span></div>
  <div class="kpi"><span class="eyebrow">Refunded</span><span class="kpi-value"><?= money($sum['refunded']) ?></span><span class="kpi-sub"><?= (int) $sum['failed'] ?> failed payment attempts</span></div>
  <a class="kpi<?= $refundNeeded ? ' attention' : '' ?>" href="payments.php?filter=refund_needed"><span class="eyebrow">Refunds to issue</span><span class="kpi-value"><?= $refundNeeded ?></span><span class="kpi-sub">Cancelled orders that were paid online</span></a>
</section>

<section class="panel">
  <form class="panel-body filter-bar" method="get" role="search">
    <div><label class="form-label small mb-1" for="q">Search</label><input class="form-control form-control-sm" id="q" name="q" type="search" value="<?= e($q) ?>" placeholder="Order no., name or Razorpay ID"></div>
    <div><label class="form-label small mb-1" for="days">Period</label>
      <select class="form-select form-select-sm" id="days" name="days"><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $k => $l): ?><option value="<?= $k ?>"<?= $days === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-1" for="method">Method</label>
      <select class="form-select form-select-sm" id="method" name="method"><?php foreach (['' => 'All', 'razorpay' => 'Online (Razorpay)', 'cod' => 'Cash'] as $k => $l): ?><option value="<?= $k ?>"<?= $method === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label small mb-1" for="status">Status</label>
      <select class="form-select form-select-sm" id="status" name="status"><?php foreach (['' => 'All', 'paid' => 'Paid', 'created' => 'Started, not paid', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="actions"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="payments.php">Reset</a></div>
  </form>
  <?php if ($filter === 'refund_needed'): ?><div class="panel-foot"><span><b>Showing only cancelled orders that still need a refund.</b></span><a href="payments.php">Show all payments</a></div><?php endif; ?>
</section>

<section class="panel">
  <?php if (!$rows): ?>
    <div class="empty-state"><i class="bi bi-credit-card"></i><?= $filter === 'refund_needed' ? 'No refunds waiting. Nice.' : 'No payments in this period.' ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Date</th><th>Order</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $p):
          $refundable = $canRefund && $p['method'] === 'razorpay' && $p['status'] === 'paid' && $p['razorpay_payment_id']; ?>
        <tr data-id="<?= (int) $p['id'] ?>">
          <td class="small text-nowrap"><?= e(format_datetime($p['created_at'])) ?></td>
          <td><a class="order-no" href="order.php?id=<?= (int) $p['order_id'] ?>"><?= e($p['order_number']) ?></a><small class="d-block text-muted"><?= e($p['customer_name']) ?> · order <?= e(strtolower(order_status_label($p['order_status']))) ?></small></td>
          <td><?= status_badge($p['method']) ?></td>
          <td class="small font-monospace text-break" style="max-width:14rem"><?= e($p['razorpay_payment_id'] ?: ($p['razorpay_order_id'] ?: ($p['marked_name'] ? 'Cash taken by ' . $p['marked_name'] : '—'))) ?>
            <?php if ($p['refund_id']): ?><span class="d-block text-muted">Refund <?= e($p['refund_id']) ?></span><?php endif; ?></td>
          <td class="text-end tabular fw-bold"><?= money($p['amount']) ?>
            <?php if ((float) $p['refund_amount'] > 0): ?><small class="d-block text-muted fw-normal">−<?= money($p['refund_amount']) ?> refunded</small><?php endif; ?></td>
          <td><?= status_badge($p['status'], ['created' => 'Not paid', 'paid' => 'Paid', 'failed' => 'Failed', 'refunded' => (float) $p['refund_amount'] < (float) $p['amount'] ? 'Part refunded' : 'Refunded'][$p['status']]) ?></td>
          <td class="text-end">
            <?php if ($refundable): ?>
              <button class="btn btn-sm <?= $p['order_status'] === 'cancelled' ? 'btn-danger' : 'btn-outline-danger' ?> js-refund" type="button"
                      data-id="<?= (int) $p['id'] ?>" data-amount="<?= e(number_format((float) $p['amount'], 2, '.', '')) ?>" data-order="<?= e($p['order_number']) ?>">Refund</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="panel-foot"><span><?= e(pagination_summary($total, $page, PAY_PER_PAGE)) ?></span><?= render_pagination($total, $page, PAY_PER_PAGE) ?></div>
</section>

<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundTitle" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" id="refundForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-4" id="refundTitle">Refund</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <input type="hidden" name="id" id="r_id">
      <div class="mb-3"><label class="form-label" for="r_amount">Amount to refund</label>
        <div class="input-group"><span class="input-group-text">₹</span><input class="form-control" type="number" step="0.01" min="1" id="r_amount" name="amount" required></div>
        <div class="form-text" id="r_help"></div></div>
      <div class="mb-0"><label class="form-label" for="r_reason">Reason <span class="text-muted">(sent to Razorpay, kept in the log)</span></label>
        <input class="form-control" id="r_reason" name="reason" maxlength="200" placeholder="Order cancelled: item out of stock"></div>
      <p class="small text-muted mt-3 mb-0">Razorpay returns the money to the customer's card, UPI or bank, usually within 5–7 working days. This cannot be undone.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit">Issue refund</button></div>
  </form></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = new bootstrap.Modal(document.getElementById('refundModal'));
  document.addEventListener('click', (e) => {
    const b = e.target.closest('.js-refund');
    if (!b) return;
    document.getElementById('r_id').value = b.dataset.id;
    document.getElementById('r_amount').value = b.dataset.amount;
    document.getElementById('r_amount').max = b.dataset.amount;
    document.getElementById('r_help').textContent = `Up to ${VC.money(b.dataset.amount)} (the full payment for ${b.dataset.order}).`;
    document.getElementById('refundTitle').textContent = `Refund ${b.dataset.order}`;
    modal.show();
  });
  document.getElementById('refundForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = e.submitter; btn.disabled = true;
    VC.post('ajax/payment-action.php', $(e.target).serialize() + '&action=refund')
      .then((res) => { VC.toast(res.message); modal.hide(); setTimeout(() => location.reload(), 700); })
      .catch(() => { btn.disabled = false; });
  });
});
</script>
<?php admin_footer();
