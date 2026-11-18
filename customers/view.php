<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.view');

$pdo = db();
$id = input_int($_GET, 'id');
$loadCustomer = function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT c.*, u.full_name AS created_name FROM customers c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = :id');
    $s->execute([':id' => $id]);
    return $s->fetch();
};
$c = $loadCustomer();
if (!$c) { flash('error', 'Customer not found.'); redirect('/customers/index.php'); }
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');

    if ($action === 'update') {
        require_permission(['customers.edit', 'customers.create']);
        $name = input($_POST, 'name');
        $phone = input($_POST, 'phone');
        $email = input($_POST, 'email');
        if ($name === '') $errors[] = 'Name is required.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($phone !== '') {
            $d = $pdo->prepare('SELECT name FROM customers WHERE phone = :p AND id <> :id');
            $d->execute([':p' => $phone, ':id' => $id]);
            if ($dn = $d->fetchColumn()) $errors[] = 'Phone ' . $phone . ' already belongs to ' . $dn . '.';
        }
        if (!$errors) {
            $fields = ['name' => $name, 'phone' => $phone ?: null, 'email' => $email ?: null, 'address' => input($_POST, 'address') ?: null, 'notes' => input($_POST, 'notes') ?: null];
            if (can('customers.edit')) {
                $limitRaw = input($_POST, 'credit_limit');
                $fields['allow_credit'] = isset($_POST['allow_credit']) ? 1 : 0;
                $fields['credit_limit'] = $limitRaw === '' ? null : number_format(max(0, (float) $limitRaw), 2, '.', '');
                $fields['is_active'] = isset($_POST['is_active']) ? 1 : 0;
            }
            $changes = [];
            foreach ($fields as $k => $v) { if ((string) $c[$k] !== (string) $v) $changes[] = $k . ': "' . ($c[$k] ?? '') . '" → "' . ($v ?? '') . '"'; }
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $pdo->prepare("UPDATE customers SET $set WHERE id = :id")->execute(array_merge(array_combine(array_map(fn($k) => ":$k", array_keys($fields)), array_values($fields)), [':id' => $id]));
            if ($changes) log_activity('customer_update', 'Updated ' . $name . ' — ' . implode('; ', $changes), 'customer', $id);
            flash('success', 'Customer details saved.');
            redirect('/customers/view.php?id=' . $id);
        }
    } elseif ($action === 'payment') {
        require_permission('customers.receive_payment');
        $amount = round(input_float($_POST, 'amount'), 2);
        $method = input($_POST, 'method');
        $ref = input($_POST, 'reference_no');
        $payBranch = write_branch();   // the money lands in this branch's cash / bank book
        if ($amount <= 0) $errors[] = 'Enter a payment amount.';
        elseif ($amount > (float) $c['credit_balance'] + 0.001) $errors[] = 'Payment is more than the ' . format_money($c['credit_balance']) . ' the customer owes.';
        if (!isset(money_methods()[$method])) $errors[] = 'Choose a payment method.';
        if (!$errors) {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT credit_balance FROM customers WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $id]);
            $newBal = round((float) $lock->fetchColumn() - $amount, 2);
            $pdo->prepare('UPDATE customers SET credit_balance = :b WHERE id = :id')->execute([':b' => $newBal, ':id' => $id]);
            $pdo->prepare("INSERT INTO customer_ledger (customer_id, branch_id, type, method, reference_no, amount, balance_after, note, created_by) VALUES (:c,:br,'payment_received',:m,:r,:a,:b,:n,:u)")
                ->execute([':c' => $id, ':br' => $payBranch, ':m' => $method, ':r' => $ref ?: null, ':a' => -$amount, ':b' => $newBal, ':n' => input($_POST, 'note') ?: 'Payment received', ':u' => current_user()['id']]);
            $pdo->commit();
            log_activity('customer_payment', 'Received ' . format_money($amount) . ' (' . method_label($method) . ') from ' . $c['name'] . ' · balance now ' . format_money($newBal), 'customer', $id);
            flash('success', 'Payment of ' . format_money($amount) . ' recorded. New balance ' . format_money($newBal) . '.');
            redirect('/customers/view.php?id=' . $id . '&tab=account');
        }
    }
}

$tab = input($_GET, 'tab') ?: 'purchases';
$stats = $pdo->prepare('SELECT COUNT(*) AS visits, COALESCE(SUM(total_amount),0) AS spent, MAX(sale_date) AS last_visit, MIN(sale_date) AS first_visit FROM sales WHERE customer_id = :id AND status IN ' . SALE_COUNTED);
$stats->execute([':id' => $id]);
$stats = $stats->fetch();
$returnsTotal = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sale_returns WHERE customer_id = :id AND status = 'processed'");
$returnsTotal->execute([':id' => $id]);
$returnsTotal = (float) $returnsTotal->fetchColumn();

