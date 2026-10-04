<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/tables.php — table numbers guests can pick for a dine-in order.
 * (Only numbers and areas; occupancy is staff-only information.)
 */
api_endpoint('GET');
api_cache(300);

json_response(['tables' => array_map(static fn ($t) => [
    'table_number' => $t['table_number'],
    'location'     => $t['location'],
    'capacity'     => (int) $t['capacity'],
], db_all('SELECT table_number, location, capacity FROM restaurant_tables WHERE is_active = 1 ORDER BY id'))]);
