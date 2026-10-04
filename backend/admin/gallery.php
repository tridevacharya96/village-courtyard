<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Gallery: upload several photos at once, caption and categorise them,
 * drag to reorder, hide or delete. Also manages gallery categories.
 */
$user = require_permission('gallery.manage');
$errors = [];

if (is_post()) {
    verify_csrf();
    $form = (string) ($_POST['form'] ?? '');

    if ($form === 'upload') {
        $catId = (int) ($_POST['category_id'] ?? 0) ?: null;
        if ($catId && !db_value('SELECT id FROM gallery_categories WHERE id = ?', [$catId])) {
            $catId = null;
        }
        $result = handle_multiple_uploads($_FILES['images'] ?? null, 'gallery');
        $caption = clean_text($_POST['caption'] ?? '', 200) ?: null;
        $order = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM gallery_images');
        foreach ($result['paths'] as $path) {
            db_insert('gallery_images', ['category_id' => $catId, 'image' => $path, 'caption' => $caption, 'sort_order' => ++$order]);
        }
        $n = count($result['paths']);
        if ($n) {
            log_activity('create', 'photo', null, "Uploaded $n gallery photo" . ($n > 1 ? 's' : ''), (int) $user['id']);
            flash('success', "$n photo" . ($n > 1 ? 's' : '') . ' added to the gallery.');
        }
        foreach ($result['errors'] as $err) {
            flash('danger', $err);
        }
        if (!$n && !$result['errors']) {
            flash('warning', 'Choose at least one photo to upload.');
        }
        redirect(admin_url('gallery.php' . ($catId ? "?category=$catId" : '')));
    }

    if ($form === 'category') {
        $catId = (int) ($_POST['id'] ?? 0);
        $name = clean_text($_POST['name'] ?? '', 60);
        if (mb_strlen($name) < 2) {
            flash('danger', 'Category names need at least 2 characters.');
        } elseif ($catId) {
            db_update('gallery_categories', ['name' => $name, 'slug' => unique_slug('gallery_categories', $name, $catId)], 'id = ?', [$catId]);
            flash('success', "Renamed to $name.");
        } else {
            db_insert('gallery_categories', ['name' => $name, 'slug' => unique_slug('gallery_categories', $name), 'sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM gallery_categories')]);
            flash('success', "Gallery category $name added.");
        }
        log_activity('update', 'gallery_category', $catId ?: null, "Saved gallery category $name", (int) $user['id']);
        redirect(admin_url('gallery.php'));
    }
}

$categories = db_all('SELECT gc.*, (SELECT COUNT(*) FROM gallery_images g WHERE g.category_id = gc.id) AS photos FROM gallery_categories gc ORDER BY sort_order, name');
$filter = $_GET['category'] ?? '';
$where = '1=1';
$params = [];
if ($filter === 'none') {
    $where = 'g.category_id IS NULL';
} elseif ((int) $filter) {
    $where = 'g.category_id = ?';
    $params[] = (int) $filter;
}
$images = db_all("SELECT g.* FROM gallery_images g WHERE $where ORDER BY g.sort_order, g.id DESC", $params);
$total = (int) db_value('SELECT COUNT(*) FROM gallery_images');
$catOptions = ['' => 'No category'] + array_column($categories, 'name', 'id');

