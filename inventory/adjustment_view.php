<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stock.php';
require_permission(['inventory.adjust', 'inventory.approve']);

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT a.*, ru.full_name AS requested_name, au.full_name AS approved_name, sc.count_number FROM stock_adjustments a LEFT JOIN users ru ON ru.id = a.requested_by LEFT JOIN users au ON au.id = a.approved_by LEFT JOIN stock_counts sc ON sc.id = a.count_id WHERE a.id = :id');
$s->execute([':id' => $id]);
$adj = $s->fetch();
if (!$adj) { flash('error', 'Adjustment not found.'); redirect('/inventory/adjustments.php'); }
assert_branch_access((int) $adj['branch_id']);

if (is_post()) {
    verify_csrf();
    require_permission('inventory.approve');
    $action = input($_POST, 'action');
    try {
        $pdo->beginTransaction();
        if ($action === 'approve') {
            apply_stock_adjustment($pdo, $id, (int) current_user()['id']);
            $pdo->commit();
            log_activity('stock_adjustment_approve', 'Approved ' . $adj['adj_number'] . ' · ' . adjustment_reason_label($adj['reason']), 'stock_adjustment', $id);
            flash('success', 'Adjustment approved and applied to stock.');
        } elseif ($action === 'reject') {
            $why = input($_POST, 'rejected_reason') ?: 'Not specified';
            $u = $pdo->prepare("UPDATE stock_adjustments SET status = 'rejected', approved_by = :u, approved_at = NOW(), rejected_reason = :r WHERE id = :id AND status = 'pending'");
            $u->execute([':u' => current_user()['id'], ':r' => $why, ':id' => $id]);
            if (!$u->rowCount()) throw new RuntimeException('This adjustment is no longer pending.');
            $pdo->commit();
            log_activity('stock_adjustment_reject', 'Rejected ' . $adj['adj_number'] . ' · ' . $why, 'stock_adjustment', $id);
            flash('success', 'Adjustment rejected. Stock unchanged.');
        } else $pdo->rollBack();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not update the adjustment.');
        error_log('Adjustment action failed: ' . $ex->getMessage());
    }
    redirect('/inventory/adjustment_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT sai.*, pv.sku, pv.variant_label, pv.stock_qty, pv.damaged_qty, p.name AS product_name, p.id AS product_id FROM stock_adjustment_items sai JOIN ' . pv((int) $adj['branch_id']) . ' pv ON pv.id = sai.variant_id JOIN products p ON p.id = pv.product_id WHERE sai.adjustment_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$seeCost = can('products.view_cost');
$est = array_sum(array_map(fn($i) => $i['qty_change'] * $i['unit_cost'], $items));

$pageTitle = $adj['adj_number'];
require __DIR__ . '/../includes/header.php';
page_header($adj['adj_number'], e(adjustment_reason_label($adj['reason'])) . ' · ' . status_badge($adj['status']) . ($adj['count_number'] ? ' · from count <a class="link" href="' . e(app_url('/inventory/count_view.php?id=' . $adj['count_id'])) . '">' . e($adj['count_number']) . '</a>' : ''), '', [['Stock Control', '/inventory/adjustments.php'], [$adj['adj_number'], null]]);
?>
<?php if ($adj['status'] === 'pending'): ?><div class="alert alert-warning"><?= icon('hourglass', 18) ?><span>Awaiting approval — stock has not changed yet.</span></div><?php endif; ?>
<?php if ($adj['status'] === 'rejected'): ?><div class="alert alert-error"><?= icon('x-circle', 18) ?><span>Rejected by <?= e($adj['approved_name']) ?>: <?= e($adj['rejected_reason']) ?></span></div><?php endif; ?>
<div class="layout-2-1">
  <div class="card flush">
    <div class="card-head"><h3>Items</h3></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">Change</th><?php if ($seeCost): ?><th class="num">Unit cost</th><th class="num">Value</th><?php endif; ?><th class="num">Now in stock</th><th>Note</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $it['product_id'])) ?>"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></a><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
          <td class="num strong <?= $it['qty_change'] > 0 ? 'text-success' : 'text-error' ?>"><?= $it['qty_change'] > 0 ? '+' : '' ?><?= (int) $it['qty_change'] ?></td>
          <?php if ($seeCost): ?><td class="num"><?= format_money($it['unit_cost']) ?></td><td class="num"><?= format_money($it['qty_change'] * $it['unit_cost']) ?></td><?php endif; ?>
          <td class="num"><?= (int) $it['stock_qty'] ?><?= (int) $it['damaged_qty'] ? ' <span class="cell-sub">(' . (int) $it['damaged_qty'] . ' dmg)</span>' : '' ?></td>
          <td class="text-secondary text-sm"><?= e($it['note'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="grid">
    <div class="card">
      <h3 class="mb-3">Details</h3>
      <dl class="desc-list">
        <dt>Reason</dt><dd><?= e(adjustment_reason_label($adj['reason'])) ?></dd>
        <dt>Explanation</dt><dd><?= e($adj['note'] ?? '—') ?></dd>
        <dt>Requested by</dt><dd><?= e($adj['requested_name'] ?? '—') ?></dd>
        <dt>Requested</dt><dd><?= e(fmt_datetime($adj['created_at'])) ?></dd>
        <?php if ($adj['status'] !== 'pending'): ?><dt><?= $adj['status'] === 'approved' ? 'Approved by' : 'Rejected by' ?></dt><dd><?= e($adj['approved_name'] ?? '—') ?></dd><dt>On</dt><dd><?= e(fmt_datetime($adj['approved_at'])) ?></dd><?php endif; ?>
        <?php if ($seeCost): ?><dt>Value impact</dt><dd class="<?= ($adj['status'] === 'approved' ? $adj['value_change'] : $est) < 0 ? 'text-error' : '' ?>"><?= in_array($adj['reason'], ['damaged', 'restore_damaged'], true) ? 'Moved between buckets' : format_money($adj['status'] === 'approved' ? $adj['value_change'] : $est) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if ($adj['status'] === 'pending' && can('inventory.approve')): ?>
      <div class="card">
        <h3 class="mb-3">Decision</h3>
        <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><button class="btn btn-success btn-block" type="submit"><?= icon('check', 16) ?> Approve & apply</button></form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input class="form-control mb-2" name="rejected_reason" required maxlength="255" placeholder="Reason for rejecting"><button class="btn btn-danger-outline btn-block" type="submit">Reject</button></form>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
