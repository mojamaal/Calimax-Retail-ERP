<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/returns.php';
require_permission('returns.create');

$pdo = db();
$user = current_user();
$saleId = input_int($_GET, 'sale_id') ?: input_int($_POST, 'sale_id');
$lookup = input($_GET, 'invoice');
$errors = [];

if (!$saleId && $lookup !== '') {
    $s = $pdo->prepare("SELECT id FROM sales WHERE invoice_number = :i OR invoice_number = :i2 LIMIT 1");
    $s->execute([':i' => $lookup, ':i2' => setting('invoice_prefix', 'INV') . '-' . str_pad(ltrim($lookup, '0'), 6, '0', STR_PAD_LEFT)]);
    $saleId = (int) $s->fetchColumn();
    if (!$saleId) $errors[] = 'No sale found with invoice number “' . $lookup . '”.';
}

$sale = null;
if ($saleId) {
    $s = $pdo->prepare('SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, u.full_name AS cashier_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id JOIN users u ON u.id = s.cashier_id WHERE s.id = :id');
    $s->execute([':id' => $saleId]);
    $sale = $s->fetch();
    if (!$sale) { $errors[] = 'Sale not found.'; }
    elseif (!in_array($sale['status'], ['completed', 'partially_refunded'], true)) { $errors[] = 'Sale ' . $sale['invoice_number'] . ' is ' . str_replace('_', ' ', $sale['status']) . ' and cannot be returned.'; $sale = null; }
}

$items = [];
$windowDays = (int) setting('return_window_days', '30');
if ($sale) {
    $it = $pdo->prepare('SELECT si.*, pv.sku, pv.variant_label, p.name AS product_name FROM sale_items si JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id WHERE si.sale_id = :id');
    $it->execute([':id' => $sale['id']]);
    $items = $it->fetchAll();
    $already = returned_quantities($pdo, (int) $sale['id']);
    foreach ($items as &$row) {
        $row['already'] = $already[(int) $row['id']]['qty'] ?? 0;
        $row['already_value'] = $already[(int) $row['id']]['value'] ?? 0;
        $row['returnable'] = max(0, (int) $row['quantity'] - $row['already']);
        $row['unit_value'] = (int) $row['quantity'] > 0 ? (float) $row['line_total'] / (int) $row['quantity'] : 0;
    }
    unset($row);
    $origMethods = $pdo->prepare('SELECT method FROM sale_payments WHERE sale_id = :id ORDER BY amount DESC');
    $origMethods->execute([':id' => $sale['id']]);
    $origMethods = $origMethods->fetchAll(PDO::FETCH_COLUMN);
    $outsideWindow = $windowDays > 0 && strtotime($sale['sale_date']) < strtotime("-{$windowDays} days");
    $needsApproval = !can('returns.approve') && (setting('returns_require_approval', '1') === '1' || $outsideWindow);
}

