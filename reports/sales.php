<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('this_month');
$b = period_bounds($period['from'], $period['to']);
$s = fin_sales($period['from'], $period['to']);
$r = fin_returns($period['from'], $period['to']);
$daily = fin_daily_sales($period['from'], $period['to']);
$methods = fin_payment_breakdown($period['from'], $period['to']);
$methodTotal = array_sum(array_column($methods, 'total')) ?: 1;
$voids = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) t FROM sales WHERE status = 'voided' AND sale_date >= :f AND sale_date < :t" . branch_and());
$voids->execute($b);
$voids = $voids->fetch();

$q = fn($sql) => (function () use ($pdo, $sql, $b) { $st = $pdo->prepare($sql); $st->execute($b); return $st->fetchAll(); })();
$byCashier = $q('SELECT u.full_name AS name, COUNT(*) AS n, SUM(s.total_amount) AS total, SUM(s.discount_amount) AS disc FROM sales s JOIN users u ON u.id = s.cashier_id WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY u.id ORDER BY total DESC');
$byCategory = $q("SELECT COALESCE(c.name, 'Uncategorized') AS name, SUM(si.quantity) AS qty, SUM(si.line_total - si.tax_amount) AS revenue, SUM(si.line_total - si.tax_amount - si.quantity * si.unit_cost) AS profit
    FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id LEFT JOIN categories c ON c.id = p.category_id
    WHERE s.status IN " . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY c.id ORDER BY revenue DESC');
$byHour = $q('SELECT HOUR(sale_date) AS h, COUNT(*) AS n, SUM(total_amount) AS total FROM sales WHERE status IN ' . SALE_COUNTED . ' AND sale_date >= :f AND sale_date < :t' . branch_and() . ' GROUP BY HOUR(sale_date) ORDER BY h');
$topCustomers = $q('SELECT c.id, c.name, COUNT(*) AS n, SUM(s.total_amount) AS total FROM sales s JOIN customers c ON c.id = s.customer_id WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY c.id ORDER BY total DESC LIMIT 8');
$walkin = $q('SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) t FROM sales WHERE customer_id IS NULL AND status IN ' . SALE_COUNTED . ' AND sale_date >= :f AND sale_date < :t' . branch_and())[0];
$hourMax = max(array_map(fn($h) => (float) $h['total'], $byHour) ?: [1]);
$avgBasket = (int) $s['count'] ? (float) $s['total'] / (int) $s['count'] : 0;

$chart = ['title' => 'Daily sales', 'labels' => array_map(fn($d) => date(count($daily) > 40 ? 'M j' : 'j M', strtotime($d['date'])), $daily), 'values' => array_map(fn($d) => round($d['total'], 2), $daily),
    'tips' => array_map(fn($d) => date('D, M j Y', strtotime($d['date'])), $daily), 'sub' => array_map(fn($d) => $d['count'] . ' transaction(s)', $daily)];

$pageTitle = 'Sales Report';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'Sales performance — ' . e($period['label']), '<a class="btn btn-secondary" href="' . e(app_url('/pos/sales_report_print.php' . query_with([]))) . '" target="_blank">' . icon('printer', 16) . ' Print / PDF</a>');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>
<div class="grid grid-5 mb-4">
  <?= kpi('Sales (incl. tax)', format_money_short($s['total']), 'banknote', '', number_format((int) $s['count']) . ' transactions') ?>
  <?= kpi('Average basket', format_money($avgBasket), 'shopping-basket', 'blue') ?>
  <?= kpi('Discounts given', format_money_short($s['discounts']), 'tag', 'purple', (float) $s['gross'] > 0 ? number_format($s['discounts'] / $s['gross'] * 100, 1) . '% of gross' : '') ?>
  <?= kpi('Returns', format_money_short($r['total']), 'undo-2', 'amber', (int) $r['count'] . ' return(s)', '/pos/returns.php') ?>
  <?= kpi('Cancelled sales', (string) (int) $voids['n'], 'ban', 'red', format_money_short($voids['t']) . ' cancelled', '/pos/history.php?status=voided&preset=' . e($period['preset']) . '&from=' . e($period['from']) . '&to=' . e($period['to'])) ?>
