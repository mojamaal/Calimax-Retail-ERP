<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$customers = $pdo->query("SELECT c.*,
    (SELECT MAX(created_at) FROM customer_ledger WHERE customer_id = c.id AND type = 'payment_received') AS last_payment,
    (SELECT MIN(cl.created_at) FROM customer_ledger cl WHERE cl.customer_id = c.id AND cl.type = 'credit_sale' AND cl.created_at > COALESCE((SELECT MAX(created_at) FROM customer_ledger WHERE customer_id = c.id AND balance_after <= 0), '1970-01-01')) AS oldest_unpaid
    FROM customers c WHERE c.credit_balance > 0 ORDER BY c.credit_balance DESC")->fetchAll();
$total = array_sum(array_column($customers, 'credit_balance'));
$aging = ['0–30 days' => 0, '31–60 days' => 0, '61–90 days' => 0, 'Over 90 days' => 0];
foreach ($customers as &$c) {
    $days = $c['oldest_unpaid'] ? (int) floor((time() - strtotime($c['oldest_unpaid'])) / 86400) : 0;
    $c['age'] = $days;
    $bucket = $days <= 30 ? '0–30 days' : ($days <= 60 ? '31–60 days' : ($days <= 90 ? '61–90 days' : 'Over 90 days'));
    $aging[$bucket] += (float) $c['credit_balance'];
}
unset($c);

$pageTitle = 'Receivables';
require __DIR__ . '/../includes/header.php';
page_header('Finance', 'Customers who bought on credit and still owe the shop' . (count(branches_all()) > 1 ? ' (customer accounts are shared by all branches).' : '.'), '<a class="btn btn-secondary" href="' . e(app_url('/finance/receivables_print.php')) . '" target="_blank">' . icon('printer', 16) . ' Print statement</a>');
nav_finance();
?>
<div class="grid grid-5 mb-4">
  <?= kpi('Total receivable', format_money($total), 'hand-coins', 'amber', count($customers) . ' customer(s)') ?>
  <?php foreach ($aging as $label => $amt): ?><?= kpi($label, format_money($amt), $label === 'Over 90 days' ? 'alarm-clock' : 'clock', $label === 'Over 90 days' && $amt > 0 ? 'red' : 'gray', 'Since oldest unpaid sale') ?><?php endforeach; ?>
</div>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Customer</th><th>Phone</th><th>Credit limit</th><th>Oldest unpaid</th><th>Last payment</th><th class="num">Owes</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($customers as $c): ?>
    <tr class="<?= $c['age'] > 90 ? 'row-bad' : '' ?>">
      <td><a class="strong" href="<?= e(app_url('/customers/view.php?id=' . $c['id'] . '&tab=account')) ?>"><?= e($c['name']) ?></a></td>
      <td class="text-secondary"><?= e($c['phone'] ?? '—') ?></td>
      <td class="text-secondary"><?= $c['credit_limit'] !== null ? format_money($c['credit_limit']) : 'No limit' ?><?= $c['credit_limit'] !== null && $c['credit_balance'] > $c['credit_limit'] ? ' <span class="badge badge-error">over</span>' : '' ?></td>
      <td class="text-secondary"><?= $c['oldest_unpaid'] ? e(fmt_date($c['oldest_unpaid'])) . ' <span class="cell-sub">(' . $c['age'] . ' days)</span>' : '—' ?></td>
      <td class="text-secondary"><?= e(fmt_date($c['last_payment'])) ?></td>
      <td class="num strong"><?= format_money($c['credit_balance']) ?></td>
      <td class="actions"><a class="btn btn-primary btn-sm" href="<?= e(app_url('/customers/view.php?id=' . $c['id'] . '&tab=account')) ?>">Receive payment</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!$customers) echo empty_state('hand-coins', 'Nobody owes the shop money', 'Credit sales will appear here until they are paid.'); ?></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
