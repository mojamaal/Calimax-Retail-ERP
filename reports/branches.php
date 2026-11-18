<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');
if (count(accessible_branches()) < 2) { flash('info', 'Branch comparison needs access to more than one branch.'); redirect('/reports/index.php'); }

$period = resolve_report_period('this_month');
$perf = branch_performance($period['from'], $period['to']);    // each branch evaluated independently of the top-bar selector
$seeCost = can('products.view_cost');

$tot = ['sales' => 0, 'net' => 0, 'gross' => 0, 'exp' => 0, 'profit' => 0, 'count' => 0, 'stock' => 0, 'cash' => 0, 'pay' => 0];
foreach ($perf as $r) {
    $p = $r['pnl'];
    $tot['sales'] += (float) $p['sales']['total']; $tot['net'] += $p['net_sales']; $tot['gross'] += $p['gross_profit']; $tot['exp'] += $p['expenses'];
    $tot['profit'] += $p['net_profit']; $tot['count'] += (int) $p['sales']['count']; $tot['stock'] += (float) $r['inventory']['cost_value'];
    $tot['cash'] += $r['cash'] + $r['bank']; $tot['pay'] += $r['payables'];
}
$maxSales = max(1, ...array_map(fn($r) => (float) $r['pnl']['sales']['total'], $perf));
$best = $perf; usort($best, fn($a, $b) => $b['pnl']['net_profit'] <=> $a['pnl']['net_profit']);

$pageTitle = 'Branch Comparison';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'How each branch performed — ' . e($period['label']) . ' (' . e(fmt_date($period['from'])) . ' – ' . e(fmt_date($period['to'])) . ')');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>
<div class="grid grid-4 mb-4">
  <?= kpi('Combined sales', format_money_short($tot['sales']), 'banknote', '', $tot['count'] . ' transaction(s) · incl. tax') ?>
  <?= kpi('Combined gross profit', format_money_short($tot['gross']), 'percent', 'green', $tot['net'] > 0 ? number_format($tot['gross'] / $tot['net'] * 100, 1) . '% margin' : '') ?>
  <?= kpi('Combined net profit', format_money_short($tot['profit']), 'scale', $tot['profit'] >= 0 ? 'green' : 'red', 'After all branch expenses') ?>
  <?= kpi('Best branch', e($best[0]['branch']['name']), 'trophy', 'amber', 'Net profit ' . format_money_short($best[0]['pnl']['net_profit'])) ?>
</div>

<div class="card flush mb-4">
  <div class="card-head"><h3>Profit &amp; loss by branch</h3></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Branch</th><th class="num">Transactions</th><th class="num">Sales (incl. tax)</th><th style="width:16%"></th><th class="num">Net sales</th><th class="num">Gross profit</th><th class="num">Margin</th><th class="num">Expenses</th><th class="num">Net profit</th></tr></thead>
    <tbody>
    <?php foreach ($perf as $r): $p = $r['pnl']; ?>
      <tr>
        <td class="strong"><?= e($r['branch']['name']) ?><div class="cell-sub mono"><?= e($r['branch']['code']) ?></div></td>
        <td class="num"><?= number_format((int) $p['sales']['count']) ?></td>
        <td class="num"><?= format_money($p['sales']['total']) ?></td>
        <td><div class="meter"><span style="width:<?= max(2, round((float) $p['sales']['total'] / $maxSales * 100)) ?>%"></span></div></td>
        <td class="num"><?= format_money($p['net_sales']) ?></td>
        <td class="num"><?= format_money($p['gross_profit']) ?></td>
        <td class="num"><?= number_format($p['gross_margin'], 1) ?>%</td>
        <td class="num"><?= format_money($p['expenses']) ?></td>
        <td class="num strong <?= $p['net_profit'] >= 0 ? 'text-success' : 'text-error' ?>"><?= format_money($p['net_profit']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>All branches</td><td class="num"><?= number_format($tot['count']) ?></td><td class="num"><?= format_money($tot['sales']) ?></td><td></td><td class="num"><?= format_money($tot['net']) ?></td><td class="num"><?= format_money($tot['gross']) ?></td><td class="num"><?= $tot['net'] > 0 ? number_format($tot['gross'] / $tot['net'] * 100, 1) . '%' : '—' ?></td><td class="num"><?= format_money($tot['exp']) ?></td><td class="num"><?= format_money($tot['profit']) ?></td></tr></tfoot>
  </table></div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Stock &amp; money position <span class="text-muted text-sm" style="font-weight:500;">(as of today)</span></h3></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Branch</th><th class="num">Stock units</th><?php if ($seeCost): ?><th class="num">Stock value (cost)</th><?php endif; ?><th class="num">Low / out</th><th class="num">Damaged</th><th class="num">Cash</th><th class="num">Bank</th><th class="num">Owed to suppliers</th></tr></thead>
    <tbody>
    <?php foreach ($perf as $r): $i = $r['inventory']; ?>
      <tr>
        <td class="strong"><?= e($r['branch']['name']) ?></td>
        <td class="num"><?= number_format((int) $i['units']) ?></td>
        <?php if ($seeCost): ?><td class="num"><?= format_money($i['cost_value']) ?></td><?php endif; ?>
        <td class="num"><?= (int) $i['low_stock'] ?> / <?= (int) $i['out_of_stock'] ?></td>
        <td class="num"><?= (int) $i['damaged_units'] ?: '—' ?></td>
        <td class="num"><?= format_money($r['cash']) ?></td>
        <td class="num"><?= format_money($r['bank']) ?></td>
        <td class="num <?= $r['payables'] > 0 ? 'text-error' : '' ?>"><?= format_money($r['payables']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>All branches</td><td class="num"><?= number_format((int) array_sum(array_map(fn($r) => (int) $r['inventory']['units'], $perf))) ?></td><?php if ($seeCost): ?><td class="num"><?= format_money($tot['stock']) ?></td><?php endif; ?><td></td><td></td><td class="num" colspan="2"><?= format_money($tot['cash']) ?></td><td class="num"><?= format_money($tot['pay']) ?></td></tr></tfoot>
  </table></div>
</div>
<div class="callout mt-4 text-sm"><?= icon('info', 14) ?> Customers and their credit balances are shared across branches, so receivables are not split here. Stock in transit between branches is not counted in either branch until it is received.</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
