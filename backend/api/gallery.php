<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/gallery.php            all images + gallery categories
 * GET /api/gallery.php?category=food
 */
api_endpoint('GET');
api_cache(120);

$category = !empty($_GET['category']) ? clean_text($_GET['category'], 70) : null;

json_response([
    'categories' => array_map(static fn ($c) => ['name' => $c['name'], 'slug' => $c['slug']],
        db_all('SELECT name, slug FROM gallery_categories ORDER BY sort_order, name')),
    'images'     => api_gallery($category),
]);
