<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');

$pdo = db();
$user = current_user();
$id = input_int($_GET, 'id');

$loadSale = function () use ($pdo, $id) {
    $stmt = $pdo->prepare(
        "SELECT s.*, u.full_name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone,
                vb.full_name AS voided_by_name, va.full_name AS void_approved_name, da.full_name AS discount_approved_name
         FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id
         LEFT JOIN users vb ON vb.id = s.voided_by LEFT JOIN users va ON va.id = s.void_approved_by LEFT JOIN users da ON da.id = s.discount_approved_by
         WHERE s.id = :id"
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
};
$sale = $loadSale();
if (!$sale) { flash('error', 'Sale not found.'); redirect('/pos/history.php'); }
assert_branch_access((int) $sale['branch_id']);
if (!can('sales.view_all') && (int) $sale['cashier_id'] !== (int) $user['id']) deny_access();

$hasReturns = (int) $pdo->query('SELECT COUNT(*) FROM sale_returns WHERE sale_id = ' . (int) $id . " AND status <> 'rejected'")->fetchColumn() > 0;
$voidError = null;

if (is_post() && input($_POST, 'action') === 'void') {
    verify_csrf();
    $reason = input($_POST, 'reason');
    $approverId = null;
    if ($sale['status'] !== 'completed') {
        $voidError = 'Only a completed sale can be cancelled.';
    } elseif ($hasReturns) {
        $voidError = 'This sale has returns recorded against it. Process further returns instead of cancelling.';
    } elseif ($reason === '') {
        $voidError = 'Enter the reason for cancelling this sale.';
    } else {
        if (can('sales.void')) {
            $approverId = (int) $user['id'];
        } else {
            $approver = authorize_override(input($_POST, 'override_username'), $_POST['override_password'] ?? '', 'sales.void');
            if (is_array($approver)) { $approverId = (int) $approver['id']; $approverName = $approver['full_name']; } else $voidError = $approver;
        }
    }

    if (!$voidError) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT status FROM sales WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => $id]);
            if ($lock->fetchColumn() !== 'completed') throw new RuntimeException('This sale was already changed by someone else.');

            $items = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id = :id');
            $items->execute([':id' => $id]);
            foreach ($items->fetchAll() as $it) {
                apply_stock_change($pdo, (int) $it['variant_id'], (int) $it['quantity'], 'sale_void', 'sale', $id, 'Cancelled ' . $sale['invoice_number'], 'saleable', (int) $sale['branch_id']);
            }
            // Reverse any credit charged to the customer's account.
            $creditStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sale_payments WHERE sale_id = :id AND method = 'store_credit'");
            $creditStmt->execute([':id' => $id]);
            $creditAmount = (float) $creditStmt->fetchColumn();
            if ($creditAmount > 0 && $sale['customer_id']) {
                $pdo->prepare('UPDATE customers SET credit_balance = credit_balance - :a WHERE id = :id')->execute([':a' => $creditAmount, ':id' => $sale['customer_id']]);
                $bal = $pdo->prepare('SELECT credit_balance FROM customers WHERE id = :id');
                $bal->execute([':id' => $sale['customer_id']]);
                $pdo->prepare("INSERT INTO customer_ledger (customer_id, branch_id, sale_id, type, amount, balance_after, note, created_by) VALUES (:c,:br,:s,'adjustment',:a,:bal,:note,:u)")
                    ->execute([':c' => $sale['customer_id'], ':br' => $sale['branch_id'], ':s' => $id, ':a' => -$creditAmount, ':bal' => (float) $bal->fetchColumn(), ':note' => 'Sale cancelled ' . $sale['invoice_number'], ':u' => $user['id']]);
            }
            // Re-open any exchange credit that was spent on this sale.
            $pdo->prepare('UPDATE sale_returns SET exchange_sale_id = NULL, exchange_used = 0, exchange_cash_back = 0 WHERE exchange_sale_id = :id')->execute([':id' => $id]);

            $pdo->prepare("UPDATE sales SET status = 'voided', void_reason = :r, voided_by = :u, void_approved_by = :a, voided_at = NOW() WHERE id = :id")
                ->execute([':r' => $reason, ':u' => $user['id'], ':a' => $approverId, ':id' => $id]);
            $pdo->commit();
            $approvedNote = isset($approverName) ? ' (authorized by ' . $approverName . ')' : '';
            log_activity('sale_void', 'Cancelled sale ' . $sale['invoice_number'] . ' · ' . format_money($sale['total_amount']) . ' · reason: ' . $reason . $approvedNote, 'sale', $id);
            flash('success', 'Sale ' . $sale['invoice_number'] . ' cancelled and stock restored.');
            redirect('/pos/sale_view.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Void failed: ' . $ex->getMessage());
            $voidError = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not cancel this sale. Please try again.';
        }
    }
}

