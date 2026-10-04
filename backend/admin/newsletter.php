<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Newsletter subscribers: list, unsubscribe, delete, export to CSV
 * (for import into Mailchimp, Brevo, Zoho Campaigns, etc.).
 */
$user = require_permission('newsletter.view');
const SUB_PER_PAGE = 50;

if (($_GET['export'] ?? '') === 'csv') {
    log_activity('export', 'subscriber', null, 'Exported newsletter subscribers', (int) $user['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="village-courtyard-subscribers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Email', 'Subscribed on'], ',', '"', '');
    foreach (db_all('SELECT email, subscribed_at FROM newsletter WHERE is_active = 1 ORDER BY subscribed_at') as $s) {
        fputcsv($out, [$s['email'], $s['subscribed_at']], ',', '"', '');
    }
    exit;
}

$q = clean_text($_GET['q'] ?? '', 80);
$show = (string) ($_GET['show'] ?? 'active');
$where = [$show === 'unsubscribed' ? 'is_active = 0' : ($show === 'all' ? '1=1' : 'is_active = 1')];
$params = [];
if ($q !== '') { $where[] = 'email LIKE ?'; $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
$w = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM newsletter WHERE $w", $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / SUB_PER_PAGE))));
$rows = db_all("SELECT * FROM newsletter WHERE $w ORDER BY subscribed_at DESC LIMIT " . SUB_PER_PAGE . ' OFFSET ' . (($page - 1) * SUB_PER_PAGE), $params);
$stats = db_one('SELECT SUM(is_active) active, SUM(is_active = 0) gone, SUM(is_active = 1 AND subscribed_at > NOW() - INTERVAL 30 DAY) recent FROM newsletter');

admin_header('Subscribers', 'newsletter.php');
?>
<section class="kpis">
  <div class="kpi"><span class="eyebrow">Subscribed</span><span class="kpi-value"><?= (int) $stats['active'] ?></span><span class="kpi-sub"><?= (int) $stats['recent'] ?> joined in the last 30 days</span></div>
  <div class="kpi"><span class="eyebrow">Unsubscribed</span><span class="kpi-value"><?= (int) $stats['gone'] ?></span><span class="kpi-sub">Kept so they aren't emailed again</span></div>
</section>
<section class="panel">
  <div class="panel-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <nav class="status-tabs" aria-label="Filter">
      <?php foreach (['active' => 'Subscribed', 'unsubscribed' => 'Unsubscribed', 'all' => 'All'] as $k => $l): ?><a href="newsletter.php?show=<?= $k ?>" class="<?= $show === $k ? 'active' : '' ?>"><?= $l ?></a><?php endforeach; ?>
    </nav>
    <div class="d-flex gap-2">
      <form method="get" role="search"><input type="hidden" name="show" value="<?= e($show) ?>"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search email" aria-label="Search email"></form>
      <a class="btn btn-sm btn-outline-primary text-nowrap" href="newsletter.php?export=csv"><i class="bi bi-download me-1"></i>Export subscribed</a>
    </div>
  </div>
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-people"></i>No subscribers here.</div><?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Email</th><th>Joined</th><th class="text-center">Subscribed</th><th class="text-end">Delete</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): ?>
        <tr data-row class="<?= (int) $s['is_active'] ? '' : 'row-off' ?>">
          <td><?= e($s['email']) ?></td>
          <td class="small"><?= e(format_date($s['subscribed_at'])) ?><?= $s['unsubscribed_at'] ? ' · left ' . e(format_date($s['unsubscribed_at'])) : '' ?></td>
          <td class="text-center"><?= row_toggle('newsletter', (int) $s['id'], 'is_active', (bool) $s['is_active'], 'Subscribed') ?></td>
          <td class="text-end"><button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="newsletter" data-id="<?= (int) $s['id'] ?>" data-name="<?= e($s['email']) ?>"
              data-body="Use this when someone asks for their data to be removed. To just stop emailing them, switch off Subscribed." aria-label="Delete <?= e($s['email']) ?>"><i class="bi bi-trash"></i></button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="panel-foot"><span><?= e(pagination_summary($total, $page, SUB_PER_PAGE)) ?></span><?= render_pagination($total, $page, SUB_PER_PAGE) ?></div>
</section>
<?php admin_footer(['js/crud.js']);
