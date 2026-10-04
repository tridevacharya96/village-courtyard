<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * POST ajax/user-action.php   (Super Admin only)
 *   action=save    id?, name, email, phone, password (password required for new users)
 *   action=reset   id            → returns a new temporary password
 *   action=toggle  id            → deactivate / reactivate
 *   action=perm    permission, granted (0|1)   → Staff role permission matrix
 */
$me = require_permission('users.manage');
admin_require_post();

$action = (string) ($_POST['action'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);

/** Readable random password that satisfies the password rule. */
function temp_password(): string
{
    $words = ['Mango', 'Lotus', 'Saffron', 'Tamarind', 'Jasmine', 'Cardamom', 'Lantern', 'Banyan', 'Clove', 'Basil'];
    return $words[random_int(0, count($words) - 1)] . '-' . random_int(1000, 9999) . '-' . $words[random_int(0, count($words) - 1)];
}

/** Only Staff accounts can be edited here; the Super Admin is never touched. */
function staff_or_fail(int $id): array
{
    $u = db_one("SELECT u.* FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND r.slug = 'staff'", [$id]);
    if (!$u) {
        json_error('Staff member not found. The Super Admin account cannot be changed here.', 404);
    }
    return $u;
}

switch ($action) {
    case 'save':
        $isNew = $id === 0;
        if (!$isNew) {
            staff_or_fail($id);
        }
        $rules = [
            'name'  => 'required|string|min:2|max:100',
            'email' => 'required|email|max:150|unique:users,email' . ($isNew ? '' : ",$id"),
            'phone' => 'nullable|phone',
        ];
        if ($isNew) {
            $rules['password'] = 'required|password';
        }
        $v = Validator::make($_POST, $rules, ['password' => 'Temporary password']);
        if ($v->fails()) {
            json_validation_error($v->errors());
        }
        $d = $v->validated();

        if ($isNew) {
            $staffRole = (int) db_value("SELECT id FROM roles WHERE slug = 'staff'");
            $newId = db_insert('users', [
                'role_id' => $staffRole, 'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?: null,
                'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'force_password_change' => 1,
            ]);
            log_activity('create', 'user', $newId, "Added staff member {$d['name']} ({$d['email']})", (int) $me['id']);
            flash('success', "{$d['name']} can now sign in with the temporary password you set.");
            json_response(['id' => $newId], 201);
        }
        db_update('users', ['name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'] ?: null], 'id = ?', [$id]);
        log_activity('update', 'user', $id, "Updated staff member {$d['name']}", (int) $me['id']);
        flash('success', "{$d['name']}'s details were saved.");
        json_response(['id' => $id]);

    case 'reset':
        $u  = staff_or_fail($id);
        $pw = temp_password();
        db_update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'force_password_change' => 1], 'id = ?', [$id]);
        db_query('DELETE FROM login_attempts WHERE email = ?', [$u['email']]);       // lift any lockout
        log_activity('password_reset', 'user', $id, "Reset password for {$u['name']}", (int) $me['id']);
        json_response(['password' => $pw, 'name' => $u['name']]);

    case 'toggle':
        $u = staff_or_fail($id);
        $active = (int) $u['is_active'] ? 0 : 1;
        db_update('users', ['is_active' => $active], 'id = ?', [$id]);
        log_activity($active ? 'reactivate' : 'deactivate', 'user', $id, ($active ? 'Reactivated ' : 'Deactivated ') . $u['name'], (int) $me['id']);
        json_response(['is_active' => $active], 200, $u['name'] . ($active ? ' can sign in again.' : ' has been deactivated and signed out.'));

    case 'perm':
        $slug    = (string) ($_POST['permission'] ?? '');
        $granted = !empty($_POST['granted']) && $_POST['granted'] !== '0';
        $permId  = db_value('SELECT id FROM permissions WHERE slug = ?', [$slug]);
        if (!$permId) {
            json_error('Unknown permission.', 404);
        }
        if (in_array($slug, SUPER_ADMIN_ONLY_PERMISSIONS, true)) {
            json_error('This permission is reserved for the Super Admin.', 403);
        }
        $staffRole = (int) db_value("SELECT id FROM roles WHERE slug = 'staff'");
        if ($granted) {
            db_query('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$staffRole, $permId]);
        } else {
            db_query('DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$staffRole, $permId]);
        }
        $name = (string) db_value('SELECT name FROM permissions WHERE id = ?', [$permId]);
        log_activity('permission_change', 'role', $staffRole, ($granted ? 'Granted' : 'Removed') . " Staff permission: $name", (int) $me['id']);
        json_response(['granted' => $granted], 200, ($granted ? 'Staff can now: ' : 'Staff can no longer: ') . lcfirst($name) . '.');

    default:
        json_error('Unknown action.', 400);
}
