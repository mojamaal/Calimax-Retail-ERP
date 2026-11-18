<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/returns.php';
require_permission(['returns.create', 'returns.approve']);

$pdo = db();
$status = input($_GET, 'status');
$q = input($_GET, 'q');
$period = resolve_report_period('this_month');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 40;

$where = 'r.created_at >= :f AND r.created_at < :t';
$params = period_bounds($period['from'], $period['to']);
$bScope = branch_and('r');
if ($status === 'pending') { $where = "r.status = 'pending'"; $params = []; }
elseif (in_array($status, ['processed', 'rejected'], true)) { $where .= ' AND r.status = :st'; $params[':st'] = $status; }
if ($q !== '') { $where .= ' AND (r.return_number LIKE :q1 OR s.invoice_number LIKE :q2 OR c.name LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
if (!can('sales.view_all')) { $where .= ' AND r.created_by = :me'; $params[':me'] = current_user()['id']; }

$from = "FROM sale_returns r JOIN sales s ON s.id = r.sale_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users a ON a.id = r.approved_by WHERE $where $bScope";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT r.*, s.invoice_number, COALESCE(c.name,'Walk-in') AS customer_name, u.full_name AS created_name, a.full_name AS approved_name,
    (SELECT SUM(quantity) FROM sale_return_items WHERE return_id = r.id) AS units,
    (SELECT SUM(quantity) FROM sale_return_items WHERE return_id = r.id AND item_condition = 'damaged') AS damaged_units
    $from ORDER BY r.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$returns = $stmt->fetchAll();

$pending = (int) $pdo->query("SELECT COUNT(*) FROM sale_returns WHERE status = 'pending'" . branch_and())->fetchColumn();
$ret = fin_returns($period['from'], $period['to']);

$pageTitle = 'Returns & Exchanges';
require __DIR__ . '/../includes/header.php';
page_header('Returns & Exchanges', 'Customer returns, refunds and exchanges, with manager approval and stock condition tracking.', '<a class="btn btn-primary" href="' . e(app_url('/pos/return_form.php')) . '">' . icon('plus', 16) . ' New return</a>');
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Awaiting approval', (string) $pending, 'hourglass', $pending ? 'amber' : 'gray', $pending ? 'Needs a manager' : 'Nothing pending', '/pos/returns.php?status=pending') ?>
  <?= kpi('Returns processed', (string) (int) $ret['count'], 'undo-2', 'blue', e($period['label'])) ?>
  <?= kpi('Value returned', format_money($ret['total']), 'banknote', 'red', 'Refunds + exchange credits') ?>
  <?= kpi('Cost back in stock', format_money($ret['cost']), 'package', 'green', 'At original cost') ?>
</div>

<div class="card tight mb-4">
  <div class="filter-bar mb-3">
    <div class="chip-group">
      <a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a>
      <a class="chip <?= $status === 'pending' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'pending', 'page' => null])) ?>">Pending <span class="chip-count"><?= $pending ?></span></a>
      <a class="chip <?= $status === 'processed' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'processed', 'page' => null])) ?>">Processed</a>
      <a class="chip <?= $status === 'rejected' ? 'active' : '' ?>" href="<?= e(query_with(['status' => 'rejected', 'page' => null])) ?>">Rejected</a>
    </div>
    <form method="get" class="flex gap-2" style="margin-left:auto;">
      <?php foreach (['status' => $status, 'preset' => $period['preset'], 'from' => $period['preset'] ? '' : $period['from'], 'to' => $period['preset'] ? '' : $period['to']] as $k => $v): if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; endforeach; ?>
      <input class="form-control sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Return / invoice / customer">
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
    </form>
  </div>
  <?php if ($status !== 'pending') render_period_filter($period, array_filter(['status' => $status, 'q' => $q])); ?>
</div>

<div class="card flush">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Return #</th><?= branch_th() ?><th>Date</th><th>Original sale</th><th>Customer</th><th>Type</th><th class="num">Items</th><th class="num">Value</th><th>Status</th><th>By</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr class="<?= $r['status'] === 'pending' ? 'row-warn' : '' ?>">
          <td class="strong"><a href="<?= e(app_url('/pos/return_view.php?id=' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
          <?= branch_td((int) $r['branch_id']) ?>
          <td class="nowrap text-secondary"><?= e(fmt_datetime($r['created_at'])) ?></td>
          <td><a class="link" href="<?= e(app_url('/pos/sale_view.php?id=' . $r['sale_id'])) ?>"><?= e($r['invoice_number']) ?></a></td>
          <td><?= e($r['customer_name']) ?></td>
          <td><?= $r['return_type'] === 'exchange' ? '<span class="badge badge-info">' . icon('repeat', 11) . ' Exchange</span>' : '<span class="badge badge-neutral">Refund · ' . e(refund_methods()[$r['refund_method']] ?? $r['refund_method']) . '</span>' ?></td>
          <td class="num"><?= (int) $r['units'] ?><?= (int) $r['damaged_units'] ? ' <span class="badge badge-warning" title="Damaged">' . (int) $r['damaged_units'] . ' dmg</span>' : '' ?></td>
          <td class="num strong"><?= format_money($r['total_amount']) ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="text-secondary text-sm"><?= e($r['created_name'] ?? '—') ?><?= $r['approved_name'] ? '<div class="cell-sub">✓ ' . e($r['approved_name']) . '</div>' : '' ?></td>
          <td class="actions"><a class="btn btn-<?= $r['status'] === 'pending' && can('returns.approve') ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/pos/return_view.php?id=' . $r['id'])) ?>"><?= $r['status'] === 'pending' && can('returns.approve') ? 'Review' : 'View' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$returns) echo empty_state('undo-2', $status === 'pending' ? 'No returns awaiting approval' : 'No returns in this period', 'Start a return from a sale, or from “New return”.'); ?>
  </div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