$sales = $pdo->prepare("SELECT s.*, (SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id) AS units FROM sales s WHERE s.customer_id = :id AND s.status NOT IN ('held','cancelled') ORDER BY s.sale_date DESC LIMIT 100");
$sales->execute([':id' => $id]);
$sales = $sales->fetchAll();
$ledger = $pdo->prepare('SELECT cl.*, s.invoice_number, u.full_name AS by_name FROM customer_ledger cl LEFT JOIN sales s ON s.id = cl.sale_id LEFT JOIN users u ON u.id = cl.created_by WHERE cl.customer_id = :id ORDER BY cl.id DESC LIMIT 200');
$ledger->execute([':id' => $id]);
$ledger = $ledger->fetchAll();
$topItems = $pdo->prepare('SELECT p.name, pv.variant_label, SUM(si.quantity) AS qty, SUM(si.line_total) AS total FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id WHERE s.customer_id = :id AND s.status IN ' . SALE_COUNTED . ' GROUP BY pv.id ORDER BY qty DESC LIMIT 5');
$topItems->execute([':id' => $id]);
$topItems = $topItems->fetchAll();
$bal = (float) $c['credit_balance'];
$ledgerLabels = ['credit_sale' => 'Credit sale', 'payment_received' => 'Payment received', 'refund' => 'Return credited', 'adjustment' => 'Adjustment'];

