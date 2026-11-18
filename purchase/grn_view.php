<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['purchase.receive', 'purchase.manage']);

$pdo = db();
$id = input_int($_GET, 'id');
$stmt = $pdo->prepare('SELECT g.*, po.po_number, po.is_direct, po.supplier_id, s.name AS supplier_name, u.full_name AS by_name FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN users u ON u.id = g.created_by WHERE g.id = :id');
$stmt->execute([':id' => $id]);
$grn = $stmt->fetch();
if (!$grn) { flash('error', 'Delivery not found.'); redirect('/purchase/receipts.php'); }
assert_branch_access((int) $grn['branch_id']);

$items = $pdo->prepare('SELECT gi.*, poi.quantity AS ordered_qty, poi.unit_cost AS po_cost, pv.sku, pv.variant_label, p.name AS product_name, p.id AS product_id FROM grn_items gi LEFT JOIN purchase_order_items poi ON poi.id = gi.po_item_id JOIN product_variants pv ON pv.id = gi.variant_id JOIN products p ON p.id = pv.product_id WHERE gi.grn_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$pay = $pdo->prepare('SELECT * FROM supplier_payments WHERE grn_id = :id');
$pay->execute([':id' => $id]);
$pay = $pay->fetch();

$pageTitle = $grn['grn_number'];
require __DIR__ . '/../includes/header.php';
page_header($grn['grn_number'], ($grn['is_direct'] ? 'Direct purchase' : 'Against <a class="link" href="' . e(app_url('/purchase/order_view.php?id=' . $grn['po_id'])) . '">' . e($grn['po_number']) . '</a>') . ' · <a class="link" href="' . e(app_url('/purchase/supplier_view.php?id=' . $grn['supplier_id'])) . '">' . e($grn['supplier_name']) . '</a> · ' . status_badge($grn['payment_mode'] === 'paid' ? 'paid' : 'credit'),
    '<a class="btn btn-secondary" href="' . e(app_url('/purchase/grn_print.php?id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Print / PDF</a>', [['Goods received', '/purchase/receipts.php'], [$grn['grn_number'], null]]);
?>
<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Items received</h3></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><?php if (!$grn['is_direct']): ?><th class="num">Ordered</th><?php endif; ?><th class="num">Accepted</th><th class="num">Rejected</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $it['product_id'])) ?>"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($it['sku']) ?><?= $it['note'] ? ' · ' . e($it['note']) : '' ?></div></td>
          <?php if (!$grn['is_direct']): ?><td class="num text-secondary"><?= (int) $it['ordered_qty'] ?></td><?php endif; ?>
          <td class="num strong"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?= (int) $it['rejected_qty'] ? '<span class="badge badge-warning">' . (int) $it['rejected_qty'] . '</span>' : '—' ?></td>
          <td class="num"><?= format_money($it['unit_cost']) ?><?= !$grn['is_direct'] && $it['po_cost'] !== null && abs($it['po_cost'] - $it['unit_cost']) > 0.004 ? '<div class="cell-sub">PO: ' . format_money($it['po_cost']) . '</div>' : '' ?></td>
          <td class="num strong"><?= format_money($it['quantity'] * $it['unit_cost']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="<?= $grn['is_direct'] ? 4 : 5 ?>">Total</td><td class="num"><?= format_money($grn['total_amount']) ?></td></tr></tfoot>
    </table></div>
  </div>
  <div class="card">
    <h3 class="mb-3">Details</h3>
    <dl class="desc-list">
      <dt>Received</dt><dd><?= e(fmt_date($grn['received_date'])) ?></dd>
      <dt>Received by</dt><dd><?= e($grn['by_name'] ?? '—') ?></dd>
      <dt>Supplier invoice</dt><dd><?= e($grn['supplier_invoice_no'] ?? '—') ?></dd>
      <dt>Payment</dt><dd><?= $grn['payment_mode'] === 'paid' ? 'Paid on delivery' : 'On credit' ?></dd>
      <?php if ($pay): ?><dt>Paid by</dt><dd><?= e(method_label($pay['payment_method'])) ?><?= $pay['reference_no'] ? ' · ' . e($pay['reference_no']) : '' ?></dd><?php endif; ?>
      <dt>Recorded</dt><dd><?= e(fmt_datetime($grn['created_at'])) ?></dd>
    </dl>
    <?php if ($grn['notes']): ?><div class="callout mt-3"><?= e($grn['notes']) ?></div><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
