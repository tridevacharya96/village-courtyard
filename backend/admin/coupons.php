<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** Coupons & offers: list with live state, usage and discount given. */
require_permission('coupons.manage');
$now = date('Y-m-d H:i:s');
$coupons = db_all(
    "SELECT c.*, (SELECT COALESCE(SUM(o.discount), 0) FROM orders o WHERE o.coupon_id = c.id AND o.status <> 'cancelled') AS discount_given
       FROM coupons c ORDER BY c.is_active DESC, c.valid_until IS NULL, c.valid_until DESC"
);

/** What a guest would experience right now. */
function coupon_state(array $c, string $now): array
{
    return match (true) {
        !(int) $c['is_active']                                        => ['Off', 'text-bg-secondary', 'bi-pause-circle'],
        $c['valid_from'] && $now < $c['valid_from']                   => ['Starts ' . date('j M', strtotime($c['valid_from'])), 'text-bg-info', 'bi-hourglass'],
        $c['valid_until'] && $now > $c['valid_until']                 => ['Expired', 'text-bg-light border', 'bi-calendar-x'],
        $c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit'] => ['Used up', 'text-bg-warning', 'bi-slash-circle'],
        default                                                       => ['Live', 'text-bg-success', 'bi-check-circle'],
    };
}

admin_header('Coupons', 'coupons.php');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0">Guests enter these codes in the cart. Discounts are checked again on the server when the order is placed.</p>
  <a class="btn btn-primary btn-sm" href="coupon-edit.php"><i class="bi bi-plus-lg me-1"></i>New coupon</a>
</div>
<section class="panel">
  <?php if (!$coupons): ?><div class="empty-state"><i class="bi bi-ticket-perforated"></i>No coupons yet.</div><?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Code</th><th>Discount</th><th>Minimum order</th><th>Valid</th><th class="text-end">Used</th><th class="text-end">Discount given</th><th>State</th><th class="text-center">On</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($coupons as $c): [$label, $cls, $icon] = coupon_state($c, $now); ?>
        <tr data-row>
          <td><a class="fw-bold font-monospace" href="coupon-edit.php?id=<?= (int) $c['id'] ?>"><?= e($c['code']) ?></a><small class="d-block text-muted"><?= e($c['description']) ?></small></td>
          <td class="tabular"><?= $c['discount_type'] === 'percent' ? rtrim(rtrim(number_format((float) $c['discount_value'], 2), '0'), '.') . '% off' : money($c['discount_value']) . ' off' ?>
            <?php if ($c['max_discount'] !== null): ?><small class="d-block text-muted">up to <?= money($c['max_discount']) ?></small><?php endif; ?></td>
          <td class="tabular"><?= (float) $c['min_order_amount'] > 0 ? money($c['min_order_amount']) : '—' ?></td>
          <td class="small text-nowrap"><?= $c['valid_from'] ? e(date('j M Y', strtotime($c['valid_from']))) : 'Any time' ?> → <?= $c['valid_until'] ? e(date('j M Y', strtotime($c['valid_until']))) : 'no end' ?></td>
          <td class="text-end tabular"><?= (int) $c['used_count'] ?><?= $c['usage_limit'] !== null ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
          <td class="text-end tabular"><?= money($c['discount_given']) ?></td>
          <td><span class="badge <?= $cls ?>"><i class="bi <?= $icon ?> me-1"></i><?= e($label) ?></span></td>
          <td class="text-center"><?= row_toggle('coupons', (int) $c['id'], 'is_active', (bool) $c['is_active'], 'Coupon switched on') ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="coupon-edit.php?id=<?= (int) $c['id'] ?>">Edit</a>
            <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="coupons" data-id="<?= (int) $c['id'] ?>" data-name="<?= e($c['code']) ?>" data-body="Coupons already used on orders can only be switched off." aria-label="Delete <?= e($c['code']) ?>"><i class="bi bi-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_footer(['js/crud.js']);
