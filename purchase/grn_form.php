<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.receive');

$pdo = db();
$poId = input_int($_GET, 'po_id') ?: input_int($_POST, 'po_id');
$direct = !$poId;
$po = null;
$pendingItems = [];
$errors = [];

if (!$direct) {
    $stmt = $pdo->prepare('SELECT po.*, s.name AS supplier_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = :id');
    $stmt->execute([':id' => $poId]);
    $po = $stmt->fetch();
    if (!$po || !in_array($po['status'], ['ordered', 'partially_received'], true)) {
        flash('error', $po && $po['status'] === 'draft' ? 'Place the order first — a draft cannot receive goods.' : 'This purchase order cannot receive goods.');
        redirect($po ? '/purchase/order_view.php?id=' . $poId : '/purchase/orders.php');
    }
    assert_branch_access((int) $po['branch_id']);
    $s = $pdo->prepare('SELECT poi.*, pv.sku, pv.barcode, pv.variant_label, pv.stock_qty, p.name AS product_name FROM purchase_order_items poi JOIN ' . pv((int) $po['branch_id']) . ' pv ON pv.id = poi.variant_id JOIN products p ON p.id = pv.product_id WHERE poi.po_id = :id AND poi.received_qty < poi.quantity');
    $s->execute([':id' => $poId]);
    $pendingItems = $s->fetchAll();
}

// Goods are received INTO a branch: the PO's branch, or the branch the user is working in.
$grnBranch = $po ? (int) $po['branch_id'] : require_single_branch();

