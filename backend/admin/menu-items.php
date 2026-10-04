<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Menu: all dishes with quick switches.
 * Staff (menu.view) can mark dishes sold out; editing needs menu.manage.
 */
require_permission('menu.view');
$canManage = can('menu.manage');
$canDelete = can('menu.delete');

$cat   = (int) ($_GET['category'] ?? 0);
$type  = (string) ($_GET['type'] ?? '');
$avail = $_GET['available'] ?? '';
$q     = clean_text($_GET['q'] ?? '', 60);

$where = ['1=1'];
$params = [];
if ($cat) { $where[] = 'm.category_id = ?'; $params[] = $cat; }
if (in_array($type, ['veg', 'non_veg', 'egg'], true)) { $where[] = 'm.food_type = ?'; $params[] = $type; }
if ($avail === '0' || $avail === '1') { $where[] = 'm.is_available = ?'; $params[] = (int) $avail; }
if ($q !== '') { $where[] = '(m.name LIKE ? OR m.description LIKE ?)'; $like = '%' . addcslashes($q, '%_\\') . '%'; array_push($params, $like, $like); }

$items = db_all(
    'SELECT m.*, c.name AS category_name,
            (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE oi.menu_item_id = m.id AND o.status <> \'cancelled\' AND o.created_at > NOW() - INTERVAL 30 DAY) AS sold_30d
       FROM menu_items m JOIN categories c ON c.id = m.category_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY c.sort_order, m.sort_order, m.name',
    $params
);
$categories = db_all('SELECT id, name FROM categories ORDER BY sort_order, name');
$counts = db_one('SELECT COUNT(*) total, SUM(is_available = 0) off, SUM(is_featured) featured, SUM(is_special) special FROM menu_items');

// Drag-to-reorder only makes sense within one category and without a search
$sortable = $canManage && $cat && $q === '' && $type === '' && $avail === '';

admin_header('Menu', 'menu-items.php');
?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
  <p class="text-muted mb-0 small">
    <?= (int) $counts['total'] ?> dishes · <?= (int) $counts['off'] ?> sold out ·
    <?= (int) $counts['featured'] ?> signature (homepage) · <?= (int) $counts['special'] ?> today's specials
  </p>
  <?php if ($canManage): ?>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-primary btn-sm" href="categories.php"><i class="bi bi-tags me-1"></i>Categories</a>
      <a class="btn btn-primary btn-sm" href="menu-item-edit.php<?= $cat ? '?category=' . $cat : '' ?>"><i class="bi bi-plus-lg me-1"></i>Add dish</a>
    </div>
  <?php endif; ?>
</div>

<section class="panel">
  <form class="panel-body filter-bar" method="get" role="search">
    <div><label class="form-label small mb-1" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Dish name or description"></div>
    <div><label class="form-label small mb-1" for="category">Category</label>
      <select class="form-select form-select-sm" id="category" name="category"><option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $cat === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="form-label small mb-1" for="type">Type</label>
      <select class="form-select form-select-sm" id="type" name="type">
        <?php foreach (['' => 'Veg & non-veg', 'veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Contains egg'] as $k => $l): ?><option value="<?= $k ?>"<?= $type === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="form-label small mb-1" for="available">Availability</label>
      <select class="form-select form-select-sm" id="available" name="available">
        <?php foreach (['' => 'All', '1' => 'Available', '0' => 'Sold out / hidden'] as $k => $l): ?><option value="<?= $k ?>"<?= (string) $avail === (string) $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div class="actions"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="menu-items.php">Reset</a></div>
  </form>
  <?php if ($canManage && !$sortable): ?>
    <div class="panel-foot"><span><i class="bi bi-arrows-move"></i> To change the order dishes appear in, pick one category (with no other filters) and drag the rows.</span></div>
  <?php endif; ?>
</section>

<section class="panel">
  <?php if (!$items): ?>
    <div class="empty-state"><i class="bi bi-journal-x"></i>No dishes match. <?php if ($canManage): ?><a href="menu-item-edit.php">Add a dish</a>.<?php endif; ?></div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr>
        <?php if ($sortable): ?><th class="p-0" style="width:2rem"></th><?php endif; ?>
        <th>Dish</th><th>Category</th><th class="text-end">Price</th><th class="text-end">Sold (30 days)</th>
        <th class="text-center">Available</th><th class="text-center">Signature</th><th class="text-center">Special</th>
        <?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?>
      </tr></thead>
      <tbody<?= $sortable ? ' data-sortable="menu_items"' : '' ?>>
      <?php foreach ($items as $m): ?>
        <tr data-row data-id="<?= (int) $m['id'] ?>" class="<?= (int) $m['is_available'] ? '' : 'row-off' ?>">
          <?php if ($sortable): ?><td class="p-0 ps-2"><?= drag_handle() ?></td><?php endif; ?>
          <td>
            <div class="d-flex align-items-center gap-3">
              <?= admin_thumb($m['image'], 'thumb', $m['name']) ?>
              <div class="min-w-0">
                <span class="food-mark food-<?= e($m['food_type']) ?>" title="<?= e(['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Contains egg'][$m['food_type']]) ?>"></span>
                <?php if ($canManage): ?><a class="fw-bold" href="menu-item-edit.php?id=<?= (int) $m['id'] ?>"><?= e($m['name']) ?></a><?php else: ?><b><?= e($m['name']) ?></b><?php endif; ?>
                <small class="d-block text-muted dish-desc"><?= e($m['description']) ?></small>
              </div>
            </div>
          </td>
          <td class="small"><?= e($m['category_name']) ?></td>
          <td class="text-end tabular fw-bold"><?= money($m['price']) ?></td>
          <td class="text-end tabular"><?= (int) $m['sold_30d'] ?></td>
          <td class="text-center"><?= row_toggle('menu_items', (int) $m['id'], 'is_available', (bool) $m['is_available'], 'Available to order') ?></td>
          <td class="text-center"><?= row_toggle('menu_items', (int) $m['id'], 'is_featured', (bool) $m['is_featured'], 'Show as a signature dish on the homepage', $canManage) ?></td>
          <td class="text-center"><?= row_toggle('menu_items', (int) $m['id'], 'is_special', (bool) $m['is_special'], "Show in today's specials", $canManage) ?></td>
          <?php if ($canManage): ?>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="menu-item-edit.php?id=<?= (int) $m['id'] ?>">Edit</a>
            <?php if ($canDelete): ?>
              <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="menu_items" data-id="<?= (int) $m['id'] ?>" data-name="<?= e($m['name']) ?>"
                      data-body="Past orders keep the dish name and price. To hide it temporarily, switch off Available instead." aria-label="Delete <?= e($m['name']) ?>"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
