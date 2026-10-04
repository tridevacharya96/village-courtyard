<?php
/**
 * Access-denied page, shown by require_permission(). Not requested directly.
 */
declare(strict_types=1);

if (!function_exists('admin_header')) {
    require __DIR__ . '/includes/admin.php';
}
admin_header('Access denied');
?>
<div class="panel panel-body empty-state">
  <i class="bi bi-shield-lock" aria-hidden="true"></i>
  <h2 class="h3">You don't have access to this page</h2>
  <p>Your role doesn't include this section. If you need it for your work, ask the Super Admin to update your permissions.</p>
  <a class="btn btn-primary" href="<?= e(admin_url('index.php')) ?>">Back to dashboard</a>
</div>
<?php admin_footer();
