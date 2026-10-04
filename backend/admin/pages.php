<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** CMS pages: list, publish, show in navigation. */
require_permission('pages.manage');
$pages = db_all('SELECT p.*, u.name AS author FROM pages p LEFT JOIN users u ON u.id = p.created_by ORDER BY p.show_in_menu DESC, p.menu_order, p.title');
$site  = rtrim(FRONTEND_URL ?: '', '/');

admin_header('Pages', 'pages.php');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0">Pages switched into the navigation appear in the website menu, in the order set on each page.</p>
  <a class="btn btn-primary btn-sm" href="page-edit.php"><i class="bi bi-plus-lg me-1"></i>New page</a>
</div>
<section class="panel">
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Page</th><th>Address</th><th class="text-center">Published</th><th class="text-center">In navigation</th><th>Last edited</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($pages as $p): ?>
        <tr data-row class="<?= (int) $p['is_published'] ? '' : 'row-off' ?>">
          <td><a class="fw-bold" href="page-edit.php?id=<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
            <?php if ((int) $p['is_system']): ?><span class="badge text-bg-light border ms-1" title="Built-in page: can be edited, not deleted"><i class="bi bi-lock"></i> Built-in</span><?php endif; ?></td>
          <td class="small"><a href="<?= e($site . '/page/' . $p['slug']) ?>" target="_blank" rel="noopener">/page/<?= e($p['slug']) ?></a></td>
          <td class="text-center"><?= row_toggle('pages', (int) $p['id'], 'is_published', (bool) $p['is_published'], 'Published') ?></td>
          <td class="text-center"><?= row_toggle('pages', (int) $p['id'], 'show_in_menu', (bool) $p['show_in_menu'], 'Show in the website navigation') ?></td>
          <td class="small text-muted"><?= e(time_ago($p['updated_at'])) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="page-edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
            <?php if (!(int) $p['is_system']): ?>
              <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="pages" data-id="<?= (int) $p['id'] ?>" data-name="<?= e($p['title']) ?>" aria-label="Delete <?= e($p['title']) ?>"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php admin_footer(['js/crud.js']);
