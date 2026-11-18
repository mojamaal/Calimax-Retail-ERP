<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');
$retBranch = require_single_branch();

$pdo = db();
$errors = [];
$prefill = [];
if (input($_GET, 'damaged') === '1') {
    $prefill = $pdo->query("SELECT pv.id AS variant_id, p.name AS product_name, pv.variant_label, pv.sku, pv.stock_qty, pv.damaged_qty, pv.cost_price AS unit_cost, pv.damaged_qty AS qty, 'damaged' AS bucket FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id WHERE pv.damaged_qty > 0 ORDER BY p.name")->fetchAll();
}

if (is_post()) {
    verify_csrf();
    $supplierId = input_int($_POST, 'supplier_id');
    $returnDate = input_date($_POST, 'return_date', date('Y-m-d'));
    $reason = input($_POST, 'reason');
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $settle = input($_POST, 'settlement') === 'refund' ? 'refund' : 'credit';
    $refundMethod = input($_POST, 'refund_method');
    if (!$supplierId) $errors[] = 'Select the supplier.';
    if ($reason === '') $errors[] = 'Enter the reason for returning these goods.';
    if (!$items) $errors[] = 'Add at least one item to return.';
    if ($settle === 'refund' && !isset(money_methods()[$refundMethod])) $errors[] = 'Choose how the supplier refunded you.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $total = 0.0;
            $lines = [];
            foreach ($items as $it) {
                $q = (int) ($it['qty'] ?? 0); $vid = (int) ($it['variant_id'] ?? 0); $bucket = ($it['bucket'] ?? '') === 'damaged' ? 'damaged' : 'saleable';
                if ($q <= 0 || !$vid) continue;
                $lines[] = ['vid' => $vid, 'qty' => $q, 'cost' => max(0, round((float) ($it['unit_cost'] ?? 0), 2)), 'bucket' => $bucket];
                $total += $q * max(0, round((float) ($it['unit_cost'] ?? 0), 2));
            }
            if (!$lines) throw new RuntimeException('Enter a quantity for at least one item.');
            $number = next_document_number('PRET', 'purchase_returns', 'return_number', $retBranch);
            $pdo->prepare('INSERT INTO purchase_returns (return_number, branch_id, supplier_id, return_date, reason, total_amount, created_by) VALUES (:n,:br,:s,:d,:r,:t,:u)')
                ->execute([':n' => $number, ':br' => $retBranch, ':s' => $supplierId, ':d' => $returnDate, ':r' => $reason, ':t' => round($total, 2), ':u' => current_user()['id']]);
            $rid = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO purchase_return_items (return_id, variant_id, quantity, unit_cost, bucket) VALUES (:r,:v,:q,:c,:b)');
            foreach ($lines as $l) {
                $ins->execute([':r' => $rid, ':v' => $l['vid'], ':q' => $l['qty'], ':c' => $l['cost'], ':b' => $l['bucket']]);
                ensure_branch_stock($pdo, $l['vid'], $retBranch);
                $avail = $pdo->prepare('SELECT bs.' . ($l['bucket'] === 'damaged' ? 'damaged_qty' : 'stock_qty') . ', pvx.sku FROM branch_stock bs JOIN product_variants pvx ON pvx.id = bs.variant_id WHERE bs.variant_id = :id AND bs.branch_id = ' . (int) $retBranch . ' FOR UPDATE');
                $avail->execute([':id' => $l['vid']]);
                [$have, $sku] = $avail->fetch(PDO::FETCH_NUM) ?: [0, '#' . $l['vid']];
                if ((int) $have < $l['qty']) throw new RuntimeException("Only $have " . ($l['bucket'] === 'damaged' ? 'damaged' : 'saleable') . " unit(s) of $sku available to return.");
                apply_stock_change($pdo, $l['vid'], -$l['qty'], 'purchase_return', 'purchase_return', $rid, 'Returned to supplier ' . $number, $l['bucket'], $retBranch);
            }
            // Supplier owes us this value: reduce our balance, or record the refund they paid.
            supplier_balance_adjust($pdo, $supplierId, $retBranch, -round($total, 2));
            if ($settle === 'refund') {
                // A cash/bank refund from the supplier is a negative payment (money coming back to us).
                $pdo->prepare('INSERT INTO supplier_payments (supplier_id, branch_id, amount, payment_method, reference_no, payment_date, note, created_by) VALUES (:s,:br,:a,:m,:r,:d,:n,:u)')
                    ->execute([':s' => $supplierId, ':br' => $retBranch, ':a' => -round($total, 2), ':m' => $refundMethod, ':r' => input($_POST, 'refund_reference') ?: null, ':d' => $returnDate, ':n' => 'Refund for ' . $number, ':u' => current_user()['id']]);
                supplier_balance_adjust($pdo, $supplierId, $retBranch, round($total, 2));
            }
            $pdo->commit();
            log_activity('purchase_return', $number . ' · ' . count($lines) . ' item(s) · ' . format_money($total) . ' · ' . ($settle === 'refund' ? 'refunded by ' . method_label($refundMethod) : 'credited to account') . ' · ' . $reason, 'purchase_return', $rid);
            flash('success', "Return $number recorded. Stock reduced and " . ($settle === 'refund' ? 'refund recorded.' : format_money($total) . ' deducted from what you owe the supplier.'));
            redirect('/purchase/return_view.php?id=' . $rid);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Purchase return failed: ' . $ex->getMessage());
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Something went wrong while saving the return.';
        }
    }
    $prefill = $items;
}

