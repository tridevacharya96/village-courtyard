<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';
require __DIR__ . '/includes/homepage-schema.php';

/**
 * Edit one homepage section. Form fields are generated from HOMEPAGE_SECTIONS.
 * The hero section also lists its slides.
 */
$user = require_permission('homepage.manage');
$key  = (string) ($_GET['key'] ?? '');
$schema  = HOMEPAGE_SECTIONS[$key] ?? null;
$section = $schema ? db_one('SELECT * FROM homepage_sections WHERE section_key = ?', [$key]) : null;
if (!$section) {
    redirect(admin_url('homepage.php'));
}
$content = json_decode((string) $section['content'], true) ?: [];
$errors  = [];

if (is_post()) {
    verify_csrf();
    [$newContent, $errors] = homepage_clean_content($schema, $_POST);
    $data = ['content' => json_encode($newContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'is_visible' => !empty($_POST['is_visible']) ? 1 : 0];
    if ($schema['title']) {
        $v = Validator::make($_POST, ['title' => 'nullable|string|max:150', 'subtitle' => 'nullable|string|max:255']);
        $errors += $v->errors();
        $data['title'] = $v->validated()['title'] ?? null;
        $data['subtitle'] = $v->validated()['subtitle'] ?? null;
    }
    if ($schema['image']) {
        $data['image'] = save_image_field('image', 'homepage', $section['image'], $errors);
    }
    if (!$errors) {
        db_update('homepage_sections', $data, 'id = ?', [$section['id']]);
        log_activity('update', 'homepage', (int) $section['id'], "Edited homepage section: {$schema['name']}", (int) $user['id']);
        flash('success', "{$schema['name']} saved. The website shows the change within a minute.");
        redirect(admin_url('homepage.php'));
    }
    $content = $newContent;
    $section['image'] = $data['image'] ?? $section['image'];
}

/** Render one schema field. $name is the input name, $value the stored value. */
function schema_field(string $name, array $def, mixed $value, array $errors): string
{
    [$type, $label] = $def;
    $opt = $def[2] ?? [];
    $err = $errors[$name] ?? null;
    $id  = 'f_' . preg_replace('/\W+/', '_', $name);
    $help = $opt['help'] ?? ($type === 'link' ? 'A page on this site like /menu or /reservations, or a full https:// address.' : null);
    $common = ' id="' . e($id) . '" name="' . e($name) . '"' . (isset($opt['maxlength']) ? ' maxlength="' . (int) $opt['maxlength'] . '"' : '') . (isset($opt['placeholder']) ? ' placeholder="' . e($opt['placeholder']) . '"' : '');

    $html = match ($type) {
        'bool' => '<input type="hidden" name="' . e($name) . '" value="0"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" value="1"' . $common . (!empty($value) ? ' checked' : '') . '><label class="form-check-label" for="' . e($id) . '">' . e($label) . '</label></div>',
        'textarea' => '<label class="form-label" for="' . e($id) . '">' . e($label) . '</label><textarea class="form-control' . ($err ? ' is-invalid' : '') . '" rows="' . (int) ($opt['rows'] ?? 3) . '"' . $common . '>' . e((string) $value) . '</textarea>',
        'number' => '<label class="form-label" for="' . e($id) . '">' . e($label) . '</label><input class="form-control' . ($err ? ' is-invalid' : '') . '" type="number" style="max-width:12rem" value="' . e((string) $value) . '"' . $common
            . (isset($opt['min']) ? ' min="' . (int) $opt['min'] . '"' : '') . (isset($opt['max']) ? ' max="' . (int) $opt['max'] . '"' : '') . (isset($opt['step']) ? ' step="' . (int) $opt['step'] . '"' : '') . '>',
        default => '<label class="form-label" for="' . e($id) . '">' . e($label) . '</label><input class="form-control' . ($err ? ' is-invalid' : '') . '" value="' . e((string) $value) . '"' . $common . '>',
    };
    return '<div class="mb-3">' . $html . ($err ? '<div class="invalid-feedback d-block">' . e($err) . '</div>' : '') . field_help($help) . '</div>';
}

$slides = $key === 'hero' ? db_all('SELECT * FROM hero_slides ORDER BY sort_order, id') : [];

admin_header('Homepage · ' . $schema['name'], 'homepage.php');
?>
<a class="small" href="homepage.php"><i class="bi bi-arrow-left"></i> Homepage sections</a>
<form method="post" enctype="multipart/form-data" novalidate class="form-layout">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-head"><h2><?= e($schema['name']) ?></h2></div>
    <div class="panel-body">
      <p class="text-muted"><?= e($schema['note']) ?>
        <?php if (!empty($schema['data_link'])): ?><a href="<?= e($schema['data_link'][0]) ?>"><?= e($schema['data_link'][1]) ?> →</a><?php endif; ?></p>
      <?php if ($schema['title']): ?>
        <?= field_input('subtitle', 'Small heading above the title', $section['subtitle'] ?? '', $errors, ['maxlength' => 255, 'placeholder' => 'Our Story', 'optional' => true]) ?>
        <?= field_input('title', 'Title', $section['title'] ?? '', $errors, ['maxlength' => 150]) ?>
      <?php endif; ?>

      <?php foreach ($schema['fields'] as $fKey => $def): ?>
        <?php if ($def[0] === 'repeater'):
            $rows = $content[$fKey] ?? [];
            $max  = (int) ($def[2]['max'] ?? 6); ?>
          <fieldset class="mb-3 repeater" data-max="<?= $max ?>" data-name="content[<?= e($fKey) ?>]">
            <legend class="form-label fs-6 mb-2"><?= e($def[1]) ?> <span class="text-muted small">(up to <?= $max ?>)</span></legend>
            <div class="repeater-rows">
              <?php foreach ($rows as $i => $row): ?>
                <div class="repeater-row" data-index="<?= (int) $i ?>">
                  <button type="button" class="btn-close position-absolute top-0 end-0 m-2 js-remove-row" aria-label="Remove"></button>
                  <div class="row g-2">
                  <?php foreach ($def[2]['fields'] as $sub => $subDef): ?>
                    <div class="<?= $subDef[0] === 'textarea' ? 'col-12' : 'col-sm-6' ?>"><?= schema_field("content[$fKey][$i][$sub]", $subDef, $row[$sub] ?? '', $errors) ?></div>
                  <?php endforeach; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <template>
              <div class="repeater-row" data-index="__i__">
                <button type="button" class="btn-close position-absolute top-0 end-0 m-2 js-remove-row" aria-label="Remove"></button>
                <div class="row g-2">
                <?php foreach ($def[2]['fields'] as $sub => $subDef): ?>
                  <div class="<?= $subDef[0] === 'textarea' ? 'col-12' : 'col-sm-6' ?>"><?= schema_field("content[$fKey][__i__][$sub]", $subDef, '', []) ?></div>
                <?php endforeach; ?>
                </div>
              </div>
            </template>
            <button type="button" class="btn btn-sm btn-outline-primary mt-2 js-add-row"><i class="bi bi-plus-lg me-1"></i>Add</button>
          </fieldset>
        <?php else: ?>
          <?= schema_field("content[$fKey]", $def, $content[$fKey] ?? ($def[0] === 'bool' ? false : ''), $errors) ?>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?= form_actions('homepage.php', 'Save section') ?>
  </section>
  <aside class="d-grid gap-3">
    <section class="panel"><div class="panel-body">
      <?= field_switch('is_visible', 'Show this section on the homepage', $section['is_visible'], ['wrap' => 'mb-0']) ?>
    </div></section>
    <?php if ($schema['image']): ?>
      <section class="panel"><div class="panel-head"><h2>Image</h2></div>
        <div class="panel-body"><?= field_image('image', 'Section image', $section['image'], $errors, ['wrap' => 'mb-0', 'help' => 'Wide images work best (at least 1600 px).']) ?></div></section>
    <?php endif; ?>
  </aside>
</form>

<?php if ($key === 'hero'): ?>
<section class="panel">
  <div class="panel-head"><h2>Slides</h2><a class="btn btn-primary btn-sm" href="slide-edit.php"><i class="bi bi-plus-lg me-1"></i>Add slide</a></div>
  <ul class="section-list" data-sortable="hero_slides">
    <?php foreach ($slides as $s): ?>
      <li data-row data-id="<?= (int) $s['id'] ?>" class="<?= (int) $s['is_active'] ? '' : 'row-off' ?>">
        <?= drag_handle() ?>
        <?= admin_thumb($s['image'], 'thumb', $s['heading']) ?>
        <div class="s-body"><a class="fw-bold" href="slide-edit.php?id=<?= (int) $s['id'] ?>"><?= e($s['heading']) ?></a><small><?= e($s['subheading']) ?></small></div>
        <?= row_toggle('hero_slides', (int) $s['id'], 'is_active', (bool) $s['is_active'], 'Show this slide') ?>
        <a class="btn btn-sm btn-outline-primary" href="slide-edit.php?id=<?= (int) $s['id'] ?>">Edit</a>
        <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="hero_slides" data-id="<?= (int) $s['id'] ?>" data-name="this slide" aria-label="Delete slide"><i class="bi bi-trash"></i></button>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$slides): ?><div class="empty-state"><i class="bi bi-images"></i>No slides yet. The homepage shows the site name instead.</div><?php endif; ?>
</section>
<?php endif; ?>

<script>
// Repeater rows: add from <template>, remove, keep indexes unique
document.querySelectorAll('.repeater').forEach((rep) => {
  const rows = rep.querySelector('.repeater-rows');
  const tpl = rep.querySelector('template');
  const max = parseInt(rep.dataset.max, 10);
  const addBtn = rep.querySelector('.js-add-row');
  let next = rows.children.length + 100;
  const sync = () => { addBtn.disabled = rows.children.length >= max; };
  addBtn.addEventListener('click', () => {
    rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__i__', String(next++)));
    sync();
  });
  rep.addEventListener('click', (e) => { if (e.target.closest('.js-remove-row')) { e.target.closest('.repeater-row').remove(); sync(); } });
  sync();
});
</script>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
