<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$id = input_int($_GET, 'id');
$stmt = $pdo->prepare('SELECT pr.*, s.name AS supplier_name, u.full_name AS by_name FROM purchase_returns pr JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN users u ON u.id = pr.created_by WHERE pr.id = :id');
$stmt->execute([':id' => $id]);
$ret = $stmt->fetch();
if (!$ret) { flash('error', 'Purchase return not found.'); redirect('/purchase/returns.php'); }
assert_branch_access((int) $ret['branch_id']);
$items = $pdo->prepare('SELECT pri.*, pv.sku, pv.variant_label, p.name AS product_name FROM purchase_return_items pri JOIN product_variants pv ON pv.id = pri.variant_id JOIN products p ON p.id = pv.product_id WHERE pri.return_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();

$pageTitle = $ret['return_number'];
require __DIR__ . '/../includes/header.php';
page_header($ret['return_number'], 'Returned to <a class="link" href="' . e(app_url('/purchase/supplier_view.php?id=' . $ret['supplier_id'])) . '">' . e($ret['supplier_name']) . '</a> on ' . e(fmt_date($ret['return_date'])) . ' by ' . e($ret['by_name'] ?? '—'),
    '<a class="btn btn-secondary" href="' . e(app_url('/purchase/return_print.php?id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Print / PDF</a>', [['Returns to supplier', '/purchase/returns.php'], [$ret['return_number'], null]]);
?>
<div class="card flush">
  <div class="card-head"><h3>Items returned</h3><span class="text-secondary text-sm">Reason: <?= e($ret['reason'] ?? '—') ?></span></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Item</th><th>Taken from</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr><td><div class="strong"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></div><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
        <td><?= $it['bucket'] === 'damaged' ? '<span class="badge badge-warning">Damaged stock</span>' : '<span class="badge badge-neutral">Saleable stock</span>' ?></td>
        <td class="num"><?= (int) $it['quantity'] ?></td><td class="num"><?= format_money($it['unit_cost']) ?></td><td class="num strong"><?= format_money($it['quantity'] * $it['unit_cost']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="4">Total</td><td class="num"><?= format_money($ret['total_amount']) ?></td></tr></tfoot>
  </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
