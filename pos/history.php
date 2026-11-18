<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');

$pdo = db();
$user = current_user();
$viewAll = can('sales.view_all');
$period = resolve_report_period('today');
$q = input($_GET, 'q');
$status = input($_GET, 'status');
$method = input($_GET, 'method');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 50;

$where = "s.status NOT IN ('held','cancelled') AND s.sale_date >= :f AND s.sale_date < :t" . branch_and('s');
$params = period_bounds($period['from'], $period['to']);
if (!$viewAll) { $where .= ' AND s.cashier_id = :u'; $params[':u'] = $user['id']; }
if ($q !== '') { $where .= ' AND (s.invoice_number LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
if (in_array($status, ['completed', 'voided', 'partially_refunded', 'refunded'], true)) { $where .= ' AND s.status = :st'; $params[':st'] = $status; }
if (isset(payment_methods()[$method])) { $where .= ' AND EXISTS (SELECT 1 FROM sale_payments sp WHERE sp.sale_id = s.id AND sp.method = :m)'; $params[':m'] = $method; }

$from = "FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id WHERE $where";
$countStmt = $pdo->prepare("SELECT COUNT(*) AS n,
    COALESCE(SUM(CASE WHEN s.status IN " . SALE_COUNTED . " THEN s.total_amount END),0) AS total,
    SUM(CASE WHEN s.status IN " . SALE_COUNTED . " THEN 1 ELSE 0 END) AS counted $from");
$countStmt->execute($params);
$agg = $countStmt->fetch();

$stmt = $pdo->prepare("SELECT s.*, u.full_name AS cashier_name, COALESCE(c.name,'Walk-in') AS customer_name,
        (SELECT GROUP_CONCAT(DISTINCT sp.method) FROM sale_payments sp WHERE sp.sale_id = s.id) AS methods,
        (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id = s.id) AS units
    $from ORDER BY s.sale_date DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
$stmt->execute($params);
$sales = $stmt->fetchAll();

$avgBasket = (int) $agg['counted'] > 0 ? (float) $agg['total'] / (int) $agg['counted'] : 0;
$voidCount = $pdo->prepare("SELECT COUNT(*) FROM sales s WHERE s.status = 'voided' AND s.sale_date >= :f AND s.sale_date < :t" . branch_and('s') . ($viewAll ? '' : ' AND s.cashier_id = :u'));
$vp = period_bounds($period['from'], $period['to']); if (!$viewAll) $vp[':u'] = $user['id'];
$voidCount->execute($vp);

$pageTitle = 'Sales History';
require __DIR__ . '/../includes/header.php';
$actions = '';
if (can('reports.view')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/pos/sales_report_print.php' . query_with(['page' => null]))) . '" target="_blank">' . icon('printer', 16) . ' Print report</a>';
$actions .= '<a class="btn btn-primary" href="' . e(app_url('/pos/index.php')) . '">' . icon('scan-barcode', 16) . ' New sale</a>';
page_header('Sales History', ($viewAll ? 'All sales' : 'Your sales') . ' · ' . e($period['label']), $actions);
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Sales value', format_money($agg['total']), 'banknote', '', (int) $agg['counted'] . ' transaction(s)') ?>
  <?= kpi('Average basket', format_money($avgBasket), 'shopping-basket', 'blue') ?>
  <?= kpi('Cancelled sales', (string) (int) $voidCount->fetchColumn(), 'ban', 'red', 'In this period', '/pos/history.php' . query_with(['status' => 'voided', 'page' => null])) ?>
  <?= kpi('Returns', '<span style="font-size:22px">View</span>', 'undo-2', 'amber', 'Refunds & exchanges', '/pos/returns.php') ?>
</div>

<div class="card tight mb-4">
  <?php render_period_filter($period, array_filter(['q' => $q, 'status' => $status, 'method' => $method])); ?>
  <form method="get" class="filter-bar mb-0">
    <input type="hidden" name="preset" value="<?= e($period['preset'] ?: 'custom') ?>"><input type="hidden" name="from" value="<?= e($period['from']) ?>"><input type="hidden" name="to" value="<?= e($period['to']) ?>">
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice no., customer name or phone"></div>
    <select class="form-control" name="status" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['completed' => 'Completed', 'partially_refunded' => 'Part refunded', 'refunded' => 'Refunded', 'voided' => 'Cancelled'] as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <select class="form-control" name="method" onchange="this.form.submit()">
      <option value="">Any payment method</option>
      <?php foreach (payment_methods() as $k => $m): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($q || $status || $method): ?><a class="btn-ghost" href="<?= e(query_with(['q' => null, 'status' => null, 'method' => null, 'page' => null])) ?>">Clear filters</a><?php endif; ?>
  </form>
</div>

<div class="card flush">
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Invoice</th><?= branch_th() ?><th>Date & time</th><th>Customer</th><?php if ($viewAll): ?><th>Cashier</th><?php endif; ?><th>Paid by</th><th class="num">Items</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s): ?>
        <tr class="<?= $s['status'] === 'voided' ? 'row-muted' : '' ?>">
          <td class="strong"><a href="<?= e(app_url('/pos/sale_view.php?id=' . $s['id'])) ?>"><?= e($s['invoice_number']) ?></a></td>
          <?= branch_td((int) $s['branch_id']) ?>
          <td class="nowrap text-secondary"><?= e(fmt_datetime($s['sale_date'])) ?></td>
          <td><?= e($s['customer_name']) ?></td>
          <?php if ($viewAll): ?><td class="text-secondary"><?= e($s['cashier_name']) ?></td><?php endif; ?>
          <td class="text-secondary text-sm"><?= e(implode(', ', array_map('method_label', array_filter(explode(',', (string) $s['methods']))))) ?: '—' ?></td>
          <td class="num"><?= (int) $s['units'] ?></td>
          <td class="num strong"><?= format_money($s['total_amount']) ?></td>
          <td><?= status_badge($s['status']) ?></td>
          <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/pos/sale_view.php?id=' . $s['id'])) ?>">View</a><a class="btn btn-secondary btn-sm btn-icon" title="Receipt" href="<?= e(app_url('/pos/receipt.php?id=' . $s['id'])) ?>" target="_blank"><?= icon('printer', 14) ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$sales) echo empty_state('receipt', 'No sales found', 'Try a different date range or clear the filters.'); ?>
  </div>
  <?php render_pagination($page, $perPage, (int) $agg['n']); ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
