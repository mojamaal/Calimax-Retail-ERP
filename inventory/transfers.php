<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('transfers.view');

$pdo = db();
$status = input($_GET, 'status');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 40;
$b = current_branch_id();

$where = '1=1';
if ($b !== null) $where .= " AND (t.from_branch_id = $b OR t.to_branch_id = $b)";
elseif (!user_has_all_branches()) { $mine = implode(',', array_map('intval', array_keys(accessible_branches()))); $where .= " AND (t.from_branch_id IN ($mine) OR t.to_branch_id IN ($mine))"; }
$params = [];
if (in_array($status, ['in_transit', 'received', 'cancelled'], true)) { $where .= ' AND t.status = :s'; $params[':s'] = $status; }

$cnt = $pdo->prepare("SELECT COUNT(*) FROM stock_transfers t WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT t.*, fb.name AS from_name, tb.name AS to_name, u.full_name AS by_name,
    (SELECT COALESCE(SUM(quantity),0) FROM stock_transfer_items WHERE transfer_id = t.id) AS units,
    (SELECT COALESCE(SUM(quantity * unit_cost),0) FROM stock_transfer_items WHERE transfer_id = t.id) AS value
    FROM stock_transfers t JOIN branches fb ON fb.id = t.from_branch_id JOIN branches tb ON tb.id = t.to_branch_id LEFT JOIN users u ON u.id = t.created_by
    WHERE $where ORDER BY (t.status = 'in_transit') DESC, t.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$incoming = $b !== null ? (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit' AND to_branch_id = $b")->fetchColumn() : (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit'")->fetchColumn();
$outgoing = $b !== null ? (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit' AND from_branch_id = $b")->fetchColumn() : $incoming;
$seeCost = can('products.view_cost');

$pageTitle = 'Branch Transfers';
require __DIR__ . '/../includes/header.php';
page_header('Stock Control', 'Move stock between branches. Stock leaves the sender straight away and joins the receiver when they confirm what arrived.',
    can('transfers.manage') && $b !== null && count(branches_all()) > 1 ? '<a class="btn btn-primary" href="' . e(app_url('/inventory/transfer_form.php')) . '">' . icon('truck', 16) . ' New transfer</a>' : '');
nav_stock();
?>
<?php if (count(branches_all()) < 2): ?>
  <div class="alert alert-info"><?= icon('info', 18) ?><span>You only have one branch. <?= can('branches.manage') ? '<a class="link" href="' . e(app_url('/branches.php')) . '">Add another branch</a> to start transferring stock.' : 'Ask the owner to add a branch.' ?></span></div>
<?php endif; ?>
<div class="grid grid-3 mb-4">
  <?= kpi('On the way to ' . ($b !== null ? 'this branch' : 'branches'), (string) $incoming, 'truck', $incoming ? 'blue' : 'gray', 'Waiting to be received', '/inventory/transfers.php?status=in_transit') ?>
  <?= kpi('Sent, not yet received', (string) $outgoing, 'send', 'amber', $b !== null ? 'From ' . e(branch_scope_label()) : 'All branches') ?>
  <?= kpi('Transfers (all time)', number_format($total), 'repeat', 'purple') ?>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;" class="filter-bar"><div class="chip-group">
    <?php foreach ([''=>'All', 'in_transit'=>'In transit', 'received'=>'Received', 'cancelled'=>'Cancelled'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k ?: null, 'page' => null])) ?>"><?= $l ?></a><?php endforeach; ?>
  </div></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Transfer</th><th>Route</th><th>Sent</th><th class="num">Units</th><?php if ($seeCost): ?><th class="num">Value (cost)</th><?php endif; ?><th>Status</th><th>By</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $t): $mineIn = $b !== null && (int) $t['to_branch_id'] === $b; ?>
      <tr class="<?= $t['status'] === 'in_transit' && $mineIn ? 'row-warn' : '' ?>">
        <td class="strong"><a href="<?= e(app_url('/inventory/transfer_view.php?id=' . $t['id'])) ?>"><?= e($t['transfer_number']) ?></a></td>
        <td><?= e($t['from_name']) ?> <?= icon('arrow-right', 13) ?> <?= e($t['to_name']) ?></td>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($t['created_at'])) ?></td>
        <td class="num"><?= (int) $t['units'] ?></td>
        <?php if ($seeCost): ?><td class="num"><?= format_money($t['value']) ?></td><?php endif; ?>
        <td><?= status_badge($t['status']) ?><?= $t['received_at'] ? '<div class="cell-sub">' . e(fmt_datetime($t['received_at'])) . '</div>' : '' ?></td>
        <td class="text-secondary text-sm"><?= e($t['by_name'] ?? '—') ?></td>
        <td class="actions"><a class="btn btn-<?= $t['status'] === 'in_transit' && $mineIn && can('transfers.manage') ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/inventory/transfer_view.php?id=' . $t['id'])) ?>"><?= $t['status'] === 'in_transit' && $mineIn && can('transfers.manage') ? 'Receive' : 'View' ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('truck', 'No transfers yet', 'Send stock from one branch to another with “New transfer”.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
