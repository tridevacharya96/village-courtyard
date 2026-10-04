<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Site settings, one tab per group. Each tab saves only its own fields.
 * Secret values are never printed; an empty box keeps the saved secret.
 */
$user = require_permission('settings.manage');

const SETTING_TABS = [
    'general'     => ['General', 'bi-shop'],
    'contact'     => ['Contact & hours', 'bi-geo-alt'],
    'social'      => ['Social links', 'bi-instagram'],
    'order'       => ['Online ordering', 'bi-bag'],
    'reservation' => ['Reservations', 'bi-calendar-check'],
    'payment'     => ['Payments', 'bi-credit-card'],
    'seo'         => ['Search & sharing', 'bi-search'],
];
const SETTING_HELP = [
    'tagline'             => 'A short line under the name in the header and browser tab.',
    'logo'                => 'PNG or SVG with a transparent background, for light backgrounds.',
    'logo_white'          => 'Light version shown on the dark header and footer.',
    'favicon'             => 'Square, at least 64 × 64 px.',
    'maintenance_mode'    => 'Shows the message below instead of the website. Payments already in progress still complete.',
    'copyright_text'      => '{year} is replaced with the current year.',
    'map_embed_url'       => 'In Google Maps: Share → Embed a map → copy the address inside src="…".',
    'whatsapp'            => 'With country code, e.g. +91 98765 43210.',
    'gst_percent'         => 'Restaurant GST is usually 5%. Applied after discounts.',
    'delivery_charge'     => 'Charged on delivery orders below the free-delivery amount.',
    'free_delivery_above' => 'Set 0 to always charge delivery.',
    'min_order_amount'    => 'Smallest subtotal accepted online.',
    'reservation_slot_minutes'       => 'Guests can book every N minutes (15, 30, 60).',
    'reservation_dining_minutes'     => 'How long a table stays blocked after a booking starts.',
    'reservation_min_notice_minutes' => 'Same-day bookings must be at least this far ahead.',
    'razorpay_key_id'     => 'From Razorpay Dashboard → Account & Settings → API Keys. Starts with rzp_test_ or rzp_live_.',
    'razorpay_key_secret' => 'Shown once when you generate keys in Razorpay.',
    'razorpay_webhook_secret' => 'The secret you typed when creating the webhook (Settings → Webhooks).',
    'meta_description'    => 'About 150 characters, shown under the link in Google.',
    'og_image'            => 'Shown when the site is shared on WhatsApp, Facebook, etc. 1200 × 630 px.',
    'google_analytics_id' => 'Optional, e.g. G-XXXXXXXXXX. The built-in analytics work without it.',
];
const ENV_SECRETS = ['razorpay_key_id' => 'RAZORPAY_KEY_ID', 'razorpay_key_secret' => 'RAZORPAY_KEY_SECRET', 'razorpay_webhook_secret' => 'RAZORPAY_WEBHOOK_SECRET'];