</div>

<div class="card mb-4">
  <div class="card-head"><div><h3>Daily sales</h3><div class="sub">Completed sales including tax · hover a bar for details</div></div></div>
  <div class="chart-wrap" data-chart='<?= e(json_encode($chart)) ?>'></div>
</div>

<div class="grid grid-2 mb-4">
  <div class="card">
    <h3 class="mb-3">By payment method</h3>
    <?php foreach ($methods as $m): ?>
      <div class="hbar-row"><span><?= e(method_label($m['method'])) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($m['total'] / $methodTotal * 100) ?>%"></span></span><span class="hbar-val"><?= format_money($m['total']) ?> <span class="text-muted text-sm">· <?= round($m['total'] / $methodTotal * 100) ?>%</span></span></div>
    <?php endforeach; ?>
    <?php if (!$methods) echo '<div class="text-muted">No sales.</div>'; ?>
  </div>
  <div class="card">
    <h3 class="mb-3">By hour of day</h3>
    <?php foreach ($byHour as $h): ?>
      <div class="hbar-row"><span><?= date('g A', mktime((int) $h['h'], 0)) ?> – <?= date('g A', mktime((int) $h['h'] + 1, 0)) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($h['total'] / $hourMax * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($h['total']) ?> <span class="text-muted text-sm">· <?= (int) $h['n'] ?></span></span></div>
    <?php endforeach; ?>
    <?php if (!$byHour) echo '<div class="text-muted">No sales.</div>'; ?>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <div class="card flush"><div class="card-head"><h3>By category</h3></div><div class="table-wrap"><table class="data-table">
    <thead><tr><th>Category</th><th class="num">Units</th><th class="num">Net sales</th><th class="num">Gross profit</th><th class="num">Margin</th></tr></thead>
    <tbody><?php foreach ($byCategory as $c): ?><tr><td class="strong"><?= e($c['name']) ?></td><td class="num"><?= number_format((int) $c['qty']) ?></td><td class="num"><?= format_money($c['revenue']) ?></td><td class="num"><?= format_money($c['profit']) ?></td><td class="num text-secondary"><?= $c['revenue'] > 0 ? number_format($c['profit'] / $c['revenue'] * 100, 1) : '0.0' ?>%</td></tr><?php endforeach; ?></tbody>
  </table></div></div>
  <div class="card flush"><div class="card-head"><h3>By cashier</h3></div><div class="table-wrap"><table class="data-table">
    <thead><tr><th>Cashier</th><th class="num">Sales</th><th class="num">Value</th><th class="num">Discounts</th></tr></thead>
    <tbody><?php foreach ($byCashier as $c): ?><tr><td class="strong"><?= e($c['name']) ?></td><td class="num"><?= number_format((int) $c['n']) ?></td><td class="num"><?= format_money($c['total']) ?></td><td class="num text-secondary"><?= format_money($c['disc']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div></div>
</div>

<div class="card flush">
  <div class="card-head"><div><h3>Top customers</h3><div class="sub">Walk-in (unregistered) sales: <?= number_format((int) $walkin['n']) ?> · <?= format_money($walkin['t']) ?></div></div></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Customer</th><th class="num">Visits</th><th class="num">Spent</th></tr></thead>
    <tbody><?php foreach ($topCustomers as $c): ?><tr><td><a class="strong" href="<?= e(app_url('/customers/view.php?id=' . $c['id'])) ?>"><?= e($c['name']) ?></a></td><td class="num"><?= (int) $c['n'] ?></td><td class="num strong"><?= format_money($c['total']) ?></td></tr><?php endforeach; ?></tbody>
  </table><?php if (!$topCustomers) echo empty_state('contact', 'No registered-customer sales'); ?></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
