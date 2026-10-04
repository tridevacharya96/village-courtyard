<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/categories.php — active categories with their count of available dishes.
 */
api_endpoint('GET');
api_cache(120);

$rows = db_all(
    'SELECT c.*, COUNT(m.id) AS item_count
       FROM categories c
       LEFT JOIN menu_items m ON m.category_id = c.id AND m.is_available = 1
      WHERE c.is_active = 1
      GROUP BY c.id
      ORDER BY c.sort_order, c.name'
);

json_response(['categories' => array_map('api_category', $rows)]);
