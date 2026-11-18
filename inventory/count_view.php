<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/stock.php';
require_permission(['inventory.count', 'inventory.approve']);

$pdo = db();
$id = input_int($_GET, 'id');
$s = $pdo->prepare('SELECT sc.*, c.name AS category_name, u.full_name AS created_name, sb.full_name AS submitted_name, a.full_name AS approved_name, sa.adj_number FROM stock_counts sc LEFT JOIN categories c ON c.id = sc.category_id LEFT JOIN users u ON u.id = sc.created_by LEFT JOIN users sb ON sb.id = sc.submitted_by LEFT JOIN users a ON a.id = sc.approved_by LEFT JOIN stock_adjustments sa ON sa.id = sc.adjustment_id WHERE sc.id = :id');
$s->execute([':id' => $id]);
$count = $s->fetch();
if (!$count) { flash('error', 'Count not found.'); redirect('/inventory/counts.php'); }
assert_branch_access((int) $count['branch_id']);
$canApprove = can('inventory.approve');

if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    try {
        if (in_array($action, ['save', 'submit'], true)) {
            if ($count['status'] !== 'in_progress') throw new RuntimeException('This count is no longer open for counting.');
            $data = json_decode($_POST['counts_json'] ?? '{}', true) ?: [];
            $upd = $pdo->prepare('UPDATE stock_count_items SET counted_qty = :q WHERE id = :id AND count_id = :cid');
            $pdo->beginTransaction();
            foreach ($data as $itemId => $qty) {
                $upd->execute([':q' => ($qty === null || $qty === '') ? null : max(0, (int) $qty), ':id' => (int) $itemId, ':cid' => $id]);
            }
            if ($action === 'submit') {
                $pdo->prepare("UPDATE stock_counts SET status = 'submitted', submitted_by = :u, submitted_at = NOW() WHERE id = :id")->execute([':u' => current_user()['id'], ':id' => $id]);
            }
            $pdo->commit();
            if ($action === 'submit') { log_activity('stock_count_submit', 'Submitted ' . $count['count_number'] . ' for approval', 'stock_count', $id); flash('success', 'Count submitted. A manager will review the differences.'); }
            else flash('success', 'Progress saved.');
        } elseif ($action === 'approve') {
            if (!$canApprove) deny_access();
            if ($count['status'] !== 'submitted') throw new RuntimeException('Only a submitted count can be approved.');
            $var = $pdo->prepare('SELECT variant_id, counted_qty - system_qty AS delta FROM stock_count_items WHERE count_id = :id AND counted_qty IS NOT NULL AND counted_qty <> system_qty');
            $var->execute([':id' => $id]);
            $lines = array_map(fn($r) => ['variant_id' => (int) $r['variant_id'], 'qty' => (int) $r['delta'], 'note' => 'Counted vs system'], $var->fetchAll());
            $pdo->beginTransaction();
            $adjId = null;
            if ($lines) $adjId = create_stock_adjustment($pdo, 'correction', $lines, 'Posted from ' . $count['count_number'] . ' — ' . $count['title'], true, 'count', $id, (int) $count['branch_id']);
            $pdo->prepare("UPDATE stock_counts SET status = 'approved', approved_by = :u, approved_at = NOW(), adjustment_id = :a WHERE id = :id")->execute([':u' => current_user()['id'], ':a' => $adjId, ':id' => $id]);
            $pdo->commit();
            log_activity('stock_count_approve', 'Approved ' . $count['count_number'] . ' · ' . count($lines) . ' correction(s) posted', 'stock_count', $id);
            flash('success', $lines ? count($lines) . ' stock correction(s) posted.' : 'Count approved — no differences found.');
        } elseif ($action === 'reopen') {
            if (!$canApprove) deny_access();
            $pdo->prepare("UPDATE stock_counts SET status = 'in_progress', submitted_by = NULL, submitted_at = NULL WHERE id = :id AND status = 'submitted'")->execute([':id' => $id]);
            log_activity('stock_count_reopen', 'Reopened ' . $count['count_number'] . ' for recount', 'stock_count', $id);
            flash('success', 'Count reopened for recounting.');
        } elseif ($action === 'cancel') {
            if (!in_array($count['status'], ['in_progress', 'submitted'], true)) throw new RuntimeException('This count cannot be cancelled.');
            $pdo->prepare("UPDATE stock_counts SET status = 'cancelled' WHERE id = :id")->execute([':id' => $id]);
            log_activity('stock_count_cancel', 'Cancelled ' . $count['count_number'], 'stock_count', $id);
            flash('success', 'Count cancelled. No stock was changed.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not update the count.');
        error_log('Count action failed: ' . $ex->getMessage());
    }
    redirect('/inventory/count_view.php?id=' . $id);
}

