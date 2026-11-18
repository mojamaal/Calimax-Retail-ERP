<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('suppliers.view');

$pdo = db();
$id = input_int($_GET, 'id');
$load = function () use ($pdo, $id) { $s = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id'); $s->execute([':id' => $id]); return $s->fetch(); };
$supplier = $load();
if (!$supplier) { flash('error', 'Supplier not found.'); redirect('/purchase/suppliers.php'); }
$errors = [];
// Suppliers are shared, but each branch pays its own bills: show / pay the balance of the current branch.
$bq = $pdo->prepare('SELECT ' . supplier_balance_sql('s') . ' FROM suppliers s WHERE s.id = :id');
$bq->execute([':id' => $id]);
$supplier['balance'] = (float) $bq->fetchColumn();
$byBranch = $pdo->prepare('SELECT b.name, sb.balance FROM supplier_balances sb JOIN branches b ON b.id = sb.branch_id WHERE sb.supplier_id = :id AND sb.balance <> 0 ORDER BY b.is_main DESC, b.name');
$byBranch->execute([':id' => $id]);
$byBranch = $byBranch->fetchAll();

if (is_post() && input($_POST, 'action') === 'pay') {
    verify_csrf();
    require_permission('suppliers.pay');
    $amount = round(input_float($_POST, 'amount'), 2);
    $method = input($_POST, 'payment_method');
    $date = input_date($_POST, 'payment_date', date('Y-m-d'));
    $ref = input($_POST, 'reference_no');
    $note = input($_POST, 'note');
    $payBranch = write_branch();   // paid from THIS branch's cash / bank
    if ($amount <= 0) $errors[] = 'Enter a valid payment amount.';
    elseif ($amount > (float) $supplier['balance'] + 0.001) $errors[] = 'Payment (' . format_money($amount) . ') is more than the ' . format_money($supplier['balance']) . ' owed.';
    if (!isset(money_methods()[$method])) $errors[] = 'Choose a payment method.';
    if (in_array($method, ['cheque', 'bank_transfer'], true) && $ref === '') $errors[] = 'Enter the ' . ($method === 'cheque' ? 'cheque' : 'transfer') . ' reference number.';
    if (!$errors) {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO supplier_payments (supplier_id, branch_id, amount, payment_method, reference_no, payment_date, note, created_by) VALUES (:s,:br,:a,:m,:r,:d,:n,:u)')
            ->execute([':s' => $id, ':br' => $payBranch, ':a' => $amount, ':m' => $method, ':r' => $ref ?: null, ':d' => $date, ':n' => $note ?: null, ':u' => current_user()['id']]);
        supplier_balance_adjust($pdo, $id, $payBranch, -$amount);
        $pdo->commit();
        log_activity('supplier_payment', 'Paid ' . $supplier['name'] . ' ' . format_money($amount) . ' by ' . method_label($method) . ($ref ? ' #' . $ref : ''), 'supplier', $id);
        flash('success', 'Payment of ' . format_money($amount) . ' recorded. Balance now ' . format_money((float) $supplier['balance'] - $amount) . '.');
        redirect('/purchase/supplier_view.php?id=' . $id . '&tab=statement');
    }
}

$tab = input($_GET, 'tab') ?: 'statement';
// Account statement: deliveries (we owe more), returns and payments (we owe less).
$stmt = $pdo->prepare(
    "SELECT * FROM (
        SELECT g.received_date AS d, g.created_at AS ts, 'Delivery' AS kind, g.grn_number AS ref, CONCAT('Goods received', IF(g.supplier_invoice_no IS NULL, '', CONCAT(' · inv ', g.supplier_invoice_no))) AS descr, g.total_amount AS charge, 0 AS credit, CONCAT('/purchase/grn_view.php?id=', g.id) AS link
          FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id WHERE po.supplier_id = :s1" . branch_and('g') . "
        UNION ALL
        SELECT pr.return_date, pr.created_at, 'Return', pr.return_number, COALESCE(pr.reason, 'Goods returned'), 0, pr.total_amount, CONCAT('/purchase/return_view.php?id=', pr.id) FROM purchase_returns pr WHERE pr.supplier_id = :s2" . branch_and('pr') . "
        UNION ALL
        SELECT sp.payment_date, sp.created_at, 'Payment', sp.reference_no, CONCAT('Paid by ', REPLACE(sp.payment_method, '_', ' '), IF(sp.note IS NULL, '', CONCAT(' · ', sp.note))), 0, sp.amount, NULL FROM supplier_payments sp WHERE sp.supplier_id = :s3" . branch_and('sp') . "
     ) x ORDER BY d, ts"
);
$stmt->execute([':s1' => $id, ':s2' => $id, ':s3' => $id]);
$entries = $stmt->fetchAll();
$running = 0.0;
foreach ($entries as &$en) { $running += (float) $en['charge'] - (float) $en['credit']; $en['balance'] = $running; }
unset($en);
$opening = (float) $supplier['balance'] - $running; // any balance that pre-dates recorded transactions
$entries = array_reverse($entries);

