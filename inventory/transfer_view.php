<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/transfers.php';
require_permission('transfers.view');

$pdo = db();
$id = input_int($_GET, 'id');
$load = function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT t.*, fb.name AS from_name, tb.name AS to_name, u.full_name AS by_name, r.full_name AS received_name, c.full_name AS cancelled_name
        FROM stock_transfers t JOIN branches fb ON fb.id = t.from_branch_id JOIN branches tb ON tb.id = t.to_branch_id
        LEFT JOIN users u ON u.id = t.created_by LEFT JOIN users r ON r.id = t.received_by LEFT JOIN users c ON c.id = t.cancelled_by WHERE t.id = :id');
    $s->execute([':id' => $id]);
    return $s->fetch();
};
$tr = $load();
if (!$tr) { flash('error', 'Transfer not found.'); redirect('/inventory/transfers.php'); }
if (!can_access_transfer($tr)) deny_access();

$accessible = array_keys(accessible_branches());
$isReceiver = in_array((int) $tr['to_branch_id'], $accessible, true);
$isSender = in_array((int) $tr['from_branch_id'], $accessible, true);

if (is_post()) {
    verify_csrf();
    require_permission('transfers.manage');
    $action = input($_POST, 'action');
    try {
        $pdo->beginTransaction();
        if ($action === 'receive') {
            if (!$isReceiver) deny_access();
            $r = receive_stock_transfer($pdo, $id, array_map('intval', (array) ($_POST['received'] ?? [])));
            $pdo->commit();
            log_activity('transfer_receive', $r['number'] . ' received at ' . $tr['to_name'] . ': ' . $r['received'] . ' unit(s)' . ($r['short'] ? ', ' . $r['short'] . ' short (returned to ' . $tr['from_name'] . ')' : ''), 'stock_transfer', $id);
            flash($r['short'] ? 'warning' : 'success', 'Transfer received: ' . $r['received'] . ' unit(s) added to ' . $tr['to_name'] . ' stock.' . ($r['short'] ? ' ' . $r['short'] . ' short unit(s) went back to ' . $tr['from_name'] . '.' : ''));
        } elseif ($action === 'cancel') {
            if (!$isSender) deny_access();
            $r = cancel_stock_transfer($pdo, $id);
            $pdo->commit();
            log_activity('transfer_cancel', $r['number'] . ' cancelled — ' . $r['units'] . ' unit(s) returned to ' . $tr['from_name'], 'stock_transfer', $id);
            flash('success', 'Transfer cancelled. The stock is back with ' . $tr['from_name'] . '.');
        } else { $pdo->rollBack(); }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Transfer action failed: ' . $ex->getMessage());
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not update the transfer.');
    }
    redirect('/inventory/transfer_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT ti.*, v.sku, v.variant_label, p.name AS product_name, p.id AS product_id FROM stock_transfer_items ti JOIN product_variants v ON v.id = ti.variant_id JOIN products p ON p.id = v.product_id WHERE ti.transfer_id = :id ORDER BY p.name');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$seeCost = can('products.view_cost');
$units = array_sum(array_column($items, 'quantity'));
$value = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_cost'], $items));
$canReceive = $tr['status'] === 'in_transit' && $isReceiver && can('transfers.manage');
$canCancel = $tr['status'] === 'in_transit' && $isSender && can('transfers.manage');

$pageTitle = $tr['transfer_number'];
require __DIR__ . '/../includes/header.php';
page_header($tr['transfer_number'], e($tr['from_name']) . ' → ' . e($tr['to_name']) . ' · ' . status_badge($tr['status']), '', [['Branch transfers', '/inventory/transfers.php'], [$tr['transfer_number'], null]]);
?>
<?php if ($tr['status'] === 'in_transit'): ?><div class="alert alert-info"><?= icon('truck', 18) ?><span>In transit — the stock has left <strong><?= e($tr['from_name']) ?></strong> and is not yet in <strong><?= e($tr['to_name']) ?></strong>'s stock.</span></div><?php endif; ?>
<?php if ($tr['status'] === 'cancelled'): ?><div class="alert alert-error"><?= icon('ban', 18) ?><span>Cancelled by <?= e($tr['cancelled_name'] ?? '—') ?> on <?= e(fmt_datetime($tr['cancelled_at'])) ?>. All stock returned to <?= e($tr['from_name']) ?>.</span></div><?php endif; ?>
<div class="layout-2-1">
  <form method="post" class="card flush" id="recvForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="receive">
    <div class="card-head"><h3>Items</h3><?php if ($canReceive): ?><span class="text-muted text-sm">Count what actually arrived, then confirm.</span><?php endif; ?></div>
    <div class="table-wrap"><table class="data-table">
      <thead><tr><th>Item</th><th class="num">Sent</th><th class="num"><?= $tr['status'] === 'received' ? 'Received' : ($canReceive ? 'Received' : '—') ?></th><?php if ($seeCost): ?><th class="num">Unit cost</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><a class="strong" href="<?= e(app_url('/inventory/product_view.php?id=' . $it['product_id'])) ?>"><?= e($it['product_name']) ?></a><?= $it['variant_label'] ? ' <span class="text-muted">— ' . e($it['variant_label']) . '</span>' : '' ?><div class="cell-sub mono"><?= e($it['sku']) ?></div></td>
          <td class="num strong"><?= (int) $it['quantity'] ?></td>
          <td class="num"><?php if ($canReceive): ?><input class="form-control sm" style="width:90px;margin-left:auto;" type="number" min="0" max="<?= (int) $it['quantity'] ?>" name="received[<?= (int) $it['id'] ?>]" value="<?= (int) $it['quantity'] ?>"><?php elseif ($tr['status'] === 'received'): $short = (int) $it['quantity'] - (int) $it['received_qty']; ?><span class="strong"><?= (int) $it['received_qty'] ?></span><?= $short > 0 ? ' <span class="badge badge-warning">' . $short . ' short</span>' : '' ?><?php else: ?>—<?php endif; ?></td>
          <?php if ($seeCost): ?><td class="num text-secondary"><?= format_money($it['unit_cost']) ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($canReceive): ?><div style="padding:16px;" class="flex gap-2"><button class="btn btn-primary" type="submit"><?= icon('package-check', 16) ?> Confirm receipt</button><span class="text-muted text-sm" style="align-self:center;">Anything not received goes back to <?= e($tr['from_name']) ?>'s stock.</span></div><?php endif; ?>
  </form>
  <div class="grid">
    <div class="card">
      <h4 class="mb-2">Details</h4>
      <dl class="desc-list">
        <dt>From</dt><dd><?= e($tr['from_name']) ?></dd>
        <dt>To</dt><dd><?= e($tr['to_name']) ?></dd>
        <dt>Units</dt><dd><?= number_format($units) ?></dd>
        <?php if ($seeCost): ?><dt>Value at cost</dt><dd><?= format_money($value) ?></dd><?php endif; ?>
        <dt>Sent</dt><dd><?= e(fmt_datetime($tr['created_at'])) ?><div class="cell-sub"><?= e($tr['by_name'] ?? '') ?></div></dd>
        <?php if ($tr['received_at']): ?><dt>Received</dt><dd><?= e(fmt_datetime($tr['received_at'])) ?><div class="cell-sub"><?= e($tr['received_name'] ?? '') ?></div></dd><?php endif; ?>
        <?php if ($tr['note']): ?><dt>Note</dt><dd><?= e($tr['note']) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if ($canCancel): ?>
    <div class="card">
      <h4 class="mb-2">Changed your mind?</h4>
      <p class="text-muted text-sm">Cancelling returns everything to <?= e($tr['from_name']) ?>. Only possible while the transfer is in transit.</p>
      <form method="post" data-confirm="Cancel this transfer and return the stock?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-danger-outline" type="submit"><?= icon('ban', 16) ?> Cancel transfer</button></form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