$tab = isset(SETTING_TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'general';
$rows = db_all('SELECT * FROM settings WHERE grp = ? ORDER BY id', [$tab]);
if ($tab === 'order') {          // the slot length lives in the order group in the seed data; show it with reservations
    $rows = array_values(array_filter($rows, static fn ($r) => $r['setting_key'] !== 'reservation_slot_minutes'));
} elseif ($tab === 'reservation') {
    $rows = array_merge($rows, db_all("SELECT * FROM settings WHERE setting_key = 'reservation_slot_minutes'"));
}
$errors = [];

if (is_post()) {
    verify_csrf();
    if ($tab === 'payment' && !can('payments.config')) {
        require_permission('payments.config');
    }
    $save = [];
    foreach ($rows as $r) {
        $k = $r['setting_key'];
        $raw = $_POST[$k] ?? null;
        switch ($r['type']) {
            case 'boolean':
                $save[$k] = !empty($raw) && $raw !== '0' ? '1' : '0';
                break;
            case 'number':
                if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1000000) {
                    $errors[$k] = 'Enter a number of 0 or more.';
                } else {
                    $save[$k] = (string) (0 + $raw);
                }
                break;
            case 'email':
                $raw = strtolower(trim((string) $raw));
                if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $errors[$k] = 'Enter a valid email address.';
                } else {
                    $save[$k] = $raw;
                }
                break;
            case 'url':
                $raw = trim((string) $raw);
                if ($raw !== '' && !(preg_match('#^https://#i', $raw) && filter_var($raw, FILTER_VALIDATE_URL))) {
                    $errors[$k] = 'Enter a full address starting with https://';
                } else {
                    $save[$k] = $raw;
                }
                break;
            case 'secret':
                $raw = trim((string) $raw);
                if ($raw !== '') {
                    $save[$k] = $raw;                  // empty keeps the saved value
                }
                break;
            case 'image':
                $new = save_image_field($k, 'logo', $r['value'] ?: null, $errors, true);
                if (($new ?? '') !== ($r['value'] ?? '')) {
                    $save[$k] = (string) $new;
                }
                break;
            case 'json':                               // opening hours rows
                $hours = [];
                foreach ((array) ($raw ?? []) as $row) {
                    $day = clean_text($row['day'] ?? '', 40);
                    $hrs = clean_text($row['hours'] ?? '', 60);
                    if ($day !== '' || $hrs !== '') {
                        $hours[] = ['day' => $day, 'hours' => $hrs];
                    }
                }
                if (!$hours) {
                    $errors[$k] = 'Add at least one line of opening hours.';
                }
                $save[$k] = json_encode(array_slice($hours, 0, 10), JSON_UNESCAPED_UNICODE);
                break;
            default:                                   // text, textarea
                $save[$k] = clean_text($raw, $r['type'] === 'textarea' ? 2000 : 500);
        }
    }

    // Field-specific rules
    if (isset($save['site_name']) && mb_strlen($save['site_name']) < 2) {
        $errors['site_name'] = 'The restaurant needs a name.';
    }
    if (isset($save['razorpay_key_id']) && $save['razorpay_key_id'] !== '' && !preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,}$/', $save['razorpay_key_id'])) {
        $errors['razorpay_key_id'] = 'Razorpay key IDs look like rzp_test_AbCd1234… or rzp_live_…';
    }
    foreach (['reservation_open_time', 'reservation_close_time'] as $tk) {
        if (isset($save[$tk]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $save[$tk])) {
            $errors[$tk] = 'Use 24-hour time, e.g. 12:00 or 22:30.';
        }
    }
    if (!$errors && isset($save['reservation_open_time'], $save['reservation_close_time']) && $save['reservation_close_time'] <= $save['reservation_open_time']) {
        $errors['reservation_close_time'] = 'The last booking time must be after the first.';
    }
    if (isset($save['gst_percent']) && (float) $save['gst_percent'] > 28) {
        $errors['gst_percent'] = 'GST can be at most 28%.';
    }
    if (isset($save['reservation_slot_minutes']) && !in_array((int) $save['reservation_slot_minutes'], [15, 20, 30, 45, 60], true)) {
        $errors['reservation_slot_minutes'] = 'Choose 15, 20, 30, 45 or 60 minutes.';
    }

    if (!$errors) {
        $changed = [];
        foreach ($save as $k => $val) {
            $old = (string) db_value('SELECT value FROM settings WHERE setting_key = ?', [$k]);
            if ($old !== $val) {
                db_query('UPDATE settings SET value = ? WHERE setting_key = ?', [$val, $k]);
                $type = db_value('SELECT type FROM settings WHERE setting_key = ?', [$k]);
                $changed[] = $type === 'secret' ? "$k (changed)" : "$k: " . mb_strimwidth($old, 0, 40, '…') . ' → ' . mb_strimwidth($val, 0, 40, '…');
            }
        }
        if ($changed) {
            log_activity('settings', 'setting', null, SETTING_TABS[$tab][0] . ': ' . implode('; ', $changed), (int) $user['id']);
        }
        flash('success', $changed ? SETTING_TABS[$tab][0] . ' settings saved.' : 'Nothing changed.');
        redirect(admin_url('settings.php?tab=' . $tab));
    }
}