$items = $pdo->prepare('SELECT sci.*, pv.sku, pv.barcode, pv.variant_label, pv.cost_price, pv.stock_qty AS current_qty, p.name AS product_name FROM stock_count_items sci JOIN ' . pv((int) $count['branch_id']) . ' pv ON pv.id = sci.variant_id JOIN products p ON p.id = pv.product_id WHERE sci.count_id = :id ORDER BY p.name, pv.variant_label');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$showSystem = $canApprove || $count['status'] !== 'in_progress';
$counted = count(array_filter($items, fn($i) => $i['counted_qty'] !== null));
$variances = array_filter($items, fn($i) => $i['counted_qty'] !== null && (int) $i['counted_qty'] !== (int) $i['system_qty']);
$varValue = array_sum(array_map(fn($i) => ((int) $i['counted_qty'] - (int) $i['system_qty']) * (float) $i['cost_price'], $variances));
$editable = $count['status'] === 'in_progress';

$pageTitle = $count['count_number'];
require __DIR__ . '/../includes/header.php';
$actions = '';
if (in_array($count['status'], ['in_progress', 'submitted'], true) && ($canApprove || (int) $count['created_by'] === (int) current_user()['id'])) $actions .= '<form method="post" data-confirm="Cancel this count? Entered quantities will be discarded.">' . csrf_field() . '<input type="hidden" name="action" value="cancel"><button class="btn btn-danger-outline" type="submit">Cancel count</button></form>';
page_header($count['count_number'] . ' · ' . $count['title'], e($count['category_name'] ?? 'Entire shop') . ' · ' . status_badge($count['status']) . ' · started ' . e(fmt_datetime($count['created_at'])) . ' by ' . e($count['created_name'] ?? '—'), $actions, [['Stock counts', '/inventory/counts.php'], [$count['count_number'], null]]);
?>
<div class="grid grid-4 mb-4">
  <?= kpi('Items in count', number_format(count($items)), 'list', 'blue') ?>
  <?= kpi('Counted', number_format($counted) . ' <span class="text-muted" style="font-size:14px;">/ ' . count($items) . '</span>', 'check-circle-2', 'green', count($items) - $counted . ' not yet counted (left unchanged)') ?>
  <?= $showSystem ? kpi('Differences', number_format(count($variances)), 'scale', count($variances) ? 'amber' : 'gray', 'Counted ≠ system') : kpi('Mode', 'Blind count', 'eye-off', 'gray', 'System quantities hidden') ?>
  <?= $showSystem && can('products.view_cost') ? kpi('Value difference', format_money($varValue), 'wallet', $varValue < 0 ? 'red' : 'green', 'At cost price') : kpi('Status', ucwords(str_replace('_', ' ', $count['status'])), 'info', 'gray') ?>
</div>
<?php if ($count['status'] === 'approved'): ?><div class="alert alert-success"><?= icon('check-circle-2', 18) ?><span>Approved by <?= e($count['approved_name']) ?> on <?= e(fmt_datetime($count['approved_at'])) ?>.<?= $count['adjustment_id'] ? ' Corrections posted as <a class="link" href="' . e(app_url('/inventory/adjustment_view.php?id=' . $count['adjustment_id'])) . '">' . e($count['adj_number']) . '</a>.' : ' No corrections were needed.' ?></span></div><?php endif; ?>
<?php if ($count['status'] === 'submitted'): ?><div class="alert alert-info"><?= icon('info', 18) ?><span>Submitted by <?= e($count['submitted_name']) ?> on <?= e(fmt_datetime($count['submitted_at'])) ?>. <?= $canApprove ? 'Review the differences below, then approve to correct stock (sales made during the count are accounted for).' : 'Waiting for manager approval.' ?></span></div><?php endif; ?>

