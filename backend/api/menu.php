<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/menu.php
 *   ?category=starters     filter by category slug
 *   ?type=veg              veg | non_veg | egg
 *   ?q=paneer              search name + description
 *   ?featured=1 / ?special=1
 *   ?slug=butter-chicken   single item (404 if not found)
 */
api_endpoint('GET');
api_cache(60);

if (isset($_GET['slug'])) {
    $items = api_menu_items('m.slug = ?', [clean_text($_GET['slug'], 140)], 1);
    if (!$items) {
        json_error('Dish not found.', 404);
    }
    json_response(['item' => $items[0]]);
}

$where  = [];
$params = [];

if (!empty($_GET['category'])) {
    $where[]  = 'c.slug = ?';
    $params[] = clean_text($_GET['category'], 90);
}
if (!empty($_GET['type'])) {
    $type = (string) $_GET['type'];
    if (!in_array($type, ['veg', 'non_veg', 'egg'], true)) {
        json_error('type must be veg, non_veg or egg.', 400);
    }
    $where[]  = 'm.food_type = ?';
    $params[] = $type;
}
if (!empty($_GET['q'])) {
    $q = clean_text($_GET['q'], 60);
    // LIKE (not FULLTEXT) so short words like "dal" still match; escape wildcards
    $like     = '%' . addcslashes($q, '%_\\') . '%';
    $where[]  = '(m.name LIKE ? OR m.description LIKE ?)';
    array_push($params, $like, $like);
}
if (!empty($_GET['featured'])) {
    $where[] = 'm.is_featured = 1';
}
if (!empty($_GET['special'])) {
    $where[] = 'm.is_special = 1';
}

$items = api_menu_items(implode(' AND ', $where), $params);

json_response(['items' => $items, 'count' => count($items)]);
