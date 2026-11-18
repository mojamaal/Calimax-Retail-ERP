<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$types = [
    'opening_cash' => ['Opening cash balance', null, 'cash'],
    'opening_bank' => ['Opening bank balance', null, 'bank'],
    'deposit'      => ['Bank deposit (cash → bank)', 'cash', 'bank'],
    'withdrawal'   => ['Bank withdrawal (bank → cash)', 'bank', 'cash'],
    'capital_cash' => ['Owner capital in (cash)', null, 'cash'],
    'capital_bank' => ['Owner capital in (bank)', null, 'bank'],
    'drawing_cash' => ['Owner drawing (cash)', 'cash', null],
    'drawing_bank' => ['Owner drawing (bank)', 'bank', null],
    'other_in_cash' => ['Other money in (cash)', null, 'cash'],
    'other_out_cash' => ['Other money out (cash)', 'cash', null],
];
$errors = [];
if (is_post()) {
    verify_csrf();
    require_permission('expenses.manage');
    $type = input($_POST, 'type');
    $amount = round(input_float($_POST, 'amount'), 2);
    $date = input_date($_POST, 'txn_date', date('Y-m-d'));
    if (!isset($types[$type])) $errors[] = 'Choose a transaction type.';
    if ($amount <= 0) $errors[] = 'Enter an amount.';
    $txnBranch = write_branch();   // every branch keeps its own cash drawer and bank account
    if (!$errors) {
        [$label, $fromAcc, $toAcc] = $types[$type];
        $note = input($_POST, 'note') ?: $label;
        $pdo->prepare('INSERT INTO cash_transactions (branch_id, txn_date, type, from_account, to_account, amount, reference_no, note, created_by) VALUES (:br,:d,:t,:f,:to,:a,:r,:n,:u)')
            ->execute([':br' => $txnBranch, ':d' => $date, ':t' => $type, ':f' => $fromAcc, ':to' => $toAcc, ':a' => $amount, ':r' => input($_POST, 'reference_no') ?: null, ':n' => $note, ':u' => current_user()['id']]);
        log_activity('cash_transaction', $label . ' ' . format_money($amount) . ' on ' . $date . ($note !== $label ? ' · ' . $note : ''), 'cash_transaction', (int) $pdo->lastInsertId());
        flash('success', $label . ' of ' . format_money($amount) . ' recorded.');
        redirect('/finance/cashbook.php?account=' . ($toAcc ?? $fromAcc));
    }
}

$account = input($_GET, 'account') === 'bank' ? 'bank' : 'cash';
$period = resolve_report_period('this_month');
$cashBal = cashbook_balance('cash');
$bankBal = cashbook_balance('bank');
$dayBefore = date('Y-m-d', strtotime($period['from'] . ' -1 day'));
$opening = cashbook_balance($account, $dayBefore);
$entries = cashbook_entries($account, $period['from'], $period['to']);
$in = array_sum(array_map(fn($e) => max(0, $e['amount']), $entries));
$out = -array_sum(array_map(fn($e) => min(0, $e['amount']), $entries));
$run = $opening;
foreach ($entries as &$en) { $run += $en['amount']; $en['balance'] = $run; }
unset($en);
$entries = array_reverse($entries);
$todayCashSales = $pdo->query("SELECT COALESCE(SUM(sp.amount),0) FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id WHERE sp.method = 'cash' AND s.status IN " . SALE_COUNTED . " AND s.sale_date >= CURDATE()" . branch_and('s'))->fetchColumn();

$pageTitle = 'Cash & Bank';
require __DIR__ . '/../includes/header.php';
page_header('Finance', 'Where the money is at ' . e(branch_scope_label()) . ': cash in the drawer/safe and money in the bank, from every sale, refund, expense and payment.', can('expenses.manage') && current_branch_id() !== null ?'<button class="btn btn-primary" type="button" data-modal-open="#txnModal">' . icon('arrow-left-right', 16) . ' Record transfer / opening balance</button>' : '');
nav_finance();
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<?php if ($cashBal < 0 || $bankBal < 0): ?>
  <div class="alert alert-warning"><?= icon('info', 18) ?><span>A balance below zero usually means money was moved between cash and bank, or an opening balance was never entered. Record <strong>opening balances</strong> and <strong>bank deposits/withdrawals</strong> so these figures match your drawer and bank statement.</span></div>
