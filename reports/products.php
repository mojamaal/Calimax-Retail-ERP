<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');

$pdo = db();
$period = resolve_report_period('last_30');
$sort = input($_GET, 'sort') === 'profit' ? 'profit' : (input($_GET, 'sort') === 'revenue' ? 'revenue' : 'qty');
$b = period_bounds($period['from'], $period['to']);
$days = max(1, (strtotime($period['to']) - strtotime($period['from'])) / 86400 + 1);

$best = $pdo->prepare('SELECT pv.id, p.id AS product_id, p.name, pv.variant_label, pv.sku, pv.stock_qty, SUM(si.quantity) AS qty, SUM(si.line_total - si.tax_amount) AS revenue, SUM(si.line_total - si.tax_amount - si.quantity * si.unit_cost) AS profit
    FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN ' . pv() . ' pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id
    WHERE s.status IN ' . SALE_COUNTED . " AND s.sale_date >= :f AND s.sale_date < :t" . branch_and('s') . " GROUP BY pv.id ORDER BY $sort DESC LIMIT 25");
$best->execute($b);
$best = $best->fetchAll();

// Slow / non-moving: in stock, sold little or nothing in the period.
$slow = $pdo->prepare('SELECT pv.id, p.id AS product_id, p.name, pv.variant_label, pv.sku, pv.stock_qty, pv.cost_price, (pv.stock_qty * pv.cost_price) AS tied_up,
        COALESCE(x.qty, 0) AS qty, (SELECT MAX(s2.sale_date) FROM sale_items si2 JOIN sales s2 ON s2.id = si2.sale_id WHERE si2.variant_id = pv.id AND s2.status IN ' . SALE_COUNTED . branch_and('s2') . ') AS last_sold
    FROM ' . pv() . ' pv JOIN products p ON p.id = pv.product_id
    LEFT JOIN (SELECT si.variant_id, SUM(si.quantity) qty FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY si.variant_id) x ON x.variant_id = pv.id
    WHERE pv.is_active = 1 AND p.is_active = 1 AND pv.stock_qty > 0 AND COALESCE(x.qty,0) <= GREATEST(1, pv.stock_qty * 0.05)
    ORDER BY tied_up DESC LIMIT 25');
$slow->execute($b);
$slow = $slow->fetchAll();
$tiedUp = array_sum(array_column($slow, 'tied_up'));

$catPerf = $pdo->prepare("SELECT COALESCE(c.name,'Uncategorized') AS name, COUNT(DISTINCT pv.id) AS items, SUM(si.quantity) AS qty, SUM(si.line_total - si.tax_amount) AS revenue, SUM(si.line_total - si.tax_amount - si.quantity * si.unit_cost) AS profit
    FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id LEFT JOIN categories c ON c.id = p.category_id
    WHERE s.status IN " . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . ' GROUP BY c.id ORDER BY revenue DESC');
$catPerf->execute($b);
$catPerf = $catPerf->fetchAll();

$pageTitle = 'Product Performance';
require __DIR__ . '/../includes/header.php';
page_header('Reports', 'Which products sell fast, which make the most money, and which are sitting on the shelf.');
nav_reports();
?>
<div class="card tight mb-4"><?php render_period_filter($period, ['sort' => $sort]); ?></div>
<div class="grid grid-3 mb-4">
  <?= kpi('Products sold', number_format(count($best)) . (count($best) >= 25 ? '+' : ''), 'package', '', 'Distinct items with sales') ?>
  <?= kpi('Slow / non-moving items', (string) count($slow), 'snail', 'amber', 'In stock but barely selling') ?>
  <?= kpi('Capital tied up', format_money($tiedUp), 'wallet', 'red', 'Cost of slow-moving stock') ?>
</div>
<div class="card flush mb-4">
  <div class="card-head"><div><h3>Best sellers</h3><div class="sub"><?= e($period['label']) ?></div></div>
    <div class="chip-group"><?php foreach (['qty' => 'By units', 'revenue' => 'By sales', 'profit' => 'By profit'] as $k => $l): ?><a class="chip <?= $sort === $k ? 'active' : '' ?>" href="<?= e(query_with(['sort' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th style="width:40px;">#</th><th>Product</th><th class="num">Units sold</th><th class="num">Per day</th><th class="num">Net sales</th><th class="num">Gross profit</th><th class="num">Margin</th><th class="num">In stock</th><th class="num">Days left</th></tr></thead>
    <tbody>
    <?php foreach ($best as $i => $r): $perDay = $r['qty'] / $days; $left = $perDay > 0 ? $r['stock_qty'] / $perDay : null; ?>
      <tr>
        <td><span class="rank"><?= $i + 1 ?></span></td>
        <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $r['product_id'])) ?>"><?= e($r['name']) ?><?= $r['variant_label'] ? ' — ' . e($r['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($r['sku']) ?></div></td>
        <td class="num strong"><?= number_format((int) $r['qty']) ?></td>
        <td class="num text-secondary"><?= number_format($perDay, 1) ?></td>
        <td class="num"><?= format_money($r['revenue']) ?></td>
        <td class="num"><?= format_money($r['profit']) ?></td>
        <td class="num text-secondary"><?= $r['revenue'] > 0 ? number_format($r['profit'] / $r['revenue'] * 100, 1) : '0.0' ?>%</td>
        <td class="num"><?= (int) $r['stock_qty'] ?></td>
        <td class="num <?= $left !== null && $left < 7 ? 'text-error fw-700' : '' ?>"><?= $left === null ? '—' : number_format($left, 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table><?php if (!$best) echo empty_state('trending-up', 'No sales in this period'); ?></div>
</div>
<div class="grid grid-2">
  <div class="card flush"><div class="card-head"><div><h3>Slow &amp; non-moving stock</h3><div class="sub">Consider a promotion, a return to supplier, or not reordering.</div></div></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Product</th><th class="num">In stock</th><th class="num">Sold</th><th>Last sold</th><th class="num">Tied up</th></tr></thead>
      <tbody><?php foreach ($slow as $r): ?><tr><td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $r['product_id'])) ?>"><?= e($r['name']) ?><?= $r['variant_label'] ? ' — ' . e($r['variant_label']) : '' ?></a></td><td class="num"><?= (int) $r['stock_qty'] ?></td><td class="num"><?= (int) $r['qty'] ?></td><td class="text-secondary"><?= $r['last_sold'] ? e(fmt_date($r['last_sold'])) : '<span class="badge badge-error">Never</span>' ?></td><td class="num strong"><?= format_money($r['tied_up']) ?></td></tr><?php endforeach; ?></tbody>
    </table><?php if (!$slow) echo empty_state('check-circle-2', 'No slow movers', 'Everything in stock is selling.'); ?></div></div>
  <div class="card flush"><div class="card-head"><h3>Category performance</h3></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Category</th><th class="num">Units</th><th class="num">Net sales</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
      <tbody><?php foreach ($catPerf as $c): ?><tr><td class="strong"><?= e($c['name']) ?></td><td class="num"><?= number_format((int) $c['qty']) ?></td><td class="num"><?= format_money($c['revenue']) ?></td><td class="num"><?= format_money($c['profit']) ?></td><td class="num text-secondary"><?= $c['revenue'] > 0 ? number_format($c['profit'] / $c['revenue'] * 100, 1) : '0.0' ?>%</td></tr><?php endforeach; ?></tbody>
    </table></div></div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
