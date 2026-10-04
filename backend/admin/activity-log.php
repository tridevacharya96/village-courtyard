<?php
declare(strict_types=1);
define('APP_CONTEXT', 'admin');
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/includes/admin.php';

/**
 * Activity log: who changed what, and when (Super Admin only).
 */
require_permission('logs.view');
const LOG_PER_PAGE = 30;

$userFilter = (int) ($_GET['user'] ?? 0);
$action     = clean_text($_GET['action'] ?? '', 60);
$entity     = clean_text($_GET['entity'] ?? '', 60);
$q          = clean_text($_GET['q'] ?? '', 80);
$day        = (string) ($_GET['day'] ?? '');

$where = ['1=1'];
$params = [];
if ($userFilter === -1) {
    $where[] = 'a.user_id IS NULL';
} elseif ($userFilter > 0) {
    $where[] = 'a.user_id = ?'; $params[] = $userFilter;
}
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if ($entity !== '') { $where[] = 'a.entity_type = ?'; $params[] = $entity; }
if ($q !== '') { $where[] = 'a.description LIKE ?'; $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $where[] = 'DATE(a.created_at) = ?'; $params[] = $day; }
$w = implode(' AND ', $where);

$total = (int) db_value("SELECT COUNT(*) FROM activity_logs a WHERE $w", $params);
$page  = max(1, min((int) ($_GET['page'] ?? 1), max(1, (int) ceil($total / LOG_PER_PAGE))));
$logs  = db_all(
    "SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
      WHERE $w ORDER BY a.id DESC LIMIT " . LOG_PER_PAGE . ' OFFSET ' . (($page - 1) * LOG_PER_PAGE),
    $params
);
$users    = db_all('SELECT id, name FROM users ORDER BY name');
$actions  = db_query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$entities = db_query('SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type')->fetchAll(PDO::FETCH_COLUMN);

$entityLink = static function (array $a): ?string {
    return match ($a['entity_type']) {
        'order' => $a['entity_id'] ? 'order.php?id=' . (int) $a['entity_id'] : null,
        'user'  => 'users.php',
        'table' => 'tables.php',
        default => null,
    };
};
$risky = ['login_failed', 'access_denied', 'csrf_failed', 'delete', 'deactivate', 'refund_needed', 'paid_after_cancel', 'duplicate_payment'];

admin_header('Activity Log', 'activity-log.php');
?>
<section class="panel">
  <form class="panel-body filter-bar" method="get">
    <div><label class="form-label small mb-1" for="user">Person</label>
      <select class="form-select form-select-sm" id="user" name="user">
        <option value="">Everyone</option>
        <option value="-1"<?= $userFilter === -1 ? ' selected' : '' ?>>Customers / system</option>
        <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $userFilter === (int) $u['id'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="form-label small mb-1" for="action">Action</label>
      <select class="form-select form-select-sm" id="action" name="action">
        <option value="">All actions</option>
        <?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"<?= $action === $a ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $a))) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="form-label small mb-1" for="entity">Area</label>
      <select class="form-select form-select-sm" id="entity" name="entity">
        <option value="">All areas</option>
        <?php foreach ($entities as $en): ?><option value="<?= e($en) ?>"<?= $entity === $en ? ' selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $en))) ?></option><?php endforeach; ?>
      </select></div>
    <div><label class="form-label small mb-1" for="day">Date</label><input class="form-control form-control-sm" type="date" id="day" name="day" value="<?= e($day) ?>"></div>
    <div><label class="form-label small mb-1" for="q">Search</label><input class="form-control form-control-sm" type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Order no., name…"></div>
    <div class="actions"><button class="btn btn-sm btn-primary" type="submit">Filter</button><a class="btn btn-sm btn-light" href="activity-log.php">Reset</a></div>
  </form>
</section>

<section class="panel">
  <?php if (!$logs): ?>
    <div class="empty-state"><i class="bi bi-clock-history"></i>No activity matches these filters.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP address</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $a): $link = $entityLink($a); ?>
        <tr>
          <td class="small text-nowrap" title="<?= e(format_datetime($a['created_at'])) ?>"><?= e(format_datetime($a['created_at'])) ?></td>
          <td><?= $a['user_name'] ? e($a['user_name']) : '<span class="text-muted">Customer / system</span>' ?></td>
          <td><span class="badge <?= in_array($a['action'], $risky, true) ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= e(ucwords(str_replace('_', ' ', $a['action']))) ?></span></td>
          <td class="small"><?= $link ? '<a href="' . e($link) . '">' . e($a['description']) . '</a>' : e($a['description']) ?></td>
          <td class="small text-muted font-monospace"><?= e($a['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="panel-foot"><span><?= e(pagination_summary($total, $page, LOG_PER_PAGE)) ?></span><?= render_pagination($total, $page, LOG_PER_PAGE) ?></div>
</section>
<?php admin_footer();
