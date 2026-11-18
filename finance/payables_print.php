<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$balSql = supplier_balance_sql('s');
$suppliers = $pdo->query("SELECT s.*, $balSql AS balance FROM suppliers s WHERE $balSql > 0 ORDER BY balance DESC")->fetchAll();
$totalPayable = array_sum(array_column($suppliers, 'balance'));
$currency = setting('currency_symbol', 'Rs.');

require __DIR__ . '/../includes/report_print.php';
render_report_print([
    'title' => 'Accounts Payable Statement',
    'period_label' => 'As of ' . date('M j, Y'),
    'generated_by' => current_user()['full_name'] ?? '',
    'kpis' => [
        ['label' => 'Total Payable', 'value' => $totalPayable],
        ['label' => 'Suppliers Owed', 'value' => count($suppliers), 'format' => 'number'],
    ],
    'tables' => [[
        'heading' => 'Outstanding Supplier Balances',
        'columns' => ['Supplier', 'Phone', 'Balance Owed'],
        'rows' => array_map(fn($s) => [$s['name'], $s['phone'] ?: '—', $currency . ' ' . number_format($s['balance'], 2)], $suppliers),
        'totals' => ['', 'Total', $currency . ' ' . number_format($totalPayable, 2)],
    ]],
]);
