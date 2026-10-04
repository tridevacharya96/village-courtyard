<?php
/**
 * Public API helpers: guards and the JSON shapes returned to the React app.
 */

declare(strict_types=1);

/**
 * Standard opening for a public endpoint.
 *   api_endpoint('GET');                 // read endpoint
 *   api_endpoint('POST', maintenance: false)  // payment callbacks must work in maintenance
 */
function api_endpoint(string|array $methods, bool $maintenance = true): void
{
    require_method(...(array) $methods);
    if ($maintenance && setting('maintenance_mode', false)) {
        header('Retry-After: 3600');
        json_error((string) setting('maintenance_message', 'We will be back shortly.'), 503, ['maintenance' => true]);
    }
}

/** Cache headers for read-only endpoints (lets browsers/CDNs cache briefly). */
function api_cache(int $seconds = 60): void
{
    header_remove('Cache-Control');
    header('Cache-Control: public, max-age=' . $seconds);
}

/** Run a callback and convert domain exceptions into JSON errors. */
function api_handle(callable $fn): never
{
    try {
        $fn();
    } catch (OrderException|ReservationException $e) {
        json_error($e->getMessage(), $e->getCode() ?: 422, $e->errors() ? ['errors' => $e->errors()] : []);
    } catch (RazorpayException $e) {
        json_error('The payment gateway is not responding. Please try again or choose Cash on Delivery.', 502);
    }
    json_error('No response.', 500);
}

// ---------------------------------------------------------------------
//  Serializers
// ---------------------------------------------------------------------

function api_menu_item(array $r): array
{
    return [
        'id'           => (int) $r['id'],
        'category_id'  => (int) $r['category_id'],
        'category'     => $r['category_name'] ?? null,
        'category_slug'=> $r['category_slug'] ?? null,
        'name'         => $r['name'],
        'slug'         => $r['slug'],
        'description'  => $r['description'],
        'price'        => (float) $r['price'],
        'image'        => upload_url($r['image']),
        'food_type'    => $r['food_type'],
        'spice_level'  => (int) $r['spice_level'],
        'prep_minutes' => (int) $r['prep_minutes'],
        'is_featured'  => (bool) $r['is_featured'],
        'is_special'   => (bool) $r['is_special'],
    ];
}

/** Base query for menu items visible to the public. */
const API_MENU_SQL = 'SELECT m.*, c.name AS category_name, c.slug AS category_slug
                        FROM menu_items m JOIN categories c ON c.id = m.category_id
                       WHERE m.is_available = 1 AND c.is_active = 1';

function api_menu_items(string $extraWhere = '', array $params = [], int $limit = 0): array
{
    $sql = API_MENU_SQL . ($extraWhere ? " AND ($extraWhere)" : '') . ' ORDER BY c.sort_order, m.sort_order, m.name';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    return array_map('api_menu_item', db_all($sql, $params));
}

function api_category(array $r): array
{
    return [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'slug'        => $r['slug'],
        'description' => $r['description'],
        'image'       => upload_url($r['image']),
        'item_count'  => (int) ($r['item_count'] ?? 0),
    ];
}

function api_gallery_image(array $r): array
{
    return [
        'id'       => (int) $r['id'],
        'image'    => upload_url($r['image']),
        'caption'  => $r['caption'],
        'category' => $r['category_name'] ?? null,
        'category_slug' => $r['category_slug'] ?? null,
    ];
}

function api_gallery(?string $categorySlug = null, int $limit = 0): array
{
    $sql = 'SELECT g.*, gc.name AS category_name, gc.slug AS category_slug
              FROM gallery_images g LEFT JOIN gallery_categories gc ON gc.id = g.category_id
             WHERE g.is_active = 1';
    $params = [];
    if ($categorySlug) {
        $sql .= ' AND gc.slug = ?';
        $params[] = $categorySlug;
    }
    $sql .= ' ORDER BY g.sort_order, g.id DESC' . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
    return array_map('api_gallery_image', db_all($sql, $params));
}

function api_testimonials(int $limit = 0): array
{
    $sql = 'SELECT * FROM testimonials WHERE is_active = 1 ORDER BY sort_order, id' . ($limit > 0 ? ' LIMIT ' . (int) $limit : '');
    return array_map(static fn ($r) => [
        'id'          => (int) $r['id'],
        'name'        => $r['name'],
        'designation' => $r['designation'],
        'message'     => $r['message'],
        'rating'      => (int) $r['rating'],
        'photo'       => upload_url($r['photo']),
    ], db_all($sql));
}

function api_hero_slides(): array
{
    return array_map(static fn ($r) => [
        'id'         => (int) $r['id'],
        'image'      => upload_url($r['image']),
        'heading'    => $r['heading'],
        'subheading' => $r['subheading'],
        'buttons'    => array_values(array_filter([
            $r['btn1_text'] ? ['text' => $r['btn1_text'], 'link' => $r['btn1_link'], 'style' => 'primary'] : null,
            $r['btn2_text'] ? ['text' => $r['btn2_text'], 'link' => $r['btn2_link'], 'style' => 'outline'] : null,
        ])),
    ], db_all('SELECT * FROM hero_slides WHERE is_active = 1 ORDER BY sort_order, id'));
}

/** Pages that appear in the site navigation. */
function api_nav_pages(): array
{
    return array_map(static fn ($r) => ['title' => $r['title'], 'slug' => $r['slug']], db_all(
        'SELECT title, slug FROM pages WHERE is_published = 1 AND show_in_menu = 1 ORDER BY menu_order, title'
    ));
}

function api_reservation(array $r): array
{
    return [
        'reference'        => $r['reference'],
        'name'             => $r['name'],
        'date'             => $r['reservation_date'],
        'time'             => substr($r['reservation_time'], 0, 5),
        'guests'           => (int) $r['guests'],
        'table_preference' => $r['table_preference'],
        'status'           => $r['status'],
    ];
}
