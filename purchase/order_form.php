<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$errors = [];
$editId = input_int($_GET, 'id');
$po = null;
if ($editId) {
    $s = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id');
    $s->execute([':id' => $editId]);
    $po = $s->fetch();
    if (!$po || $po['status'] !== 'draft') { flash('error', 'Only draft purchase orders can be edited.'); redirect('/purchase/order_view.php?id=' . $editId); }
    assert_branch_access((int) $po['branch_id']);
}
// Each branch raises its own orders and pays its own supplier bills.
$poBranch = $po ? (int) $po['branch_id'] : require_single_branch();
$PV = pv($poBranch);

$itemCols = 'pv.id AS variant_id, p.name AS product_name, pv.variant_label, pv.sku, pv.barcode, pv.stock_qty, pv.reorder_level, pv.cost_price';
$prefill = [];
if ($po) {
    $s = $pdo->prepare("SELECT $itemCols, poi.quantity AS qty, poi.unit_cost FROM purchase_order_items poi JOIN $PV pv ON pv.id = poi.variant_id JOIN products p ON p.id = pv.product_id WHERE poi.po_id = :id");
    $s->execute([':id' => $po['id']]);
    $prefill = $s->fetchAll();
} elseif ($ids = array_filter(array_map('intval', explode(',', input($_GET, 'variant_ids'))))) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT $itemCols FROM $PV pv JOIN products p ON p.id = pv.product_id WHERE pv.id IN ($ph) AND pv.is_active = 1");
    $s->execute(array_values($ids));
    $prefill = $s->fetchAll();
} elseif (input($_GET, 'suggest') === '1') {
    $prefill = $pdo->query("SELECT $itemCols FROM $PV pv JOIN products p ON p.id = pv.product_id WHERE pv.is_active = 1 AND p.is_active = 1 AND pv.stock_qty <= pv.reorder_level ORDER BY p.name LIMIT 200")->fetchAll();
}
foreach ($prefill as &$pf) {
    $pf['qty'] = (int) ($pf['qty'] ?? max(1, (int) $pf['reorder_level'] * 2 - (int) $pf['stock_qty']));
    $pf['unit_cost'] = (float) ($pf['unit_cost'] ?? $pf['cost_price']);
}
unset($pf);