<?php endif; ?>
<div class="grid grid-4 mb-4">
  <?= kpi('Cash on hand', format_money($cashBal), 'banknote', 'green', 'Drawer + safe (all time)', '/finance/cashbook.php?account=cash') ?>
  <?= kpi('Bank balance', format_money($bankBal), 'landmark', 'blue', 'Card, transfer, mobile & cheques', '/finance/cashbook.php?account=bank') ?>
  <?= kpi('Cash sales today', format_money($todayCashSales), 'scan-barcode', '', 'Collected at the tills') ?>
  <?= kpi('Total cash + bank', format_money($cashBal + $bankBal), 'wallet', 'purple') ?>
</div>
<div class="card tight mb-4">
  <div class="filter-bar mb-3"><div class="segmented">
    <label><input type="radio" name="acc" <?= $account === 'cash' ? 'checked' : '' ?> onchange="location='<?= e(query_with(['account' => 'cash'])) ?>'"><span><?= icon('banknote', 14) ?> Cash book</span></label>
    <label><input type="radio" name="acc" <?= $account === 'bank' ? 'checked' : '' ?> onchange="location='<?= e(query_with(['account' => 'bank'])) ?>'"><span><?= icon('landmark', 14) ?> Bank book</span></label>
  </div></div>
  <?php render_period_filter($period, ['account' => $account]); ?>
</div>
<div class="grid grid-4 mb-4">
  <?= kpi('Opening balance', format_money($opening), 'flag', 'gray', 'On ' . e(fmt_date($period['from']))) ?>
  <?= kpi('Money in', format_money($in), 'arrow-down-left', 'green') ?>
  <?= kpi('Money out', format_money($out), 'arrow-up-right', 'red') ?>
  <?= kpi('Closing balance', format_money($opening + $in - $out), 'flag-triangle-right', 'blue', 'On ' . e(fmt_date($period['to']))) ?>
</div>
<div class="card flush">
  <div class="table-wrap" style="max-height:640px;overflow:auto;"><table class="data-table">
    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Ref</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($entries, 0, 1000) as $en): ?>
      <tr>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($en['dt'])) ?></td>
        <td><span class="badge badge-<?= $en['amount'] >= 0 ? 'success' : 'neutral' ?>"><?= e($en['kind']) ?></span></td>
        <td><?= $en['link'] ? '<a href="' . e(app_url($en['link'])) . '">' . e($en['description']) . '</a>' : e($en['description']) ?></td>
        <td class="text-secondary text-sm"><?= e($en['ref'] ?? '') ?></td>
        <td class="num text-success"><?= $en['amount'] > 0 ? format_money($en['amount']) : '' ?></td>
        <td class="num"><?= $en['amount'] < 0 ? format_money(-$en['amount']) : '' ?></td>
        <td class="num strong"><?= format_money($en['balance']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$entries) echo empty_state('landmark', 'No ' . $account . ' movements in this period'); ?></div>
  <?php if (count($entries) > 1000): ?><div class="pagination"><span class="text-muted">Showing the latest 1,000 of <?= count($entries) ?> entries — narrow the date range to see more.</span></div><?php endif; ?>
</div>

<?php if (can('expenses.manage')): ?>
<div class="modal-overlay" id="txnModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3>Record money movement</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <p class="text-muted text-sm">Sales, refunds, expenses and supplier/customer payments are recorded automatically. Use this for opening balances, bank deposits, withdrawals and owner capital or drawings.</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group"><label>Type</label><select class="form-control" name="type" required><?php foreach ($types as $k => [$l]): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="form-row">
        <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" required></div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="txn_date" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Reference <span class="label-opt">(deposit slip no.)</span></label><input class="form-control" type="text" name="reference_no" maxlength="60"></div>
        <div class="form-group"><label>Note</label><input class="form-control" type="text" name="note" maxlength="255"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Save</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
