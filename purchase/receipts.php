<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.receive');

$pdo = db();
$period = resolve_report_period('this_month');
$q = input($_GET, 'q');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 40;
$where = 'g.received_date BETWEEN :f AND :t' . branch_and('g');
$params = [':f' => $period['from'], ':t' => $period['to']];
if ($q !== '') { $where .= ' AND (g.grn_number LIKE :q1 OR po.po_number LIKE :q2 OR s.name LIKE :q3 OR g.supplier_invoice_no LIKE :q4)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => "%$q%"]; }
$from = "FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id JOIN suppliers s ON s.id = po.supplier_id LEFT JOIN users u ON u.id = g.created_by WHERE $where";
$agg = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(g.total_amount),0) t FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id JOIN suppliers s ON s.id = po.supplier_id WHERE $where");
$agg->execute($params);
$agg = $agg->fetch();
$stmt = $pdo->prepare("SELECT g.*, po.po_number, po.is_direct, s.name AS supplier_name, u.full_name AS by_name, (SELECT SUM(quantity) FROM grn_items WHERE grn_id = g.id) AS units $from ORDER BY g.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Goods Received';
require __DIR__ . '/../includes/header.php';
page_header('Purchasing', 'Every delivery received into stock, with the supplier invoice and payment status.', current_branch_id() !== null ? '<a class="btn btn-primary" href="' . e(app_url('/purchase/grn_form.php')) . '">' . icon('package-plus', 16) . ' Receive without PO</a>' : '');
nav_purchase();
?>
<div class="card tight mb-4">
  <?php render_period_filter($period, array_filter(['q' => $q])); ?>
  <form method="get" class="filter-bar mb-0"><input type="hidden" name="preset" value="<?= e($period['preset'] ?: 'custom') ?>"><input type="hidden" name="from" value="<?= e($period['from']) ?>"><input type="hidden" name="to" value="<?= e($period['to']) ?>">
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="GRN, PO, supplier or invoice no."></div>
    <span class="text-secondary text-sm" style="margin-left:auto;"><?= (int) $agg['n'] ?> deliveries · <strong><?= format_money($agg['t']) ?></strong></span></form>
</div>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>GRN</th><?= branch_th() ?><th>Date</th><th>Supplier</th><th>Order</th><th>Supplier invoice</th><th class="num">Units</th><th class="num">Value</th><th>Payment</th><th>Received by</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $g): ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/purchase/grn_view.php?id=' . $g['id'])) ?>"><?= e($g['grn_number']) ?></a></td>
        <?= branch_td((int) $g['branch_id']) ?>
        <td class="text-secondary nowrap"><?= e(fmt_date($g['received_date'])) ?></td>
        <td><?= e($g['supplier_name']) ?></td>
        <td><?= $g['is_direct'] ? '<span class="badge badge-neutral">Direct</span>' : '<a class="link" href="' . e(app_url('/purchase/order_view.php?id=' . $g['po_id'])) . '">' . e($g['po_number']) . '</a>' ?></td>
        <td class="text-secondary"><?= e($g['supplier_invoice_no'] ?? '—') ?></td>
        <td class="num"><?= (int) $g['units'] ?></td>
        <td class="num strong"><?= format_money($g['total_amount']) ?></td>
        <td><?= status_badge($g['payment_mode'] === 'paid' ? 'paid' : 'credit') ?></td>
        <td class="text-secondary text-sm"><?= e($g['by_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows) echo empty_state('package-check', 'No deliveries in this period'); ?></div>
  <?php render_pagination($page, $perPage, (int) $agg['n']); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
