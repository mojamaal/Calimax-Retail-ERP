<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('last_month');
$b = period_bounds($period['from'], $period['to']);
$byRate = $pdo->prepare('SELECT si.tax_rate, COUNT(DISTINCT si.sale_id) AS sales, SUM(si.line_total) AS gross, SUM(si.tax_amount) AS tax
    FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY si.tax_rate ORDER BY si.tax_rate DESC');
$byRate->execute($b);
$byRate = $byRate->fetchAll();
$ret = fin_returns($period['from'], $period['to']);
$sales = fin_sales($period['from'], $period['to']);
$netTax = (float) $sales['tax'] - (float) $ret['tax'];
$monthly = $pdo->query('SELECT DATE_FORMAT(sale_date, "%Y-%m") AS m, SUM(total_amount) AS total, SUM(tax_amount) AS tax FROM sales WHERE status IN ' . SALE_COUNTED . ' AND sale_date >= DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, "%Y-%m-01")' . branch_and() . ' GROUP BY m ORDER BY m DESC')->fetchAll();
$inclusive = setting('prices_include_tax', '1') === '1';

$pageTitle = 'Tax Report';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'Tax collected on sales, for filing returns. Registration no: ' . e(setting('tax_registration_no') ?: 'not set — add it in Settings'));
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>
<div class="grid grid-4 mb-4">
  <?= kpi('Taxable sales', format_money($sales['total']), 'receipt', '', 'Prices ' . ($inclusive ? 'include' : 'exclude') . ' tax') ?>
  <?= kpi('Tax collected', format_money($sales['tax']), 'percent', 'blue') ?>
  <?= kpi('Tax refunded on returns', format_money($ret['tax']), 'undo-2', 'amber') ?>
  <?= kpi('Net tax payable', format_money($netTax), 'landmark', 'red', e($period['label'])) ?>
</div>
<div class="grid grid-2">
  <div class="card flush"><div class="card-head"><h3>By tax rate</h3></div><div class="table-wrap"><table class="data-table">
    <thead><tr><th>Rate</th><th class="num">Sales</th><th class="num">Value (incl. tax)</th><th class="num">Tax</th></tr></thead>
    <tbody><?php foreach ($byRate as $r): ?><tr><td class="strong"><?= rtrim(rtrim(number_format($r['tax_rate'], 2), '0'), '.') ?>%<?= (float) $r['tax_rate'] == 0 ? ' <span class="cell-sub">exempt / zero-rated</span>' : '' ?></td><td class="num"><?= number_format((int) $r['sales']) ?></td><td class="num"><?= format_money($r['gross']) ?></td><td class="num strong"><?= format_money($r['tax']) ?></td></tr><?php endforeach; ?></tbody>
  </table><?php if (!$byRate) echo empty_state('percent', 'No sales in this period'); ?></div></div>
  <div class="card flush"><div class="card-head"><h3>Monthly summary</h3></div><div class="table-wrap"><table class="data-table">
    <thead><tr><th>Month</th><th class="num">Sales</th><th class="num">Tax collected</th></tr></thead>
    <tbody><?php foreach ($monthly as $m): ?><tr><td class="strong"><?= e(date('F Y', strtotime($m['m'] . '-01'))) ?></td><td class="num"><?= format_money($m['total']) ?></td><td class="num"><?= format_money($m['tax']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