$items = $pdo->prepare(
    'SELECT si.*, pv.sku, pv.barcode, pv.variant_label, p.name AS product_name, p.id AS product_id
     FROM sale_items si JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id WHERE si.sale_id = :id'
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$payments = $pdo->prepare('SELECT * FROM sale_payments WHERE sale_id = :id');
$payments->execute([':id' => $id]);
$payments = $payments->fetchAll();
$returns = $pdo->prepare('SELECT r.*, u.full_name AS created_name FROM sale_returns r LEFT JOIN users u ON u.id = r.created_by WHERE r.sale_id = :id ORDER BY r.id DESC');
$returns->execute([':id' => $id]);
$returns = $returns->fetchAll();
$returnable = array_sum(array_map(fn($i) => (int) $i['quantity'] - (int) $i['returned_qty'], $items));
$windowDays = (int) setting('return_window_days', '30');
$withinWindow = $windowDays <= 0 || strtotime($sale['sale_date']) >= strtotime("-{$windowDays} days");
$canReturn = can('returns.create') && in_array($sale['status'], ['completed', 'partially_refunded'], true) && $returnable > 0;

$pageTitle = $sale['invoice_number'];
require __DIR__ . '/../includes/header.php';

$actions = '<a class="btn btn-secondary" href="' . e(app_url('/pos/receipt.php?id=' . $id)) . '" target="_blank">' . icon('printer', 16) . ' Receipt</a>';
if ($canReturn) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/pos/return_form.php?sale_id=' . $id)) . '">' . icon('undo-2', 16) . ' Return / Exchange</a>';
if ($sale['status'] === 'completed' && !$hasReturns && can('pos.checkout')) $actions .= '<button class="btn btn-danger-outline" type="button" data-modal-open="#voidModal">' . icon('ban', 16) . ' Cancel Sale</button>';
page_header($sale['invoice_number'], fmt_datetime($sale['sale_date']) . ' · Cashier: ' . e($sale['cashier_name']) . ' · ' . status_badge($sale['status']), $actions, [['Sales History', '/pos/history.php'], [$sale['invoice_number'], null]]);
?>
<?php if ($voidError): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($voidError) ?></span></div><?php endif; ?>
<?php if ($sale['status'] === 'voided'): ?>
  <div class="alert alert-error"><?= icon('ban', 18) ?><span>Cancelled on <?= e(fmt_datetime($sale['voided_at'])) ?> by <?= e($sale['voided_by_name'] ?? '—') ?><?= $sale['void_approved_name'] && $sale['void_approved_by'] != $sale['voided_by'] ? ', approved by ' . e($sale['void_approved_name']) : '' ?>. Reason: <strong><?= e($sale['void_reason'] ?? '—') ?></strong>. Stock was restored.</span></div>
<?php endif; ?>
<?php if ($canReturn && !$withinWindow): ?>
  <div class="alert alert-warning"><?= icon('clock', 18) ?><span>This sale is older than the <?= $windowDays ?>-day return window. Returns will need manager approval.</span></div>
<?php endif; ?>

<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Items</h3><span class="text-muted text-sm"><?= count($items) ?> line(s)</span></div>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Total</th><?php if ($returns): ?><th class="num">Returned</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td><div class="strong"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></div><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
            <td class="num"><?= (int) $it['quantity'] ?></td>
            <td class="num"><?= format_money($it['unit_price']) ?></td>
            <td class="num text-success"><?= (float) $it['discount_amount'] > 0 ? '−' . format_money($it['discount_amount']) : '—' ?></td>
            <td class="num text-muted"><?= format_money($it['tax_amount']) ?></td>
            <td class="num strong"><?= format_money($it['line_total']) ?></td>
            <?php if ($returns): ?><td class="num"><?= (int) $it['returned_qty'] ?: '—' ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Summary</h3>
      <dl class="desc-list">
        <dt>Customer</dt><dd><?= $sale['customer_id'] ? '<a class="link" href="' . e(app_url('/customers/view.php?id=' . $sale['customer_id'])) . '">' . e($sale['customer_name']) . '</a>' : 'Walk-in' ?></dd>
        <dt>Subtotal</dt><dd><?= format_money($sale['subtotal']) ?></dd>
        <dt>Discount</dt><dd class="text-success"><?= (float) $sale['discount_amount'] > 0 ? '−' . format_money($sale['discount_amount']) : '—' ?></dd>
        <?php if ($sale['discount_approved_name']): ?><dt>Discount approved by</dt><dd><?= e($sale['discount_approved_name']) ?></dd><?php endif; ?>
        <dt>Tax<?= setting('prices_include_tax', '1') === '1' ? ' (incl.)' : '' ?></dt><dd><?= format_money($sale['tax_amount']) ?></dd>
        <dt style="font-size:15px;color:var(--dark);font-weight:700;">Total</dt><dd style="font-size:17px;font-weight:800;"><?= format_money($sale['total_amount']) ?></dd>
      </dl>
      <?php if ($sale['notes']): ?><div class="callout mt-3"><?= e($sale['notes']) ?></div><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mb-3">Payments</h3>
      <div class="list-rows">
        <?php foreach ($payments as $p): ?>
          <div><span><?= icon(payment_methods()[$p['method']]['icon'] ?? 'wallet', 15) ?> <?= e(method_label($p['method'])) ?><?= $p['reference_no'] ? ' <span class="text-muted text-sm">· ' . e($p['reference_no']) . '</span>' : '' ?>
            <?php if ($p['method'] === 'cash' && $p['tendered_amount'] !== null): ?><div class="cell-sub">Received <?= format_money($p['tendered_amount']) ?> · change <?= format_money($p['change_amount']) ?></div><?php endif; ?></span>
            <strong><?= format_money($p['amount']) ?></strong></div>
        <?php endforeach; ?>
        <?php if (!$payments): ?><div class="text-muted">No payments recorded.</div><?php endif; ?>
      </div>
    </div>
    <?php if ($returns): ?>
    <div class="card">
      <h3 class="mb-3">Returns</h3>
      <div class="list-rows">
        <?php foreach ($returns as $r): ?>
          <a href="<?= e(app_url('/pos/return_view.php?id=' . $r['id'])) ?>"><span><span class="strong"><?= e($r['return_number']) ?></span> <?= status_badge($r['status']) ?><div class="cell-sub"><?= e(ucfirst($r['return_type'])) ?> · <?= e(fmt_datetime($r['created_at'])) ?></div></span><strong><?= format_money($r['total_amount']) ?></strong></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-overlay" id="voidModal" <?= $voidError ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3>Cancel sale <?= e($sale['invoice_number']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <div class="alert alert-warning"><?= icon('alert-triangle', 18) ?><span>All <?= array_sum(array_column($items, 'quantity')) ?> item(s) go back into stock<?= array_filter($payments, fn($p) => $p['method'] === 'store_credit') ? ' and the credit charged to the customer is reversed' : '' ?>. Refund <?= format_money($sale['total_amount']) ?> to the customer. This can’t be undone.</span></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="void">
      <div class="form-group"><label for="reason">Reason for cancelling</label>
        <select class="form-control" id="reasonPick" onchange="document.getElementById('reason').value=this.value">
          <option value="">Choose a reason…</option><option>Entered by mistake</option><option>Customer changed their mind at the till</option><option>Wrong items or quantities</option><option>Payment failed</option><option>Duplicate transaction</option>
        </select>
        <input class="form-control mt-2" type="text" id="reason" name="reason" required maxlength="255" placeholder="Or type the reason">
      </div>
      <?php if (!can('sales.void')): ?>
        <div class="callout mb-3"><div class="fw-700 mb-2"><?= icon('shield-check', 15) ?> Manager authorization</div>
          <div class="form-row"><input class="form-control" type="text" name="override_username" placeholder="Manager username" required autocomplete="off"><input class="form-control" type="password" name="override_password" placeholder="Password" required autocomplete="new-password"></div>
        </div>
      <?php endif; ?>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Keep sale</button><button class="btn btn-danger" type="submit"><?= icon('ban', 16) ?> Cancel sale</button></div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
