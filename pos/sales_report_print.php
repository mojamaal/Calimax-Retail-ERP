<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('this_month');
$currency = setting('currency_symbol', 'Rs.');
$m = fn($n) => $currency . ' ' . number_format((float) $n, 2);
$s = fin_sales($period['from'], $period['to']);
$r = fin_returns($period['from'], $period['to']);
$p = fin_pnl($period['from'], $period['to']);
$methods = fin_payment_breakdown($period['from'], $period['to']);
$best = fin_best_sellers($period['from'], $period['to'], 15);
$sales = $pdo->prepare("SELECT s.invoice_number, s.sale_date, COALESCE(c.name,'Walk-in') AS customer, u.full_name AS cashier, s.total_amount, s.status
     FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id
     WHERE s.status IN " . SALE_COUNTED . " AND s.sale_date >= :f AND s.sale_date < :t" . branch_and('s') . " ORDER BY s.sale_date DESC LIMIT 500");
$sales->execute(period_bounds($period['from'], $period['to']));
$sales = $sales->fetchAll();

require __DIR__ . '/../includes/report_print.php';
render_report_print([
    'title' => 'Sales Report',
    'period_label' => $period['label'],
    'period_range' => fmt_date($period['from']) . ' to ' . fmt_date($period['to']),
    'generated_by' => current_user()['full_name'] ?? '',
    'kpis' => [
        ['label' => 'Sales (incl. tax)', 'value' => $s['total']],
        ['label' => 'Transactions', 'value' => $s['count'], 'format' => 'number'],
        ['label' => 'Returns', 'value' => $r['total']],
        ['label' => 'Gross profit', 'value' => $p['gross_profit'], 'sub' => number_format($p['gross_margin'], 1) . '% margin'],
    ],
    'tables' => [
        ['heading' => 'By payment method', 'columns' => ['Method', 'Transactions', 'Amount'], 'rows' => array_map(fn($x) => [method_label($x['method']), (int) $x['count'], $m($x['total'])], $methods)],
        ['heading' => 'Best-selling products', 'columns' => ['Product', 'Units', 'Net sales', 'Profit'], 'rows' => array_map(fn($b) => [$b['name'] . ($b['variant_label'] ? ' — ' . $b['variant_label'] : ''), (int) $b['qty'], $m($b['revenue']), $m($b['profit'])], $best)],
        ['heading' => 'Transactions' . (count($sales) >= 500 ? ' (latest 500)' : ''), 'columns' => ['Invoice', 'Date', 'Customer', 'Cashier', 'Total'],
         'rows' => array_map(fn($x) => [$x['invoice_number'] . ($x['status'] !== 'completed' ? ' *' : ''), date('M j, Y g:i A', strtotime($x['sale_date'])), $x['customer'], $x['cashier'], $m($x['total_amount'])], $sales),
         'totals' => ['', '', '', 'Total', $m($s['total'])]],
    ],
    'notes' => 'Cancelled sales are excluded. * = sale with items later returned (returns are reported separately).',
]);
