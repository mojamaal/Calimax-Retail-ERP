<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$returns = $pdo->query("SELECT pr.*, s.name AS supplier_name, u.full_name AS by_name, (SELECT SUM(quantity) FROM purchase_return_items WHERE return_id = pr.id) AS units,
    (SELECT SUM(quantity) FROM purchase_return_items WHERE return_id = pr.id AND bucket = 'damaged') AS damaged_units
    FROM purchase_returns pr JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN users u ON u.id = pr.created_by WHERE " . branch_where('pr') . " ORDER BY pr.id DESC LIMIT 200")->fetchAll();
$damaged = inventory_value();

$pageTitle = 'Returns to Supplier';
require __DIR__ . '/../includes/header.php';
page_header('Purchasing', 'Goods sent back to suppliers. Each return reduces stock and what you owe (or records their refund).', current_branch_id() !== null ? '<a class="btn btn-primary" href="' . e(app_url('/purchase/return_form.php')) . '">' . icon('plus', 16) . ' New return</a>' : '');
nav_purchase();
?>
<?php if ((int) $damaged['damaged_units'] > 0): ?>
  <div class="alert alert-info"><?= icon('shield-alert', 18) ?><span>You have <strong><?= (int) $damaged['damaged_units'] ?> damaged unit(s)</strong> in stock. <a class="link" href="<?= e(app_url('/purchase/return_form.php?damaged=1')) ?>">Return them to the supplier</a> or <a class="link" href="<?= e(app_url('/inventory/adjustment_form.php')) ?>">write them off</a>.</span></div>
<?php endif; ?>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Return #</th><?= branch_th() ?><th>Date</th><th>Supplier</th><th>Reason</th><th class="num">Units</th><th class="num">Value</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($returns as $r): ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/purchase/return_view.php?id=' . $r['id'])) ?>"><?= e($r['return_number']) ?></a></td>
        <?= branch_td((int) $r['branch_id']) ?>
        <td class="text-secondary nowrap"><?= e(fmt_date($r['return_date'])) ?></td>
        <td><?= e($r['supplier_name']) ?></td>
        <td class="text-secondary"><?= e($r['reason'] ?? '—') ?></td>
        <td class="num"><?= (int) $r['units'] ?><?= (int) $r['damaged_units'] ? ' <span class="badge badge-warning">' . (int) $r['damaged_units'] . ' dmg</span>' : '' ?></td>
        <td class="num strong"><?= format_money($r['total_amount']) ?></td>
        <td class="text-secondary text-sm"><?= e($r['by_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$returns) echo empty_state('undo-2', 'No returns to suppliers yet'); ?></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