if (is_post()) {
    verify_csrf();
    $receivedDate = input_date($_POST, 'received_date', date('Y-m-d'));
    $invoiceNo = input($_POST, 'supplier_invoice_no');
    $notes = input($_POST, 'notes');
    $paymentMode = input($_POST, 'payment_mode') === 'paid' ? 'paid' : 'credit';
    $payMethod = input($_POST, 'pay_method');
    $payRef = input($_POST, 'pay_reference');
    if ($paymentMode === 'paid' && !can('suppliers.pay')) $errors[] = 'You are not allowed to record supplier payments. Receive on credit; a manager will pay the supplier.';
    if ($paymentMode === 'paid' && !isset(money_methods()[$payMethod])) $errors[] = 'Choose how the supplier was paid.';
    if ($receivedDate > date('Y-m-d')) $errors[] = 'Received date cannot be in the future.';

    $lines = [];
    if ($direct) {
        $supplierId = input_int($_POST, 'supplier_id');
        if (!$supplierId) $errors[] = 'Select the supplier who delivered the goods.';
        foreach (json_decode($_POST['items_json'] ?? '[]', true) ?: [] as $it) {
            $q = (int) ($it['qty'] ?? 0); $c = round((float) ($it['unit_cost'] ?? 0), 2);
            if ($q > 0 && !empty($it['variant_id'])) $lines[] = ['po_item_id' => null, 'variant_id' => (int) $it['variant_id'], 'qty' => $q, 'rejected' => max(0, (int) ($it['rejected'] ?? 0)), 'unit_cost' => max(0, $c), 'note' => null];
        }
    } else {
        $supplierId = (int) $po['supplier_id'];
        foreach ($pendingItems as $it) {
            $q = (int) ($_POST['receive_qty'][$it['id']] ?? 0);
            $rej = max(0, (int) ($_POST['rejected_qty'][$it['id']] ?? 0));
            $cost = isset($_POST['unit_cost'][$it['id']]) && is_numeric($_POST['unit_cost'][$it['id']]) ? round((float) $_POST['unit_cost'][$it['id']], 2) : (float) $it['unit_cost'];
            $pending = (int) $it['quantity'] - (int) $it['received_qty'];
            if ($q < 0 || $q > $pending) { $errors[] = 'You can accept at most ' . $pending . ' of ' . $it['product_name'] . ' (outstanding on the order).'; continue; }
            if ($q > 0 || $rej > 0) $lines[] = ['po_item_id' => (int) $it['id'], 'variant_id' => (int) $it['variant_id'], 'qty' => $q, 'rejected' => $rej, 'unit_cost' => max(0, $cost), 'note' => input($_POST['line_note'] ?? [], (string) $it['id']) ?: null];
        }
    }
    if (!array_filter($lines, fn($l) => $l['qty'] > 0) && !$errors) $errors[] = 'Enter the quantity accepted for at least one item.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $total = round(array_sum(array_map(fn($l) => $l['qty'] * $l['unit_cost'], $lines)), 2);
            if ($direct) {
                $poNumber = next_document_number(setting('po_prefix', 'PO'), 'purchase_orders', 'po_number', $grnBranch);
                $pdo->prepare("INSERT INTO purchase_orders (po_number, branch_id, supplier_id, status, order_date, notes, subtotal, total_amount, created_by, is_direct) VALUES (:n,:br,:s,'received',:d,:notes,:t,:t2,:u,1)")
                    ->execute([':n' => $poNumber, ':br' => $grnBranch, ':s' => $supplierId, ':d' => $receivedDate, ':notes' => 'Direct purchase (no prior PO)', ':t' => $total, ':t2' => $total, ':u' => current_user()['id']]);
                $poId = (int) $pdo->lastInsertId();
                $insPoi = $pdo->prepare('INSERT INTO purchase_order_items (po_id, variant_id, quantity, unit_cost, received_qty) VALUES (:po,:v,:q,:c,0)');
                foreach ($lines as &$l) { $insPoi->execute([':po' => $poId, ':v' => $l['variant_id'], ':q' => $l['qty'], ':c' => $l['unit_cost']]); $l['po_item_id'] = (int) $pdo->lastInsertId(); }
                unset($l);
            }
            $grnNumber = next_document_number(setting('grn_prefix', 'GRN'), 'goods_received_notes', 'grn_number', $grnBranch);
            $pdo->prepare('INSERT INTO goods_received_notes (grn_number, branch_id, po_id, supplier_invoice_no, received_date, notes, total_amount, payment_mode, created_by) VALUES (:n,:br,:po,:inv,:d,:notes,:t,:pm,:u)')
                ->execute([':n' => $grnNumber, ':br' => $grnBranch, ':po' => $poId, ':inv' => $invoiceNo ?: null, ':d' => $receivedDate, ':notes' => $notes ?: null, ':t' => $total, ':pm' => $paymentMode, ':u' => current_user()['id']]);
            $grnId = (int) $pdo->lastInsertId();

            $insGrnItem = $pdo->prepare('INSERT INTO grn_items (grn_id, po_item_id, variant_id, quantity, rejected_qty, unit_cost, note) VALUES (:g,:poi,:v,:q,:rej,:c,:note)');
            $updPoItem = $pdo->prepare('UPDATE purchase_order_items SET received_qty = received_qty + :q WHERE id = :id');
            foreach ($lines as $l) {
                $insGrnItem->execute([':g' => $grnId, ':poi' => $l['po_item_id'], ':v' => $l['variant_id'], ':q' => $l['qty'], ':rej' => $l['rejected'], ':c' => $l['unit_cost'], ':note' => $l['note']]);
                if ($l['qty'] > 0) {
                    $updPoItem->execute([':q' => $l['qty'], ':id' => $l['po_item_id']]);
                    update_average_cost($pdo, $l['variant_id'], $l['qty'], $l['unit_cost'], $grnBranch);
                    apply_stock_change($pdo, $l['variant_id'], $l['qty'], 'purchase', 'grn', $grnId, 'Received ' . $grnNumber . ($invoiceNo ? ' · inv ' . $invoiceNo : ''), 'saleable', $grnBranch);
                }
            }

            // Supplier account: the delivery is a charge; paying on delivery settles it immediately.
            supplier_balance_adjust($pdo, $supplierId, $grnBranch, $total);
            if ($paymentMode === 'paid' && $total > 0) {
                $pdo->prepare('INSERT INTO supplier_payments (supplier_id, branch_id, po_id, grn_id, amount, payment_method, reference_no, payment_date, note, created_by) VALUES (:s,:br,:po,:g,:a,:m,:r,:d,:n,:u)')
                    ->execute([':s' => $supplierId, ':br' => $grnBranch, ':po' => $poId, ':g' => $grnId, ':a' => $total, ':m' => $payMethod, ':r' => $payRef ?: null, ':d' => $receivedDate, ':n' => 'Paid on delivery ' . $grnNumber, ':u' => current_user()['id']]);
                supplier_balance_adjust($pdo, $supplierId, $grnBranch, -$total);
            }

            if (!$direct) {
                $rem = $pdo->prepare('SELECT COALESCE(SUM(quantity - received_qty),0) FROM purchase_order_items WHERE po_id = :id');
                $rem->execute([':id' => $poId]);
                $pdo->prepare('UPDATE purchase_orders SET status = :s WHERE id = :id')->execute([':s' => (int) $rem->fetchColumn() <= 0 ? 'received' : 'partially_received', ':id' => $poId]);
            }
            $pdo->commit();
            $units = array_sum(array_column($lines, 'qty'));
            $rejected = array_sum(array_column($lines, 'rejected'));
            log_activity('grn_create', $grnNumber . ' · ' . $units . ' units into stock' . ($rejected ? ' · ' . $rejected . ' rejected' : '') . ' · ' . format_money($total) . ' · ' . ($paymentMode === 'paid' ? 'paid ' . method_label($payMethod) : 'on credit') . ($direct ? ' · direct purchase' : ' · against ' . $po['po_number']), 'grn', $grnId);
            flash('success', "Goods received ($grnNumber): $units units added to stock. " . ($paymentMode === 'paid' ? 'Supplier marked as paid.' : format_money($total) . ' added to the supplier balance.'));
            redirect('/purchase/grn_view.php?id=' . $grnId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('GRN failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong while receiving goods. Nothing was saved.';
        }
    }
}

