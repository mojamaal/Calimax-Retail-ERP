<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$period = resolve_report_period('this_month');
$p = fin_pnl($period['from'], $period['to']);
$byCat = fin_expenses_by_category($period['from'], $period['to']);
$purch = fin_purchases($period['from'], $period['to']);
$inclusive = setting('prices_include_tax', '1') === '1';

$statement = [
    ['type' => 'section', 'label' => 'Revenue'],
    ['type' => 'line', 'label' => 'Gross sales (' . (int) $p['sales']['count'] . ' transactions)', 'value' => $p['gross_sales']],
    ['type' => 'line', 'label' => 'Less: discounts', 'value' => -$p['discounts']],
];
if ($inclusive) $statement[] = ['type' => 'line', 'label' => 'Less: tax included in prices', 'value' => -(float) $p['sales']['tax']];
$statement[] = ['type' => 'line', 'label' => 'Less: customer returns (' . (int) $p['returns']['count'] . ')', 'value' => -$p['returns']['net_ex_tax']];
$statement[] = ['type' => 'subtotal', 'label' => 'Net sales', 'value' => $p['net_sales']];
$statement[] = ['type' => 'section', 'label' => 'Cost of sales'];
$statement[] = ['type' => 'line', 'label' => 'Cost of goods sold', 'value' => -$p['cogs']];
$statement[] = ['type' => 'line', 'label' => 'Inventory write-offs & adjustments', 'value' => $p['inventory_adjustments']];
$statement[] = ['type' => 'subtotal', 'label' => 'Gross profit (' . number_format($p['gross_margin'], 1) . '%)', 'value' => $p['gross_profit']];
$statement[] = ['type' => 'section', 'label' => 'Operating expenses'];
foreach ($byCat as $row) $statement[] = ['type' => 'line', 'label' => $row['name'], 'value' => -(float) $row['total']];
if (!$byCat) $statement[] = ['type' => 'line', 'label' => 'No expenses recorded', 'value' => 0];
$statement[] = ['type' => 'subtotal', 'label' => 'Total operating expenses', 'value' => -$p['expenses']];
$statement[] = ['type' => 'grand', 'label' => 'Net profit', 'value' => $p['net_profit']];

require __DIR__ . '/../includes/report_print.php';
render_report_print([
    'title' => 'Profit & Loss Statement',
    'period_label' => $period['label'],
    'period_range' => fmt_date($period['from']) . ' to ' . fmt_date($period['to']),
    'generated_by' => current_user()['full_name'] ?? '',
    'kpis' => [
        ['label' => 'Net sales', 'value' => $p['net_sales']],
        ['label' => 'Purchases received', 'value' => $purch['total']],
        ['label' => 'Gross profit', 'value' => $p['gross_profit'], 'sub' => number_format($p['gross_margin'], 1) . '% margin'],
        ['label' => 'Net profit', 'value' => $p['net_profit'], 'tone' => $p['net_profit'] >= 0 ? 'positive' : 'negative'],
    ],
    'statement' => $statement,
    'notes' => 'Net sales exclude tax collected. Cost of goods sold uses the purchase cost recorded at the moment of each sale (weighted average), less the cost of items customers returned. Purchases add to inventory and are expensed only when sold.',
]);
