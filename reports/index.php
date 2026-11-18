<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$period = resolve_report_period('this_month');
$p = fin_pnl($period['from'], $period['to']);
$byCat = fin_expenses_by_category($period['from'], $period['to']);
$purch = fin_purchases($period['from'], $period['to']);
$inclusive = setting('prices_include_tax', '1') === '1';

// Previous equivalent period for comparison.
$days = (strtotime($period['to']) - strtotime($period['from'])) / 86400 + 1;
$prevTo = date('Y-m-d', strtotime($period['from'] . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
$prev = fin_pnl($prevFrom, $prevTo);
$delta = function ($cur, $old) {
    if (abs($old) < 0.01) return '';
    $d = ($cur - $old) / abs($old) * 100;
    return '<span class="kpi-trend ' . ($d < 0 ? 'negative' : '') . '">' . ($d >= 0 ? '▲ ' : '▼ ') . number_format(abs($d), 1) . '%</span> vs previous period';
};

// 12-month trend
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $mf = date('Y-m-01', strtotime("first day of -$i month"));
    $mt = date('Y-m-t', strtotime($mf));
    if ($mt > date('Y-m-d')) $mt = date('Y-m-d');
    $mp = fin_pnl($mf, $mt);
    $months[] = ['label' => date('M Y', strtotime($mf)), 'from' => $mf, 'to' => $mt, 'p' => $mp];
}
$neg = fn($v) => '(' . format_money(abs($v)) . ')';

$pageTitle = 'Profit & Loss';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'How the business performed — ' . e($period['label']) . ' (' . e(fmt_date($period['from'])) . ' – ' . e(fmt_date($period['to'])) . ')',
    '<a class="btn btn-secondary" href="' . e(app_url('/finance/reports_print.php' . query_with([]))) . '" target="_blank">' . icon('printer', 16) . ' Print / PDF</a>');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>

<div class="grid grid-4 mb-4">
  <?= kpi('Net sales', format_money($p['net_sales']), 'trending-up', '', $delta($p['net_sales'], $prev['net_sales'])) ?>
  <?= kpi('Gross profit', format_money($p['gross_profit']), 'percent', 'green', number_format($p['gross_margin'], 1) . '% margin · ' . $delta($p['gross_profit'], $prev['gross_profit'])) ?>
  <?= kpi('Operating expenses', format_money($p['expenses']), 'receipt', 'amber', $delta($p['expenses'], $prev['expenses'])) ?>
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">Net profit</span><span class="icon-tile sm <?= $p['net_profit'] >= 0 ? 'green' : 'red' ?>"><?= icon($p['net_profit'] >= 0 ? 'smile' : 'frown', 16) ?></span></div>
    <div class="kpi-value <?= $p['net_profit'] >= 0 ? 'tone-pos' : 'tone-neg' ?>"><?= format_money($p['net_profit']) ?></div><div class="kpi-desc"><?= number_format($p['net_margin'], 1) ?>% of net sales · <?= $delta($p['net_profit'], $prev['net_profit']) ?></div></div>
</div>

<div class="callout mb-4"><?= icon('lightbulb', 14) ?> <strong>Sales are not profit.</strong> Of every <?= format_money(100) ?> of net sales this period, <?= format_money($p['net_sales'] > 0 ? $p['cogs'] / $p['net_sales'] * 100 : 0) ?> paid for the goods themselves and <?= format_money($p['net_sales'] > 0 ? $p['expenses'] / $p['net_sales'] * 100 : 0) ?> covered running costs, leaving <strong><?= format_money($p['net_sales'] > 0 ? $p['net_profit'] / $p['net_sales'] * 100 : 0) ?></strong> profit.</div>

