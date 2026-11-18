<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.view');

$pdo = db();
$search = input($_GET, 'q');
$categoryId = input_int($_GET, 'category_id');
$stock = input($_GET, 'stock');
if (input($_GET, 'filter') === 'low_stock') $stock = 'low';
$showInactive = input($_GET, 'show') === 'inactive';
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 50;
$seeCost = can('products.view_cost');

$where = $showInactive ? 'p.is_active = 0' : 'p.is_active = 1';
$params = [];
if ($search !== '') {
    $where .= ' AND (p.name LIKE :q1 OR p.brand LIKE :q2 OR EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND (x.sku LIKE :q3 OR x.barcode = :q4)))';
    $params += [':q1' => "%$search%", ':q2' => "%$search%", ':q3' => "%$search%", ':q4' => $search];
}
if ($categoryId) { $where .= ' AND (p.category_id = :cat OR c.parent_id = :cat2)'; $params[':cat'] = $categoryId; $params[':cat2'] = $categoryId; }

$base = "SELECT p.id, p.name, p.brand, p.unit, p.image, p.is_active, c.name AS category_name,
               COUNT(pv.id) AS variant_count, MIN(pv.sku) AS first_sku, MIN(pv.barcode) AS first_barcode,
               COALESCE(SUM(pv.stock_qty), 0) AS total_stock, COALESCE(SUM(pv.damaged_qty), 0) AS damaged,
               COALESCE(SUM(pv.reorder_level), 0) AS reorder_total,
               COALESCE(MIN(pv.price), 0) AS min_price, COALESCE(MAX(pv.price), 0) AS max_price,
               COALESCE(SUM(GREATEST(pv.stock_qty,0) * pv.cost_price), 0) AS stock_value,
               SUM(CASE WHEN pv.stock_qty <= 0 THEN 1 ELSE 0 END) AS out_variants,
               SUM(CASE WHEN pv.stock_qty > 0 AND pv.stock_qty <= pv.reorder_level THEN 1 ELSE 0 END) AS low_variants
        FROM products p
        LEFT JOIN " . pv() . " pv ON pv.product_id = p.id AND pv.is_active = 1
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $where GROUP BY p.id";   // pv() = this branch's stock, price & reorder level
$having = ['low' => 'low_variants > 0', 'out' => 'out_variants > 0', 'damaged' => 'damaged > 0', 'in' => 'total_stock > 0'][$stock] ?? null;
$sql = "SELECT * FROM ($base) t" . ($having ? " WHERE $having" : '');

