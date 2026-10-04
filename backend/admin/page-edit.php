<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Create or edit a CMS page (Summernote rich-text editor).
 * Content is sanitised server-side on save, whatever the editor sends.
 */
$user = require_permission('pages.manage');
$id   = (int) ($_GET['id'] ?? 0);
$page = $id ? db_one('SELECT * FROM pages WHERE id = ?', [$id]) : null;
if ($id && !$page) {
    redirect(admin_url('pages.php'));
}
$page ??= ['title' => '', 'slug' => '', 'content' => '', 'banner_image' => null, 'meta_title' => '', 'meta_description' => '', 'show_in_menu' => 0, 'menu_order' => 5, 'is_published' => 1, 'is_system' => 0];
$errors = [];
// Paths the React app already uses; a CMS page can't take these slugs
const RESERVED_SLUGS = ['menu', 'order', 'checkout', 'track', 'reservations', 'gallery', 'contact', 'admin', 'api', 'cart', 'page'];

if (is_post()) {
    verify_csrf();
    $_POST['slug'] = slugify((string) ($_POST['slug'] ?? '') ?: (string) ($_POST['title'] ?? ''));
    $v = Validator::make($_POST, [
        'title'            => 'required|string|min:2|max:150',
        'slug'             => 'required|slug|max:160|unique:pages,slug' . ($id ? ",$id" : ''),
        'content'          => 'nullable|html',
        'meta_title'       => 'nullable|string|max:160',
        'meta_description' => 'nullable|string|max:320',
        'menu_order'       => 'required|integer|between:0,99',
        'show_in_menu'     => 'boolean',
        'is_published'     => 'boolean',
    ], ['slug' => 'Page address', 'meta_title' => 'Search title', 'meta_description' => 'Search description', 'menu_order' => 'Position']);
    $errors = $v->errors();
    $d = $v->validated();
    if (!isset($errors['slug']) && in_array($d['slug'], RESERVED_SLUGS, true)) {
        $errors['slug'] = 'That address is already used by the website. Choose another.';
    }
    if ((int) $page['is_system'] && $id && $d['slug'] !== $page['slug']) {
        $errors['slug'] = 'The address of a built-in page cannot change.';
    }
    if (mb_strlen(strip_tags((string) ($d['content'] ?? ''))) > 60000) {
        $errors['content'] = 'This page is too long. Split it into two pages.';
    }
    $banner = save_image_field('banner_image', 'pages', $page['banner_image'], $errors);

    if (!$errors) {
        $data = [
            'title' => $d['title'], 'slug' => $d['slug'], 'content' => $d['content'] ?? '', 'banner_image' => $banner,
            'meta_title' => $d['meta_title'] ?: null, 'meta_description' => $d['meta_description'] ?: null,
            'show_in_menu' => $d['show_in_menu'], 'menu_order' => $d['menu_order'], 'is_published' => (int) $page['is_system'] ? 1 : $d['is_published'],
        ];
        if ($id) {
            db_update('pages', $data, 'id = ?', [$id]);
            log_activity('update', 'page', $id, "Edited page {$d['title']}", (int) $user['id']);
        } else {
            $id = db_insert('pages', $data + ['created_by' => $user['id']]);
            log_activity('create', 'page', $id, "Created page {$d['title']}", (int) $user['id']);
        }
        flash('success', "Page “{$d['title']}” saved.");
        redirect(admin_url('pages.php'));
    }
    $page = array_merge($page, ['banner_image' => $banner, 'content' => $d['content'] ?? $page['content']]);
}

admin_header($id ? 'Edit page' : 'New page', 'pages.php');
?>
<link rel="stylesheet" href="<?= e(admin_asset('vendor/summernote/summernote-bs5.min.css')) ?>">
<a class="small" href="pages.php"><i class="bi bi-arrow-left"></i> Pages</a>
<form method="post" enctype="multipart/form-data" novalidate class="form-layout" id="pageForm">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-body">
      <?= field_input('title', 'Title', $page['title'], $errors, ['required' => true, 'maxlength' => 150]) ?>
      <div class="mb-3">
        <label class="form-label" for="content">Content</label>
        <textarea id="content" name="content" class="form-control<?= isset($errors['content']) ? ' is-invalid' : '' ?>"><?= e(fv('content', $page['content'])) ?></textarea>
        <?= field_error('content', $errors) ?>
        <div class="form-text">Paste from Word or Google Docs is cleaned up automatically. Scripts and embedded code are removed when you save.</div>
      </div>
    </div>
    <?= form_actions('pages.php', 'Save page') ?>
  </section>
  <aside class="d-grid gap-3">
    <section class="panel"><div class="panel-head"><h2>Publishing</h2></div><div class="panel-body">
      <?= field_input('slug', 'Page address', $page['slug'], $errors, ['prefix' => '/page/', 'maxlength' => 160, 'help' => (int) $page['is_system'] ? 'Built-in page: address is fixed.' : 'Lowercase letters, numbers and hyphens. Left empty, it is made from the title.']) ?>
      <?php if (!(int) $page['is_system']): ?><?= field_switch('is_published', 'Published (visible on the website)', $page['is_published']) ?><?php endif; ?>
      <?= field_switch('show_in_menu', 'Show in the website navigation', $page['show_in_menu']) ?>
      <?= field_input('menu_order', 'Position in navigation', $page['menu_order'], $errors, ['type' => 'number', 'min' => 0, 'max' => 99, 'wrap' => 'mb-0', 'help' => 'Lower numbers appear first.']) ?>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Banner</h2></div><div class="panel-body">
      <?= field_image('banner_image', 'Banner image', $page['banner_image'], $errors, ['wrap' => 'mb-0']) ?>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Search engines</h2></div><div class="panel-body">
      <?= field_input('meta_title', 'Search title', $page['meta_title'], $errors, ['maxlength' => 160, 'optional' => true, 'help' => 'Shown as the link in Google. Defaults to the page title.']) ?>
      <?= field_textarea('meta_description', 'Search description', $page['meta_description'], $errors, ['maxlength' => 320, 'rows' => 3, 'optional' => true, 'wrap' => 'mb-0', 'help' => 'About 150 characters.']) ?>
    </div></section>
  </aside>
</form>
<?php admin_footer(['vendor/summernote/summernote-bs5.min.js', 'js/crud.js', 'js/page-editor.js']);