admin_header('Gallery', 'gallery.php');
?>
<div class="form-layout">
  <form class="panel" method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><input type="hidden" name="form" value="upload">
    <div class="panel-head"><h2>Add photos</h2></div>
    <div class="panel-body">
      <div class="mb-3">
        <label class="form-label" for="images">Photos</label>
        <input class="form-control" type="file" id="images" name="images[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple required>
        <div class="form-text">Select several at once. JPG, PNG or WEBP, up to <?= round(UPLOAD_MAX_BYTES / 1048576) ?> MB each.</div>
      </div>
      <div class="row g-3">
        <div class="col-sm-6"><?= field_select('category_id', 'Category', (string) ((int) $filter ?: ''), $catOptions, [], ['wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('caption', 'Caption for these photos', '', [], ['maxlength' => 200, 'optional' => true, 'wrap' => '']) ?></div>
      </div>
    </div>
    <div class="panel-foot justify-content-end"><button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1"></i>Upload</button></div>
  </form>

  <section class="panel">
    <div class="panel-head"><h2>Categories</h2></div>
    <ul class="list-group list-group-flush" data-sortable="gallery_categories">
      <?php foreach ($categories as $c): ?>
        <li class="list-group-item d-flex align-items-center gap-2" data-row data-id="<?= (int) $c['id'] ?>">
          <?= drag_handle() ?>
          <form method="post" class="d-flex gap-2 flex-grow-1 min-w-0">
            <?= csrf_field() ?><input type="hidden" name="form" value="category"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <input class="form-control form-control-sm" name="name" value="<?= e($c['name']) ?>" maxlength="60" aria-label="Category name">
            <button class="btn btn-sm btn-outline-primary" type="submit">Rename</button>
          </form>
          <span class="small text-muted text-nowrap"><?= (int) $c['photos'] ?></span>
          <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="gallery_categories" data-id="<?= (int) $c['id'] ?>" data-name="<?= e($c['name']) ?>"
                  data-body="Its photos stay in the gallery without a category." aria-label="Delete <?= e($c['name']) ?>"><i class="bi bi-trash"></i></button>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" class="panel-body d-flex gap-2 border-top">
      <?= csrf_field() ?><input type="hidden" name="form" value="category">
      <input class="form-control form-control-sm" name="name" placeholder="New category, e.g. Festivals" maxlength="60" aria-label="New category name">
      <button class="btn btn-sm btn-primary text-nowrap" type="submit">Add</button>
    </form>
  </section>
</div>

<nav class="status-tabs" aria-label="Filter by category">
  <a href="gallery.php" class="<?= $filter === '' ? 'active' : '' ?>">All<span class="count"><?= $total ?></span></a>
  <?php foreach ($categories as $c): ?>
    <a href="gallery.php?category=<?= (int) $c['id'] ?>" class="<?= (int) $filter === (int) $c['id'] ? 'active' : '' ?>"><?= e($c['name']) ?><span class="count"><?= (int) $c['photos'] ?></span></a>
  <?php endforeach; ?>
  <a href="gallery.php?category=none" class="<?= $filter === 'none' ? 'active' : '' ?>">No category</a>
</nav>

<?php if (!$images): ?>
  <div class="panel empty-state"><i class="bi bi-images"></i>No photos here yet.</div>
<?php else: ?>
  <p class="small text-muted mb-0"><i class="bi bi-arrows-move"></i> Drag photos by the handle to change their order on the website. Captions save when you leave the field.</p>
  <div class="gallery-grid" data-sortable="gallery_images">
    <?php foreach ($images as $g): ?>
      <div class="gallery-card<?= (int) $g['is_active'] ? '' : ' row-off' ?>" data-row data-id="<?= (int) $g['id'] ?>">
        <?= admin_thumb($g['image'], 'thumb-lg', $g['caption'] ?? 'Gallery photo') ?>
        <div class="g-body">
          <input class="form-control form-control-sm js-gallery-field" data-field="caption" data-id="<?= (int) $g['id'] ?>" value="<?= e($g['caption']) ?>" maxlength="200" placeholder="Add a caption" aria-label="Caption">
          <select class="form-select form-select-sm js-gallery-field" data-field="category_id" data-id="<?= (int) $g['id'] ?>" aria-label="Category">
            <?php foreach ($catOptions as $val => $label): ?><option value="<?= e((string) $val) ?>"<?= (string) $val === (string) ($g['category_id'] ?? '') ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="g-foot">
          <?= drag_handle() ?>
          <span class="d-flex align-items-center gap-1 small text-muted">Shown <?= row_toggle('gallery_images', (int) $g['id'], 'is_active', (bool) $g['is_active'], 'Show on the website') ?></span>
          <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="gallery_images" data-id="<?= (int) $g['id'] ?>" data-name="this photo" aria-label="Delete photo"><i class="bi bi-trash"></i></button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<script>
// Caption / category edits save on change
document.addEventListener('change', (e) => {
  const el = e.target.closest('.js-gallery-field');
  if (!el) return;
  VC.post('ajax/gallery-update.php', { id: el.dataset.id, field: el.dataset.field, value: el.value }).then((res) => VC.toast(res.message));
});
</script>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
