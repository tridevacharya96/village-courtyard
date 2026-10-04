<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * POST ajax/table-action.php
 *   action=status  id, status                                   (tables.update)
 *   action=save    id?, table_number, capacity, location, is_active (tables.manage)
 *   action=delete  id                                           (tables.manage)
 */
require_login();
admin_require_post();

$action = (string) ($_POST['action'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);

$activeOrders = static function (int $tableId): int {
    $ph = implode(',', array_fill(0, count(ACTIVE_ORDER_STATUSES), '?'));
    return (int) db_value("SELECT COUNT(*) FROM orders o WHERE o.table_id = ? AND o.status IN ($ph) AND " . ORDER_VISIBLE_SQL, [$tableId, ...ACTIVE_ORDER_STATUSES]);
};

switch ($action) {
    case 'status':
        $user   = require_permission('tables.update');
        $status = (string) ($_POST['status'] ?? '');
        $table  = db_one('SELECT * FROM restaurant_tables WHERE id = ?', [$id]);
        if (!$table || !in_array($status, TABLE_STATUSES, true)) {
            json_error('Table or status not found.', 404);
        }
        if ($status === 'available' && ($n = $activeOrders($id)) > 0) {
            json_error("Table {$table['table_number']} still has $n active order" . ($n > 1 ? 's' : '') . '. Complete or cancel them first.', 409);
        }
        db_update('restaurant_tables', ['status' => $status], 'id = ?', [$id]);
        log_activity('table_status', 'table', $id, "{$table['table_number']}: {$table['status']} → $status", (int) $user['id']);
        json_response(['status' => $status], 200, "Table {$table['table_number']} is now $status.");

    case 'save':
        $user = require_permission('tables.manage');
        $_POST['table_number'] = strtoupper(trim((string) ($_POST['table_number'] ?? '')));
        $v = Validator::make($_POST, [
            'table_number' => 'required|string|max:10|unique:restaurant_tables,table_number' . ($id ? ",$id" : ''),
            'capacity'     => 'required|integer|between:1,30',
            'location'     => 'nullable|string|max:50',
            'is_active'    => 'boolean',
        ], ['table_number' => 'Table number', 'capacity' => 'Seats', 'location' => 'Area']);
        if ($v->fails()) {
            json_validation_error($v->errors());
        }
        $d = $v->validated();
        $data = ['table_number' => $d['table_number'], 'capacity' => $d['capacity'], 'location' => $d['location'] ? ucwords((string) $d['location']) : null, 'is_active' => $d['is_active']];

        if ($id) {
            if (!$d['is_active'] && $activeOrders($id) > 0) {
                json_error('This table has active orders. Complete them before taking the table out of use.', 409);
            }
            db_update('restaurant_tables', $data, 'id = ?', [$id]);
            log_activity('update', 'table', $id, "Updated table {$data['table_number']}", (int) $user['id']);
            json_response(['id' => $id], 200, "Table {$data['table_number']} saved.");
        }
        $newId = db_insert('restaurant_tables', $data + ['status' => 'available']);
        log_activity('create', 'table', $newId, "Added table {$data['table_number']}", (int) $user['id']);
        json_response(['id' => $newId], 201, "Table {$data['table_number']} added.");

    case 'delete':
        $user  = require_permission('tables.manage');
        $table = db_one('SELECT * FROM restaurant_tables WHERE id = ?', [$id]);
        if (!$table) {
            json_error('Table not found.', 404);
        }
        $used = (int) db_value('SELECT COUNT(*) FROM orders WHERE table_id = ?', [$id])
              + (int) db_value("SELECT COUNT(*) FROM reservations WHERE table_id = ? AND status IN ('pending','confirmed')", [$id]);
        if ($used > 0) {
            json_error("Table {$table['table_number']} has order or booking history. Edit it and switch off \"In use\" instead, so past records stay intact.", 409);
        }
        db_query('DELETE FROM restaurant_tables WHERE id = ?', [$id]);
        log_activity('delete', 'table', $id, "Deleted table {$table['table_number']}", (int) $user['id']);
        json_response(null, 200, "Table {$table['table_number']} deleted.");

    default:
        json_error('Unknown action.', 400);
}
