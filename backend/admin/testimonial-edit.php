<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/** Add or edit a testimonial. */
$user = require_permission('testimonials.manage');
$id = (int) ($_GET['id'] ?? 0);
$t  = $id ? db_one('SELECT * FROM testimonials WHERE id = ?', [$id]) : null;
if ($id && !$t) {
    redirect(admin_url('testimonials.php'));
}
$t ??= ['name' => '', 'designation' => '', 'message' => '', 'rating' => 5, 'photo' => null, 'is_active' => 1];
$errors = [];

if (is_post()) {
    verify_csrf();
    $v = Validator::make($_POST, [
        'name'        => 'required|string|min:2|max:100',
        'designation' => 'nullable|string|max:100',
        'message'     => 'required|string|min:10|max:600',
        'rating'      => 'required|integer|between:1,5',
        'is_active'   => 'boolean',
    ], ['designation' => 'Who they are']);
    $errors = $v->errors();
    $d = $v->validated();
    $photo = save_image_field('photo', 'testimonials', $t['photo'], $errors);
    if (!$errors) {
        $data = ['name' => $d['name'], 'designation' => $d['designation'] ?: null, 'message' => $d['message'], 'rating' => $d['rating'], 'photo' => $photo, 'is_active' => $d['is_active']];
        if ($id) {
            db_update('testimonials', $data, 'id = ?', [$id]);
        } else {
            $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM testimonials');
            $id = db_insert('testimonials', $data);
        }
        log_activity('update', 'testimonial', $id, "Saved testimonial from {$d['name']}", (int) $user['id']);
        flash('success', 'Testimonial saved.');
        redirect(admin_url('testimonials.php'));
    }
    $t['photo'] = $photo;
}

admin_header($id ? 'Edit testimonial' : 'Add testimonial', 'testimonials.php');
?>
<a class="small" href="testimonials.php"><i class="bi bi-arrow-left"></i> Testimonials</a>
<form method="post" enctype="multipart/form-data" novalidate class="form-layout">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-body">
      <div class="row g-3">
        <div class="col-sm-6"><?= field_input('name', 'Guest name', $t['name'], $errors, ['required' => true, 'maxlength' => 100, 'wrap' => '']) ?></div>
        <div class="col-sm-6"><?= field_input('designation', 'Who they are', $t['designation'], $errors, ['maxlength' => 100, 'placeholder' => 'Regular guest, food blogger…', 'optional' => true, 'wrap' => '']) ?></div>
        <div class="col-12"><?= field_textarea('message', 'What they said', $t['message'], $errors, ['rows' => 4, 'maxlength' => 600, 'required' => true, 'wrap' => '']) ?></div>
        <div class="col-sm-4"><?= field_select('rating', 'Rating', (string) $t['rating'], ['5' => '★★★★★  5', '4' => '★★★★☆  4', '3' => '★★★☆☆  3', '2' => '★★☆☆☆  2', '1' => '★☆☆☆☆  1'], $errors, ['wrap' => '']) ?></div>
      </div>
    </div>
    <?= form_actions('testimonials.php', 'Save testimonial') ?>
  </section>
  <aside class="panel"><div class="panel-body">
    <?= field_image('photo', 'Photo', $t['photo'], $errors, ['help' => 'Square, with their permission.']) ?>
    <?= field_switch('is_active', 'Show on the website', $t['is_active'], ['wrap' => 'mb-0']) ?>
  </div></aside>
</form>
<?php admin_footer(['js/crud.js']);