if (is_post() && $sale) {
    verify_csrf();
    $qtys = $_POST['qty'] ?? [];
    $conds = $_POST['condition'] ?? [];
    $reason = input($_POST, 'reason_pick');
    $reasonText = input($_POST, 'reason_text');
    $fullReason = trim($reason . ($reasonText !== '' ? ' — ' . $reasonText : ''));
    $type = input($_POST, 'return_type') === 'exchange' ? 'exchange' : 'refund';
    $refundMethod = input($_POST, 'refund_method');
    $refundRef = input($_POST, 'refund_reference');

    $lines = [];
    $total = 0.0; $tax = 0.0; $cost = 0.0;
    foreach ($items as $row) {
        $q = (int) ($qtys[$row['id']] ?? 0);
        if ($q <= 0) continue;
        if ($q > $row['returnable']) { $errors[] = 'You can return at most ' . $row['returnable'] . ' of ' . $row['product_name'] . '.'; continue; }
        // Returning everything that's left takes the exact remaining value (no rounding drift).
        $value = $q === $row['returnable'] ? round((float) $row['line_total'] - $row['already_value'], 2) : round($row['unit_value'] * $q, 2);
        $lineTax = round((float) $row['tax_amount'] / max(1, (int) $row['quantity']) * $q, 2);
        $condition = ($conds[$row['id']] ?? '') === 'damaged' ? 'damaged' : 'resellable';
        $lines[] = ['row' => $row, 'qty' => $q, 'value' => $value, 'tax' => $lineTax, 'condition' => $condition];
        $total += $value; $tax += $lineTax; $cost += $q * (float) $row['unit_cost'];
    }
    if (!$lines && !$errors) $errors[] = 'Enter a return quantity for at least one item.';
    if ($reason === '') $errors[] = 'Select the reason for the return.';
    if ($type === 'refund') {
        if (!isset(refund_methods()[$refundMethod])) $errors[] = 'Choose how the money will be refunded.';
        if ($refundMethod === 'store_credit' && !$sale['customer_id']) $errors[] = 'Account credit needs a registered customer on the original sale.';
    } else {
        $refundMethod = 'exchange';
    }

    $approverId = null;
    $approverName = null;
    if (!$errors) {
        if (!$needsApproval) {
            $approverId = (int) $user['id'];
        } elseif (input($_POST, 'override_username') !== '') {
            $approver = authorize_override(input($_POST, 'override_username'), $_POST['override_password'] ?? '', 'returns.approve');
            if (is_array($approver)) { $approverId = (int) $approver['id']; $approverName = $approver['full_name']; } else $errors[] = $approver;
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            // Re-check availability under lock (two cashiers returning the same receipt).
            $pdo->prepare('SELECT id FROM sales WHERE id = :id FOR UPDATE')->execute([':id' => $sale['id']]);
            $fresh = returned_quantities($pdo, (int) $sale['id']);
            foreach ($lines as $l) {
                if ($l['qty'] > (int) $l['row']['quantity'] - ($fresh[(int) $l['row']['id']]['qty'] ?? 0)) throw new RuntimeException('Another return was just recorded for this sale. Reload and try again.');
            }
            // The return is booked at the branch where the goods come back (stock, refund cash and numbering follow it).
            $retBranch = write_branch();
            $number = next_document_number('RET', 'sale_returns', 'return_number', $retBranch);
            $pdo->prepare("INSERT INTO sale_returns (sale_id, branch_id, customer_id, return_number, status, return_type, total_amount, tax_amount, cost_amount, refund_amount, refund_method, refund_reference, reason, created_by)
                           VALUES (:s,:br,:c,:n,'pending',:t,:tot,:tax,:cost,:ref,:m,:rr,:reason,:u)")
                ->execute([':br' => $retBranch, ':s' => $sale['id'], ':c' => $sale['customer_id'], ':n' => $number, ':t' => $type, ':tot' => round($total, 2), ':tax' => round($tax, 2), ':cost' => round($cost, 2),
                           ':ref' => $type === 'refund' ? round($total, 2) : 0, ':m' => $refundMethod, ':rr' => $refundRef ?: null, ':reason' => mb_substr($fullReason, 0, 255), ':u' => $user['id']]);
            $returnId = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO sale_return_items (return_id, sale_item_id, variant_id, quantity, unit_price, unit_cost, tax_amount, line_total, item_condition) VALUES (:r,:si,:v,:q,:p,:c,:t,:lt,:cond)');
            foreach ($lines as $l) {
                $ins->execute([':r' => $returnId, ':si' => $l['row']['id'], ':v' => $l['row']['variant_id'], ':q' => $l['qty'], ':p' => $l['row']['unit_price'], ':c' => $l['row']['unit_cost'], ':t' => $l['tax'], ':lt' => $l['value'], ':cond' => $l['condition']]);
            }
            if ($approverId) process_sale_return($pdo, $returnId, $approverId);
            $pdo->commit();

            $summary = $number . ' for ' . $sale['invoice_number'] . ' · ' . format_money($total) . ' · ' . ($type === 'exchange' ? 'exchange' : 'refund via ' . refund_methods()[$refundMethod]) . ' · reason: ' . $fullReason;
            log_activity('return_create', $summary . ($approverId ? '' : ' · awaiting approval'), 'sale_return', $returnId);
            if ($approverId) log_activity('return_approve', 'Approved ' . $number . ($approverName ? ' (authorized at counter by ' . $approverName . ')' : ''), 'sale_return', $returnId);

            if ($approverId) {
                flash('success', $type === 'exchange'
                    ? 'Exchange ' . $number . ' approved. Credit of ' . format_money($total) . ' is ready — add the replacement items.'
                    : 'Return ' . $number . ' processed. Refund ' . format_money($total) . ' by ' . refund_methods()[$refundMethod] . '.');
                if ($type === 'exchange' && can('pos.checkout')) redirect('/pos/index.php?exchange=' . urlencode($number));
            } else {
                flash('warning', 'Return ' . $number . ' saved and sent for manager approval. No refund has been given yet.');
            }
            redirect('/pos/return_view.php?id=' . $returnId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Return failed: ' . $ex->getMessage());
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the return. Please try again.';
        }
    }
}

$pageTitle = 'New Return';
require __DIR__ . '/../includes/header.php';
page_header('Return or Exchange', 'Find the original sale, choose the items coming back and how the customer is compensated.', '<a class="btn btn-secondary" href="' . e(app_url('/pos/returns.php')) . '">Cancel</a>', [['Returns', '/pos/returns.php'], ['New', null]]);
?>
<div class="steps">
  <div class="step <?= $sale ? 'done' : 'current' ?>"><span class="n">1</span> Find the sale</div>
  <div class="step <?= $sale ? 'current' : '' ?>"><span class="n">2</span> Select items & condition</div>
  <div class="step"><span class="n">3</span> Refund or exchange</div>
</div>

<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<?php if ($sale && current_branch_id() === null): ?>
  <div class="alert alert-warning"><?= icon('building-2', 18) ?><span>You are viewing all branches. Choose the branch receiving this return (top bar) before saving.</span></div>
<?php elseif ($sale && (int) $sale['branch_id'] !== current_branch_id()): ?>
  <div class="alert alert-info"><?= icon('building-2', 18) ?><span>This sale was made at <strong><?= e(branch_row((int) $sale['branch_id'])['name'] ?? 'another branch') ?></strong>. The goods return to <strong><?= e(branch_scope_label()) ?></strong> stock and any cash refund is paid from this branch's till.</span></div>
<?php endif; ?>

<?php if (!$sale): ?>
  <div class="card" style="max-width:640px;">
    <h3 class="mb-2">Find the original sale</h3>
    <p class="text-muted text-sm mb-4">Scan the barcode on the receipt or type the invoice number. You can also open the sale from Sales History and choose “Return / Exchange”.</p>
    <form method="get" class="flex gap-2">
      <input class="form-control" type="text" name="invoice" value="<?= e($lookup) ?>" placeholder="e.g. INV-000123" autofocus required>
      <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?> Find sale</button>
    </form>
    <div class="mt-4 text-sm"><a class="link" href="<?= e(app_url('/pos/history.php')) ?>">Browse sales history →</a></div>
  </div>
<?php else: ?>
<form method="post" id="returnForm">
  <?= csrf_field() ?><input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
  <div class="layout-2-1">
    <div class="card flush">
      <div class="card-head">
        <div><h3>Items on <?= e($sale['invoice_number']) ?></h3><div class="sub"><?= e(fmt_datetime($sale['sale_date'])) ?> · <?= e($sale['customer_name'] ?? 'Walk-in') ?> · Cashier <?= e($sale['cashier_name']) ?></div></div>
        <button class="btn btn-secondary btn-sm" type="button" id="returnAll">Return all</button>
      </div>
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Item</th><th class="num">Sold</th><th class="num">Returned</th><th class="num">Paid / unit</th><th style="width:110px;">Return qty</th><th style="width:170px;">Condition</th></tr></thead>
          <tbody>
          <?php foreach ($items as $row): ?>
            <tr class="<?= $row['returnable'] <= 0 ? 'row-muted' : '' ?>">
              <td><div class="strong"><?= e($row['product_name']) ?><?= $row['variant_label'] ? ' — ' . e($row['variant_label']) : '' ?></div><div class="cell-sub mono"><?= e($row['sku']) ?></div></td>
              <td class="num"><?= (int) $row['quantity'] ?></td>
              <td class="num"><?= $row['already'] ?: '—' ?></td>
              <td class="num"><?= format_money($row['unit_value']) ?></td>
              <td><?php if ($row['returnable'] > 0): ?><input class="form-control ret-qty" type="number" name="qty[<?= (int) $row['id'] ?>]" min="0" max="<?= $row['returnable'] ?>" value="<?= e((string) ($_POST['qty'][$row['id']] ?? 0)) ?>" data-unit="<?= e((string) $row['unit_value']) ?>" data-max="<?= $row['returnable'] ?>"><?php else: ?><span class="text-muted text-sm">Fully returned</span><?php endif; ?></td>
              <td><?php if ($row['returnable'] > 0): ?>
                <select class="form-control" name="condition[<?= (int) $row['id'] ?>]">
                  <option value="resellable">Good — back to shelf</option>
                  <option value="damaged" <?= ($_POST['condition'][$row['id']] ?? '') === 'damaged' ? 'selected' : '' ?>>Damaged / defective</option>
                </select><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="callout" style="margin:16px;"><?= icon('info', 14) ?> <strong>Damaged / defective</strong> items are recorded in separate <em>damaged stock</em> and are <u>not</u> made available for sale. They can later be returned to the supplier or written off.</div>
    </div>

    <div class="grid">
      <div class="card">
        <div class="kpi-label">Value of returned items</div>
        <div class="kpi-value hero" id="retTotal"><?= format_money(0) ?></div>
        <div class="kpi-desc">Exactly what the customer paid for these items, including discounts and tax.</div>
      </div>
      <div class="card">
        <div class="form-group">
          <label>Reason for return</label>
          <select class="form-control" name="reason_pick" required>
            <option value="">Select a reason…</option>
            <?php foreach (return_reasons() as $r): ?><option <?= input($_POST, 'reason_pick') === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
          </select>
          <input class="form-control mt-2" type="text" name="reason_text" maxlength="200" placeholder="Details (optional), e.g. screen flickers" value="<?= e(input($_POST, 'reason_text')) ?>">
        </div>
        <div class="form-group">
          <label>Compensation</label>
          <div class="option-cards">
            <label class="option-card"><input type="radio" name="return_type" value="refund" <?= input($_POST, 'return_type') !== 'exchange' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('banknote', 15) ?> Refund</span><span class="oc-desc">Give the money back</span></span></label>
            <label class="option-card"><input type="radio" name="return_type" value="exchange" <?= input($_POST, 'return_type') === 'exchange' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('repeat', 15) ?> Exchange</span><span class="oc-desc">Credit towards replacement items</span></span></label>
          </div>
        </div>
        <div id="refundFields">
          <div class="form-group">
            <label>Refund method</label>
            <?php $defaultMethod = in_array('store_credit', $origMethods, true) ? 'store_credit' : (in_array($origMethods[0] ?? 'cash', ['card', 'bank_transfer', 'mobile_wallet'], true) ? $origMethods[0] : 'cash'); ?>
            <select class="form-control" name="refund_method">
              <?php foreach (refund_methods() as $k => $l): if ($k === 'store_credit' && !$sale['customer_id']) continue; ?>
                <option value="<?= $k ?>" <?= (input($_POST, 'refund_method') ?: $defaultMethod) === $k ? 'selected' : '' ?>><?= e($l) ?><?= in_array($k, $origMethods, true) ? ' (original payment)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-0"><label>Refund reference <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="refund_reference" maxlength="60" placeholder="Card reversal / transfer reference" value="<?= e(input($_POST, 'refund_reference')) ?>"></div>
        </div>
      </div>

      <?php if ($needsApproval): ?>
      <div class="card">
        <h4 class="mb-2"><?= icon('shield-check', 15) ?> Manager approval needed</h4>
        <p class="text-muted text-sm"><?= $outsideWindow ? 'This sale is older than the ' . $windowDays . '-day return window. ' : '' ?>A manager can approve now by signing in below, or leave it blank to send the return for approval later (no refund is given until approved).</p>
        <div class="form-row"><input class="form-control" type="text" name="override_username" placeholder="Manager username" autocomplete="off"><input class="form-control" type="password" name="override_password" placeholder="Password" autocomplete="new-password"></div>
      </div>
      <?php endif; ?>

      <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('check', 18) ?> <?= $needsApproval ? 'Submit return' : 'Process return' ?></button>
    </div>
  </div>
</form>
<script>
(function () {
  const inputs = document.querySelectorAll('.ret-qty');
  function total() {
    let t = 0;
    inputs.forEach((i) => { let q = Math.min(parseInt(i.value, 10) || 0, +i.dataset.max); if (q < 0) q = 0; t += q * parseFloat(i.dataset.unit); });
    document.getElementById('retTotal').textContent = CalimaxUI.money(t);
  }
  inputs.forEach((i) => i.addEventListener('input', total));
  document.getElementById('returnAll').addEventListener('click', () => { inputs.forEach((i) => { i.value = i.dataset.max; }); total(); });
  function toggle() { document.getElementById('refundFields').hidden = document.querySelector('input[name=return_type]:checked').value !== 'refund'; }
  document.querySelectorAll('input[name=return_type]').forEach((r) => r.addEventListener('change', toggle));
  toggle(); total();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
