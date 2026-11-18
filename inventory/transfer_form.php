<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/transfers.php';
require_permission('transfers.manage');
$from = require_single_branch();

$pdo = db();
$errors = [];
$dests = array_filter(branches_all(), fn($b) => (int) $b['id'] !== $from);
$prefill = [];
$toId = input_int($_POST, 'to_branch_id') ?: input_int($_GET, 'to');

if (is_post()) {
    verify_csrf();
    $note = input($_POST, 'note');
    $lines = json_decode($_POST['items_json'] ?? '[]', true) ?: [];
    if (!isset($dests[$toId])) $errors[] = 'Choose the branch the stock is going to.';
    if (!$lines) $errors[] = 'Add at least one item.';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $id = create_stock_transfer($pdo, $from, $toId, $lines, $note);
            $pdo->commit();
            $num = $pdo->query('SELECT transfer_number FROM stock_transfers WHERE id = ' . $id)->fetchColumn();
            $units = array_sum(array_map(fn($l) => (int) ($l['qty'] ?? 0), $lines));
            log_activity('transfer_create', $num . ' · ' . $units . ' unit(s) · ' . branch_row($from)['name'] . ' → ' . $dests[$toId]['name'] . ($note ? ' · ' . $note : ''), 'stock_transfer', $id);
            flash('success', "Transfer $num sent to " . $dests[$toId]['name'] . '. The stock is in transit until they confirm receipt.');
            redirect('/inventory/transfer_view.php?id=' . $id);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Transfer failed: ' . $ex->getMessage());
            $errors[] = $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not create the transfer.';
            $prefill = $lines;
        }
    } else $prefill = $lines;
}

$pageTitle = 'New Transfer';
require __DIR__ . '/../includes/header.php';
page_header('New branch transfer', 'Send saleable stock from ' . e(branch_scope_label()) . ' to another branch.', '<a class="btn btn-secondary" href="' . e(app_url('/inventory/transfers.php')) . '">Cancel</a>', [['Branch transfers', '/inventory/transfers.php'], ['New', null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<?php if (!$dests): ?>
  <div class="alert alert-info"><?= icon('info', 18) ?><span>There is no other active branch to send stock to.</span></div>
<?php else: ?>
<form method="post" id="trForm">
  <?= csrf_field() ?><input type="hidden" name="items_json" id="items_json">
  <div class="card mb-4"><div class="form-row">
    <div class="form-group"><label>From</label><input class="form-control" type="text" value="<?= e(branch_scope_label()) ?>" disabled></div>
    <div class="form-group"><label>To</label><select class="form-control" name="to_branch_id" required><option value="">Select branch…</option><?php foreach ($dests as $b): ?><option value="<?= (int) $b['id'] ?>" <?= $toId === (int) $b['id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
  </div></div>
  <div class="card flush mb-4">
    <div class="card-head"><div><h3>Items to send</h3><div class="sub">Only saleable stock can be transferred (damaged stock stays put).</div></div>
      <div style="position:relative;width:min(420px,100%);"><input class="form-control" type="text" id="itemSearch" placeholder="Scan barcode or search product to add" autocomplete="off"><div class="search-results" id="searchResults" hidden></div></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">Available here</th><th style="width:130px;">Quantity</th><th class="num">Left after</th><th></th></tr></thead>
      <tbody id="itemsBody"></tbody>
    </table></div>
    <div id="emptyItems"><?= empty_state('scan-barcode', 'No items added', 'Scan or search above to add products to this transfer.') ?></div>
  </div>
  <div class="card">
    <div class="form-group"><label for="note">Note <span class="label-opt">(optional)</span></label><input class="form-control" type="text" id="note" name="note" maxlength="255" placeholder="e.g. Weekly restock for the Kandy shop" value="<?= e(input($_POST, 'note')) ?>"></div>
    <div class="form-actions"><button class="btn btn-primary btn-lg" type="submit"><?= icon('send', 18) ?> Send stock</button></div>
  </div>
</form>
<script>
(function () {
  let items = <?= json_encode(array_values($prefill), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const body = document.getElementById('itemsBody'), esc = CalimaxUI.esc;
  function render() {
    body.innerHTML = items.map((it, i) => {
      const q = parseInt(it.qty, 10) || 0, left = it.stock_qty - q, bad = left < 0;
      return '<tr class="' + (bad ? 'row-bad' : '') + '"><td><div class="strong">' + esc(it.product_name) + (it.variant_label ? ' — ' + esc(it.variant_label) : '') + '</div><div class="cell-sub mono">' + esc(it.sku) + '</div></td>'
        + '<td class="num">' + it.stock_qty + '</td><td><input class="form-control" type="number" min="1" value="' + q + '" data-i="' + i + '"></td>'
        + '<td class="num strong">' + left + (bad ? ' <span class="badge badge-error">not enough</span>' : '') + '</td>'
        + '<td class="actions"><button type="button" class="btn btn-secondary btn-sm btn-icon" data-rm="' + i + '" aria-label="Remove">&times;</button></td></tr>';
    }).join('');
    document.getElementById('emptyItems').hidden = items.length > 0;
    document.getElementById('items_json').value = JSON.stringify(items.map((it) => ({ variant_id: it.variant_id, qty: it.qty, product_name: it.product_name, variant_label: it.variant_label, sku: it.sku, stock_qty: it.stock_qty })));
  }
  body.addEventListener('change', (e) => { const i = e.target.dataset.i; if (i === undefined) return; items[i].qty = parseInt(e.target.value, 10) || 0; render(); });
  body.addEventListener('click', (e) => { const b = e.target.closest('[data-rm]'); if (b) { items.splice(+b.dataset.rm, 1); render(); } });
  CalimaxUI.variantPicker(document.getElementById('itemSearch'), document.getElementById('searchResults'), (v) => {
    if (+v.stock_qty <= 0) { CalimaxUI.toast(v.product_name + ' has no saleable stock here.', 'error'); return; }
    const ex = items.find((i) => i.variant_id == v.id);
    if (ex) ex.qty = (parseInt(ex.qty, 10) || 0) + 1;
    else items.push({ variant_id: +v.id, product_name: v.product_name, variant_label: v.variant_label, sku: v.sku, stock_qty: +v.stock_qty, qty: 1 });
    render();
  });
  document.getElementById('trForm').addEventListener('submit', (e) => { if (!items.length) { e.preventDefault(); CalimaxUI.toast('Add at least one item.', 'error'); } });
  render();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
