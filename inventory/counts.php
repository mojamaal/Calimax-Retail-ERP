<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['inventory.count', 'inventory.approve']);

$pdo = db();
if (is_post() && input($_POST, 'action') === 'create') {
    verify_csrf();
    require_permission('inventory.count');
    $title = input($_POST, 'title') ?: 'Stock count ' . date('M j, Y');
    $categoryId = input_int($_POST, 'category_id') ?: null;
    $countBranch = write_branch();
    try {
        $pdo->beginTransaction();
        $number = next_document_number('CNT', 'stock_counts', 'count_number', $countBranch);
        $pdo->prepare("INSERT INTO stock_counts (count_number, branch_id, title, category_id, status, created_by) VALUES (:n,:br,:t,:c,'in_progress',:u)")
            ->execute([':n' => $number, ':br' => $countBranch, ':t' => $title, ':c' => $categoryId, ':u' => current_user()['id']]);
        $countId = (int) $pdo->lastInsertId();
        // Snapshot this branch's system quantities for every active item in scope.
        $sql = 'INSERT INTO stock_count_items (count_id, variant_id, system_qty) SELECT :cid, pv.id, pv.stock_qty FROM ' . pv($countBranch) . ' pv JOIN products p ON p.id = pv.product_id WHERE pv.is_active = 1 AND p.is_active = 1';
        $params = [':cid' => $countId];
        if ($categoryId) { $sql .= ' AND (p.category_id = :cat OR p.category_id IN (SELECT id FROM categories WHERE parent_id = :cat2))'; $params[':cat'] = $categoryId; $params[':cat2'] = $categoryId; }
        $pdo->prepare($sql)->execute($params);
        $items = (int) $pdo->query('SELECT COUNT(*) FROM stock_count_items WHERE count_id = ' . $countId)->fetchColumn();
        if (!$items) throw new RuntimeException('There are no active products in that category to count.');
        $pdo->commit();
        log_activity('stock_count_create', 'Started ' . $number . ' “' . $title . '” · ' . $items . ' items', 'stock_count', $countId);
        flash('success', 'Count started with ' . $items . ' items. Scan or type quantities as you count the shelf.');
        redirect('/inventory/count_view.php?id=' . $countId);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not start the count.');
        error_log('Count create failed: ' . $ex->getMessage());
        redirect('/inventory/counts.php');
    }
}

$status = input($_GET, 'status');
$where = 'WHERE ' . branch_where('sc') . (in_array($status, ['in_progress', 'submitted', 'approved', 'cancelled'], true) ? ' AND sc.status = ' . $pdo->quote($status) : '');
$counts = $pdo->query("SELECT sc.*, c.name AS category_name, u.full_name AS created_name, a.full_name AS approved_name,
    (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id) AS items,
    (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id AND counted_qty IS NOT NULL) AS counted,
    (SELECT COUNT(*) FROM stock_count_items WHERE count_id = sc.id AND counted_qty IS NOT NULL AND counted_qty <> system_qty) AS variances
    FROM stock_counts sc LEFT JOIN categories c ON c.id = sc.category_id LEFT JOIN users u ON u.id = sc.created_by LEFT JOIN users a ON a.id = sc.approved_by
    $where ORDER BY sc.id DESC LIMIT 100")->fetchAll();
$categories = $pdo->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();

$pageTitle = 'Stock Counts';
require __DIR__ . '/../includes/header.php';
page_header('Stock Control', 'Physical stock counts: count the shelf, compare with the system, and post approved corrections.',
    can('inventory.count') && current_branch_id() !== null ? '<button class="btn btn-primary" type="button" data-modal-open="#countModal">' . icon('plus', 16) . ' Start a count</button>' : '');
nav_stock();
?>
<div class="filter-bar">
  <div class="chip-group">
    <?php foreach (['' => 'All', 'in_progress' => 'In progress', 'submitted' => 'Submitted', 'approved' => 'Approved', 'cancelled' => 'Cancelled'] as $k => $l): ?><a class="chip <?= $status === $k ? 'active' : '' ?>" href="?<?= $k ? 'status=' . $k : '' ?>"><?= $l ?></a><?php endforeach; ?>
  </div>
</div>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Count</th><?= branch_th() ?><th>Scope</th><th>Started</th><th style="width:180px;">Progress</th><th class="num">Variances</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($counts as $c): $pct = $c['items'] ? round($c['counted'] / $c['items'] * 100) : 0; ?>
      <tr>
        <td><a class="strong" href="<?= e(app_url('/inventory/count_view.php?id=' . $c['id'])) ?>"><?= e($c['count_number']) ?></a><div class="cell-sub"><?= e($c['title']) ?></div></td>
        <?= branch_td((int) $c['branch_id']) ?>
        <td class="text-secondary"><?= e($c['category_name'] ?? 'All products') ?></td>
        <td class="text-secondary text-sm"><?= e(fmt_datetime($c['created_at'])) ?><div class="cell-sub"><?= e($c['created_name'] ?? '') ?></div></td>
        <td><div class="text-sm"><?= (int) $c['counted'] ?> / <?= (int) $c['items'] ?> counted</div><div class="meter mt-1"><span style="width:<?= $pct ?>%"></span></div></td>
        <td class="num"><?= (int) $c['variances'] ? '<span class="badge badge-warning">' . (int) $c['variances'] . '</span>' : '—' ?></td>
        <td><?= status_badge($c['status']) ?></td>
        <td class="actions"><a class="btn btn-<?= in_array($c['status'], ['in_progress', 'submitted'], true) ? 'primary' : 'secondary' ?> btn-sm" href="<?= e(app_url('/inventory/count_view.php?id=' . $c['id'])) ?>"><?= $c['status'] === 'in_progress' ? 'Continue' : ($c['status'] === 'submitted' && can('inventory.approve') ? 'Review' : 'View') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$counts) echo empty_state('list-checks', 'No stock counts yet', 'Start a count for the whole shop or one category. The system snapshots current quantities so you can count at your own pace.'); ?></div>
</div>

<div class="modal-overlay" id="countModal" hidden>
  <div class="modal sm">
    <div class="modal-header"><h3>Start a stock count</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="form-group"><label>Title</label><input class="form-control" type="text" name="title" maxlength="150" value="Stock count <?= e(date('M j, Y')) ?>"></div>
      <div class="form-group"><label>What to count</label><select class="form-control" name="category_id"><option value="">Entire shop (all products)</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="callout text-sm">Counters won’t see the system quantity (blind count) — this gives an honest count. A manager reviews the differences before stock is corrected.</div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Start count</button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
