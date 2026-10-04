<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';
require __DIR__ . '/includes/homepage-schema.php';

/** Add or edit a hero slide. */
$user  = require_permission('homepage.manage');
$id    = (int) ($_GET['id'] ?? 0);
$slide = $id ? db_one('SELECT * FROM hero_slides WHERE id = ?', [$id]) : null;
if ($id && !$slide) {
    redirect(admin_url('homepage-section.php?key=hero'));
}
$slide ??= ['image' => null, 'heading' => '', 'subheading' => '', 'btn1_text' => 'View Menu', 'btn1_link' => '/menu', 'btn2_text' => 'Reserve a Table', 'btn2_link' => '/reservations', 'is_active' => 1];
$errors = [];

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'heading'    => 'required|string|max:150',
        'subheading' => 'nullable|string|max:255',
        'btn1_text'  => 'nullable|string|max:50',
        'btn1_link'  => 'nullable|url|max:255',
        'btn2_text'  => 'nullable|string|max:50',
        'btn2_link'  => 'nullable|url|max:255',
        'is_active'  => 'boolean',
    ], ['btn1_link' => 'First button link', 'btn2_link' => 'Second button link']);
    $errors = $v->errors();
    $d = $v->validated();
    $image = save_image_field('image', 'slides', $slide['image'], $errors);
    if (!$image && !isset($errors['image'])) {
        $errors['image'] = 'Every slide needs a background image.';
    }
    if (!$errors) {
        $data = ['image' => $image] + array_intersect_key($d, array_flip(['heading', 'subheading', 'btn1_text', 'btn1_link', 'btn2_text', 'btn2_link', 'is_active']));
        if ($id) {
            db_update('hero_slides', $data, 'id = ?', [$id]);
        } else {
            $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM hero_slides');
            $id = db_insert('hero_slides', $data);
        }
        log_activity('update', 'slide', $id, "Saved hero slide: {$d['heading']}", (int) $user['id']);
        flash('success', 'Slide saved.');
        redirect(admin_url('homepage-section.php?key=hero'));
    }
    $slide['image'] = $image;
}

admin_header($id ? 'Edit slide' : 'Add slide', 'homepage.php');
?>
<a class="small" href="homepage-section.php?key=hero"><i class="bi bi-arrow-left"></i> Hero slider</a>
<form method="post" enctype="multipart/form-data" novalidate class="form-layout">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-body">
      <?= field_input('heading', 'Heading', $slide['heading'], $errors, ['required' => true, 'maxlength' => 150]) ?>
      <?= field_input('subheading', 'Subheading', $slide['subheading'], $errors, ['maxlength' => 255, 'optional' => true]) ?>
      <div class="row g-3">
        <div class="col-sm-6"><?= field_input('btn1_text', 'First button text', $slide['btn1_text'], $errors, ['maxlength' => 50, 'optional' => true]) ?></div>
        <div class="col-sm-6"><?= field_input('btn1_link', 'First button link', $slide['btn1_link'], $errors, ['placeholder' => '/menu']) ?></div>
        <div class="col-sm-6"><?= field_input('btn2_text', 'Second button text', $slide['btn2_text'], $errors, ['maxlength' => 50, 'optional' => true]) ?></div>
        <div class="col-sm-6"><?= field_input('btn2_link', 'Second button link', $slide['btn2_link'], $errors, ['placeholder' => '/reservations']) ?></div>
      </div>
      <p class="form-text mb-0">Links can be a page on this site (/menu, /reservations, /gallery) or a full https:// address. Leave a button's text empty to hide it.</p>
    </div>
    <?= form_actions('homepage-section.php?key=hero', 'Save slide') ?>
  </section>
  <aside class="d-grid gap-3">
    <section class="panel"><div class="panel-body">
      <?= field_image('image', 'Background image', $slide['image'], $errors, ['required' => true, 'help' => 'Landscape, at least 1920 × 1080. Text sits on the left, so keep the subject centre or right.']) ?>
      <?= field_switch('is_active', 'Show this slide', $slide['is_active'], ['wrap' => 'mb-0']) ?>
    </div></section>
  </aside>
</form>
<?php admin_footer(['js/crud.js']);
