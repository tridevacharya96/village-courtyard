<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';
require __DIR__ . '/includes/forms.php';

/**
 * Messages from the website contact form. Opening a message marks it read.
 * ?id= shows one message; ?export=csv downloads all (Super Admin).
 */
require_permission('contacts.view');
const MSG_PER_PAGE = 25;

if (($_GET['export'] ?? '') === 'csv') {
    $user = require_permission('contacts.delete');       // Super Admin by default: holds personal data
    log_activity('export', 'message', null, 'Exported contact messages', (int) $user['id']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="village-courtyard-messages-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Received', 'Name', 'Email', 'Phone', 'Subject', 'Message', 'Read'], ',', '"', '');
    foreach (db_all('SELECT * FROM contacts ORDER BY id DESC') as $c) {
        $row = [$c['created_at'], $c['name'], $c['email'], $c['phone'], $c['subject'], $c['message'], (int) $c['is_read'] ? 'Yes' : 'No'];
        fputcsv($out, array_map(static fn ($v) => is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'$v" : $v, $row), ',', '"', '');
    }
    exit;
}

$open = null;
if (!empty($_GET['id'])) {
    $open = db_one('SELECT * FROM contacts WHERE id = ?', [(int) $_GET['id']]);
    if ($open && !(int) $open['is_read']) {
        db_update('contacts', ['is_read' => 1], 'id = ?', [$open['id']]);
    }
}

$filter = (string) ($_GET['show'] ?? '');
$q = clean_text($_GET['q'] ?? '', 60);
$where = ['1=1'];
$params = [];
if ($filter === 'unread') { $where[] = 'is_read = 0'; }
if ($q !== '') { $like = '%' . addcslashes($q, '%_\\') . '%'; $where[] = '(name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ?)'; array_push($params, $like, $like, $like, $like); }
$w = implode(' AND ', $where);
$total = (int) db_value("SELECT COUNT(*) FROM contacts WHERE $w", $params);
$page = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / MSG_PER_PAGE))));
$rows = db_all("SELECT * FROM contacts WHERE $w ORDER BY id DESC LIMIT " . MSG_PER_PAGE . ' OFFSET ' . (($page - 1) * MSG_PER_PAGE), $params);
$unread = (int) db_value('SELECT COUNT(*) FROM contacts WHERE is_read = 0');

admin_header('Messages', 'contacts.php');
?>
<?php if ($open): ?>
<section class="panel">
  <div class="panel-head">
    <div><div class="eyebrow"><?= e(format_datetime($open['created_at'])) ?></div><h2><?= e($open['subject'] ?: 'Message from ' . $open['name']) ?></h2></div>
    <a class="btn-close" href="<?= e(qs(['id' => null])) ?>" aria-label="Close message"></a>
  </div>
  <div class="panel-body">
    <dl class="dl-grid mb-3">
      <dt>From</dt><dd><?= e($open['name']) ?></dd>
      <dt>Email</dt><dd><a href="mailto:<?= e($open['email']) ?>?subject=<?= rawurlencode('Re: ' . ($open['subject'] ?: 'Your message to ' . setting('site_name', APP_NAME))) ?>"><?= e($open['email']) ?></a></dd>
      <?php if ($open['phone']): ?><dt>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $open['phone'])) ?>"><?= e($open['phone']) ?></a></dd><?php endif; ?>
    </dl>
    <div class="message-body"><?= e($open['message']) ?></div>
  </div>
  <div class="panel-foot">
    <span>Reply from your email app; replies aren't sent from here.</span>
    <span class="d-flex gap-2">
      <a class="btn btn-sm btn-primary" href="mailto:<?= e($open['email']) ?>?subject=<?= rawurlencode('Re: ' . ($open['subject'] ?: 'Your message')) ?>"><i class="bi bi-reply me-1"></i>Reply by email</a>
      <?php if (can('contacts.delete')): ?>
        <button class="btn btn-sm btn-outline-danger js-delete" type="button" data-resource="contacts" data-id="<?= (int) $open['id'] ?>" data-name="this message" data-redirect="contacts.php">Delete</button>
      <?php endif; ?>
    </span>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <div class="panel-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <nav class="status-tabs" aria-label="Filter">
      <a href="contacts.php" class="<?= $filter === '' ? 'active' : '' ?>">All</a>
      <a href="contacts.php?show=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread<span class="count"><?= $unread ?></span></a>
    </nav>
    <div class="d-flex gap-2">
      <form method="get" role="search"><input type="hidden" name="show" value="<?= e($filter) ?>"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search messages" aria-label="Search messages"></form>
      <?php if (can('contacts.delete')): ?><a class="btn btn-sm btn-outline-primary text-nowrap" href="contacts.php?export=csv"><i class="bi bi-download me-1"></i>CSV</a><?php endif; ?>
    </div>
  </div>
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-envelope-open"></i>No messages.</div><?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>From</th><th>Message</th><th>Received</th><th class="text-center">Read</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $c): ?>
        <tr data-row class="<?= (int) $c['is_read'] ? '' : 'unread' ?>">
          <td class="text-nowrap"><a href="<?= e(qs(['id' => $c['id']])) ?>"><?= e($c['name']) ?></a><small class="d-block text-muted fw-normal"><?= e($c['email']) ?></small></td>
          <td class="small" style="max-width:34rem"><?php if ($c['subject']): ?><span><?= e($c['subject']) ?> — </span><?php endif; ?><span class="text-muted fw-normal"><?= e(mb_strimwidth($c['message'], 0, 140, '…')) ?></span></td>
          <td class="small text-nowrap fw-normal"><?= e(time_ago($c['created_at'])) ?></td>
          <td class="text-center"><?= row_toggle('contacts', (int) $c['id'], 'is_read', (bool) $c['is_read'], 'Mark as read') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="panel-foot"><span><?= e(pagination_summary($total, $page, MSG_PER_PAGE)) ?></span><?= render_pagination($total, $page, MSG_PER_PAGE) ?></div>
</section>
<?php admin_footer(['js/crud.js']);
