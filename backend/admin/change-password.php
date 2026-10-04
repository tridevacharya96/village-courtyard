<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Change own password. Users with a temporary password (new staff, a reset,
 * or the seeded Super Admin) are sent here until they set a new one.
 */
$user   = require_login();
$forced = (int) $user['force_password_change'] === 1;
$errors = [];

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'current_password' => 'required',
        'password'         => 'required|password|confirmed',
    ], ['current_password' => 'Current password', 'password' => 'New password']);

    $errors = $v->errors();
    if (!$errors) {
        $result = change_password((int) $user['id'], (string) $_POST['current_password'], (string) $_POST['password']);
        if ($result['ok']) {
            flash('success', 'Your password has been changed.');
            redirect(admin_url('index.php'));
        }
        $errors['current_password'] = $result['error'];
    }
}

admin_header('Change password', '', ['bare' => $forced]);
?>
<div class="<?= $forced ? 'bare-stack' : '' ?>">
  <form class="<?= $forced ? 'auth-card' : 'panel panel-body' ?>" method="post" novalidate style="<?= $forced ? '' : 'max-width:480px' ?>">
    <?= csrf_field() ?>
    <?php if ($forced): ?>
      <div class="brand"><?= admin_logo_svg(48) ?><b>Set a new password</b>
        <small>Required before you continue</small></div>
      <p class="text-muted small">You signed in with a temporary password. Choose a new one that only you know.</p>
    <?php endif; ?>
    <?php foreach ([
        ['current_password', 'Current password', 'current-password'],
        ['password', 'New password', 'new-password'],
        ['password_confirmation', 'Confirm new password', 'new-password'],
    ] as [$name, $label, $ac]): ?>
      <div class="mb-3">
        <label class="form-label" for="<?= $name ?>"><?= $label ?></label>
        <input class="form-control<?= isset($errors[$name]) ? ' is-invalid' : '' ?>" type="password" id="<?= $name ?>" name="<?= $name ?>" autocomplete="<?= $ac ?>" required>
        <?php if (isset($errors[$name])): ?><div class="invalid-feedback"><?= e($errors[$name]) ?></div><?php endif; ?>
        <?php if ($name === 'password'): ?><div class="form-text">At least 8 characters, with upper-case, lower-case and a number.</div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <button class="btn btn-primary<?= $forced ? ' w-100' : '' ?>" type="submit">Save new password</button>
  </form>
  <?php if ($forced): ?>
    <form method="post" action="<?= e(admin_url('logout.php')) ?>"><?= csrf_field() ?>
      <button class="btn btn-link link-light" type="submit">Sign out instead</button>
    </form>
  <?php endif; ?>
</div>
<?php admin_footer();
