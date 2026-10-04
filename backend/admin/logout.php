<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/** Sign out (POST only, with CSRF, so a link on another site can't log staff out). */
admin_require_post();
logout_user();
start_secure_session();
flash('success', 'You have been signed out.');
redirect(admin_url('login.php'));
