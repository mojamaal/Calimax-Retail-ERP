<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('inventory.view');

$pdo = db();
$period = resolve_report_period('this_month');
$type = input($_GET, 'type');
$q = input($_GET, 'q');
$productId = input_int($_GET, 'product_id');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 60;

$where = 'sm.created_at >= :f AND sm.created_at < :t';
$params = period_bounds($period['from'], $period['to']);
if ($productId) { $where = 'pv.product_id = :pid'; $params = [':pid' => $productId]; }
if (isset(movement_types()[$type])) { $where .= ' AND sm.type = :type'; $params[':type'] = $type; }
if ($q !== '') { $where .= ' AND (p.name LIKE :q1 OR pv.sku LIKE :q2 OR pv.barcode = :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => $q]; }

$from = "FROM stock_movements sm JOIN product_variants pv ON pv.id = sm.variant_id JOIN products p ON p.id = pv.product_id LEFT JOIN users u ON u.id = sm.created_by WHERE $where" . branch_and('sm');
$agg = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN sm.change_qty > 0 AND sm.bucket = 'saleable' THEN sm.change_qty END),0) AS units_in, COALESCE(SUM(CASE WHEN sm.change_qty < 0 AND sm.bucket = 'saleable' THEN -sm.change_qty END),0) AS units_out $from");
$agg->execute($params);
$agg = $agg->fetch();
$stmt = $pdo->prepare("SELECT sm.*, pv.sku, pv.variant_label, p.name AS product_name, p.id AS product_id, u.full_name AS by_name $from ORDER BY sm.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();
$mt = movement_types();
$refLinks = ['sale' => '/pos/sale_view.php?id=', 'grn' => '/purchase/grn_view.php?id=', 'purchase_return' => '/purchase/return_view.php?id=', 'sale_return' => '/pos/return_view.php?id=', 'adjustment' => '/inventory/adjustment_view.php?id=', 'product' => '/inventory/product_view.php?id=', 'stock_transfer' => '/inventory/transfer_view.php?id='];

$pageTitle = 'Stock Movement History';
require __DIR__ . '/../includes/header.php';
page_header('Stock Control', 'Every unit that came in or went out, with who did it and the running balance.');
if (can_any(['inventory.adjust', 'inventory.count'])) nav_stock();
?>
<div class="grid grid-3 mb-4">
  <?= kpi('Movements', number_format((int) $agg['n']), 'history', 'blue', $productId ? 'For this product' : e($period['label'])) ?>
  <?= kpi('Units in', '+' . number_format((int) $agg['units_in']), 'arrow-down-to-line', 'green', 'Received, returned, found') ?>
  <?= kpi('Units out', '−' . number_format((int) $agg['units_out']), 'arrow-up-from-line', 'red', 'Sold, damaged, written off') ?>
</div>
<div class="card tight mb-4">
  <?php if (!$productId) render_period_filter($period, array_filter(['type' => $type, 'q' => $q])); ?>
  <form method="get" class="filter-bar mb-0">
    <?php if ($productId): ?><input type="hidden" name="product_id" value="<?= $productId ?>"><?php else: ?><input type="hidden" name="preset" value="<?= e($period['preset'] ?: 'custom') ?>"><input type="hidden" name="from" value="<?= e($period['from']) ?>"><input type="hidden" name="to" value="<?= e($period['to']) ?>"><?php endif; ?>
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Product, SKU or barcode"></div>
    <select class="form-control" name="type" onchange="this.form.submit()"><option value="">All movement types</option><?php foreach ($mt as $k => [$l]): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <button class="btn btn-secondary" type="submit">Filter</button>
    <?php if ($productId): ?><a class="btn-ghost" href="<?= e(app_url('/inventory/movements.php')) ?>">All products</a><?php endif; ?>
  </form>
</div>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Date</th><?= branch_th() ?><th>Product</th><th>Movement</th><th>Reference</th><th class="num">Change</th><th class="num">Balance</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): [$ml, $tone] = $mt[$m['type']] ?? [ucfirst($m['type']), 'neutral']; $link = isset($refLinks[$m['reference_type']]) && $m['reference_id'] ? app_url($refLinks[$m['reference_type']] . $m['reference_id']) : null; ?>
      <tr>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($m['created_at'])) ?></td>
        <?= branch_td((int) $m['branch_id']) ?>
        <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $m['product_id'])) ?>"><?= e($m['product_name']) ?><?= $m['variant_label'] ? ' — ' . e($m['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($m['sku']) ?></div></td>
        <td><span class="badge badge-<?= $tone ?>"><?= e($ml) ?></span><?= $m['bucket'] === 'damaged' ? ' <span class="badge badge-neutral">damaged stock</span>' : '' ?></td>
        <td class="text-sm"><?= $link ? '<a class="link" href="' . e($link) . '">' . e($m['note'] ?: 'Open') . '</a>' : e($m['note'] ?? '') ?></td>
        <td class="num strong <?= $m['change_qty'] > 0 ? 'text-success' : 'text-error' ?>"><?= $m['change_qty'] > 0 ? '+' : '' ?><?= (int) $m['change_qty'] ?></td>
        <td class="num"><?= $m['balance_after'] !== null ? (int) $m['balance_after'] : '—' ?></td>
        <td class="text-secondary text-sm"><?= e($m['by_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('history', 'No movements', 'Nothing moved in this period.'); ?></div>
  <?php render_pagination($page, $perPage, (int) $agg['n']); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
