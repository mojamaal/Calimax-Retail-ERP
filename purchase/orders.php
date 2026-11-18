<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$status = input($_GET, 'status');
$q = input($_GET, 'q');
$supplierId = input_int($_GET, 'supplier_id');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 40;

$where = 'po.is_direct = 0' . branch_and('po');
$params = [];
$statuses = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Part received', 'received' => 'Received', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];
if (isset($statuses[$status])) { $where .= ' AND po.status = :st'; $params[':st'] = $status; }
if ($q !== '') { $where .= ' AND (po.po_number LIKE :q1 OR s.name LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
if ($supplierId) { $where .= ' AND po.supplier_id = :sid'; $params[':sid'] = $supplierId; }

$from = "FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE $where";
$cnt = $pdo->prepare("SELECT COUNT(*) $from"); $cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT po.*, s.name AS supplier_name,
    (SELECT COALESCE(SUM(quantity),0) FROM purchase_order_items WHERE po_id = po.id) AS ordered_units,
    (SELECT COALESCE(SUM(received_qty),0) FROM purchase_order_items WHERE po_id = po.id) AS received_units
    $from ORDER BY po.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$orders = $stmt->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT status, COUNT(*) c FROM purchase_orders WHERE is_direct = 0' . branch_and() . ' GROUP BY status') as $r) $counts[$r['status']] = (int) $r['c'];
$kpi = $pdo->query("SELECT
    (SELECT COALESCE(SUM(poi.quantity * poi.unit_cost - poi.received_qty * poi.unit_cost),0) FROM purchase_order_items poi JOIN purchase_orders p ON p.id = poi.po_id WHERE p.status IN ('ordered','partially_received')" . branch_and('p') . ") AS on_order,
    (SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_received') AND expected_date < CURDATE()" . branch_and() . ") AS overdue")->fetch();
$monthPurch = fin_purchases(date('Y-m-01'), date('Y-m-d'));
$lowCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . pv() . ' v WHERE is_active = 1 AND stock_qty <= reorder_level')->fetchColumn();
$suppliers = $pdo->query('SELECT id, name FROM suppliers ORDER BY name')->fetchAll();

$pageTitle = 'Purchase Orders';
require __DIR__ . '/../includes/header.php';
$actions = '';
if (can('purchase.receive')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/purchase/grn_form.php')) . '">' . icon('package-plus', 16) . ' Receive without PO</a>';
if (current_branch_id() !== null) $actions .= '<a class="btn btn-primary" href="' . e(app_url('/purchase/order_form.php')) . '">' . icon('plus', 16) . ' New purchase order</a>';
page_header('Purchasing', 'Order from suppliers, receive deliveries into stock and track what you owe.', $actions);
nav_purchase();
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Open orders', (string) (($counts['ordered'] ?? 0) + ($counts['partially_received'] ?? 0)), 'truck', 'blue', format_money($kpi['on_order']) . ' still to arrive', '/purchase/orders.php?status=ordered') ?>
  <?= kpi('Overdue deliveries', (string) (int) $kpi['overdue'], 'clock-alert', (int) $kpi['overdue'] ? 'red' : 'gray', 'Past expected date') ?>
  <?= kpi('Received this month', format_money_short($monthPurch['total']), 'package-check', 'green', (int) $monthPurch['count'] . ' delivery(ies)', '/purchase/receipts.php') ?>
  <?= kpi('Items to reorder', (string) $lowCount, 'alert-triangle', 'amber', 'At or below reorder level', '/purchase/order_form.php?suggest=1') ?>
</div>

<div class="card flush">
  <div style="padding:16px 16px 0;">
    <div class="filter-bar">
      <div class="chip-group">
        <a class="chip <?= $status === '' ? 'active' : '' ?>" href="<?= e(query_with(['status' => null, 'page' => null])) ?>">All</a>
        <?php foreach ($statuses as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="<?= e(query_with(['status' => $k, 'page' => null])) ?>"><?= $l ?> <span class="chip-count"><?= $counts[$k] ?? 0 ?></span></a><?php endforeach; ?>
      </div>
    </div>
    <form method="get" class="filter-bar">
      <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="PO number or supplier"></div>
      <select class="form-control" name="supplier_id" onchange="this.form.submit()"><option value="">All suppliers</option><?php foreach ($suppliers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $supplierId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
    </form>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>PO number</th><?= branch_th() ?><th>Supplier</th><th>Ordered</th><th>Expected</th><th style="width:160px;">Received</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): $pct = $o['ordered_units'] ? round($o['received_units'] / $o['ordered_units'] * 100) : 0; $overdue = in_array($o['status'], ['ordered', 'partially_received'], true) && $o['expected_date'] && $o['expected_date'] < date('Y-m-d'); ?>
      <tr>
        <td class="strong"><a href="<?= e(app_url('/purchase/order_view.php?id=' . $o['id'])) ?>"><?= e($o['po_number']) ?></a></td>
        <?= branch_td((int) $o['branch_id']) ?>
        <td><?= e($o['supplier_name']) ?></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($o['order_date'])) ?></td>
        <td class="nowrap <?= $overdue ? 'text-error fw-700' : 'text-secondary' ?>"><?= e(fmt_date($o['expected_date'])) ?><?= $overdue ? ' · overdue' : '' ?></td>
        <td><div class="text-sm"><?= (int) $o['received_units'] ?> / <?= (int) $o['ordered_units'] ?> units</div><div class="meter mt-1"><span style="width:<?= $pct ?>%"></span></div></td>
        <td class="num strong"><?= format_money($o['total_amount']) ?></td>
        <td><?= status_badge($o['status']) ?></td>
        <td class="actions">
          <?php if (in_array($o['status'], ['ordered', 'partially_received'], true) && can('purchase.receive')): ?><a class="btn btn-primary btn-sm" href="<?= e(app_url('/purchase/grn_form.php?po_id=' . $o['id'])) ?>">Receive</a><?php endif; ?>
          <a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchase/order_view.php?id=' . $o['id'])) ?>">View</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$orders) echo empty_state('file-text', 'No purchase orders', 'Create a PO to formally request goods from a supplier. Stock only increases when goods are received.'); ?></div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