/** Render one setting row as a form field. */
function setting_field(array $r, array $errors): string
{
    $k = $r['setting_key'];
    $help = SETTING_HELP[$k] ?? null;
    switch ($r['type']) {
        case 'boolean':
            return field_switch($k, $r['label'], $r['value'] === '1', ['help' => $help]);
        case 'textarea':
            return field_textarea($k, $r['label'], $r['value'], $errors, ['rows' => 3, 'maxlength' => 2000, 'help' => $help]);
        case 'number':
            return field_input($k, $r['label'], $r['value'], $errors, ['type' => 'number', 'step' => 'any', 'min' => 0, 'help' => $help]);
        case 'email':
            return field_input($k, $r['label'], $r['value'], $errors, ['type' => 'email', 'help' => $help]);
        case 'url':
            return field_input($k, $r['label'], $r['value'], $errors, ['type' => 'url', 'placeholder' => 'https://', 'help' => $help]);
        case 'image':
            return field_image($k, $r['label'], $r['value'] ?: null, $errors, ['svg' => true, 'help' => $help]);
        case 'secret':
            $env = ENV_SECRETS[$k] ?? null;
            $envSet = $env && (string) env($env, '') !== '';
            $state = $envSet ? '<span class="secret-state"><i class="bi bi-file-earmark-lock"></i> Set in the server’s .env file, which takes priority over this box.</span>'
                : ($r['value'] !== '' && $r['value'] !== null ? '<span class="secret-state"><i class="bi bi-check-circle text-success"></i> Saved. Leave empty to keep it.</span>' : '<span class="secret-state"><i class="bi bi-exclamation-circle text-warning"></i> Not set yet.</span>');
            return '<div class="mb-3"><label class="form-label" for="f_' . e($k) . '">' . e($r['label']) . '</label>'
                . '<input class="form-control' . (isset($errors[$k]) ? ' is-invalid' : '') . '" type="password" id="f_' . e($k) . '" name="' . e($k) . '" autocomplete="new-password" placeholder="' . ($r['value'] ? '••••••••••••' : '') . '">'
                . field_error($k, $errors) . '<div class="form-text">' . $state . ($help ? ' ' . e($help) : '') . '</div></div>';
        case 'json':
            $rows = is_post() ? (array) ($_POST[$k] ?? []) : (json_decode((string) $r['value'], true) ?: []);
            $html = '<fieldset class="mb-3" id="hoursEditor"><legend class="form-label fs-6">' . e($r['label']) . '</legend><div id="hoursRows">';
            foreach (array_values($rows) as $i => $row) {
                $html .= hours_row($k, (string) $i, $row['day'] ?? '', $row['hours'] ?? '');
            }
            $html .= '</div><template>' . hours_row($k, '__i__', '', '') . '</template>'
                . '<button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addHours"><i class="bi bi-plus-lg me-1"></i>Add line</button>'
                . field_error($k, $errors) . '<div class="form-text">Shown exactly as written, e.g. “Monday – Friday” and “12:00 PM – 11:00 PM”, or “Tuesday” and “Closed”.</div></fieldset>';
            return $html;
        default:
            $extra = in_array($k, ['reservation_open_time', 'reservation_close_time'], true) ? ['type' => 'time'] : [];
            return field_input($k, $r['label'], $r['value'], $errors, $extra + ['maxlength' => 500, 'help' => $help]);
    }
}

function hours_row(string $k, string $i, string $day, string $hours): string
{
    return '<div class="hours-row"><input class="form-control form-control-sm" name="' . e($k) . '[' . e($i) . '][day]" value="' . e($day) . '" placeholder="Monday – Friday" aria-label="Days">'
        . '<input class="form-control form-control-sm" name="' . e($k) . '[' . e($i) . '][hours]" value="' . e($hours) . '" placeholder="12:00 PM – 11:00 PM" aria-label="Hours">'
        . '<button type="button" class="btn btn-sm btn-outline-danger js-remove-hours" aria-label="Remove line"><i class="bi bi-x-lg"></i></button></div>';
}

admin_header('Settings', 'settings.php');
?>
<div class="form-layout settings-layout">
  <nav class="panel panel-body nav flex-column settings-nav gap-1" aria-label="Settings sections">
    <?php foreach (SETTING_TABS as $k => [$label, $icon]): ?>
      <a class="nav-link<?= $tab === $k ? ' active' : '' ?>" href="settings.php?tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><i class="bi <?= $icon ?> me-2"></i><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="post" enctype="multipart/form-data" novalidate class="panel">
    <?= csrf_field() ?>
    <div class="panel-head"><h2><?= e(SETTING_TABS[$tab][0]) ?></h2>
      <?php if ($tab === 'payment'): ?>
        <span class="d-flex align-items-center gap-2">
          <?= razorpay_is_configured() ? '<span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Online payments on</span>' : '<span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i>Online payments off</span>' ?>
          <?php if (razorpay_is_configured()): ?><button type="button" class="btn btn-sm btn-outline-primary" id="testRzp">Test connection</button><?php endif; ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="panel-body">
      <?php if ($tab === 'payment'): ?>
        <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>
          Set your Razorpay webhook to <code class="user-select-all text-break"><?= e(base_url('api/razorpay-webhook.php')) ?></code> with events
          <b>payment.captured</b>, <b>payment.failed</b>, <b>order.paid</b> and <b>refund.processed</b>. Use test keys until you have placed a test order end to end.</div>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?><?= setting_field($r, $errors) ?><?php endforeach; ?>
    </div>
    <?= form_actions('settings.php?tab=' . $tab, 'Save ' . strtolower(SETTING_TABS[$tab][0])) ?>
  </form>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const rows = document.getElementById('hoursRows');
  if (rows) {
    let next = rows.children.length + 100;
    document.getElementById('addHours').addEventListener('click', () => {
      rows.insertAdjacentHTML('beforeend', document.querySelector('#hoursEditor template').innerHTML.replaceAll('__i__', String(next++)));
    });
    rows.addEventListener('click', (e) => { if (e.target.closest('.js-remove-hours')) e.target.closest('.hours-row').remove(); });
  }
  const test = document.getElementById('testRzp');
  if (test) test.addEventListener('click', () => {
    test.disabled = true;
    VC.post('ajax/settings-test.php', {}).then((res) => VC.toast(res.message)).finally(() => { test.disabled = false; });
  });
});
</script>
<?php admin_footer(['js/crud.js']);