$pageTitle = $c['name'];
require __DIR__ . '/../includes/header.php';
$actions = '';
if (can('customers.receive_payment') && $bal > 0) $actions .= '<button class="btn btn-primary" type="button" data-modal-open="#payModal">' . icon('hand-coins', 16) . ' Receive payment</button>';
if (can_any(['customers.edit', 'customers.create'])) $actions .= '<button class="btn btn-secondary" type="button" data-modal-open="#editModal">' . icon('pencil', 16) . ' Edit</button>';
page_header($c['name'], e($c['phone'] ?? 'No phone') . ($c['email'] ? ' · ' . e($c['email']) : '') . ($c['is_active'] ? '' : ' · ' . status_badge('inactive')), $actions, [['Customers', '/customers/index.php'], [$c['name'], null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>

<div class="grid grid-4 mb-4">
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">Account balance</span></div>
    <div class="kpi-value <?= $bal > 0 ? 'tone-neg' : ($bal < 0 ? 'tone-pos' : '') ?>"><?= $bal < 0 ? format_money(-$bal) : format_money($bal) ?></div>
    <div class="kpi-desc"><?= $bal > 0 ? 'Owed to the shop' : ($bal < 0 ? 'Store credit available' : 'Nothing owed') ?><?= !$c['allow_credit'] ? ' · credit disabled' : ($c['credit_limit'] !== null ? ' · limit ' . format_money($c['credit_limit']) : ' · no limit') ?></div></div>
  <?= kpi('Total spent', format_money($stats['spent']), 'banknote', 'green', 'Returns: ' . format_money($returnsTotal)) ?>
  <?= kpi('Visits', number_format((int) $stats['visits']), 'shopping-bag', 'blue', 'Avg ' . format_money((int) $stats['visits'] ? $stats['spent'] / $stats['visits'] : 0)) ?>
  <?= kpi('Last purchase', $stats['last_visit'] ? e(fmt_date($stats['last_visit'])) : '—', 'calendar', 'purple', $stats['first_visit'] ? 'Customer since ' . e(fmt_date($stats['first_visit'])) : '') ?>
</div>

<div class="layout-2-1">
  <div>
    <nav class="subnav">
      <a class="subnav-item <?= $tab === 'purchases' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=purchases"><?= icon('receipt', 15) ?> Purchase history</a>
      <a class="subnav-item <?= $tab === 'account' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=account"><?= icon('notebook-pen', 15) ?> Account statement</a>
    </nav>
    <div class="card flush">
    <?php if ($tab === 'account'): ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th class="num">Charge</th><th class="num">Payment / credit</th><th class="num">Balance</th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $l): $amt = (float) $l['amount']; ?>
          <tr>
            <td class="nowrap text-secondary"><?= e(fmt_datetime($l['created_at'])) ?></td>
            <td><?= e($ledgerLabels[$l['type']] ?? $l['type']) ?><?= $l['method'] ? ' · ' . e(method_label($l['method'])) : '' ?><div class="cell-sub"><?= e($l['note'] ?? '') ?><?= $l['by_name'] ? ' · ' . e($l['by_name']) : '' ?></div></td>
            <td class="text-secondary"><?= $l['invoice_number'] ? '<a class="link" href="' . e(app_url('/pos/sale_view.php?id=' . $l['sale_id'])) . '">' . e($l['invoice_number']) . '</a>' : e($l['reference_no'] ?? '—') ?></td>
            <td class="num"><?= $amt > 0 ? format_money($amt) : '' ?></td>
            <td class="num text-success"><?= $amt < 0 ? format_money(-$amt) : '' ?></td>
            <td class="num strong"><?= format_money($l['balance_after']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$ledger) echo empty_state('notebook-pen', 'No account activity', 'Credit sales and payments will appear here.'); ?></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Invoice</th><th>Date</th><th class="num">Items</th><th class="num">Total</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($sales as $s): ?>
          <tr class="<?= $s['status'] === 'voided' ? 'row-muted' : '' ?>">
            <td class="strong"><a href="<?= e(app_url('/pos/sale_view.php?id=' . $s['id'])) ?>"><?= e($s['invoice_number']) ?></a></td>
            <td class="text-secondary nowrap"><?= e(fmt_datetime($s['sale_date'])) ?></td>
            <td class="num"><?= (int) $s['units'] ?></td>
            <td class="num strong"><?= format_money($s['total_amount']) ?></td>
            <td><?= status_badge($s['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$sales) echo empty_state('receipt', 'No purchases yet', 'Attach this customer at the POS to build their history.'); ?></div>
    <?php endif; ?>
    </div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Details</h3>
      <dl class="desc-list">
        <dt>Phone</dt><dd><?= e($c['phone'] ?? '—') ?></dd>
        <dt>Email</dt><dd><?= e($c['email'] ?? '—') ?></dd>
        <dt>Address</dt><dd><?= e($c['address'] ?? '—') ?></dd>
        <dt>Credit</dt><dd><?= $c['allow_credit'] ? 'Allowed' . ($c['credit_limit'] !== null ? ' up to ' . format_money($c['credit_limit']) : '') : 'Not allowed' ?></dd>
        <dt>Registered</dt><dd><?= e(fmt_date($c['created_at'])) ?><?= $c['created_name'] ? ' · ' . e($c['created_name']) : '' ?></dd>
      </dl>
      <?php if ($c['notes']): ?><div class="callout mt-3"><?= e($c['notes']) ?></div><?php endif; ?>
    </div>
    <div class="card">
      <h3 class="mb-3">Favourite items</h3>
      <div class="list-rows">
        <?php foreach ($topItems as $i => $t): ?><div><span class="flex items-center gap-2"><span class="rank"><?= $i + 1 ?></span><?= e($t['name']) ?><?= $t['variant_label'] ? ' — ' . e($t['variant_label']) : '' ?></span><span class="text-secondary text-sm"><?= (int) $t['qty'] ?> bought</span></div><?php endforeach; ?>
        <?php if (!$topItems): ?><div class="text-muted">No purchases yet.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (can('customers.receive_payment')): ?>
<div class="modal-overlay" id="payModal" hidden>
  <div class="modal sm">
    <div class="modal-header"><h3>Receive payment</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <p class="text-muted text-sm"><?= e($c['name']) ?> owes <strong><?= format_money($bal) ?></strong>.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="payment">
      <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" min="0.01" max="<?= e((string) $bal) ?>" name="amount" value="<?= e(number_format(max(0, $bal), 2, '.', '')) ?>" required></div>
      <div class="form-row">
        <div class="form-group"><label>Method</label><select class="form-control" name="method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="reference_no" maxlength="60"></div>
      </div>
      <div class="form-group"><label>Note <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="note" maxlength="255"></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Record payment</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="editModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3>Edit customer</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update">
      <div class="form-group"><label>Full name</label><input class="form-control" type="text" name="name" required maxlength="150" value="<?= e($c['name']) ?>"></div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" type="tel" name="phone" maxlength="30" value="<?= e($c['phone'] ?? '') ?>"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" maxlength="150" value="<?= e($c['email'] ?? '') ?>"></div>
      </div>
      <div class="form-group"><label>Address</label><input class="form-control" type="text" name="address" maxlength="255" value="<?= e($c['address'] ?? '') ?>"></div>
      <div class="form-group"><label>Notes</label><input class="form-control" type="text" name="notes" maxlength="255" value="<?= e($c['notes'] ?? '') ?>"></div>
      <?php if (can('customers.edit')): ?>
        <div class="form-section-title">Credit & status</div>
        <div class="form-row items-center">
          <label class="check"><input type="checkbox" name="allow_credit" <?= $c['allow_credit'] ? 'checked' : '' ?>> Allow buying on credit</label>
          <div class="form-group mb-0"><label>Credit limit <span class="label-opt">(blank = no limit)</span></label><input class="form-control" type="number" step="0.01" min="0" name="credit_limit" value="<?= e($c['credit_limit'] ?? '') ?>"></div>
        </div>
        <label class="check mt-3"><input type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>> Active</label>
      <?php endif; ?>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