$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();
$pageTitle = 'Return to Supplier';
require __DIR__ . '/../includes/header.php';
page_header('Return goods to supplier', 'Send back damaged, expired or excess stock. You can return saleable items or items from damaged stock.', '<a class="btn btn-secondary" href="' . e(app_url('/purchase/returns.php')) . '">Cancel</a>', [['Returns to supplier', '/purchase/returns.php'], ['New', null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="retForm">
  <?= csrf_field() ?><input type="hidden" name="items_json" id="items_json">
  <div class="card mb-4"><div class="form-row">
    <div class="form-group"><label>Supplier</label><select class="form-control" name="supplier_id" required><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= input_int($_POST, 'supplier_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group"><label>Return date</label><input class="form-control" type="date" name="return_date" value="<?= e(input($_POST, 'return_date') ?: date('Y-m-d')) ?>"></div>
    <div class="form-group" style="flex:2;"><label>Reason</label><input class="form-control" type="text" name="reason" maxlength="255" required placeholder="e.g. Defective units returned by customers" value="<?= e(input($_POST, 'reason')) ?>"></div>
  </div></div>
  <div class="card flush mb-4">
    <div class="card-head"><div><h3>Items</h3><div class="sub"><a class="link" href="?damaged=1">Load all damaged stock</a></div></div>
      <div style="position:relative;width:min(380px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Scan barcode or search product" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th style="width:170px;">From</th><th class="num">Available</th><th style="width:110px;">Qty</th><th style="width:130px;">Unit cost</th><th class="num">Line total</th><th></th></tr></thead>
      <tbody id="itemsBody"></tbody>
      <tfoot><tr><td colspan="5" class="text-right">Return value</td><td class="num" id="grandTotal"><?= format_money(0) ?></td><td></td></tr></tfoot>
    </table></div>
    <div id="emptyItems"><?= empty_state('undo-2', 'No items yet', 'Search or scan the products being sent back.') ?></div>
  </div>
  <div class="card">
    <div class="form-section-title">How will the supplier settle?</div>
    <div class="option-cards mb-3" style="max-width:640px;">
      <label class="option-card"><input type="radio" name="settlement" value="credit" checked><span class="oc-body"><span class="oc-title"><?= icon('file-minus', 15) ?> Credit note</span><span class="oc-desc">Deduct from what we owe them</span></span></label>
      <label class="option-card"><input type="radio" name="settlement" value="refund"><span class="oc-body"><span class="oc-title"><?= icon('banknote', 15) ?> Refund received</span><span class="oc-desc">They paid us back</span></span></label>
    </div>
    <div class="form-row" id="refundFields" hidden style="max-width:640px;">
      <div class="form-group"><label>Refunded by</label><select class="form-control" name="refund_method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Reference</label><input class="form-control" type="text" name="refund_reference" maxlength="60"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg"><?= icon('check', 18) ?> Save return</button></div>
  </div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, money = CalimaxUI.money;
  let items = <?= json_encode(array_values(array_map(fn($p) => ['variant_id' => (int) $p['variant_id'], 'product_name' => $p['product_name'], 'variant_label' => $p['variant_label'], 'sku' => $p['sku'], 'stock_qty' => (int) $p['stock_qty'], 'damaged_qty' => (int) $p['damaged_qty'], 'unit_cost' => (float) $p['unit_cost'], 'qty' => (int) $p['qty'], 'bucket' => $p['bucket'] ?? 'saleable'], $prefill)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const body = document.getElementById('itemsBody');
  function render() {
    let t = 0;
    body.innerHTML = items.map((it, i) => { const avail = it.bucket === 'damaged' ? it.damaged_qty : it.stock_qty; const lt = it.qty * it.unit_cost; t += lt;
      return '<tr class="' + (it.qty > avail ? 'row-bad' : '') + '"><td><div class="strong">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div><div class="cell-sub mono">' + esc(it.sku) + '</div></td>'
        + '<td><select class="form-control" data-i="' + i + '" data-f="bucket"><option value="saleable"' + (it.bucket !== 'damaged' ? ' selected' : '') + '>Saleable stock</option><option value="damaged"' + (it.bucket === 'damaged' ? ' selected' : '') + '>Damaged stock</option></select></td>'
        + '<td class="num">' + avail + '</td><td><input class="form-control" type="number" min="1" max="' + avail + '" value="' + it.qty + '" data-i="' + i + '" data-f="qty"></td>'
        + '<td><input class="form-control" type="number" min="0" step="0.01" value="' + it.unit_cost + '" data-i="' + i + '" data-f="unit_cost"></td><td class="num strong">' + money(lt) + '</td>'
        + '<td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '">&times;</button></td></tr>'; }).join('');
    document.getElementById('grandTotal').textContent = money(t);
    document.getElementById('emptyItems').hidden = items.length > 0;
    document.getElementById('items_json').value = JSON.stringify(items);
  }
  body.addEventListener('change', (e) => { const i = e.target.dataset.i; if (i === undefined) return; const f = e.target.dataset.f; items[i][f] = f === 'bucket' ? e.target.value : (parseFloat(e.target.value) || 0); render(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });
  CalimaxUI.variantPicker(document.getElementById('itemSearch'), document.getElementById('searchResults'), (v) => {
    items.push({ variant_id: +v.id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku, stock_qty: +v.stock_qty, damaged_qty: +v.damaged_qty, unit_cost: +(v.cost_price || 0), qty: 1, bucket: +v.damaged_qty > 0 && +v.stock_qty === 0 ? 'damaged' : 'saleable' });
    render();
  });
  document.querySelectorAll('input[name=settlement]').forEach((r) => r.addEventListener('change', () => { document.getElementById('refundFields').hidden = document.querySelector('input[name=settlement]:checked').value !== 'refund'; }));
  document.getElementById('retForm').addEventListener('submit', (e) => { if (!items.length) { e.preventDefault(); CalimaxUI.toast('Add at least one item.', 'error'); } });
  render();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
