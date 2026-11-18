<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$inv = inventory_value();
$byCat = $pdo->query("SELECT COALESCE(c.name,'Uncategorized') AS name, COUNT(pv.id) AS items, SUM(GREATEST(pv.stock_qty,0)) AS units,
    SUM(GREATEST(pv.stock_qty,0) * pv.cost_price) AS cost_value, SUM(GREATEST(pv.stock_qty,0) * pv.price) AS retail_value,
    SUM(CASE WHEN pv.stock_qty <= 0 THEN 1 ELSE 0 END) AS out_c, SUM(CASE WHEN pv.stock_qty > 0 AND pv.stock_qty <= pv.reorder_level THEN 1 ELSE 0 END) AS low_c
    FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE pv.is_active = 1 AND p.is_active = 1 GROUP BY c.id ORDER BY cost_value DESC")->fetchAll();
$reorder = $pdo->query("SELECT p.id AS product_id, p.name, pv.id, pv.variant_label, pv.sku, pv.stock_qty, pv.reorder_level, pv.cost_price,
    (SELECT COALESCE(SUM(si.quantity),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.variant_id = pv.id AND s.status IN " . SALE_COUNTED . " AND s.sale_date >= NOW() - INTERVAL 30 DAY" . branch_and('s') . ") AS sold30,
    (SELECT COALESCE(SUM(poi.quantity - poi.received_qty),0) FROM purchase_order_items poi JOIN purchase_orders po ON po.id = poi.po_id WHERE poi.variant_id = pv.id AND po.status IN ('ordered','partially_received')" . branch_and('po') . ") AS on_order
    FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id WHERE pv.is_active = 1 AND p.is_active = 1 AND pv.stock_qty <= pv.reorder_level ORDER BY pv.stock_qty ASC, sold30 DESC LIMIT 100")->fetchAll();
$damaged = $pdo->query('SELECT p.id AS product_id, p.name, pv.variant_label, pv.sku, pv.damaged_qty, (pv.damaged_qty * pv.cost_price) AS value FROM ' . pv() . ' pv JOIN products p ON p.id = pv.product_id WHERE pv.damaged_qty > 0 ORDER BY value DESC')->fetchAll();
$cogs90 = fin_cogs_gross(date('Y-m-d', strtotime('-89 days')), date('Y-m-d'));
$turnover = (float) $inv['cost_value'] > 0 ? ($cogs90 * 365 / 90) / (float) $inv['cost_value'] : 0;

$pageTitle = 'Inventory Report';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'Stock value, what needs reordering, damaged stock and how fast inventory turns over.', '<a class="btn btn-secondary" href="' . e(app_url('/inventory/stock_report_print.php')) . '" target="_blank">' . icon('printer', 16) . ' Full valuation (PDF)</a>');
nav_reports();
?>
<div class="grid grid-5 mb-4">
  <?= kpi('Inventory value (cost)', format_money_short($inv['cost_value']), 'wallet', '', number_format((int) $inv['units']) . ' units') ?>
  <?= kpi('Retail value', format_money_short($inv['retail_value']), 'tag', 'green', 'Potential margin ' . format_money_short($inv['retail_value'] - $inv['cost_value'])) ?>
  <?= kpi('Need reordering', (string) ((int) $inv['low_stock'] + (int) $inv['out_of_stock']), 'alert-triangle', 'amber', (int) $inv['out_of_stock'] . ' out of stock') ?>
  <?= kpi('Damaged stock', format_money_short($inv['damaged_value']), 'shield-alert', 'red', (int) $inv['damaged_units'] . ' units held out of sale') ?>
  <?= kpi('Stock turnover', number_format($turnover, 1) . '×', 'refresh-cw', 'blue', 'Per year · ~' . ($turnover > 0 ? round(365 / $turnover) : '—') . ' days of stock') ?>
</div>
<div class="card flush mb-4"><div class="card-head"><h3>Value by category</h3></div><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Units</th><th class="num">Cost value</th><th class="num">Retail value</th><th class="num">Low</th><th class="num">Out</th></tr></thead>
  <tbody><?php foreach ($byCat as $c): ?><tr><td class="strong"><?= e($c['name']) ?></td><td class="num"><?= (int) $c['items'] ?></td><td class="num"><?= number_format((int) $c['units']) ?></td><td class="num"><?= format_money($c['cost_value']) ?></td><td class="num text-secondary"><?= format_money($c['retail_value']) ?></td><td class="num"><?= (int) $c['low_c'] ?: '—' ?></td><td class="num"><?= (int) $c['out_c'] ? '<span class="text-error fw-700">' . (int) $c['out_c'] . '</span>' : '—' ?></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td>Total</td><td></td><td class="num"><?= number_format((int) $inv['units']) ?></td><td class="num"><?= format_money($inv['cost_value']) ?></td><td class="num"><?= format_money($inv['retail_value']) ?></td><td></td><td></td></tr></tfoot>
</table></div></div>
<div class="layout-2-1">
  <div class="card flush"><div class="card-head"><div><h3>Reorder list</h3><div class="sub">At or below reorder level, with what’s already on order.</div></div>
    <?php if (can('purchase.manage')): ?><a class="btn btn-primary btn-sm" href="<?= e(app_url('/purchase/order_form.php?suggest=1')) ?>"><?= icon('file-plus', 14) ?> Create purchase order</a><?php endif; ?></div>
    <div class="table-wrap" style="max-height:520px;overflow:auto;"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Reorder at</th><th class="num">Sold (30d)</th><th class="num">On order</th></tr></thead>
      <tbody><?php foreach ($reorder as $r): ?><tr class="<?= $r['stock_qty'] <= 0 ? 'row-bad' : '' ?>"><td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $r['product_id'])) ?>"><?= e($r['name']) ?><?= $r['variant_label'] ? ' — ' . e($r['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($r['sku']) ?></div></td>
        <td class="num strong <?= $r['stock_qty'] <= 0 ? 'text-error' : 'text-warning' ?>"><?= (int) $r['stock_qty'] ?></td><td class="num text-secondary"><?= (int) $r['reorder_level'] ?></td><td class="num"><?= (int) $r['sold30'] ?></td><td class="num"><?= (int) $r['on_order'] ? '<span class="badge badge-info">' . (int) $r['on_order'] . '</span>' : '—' ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$reorder) echo empty_state('check-circle-2', 'Nothing needs reordering'); ?></div></div>
  <div class="card flush"><div class="card-head"><h3>Damaged stock</h3><?php if ($damaged && can('purchase.manage')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchase/return_form.php?damaged=1')) ?>">Return to supplier</a><?php endif; ?></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">Units</th><th class="num">Value</th></tr></thead>
      <tbody><?php foreach ($damaged as $d): ?><tr><td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $d['product_id'])) ?>"><?= e($d['name']) ?><?= $d['variant_label'] ? ' — ' . e($d['variant_label']) : '' ?></a></td><td class="num"><?= (int) $d['damaged_qty'] ?></td><td class="num"><?= format_money($d['value']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$damaged) echo empty_state('shield-check', 'No damaged stock'); ?></div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
