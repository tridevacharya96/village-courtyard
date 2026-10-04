<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Add or edit a dish.
 */
$user = require_permission('menu.manage');
$id   = (int) ($_GET['id'] ?? 0);
$item = $id ? db_one('SELECT * FROM menu_items WHERE id = ?', [$id]) : null;
if ($id && !$item) {
    flash('warning', 'That dish no longer exists.');
    redirect(admin_url('menu-items.php'));
}
$categories = db_all('SELECT id, name FROM categories ORDER BY sort_order, name');
if (!$categories) {
    flash('warning', 'Create a menu category first.');
    redirect(admin_url('category-edit.php'));
}
$item ??= ['category_id' => (int) ($_GET['category'] ?? $categories[0]['id']), 'food_type' => 'veg', 'spice_level' => 0, 'prep_minutes' => 20, 'is_available' => 1, 'is_featured' => 0, 'is_special' => 0, 'image' => null];
$errors = [];

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'name'         => 'required|string|min:2|max:120',
        'category_id'  => 'required|integer|exists:categories,id',
        'description'  => 'nullable|string|max:500',
        'price'        => 'required|numeric|between:0,100000',
        'food_type'    => 'required|in:veg,non_veg,egg',
        'spice_level'  => 'required|integer|between:0,3',
        'prep_minutes' => 'required|integer|between:1,240',
        'is_available' => 'boolean',
        'is_featured'  => 'boolean',
        'is_special'   => 'boolean',
    ], ['category_id' => 'Category', 'prep_minutes' => 'Preparation time']);
    $errors = $v->errors();
    $d = $v->validated();
    $image = save_image_field('image', 'menu', $item['image'] ?? null, $errors);

    if (!$errors) {
        $data = [
            'category_id' => $d['category_id'], 'name' => $d['name'], 'description' => $d['description'] ?: null,
            'price' => round((float) $d['price'], 2), 'image' => $image, 'food_type' => $d['food_type'],
            'spice_level' => $d['spice_level'], 'prep_minutes' => $d['prep_minutes'],
            'is_available' => $d['is_available'], 'is_featured' => $d['is_featured'], 'is_special' => $d['is_special'],
        ];
        if ($id) {
            if ($d['name'] !== $item['name']) {
                $data['slug'] = unique_slug('menu_items', $d['name'], $id);
            }
            db_update('menu_items', $data, 'id = ?', [$id]);
            log_activity('update', 'dish', $id, "Updated dish {$d['name']}" . ((float) $item['price'] !== (float) $data['price'] ? ' (price ' . money($item['price']) . ' → ' . money($data['price']) . ')' : ''), (int) $user['id']);
            flash('success', "{$d['name']} saved.");
        } else {
            $data['slug'] = unique_slug('menu_items', $d['name']);
            $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE category_id = ?', [$d['category_id']]);
            $id = db_insert('menu_items', $data);
            log_activity('create', 'dish', $id, "Added dish {$d['name']} at " . money($data['price']), (int) $user['id']);
            flash('success', "{$d['name']} added to the menu.");
        }
        redirect(admin_url('menu-items.php?category=' . $d['category_id']));
    }
    // Keep a freshly uploaded image if another field failed
    $item['image'] = $image;
}

$catOptions = array_column($categories, 'name', 'id');
admin_header($id ? 'Edit ' . $item['name'] : 'Add dish', 'menu-items.php');
?>
<a class="small" href="menu-items.php"><i class="bi bi-arrow-left"></i> Menu</a>
<form method="post" enctype="multipart/form-data" novalidate class="form-layout">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-head"><h2>Dish details</h2></div>
    <div class="panel-body">
      <?= field_input('name', 'Name', $item['name'] ?? '', $errors, ['required' => true, 'maxlength' => 120]) ?>
      <?= field_textarea('description', 'Description', $item['description'] ?? '', $errors, ['maxlength' => 500, 'optional' => true, 'help' => 'One or two sentences shown under the name on the menu.']) ?>
      <div class="row g-3">
        <div class="col-sm-6"><?= field_select('category_id', 'Category', $item['category_id'], $catOptions, $errors) ?></div>
        <div class="col-sm-6"><?= field_input('price', 'Price', $item['price'] ?? '', $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'required' => true, 'prefix' => '₹', 'help' => 'Before GST.']) ?></div>
        <div class="col-sm-4"><?= field_select('food_type', 'Type', $item['food_type'], ['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Contains egg'], $errors) ?></div>
        <div class="col-sm-4"><?= field_select('spice_level', 'Spice level', $item['spice_level'], ['0' => 'Not spicy', '1' => 'Mild', '2' => 'Medium', '3' => 'Hot'], $errors) ?></div>
        <div class="col-sm-4"><?= field_input('prep_minutes', 'Preparation time (min)', $item['prep_minutes'], $errors, ['type' => 'number', 'min' => 1, 'max' => 240]) ?></div>
      </div>
    </div>
    <?= form_actions('menu-items.php', $id ? 'Save dish' : 'Add dish') ?>
  </section>
  <aside class="d-grid gap-3">
    <section class="panel"><div class="panel-head"><h2>Photo</h2></div>
      <div class="panel-body"><?= field_image('image', 'Dish photo', $item['image'], $errors, ['wrap' => 'mb-0', 'help' => 'Landscape photos look best (4:3).']) ?></div></section>
    <section class="panel"><div class="panel-head"><h2>Visibility</h2></div>
      <div class="panel-body">
        <?= field_switch('is_available', 'Available to order', $item['is_available'], ['help' => 'Switch off when sold out. The dish is hidden from the website until switched back on.']) ?>
        <?= field_switch('is_featured', 'Signature dish', $item['is_featured'], ['help' => 'Shown in the homepage “Signature Dishes” section.']) ?>
        <?= field_switch('is_special', "Today's special", $item['is_special'], ['wrap' => 'mb-0', 'help' => 'Shown in the homepage specials banner.']) ?>
      </div></section>
  </aside>
</form>
<?php admin_footer(['js/crud.js']);