// CSV export of the current view
if (input($_GET, 'export') === 'csv') {
    $stmt = $pdo->prepare($sql . ' ORDER BY name');
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['Product', 'Brand', 'Category', 'SKU', 'Barcode', 'Variants', 'Stock', 'Damaged', 'Min price', 'Max price'], $seeCost ? ['Stock value (cost)'] : []));
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, array_merge([$r['name'], $r['brand'], $r['category_name'], $r['first_sku'], $r['first_barcode'], $r['variant_count'], $r['total_stock'], $r['damaged'], $r['min_price'], $r['max_price']], $seeCost ? [$r['stock_value']] : []));
    }
    exit;
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) z");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare($sql . ' ORDER BY ' . ($stock === 'low' || $stock === 'out' ? 'total_stock ASC, name' : 'name') . " LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories = $pdo->query('SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$inv = inventory_value();
$activeProducts = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();

$pageTitle = 'Products';
require __DIR__ . '/../includes/header.php';
$actions = '<a class="btn btn-secondary" href="' . e(query_with(['export' => 'csv', 'page' => null])) . '">' . icon('download', 16) . ' Export CSV</a>';
if (can('reports.view')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/inventory/stock_report_print.php')) . '" target="_blank">' . icon('printer', 16) . ' Stock report</a>';
if (can('products.manage')) $actions .= '<a class="btn btn-primary" href="' . e(app_url('/inventory/product_form.php')) . '">' . icon('plus', 16) . ' New product</a>';
page_header('Products', 'One shared catalogue — stock, prices and reorder levels shown for ' . e(branch_scope_label()) . '.', $actions);
nav_products();
?>
<div class="grid grid-5 mb-4">
  <?= kpi('Active products', number_format($activeProducts), 'package', '', number_format((int) $inv['units']) . ' units on hand') ?>
  <?= $seeCost ? kpi('Inventory value', format_money_short($inv['cost_value']), 'wallet', 'green', 'At cost · retail ' . format_money_short($inv['retail_value'])) : kpi('Retail value', format_money_short($inv['retail_value']), 'wallet', 'green') ?>
  <?= kpi('Low stock', number_format((int) $inv['low_stock']), 'alert-triangle', 'amber', 'At or below reorder level', '/inventory/products.php?stock=low') ?>
  <?= kpi('Out of stock', number_format((int) $inv['out_of_stock']), 'package-x', 'red', 'Cannot be sold', '/inventory/products.php?stock=out') ?>
  <?= kpi('Damaged stock', number_format((int) $inv['damaged_units']), 'shield-alert', 'purple', $seeCost ? format_money($inv['damaged_value']) . ' at cost' : 'Held out of sale', '/inventory/products.php?stock=damaged') ?>
</div>

<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" placeholder="Name, brand, SKU or scan barcode" value="<?= e($search) ?>"></div>
      <select class="form-control" name="category_id" onchange="this.form.submit()">
        <option value="">All categories</option>
        <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= $c['parent_id'] ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <?php if ($stock): ?><input type="hidden" name="stock" value="<?= e($stock) ?>"><?php endif; ?>
      <div class="chip-group">
        <?php foreach (['' => 'All stock', 'in' => 'In stock', 'low' => 'Low', 'out' => 'Out of stock', 'damaged' => 'Damaged'] as $k => $l): ?>
          <a class="chip <?= $stock === $k ? 'active' : '' ?>" href="<?= e(query_with(['stock' => $k ?: null, 'filter' => null, 'page' => null])) ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
        <a class="chip <?= $showInactive ? 'active' : '' ?>" href="<?= e(query_with(['show' => $showInactive ? null : 'inactive', 'page' => null])) ?>">Inactive</a>
      </div>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Product</th><th>Code / barcode</th><th>Category</th><th class="num">Price</th><th style="width:170px;">Stock</th><?php if ($seeCost): ?><th class="num">Value (cost)</th><?php endif; ?><th></th></tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $tot = (int) $p['total_stock']; $re = max(1, (int) $p['reorder_total']);
          $cls = $tot <= 0 ? 'bad' : ((int) $p['low_variants'] > 0 || $tot <= $re ? 'warn' : '');
          $pct = min(100, $tot / ($re * 3) * 100); ?>
        <tr>
          <td><div class="cell-product">
            <div class="thumb"><?php if ($p['image']): ?><img src="<?= e(UPLOAD_PRODUCT_URL . $p['image']) ?>" alt="" loading="lazy"><?php else: ?><?= e(strtoupper(substr($p['name'], 0, 2))) ?><?php endif; ?></div>
            <div><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $p['id'])) ?>"><?= e($p['name']) ?></a>
              <div class="cell-sub"><?= e($p['brand'] ?: '—') ?><?= (int) $p['variant_count'] > 1 ? ' · ' . (int) $p['variant_count'] . ' variants' : '' ?></div></div>
          </div></td>
          <td><span class="mono"><?= e($p['first_sku'] ?? '—') ?></span><div class="cell-sub mono"><?= (int) $p['variant_count'] > 1 ? 'multiple' : e($p['first_barcode'] ?? '') ?></div></td>
          <td class="text-secondary"><?= e($p['category_name'] ?? 'Uncategorized') ?></td>
          <td class="num"><?= $p['min_price'] == $p['max_price'] ? format_money($p['min_price']) : format_money($p['min_price']) . '<div class="cell-sub">to ' . format_money($p['max_price']) . '</div>' ?></td>
          <td>
            <div class="flex items-center gap-2"><span class="strong" style="min-width:44px;"><?= number_format($tot) ?></span><span class="text-muted text-sm"><?= e($p['unit']) ?></span>
              <?php if ((int) $p['out_variants'] > 0): ?><span class="badge badge-error"><?= (int) $p['variant_count'] > 1 ? (int) $p['out_variants'] . ' out' : 'Out' ?></span>
              <?php elseif ((int) $p['low_variants'] > 0): ?><span class="badge badge-warning">Low</span><?php endif; ?>
              <?php if ((int) $p['damaged'] > 0): ?><span class="badge badge-neutral" title="Damaged units held out of sale"><?= (int) $p['damaged'] ?> dmg</span><?php endif; ?>
            </div>
            <div class="meter mt-1 <?= $cls ?>"><span style="width:<?= max(3, $pct) ?>%"></span></div>
          </td>
          <?php if ($seeCost): ?><td class="num"><?= format_money($p['stock_value']) ?></td><?php endif; ?>
          <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/inventory/product_view.php?id=' . $p['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$products) echo empty_state('package', 'No products found', $search ? 'Nothing matches “' . $search . '”.' : 'Add your first product to start selling.', can('products.manage') ? '<a class="btn btn-primary" href="' . e(app_url('/inventory/product_form.php')) . '">' . icon('plus', 16) . ' New product</a>' : ''); ?>
  </div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