$suppliers = $direct ? $pdo->query('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name')->fetchAll() : [];
$pageTitle = 'Receive Goods';
require __DIR__ . '/../includes/header.php';
page_header($direct ? 'Receive goods (no PO)' : 'Receive goods — ' . $po['po_number'],
    $direct ? 'Record a delivery that wasn’t ordered with a purchase order. Stock increases immediately.' : e($po['supplier_name']) . ' · check each item against the delivery and the supplier’s invoice.',
    '<a class="btn btn-secondary" href="' . e(app_url($direct ? '/purchase/receipts.php' : '/purchase/order_view.php?id=' . $poId)) . '">Cancel</a>',
    [['Purchasing', '/purchase/orders.php'], $direct ? ['Receive goods', null] : [$po['po_number'], '/purchase/order_view.php?id=' . $poId], ['Receive', null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<form method="post" id="grnForm">
  <?= csrf_field() ?><input type="hidden" name="po_id" value="<?= (int) $poId ?>"><input type="hidden" name="items_json" id="items_json">
  <div class="card mb-4">
    <div class="form-row">
      <?php if ($direct): ?><div class="form-group"><label>Supplier</label><select class="form-control" name="supplier_id" required><option value="">Select supplier…</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= input_int($_POST, 'supplier_id') === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="form-group"><label>Received date</label><input class="form-control" type="date" name="received_date" max="<?= date('Y-m-d') ?>" value="<?= e(input($_POST, 'received_date') ?: date('Y-m-d')) ?>"></div>
      <div class="form-group"><label>Supplier invoice / delivery note no.</label><input class="form-control" type="text" name="supplier_invoice_no" maxlength="60" value="<?= e(input($_POST, 'supplier_invoice_no')) ?>" placeholder="As printed on their invoice"></div>
      <div class="form-group"><label>Notes <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e(input($_POST, 'notes')) ?>"></div>
    </div>
  </div>

  <div class="card flush mb-4">
    <?php if ($direct): ?>
      <div class="card-head"><div><h3>Items delivered</h3><div class="sub">Scan or search each product from the delivery.</div></div>
        <div style="position:relative;width:min(380px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Scan barcode or search product" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Item</th><th class="num">In stock</th><th style="width:120px;">Accepted qty</th><th style="width:120px;">Rejected</th><th style="width:140px;">Unit cost</th><th class="num">Line total</th><th></th></tr></thead>
        <tbody id="itemsBody"></tbody>
      </table></div>
      <div id="emptyItems"><?= empty_state('scan-barcode', 'No items yet', 'Scan the products as you unpack the delivery.') ?></div>
    <?php else: ?>
      <div class="card-head"><div><h3>Check the delivery</h3><div class="sub">“Accepted” goes into stock. “Rejected” = delivered damaged or wrong and refused — not added to stock or owed.</div></div>
        <button type="button" class="btn btn-secondary btn-sm" id="fillAll">Everything arrived as ordered</button></div>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Item</th><th class="num">Ordered</th><th class="num">Already received</th><th class="num">Outstanding</th><th style="width:110px;">Accepted</th><th style="width:100px;">Rejected</th><th style="width:130px;">Invoice cost</th><th>Check</th></tr></thead>
        <tbody>
        <?php foreach ($pendingItems as $it): $pending = (int) $it['quantity'] - (int) $it['received_qty']; ?>
          <tr data-line data-pending="<?= $pending ?>" data-ordercost="<?= e($it['unit_cost']) ?>">
            <td><div class="strong"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></div><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
            <td class="num"><?= (int) $it['quantity'] ?></td>
            <td class="num"><?= (int) $it['received_qty'] ?></td>
            <td class="num strong"><?= $pending ?></td>
            <td><input class="form-control rq" type="number" min="0" max="<?= $pending ?>" name="receive_qty[<?= (int) $it['id'] ?>]" value="<?= e((string) ($_POST['receive_qty'][$it['id']] ?? 0)) ?>"></td>
            <td><input class="form-control rj" type="number" min="0" name="rejected_qty[<?= (int) $it['id'] ?>]" value="<?= e((string) ($_POST['rejected_qty'][$it['id']] ?? 0)) ?>"></td>
            <td><input class="form-control uc" type="number" min="0" step="0.01" name="unit_cost[<?= (int) $it['id'] ?>]" value="<?= e((string) ($_POST['unit_cost'][$it['id']] ?? $it['unit_cost'])) ?>"></td>
            <td class="chk text-sm"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <div class="pagination"><span class="text-muted" id="unitsSummary"></span><span class="text-lg fw-700">Value received: <span id="grnTotal"><?= format_money(0) ?></span></span></div>
  </div>

  <div class="card">
    <div class="form-section-title">Payment for this delivery</div>
    <div class="option-cards mb-3" style="max-width:640px;">
      <label class="option-card"><input type="radio" name="payment_mode" value="credit" <?= input($_POST, 'payment_mode') !== 'paid' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('file-clock', 15) ?> On credit</span><span class="oc-desc">Add to what we owe the supplier</span></span></label>
      <?php if (can('suppliers.pay')): ?><label class="option-card"><input type="radio" name="payment_mode" value="paid" <?= input($_POST, 'payment_mode') === 'paid' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('banknote', 15) ?> Paid on delivery</span><span class="oc-desc">Supplier paid now in full</span></span></label><?php endif; ?>
    </div>
    <div class="form-row" id="payFields" hidden style="max-width:640px;">
      <div class="form-group"><label>Paid by</label><select class="form-control" name="pay_method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="form-group"><label>Reference <span class="label-opt">(cheque / transfer no.)</span></label><input class="form-control" type="text" name="pay_reference" maxlength="60"></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary btn-lg" type="submit"><?= icon('package-check', 18) ?> Confirm receipt &amp; update stock</button></div>
  </div>
</form>
<script>
(function () {
  const money = CalimaxUI.money, esc = CalimaxUI.esc;
  const pm = document.querySelectorAll('input[name=payment_mode]');
  const syncPay = () => { const c = document.querySelector('input[name=payment_mode]:checked'); document.getElementById('payFields').hidden = !c || c.value !== 'paid'; };
  pm.forEach((r) => r.addEventListener('change', syncPay)); syncPay();
  <?php if ($direct): ?>
  let items = [];
  const body = document.getElementById('itemsBody');
  function render() {
    let t = 0, u = 0;
    body.innerHTML = items.map((it, i) => { const lt = it.qty * it.unit_cost; t += lt; u += it.qty;
      return '<tr><td><div class="strong">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div><div class="cell-sub mono">' + esc(it.sku) + '</div></td><td class="num">' + it.stock_qty + '</td>'
        + '<td><input class="form-control" type="number" min="0" value="' + it.qty + '" data-i="' + i + '" data-f="qty"></td><td><input class="form-control" type="number" min="0" value="' + it.rejected + '" data-i="' + i + '" data-f="rejected"></td>'
        + '<td><input class="form-control" type="number" min="0" step="0.01" value="' + it.unit_cost + '" data-i="' + i + '" data-f="unit_cost"></td><td class="num strong">' + money(lt) + '</td>'
        + '<td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '">&times;</button></td></tr>'; }).join('');
    document.getElementById('emptyItems').hidden = items.length > 0;
    document.getElementById('grnTotal').textContent = money(t);
    document.getElementById('unitsSummary').textContent = u + ' unit(s) going into stock';
    document.getElementById('items_json').value = JSON.stringify(items);
  }
  body.addEventListener('change', (e) => { const i = e.target.dataset.i; if (i === undefined) return; items[i][e.target.dataset.f] = parseFloat(e.target.value) || 0; render(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });
  CalimaxUI.variantPicker(document.getElementById('itemSearch'), document.getElementById('searchResults'), (v) => {
    const ex = items.find((i) => i.variant_id == v.id);
    if (ex) ex.qty++; else items.push({ variant_id: +v.id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku, stock_qty: +v.stock_qty, qty: 1, rejected: 0, unit_cost: +(v.cost_price || 0) });
    render();
  });
  document.getElementById('grnForm').addEventListener('submit', (e) => { if (!items.length) { e.preventDefault(); CalimaxUI.toast('Add the delivered items.', 'error'); } });
  render();
  <?php else: ?>
  const rows = document.querySelectorAll('[data-line]');
  function check() {
    let t = 0, u = 0, short = 0;
    rows.forEach((r) => {
      const q = parseInt(r.querySelector('.rq').value, 10) || 0, rj = parseInt(r.querySelector('.rj').value, 10) || 0, c = parseFloat(r.querySelector('.uc').value) || 0, p = +r.dataset.pending;
      t += q * c; u += q;
      const msgs = [];
      if (q > p) msgs.push('<span class="badge badge-error">More than ordered</span>');
      else if (q === p) msgs.push('<span class="badge badge-success">Matches order</span>');
      else if (q > 0 || rj > 0) { msgs.push('<span class="badge badge-warning">' + (p - q) + ' short</span>'); short++; }
      if (rj > 0) msgs.push('<span class="badge badge-neutral">' + rj + ' rejected</span>');
      if (Math.abs(c - +r.dataset.ordercost) > 0.004) msgs.push('<span class="badge badge-info">Cost ' + (c > +r.dataset.ordercost ? '↑' : '↓') + ' vs PO</span>');
      r.querySelector('.chk').innerHTML = msgs.join(' ');
      r.classList.toggle('row-warn', q > 0 && q < p);
    });
    document.getElementById('grnTotal').textContent = money(t);
    document.getElementById('unitsSummary').textContent = u + ' unit(s) going into stock' + (short ? ' · ' + short + ' line(s) short — they stay open on the PO' : '');
  }
  document.querySelectorAll('.rq,.rj,.uc').forEach((i) => i.addEventListener('input', check));
  document.getElementById('fillAll').addEventListener('click', () => { rows.forEach((r) => { r.querySelector('.rq').value = r.dataset.pending; r.querySelector('.rj').value = 0; }); check(); });
  check();
  <?php endif; ?>
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
