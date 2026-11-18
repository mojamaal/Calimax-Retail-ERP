<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/returns.php';
require_permission(['returns.create', 'returns.approve']);

$pdo = db();
$id = input_int($_GET, 'id');
$load = function () use ($pdo, $id) {
    $s = $pdo->prepare(
        "SELECT r.*, s.invoice_number, s.sale_date, c.name AS customer_name, c.phone AS customer_phone, u.full_name AS created_name, a.full_name AS approved_name, xs.invoice_number AS exchange_invoice
         FROM sale_returns r JOIN sales s ON s.id = r.sale_id LEFT JOIN customers c ON c.id = r.customer_id
         LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users a ON a.id = r.approved_by LEFT JOIN sales xs ON xs.id = r.exchange_sale_id
         WHERE r.id = :id"
    );
    $s->execute([':id' => $id]);
    return $s->fetch();
};
$ret = $load();
if (!$ret) { flash('error', 'Return not found.'); redirect('/pos/returns.php'); }
assert_branch_access((int) $ret['branch_id']);
$lh = branch_letterhead((int) $ret['branch_id']);

if (is_post()) {
    verify_csrf();
    require_permission('returns.approve');
    $action = input($_POST, 'action');
    try {
        $pdo->beginTransaction();
        if ($action === 'approve') {
            process_sale_return($pdo, $id, (int) current_user()['id']);
            $pdo->commit();
            log_activity('return_approve', 'Approved ' . $ret['return_number'] . ' · ' . format_money($ret['total_amount']), 'sale_return', $id);
            flash('success', 'Return approved. ' . ($ret['return_type'] === 'exchange' ? 'The exchange credit is ready to use at the POS.' : 'Refund ' . format_money($ret['total_amount']) . ' by ' . (refund_methods()[$ret['refund_method']] ?? $ret['refund_method']) . '.'));
        } elseif ($action === 'reject') {
            $why = input($_POST, 'rejected_reason') ?: 'Not specified';
            $u = $pdo->prepare("UPDATE sale_returns SET status = 'rejected', rejected_reason = :r, approved_by = :a, approved_at = NOW() WHERE id = :id AND status = 'pending'");
            $u->execute([':r' => $why, ':a' => current_user()['id'], ':id' => $id]);
            if (!$u->rowCount()) throw new RuntimeException('This return is no longer pending.');
            $pdo->commit();
            log_activity('return_reject', 'Rejected ' . $ret['return_number'] . ' · reason: ' . $why, 'sale_return', $id);
            flash('success', 'Return rejected. No refund or stock change was made.');
        } else { $pdo->rollBack(); }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Return action failed: ' . $ex->getMessage());
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not update the return.');
    }
    redirect('/pos/return_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT ri.*, pv.sku, pv.variant_label, p.name AS product_name FROM sale_return_items ri JOIN product_variants pv ON pv.id = ri.variant_id JOIN products p ON p.id = pv.product_id WHERE ri.return_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();

if (input($_GET, 'print') === '1'): ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title><?= e($ret['return_number']) ?></title>
<style>body{font-family:'Courier New',monospace;width:80mm;margin:0 auto;padding:10px;font-size:12px}.c{text-align:center}.r{text-align:right}.l{border-top:1px dashed #000;margin:7px 0}table{width:100%;border-collapse:collapse}td{padding:2px 0;vertical-align:top}.b{font-weight:800;font-size:14px}@media print{.np{display:none}}</style></head>
<body><button class="np" onclick="window.print()" style="width:100%;padding:10px;margin-bottom:10px;">Print</button>
<div class="c"><div class="b" style="text-transform:uppercase"><?= e($lh['business'] ?: APP_NAME) ?></div><?php if (count(branches_all()) > 1): ?><div><?= e($lh['branch']) ?></div><?php endif; ?><div><?= e($lh['phone']) ?></div><div class="b" style="margin-top:6px;"><?= $ret['return_type'] === 'exchange' ? 'EXCHANGE CREDIT SLIP' : 'REFUND RECEIPT' ?></div></div>
<div class="l"></div>
<table><tr><td>Return</td><td class="r b"><?= e($ret['return_number']) ?></td></tr><tr><td>Original sale</td><td class="r"><?= e($ret['invoice_number']) ?></td></tr><tr><td>Date</td><td class="r"><?= e(date('Y-m-d H:i', strtotime($ret['approved_at'] ?? $ret['created_at']))) ?></td></tr><?php if ($ret['customer_name']): ?><tr><td>Customer</td><td class="r"><?= e($ret['customer_name']) ?></td></tr><?php endif; ?></table>
<div class="l"></div>
<table><?php foreach ($items as $it): ?><tr><td colspan="2"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' ' . e($it['variant_label']) : '' ?></td></tr><tr><td>&nbsp;&nbsp;<?= (int) $it['quantity'] ?> x <?= $it['item_condition'] === 'damaged' ? '(damaged)' : '' ?></td><td class="r"><?= number_format($it['line_total'], 2) ?></td></tr><?php endforeach; ?></table>
<div class="l"></div>
<table><tr class="b"><td><?= $ret['return_type'] === 'exchange' ? 'CREDIT' : 'REFUNDED' ?></td><td class="r"><?= number_format($ret['total_amount'], 2) ?></td></tr><?php if ($ret['return_type'] === 'refund'): ?><tr><td>Method</td><td class="r"><?= e(refund_methods()[$ret['refund_method']] ?? $ret['refund_method']) ?></td></tr><?php endif; ?></table>
<div class="l"></div><div>Reason: <?= e($ret['reason']) ?></div>
<?php if ($ret['return_type'] === 'exchange'): ?><div class="c" style="margin-top:6px;">Present this slip at the till to use your credit.</div><?php endif; ?>
<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script></body></html>
<?php exit; endif;

$pageTitle = $ret['return_number'];
require __DIR__ . '/../includes/header.php';
$actions = '';
if ($ret['status'] === 'processed') $actions .= '<a class="btn btn-secondary" href="?id=' . $id . '&print=1" target="_blank">' . icon('printer', 16) . ' Print slip</a>';
if ($ret['status'] === 'processed' && $ret['return_type'] === 'exchange' && !$ret['exchange_sale_id'] && can('pos.checkout')) $actions .= '<a class="btn btn-primary" href="' . e(app_url('/pos/index.php?exchange=' . urlencode($ret['return_number']))) . '">' . icon('repeat', 16) . ' Start exchange sale</a>';
page_header($ret['return_number'], 'Return against <a class="link" href="' . e(app_url('/pos/sale_view.php?id=' . $ret['sale_id'])) . '">' . e($ret['invoice_number']) . '</a> · ' . status_badge($ret['status']), $actions, [['Returns', '/pos/returns.php'], [$ret['return_number'], null]]);
?>
<?php if ($ret['status'] === 'pending'): ?>
  <div class="alert alert-warning"><?= icon('hourglass', 18) ?><span>Waiting for manager approval. No refund has been given and stock has not changed yet.</span></div>
<?php elseif ($ret['status'] === 'rejected'): ?>
  <div class="alert alert-error"><?= icon('x-circle', 18) ?><span>Rejected by <?= e($ret['approved_name'] ?? '—') ?> on <?= e(fmt_datetime($ret['approved_at'])) ?>. Reason: <?= e($ret['rejected_reason'] ?? '—') ?></span></div>
<?php endif; ?>

<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Returned items</h3></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Item</th><th class="num">Qty</th><th>Condition</th><th>Stock outcome</th><th class="num">Value</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><div class="strong"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></div><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
            <td class="num"><?= (int) $it['quantity'] ?></td>
            <td><?= $it['item_condition'] === 'damaged' ? '<span class="badge badge-warning">Damaged / defective</span>' : '<span class="badge badge-success">Good</span>' ?></td>
            <td class="text-secondary text-sm"><?= $ret['status'] === 'processed' ? ($it['item_condition'] === 'damaged' ? 'Moved to damaged stock (not for sale)' : 'Back in saleable stock') : ($ret['status'] === 'pending' ? 'On approval' : 'No change') ?></td>
            <td class="num strong"><?= format_money($it['line_total']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="4">Total</td><td class="num"><?= format_money($ret['total_amount']) ?></td></tr></tfoot>
      </table>
    </div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Details</h3>
      <dl class="desc-list">
        <dt>Type</dt><dd><?= $ret['return_type'] === 'exchange' ? 'Exchange' : 'Refund' ?></dd>
        <?php if ($ret['return_type'] === 'refund'): ?><dt>Refund method</dt><dd><?= e(refund_methods()[$ret['refund_method']] ?? $ret['refund_method']) ?></dd><?php endif; ?>
        <?php if ($ret['refund_reference']): ?><dt>Reference</dt><dd><?= e($ret['refund_reference']) ?></dd><?php endif; ?>
        <dt>Customer</dt><dd><?= e($ret['customer_name'] ?? 'Walk-in') ?></dd>
        <dt>Reason</dt><dd><?= e($ret['reason'] ?? '—') ?></dd>
        <dt>Recorded by</dt><dd><?= e($ret['created_name'] ?? '—') ?></dd>
        <dt>Recorded</dt><dd><?= e(fmt_datetime($ret['created_at'])) ?></dd>
        <?php if ($ret['status'] === 'processed'): ?><dt>Approved by</dt><dd><?= e($ret['approved_name'] ?? '—') ?></dd><dt>Approved</dt><dd><?= e(fmt_datetime($ret['approved_at'])) ?></dd><?php endif; ?>
        <?php if ($ret['return_type'] === 'exchange' && $ret['status'] === 'processed'): ?>
          <dt>Exchange</dt><dd><?= $ret['exchange_sale_id'] ? 'Used on <a class="link" href="' . e(app_url('/pos/sale_view.php?id=' . $ret['exchange_sale_id'])) . '">' . e($ret['exchange_invoice']) . '</a>' : '<span class="badge badge-info">Credit unused</span>' ?></dd>
          <?php if ((float) $ret['exchange_cash_back'] > 0): ?><dt>Balance paid in cash</dt><dd><?= format_money($ret['exchange_cash_back']) ?></dd><?php endif; ?>
        <?php endif; ?>
      </dl>
    </div>
    <?php if ($ret['status'] === 'pending' && can('returns.approve')): ?>
    <div class="card">
      <h3 class="mb-2">Decision</h3>
      <p class="text-muted text-sm mb-3">Approving restocks good items, moves damaged items to damaged stock<?= $ret['return_type'] === 'refund' ? ' and authorizes the ' . e(refund_methods()[$ret['refund_method']] ?? '') . ' refund' : ' and releases the exchange credit' ?>.</p>
      <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><button class="btn btn-success btn-block" type="submit"><?= icon('check', 16) ?> Approve return</button></form>
      <form method="post" data-confirm="Reject this return?"><?= csrf_field() ?><input type="hidden" name="action" value="reject">
        <input class="form-control mb-2" type="text" name="rejected_reason" maxlength="255" placeholder="Reason for rejecting" required>
        <button class="btn btn-danger-outline btn-block" type="submit"><?= icon('x', 16) ?> Reject</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
