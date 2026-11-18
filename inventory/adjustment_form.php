<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stock.php';
require_permission('inventory.adjust');
require_single_branch();

$pdo = db();
$errors = [];
$autoApprove = can('inventory.approve') || setting('adjustments_require_approval', '1') !== '1';

// Pre-fill from a product or variant link.
$prefill = [];
$pv = input_int($_GET, 'variant_id');
$pp = input_int($_GET, 'product_id');
if ($pv || $pp) {
    $s = $pdo->prepare('SELECT pv.id, pv.sku, pv.barcode, pv.variant_label, pv.stock_qty, pv.damaged_qty, pv.cost_price, p.name AS product_name FROM ' . pv() . ' pv JOIN products p ON p.id = pv.product_id WHERE ' . ($pv ? 'pv.id = :id' : 'pv.product_id = :id AND pv.is_active = 1'));
    $s->execute([':id' => $pv ?: $pp]);
    foreach ($s->fetchAll() as $r) {
        $prefill[] = ['variant_id' => (int) $r['id'], 'product_name' => $r['product_name'], 'variant_label' => $r['variant_label'], 'sku' => $r['sku'], 'stock_qty' => (int) $r['stock_qty'], 'damaged_qty' => (int) $r['damaged_qty'], 'unit_cost' => can('products.view_cost') ? (float) $r['cost_price'] : null, 'qty' => 1];
    }
}

if (is_post()) {
    verify_csrf();
    $reason = input($_POST, 'reason');
    $note = input($_POST, 'note');
    $lines = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $clean = [];
    foreach ($lines as $l) {
        $q = (int) ($l['qty'] ?? 0);
        if (!empty($l['variant_id']) && $q !== 0) $clean[] = ['variant_id' => (int) $l['variant_id'], 'qty' => $q, 'note' => mb_substr((string) ($l['note'] ?? ''), 0, 255) ?: null];
    }
    if (!isset(adjustment_reasons()[$reason])) $errors[] = 'Choose the reason for this adjustment.';
    if (!$clean) $errors[] = 'Add at least one item with a quantity.';
    if ($note === '') $errors[] = 'Add a short note explaining what happened (required for the audit trail).';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $id = create_stock_adjustment($pdo, $reason, $clean, $note, $autoApprove);
            $pdo->commit();
            $num = $pdo->query('SELECT adj_number FROM stock_adjustments WHERE id = ' . $id)->fetchColumn();
            log_activity('stock_adjustment_create', $num . ' · ' . adjustment_reason_label($reason) . ' · ' . count($clean) . ' item(s) · ' . $note . ($autoApprove ? ' · approved' : ' · awaiting approval'), 'stock_adjustment', $id);
            if ($autoApprove) log_activity('stock_adjustment_approve', 'Approved ' . $num . ' (self-approved by authorized user)', 'stock_adjustment', $id);
            flash($autoApprove ? 'success' : 'warning', $autoApprove ? "Adjustment $num applied to stock." : "Adjustment $num sent for manager approval. Stock will change once approved.");
            redirect('/inventory/adjustment_view.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Adjustment failed: ' . $ex->getMessage());
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save the adjustment.';
            $prefill = $lines;
        }
    } else {
        $prefill = $lines;
    }
}