<form method="post" id="countForm">
  <?= csrf_field() ?><input type="hidden" name="counts_json" id="counts_json"><input type="hidden" name="action" id="countAction" value="save">
  <div class="card flush">
    <div class="card-head">
      <div><h3>Count sheet</h3><div class="sub"><?= $editable ? 'Scan an item to add 1, or type the counted quantity. Leave blank if not counted.' : 'Read-only.' ?></div></div>
      <div class="flex gap-2 flex-wrap">
        <?php if ($editable): ?><input class="form-control" id="scanCount" type="text" placeholder="Scan barcode to count +1" autocomplete="off" style="width:240px;"><?php endif; ?>
        <input class="form-control" id="filterCount" type="search" placeholder="Filter list" style="width:180px;">
        <?php if ($showSystem): ?><label class="check text-sm"><input type="checkbox" id="onlyVar"> Only differences</label><?php endif; ?>
      </div>
    </div>
    <div class="table-wrap" style="max-height:60vh;overflow:auto;"><table class="data-table">
      <thead><tr><th>Item</th><th>Code / barcode</th><?php if ($showSystem): ?><th class="num">System</th><?php endif; ?><th style="width:130px;">Counted</th><?php if ($showSystem): ?><th class="num">Difference</th><th class="num">Now</th><?php endif; ?></tr></thead>
      <tbody id="countBody">
      <?php foreach ($items as $it): $d = $it['counted_qty'] !== null ? (int) $it['counted_qty'] - (int) $it['system_qty'] : null; ?>
        <tr data-row data-barcode="<?= e($it['barcode']) ?>" data-sku="<?= e($it['sku']) ?>" data-text="<?= e(strtolower($it['product_name'] . ' ' . $it['variant_label'] . ' ' . $it['sku'])) ?>" data-var="<?= $d ? 1 : 0 ?>">
          <td class="strong"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' — ' . e($it['variant_label']) : '' ?></td>
          <td class="mono text-secondary"><?= e($it['sku']) ?><div class="cell-sub"><?= e($it['barcode']) ?></div></td>
          <?php if ($showSystem): ?><td class="num"><?= (int) $it['system_qty'] ?></td><?php endif; ?>
          <td><?php if ($editable): ?><input class="form-control count-input" type="number" min="0" data-id="<?= (int) $it['id'] ?>" data-sys="<?= (int) $it['system_qty'] ?>" value="<?= $it['counted_qty'] !== null ? (int) $it['counted_qty'] : '' ?>"><?php else: ?><span class="strong"><?= $it['counted_qty'] !== null ? (int) $it['counted_qty'] : '<span class="text-muted">not counted</span>' ?></span><?php endif; ?></td>
          <?php if ($showSystem): ?><td class="num strong diff <?= $d > 0 ? 'text-success' : ($d < 0 ? 'text-error' : '') ?>"><?= $d === null ? '—' : ($d > 0 ? '+' : '') . $d ?></td><td class="num text-secondary"><?= (int) $it['current_qty'] ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="pagination">
      <span class="text-muted" id="countProgress"></span>
      <div class="flex gap-2">
        <?php if ($editable): ?>
          <button class="btn btn-secondary" type="submit" data-act="save" data-no-busy><?= icon('save', 16) ?> Save progress</button>
          <button class="btn btn-primary" type="submit" data-act="submit"><?= icon('send', 16) ?> Submit for approval</button>
        <?php elseif ($count['status'] === 'submitted' && $canApprove): ?>
          <button class="btn btn-secondary" type="submit" data-act="reopen">Reopen for recount</button>
          <button class="btn btn-success" type="submit" data-act="approve"><?= icon('check', 16) ?> Approve &amp; post <?= count($variances) ?> correction(s)</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  const form = document.getElementById('countForm');
  const inputs = [...document.querySelectorAll('.count-input')];
  function progress() {
    const done = inputs.length ? inputs.filter((i) => i.value !== '').length : <?= $counted ?>;
    document.getElementById('countProgress').textContent = done + ' of <?= count($items) ?> items counted';
  }
  inputs.forEach((i) => i.addEventListener('input', () => {
    const row = i.closest('tr'), diff = row.querySelector('.diff');
    if (diff) { const d = i.value === '' ? null : (+i.value - +i.dataset.sys); diff.textContent = d === null ? '—' : (d > 0 ? '+' : '') + d; diff.className = 'num strong diff ' + (d > 0 ? 'text-success' : d < 0 ? 'text-error' : ''); row.dataset.var = d ? 1 : 0; }
    progress();
  }));
  const scan = document.getElementById('scanCount');
  if (scan) scan.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = scan.value.trim(); scan.value = '';
    const row = [...document.querySelectorAll('[data-row]')].find((r) => r.dataset.barcode === code || r.dataset.sku.toLowerCase() === code.toLowerCase());
    if (!row) { CalimaxUI.toast('Item ' + code + ' is not part of this count.', 'error'); return; }
    const inp = row.querySelector('.count-input');
    inp.value = (parseInt(inp.value, 10) || 0) + 1;
    inp.dispatchEvent(new Event('input'));
    row.scrollIntoView({ block: 'center' });
    row.style.background = 'var(--orange-soft)'; setTimeout(() => { row.style.background = ''; }, 600);
  });
  const filter = document.getElementById('filterCount'), only = document.getElementById('onlyVar');
  function applyFilter() {
    const q = filter.value.trim().toLowerCase();
    document.querySelectorAll('[data-row]').forEach((r) => { r.hidden = (q && !r.dataset.text.includes(q)) || (only && only.checked && r.dataset.var !== '1'); });
  }
  filter.addEventListener('input', applyFilter);
  if (only) only.addEventListener('change', applyFilter);
  form.querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => { document.getElementById('countAction').value = b.dataset.act; }));
  form.addEventListener('submit', (e) => {
    const act = document.getElementById('countAction').value;
    const data = {}; inputs.forEach((i) => { data[i.dataset.id] = i.value === '' ? null : +i.value; });
    document.getElementById('counts_json').value = JSON.stringify(data);
    if (act === 'submit' && !confirm('Submit this count for approval? You won’t be able to edit it afterwards.')) e.preventDefault();
    if (act === 'approve' && !confirm('Post the stock corrections now?')) e.preventDefault();
  });
  progress();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
