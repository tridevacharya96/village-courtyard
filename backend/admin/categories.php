<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Menu categories: order (drag), show/hide, edit, delete when empty.
 */
require_permission('menu.manage');
$canDelete = can('menu.delete');
$cats = db_all('SELECT c.*, (SELECT COUNT(*) FROM menu_items m WHERE m.category_id = c.id) AS items FROM categories c ORDER BY c.sort_order, c.name');

admin_header('Categories', 'categories.php');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0"><i class="bi bi-arrows-move"></i> Drag to set the order categories appear on the menu page.</p>
  <a class="btn btn-primary btn-sm" href="category-edit.php"><i class="bi bi-plus-lg me-1"></i>Add category</a>
</div>
<section class="panel">
  <ul class="section-list" data-sortable="categories">
    <?php foreach ($cats as $c): ?>
      <li data-row data-id="<?= (int) $c['id'] ?>" class="<?= (int) $c['is_active'] ? '' : 'row-off' ?>">
        <?= drag_handle() ?>
        <?= admin_thumb($c['image'], 'thumb', $c['name']) ?>
        <div class="s-body"><a class="fw-bold" href="category-edit.php?id=<?= (int) $c['id'] ?>"><?= e($c['name']) ?></a>
          <small><?= (int) $c['items'] ?> dish<?= (int) $c['items'] === 1 ? '' : 'es' ?><?= $c['description'] ? ' · ' . e($c['description']) : '' ?></small></div>
        <span class="small text-muted d-none d-sm-inline">Shown</span>
        <?= row_toggle('categories', (int) $c['id'], 'is_active', (bool) $c['is_active'], 'Show this category on the website') ?>
        <a class="btn btn-sm btn-outline-primary" href="menu-items.php?category=<?= (int) $c['id'] ?>">Dishes</a>
        <a class="btn btn-sm btn-outline-primary" href="category-edit.php?id=<?= (int) $c['id'] ?>">Edit</a>
        <?php if ($canDelete): ?>
          <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="categories" data-id="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>"
                  data-body="Only empty categories can be deleted." aria-label="Delete <?= e($c['name']) ?>"><i class="bi bi-trash"></i></button>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$cats): ?><div class="empty-state"><i class="bi bi-tags"></i>No categories yet.</div><?php endif; ?>
</section>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
