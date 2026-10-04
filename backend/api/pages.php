<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/pages.php             pages shown in the navigation
 * GET /api/pages.php?slug=about  one published page (CMS content is sanitised on save)
 */
api_endpoint('GET');
api_cache(120);

if (!empty($_GET['slug'])) {
    $page = db_one('SELECT * FROM pages WHERE slug = ? AND is_published = 1 LIMIT 1', [clean_text($_GET['slug'], 160)]);
    if (!$page) {
        json_error('Page not found.', 404);
    }
    json_response(['page' => [
        'title'            => $page['title'],
        'slug'             => $page['slug'],
        'content'          => $page['content'],
        'banner_image'     => upload_url($page['banner_image']),
        'meta_title'       => $page['meta_title'] ?: $page['title'],
        'meta_description' => $page['meta_description'],
        'updated_at'       => date(DATE_ATOM, strtotime($page['updated_at'])),
    ]]);
}

json_response(['pages' => api_nav_pages()]);