if (is_post()) {
    verify_csrf();
    $supplierId = input_int($_POST, 'supplier_id');
    $orderDate = input_date($_POST, 'order_date', date('Y-m-d'));
    $expectedDate = input_date($_POST, 'expected_date');
    $notes = input($_POST, 'notes');
    $status = input($_POST, 'status') === 'ordered' ? 'ordered' : 'draft';
    $items = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    $merged = [];
    foreach ($items as $it) {
        $vid = (int) ($it['variant_id'] ?? 0); $q = (int) ($it['qty'] ?? 0); $c = round((float) ($it['unit_cost'] ?? -1), 2);
        if (!$vid || $q <= 0 || $c < 0) { $errors[] = 'Each line needs a quantity of at least 1 and a cost.'; break; }
        if (isset($merged[$vid])) $merged[$vid]['qty'] += $q; else $merged[$vid] = ['qty' => $q, 'unit_cost' => $c];
    }
    if (!$supplierId) $errors[] = 'Select the supplier.';
    if (!$merged && !$errors) $errors[] = 'Add at least one product to the order.';
    if ($expectedDate && $expectedDate < $orderDate) $errors[] = 'Expected delivery cannot be before the order date.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $total = array_sum(array_map(fn($m) => $m['qty'] * $m['unit_cost'], $merged));
            if ($po) {
                $pdo->prepare('UPDATE purchase_orders SET supplier_id=:s, status=:st, order_date=:od, expected_date=:ed, notes=:n, subtotal=:t, total_amount=:t2 WHERE id=:id')
                    ->execute([':s' => $supplierId, ':st' => $status, ':od' => $orderDate, ':ed' => $expectedDate, ':n' => $notes ?: null, ':t' => $total, ':t2' => $total, ':id' => $po['id']]);
                $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id = :id')->execute([':id' => $po['id']]);
                $poId = (int) $po['id'];
                $poNumber = $po['po_number'];
            } else {
                $poNumber = next_document_number(setting('po_prefix', 'PO'), 'purchase_orders', 'po_number', $poBranch);
                $pdo->prepare('INSERT INTO purchase_orders (po_number, branch_id, supplier_id, status, order_date, expected_date, notes, subtotal, total_amount, created_by) VALUES (:n,:br,:s,:st,:od,:ed,:notes,:t,:t2,:u)')
                    ->execute([':n' => $poNumber, ':br' => $poBranch, ':s' => $supplierId, ':st' => $status, ':od' => $orderDate, ':ed' => $expectedDate, ':notes' => $notes ?: null, ':t' => $total, ':t2' => $total, ':u' => current_user()['id']]);
                $poId = (int) $pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO purchase_order_items (po_id, variant_id, quantity, unit_cost) VALUES (:po,:v,:q,:c)');
            foreach ($merged as $vid => $m) $ins->execute([':po' => $poId, ':v' => $vid, ':q' => $m['qty'], ':c' => $m['unit_cost']]);
            $pdo->commit();
            $supplierName = $pdo->query('SELECT name FROM suppliers WHERE id = ' . $supplierId)->fetchColumn();
            log_activity($po ? 'po_update' : 'po_create', ($po ? 'Edited ' : 'Created ') . $poNumber . ' for ' . $supplierName . ' · ' . count($merged) . ' item(s) · ' . format_money($total) . ' · ' . $status, 'purchase_order', $poId);
            flash('success', 'Purchase order ' . $poNumber . ($status === 'ordered' ? ' placed with ' . $supplierName . '. Stock will increase when the goods are received.' : ' saved as draft.'));
            redirect('/purchase/order_view.php?id=' . $poId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('PO save failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong while saving the order.';
        }
    }
    $prefill = $items;
}

$suppliers = $pdo->query('SELECT id, name, payment_terms_days FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll();
$sel = (int) ($_POST['supplier_id'] ?? $po['supplier_id'] ?? input_int($_GET, 'supplier_id'));
$pageTitle = $po ? 'Edit ' . $po['po_number'] : 'New Purchase Order';
require __DIR__ . '/../includes/header.php';
page_header($po ? 'Edit ' . $po['po_number'] : 'New purchase order', 'A purchase order formally requests goods. It does not change stock — stock increases only when you receive the delivery.', '<a class="btn btn-secondary" href="' . e(app_url($po ? '/purchase/order_view.php?id=' . $po['id'] : '/purchase/orders.php')) . '">Cancel</a>', [['Purchasing', '/purchase/orders.php'], [$po ? $po['po_number'] : 'New order', null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="poForm">
  <?= csrf_field() ?><input type="hidden" name="items_json" id="items_json">
  <div class="card mb-4">
    <div class="form-row">
      <div class="form-group"><label>Supplier</label>
        <select class="form-control" name="supplier_id" required><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $sel === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
        <div class="form-hint"><a class="link" href="<?= e(app_url('/purchase/suppliers.php')) ?>">Add a supplier</a></div></div>
      <div class="form-group"><label>Order date</label><input class="form-control" type="date" name="order_date" value="<?= e($_POST['order_date'] ?? $po['order_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Expected delivery</label><input class="form-control" type="date" name="expected_date" value="<?= e($_POST['expected_date'] ?? $po['expected_date'] ?? date('Y-m-d', strtotime('+3 days'))) ?>"></div>
    </div>
    <div class="form-group mb-0"><label>Notes to supplier <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e($_POST['notes'] ?? $po['notes'] ?? '') ?>"></div>
  </div>
  <div class="card flush">
    <div class="card-head">
      <div><h3>Items to order</h3><div class="sub">Search or scan products. Suggested quantity = 2 × reorder level − current stock.</div></div>
      <div class="flex gap-2 flex-wrap">
        <div style="position:relative;width:min(380px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Scan barcode or search product" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div>
        <button class="btn btn-secondary" type="button" id="addLow"><?= icon('alert-triangle', 15) ?> Add low-stock items</button>
      </div>
    </div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Reorder at</th><th style="width:110px;">Order qty</th><th style="width:140px;">Unit cost</th><th class="num">Line total</th><th></th></tr></thead>
      <tbody id="itemsBody"></tbody>
      <tfoot><tr><td colspan="5" class="text-right">Expected purchase total</td><td class="num" id="grandTotal"><?= format_money(0) ?></td><td></td></tr></tfoot>
    </table></div>
    <div id="emptyItems"><?= empty_state('package-search', 'No items yet', 'Search above, or click “Add low-stock items” to fill the order with everything that needs restocking.') ?></div>
    <div class="pagination">
      <span class="text-muted" id="lineCount"></span>
      <div class="flex gap-2">
        <button type="submit" class="btn btn-secondary" name="status" value="draft">Save as draft</button>
        <button type="submit" class="btn btn-primary" name="status" value="ordered"><?= icon('send', 15) ?> Place order</button>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  const esc = CalimaxUI.esc, money = CalimaxUI.money;
  let items = <?= json_encode(array_values(array_map(fn($p) => ['variant_id' => (int) $p['variant_id'], 'product_name' => $p['product_name'], 'variant_label' => $p['variant_label'], 'sku' => $p['sku'], 'stock_qty' => (int) $p['stock_qty'], 'reorder_level' => (int) $p['reorder_level'], 'qty' => (int) $p['qty'], 'unit_cost' => (float) $p['unit_cost']], $prefill)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const body = document.getElementById('itemsBody');
  function render() {
    let total = 0;
    body.innerHTML = items.map((it, i) => {
      const lt = (+it.qty || 0) * (+it.unit_cost || 0); total += lt;
      const low = it.stock_qty <= it.reorder_level;
      return '<tr><td><div class="strong">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div><div class="cell-sub mono">' + esc(it.sku) + '</div></td>'
        + '<td class="num ' + (low ? 'text-error fw-700' : '') + '">' + it.stock_qty + '</td><td class="num text-secondary">' + it.reorder_level + '</td>'
        + '<td><input class="form-control" type="number" min="1" value="' + it.qty + '" data-i="' + i + '" data-f="qty"></td>'
        + '<td><input class="form-control" type="number" min="0" step="0.01" value="' + it.unit_cost + '" data-i="' + i + '" data-f="unit_cost"></td>'
        + '<td class="num strong">' + money(lt) + '</td><td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '" aria-label="Remove">&times;</button></td></tr>';
    }).join('');
    document.getElementById('grandTotal').textContent = money(total);
    document.getElementById('emptyItems').hidden = items.length > 0;
    document.getElementById('lineCount').textContent = items.length + ' line(s) · ' + items.reduce((s, i) => s + (+i.qty || 0), 0) + ' units';
    document.getElementById('items_json').value = JSON.stringify(items);
  }
  function add(v, qty) {
    const ex = items.find((i) => i.variant_id == v.id);
    if (ex) { ex.qty += qty || 1; render(); return; }
    items.push({ variant_id: +v.id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku, stock_qty: +v.stock_qty, reorder_level: +v.reorder_level, qty: qty || Math.max(1, v.reorder_level * 2 - v.stock_qty), unit_cost: +(v.cost_price || 0) });
    render();
  }
  body.addEventListener('change', (e) => { const i = e.target.dataset.i; if (i === undefined) return; items[i][e.target.dataset.f] = parseFloat(e.target.value) || 0; render(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });
  CalimaxUI.variantPicker(document.getElementById('itemSearch'), document.getElementById('searchResults'), (v) => add(v));
  document.getElementById('addLow').addEventListener('click', () => {
    fetch(APP.url + 'api/variant_search.php?low=1').then((r) => r.json()).then((list) => {
      let n = 0; list.forEach((v) => { if (!items.find((i) => i.variant_id == v.id)) { add(v); n++; } });
      CalimaxUI.toast(n ? n + ' low-stock item(s) added.' : 'No other items are below their reorder level.', n ? 'success' : 'info');
    });
  });
  document.getElementById('poForm').addEventListener('submit', (e) => { if (!items.length) { e.preventDefault(); CalimaxUI.toast('Add at least one item.', 'error'); } });
  render();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
