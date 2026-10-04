<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Users & Roles → Staff accounts (Super Admin only).
 * There is exactly one Super Admin; everyone created here is Staff.
 */
$me = require_permission('users.manage');

$users = db_all(
    "SELECT u.*, r.slug AS role_slug, r.name AS role_name,
            (SELECT COUNT(*) FROM activity_logs a WHERE a.user_id = u.id AND a.created_at > NOW() - INTERVAL 30 DAY) AS actions_30d
       FROM users u JOIN roles r ON r.id = u.role_id
      ORDER BY (r.slug = 'super_admin') DESC, u.is_active DESC, u.name"
);

admin_header('Users & Roles', 'users.php');
?>
<nav class="status-tabs" aria-label="Users and roles">
  <a href="users.php" class="active" aria-current="page"><i class="bi bi-people me-1"></i>Staff accounts</a>
  <a href="roles.php"><i class="bi bi-shield-check me-1"></i>Staff permissions</a>
</nav>

<section class="panel">
  <div class="panel-head">
    <h2>Accounts</h2>
    <button class="btn btn-primary btn-sm" type="button" id="addUser"><i class="bi bi-person-plus me-1"></i>Add staff member</button>
  </div>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Name</th><th>Role</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions (30 days)</th><th class="text-end">Manage</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): $isSuper = $u['role_slug'] === 'super_admin'; ?>
        <tr<?= (int) $u['is_active'] ? '' : ' class="text-muted"' ?>>
          <td>
            <div class="d-flex align-items-center gap-2">
              <span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
              <div><b><?= e($u['name']) ?></b><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="badge text-bg-light border">You</span>' : '' ?>
                <small class="d-block text-muted"><?= e($u['email']) ?><?= $u['phone'] ? ' · ' . e($u['phone']) : '' ?></small></div>
            </div>
          </td>
          <td><?= $isSuper ? '<span class="badge text-bg-dark"><i class="bi bi-star-fill me-1"></i>Super Admin</span>' : '<span class="badge text-bg-light border">Staff</span>' ?></td>
          <td>
            <?php if (!(int) $u['is_active']): ?><span class="badge text-bg-secondary"><i class="bi bi-slash-circle me-1"></i>Deactivated</span>
            <?php elseif ((int) $u['force_password_change']): ?><span class="badge text-bg-warning"><i class="bi bi-key me-1"></i>Temporary password</span>
            <?php else: ?><span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Active</span><?php endif; ?>
          </td>
          <td class="small"><?= $u['last_login_at'] ? e(time_ago($u['last_login_at'])) : '<span class="text-muted">Never</span>' ?></td>
          <td class="text-end tabular"><a href="activity-log.php?user=<?= (int) $u['id'] ?>"><?= (int) $u['actions_30d'] ?></a></td>
          <td class="text-end">
            <?php if ($isSuper): ?>
              <a class="btn btn-sm btn-light" href="change-password.php">Change password</a>
            <?php else: ?>
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary js-edit" type="button" data-user="<?= e(json_encode(['id' => (int) $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone']])) ?>">Edit</button>
                <button class="btn btn-outline-primary js-reset" type="button" data-id="<?= (int) $u['id'] ?>" data-name="<?= e($u['name']) ?>">Reset password</button>
                <button class="btn btn-outline-<?= (int) $u['is_active'] ? 'danger' : 'success' ?> js-toggle" type="button" data-id="<?= (int) $u['id'] ?>" data-name="<?= e($u['name']) ?>" data-active="<?= (int) $u['is_active'] ?>">
                  <?= (int) $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="panel-foot"><span>Deactivated staff are signed out immediately and can't sign in. Their past actions stay in the activity log.</span></div>
</section>

<!-- Add / edit staff -->
<div class="modal fade" id="userModal" tabindex="-1" aria-labelledby="userModalTitle" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" id="userForm" novalidate>
    <div class="modal-header"><h2 class="modal-title fs-4" id="userModalTitle">Add staff member</h2>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body d-grid gap-3">
      <input type="hidden" name="id" id="u_id">
      <div><label class="form-label" for="u_name">Full name</label><input class="form-control" id="u_name" name="name" maxlength="100" required><div class="invalid-feedback"></div></div>
      <div><label class="form-label" for="u_email">Email (used to sign in)</label><input class="form-control" type="email" id="u_email" name="email" maxlength="150" required><div class="invalid-feedback"></div></div>
      <div><label class="form-label" for="u_phone">Phone <span class="text-muted">(optional)</span></label><input class="form-control" id="u_phone" name="phone" maxlength="20"><div class="invalid-feedback"></div></div>
      <div id="u_pw_wrap"><label class="form-label" for="u_password">Temporary password</label>
        <div class="input-group"><input class="form-control" id="u_password" name="password" autocomplete="new-password">
          <button class="btn btn-outline-secondary" type="button" id="genPw">Generate</button></div>
        <div class="invalid-feedback d-block" id="u_password_err"></div>
        <div class="form-text">They will be asked to choose their own password the first time they sign in.</div></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
  </form></div>
</div>

<!-- Temporary password shown once -->
<div class="modal fade" id="pwModal" tabindex="-1" aria-labelledby="pwModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-4" id="pwModalTitle">Temporary password</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p id="pwFor"></p>
      <div class="input-group"><input class="form-control font-monospace fs-5" id="pwValue" readonly><button class="btn btn-outline-primary" type="button" id="copyPw"><i class="bi bi-clipboard"></i> Copy</button></div>
      <p class="small text-muted mt-2 mb-0">Share it with them privately. It is shown only once and must be changed at first sign-in.</p>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button></div>
  </div></div>
</div>
<?php admin_footer(['js/users.js']);