$pageTitle = 'New Stock Adjustment';
require __DIR__ . '/../includes/header.php';
page_header('New stock adjustment', $autoApprove ? 'You are authorized — this adjustment applies to stock immediately.' : 'This adjustment will be sent to a manager for approval before stock changes.', '<a class="btn btn-secondary" href="' . e(app_url('/inventory/adjustments.php')) . '">Cancel</a>', [['Stock Control', '/inventory/adjustments.php'], ['New adjustment', null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="adjForm">
  <?= csrf_field() ?><input type="hidden" name="items_json" id="items_json">
  <div class="card mb-4">
    <div class="form-section-title">1. What happened?</div>
    <div class="option-cards">
      <?php foreach (adjustment_reasons() as $k => [$label, $desc, $dir, $ico]): ?>
        <label class="option-card"><input type="radio" name="reason" value="<?= $k ?>" data-dir="<?= $dir ?>" <?= input($_POST, 'reason') === $k ? 'checked' : '' ?> required><span class="oc-body"><span class="oc-title"><?= icon($ico, 15) ?> <?= e($label) ?></span><span class="oc-desc"><?= e($desc) ?></span></span></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card flush mb-4">
    <div class="card-head"><div><h3>2. Items</h3><div class="sub" id="qtyHint">Enter how many units are affected.</div></div>
      <div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Scan barcode or search product to add" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">Saleable</th><th class="num">Damaged</th><th style="width:120px;">Quantity</th><th class="num">After</th><?php if (can('products.view_cost')): ?><th class="num">Value</th><?php endif; ?><th>Line note</th><th></th></tr></thead>
      <tbody id="itemsBody"></tbody>
    </table></div>
    <div id="emptyItems"><?= empty_state('scan-barcode', 'No items added', 'Scan or search above to add the affected products.') ?></div>
  </div>
  <div class="card">
    <div class="form-group"><label for="note">3. Explanation <span class="label-opt">(required)</span></label><input class="form-control" type="text" id="note" name="note" maxlength="255" required placeholder="e.g. Carton dropped during unloading; 3 bottles broken" value="<?= e(input($_POST, 'note')) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary btn-lg" type="submit"><?= icon('check', 18) ?> <?= $autoApprove ? 'Apply adjustment' : 'Submit for approval' ?></button></div>
  </div>
</form>
<script>
(function () {
  const seeCost = <?= json_encode(can('products.view_cost')) ?>;
  let items = <?= json_encode(array_values($prefill), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const body = document.getElementById('itemsBody');
  const esc = CalimaxUI.esc;
  function dir() { const r = document.querySelector('input[name=reason]:checked'); return r ? +r.dataset.dir : -1; }
  function reason() { const r = document.querySelector('input[name=reason]:checked'); return r ? r.value : ''; }
  function render() {
    const d = dir(), rs = reason();
    document.getElementById('qtyHint').textContent = d === 0 ? 'Enter + to add or − to remove (e.g. -3).' : (d > 0 ? 'Units being added.' : 'Units being removed.');
    body.innerHTML = items.map((it, i) => {
      const q = parseInt(it.qty, 10) || 0;
      const signed = d === 0 ? q : Math.abs(q) * d;
      const fromDamaged = rs === 'write_off_damaged' || rs === 'restore_damaged';
      let afterS = it.stock_qty, afterD = it.damaged_qty;
      if (rs === 'damaged') { afterS -= Math.abs(q); afterD += Math.abs(q); }
      else if (rs === 'restore_damaged') { afterD -= Math.abs(q); afterS += Math.abs(q); }
      else if (fromDamaged) afterD -= Math.abs(q); else afterS += signed;
      const bad = afterS < 0 || afterD < 0;
      const valueLost = ['damaged', 'restore_damaged'].includes(rs) ? 0 : signed * (it.unit_cost || 0);
      return '<tr class="' + (bad ? 'row-bad' : '') + '"><td><div class="strong">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div><div class="cell-sub mono">' + esc(it.sku) + '</div></td>'
        + '<td class="num">' + it.stock_qty + '</td><td class="num">' + it.damaged_qty + '</td>'
        + '<td><input class="form-control" type="number" value="' + q + '" data-i="' + i + '" data-f="qty"' + (d !== 0 ? ' min="1"' : '') + '></td>'
        + '<td class="num strong">' + (fromDamaged || rs === 'damaged' || rs === 'restore_damaged' ? afterS + ' / ' + afterD + ' dmg' : afterS) + (bad ? ' <span class="badge badge-error">not enough</span>' : '') + '</td>'
        + (seeCost ? '<td class="num ' + (valueLost < 0 ? 'text-error' : '') + '">' + (['damaged', 'restore_damaged'].includes(rs) ? '—' : CalimaxUI.money(valueLost)) + '</td>' : '')
        + '<td><input class="form-control" type="text" maxlength="255" value="' + esc(it.note || '') + '" data-i="' + i + '" data-f="note" placeholder="optional"></td>'
        + '<td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '" aria-label="Remove">&times;</button></td></tr>';
    }).join('');
    document.getElementById('emptyItems').hidden = items.length > 0;
    document.getElementById('items_json').value = JSON.stringify(items.map((it) => ({ variant_id: it.variant_id, qty: it.qty, note: it.note || '', product_name: it.product_name, variant_label: it.variant_label, sku: it.sku, stock_qty: it.stock_qty, damaged_qty: it.damaged_qty, unit_cost: it.unit_cost })));
  }
  body.addEventListener('change', (e) => { const i = e.target.dataset.i; if (i === undefined) return; items[i][e.target.dataset.f] = e.target.dataset.f === 'qty' ? (parseInt(e.target.value, 10) || 0) : e.target.value; render(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });
  document.querySelectorAll('input[name=reason]').forEach((r) => r.addEventListener('change', render));
  CalimaxUI.variantPicker(document.getElementById('itemSearch'), document.getElementById('searchResults'), (v) => {
    const ex = items.find((i) => i.variant_id == v.id);
    if (ex) { ex.qty = (parseInt(ex.qty, 10) || 0) + 1; }
    else items.push({ variant_id: +v.id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku, stock_qty: +v.stock_qty, damaged_qty: +v.damaged_qty, unit_cost: v.cost_price !== undefined ? +v.cost_price : 0, qty: 1 });
    render();
  });
  document.getElementById('adjForm').addEventListener('submit', (e) => { if (!items.length) { e.preventDefault(); CalimaxUI.toast('Add at least one item.', 'error'); } });
  render();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