$orders = $pdo->prepare('SELECT * FROM purchase_orders WHERE supplier_id = :id' . branch_and() . ' ORDER BY id DESC LIMIT 50');
$orders->execute([':id' => $id]);
$orders = $orders->fetchAll();
$ytd = $pdo->prepare("SELECT COALESCE(SUM(g.total_amount),0) FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id WHERE po.supplier_id = :id AND g.received_date >= DATE_FORMAT(CURDATE(), '%Y-01-01')" . branch_and('g'));
$ytd->execute([':id' => $id]);
$ytd = (float) $ytd->fetchColumn();
$top = $pdo->prepare('SELECT p.name, pv.variant_label, SUM(gi.quantity) AS qty, SUM(gi.quantity * gi.unit_cost) AS value FROM grn_items gi JOIN goods_received_notes g ON g.id = gi.grn_id JOIN purchase_orders po ON po.id = g.po_id JOIN product_variants pv ON pv.id = gi.variant_id JOIN products p ON p.id = pv.product_id WHERE po.supplier_id = :id' . branch_and('g') . ' GROUP BY pv.id ORDER BY qty DESC LIMIT 6');
$top->execute([':id' => $id]);
$top = $top->fetchAll();

$pageTitle = $supplier['name'];
require __DIR__ . '/../includes/header.php';
$a = '';
if (can('suppliers.pay') && (float) $supplier['balance'] > 0 && current_branch_id() !== null) $a .= '<button class="btn btn-primary" type="button" data-modal-open="#payModal">' . icon('banknote', 16) . ' Record payment</button>';
if (can('purchase.manage')) $a .= '<a class="btn btn-secondary" href="' . e(app_url('/purchase/order_form.php?supplier_id=' . $id)) . '">' . icon('file-plus', 16) . ' New order</a>';
if (can('suppliers.manage')) $a .= '<button class="btn btn-secondary" type="button" onclick=\'openSupplier(' . json_encode($supplier, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) . ')\'>' . icon('pencil', 16) . ' Edit</button>';
page_header($supplier['name'], e(implode(' · ', array_filter([$supplier['contact_person'], $supplier['phone'], $supplier['email']]))) . ($supplier['is_active'] ? '' : ' · ' . status_badge('inactive')), $a, [['Suppliers', '/purchase/suppliers.php'], [$supplier['name'], null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<?php if (count(branches_all()) > 1 && $byBranch): ?>
  <div class="callout mb-3 text-sm"><?= icon('building-2', 14) ?> <strong>Each branch pays its own bills.</strong> Currently owed by branch:
    <?php foreach ($byBranch as $bb): ?><span class="badge badge-<?= (float) $bb['balance'] > 0 ? 'warning' : 'neutral' ?>"><?= e($bb['name']) ?> · <?= format_money($bb['balance']) ?></span> <?php endforeach; ?>
    <?= current_branch_id() === null ? '— pick a branch in the top bar to record a payment.' : '' ?></div>
<?php endif; ?>
<div class="grid grid-4 mb-4">
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">We owe<?= current_branch_id() !== null && count(branches_all()) > 1 ? ' · ' . e(branch_scope_label()) : '' ?></span></div><div class="kpi-value <?= (float) $supplier['balance'] > 0 ? 'tone-neg' : '' ?>"><?= format_money($supplier['balance']) ?></div><div class="kpi-desc"><?= (int) $supplier['payment_terms_days'] ? (int) $supplier['payment_terms_days'] . '-day terms' : 'Cash on delivery' ?></div></div>
  <?= kpi('Purchased this year', format_money($ytd), 'package-check', 'green') ?>
  <?= kpi('Open orders', (string) count(array_filter($orders, fn($o) => in_array($o['status'], ['ordered', 'partially_received'], true))), 'truck', 'blue') ?>
  <?= kpi('Total deliveries', (string) count(array_filter($entries, fn($e) => $e['kind'] === 'Delivery')), 'calendar', 'purple') ?>
</div>

<div class="layout-2-1">
  <div>
    <nav class="subnav">
      <a class="subnav-item <?= $tab === 'statement' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=statement"><?= icon('scroll-text', 15) ?> Account statement</a>
      <a class="subnav-item <?= $tab === 'orders' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=orders"><?= icon('file-text', 15) ?> Purchase history</a>
    </nav>
    <div class="card flush">
    <?php if ($tab === 'orders'): ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Order</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($orders as $o): ?><tr><td class="strong"><a href="<?= e(app_url('/purchase/order_view.php?id=' . $o['id'])) ?>"><?= e($o['po_number']) ?></a><?= $o['is_direct'] ? ' <span class="badge badge-neutral">Direct</span>' : '' ?></td><td class="text-secondary"><?= e(fmt_date($o['order_date'])) ?></td><td class="num"><?= format_money($o['total_amount']) ?></td><td><?= status_badge($o['status']) ?></td></tr><?php endforeach; ?></tbody>
      </table><?php if (!$orders) echo empty_state('file-text', 'No orders yet'); ?></div>
    <?php else: ?>
      <div class="table-wrap" style="max-height:640px;overflow:auto;"><table class="data-table">
        <thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th class="num">Purchases</th><th class="num">Paid / returned</th><th class="num">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $en): ?>
          <tr>
            <td class="nowrap text-secondary"><?= e(fmt_date($en['d'])) ?></td>
            <td><?= e($en['kind']) ?><div class="cell-sub"><?= e($en['descr']) ?></div></td>
            <td><?= $en['link'] ? '<a class="link" href="' . e(app_url($en['link'])) . '">' . e($en['ref']) . '</a>' : e($en['ref'] ?? '—') ?></td>
            <td class="num"><?= (float) $en['charge'] ? format_money($en['charge']) : '' ?></td>
            <td class="num text-success"><?= (float) $en['credit'] ? format_money($en['credit']) : '' ?></td>
            <td class="num strong"><?= format_money($en['balance'] + $opening) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table><?php if (!$entries) echo empty_state('scroll-text', 'No transactions yet'); ?></div>
    <?php endif; ?>
    </div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Business details</h3>
      <dl class="desc-list">
        <dt>Business reg. no.</dt><dd><?= e($supplier['business_reg_no'] ?? '—') ?></dd>
        <dt>VAT / TIN</dt><dd><?= e($supplier['tax_id'] ?? '—') ?></dd>
        <dt>Contact</dt><dd><?= e($supplier['contact_person'] ?? '—') ?></dd>
        <dt>Phone</dt><dd><?= e($supplier['phone'] ?? '—') ?></dd>
        <dt>Email</dt><dd><?= e($supplier['email'] ?? '—') ?></dd>
        <dt>Address</dt><dd><?= e($supplier['address'] ?? '—') ?></dd>
      </dl>
      <?php if ($supplier['notes']): ?><div class="callout mt-3"><?= e($supplier['notes']) ?></div><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mb-3">Products supplied</h3>
      <div class="list-rows"><?php foreach ($top as $i => $t): ?><div><span class="flex items-center gap-2"><span class="rank"><?= $i + 1 ?></span><?= e($t['name']) ?><?= $t['variant_label'] ? ' — ' . e($t['variant_label']) : '' ?></span><span class="text-secondary text-sm"><?= number_format((int) $t['qty']) ?> units</span></div><?php endforeach; ?>
      <?php if (!$top): ?><div class="text-muted">Nothing received yet.</div><?php endif; ?></div>
    </div>
  </div>
</div>

<?php if (can('suppliers.pay')): ?>
<div class="modal-overlay" id="payModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3>Pay <?= e($supplier['name']) ?></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <p class="text-muted text-sm">Outstanding: <strong><?= format_money($supplier['balance']) ?></strong></p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="pay">
      <div class="form-row">
        <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" min="0.01" max="<?= e($supplier['balance']) ?>" name="amount" required value="<?= e(number_format((float) $supplier['balance'], 2, '.', '')) ?>"></div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="payment_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Method</label><select class="form-control" name="payment_method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference <span class="label-opt">(cheque / transfer no.)</span></label><input class="form-control" type="text" name="reference_no" maxlength="60"></div>
      </div>
      <div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="255" placeholder="e.g. Settlement of October invoices"></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record payment</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_supplier_modal.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
