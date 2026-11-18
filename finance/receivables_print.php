<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$customers = $pdo->query('SELECT * FROM customers WHERE credit_balance > 0 ORDER BY credit_balance DESC')->fetchAll();
$totalReceivable = array_sum(array_column($customers, 'credit_balance'));
$currency = setting('currency_symbol', 'Rs.');

require __DIR__ . '/../includes/report_print.php';
render_report_print([
    'title' => 'Accounts Receivable Statement',
    'period_label' => 'As of ' . date('M j, Y'),
    'generated_by' => current_user()['full_name'] ?? '',
    'kpis' => [
        ['label' => 'Total Receivable', 'value' => $totalReceivable],
        ['label' => 'Customers Owing', 'value' => count($customers), 'format' => 'number'],
    ],
    'tables' => [[
        'heading' => 'Outstanding Customer Balances',
        'columns' => ['Customer', 'Phone', 'Balance Owed'],
        'rows' => array_map(fn($c) => [$c['name'], $c['phone'] ?: '—', $currency . ' ' . number_format($c['credit_balance'], 2)], $customers),
        'totals' => ['', 'Total', $currency . ' ' . number_format($totalReceivable, 2)],
    ]],
]);
