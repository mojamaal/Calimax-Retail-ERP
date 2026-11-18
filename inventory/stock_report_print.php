<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$currency = setting('currency_symbol', 'Rs.');
$m = fn($n) => $currency . ' ' . number_format((float) $n, 2);
$inv = inventory_value();
$variants = $pdo->query(
    "SELECT p.name AS product_name, pv.variant_label, pv.sku, pv.stock_qty, pv.damaged_qty, pv.reorder_level, pv.cost_price, pv.price,
            (GREATEST(pv.stock_qty,0) * pv.cost_price) AS value
     FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id WHERE pv.is_active = 1 AND p.is_active = 1 ORDER BY p.name, pv.variant_label"
)->fetchAll();

require __DIR__ . '/../includes/report_print.php';
render_report_print([
    'title' => 'Inventory Valuation',
    'period_label' => 'As of ' . date('M j, Y g:i A'),
    'generated_by' => current_user()['full_name'] ?? '',
    'kpis' => [
        ['label' => 'Units on hand', 'value' => $inv['units'], 'format' => 'number'],
        ['label' => 'Value at cost', 'value' => $inv['cost_value']],
        ['label' => 'Value at retail', 'value' => $inv['retail_value']],
        ['label' => 'Damaged (at cost)', 'value' => $inv['damaged_value'], 'tone' => $inv['damaged_value'] > 0 ? 'negative' : ''],
    ],
    'tables' => [[
        'heading' => 'Stock on hand',
        'columns' => ['Product', 'Code', 'Qty', 'Damaged', 'Reorder', 'Unit cost', 'Value'],
        'rows' => array_map(fn($v) => [
            $v['product_name'] . ($v['variant_label'] ? ' — ' . $v['variant_label'] : '') . ((int) $v['stock_qty'] <= (int) $v['reorder_level'] ? '  [LOW]' : ''),
            $v['sku'], (int) $v['stock_qty'], (int) $v['damaged_qty'], (int) $v['reorder_level'], $m($v['cost_price']), $m($v['value']),
        ], $variants),
        'totals' => ['', '', number_format((float) $inv['units']), number_format((float) $inv['damaged_units']), '', 'Total', $m($inv['cost_value'])],
    ]],
    'notes' => 'Valued at weighted-average cost. Damaged stock is held separately and excluded from saleable quantity and value.',
]);
