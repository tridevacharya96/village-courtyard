<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Users & Roles → Staff permissions. The Super Admin always has everything;
 * a fixed set of sensitive permissions can never be given to Staff.
 */
require_permission('users.manage');

$perms = db_all(
    "SELECT p.*, EXISTS(SELECT 1 FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
                        WHERE rp.permission_id = p.id AND r.slug = 'staff') AS staff_has
       FROM permissions p ORDER BY FIELD(p.module,'dashboard','orders','tables','reservations','payments','menu','homepage','gallery','pages','content','analytics','settings','users'), p.id"
);
$byModule = [];
foreach ($perms as $p) {
    $byModule[$p['module']][] = $p;
}
$staffCount = (int) db_value("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'staff' AND u.is_active = 1");

admin_header('Users & Roles', 'users.php');
?>
<nav class="status-tabs" aria-label="Users and roles">
  <a href="users.php"><i class="bi bi-people me-1"></i>Staff accounts</a>
  <a href="roles.php" class="active" aria-current="page"><i class="bi bi-shield-check me-1"></i>Staff permissions</a>
</nav>

<section class="panel">
  <div class="panel-head">
    <h2>What staff can do</h2>
    <span class="small text-muted">Applies to all <?= $staffCount ?> active staff member<?= $staffCount === 1 ? '' : 's' ?>. Changes take effect on their next page load.</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Permission</th><th class="text-center" style="width:9rem">Super Admin</th><th class="text-center" style="width:9rem">Staff</th></tr></thead>
      <tbody>
      <?php foreach ($byModule as $module => $list): ?>
        <tr><td colspan="3" class="perm-module"><?= e(ucfirst($module)) ?></td></tr>
        <?php foreach ($list as $p): $locked = in_array($p['slug'], SUPER_ADMIN_ONLY_PERMISSIONS, true); ?>
          <tr>
            <td><label for="perm_<?= (int) $p['id'] ?>" class="mb-0"><?= e($p['name']) ?></label>
              <?php if ($locked): ?><div class="lock-note"><i class="bi bi-lock"></i>Super Admin only</div><?php endif; ?></td>
            <td class="text-center"><i class="bi bi-check-lg text-success fs-5" aria-label="Allowed"></i></td>
            <td class="text-center">
              <?php if ($locked): ?>
                <i class="bi bi-dash-lg text-muted fs-5" aria-label="Not allowed"></i>
              <?php else: ?>
                <div class="form-check form-switch d-inline-block mb-0">
                  <input class="form-check-input js-perm" type="checkbox" role="switch" id="perm_<?= (int) $p['id'] ?>" data-perm="<?= e($p['slug']) ?>" <?= (int) $p['staff_has'] ? 'checked' : '' ?>>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php admin_footer(['js/users.js']);
