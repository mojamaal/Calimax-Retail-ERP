<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$balSql = supplier_balance_sql('s');   // what the CURRENT branch owes (combined in the all-branches view)
$suppliers = $pdo->query("SELECT s.*, $balSql AS balance,
    (SELECT MAX(payment_date) FROM supplier_payments WHERE supplier_id = s.id AND amount > 0" . branch_and() . ") AS last_payment,
    (SELECT MIN(g.received_date) FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id WHERE po.supplier_id = s.id AND g.payment_mode = 'credit'" . branch_and('g') . "
        AND g.received_date > COALESCE((SELECT MAX(payment_date) FROM supplier_payments WHERE supplier_id = s.id AND amount > 0" . branch_and() . "), '1970-01-01')) AS oldest_unpaid
    FROM suppliers s WHERE $balSql > 0 ORDER BY balance DESC")->fetchAll();
$total = array_sum(array_column($suppliers, 'balance'));
$overdue = 0.0;
foreach ($suppliers as &$s) {
    $s['due'] = $s['oldest_unpaid'] ? date('Y-m-d', strtotime($s['oldest_unpaid'] . ' +' . (int) $s['payment_terms_days'] . ' days')) : null;
    $s['is_overdue'] = $s['due'] && $s['due'] < date('Y-m-d');
    if ($s['is_overdue']) $overdue += (float) $s['balance'];
}
unset($s);

$pageTitle = 'Payables';
require __DIR__ . '/../includes/header.php';
page_header('Finance', 'What the shop owes its suppliers, with payment terms and due dates.', '<a class="btn btn-secondary" href="' . e(app_url('/finance/payables_print.php')) . '" target="_blank">' . icon('printer', 16) . ' Print statement</a>');
nav_finance();
?>
<div class="grid grid-3 mb-4">
  <?= kpi('Total payable', format_money($total), 'file-clock', 'amber', count($suppliers) . ' supplier(s)') ?>
  <?= kpi('Overdue', format_money($overdue), 'alarm-clock', $overdue > 0 ? 'red' : 'gray', 'Past supplier payment terms') ?>
  <?= kpi('Cash + bank available', format_money(cashbook_balance('cash') + cashbook_balance('bank')), 'wallet', 'green', 'To settle payables', '/finance/cashbook.php') ?>
</div>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Supplier</th><th>Terms</th><th>Oldest unpaid delivery</th><th>Due</th><th>Last payment</th><th class="num">We owe</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($suppliers as $s): ?>
    <tr class="<?= $s['is_overdue'] ? 'row-bad' : '' ?>">
      <td><a class="strong" href="<?= e(app_url('/purchase/supplier_view.php?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a><div class="cell-sub"><?= e($s['phone'] ?? '') ?></div></td>
      <td class="text-secondary"><?= (int) $s['payment_terms_days'] ? (int) $s['payment_terms_days'] . ' days' : 'COD' ?></td>
      <td class="text-secondary"><?= e(fmt_date($s['oldest_unpaid'])) ?></td>
      <td><?= $s['due'] ? ($s['is_overdue'] ? '<span class="badge badge-error">Overdue · ' . e(fmt_date($s['due'])) . '</span>' : e(fmt_date($s['due']))) : '—' ?></td>
      <td class="text-secondary"><?= e(fmt_date($s['last_payment'])) ?></td>
      <td class="num strong"><?= format_money($s['balance']) ?></td>
      <td class="actions"><a class="btn btn-<?= can('suppliers.pay') ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/purchase/supplier_view.php?id=' . $s['id'])) ?>"><?= can('suppliers.pay') ? 'Pay' : 'View' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$suppliers) echo empty_state('file-clock', 'You don’t owe any suppliers', 'Deliveries received on credit appear here until paid.'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
