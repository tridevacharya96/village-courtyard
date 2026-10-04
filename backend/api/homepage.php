<?php
declare(strict_types=1);
define('APP_CONTEXT', 'api');
require __DIR__ . '/../includes/bootstrap.php';

/**
 * GET /api/homepage.php
 * Visible homepage sections in admin-defined order. Each section carries its
 * editable text (title, subtitle, content JSON, image) plus the live data it
 * displays (slides, dishes, gallery, testimonials, hours).
 */
api_endpoint('GET');
api_cache(60);

$sections = [];
foreach (db_all('SELECT * FROM homepage_sections WHERE is_visible = 1 ORDER BY sort_order, id') as $s) {
    $content = json_decode((string) $s['content'], true) ?: [];
    $limit   = max(1, min(24, (int) ($content['limit'] ?? 6)));

    $data = match ($s['section_key']) {
        'hero'         => ['slides' => api_hero_slides()],
        'signature'    => ['items' => api_menu_items('m.is_featured = 1', [], $limit)],
        'specials'     => ['items' => !empty($content['show_items']) ? api_menu_items('m.is_special = 1', [], $limit) : []],
        'gallery'      => ['images' => api_gallery(null, $limit)],
        'testimonials' => ['testimonials' => api_testimonials($limit)],
        'hours'        => [
            'opening_hours' => setting('opening_hours', []),
            'address'       => setting('address'),
            'phone'         => setting('phone'),
            'email'         => setting('email'),
            'map_embed_url' => setting('map_embed_url'),
        ],
        default        => [],
    };

    $sections[] = [
        'key'      => $s['section_key'],
        'title'    => $s['title'],
        'subtitle' => $s['subtitle'],
        'content'  => $content,
        'image'    => upload_url($s['image']),
        'data'     => $data,
    ];
}

json_response(['sections' => $sections]);
