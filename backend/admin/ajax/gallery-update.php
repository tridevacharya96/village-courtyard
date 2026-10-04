<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../includes/admin.php';

/** POST ajax/gallery-update.php  id, field (caption|category_id), value */
$user = require_permission('gallery.manage');
admin_require_post();

$id    = (int) ($_POST['id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');
if (!db_value('SELECT id FROM gallery_images WHERE id = ?', [$id])) {
    json_error('Photo not found.', 404);
}

if ($field === 'caption') {
    $value = clean_text($_POST['value'] ?? '', 200) ?: null;
    db_update('gallery_images', ['caption' => $value], 'id = ?', [$id]);
    json_response(null, 200, 'Caption saved.');
}
if ($field === 'category_id') {
    $cat = (int) ($_POST['value'] ?? 0) ?: null;
    if ($cat && !db_value('SELECT id FROM gallery_categories WHERE id = ?', [$cat])) {
        json_error('Category not found.', 404);
    }
    db_update('gallery_images', ['category_id' => $cat], 'id = ?', [$id]);
    json_response(null, 200, 'Category updated.');
}
json_error('Unknown field.', 400);
