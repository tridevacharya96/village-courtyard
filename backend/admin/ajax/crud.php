<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/**
 * Generic list actions for content modules, driven by an allow-list.
 *
 * POST ajax/crud.php
 *   action=toggle   resource, id, field, value(0|1)
 *   action=reorder  resource, ids[] (in the new order)
 *   action=delete   resource, id
 *
 * Only the tables, fields and permissions listed in RESOURCES can be touched.
 */
const RESOURCES = [
    'menu_items' => [
        'label' => 'dish', 'name' => 'name', 'manage' => 'menu.manage', 'delete' => 'menu.delete',
        'toggles' => ['is_available', 'is_featured', 'is_special'], 'sortable' => true, 'files' => ['image'],
        // Staff may switch dishes on/off during service (sold out) with menu.view
        'toggle_perms' => ['is_available' => 'menu.view'],
    ],
    'categories' => [
        'label' => 'category', 'name' => 'name', 'manage' => 'menu.manage', 'delete' => 'menu.delete',
        'toggles' => ['is_active'], 'sortable' => true, 'files' => ['image'],
        'guard' => ['SELECT COUNT(*) FROM menu_items WHERE category_id = ?', 'This category still has dishes. Move or delete them first.'],
    ],
    'hero_slides' => [
        'label' => 'slide', 'name' => 'heading', 'manage' => 'homepage.manage', 'delete' => 'homepage.manage',
        'toggles' => ['is_active'], 'sortable' => true, 'files' => ['image'],
    ],
    'homepage_sections' => [
        'label' => 'section', 'name' => 'section_key', 'manage' => 'homepage.manage', 'delete' => null,
        'toggles' => ['is_visible'], 'sortable' => true, 'files' => [],
    ],
    'gallery_images' => [
        'label' => 'photo', 'name' => 'caption', 'manage' => 'gallery.manage', 'delete' => 'gallery.manage',
        'toggles' => ['is_active'], 'sortable' => true, 'files' => ['image'],
    ],
    'gallery_categories' => [
        'label' => 'gallery category', 'name' => 'name', 'manage' => 'gallery.manage', 'delete' => 'gallery.manage',
        'toggles' => [], 'sortable' => true, 'files' => [],
    ],
    'testimonials' => [
        'label' => 'testimonial', 'name' => 'name', 'manage' => 'testimonials.manage', 'delete' => 'testimonials.manage',
        'toggles' => ['is_active'], 'sortable' => true, 'files' => ['photo'],
    ],
    'coupons' => [
        'label' => 'coupon', 'name' => 'code', 'manage' => 'coupons.manage', 'delete' => 'coupons.manage',
        'toggles' => ['is_active'], 'sortable' => false, 'files' => [],
        'guard' => ['SELECT COUNT(*) FROM orders WHERE coupon_id = ?', 'This coupon has been used on orders. Switch it off instead so order records stay complete.'],
    ],
    'pages' => [
        'label' => 'page', 'name' => 'title', 'manage' => 'pages.manage', 'delete' => 'pages.manage',
        'toggles' => ['is_published', 'show_in_menu'], 'sortable' => false, 'files' => ['banner_image'],
        'guard' => ['SELECT is_system FROM pages WHERE id = ?', 'This page is part of the site and can be edited but not deleted.'],
    ],
    'contacts' => [
        'label' => 'message', 'name' => 'name', 'manage' => 'contacts.view', 'delete' => 'contacts.delete',
        'toggles' => ['is_read'], 'sortable' => false, 'files' => [],
    ],
    'newsletter' => [
        'label' => 'subscriber', 'name' => 'email', 'manage' => 'newsletter.view', 'delete' => 'newsletter.view',
        'toggles' => ['is_active'], 'sortable' => false, 'files' => [],
    ],
];

require_login();
admin_require_post();

$resource = (string) ($_POST['resource'] ?? '');
$cfg = RESOURCES[$resource] ?? json_error('Unknown list.', 400);
$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);

$row = $id ? db_one("SELECT * FROM `$resource` WHERE id = ?", [$id]) : null;
$entity = str_replace(' ', '_', $cfg['label']);
$name = static fn (?array $r) => $r ? (string) ($r[$cfg['name']] ?: ucfirst($cfg['label']) . ' #' . $r['id']) : '';

switch ($action) {
    case 'toggle':
        $field = (string) ($_POST['field'] ?? '');
        if (!in_array($field, $cfg['toggles'], true)) {
            json_error('This setting cannot be changed here.', 400);
        }
        $user = require_permission($cfg['toggle_perms'][$field] ?? $cfg['manage']);
        if (!$row) {
            json_error(ucfirst($cfg['label']) . ' not found.', 404);
        }
        $value = !empty($_POST['value']) && $_POST['value'] !== '0' ? 1 : 0;
        db_update($resource, [$field => $value], 'id = ?', [$id]);

        if ($resource === 'newsletter') {
            db_update('newsletter', ['unsubscribed_at' => $value ? null : date('Y-m-d H:i:s')], 'id = ?', [$id]);
        }
        $fieldLabel = ['is_available' => 'available', 'is_featured' => 'signature dish', 'is_special' => "today's special",
            'is_active' => 'shown', 'is_visible' => 'visible', 'is_published' => 'published', 'show_in_menu' => 'in the navigation', 'is_read' => 'read'][$field] ?? $field;
        log_activity('update', $entity, $id, $name($row) . ': ' . ($value ? '' : 'not ') . $fieldLabel, (int) $user['id']);
        json_response(['value' => $value], 200, $name($row) . ' is ' . ($value ? 'now ' : 'no longer ') . $fieldLabel . '.');

    case 'reorder':
        $user = require_permission($cfg['manage']);
        if (!$cfg['sortable']) {
            json_error('This list cannot be reordered.', 400);
        }
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn ($v) => $v > 0));
        if (!$ids || count($ids) > 500) {
            json_error('Nothing to reorder.', 422);
        }
        db_transaction(static function () use ($ids, $resource) {
            foreach ($ids as $position => $rowId) {
                db_query("UPDATE `$resource` SET sort_order = ? WHERE id = ?", [$position + 1, $rowId]);
            }
        });
        log_activity('reorder', $entity, null, 'Reordered ' . $cfg['label'] . 's', (int) $user['id']);
        json_response(null, 200, 'New order saved.');

    case 'delete':
        if (!$cfg['delete']) {
            json_error('This item cannot be deleted.', 400);
        }
        $user = require_permission($cfg['delete']);
        if (!$row) {
            json_error(ucfirst($cfg['label']) . ' not found.', 404);
        }
        if (isset($cfg['guard']) && (int) db_value($cfg['guard'][0], [$id]) > 0) {
            json_error($cfg['guard'][1], 409);
        }
        db_query("DELETE FROM `$resource` WHERE id = ?", [$id]);
        foreach ($cfg['files'] as $f) {
            delete_upload($row[$f] ?? null);
        }
        log_activity('delete', $entity, $id, 'Deleted ' . $cfg['label'] . ' ' . $name($row), (int) $user['id']);
        json_response(null, 200, ucfirst($cfg['label']) . ' "' . $name($row) . '" deleted.');

    default:
        json_error('Unknown action.', 400);
}
