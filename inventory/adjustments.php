<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stock.php';
require_permission(['inventory.adjust', 'inventory.approve']);

$pdo = db();
$status = input($_GET, 'status');
$reason = input($_GET, 'reason');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 40;
$where = '1=1' . branch_and('a');
$params = [];
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND a.status = :s'; $params[':s'] = $status; }
if (isset(adjustment_reasons()[$reason])) { $where .= ' AND a.reason = :r'; $params[':r'] = $reason; }

$cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments a WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT a.*, ru.full_name AS requested_name, au.full_name AS approved_name,
    (SELECT COUNT(*) FROM stock_adjustment_items WHERE adjustment_id = a.id) AS line_count,
    (SELECT SUM(ABS(qty_change)) FROM stock_adjustment_items WHERE adjustment_id = a.id) AS units,
    (SELECT SUM(qty_change * unit_cost) FROM stock_adjustment_items WHERE adjustment_id = a.id) AS est_value
    FROM stock_adjustments a LEFT JOIN users ru ON ru.id = a.requested_by LEFT JOIN users au ON au.id = a.approved_by
    WHERE $where ORDER BY (a.status = 'pending') DESC, a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pending = (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending'" . branch_and())->fetchColumn();
$monthLoss = (float) $pdo->query("SELECT COALESCE(SUM(value_change),0) FROM stock_adjustments WHERE status = 'approved' AND value_change < 0 AND approved_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" . branch_and())->fetchColumn();
$inv = inventory_value();

$pageTitle = 'Stock Adjustments';
require __DIR__ . '/../includes/header.php';
page_header('Stock Control', 'Authorized changes to stock: damaged, expired, lost or found items and count corrections — every change needs a reason and is approved.',
    can('inventory.adjust') ? '<a class="btn btn-primary" href="' . e(app_url('/inventory/adjustment_form.php')) . '">' . icon('plus', 16) . ' New adjustment</a>' : '');
nav_stock();
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Awaiting approval', (string) $pending, 'hourglass', $pending ? 'amber' : 'gray', can('inventory.approve') ? 'Review and approve' : 'Sent to a manager', '/inventory/adjustments.php?status=pending') ?>
  <?= kpi('Write-offs this month', format_money(-$monthLoss), 'trending-down', 'red', 'Value lost at cost') ?>
  <?= kpi('Damaged stock', number_format((int) $inv['damaged_units']) . ' units', 'shield-alert', 'purple', can('products.view_cost') ? format_money($inv['damaged_value']) . ' at cost' : 'Held out of sale', '/inventory/products.php?stock=damaged') ?>
  <?= kpi('Physical counts', '<span style="font-size:20px;">Start a count</span>', 'list-checks', 'blue', 'Compare shelf vs system', '/inventory/counts.php') ?>
</div>

<div class="card flush">
  <div style="padding:16px 16px 0;" class="filter-bar">
    <div class="chip-group">
      <?php foreach (['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $k => $l): ?>
        <a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k ?: null, 'page' => null])) ?>"><?= $l ?><?= $k === 'pending' && $pending ? ' <span class="chip-count">' . $pending . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </div>
    <form method="get" style="margin-left:auto;"><?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
      <select class="form-control" name="reason" onchange="this.form.submit()"><option value="">All reasons</option><?php foreach (adjustment_reasons() as $k => $r): ?><option value="<?= $k ?>" <?= $reason === $k ? 'selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach; ?></select></form>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Number</th><?= branch_th() ?><th>Date</th><th>Reason</th><th class="num">Items</th><th class="num">Units</th><th class="num">Value impact</th><th>Requested by</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $val = $r['status'] === 'approved' ? (float) $r['value_change'] : (float) $r['est_value']; ?>
        <tr class="<?= $r['status'] === 'pending' ? 'row-warn' : '' ?>">
          <td class="strong"><a href="<?= e(app_url('/inventory/adjustment_view.php?id=' . $r['id'])) ?>"><?= e($r['adj_number']) ?></a><?= $r['source'] === 'count' ? '<div class="cell-sub">From stock count</div>' : '' ?></td>
          <?= branch_td((int) $r['branch_id']) ?>
          <td class="nowrap text-secondary"><?= e(fmt_datetime($r['created_at'])) ?></td>
          <td><?= icon(adjustment_reasons()[$r['reason']][3] ?? 'scale', 14) ?> <?= e(adjustment_reason_label($r['reason'])) ?><?= $r['note'] ? '<div class="cell-sub">' . e($r['note']) . '</div>' : '' ?></td>
          <td class="num"><?= (int) $r['line_count'] ?></td>
          <td class="num"><?= (int) $r['units'] ?></td>
          <td class="num <?= $val < 0 ? 'text-error' : ($val > 0 ? 'text-success' : '') ?>"><?= in_array($r['reason'], ['damaged', 'restore_damaged'], true) ? '<span class="text-muted">moved</span>' : format_money($val) ?></td>
          <td class="text-secondary text-sm"><?= e($r['requested_name'] ?? '—') ?><?= $r['approved_name'] ? '<div class="cell-sub">' . ($r['status'] === 'rejected' ? '✕ ' : '✓ ') . e($r['approved_name']) . '</div>' : '' ?></td>
          <td><?= status_badge($r['status']) ?></td>
          <td class="actions"><a class="btn btn-<?= $r['status'] === 'pending' && can('inventory.approve') ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/inventory/adjustment_view.php?id=' . $r['id'])) ?>"><?= $r['status'] === 'pending' && can('inventory.approve') ? 'Review' : 'View' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$rows) echo empty_state('clipboard-check', 'No adjustments', 'Record damaged, expired or lost stock here instead of editing quantities directly.'); ?>
  </div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
