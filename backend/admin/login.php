<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Admin sign-in. Lockout after repeated failures is handled by attempt_login().
 */
if (is_logged_in()) {
    redirect(admin_url('index.php'));
}

$error = null;
$email = '';

if (is_post()) {
    verify_csrf();
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter your email and password.';
    } else {
        $result = attempt_login($email, $password);
        if ($result['ok']) {
            $target = $_SESSION['intended_url'] ?? null;
            unset($_SESSION['intended_url']);
            // Only follow same-site admin paths after login
            $safe = is_string($target) && str_starts_with($target, (string) parse_url(admin_url(''), PHP_URL_PATH)) && !str_contains($target, '//');
            redirect($safe ? $target : admin_url('index.php'));
        }
        $error = $result['error'];
    }
}

admin_header('Sign in', '', ['bare' => true]);
?>
<div class="bare-stack">
  <form class="auth-card" method="post" novalidate>
    <?= csrf_field() ?>
    <div class="brand">
      <?= admin_logo_svg(56) ?>
      <b><?= e(setting('site_name', APP_NAME)) ?></b>
      <small>Admin panel</small>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="mb-3">
      <label class="form-label" for="email">Email</label>
      <input class="form-control form-control-lg" type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label" for="password">Password</label>
      <div class="input-group">
        <input class="form-control form-control-lg" type="password" id="password" name="password" autocomplete="current-password" required>
        <button class="btn btn-outline-secondary" type="button" id="showPw" aria-label="Show password"><i class="bi bi-eye"></i></button>
      </div>
    </div>
    <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
    <p class="text-muted small text-center mt-3 mb-0">Forgot your password? Ask the Super Admin to reset it.</p>
  </form>
</div>
<script>
document.getElementById('showPw').addEventListener('click', function () {
  const f = document.getElementById('password'); const show = f.type === 'password';
  f.type = show ? 'text' : 'password';
  this.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
</script>
<?php admin_footer();
