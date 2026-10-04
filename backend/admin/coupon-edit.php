<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** Create or edit a coupon. */
$user   = require_permission('coupons.manage');
$id     = (int) ($_GET['id'] ?? 0);
$coupon = $id ? db_one('SELECT * FROM coupons WHERE id = ?', [$id]) : null;
if ($id && !$coupon) {
    redirect(admin_url('coupons.php'));
}
$coupon ??= ['code' => '', 'description' => '', 'discount_type' => 'percent', 'discount_value' => '', 'min_order_amount' => 0, 'max_discount' => null, 'usage_limit' => null, 'used_count' => 0, 'valid_from' => date('Y-m-d 00:00:00'), 'valid_until' => null, 'is_active' => 1];
$errors = [];
$dateOnly = static fn (?string $dt) => $dt ? substr($dt, 0, 10) : '';

if (is_post()) {
    verify_csrf();
    $_POST['code'] = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['code'] ?? '')));
    $v = Validator::make($_POST, [
        'code'             => 'required|string|min:3|max:30|unique:coupons,code' . ($id ? ",$id" : ''),
        'description'      => 'nullable|string|max:255',
        'discount_type'    => 'required|in:percent,flat',
        'discount_value'   => 'required|numeric|between:1,100000',
        'min_order_amount' => 'nullable|numeric|between:0,1000000',
        'max_discount'     => 'nullable|numeric|between:1,1000000',
        'usage_limit'      => 'nullable|integer|between:1,1000000',
        'valid_from'       => 'nullable|date',
        'valid_until'      => 'nullable|date',
        'is_active'        => 'boolean',
    ], ['discount_value' => 'Discount', 'min_order_amount' => 'Minimum order', 'max_discount' => 'Maximum discount', 'usage_limit' => 'Usage limit', 'valid_from' => 'Start date', 'valid_until' => 'End date']);
    $errors = $v->errors();
    $d = $v->validated();
    if (!isset($errors['code']) && !preg_match('/^[A-Z0-9_-]+$/', $d['code'])) {
        $errors['code'] = 'Use letters, numbers, hyphens or underscores only.';
    }
    if (!$errors && $d['discount_type'] === 'percent' && $d['discount_value'] > 100) {
        $errors['discount_value'] = 'A percentage discount cannot be more than 100%.';
    }
    if (!$errors && $d['valid_from'] && $d['valid_until'] && $d['valid_until'] < $d['valid_from']) {
        $errors['valid_until'] = 'The end date must be after the start date.';
    }
    if (!$errors && $d['usage_limit'] !== null && $d['usage_limit'] < (int) $coupon['used_count']) {
        $errors['usage_limit'] = 'It has already been used ' . (int) $coupon['used_count'] . ' times.';
    }
    if (!$errors) {
        $data = [
            'code' => $d['code'], 'description' => $d['description'] ?: null, 'discount_type' => $d['discount_type'],
            'discount_value' => $d['discount_value'], 'min_order_amount' => $d['min_order_amount'] ?: 0,
            'max_discount' => $d['discount_type'] === 'percent' ? ($d['max_discount'] ?: null) : null,
            'usage_limit' => $d['usage_limit'], 'valid_from' => $d['valid_from'] ? $d['valid_from'] . ' 00:00:00' : null,
            'valid_until' => $d['valid_until'] ? $d['valid_until'] . ' 23:59:59' : null, 'is_active' => $d['is_active'],
        ];
        if ($id) {
            db_update('coupons', $data, 'id = ?', [$id]);
        } else {
            $id = db_insert('coupons', $data);
        }
        log_activity('update', 'coupon', $id, "Saved coupon {$d['code']}", (int) $user['id']);
        flash('success', "Coupon {$d['code']} saved.");
        redirect(admin_url('coupons.php'));
    }
}

admin_header($id ? 'Edit coupon' : 'New coupon', 'coupons.php');
?>
<a class="small" href="coupons.php"><i class="bi bi-arrow-left"></i> Coupons</a>
<form method="post" novalidate class="panel" style="max-width:820px">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="row g-3">
      <div class="col-sm-6"><?= field_input('code', 'Code', $coupon['code'], $errors, ['required' => true, 'maxlength' => 30, 'placeholder' => 'DIWALI20', 'help' => 'What guests type. Saved in capitals.', 'wrap' => '']) ?></div>
      <div class="col-sm-6"><?= field_input('description', 'Description', $coupon['description'], $errors, ['maxlength' => 255, 'placeholder' => '20% off for Diwali week', 'optional' => true, 'wrap' => '']) ?></div>
      <div class="col-sm-4"><?= field_select('discount_type', 'Type', $coupon['discount_type'], ['percent' => 'Percentage off', 'flat' => 'Fixed amount off'], $errors, ['wrap' => '']) ?></div>
      <div class="col-sm-4"><?= field_input('discount_value', 'Discount', $coupon['discount_value'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 1, 'required' => true, 'help' => '% or ₹, depending on type.', 'wrap' => '']) ?></div>
      <div class="col-sm-4" id="maxWrap"><?= field_input('max_discount', 'Maximum discount', $coupon['max_discount'], $errors, ['type' => 'number', 'step' => '0.01', 'prefix' => '₹', 'optional' => true, 'help' => 'Caps a percentage discount.', 'wrap' => '']) ?></div>
      <div class="col-sm-4"><?= field_input('min_order_amount', 'Minimum order', $coupon['min_order_amount'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'prefix' => '₹', 'help' => 'Subtotal before tax.', 'wrap' => '']) ?></div>
      <div class="col-sm-4"><?= field_input('usage_limit', 'Total uses allowed', $coupon['usage_limit'], $errors, ['type' => 'number', 'min' => 1, 'optional' => true, 'help' => 'Empty = unlimited. Used ' . (int) $coupon['used_count'] . ' times so far.', 'wrap' => '']) ?></div>
      <div class="col-sm-4"></div>
      <div class="col-sm-4"><?= field_input('valid_from', 'Starts', $dateOnly($coupon['valid_from']), $errors, ['type' => 'date', 'optional' => true, 'wrap' => '']) ?></div>
      <div class="col-sm-4"><?= field_input('valid_until', 'Ends (inclusive)', $dateOnly($coupon['valid_until']), $errors, ['type' => 'date', 'optional' => true, 'wrap' => '']) ?></div>
      <div class="col-12"><?= field_switch('is_active', 'Coupon switched on', $coupon['is_active'], ['wrap' => 'mb-0']) ?></div>
    </div>
  </div>
  <?= form_actions('coupons.php', 'Save coupon') ?>
</form>
<script>
const typeSel = document.getElementById('f_discount_type');
const syncMax = () => { document.getElementById('maxWrap').hidden = typeSel.value !== 'percent'; };
typeSel.addEventListener('change', syncMax); syncMax();
</script>
<?php admin_footer();
