<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['purchase.manage', 'purchase.receive']);

$pdo = db();
$id = input_int($_GET, 'id');
$stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name, s.phone AS supplier_phone, s.payment_terms_days, u.full_name AS created_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN users u ON u.id = po.created_by WHERE po.id = :id');
$stmt->execute([':id' => $id]);
$po = $stmt->fetch();
if (!$po) { flash('error', 'Purchase order not found.'); redirect('/purchase/orders.php'); }
assert_branch_access((int) $po['branch_id']);

if (is_post()) {
    verify_csrf();
    require_permission('purchase.manage');
    $action = input($_POST, 'action');
    $map = [
        'mark_ordered' => [['draft'], 'ordered', 'Placed order with supplier'],
        'cancel'       => [['draft', 'ordered'], 'cancelled', 'Cancelled'],
        'close'        => [['partially_received'], 'closed', 'Closed short (remaining items will not be delivered)'],
    ];
    if (isset($map[$action]) && in_array($po['status'], $map[$action][0], true)) {
        $reason = input($_POST, 'reason');
        $pdo->prepare('UPDATE purchase_orders SET status = :s, closed_reason = :r WHERE id = :id')->execute([':s' => $map[$action][1], ':r' => $reason ?: null, ':id' => $id]);
        log_activity('po_' . $action, $po['po_number'] . ': ' . $map[$action][2] . ($reason ? ' · ' . $reason : ''), 'purchase_order', $id);
        flash('success', $po['po_number'] . ' — ' . strtolower($map[$action][2]) . '.');
    } else {
        flash('error', 'That action is not available for this order.');
    }
    redirect('/purchase/order_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT poi.*, pv.sku, pv.variant_label, pv.stock_qty, p.name AS product_name, p.id AS product_id FROM purchase_order_items poi JOIN ' . pv((int) $po['branch_id']) . ' pv ON pv.id = poi.variant_id JOIN products p ON p.id = pv.product_id WHERE poi.po_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$grns = $pdo->prepare('SELECT g.*, u.full_name AS by_name, (SELECT SUM(quantity) FROM grn_items WHERE grn_id = g.id) AS units, (SELECT SUM(rejected_qty) FROM grn_items WHERE grn_id = g.id) AS rejected FROM goods_received_notes g LEFT JOIN users u ON u.id = g.created_by WHERE g.po_id = :id ORDER BY g.id');
$grns->execute([':id' => $id]);
$grns = $grns->fetchAll();
$ordered = array_sum(array_column($items, 'quantity'));
$received = array_sum(array_column($items, 'received_qty'));
$receivedValue = array_sum(array_column($grns, 'total_amount'));
$hasPending = $received < $ordered;

$pageTitle = $po['po_number'];
require __DIR__ . '/../includes/header.php';
$a = '<a class="btn btn-secondary" href="' . e(app_url('/purchase/order_print.php?id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Print / PDF</a>';
if (can('purchase.manage')) {
    if ($po['status'] === 'draft') {
        $a .= '<a class="btn btn-secondary" href="' . e(app_url('/purchase/order_form.php?id=' . $id)) . '">' . icon('pencil', 16) . ' Edit</a>';
        $a .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="mark_ordered"><button class="btn btn-primary" type="submit">' . icon('send', 16) . ' Place order</button></form>';
    }
    if (in_array($po['status'], ['draft', 'ordered'], true)) $a .= '<button class="btn btn-danger-outline" type="button" data-modal-open="#cancelModal">Cancel order</button>';
    if ($po['status'] === 'partially_received') $a .= '<button class="btn btn-secondary" type="button" data-modal-open="#closeModal">' . icon('circle-slash', 16) . ' Close short</button>';
}
if (in_array($po['status'], ['ordered', 'partially_received'], true) && $hasPending && can('purchase.receive')) $a .= '<a class="btn btn-primary" href="' . e(app_url('/purchase/grn_form.php?po_id=' . $id)) . '">' . icon('package-check', 16) . ' Receive goods</a>';
page_header($po['po_number'], e($po['supplier_name']) . ' · ' . status_badge($po['status']) . ($po['is_direct'] ? ' · <span class="badge badge-neutral">Direct purchase</span>' : ''), $a, [['Purchasing', '/purchase/orders.php'], [$po['po_number'], null]]);
?>
<div class="steps">
  <?php $stage = ['draft' => 1, 'ordered' => 2, 'partially_received' => 3, 'received' => 4, 'closed' => 4, 'cancelled' => 0][$po['status']] ?? 1; ?>
  <?php foreach (['Draft', 'Ordered', 'Receiving', 'Completed'] as $i => $label): $n = $i + 1; ?>
    <div class="step <?= $stage > $n || ($stage === 4 && $n === 4) ? 'done' : ($stage === $n ? 'current' : '') ?>"><span class="n"><?= $n ?></span> <?= $label ?></div>
  <?php endforeach; ?>
  <?php if ($po['status'] === 'cancelled'): ?><div class="step" style="color:var(--error);"><span class="n">✕</span> Cancelled</div><?php endif; ?>
</div>
<?php if ($po['closed_reason']): ?><div class="callout mb-4"><?= icon('info', 14) ?> <?= $po['status'] === 'cancelled' ? 'Cancelled' : 'Closed' ?>: <?= e($po['closed_reason']) ?></div><?php endif; ?>

<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Items</h3><span class="text-muted text-sm"><?= $received ?> of <?= $ordered ?> units received</span></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Received</th><th class="num">Outstanding</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): $out = (int) $it['quantity'] - (int) $it['received_qty']; ?>
        <tr>
          <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $it['product_id'])) ?>"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($it['sku']) ?> · <?= (int) $it['stock_qty'] ?> in stock now</div></td>
          <td class="num"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?= (int) $it['received_qty'] ?> <?= $out <= 0 ? '<span class="badge badge-success">✓</span>' : '' ?></td>
          <td class="num <?= $out > 0 && in_array($po['status'], ['closed'], true) ? 'text-muted' : ($out > 0 ? 'text-warning fw-700' : '') ?>"><?= $out > 0 ? $out : '—' ?></td>
          <td class="num"><?= format_money($it['unit_cost']) ?></td>
          <td class="num strong"><?= format_money($it['unit_cost'] * $it['quantity']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5">Order total</td><td class="num"><?= format_money($po['total_amount']) ?></td></tr></tfoot>
    </table></div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Summary</h3>
      <dl class="desc-list">
        <dt>Supplier</dt><dd><a class="link" href="<?= e(app_url('/purchase/supplier_view.php?id=' . $po['supplier_id'])) ?>"><?= e($po['supplier_name']) ?></a></dd>
        <dt>Order date</dt><dd><?= e(fmt_date($po['order_date'])) ?></dd>
        <dt>Expected</dt><dd><?= e(fmt_date($po['expected_date'])) ?></dd>
        <dt>Created by</dt><dd><?= e($po['created_name'] ?? '—') ?></dd>
        <dt>Ordered value</dt><dd><?= format_money($po['total_amount']) ?></dd>
        <dt>Received value</dt><dd><?= format_money($receivedValue) ?></dd>
        <?php if ($po['payment_terms_days']): ?><dt>Payment terms</dt><dd><?= (int) $po['payment_terms_days'] ?> days</dd><?php endif; ?>
      </dl>
      <?php if ($po['notes']): ?><div class="callout mt-3"><?= e($po['notes']) ?></div><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mb-3">Deliveries received</h3>
      <div class="list-rows">
        <?php foreach ($grns as $g): ?>
          <a href="<?= e(app_url('/purchase/grn_view.php?id=' . $g['id'])) ?>"><span><span class="strong"><?= e($g['grn_number']) ?></span> <?= status_badge($g['payment_mode'] === 'paid' ? 'paid' : 'credit') ?><div class="cell-sub"><?= e(fmt_date($g['received_date'])) ?> · <?= (int) $g['units'] ?> units<?= (int) $g['rejected'] ? ' · ' . (int) $g['rejected'] . ' rejected' : '' ?> · <?= e($g['by_name'] ?? '') ?></div></span><strong><?= format_money($g['total_amount']) ?></strong></a>
        <?php endforeach; ?>
        <?php if (!$grns): ?><div class="text-muted text-sm">Nothing received yet. Stock does not change until a delivery is received.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="cancelModal" hidden><div class="modal sm">
  <div class="modal-header"><h3>Cancel <?= e($po['po_number']) ?>?</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
    <div class="form-group"><label>Reason</label><input class="form-control" name="reason" maxlength="255" required placeholder="e.g. Supplier out of stock"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Keep order</button><button class="btn btn-danger" type="submit">Cancel order</button></div></form>
</div></div>
<div class="modal-overlay" id="closeModal" hidden><div class="modal sm">
  <div class="modal-header"><h3>Close order short</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
  <p class="text-muted text-sm">Use this when the supplier will not deliver the remaining <?= $ordered - $received ?> unit(s). What was already received stays in stock and on the supplier’s account.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="close">
    <div class="form-group"><label>Reason</label><input class="form-control" name="reason" maxlength="255" required placeholder="e.g. Balance discontinued by supplier"></div>
    <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Back</button><button class="btn btn-primary" type="submit">Close order</button></div></form>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
