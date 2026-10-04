<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';
require __DIR__ . '/includes/homepage-schema.php';

/**
 * Homepage builder: order sections by dragging, show/hide them, edit each one.
 */
require_permission('homepage.manage');

$sections = db_all('SELECT * FROM homepage_sections ORDER BY sort_order, id');
$slideCount = (int) db_value('SELECT COUNT(*) FROM hero_slides WHERE is_active = 1');

/** One-line summary of what a section currently shows. */
function section_summary(array $s, int $slideCount): string
{
    $c = json_decode((string) $s['content'], true) ?: [];
    return match ($s['section_key']) {
        'hero'         => "$slideCount active slide" . ($slideCount === 1 ? '' : 's') . (!empty($c['autoplay']) ? ', rotating every ' . round(($c['interval'] ?? 6000) / 1000) . 's' : ''),
        'signature'    => (int) db_value('SELECT COUNT(*) FROM menu_items WHERE is_featured = 1 AND is_available = 1') . ' signature dishes available, showing up to ' . (int) ($c['limit'] ?? 6),
        'specials'     => (string) ($c['banner_text'] ?? ''),
        'why_us'       => count($c['items'] ?? []) . ' reasons',
        'gallery'      => 'First ' . (int) ($c['limit'] ?? 8) . ' photos from the gallery',
        'testimonials' => (int) db_value('SELECT COUNT(*) FROM testimonials WHERE is_active = 1') . ' active testimonials',
        'hours'        => 'Hours and contact from Settings' . (!empty($c['show_map']) ? ', with map' : ''),
        default        => (string) ($s['subtitle'] ?: mb_substr((string) ($c['text'] ?? ''), 0, 90)),
    };
}

admin_header('Homepage', 'homepage.php');
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
  <p class="text-muted small mb-0"><i class="bi bi-arrows-move"></i> Drag sections into the order they should appear. Switch a section off to hide it without losing its content.</p>
  <a class="btn btn-outline-primary btn-sm" href="<?= e(FRONTEND_URL ?: '/') ?>" target="_blank" rel="noopener"><i class="bi bi-eye me-1"></i>View homepage</a>
</div>

<section class="panel">
  <ul class="section-list" data-sortable="homepage_sections">
    <?php foreach ($sections as $s): $def = HOMEPAGE_SECTIONS[$s['section_key']] ?? ['name' => $s['section_key'], 'icon' => 'bi-square']; ?>
      <li data-row data-id="<?= (int) $s['id'] ?>" class="<?= (int) $s['is_visible'] ? '' : 'row-off' ?>">
        <?= drag_handle() ?>
        <span class="s-icon"><i class="bi <?= e($def['icon']) ?>"></i></span>
        <div class="s-body">
          <a class="fw-bold" href="homepage-section.php?key=<?= e($s['section_key']) ?>"><?= e($def['name']) ?></a>
          <?php if ($s['title']): ?><span class="text-muted"> · “<?= e($s['title']) ?>”</span><?php endif; ?>
          <small><?= e(section_summary($s, $slideCount)) ?></small>
        </div>
        <span class="small text-muted d-none d-sm-inline">Visible</span>
        <?= row_toggle('homepage_sections', (int) $s['id'], 'is_visible', (bool) $s['is_visible'], 'Show this section on the homepage') ?>
        <a class="btn btn-sm btn-outline-primary" href="homepage-section.php?key=<?= e($s['section_key']) ?>">Edit</a>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php admin_footer(['vendor/sortable/Sortable.min.js', 'js/crud.js']);