<div class="layout-2-1 mb-4">
  <div class="card">
    <h3 class="mb-3">Profit &amp; loss statement</h3>
    <table class="stmt">
      <tr class="section"><td colspan="2">Revenue</td></tr>
      <tr class="indent"><td>Gross sales (<?= number_format((int) $p['sales']['count']) ?> transactions, before discounts)</td><td><?= format_money($p['gross_sales']) ?></td></tr>
      <tr class="indent"><td>Less: discounts given</td><td><?= $neg($p['discounts']) ?></td></tr>
      <?php if ($inclusive): ?><tr class="indent"><td>Less: tax included in prices (owed to government)</td><td><?= $neg($p['sales']['tax']) ?></td></tr><?php endif; ?>
      <tr class="indent"><td>Less: customer returns (<?= (int) $p['returns']['count'] ?>, excl. tax)</td><td><?= $neg($p['returns']['net_ex_tax']) ?></td></tr>
      <tr class="sub"><td>Net sales</td><td><?= format_money($p['net_sales']) ?></td></tr>
      <tr class="section"><td colspan="2">Cost of sales</td></tr>
      <tr class="indent"><td>Cost of goods sold (purchase cost of items sold, net of returns)</td><td><?= $neg($p['cogs']) ?></td></tr>
      <tr class="indent"><td>Inventory write-offs &amp; count adjustments</td><td><?= $p['inventory_adjustments'] < 0 ? $neg($p['inventory_adjustments']) : format_money($p['inventory_adjustments']) ?></td></tr>
      <tr class="sub"><td>Gross profit <span class="text-muted" style="font-weight:500;">· <?= number_format($p['gross_margin'], 1) ?>% margin</span></td><td><?= format_money($p['gross_profit']) ?></td></tr>
      <tr class="section"><td colspan="2">Operating expenses</td></tr>
      <?php foreach ($byCat as $c): ?><tr class="indent"><td><?= e($c['name']) ?></td><td><?= $neg($c['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$byCat): ?><tr class="indent"><td class="text-muted">No expenses recorded</td><td>—</td></tr><?php endif; ?>
      <tr class="sub"><td>Total operating expenses</td><td><?= $neg($p['expenses']) ?></td></tr>
      <tr class="grand"><td>Net profit</td><td class="<?= $p['net_profit'] >= 0 ? 'text-success' : 'text-error' ?>"><?= $p['net_profit'] < 0 ? $neg($p['net_profit']) : format_money($p['net_profit']) ?></td></tr>
    </table>
  </div>
  <div class="grid">
    <div class="card">
      <h4 class="mb-3">Purchasing in this period</h4>
      <dl class="desc-list">
        <dt>Goods received</dt><dd><?= format_money($purch['total']) ?></dd>
        <dt>Returned to suppliers</dt><dd><?= $neg($purch['returns']) ?></dd>
        <dt>Net purchases</dt><dd><?= format_money($purch['net']) ?></dd>
        <dt>Bought on credit</dt><dd><?= format_money($purch['on_credit']) ?></dd>
      </dl>
      <p class="text-muted text-sm mt-3 mb-0">Purchases add to stock; they become cost only when the goods are sold (COGS).</p>
    </div>
    <div class="card">
      <h4 class="mb-3">Position today</h4>
      <dl class="desc-list">
        <dt>Cash on hand</dt><dd><?= format_money(cashbook_balance('cash')) ?></dd>
        <dt>Bank</dt><dd><?= format_money(cashbook_balance('bank')) ?></dd>
        <dt>Inventory (at cost)</dt><dd><?= format_money(inventory_value()['cost_value']) ?></dd>
        <dt>Customers owe us</dt><dd><?= format_money(receivables_total()) ?></dd>
        <dt>We owe suppliers</dt><dd class="text-error"><?= format_money(payables_total()) ?></dd>
        <dt>Tax collected (period)</dt><dd><?= format_money($p['tax_collected']) ?></dd>
      </dl>
    </div>
  </div>
</div>

<div class="card flush">
  <div class="card-head"><div><h3>Last 12 months</h3><div class="sub">Click a month to open its full report.</div></div></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Month</th><th class="num">Net sales</th><th class="num">COGS</th><th class="num">Gross profit</th><th class="num">Margin</th><th class="num">Expenses</th><th class="num">Net profit</th></tr></thead>
    <tbody>
    <?php foreach (array_reverse($months) as $m): $mp = $m['p']; ?>
      <tr>
        <td class="strong"><a href="?preset=custom&from=<?= $m['from'] ?>&to=<?= $m['to'] ?>"><?= e($m['label']) ?></a></td>
        <td class="num"><?= format_money($mp['net_sales']) ?></td>
        <td class="num text-secondary"><?= format_money($mp['cogs']) ?></td>
        <td class="num"><?= format_money($mp['gross_profit']) ?></td>
        <td class="num text-secondary"><?= number_format($mp['gross_margin'], 1) ?>%</td>
        <td class="num text-secondary"><?= format_money($mp['expenses']) ?></td>
        <td class="num strong <?= $mp['net_profit'] < 0 ? 'text-error' : 'text-success' ?>"><?= format_money($mp['net_profit']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
