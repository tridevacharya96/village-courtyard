<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** Testimonials shown on the homepage: order, show/hide, edit. */
require_permission('testimonials.manage');
$rows = db_all('SELECT * FROM testimonials ORDER BY sort_order, id');

admin_header('Testimonials', 'testimonials.php');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0"><i class="bi bi-arrows-move"></i> Drag to set the order. Only publish words guests actually wrote to you or on review sites.</p>
  <a class="btn btn-primary btn-sm" href="testimonial-edit.php"><i class="bi bi-plus-lg me-1"></i>Add testimonial</a>
</div>
<section class="panel">
  <ul class="section-list" data-sortable="testimonials">
    <?php foreach ($rows as $t): ?>
      <li data-row data-id="<?= (int) $t['id'] ?>" class="<?= (int) $t['is_active'] ? '' : 'row-off' ?>">
        <?= drag_handle() ?>
        <?= admin_thumb($t['photo'], 'thumb', $t['name']) ?>
        <div class="s-body">
          <a class="fw-bold" href="testimonial-edit.php?id=<?= (int) $t['id'] ?>"><?= e($t['name']) ?></a>
          <span class="text-muted small"><?= e($t['designation']) ?> · <span aria-label="<?= (int) $t['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $t['rating']) . str_repeat('☆', 5 - (int) $t['rating']) ?></span></span>
          <small>“<?= e($t['message']) ?>”</small>
        </div>
        <?= row_toggle('testimonials', (int) $t['id'], 'is_active', (bool) $t['is_active'], 'Show on the website') ?>
        <a class="btn btn-sm btn-outline-primary" href="testimonial-edit.php?id=<?= (int) $t['id'] ?>">Edit</a>
        <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="testimonials" data-id="<?= (int) $t['id'] ?>" data-name="<?= e($t['name']) ?>’s testimonial" aria-label="Delete"><i class="bi bi-trash"></i></button>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-chat-quote"></i>No testimonials yet.</div><?php endif; ?>
</section>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
