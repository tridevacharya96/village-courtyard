<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * POST ajax/reservation-action.php  id, action
 *   action = pending | confirmed | completed | cancelled | no_show   (reservations.manage)
 *   action = delete                                                (reservations.delete)
 */
require_login();
admin_require_post();

$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$r = db_one('SELECT r.*, t.table_number FROM reservations r LEFT JOIN restaurant_tables t ON t.id = r.table_id WHERE r.id = ?', [$id]);
if (!$r) {
    json_error('Booking not found.', 404);
}

if ($action === 'delete') {
    $user = require_permission('reservations.delete');
    db_query('DELETE FROM reservations WHERE id = ?', [$id]);
    log_activity('delete', 'reservation', $id, "Deleted booking {$r['reference']} ({$r['name']})", (int) $user['id']);
    json_response(null, 200, 'Booking deleted.');
}

$user = require_permission('reservations.manage');
if (!in_array($action, RESERVATION_STATUSES, true)) {
    json_error('Unknown action.', 400);
}

// Reopening or confirming must still fit: re-check the slot without this booking
if (in_array($action, ['pending', 'confirmed'], true) && !in_array($r['status'], ['pending', 'confirmed'], true)) {
    $fits = false;
    foreach (reservation_availability($r['reservation_date'], (int) $r['guests'], null, $id) as $slot) {
        $fits = $fits || ($slot['time'] === substr($r['reservation_time'], 0, 5) && $slot['available']);
    }
    // Staff may override: reservation_slot_times() hides past/too-soon slots, so only block when the slot exists and is full
    if (!$fits && in_array(substr($r['reservation_time'], 0, 5), reservation_slot_times($r['reservation_date']), true)) {
        json_error('That time is now fully booked. Edit the booking to choose another time or table.', 409);
    }
}

// An assigned table must not be double-booked when confirming
if ($action === 'confirmed' && $r['table_id']) {
    $clash = db_value(
        "SELECT reference FROM reservations
          WHERE id <> ? AND table_id = ? AND reservation_date = ? AND status IN ('pending','confirmed')
            AND ABS(TIMESTAMPDIFF(MINUTE, CONCAT(reservation_date, ' ', reservation_time), CONCAT(?, ' ', ?))) < ?",
        [$id, $r['table_id'], $r['reservation_date'], $r['reservation_date'], $r['reservation_time'], reservation_config()['dining_minutes']]
    );
    if ($clash) {
        json_error("Table {$r['table_number']} is already held for booking $clash at that time. Assign another table first.", 409);
    }
}

db_update('reservations', ['status' => $action, 'handled_by' => $user['id']], 'id = ?', [$id]);
log_activity('reservation_' . $action, 'reservation', $id, "{$r['reference']} ({$r['name']}, {$r['guests']} guests): {$r['status']} → $action", (int) $user['id']);

$messages = [
    'confirmed' => "Booking for {$r['name']} confirmed. Call or message them on {$r['phone']} to let them know.",
    'completed' => "{$r['name']}'s party marked as seated.",
    'cancelled' => "Booking for {$r['name']} cancelled.",
    'no_show'   => "{$r['name']} marked as a no-show.",
    'pending'   => 'Booking reopened.',
];
json_response(['status' => $action], 200, $messages[$action]);
