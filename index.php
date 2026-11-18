<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!can('dashboard.view')) redirect(home_path_for(current_user()));

$pdo = db();
$period = resolve_report_period('today');
$p = fin_pnl($period['from'], $period['to']);
$s = $p['sales'];
$avgBasket = (int) $s['count'] ? (float) $s['total'] / (int) $s['count'] : 0;
$methods = fin_payment_breakdown($period['from'], $period['to']);
$methodTotal = array_sum(array_column($methods, 'total')) ?: 1;
$best = fin_best_sellers($period['from'], $period['to'], 6);
$inv = inventory_value();
$cash = cashbook_balance('cash');
$bank = cashbook_balance('bank');

// Trend: the selected range, or the last 14 days when looking at a single day.
$singleDay = $period['from'] === $period['to'];
$trendFrom = $singleDay ? date('Y-m-d', strtotime($period['to'] . ' -13 days')) : $period['from'];
$daily = fin_daily_sales($trendFrom, $period['to']);
$chart = ['title' => 'Daily sales', 'labels' => array_map(fn($d) => date('j M', strtotime($d['date'])), $daily), 'values' => array_map(fn($d) => round($d['total'], 2), $daily),
    'tips' => array_map(fn($d) => date('D, M j', strtotime($d['date'])), $daily), 'sub' => array_map(fn($d) => $d['count'] . ' transaction(s)', $daily)];

