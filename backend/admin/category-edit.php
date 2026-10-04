<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** Add or edit a menu category. */
$user = require_permission('menu.manage');
$id   = (int) ($_GET['id'] ?? 0);
$cat  = $id ? db_one('SELECT * FROM categories WHERE id = ?', [$id]) : null;
if ($id && !$cat) {
    redirect(admin_url('categories.php'));
}
$cat ??= ['name' => '', 'description' => '', 'image' => null, 'is_active' => 1];
$errors = [];

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'name'        => 'required|string|min:2|max:80',
        'description' => 'nullable|string|max:255',
        'is_active'   => 'boolean',
    ]);
    $errors = $v->errors();
    $d = $v->validated();
    $image = save_image_field('image', 'menu', $cat['image'], $errors);

    if (!$errors) {
        $data = ['name' => $d['name'], 'description' => $d['description'] ?: null, 'image' => $image, 'is_active' => $d['is_active']];
        if ($id) {
            if ($d['name'] !== $cat['name']) {
                $data['slug'] = unique_slug('categories', $d['name'], $id);
            }
            db_update('categories', $data, 'id = ?', [$id]);
            log_activity('update', 'category', $id, "Updated category {$d['name']}", (int) $user['id']);
        } else {
            $data['slug'] = unique_slug('categories', $d['name']);
            $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories');
            $id = db_insert('categories', $data);
            log_activity('create', 'category', $id, "Added category {$d['name']}", (int) $user['id']);
        }
        flash('success', "Category {$d['name']} saved.");
        redirect(admin_url('categories.php'));
    }
    $cat['image'] = $image;
}

admin_header($id ? 'Edit category' : 'Add category', 'categories.php');
?>
<a class="small" href="categories.php"><i class="bi bi-arrow-left"></i> Categories</a>
<form method="post" enctype="multipart/form-data" novalidate class="panel" style="max-width:720px">
  <?= csrf_field() ?>
  <div class="panel-body">
    <?= field_input('name', 'Name', $cat['name'], $errors, ['required' => true, 'maxlength' => 80, 'placeholder' => 'Village Mains']) ?>
    <?= field_input('description', 'Short description', $cat['description'], $errors, ['maxlength' => 255, 'optional' => true]) ?>
    <?= field_image('image', 'Category image', $cat['image'], $errors) ?>
    <?= field_switch('is_active', 'Show this category on the website', $cat['is_active'], ['wrap' => 'mb-0']) ?>
  </div>
  <?= form_actions('categories.php', 'Save category') ?>
</form>
<?php admin_footer(['js/crud.js']);