// Comparison with the previous equivalent period.
$days = (strtotime($period['to']) - strtotime($period['from'])) / 86400 + 1;
$prevTo = date('Y-m-d', strtotime($period['from'] . ' -1 day'));
$prevSales = fin_sales(date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days')), $prevTo);
$trend = (float) $prevSales['total'] > 0 ? ((float) $s['total'] - (float) $prevSales['total']) / (float) $prevSales['total'] * 100 : null;

$lowStock = $pdo->query('SELECT p.id AS product_id, p.name, pv.variant_label, pv.stock_qty, pv.reorder_level FROM ' . pv() . ' pv JOIN products p ON p.id = pv.product_id
    WHERE pv.is_active = 1 AND p.is_active = 1 AND pv.stock_qty <= pv.reorder_level ORDER BY pv.stock_qty ASC, p.name LIMIT 7')->fetchAll();
$recent = $pdo->query("SELECT s.id, s.invoice_number, s.total_amount, s.status, s.sale_date, COALESCE(c.name,'Walk-in') AS customer, u.full_name AS cashier
    FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id WHERE s.status NOT IN ('held','cancelled')" . branch_and('s') . " ORDER BY s.sale_date DESC LIMIT 7")->fetchAll();
$pending = [
    ['Customer returns', (int) $pdo->query("SELECT COUNT(*) FROM sale_returns WHERE status = 'pending'" . branch_and())->fetchColumn(), '/pos/returns.php?status=pending', 'undo-2'],
    ['Stock adjustments', (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending'" . branch_and())->fetchColumn(), '/inventory/adjustments.php?status=pending', 'clipboard-check'],
    ['Stock counts', (int) $pdo->query("SELECT COUNT(*) FROM stock_counts WHERE status = 'submitted'" . branch_and())->fetchColumn(), '/inventory/counts.php?status=submitted', 'list-checks'],
    ['Deliveries due', (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_received') AND expected_date <= CURDATE()" . branch_and())->fetchColumn(), '/purchase/orders.php?status=ordered', 'truck'],
];
if (can('transfers.view') && count(branches_all()) > 1) {
    $pending[] = ['Transfers on the way', (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit'" . (current_branch_id() !== null ? ' AND to_branch_id = ' . current_branch_id() : ''))->fetchColumn(), '/inventory/transfers.php?status=in_transit', 'truck'];
}
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
page_header($greeting . ', ' . explode(' ', current_user()['full_name'])[0], 'Here’s how ' . e(setting('store_name', APP_NAME)) . (count(branches_all()) > 1 ? ' · <strong>' . e(branch_scope_label()) . '</strong>' : '') . ' is doing — <strong>' . e($period['label']) . '</strong>' . ($singleDay ? '' : ' (' . e(fmt_date($period['from'])) . ' – ' . e(fmt_date($period['to'])) . ')'),
    '<a class="btn btn-secondary" href="' . e(app_url('/reports/index.php' . query_with([]))) . '">' . icon('scale', 16) . ' Full P&amp;L</a>' . (can('pos.access') && current_branch_id() !== null ? '<a class="btn btn-primary" href="' . e(app_url('/pos/index.php')) . '">' . icon('scan-barcode', 16) . ' Open POS</a>' : ''));
?>
<div class="card tight mb-4"><?php render_period_filter($period); ?></div>

<div class="grid grid-5 mb-4">
  <div class="kpi-card">
    <div class="kpi-top"><span class="kpi-label">Total sales</span><span class="icon-tile sm"><?= icon('banknote', 16) ?></span></div>
    <div class="kpi-value"><?= format_money_short($s['total']) ?></div>
    <div class="kpi-desc"><?= $trend === null ? 'No sales in the previous period' : '<span class="kpi-trend ' . ($trend < 0 ? 'negative' : '') . '">' . ($trend >= 0 ? '▲' : '▼') . ' ' . number_format(abs($trend), 1) . '%</span> vs previous period' ?></div>
  </div>
  <?= kpi('Transactions', number_format((int) $s['count']), 'receipt', 'blue', 'Avg basket ' . format_money($avgBasket), '/pos/history.php' . query_with([])) ?>
  <?= kpi('Gross profit', format_money_short($p['gross_profit']), 'percent', 'green', number_format($p['gross_margin'], 1) . '% margin after cost of goods') ?>
  <?= kpi('Expenses', format_money_short($p['expenses']), 'receipt', 'amber', 'Rent, bills, salaries…', '/finance/expenses.php' . query_with([])) ?>
  <div class="kpi-card"><div class="kpi-top"><span class="kpi-label">Net profit</span><span class="icon-tile sm <?= $p['net_profit'] >= 0 ? 'green' : 'red' ?>"><?= icon('scale', 16) ?></span></div>
    <div class="kpi-value <?= $p['net_profit'] >= 0 ? 'tone-pos' : 'tone-neg' ?>"><?= format_money_short($p['net_profit']) ?></div><div class="kpi-desc">After COGS &amp; expenses</div></div>
</div>

<div class="layout-2-1 mb-4">
  <div class="card">
    <div class="card-head"><div><h3>Sales trend</h3><div class="sub"><?= $singleDay ? 'Last 14 days' : e($period['label']) ?> · including tax</div></div><a class="btn-ghost btn-sm" href="<?= e(app_url('/reports/sales.php' . query_with([]))) ?>">Sales report →</a></div>
    <div class="chart-wrap" data-chart='<?= e(json_encode($chart)) ?>'></div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Sales by payment method</h3></div>
    <?php foreach ($methods as $m): ?>
      <div class="hbar-row"><span><?= e(method_label($m['method'])) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($m['total'] / $methodTotal * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($m['total']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$methods) echo empty_state('credit-card', 'No sales yet', 'Payments will appear here as sales are made.'); ?>
    <div class="dropdown-sep" style="margin:14px 0;"></div>
    <dl class="desc-list">
      <dt>Discounts given</dt><dd><?= format_money($p['discounts']) ?></dd>
      <dt>Customer returns</dt><dd><?= format_money($p['returns']['total']) ?></dd>
      <dt>Tax collected</dt><dd><?= format_money($p['tax_collected']) ?></dd>
    </dl>
  </div>
</div>

<?php if (current_branch_id() === null && count(accessible_branches()) > 1):
    $__perf = branch_performance($period['from'], $period['to']); $__max = max(1, ...array_map(fn($r) => (float) $r['pnl']['sales']['total'], $__perf)); ?>
<div class="card mb-4">
  <div class="card-head"><div><h3>Branch performance</h3><div class="sub"><?= e($period['label']) ?> · sales incl. tax</div></div><a class="btn-ghost btn-sm" href="<?= e(app_url('/reports/branches.php' . query_with([]))) ?>">Compare branches →</a></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Branch</th><th class="num">Sales</th><th style="width:22%"></th><th class="num">Gross profit</th><th class="num">Expenses</th><th class="num">Net profit</th><th class="num">Cash + bank</th></tr></thead>
    <tbody>
    <?php foreach ($__perf as $__r): $__pp = $__r['pnl']; ?>
      <tr><td class="strong"><?= e($__r['branch']['name']) ?></td><td class="num"><?= format_money_short($__pp['sales']['total']) ?></td>
        <td><div class="meter"><span style="width:<?= max(2, round((float) $__pp['sales']['total'] / $__max * 100)) ?>%"></span></div></td>
        <td class="num"><?= format_money_short($__pp['gross_profit']) ?></td><td class="num"><?= format_money_short($__pp['expenses']) ?></td>
        <td class="num strong <?= $__pp['net_profit'] >= 0 ? 'text-success' : 'text-error' ?>"><?= format_money_short($__pp['net_profit']) ?></td>
        <td class="num"><?= format_money_short($__r['cash'] + $__r['bank']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<div class="grid grid-5 mb-4">
  <?= kpi('Inventory value', format_money_short($inv['cost_value']), 'warehouse', 'purple', 'At cost · ' . number_format((int) $inv['units']) . ' units', '/reports/inventory.php') ?>
  <?= kpi('Low / out of stock', (int) $inv['low_stock'] . ' / ' . (int) $inv['out_of_stock'], 'alert-triangle', (int) $inv['out_of_stock'] ? 'red' : 'amber', 'Need reordering', '/inventory/products.php?stock=low') ?>
  <?= kpi('We owe suppliers', format_money_short(payables_total()), 'file-clock', 'red', 'Outstanding payables', '/finance/payables.php') ?>
  <?= kpi('Customers owe us', format_money_short(receivables_total()), 'hand-coins', 'amber', 'Credit balances', '/finance/receivables.php') ?>
  <?= kpi('Cash + bank', format_money_short($cash + $bank), 'landmark', 'green', 'Cash ' . format_money_short($cash) . ' · Bank ' . format_money_short($bank), '/finance/cashbook.php') ?>
</div>

<div class="grid grid-3">
  <div class="card">
    <div class="card-head"><h3>Best sellers</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/reports/products.php' . query_with([]))) ?>">All →</a></div>
    <div class="list-rows">
      <?php foreach ($best as $i => $b): ?>
        <a href="<?= e(app_url('/inventory/product_view.php?id=' . $b['product_id'])) ?>"><span class="flex items-center gap-2" style="min-width:0;"><span class="rank"><?= $i + 1 ?></span><span style="min-width:0;"><span class="strong"><?= e($b['name']) ?></span><?= $b['variant_label'] ? ' <span class="text-muted">' . e($b['variant_label']) . '</span>' : '' ?><div class="cell-sub"><?= format_money($b['revenue']) ?> · <?= (int) $b['stock_qty'] ?> left</div></span></span><span class="badge badge-orange"><?= (int) $b['qty'] ?> sold</span></a>
      <?php endforeach; ?>
      <?php if (!$best) echo '<div class="text-muted text-sm">No products sold in this period.</div>'; ?>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h3>Restock alerts</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/purchase/order_form.php?suggest=1')) ?>">Reorder →</a></div>
    <div class="list-rows">
      <?php foreach ($lowStock as $l): ?>
        <a href="<?= e(app_url('/inventory/product_view.php?id=' . $l['product_id'])) ?>"><span><span class="strong"><?= e($l['name']) ?></span><?= $l['variant_label'] ? ' <span class="text-muted">· ' . e($l['variant_label']) . '</span>' : '' ?><div class="cell-sub">Reorder at <?= (int) $l['reorder_level'] ?></div></span><span class="badge badge-<?= $l['stock_qty'] <= 0 ? 'error' : 'warning' ?>"><?= $l['stock_qty'] <= 0 ? 'Out' : (int) $l['stock_qty'] . ' left' ?></span></a>
      <?php endforeach; ?>
      <?php if (!$lowStock) echo '<div class="text-muted text-sm">All stock levels are healthy.</div>'; ?>
    </div>
  </div>
  <div class="grid">
    <div class="card">
      <div class="card-head"><h3>Needs attention</h3></div>
      <div class="list-rows">
        <?php foreach ($pending as [$label, $n, $href, $ic]): ?>
          <a href="<?= e(app_url($href)) ?>"><span class="flex items-center gap-2"><?= icon($ic, 15) ?> <?= e($label) ?></span><span class="badge badge-<?= $n ? 'warning' : 'neutral' ?>"><?= $n ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Latest sales</h3><a class="btn-ghost btn-sm" href="<?= e(app_url('/pos/history.php')) ?>">All →</a></div>
      <div class="list-rows">
        <?php foreach (array_slice($recent, 0, 5) as $r): ?>
          <a href="<?= e(app_url('/pos/sale_view.php?id=' . $r['id'])) ?>"><span><span class="strong"><?= e($r['invoice_number']) ?></span> <span class="text-muted text-sm"><?= e($r['customer']) ?></span><div class="cell-sub"><?= e(fmt_datetime($r['sale_date'])) ?> · <?= e($r['cashier']) ?></div></span><span class="text-right"><strong><?= format_money($r['total_amount']) ?></strong><?= $r['status'] !== 'completed' ? '<div>' . status_badge($r['status']) . '</div>' : '' ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
