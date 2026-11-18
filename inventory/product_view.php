<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.view');

$pdo = db();
$id = input_int($_GET, 'id');
$stmt = $pdo->prepare('SELECT p.*, c.name AS category_name, u.full_name AS created_name FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.created_by WHERE p.id = :id');
$stmt->execute([':id' => $id]);
$product = $stmt->fetch();
if (!$product) { flash('error', 'Product not found.'); redirect('/inventory/products.php'); }
$seeCost = can('products.view_cost');
$errors = [];

if (is_post()) {
    verify_csrf();
    require_permission('products.manage');
    $action = input($_POST, 'action');

    if ($action === 'update_variant') {
        $vid = input_int($_POST, 'variant_id');
        // Price, cost and reorder level belong to the CURRENT BRANCH; code, barcode and active flag are shared.
        $v = $pdo->prepare('SELECT * FROM ' . pv() . ' v WHERE id = :id AND product_id = :pid');
        $v->execute([':id' => $vid, ':pid' => $id]);
        $old = $v->fetch();
        $editBranch = current_branch_id();
        $new = [
            'sku' => strtoupper(input($_POST, 'sku')),
            'barcode' => input($_POST, 'barcode'),
            'price' => number_format(max(0, input_float($_POST, 'price')), 2, '.', ''),
            'cost_price' => number_format(max(0, input_float($_POST, 'cost_price')), 2, '.', ''),
            'reorder_level' => (string) max(0, input_int($_POST, 'reorder_level')),
            'is_active' => isset($_POST['is_active']) ? '1' : '0',
        ];
        if (!$old) $errors[] = 'Item not found.';
        if ($new['sku'] === '' || !preg_match('/^[A-Z0-9._-]{2,60}$/', $new['sku'])) $errors[] = 'Enter a valid product code (letters, numbers, . _ -).';
        elseif (!variant_code_available('sku', $new['sku'], $vid)) $errors[] = 'Product code ' . $new['sku'] . ' is already used.';
        if ($new['barcode'] === '' || !preg_match('/^[0-9A-Za-z-]{4,60}$/', $new['barcode'])) $errors[] = 'Enter a valid barcode.';
        elseif (!variant_code_available('barcode', $new['barcode'], $vid)) $errors[] = 'Barcode ' . $new['barcode'] . ' already belongs to another product.';
        if ($old && $editBranch === null) {   // consolidated view: branch figures can't be edited here
            $new['price'] = number_format((float) $old['price'], 2, '.', ''); $new['cost_price'] = number_format((float) $old['cost_price'], 2, '.', ''); $new['reorder_level'] = (string) $old['reorder_level'];
        }
        if ((float) $new['price'] <= 0) $errors[] = 'Selling price must be greater than zero.';
        if (!$seeCost && $old) $new['cost_price'] = number_format((float) $old['cost_price'], 2, '.', '');
        if (!$errors) {
            $labels = ['sku' => 'code', 'barcode' => 'barcode', 'price' => 'selling price', 'cost_price' => 'cost price', 'reorder_level' => 'reorder level', 'is_active' => 'active'];
            $changes = [];
            foreach ($new as $k => $val) { if ((string) $old[$k] !== $val && !(is_numeric($val) && is_numeric($old[$k]) && abs((float) $old[$k] - (float) $val) < 0.0001)) $changes[] = $labels[$k] . ' ' . $old[$k] . ' → ' . $val; }
            if ($changes) {
                $pdo->prepare('UPDATE product_variants SET sku=:sku, barcode=:bc, is_active=:a WHERE id=:id')
                    ->execute([':sku' => $new['sku'], ':bc' => $new['barcode'], ':a' => $new['is_active'], ':id' => $vid]);
                if ($editBranch !== null) {
                    ensure_branch_stock($pdo, $vid, $editBranch);
                    $pdo->prepare('UPDATE branch_stock SET price = :p, cost_price = :c, reorder_level = :r WHERE variant_id = :id AND branch_id = :b')
                        ->execute([':p' => $new['price'], ':c' => $new['cost_price'], ':r' => $new['reorder_level'], ':id' => $vid, ':b' => $editBranch]);
                }
                $what = $product['name'] . ($old['variant_label'] ? ' (' . $old['variant_label'] . ')' : '') . ' [' . $old['sku'] . ']' . ($editBranch !== null ? ' @ ' . branch_scope_label() : '');
                $isPrice = (float) $old['price'] != (float) $new['price'] || (float) $old['cost_price'] != (float) $new['cost_price'];
                log_activity($isPrice ? 'price_change' : 'product_update', $what . ': ' . implode('; ', $changes), 'product', $id);
                flash('success', 'Saved: ' . implode(', ', $changes) . '.');
            } else {
                flash('info', 'No changes to save.');
            }
            redirect('/inventory/product_view.php?id=' . $id);
        }
    } elseif ($action === 'branch_prices') {
        // Each branch sets its own selling prices and reorder levels.
        $bid = input_int($_POST, 'branch_id');
        if (!isset(accessible_branches()[$bid])) { $errors[] = 'You cannot change prices for that branch.'; }
        else {
            $changes = [];
            $cur = $pdo->prepare('SELECT * FROM ' . pv($bid) . ' v WHERE id = :id AND product_id = :pid');
            foreach ((array) ($_POST['price'] ?? []) as $vid => $newPrice) {
                $vid = (int) $vid; $newPrice = round((float) $newPrice, 2);
                $cur->execute([':id' => $vid, ':pid' => $id]);
                $o = $cur->fetch();
                if (!$o || $newPrice <= 0) continue;
                $newRe = isset($_POST['reorder'][$vid]) ? max(0, (int) $_POST['reorder'][$vid]) : (int) $o['reorder_level'];
                if (abs((float) $o['price'] - $newPrice) < 0.005 && (int) $o['reorder_level'] === $newRe) continue;
                set_branch_price($pdo, $vid, $bid, $newPrice, $newRe);
                $lbl = $o['variant_label'] ?: 'Standard';
                if (abs((float) $o['price'] - $newPrice) >= 0.005) $changes[] = $lbl . ' price ' . number_format((float) $o['price'], 2) . ' → ' . number_format($newPrice, 2);
                if ((int) $o['reorder_level'] !== $newRe) $changes[] = $lbl . ' reorder ' . (int) $o['reorder_level'] . ' → ' . $newRe;
            }
            if ($changes) {
                log_activity('price_change', $product['name'] . ' @ ' . branch_row($bid)['name'] . ': ' . implode('; ', $changes), 'product', $id);
                flash('success', 'Saved for ' . branch_row($bid)['name'] . ': ' . implode(', ', $changes) . '.');
            } else flash('info', 'No changes to save.');
            redirect('/inventory/product_view.php?id=' . $id . '#branches');
        }
    } elseif ($action === 'add_variant') {
        $price = input_float($_POST, 'price');
        $cost = input_float($_POST, 'cost_price');
        $selected = array_values(array_filter(array_map('intval', $_POST['attribute_values'] ?? [])));
        $sku = strtoupper(input($_POST, 'sku'));
        $barcode = input($_POST, 'barcode');
        $opening = max(0, input_int($_POST, 'initial_stock'));
        $label = '';
        if ($selected) {
            $ph = implode(',', array_fill(0, count($selected), '?'));
            $s = $pdo->prepare("SELECT value FROM attribute_values WHERE id IN ($ph) ORDER BY attribute_id");
            $s->execute($selected);
            $label = implode(' / ', $s->fetchAll(PDO::FETCH_COLUMN));
        }
        if ($price <= 0) $errors[] = 'Enter a selling price.';
        if ($opening > 0 && current_branch_id() === null) $errors[] = 'Choose a branch (top bar) to receive the opening stock, or leave opening stock at 0.';
        if ($sku !== '' && !variant_code_available('sku', $sku)) $errors[] = 'Product code ' . $sku . ' is already used.';
        if ($barcode !== '' && !variant_code_available('barcode', $barcode)) $errors[] = 'Barcode ' . $barcode . ' is already used.';
        if (!$errors) {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO product_variants (product_id, sku, barcode, variant_label, price, cost_price, reorder_level, stock_qty) VALUES (:p,:sku,:bc,:lbl,:price,:cost,:re,0)')
                ->execute([':p' => $id, ':sku' => $sku ?: generate_sku($product['name'], $label), ':bc' => $barcode ?: generate_barcode(), ':lbl' => $label ?: null, ':price' => $price, ':cost' => $cost, ':re' => max(0, input_int($_POST, 'reorder_level'))]);
            $variantId = (int) $pdo->lastInsertId();
            foreach ($selected as $avId) $pdo->prepare('INSERT INTO variant_attribute_values (variant_id, attribute_value_id) VALUES (:v,:a)')->execute([':v' => $variantId, ':a' => $avId]);
            if ($opening > 0) apply_stock_change($pdo, $variantId, $opening, 'initial', 'product', $id, 'Opening stock for new variant', 'saleable', current_branch_id());
            $pdo->commit();
            log_activity('product_update', 'Added variant ' . ($label ?: 'Default') . ' to ' . $product['name'] . ' · price ' . format_money($price), 'product', $id);
            flash('success', 'Variant added.');
            redirect('/inventory/product_view.php?id=' . $id);
        }
    }
}

$variants = $pdo->prepare('SELECT * FROM ' . pv() . ' v WHERE product_id = :id ORDER BY is_active DESC, variant_label');
$variants->execute([':id' => $id]);
$variants = $variants->fetchAll();
$variantIds = array_column($variants, 'id') ?: [0];
$in = implode(',', array_map('intval', $variantIds));

$sold30 = $pdo->query("SELECT COALESCE(SUM(si.quantity),0) AS qty, COALESCE(SUM(si.line_total - si.tax_amount),0) AS rev FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.variant_id IN ($in) AND s.status IN " . SALE_COUNTED . branch_and('s') . " AND s.sale_date >= NOW() - INTERVAL 30 DAY")->fetch();
$lastSold = $pdo->query("SELECT MAX(s.sale_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.variant_id IN ($in) AND s.status IN " . SALE_COUNTED . branch_and('s'))->fetchColumn();
$tab = input($_GET, 'tab') ?: 'movements';
$movements = $pdo->query("SELECT sm.*, pv.variant_label, pv.sku, u.full_name AS by_name FROM stock_movements sm JOIN product_variants pv ON pv.id = sm.variant_id LEFT JOIN users u ON u.id = sm.created_by WHERE sm.variant_id IN ($in)" . branch_and('sm') . " ORDER BY sm.id DESC LIMIT 60")->fetchAll();
$history = $pdo->prepare("SELECT al.*, u.full_name AS by_name FROM activity_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.entity_type = 'product' AND al.entity_id = :id ORDER BY al.id DESC LIMIT 50");
$history->execute([':id' => $id]);
$history = $history->fetchAll();
$totStock = array_sum(array_column($variants, 'stock_qty'));
$totDamaged = array_sum(array_column($variants, 'damaged_qty'));
$value = array_sum(array_map(fn($v) => max(0, $v['stock_qty']) * $v['cost_price'], $variants));
$daysCover = (float) $sold30['qty'] > 0 ? $totStock / ((float) $sold30['qty'] / 30) : null;
$mt = movement_types();

$attributes = $pdo->query('SELECT * FROM attributes ORDER BY name')->fetchAll();
$valuesByAttr = [];
foreach ($pdo->query('SELECT * FROM attribute_values ORDER BY attribute_id, id')->fetchAll() as $v) $valuesByAttr[$v['attribute_id']][] = $v;

$pageTitle = $product['name'];
require __DIR__ . '/../includes/header.php';
$actions = '';
if (can('inventory.adjust')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/inventory/adjustment_form.php?product_id=' . $id)) . '">' . icon('clipboard-check', 16) . ' Adjust stock</a>';
if (can('purchase.manage')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/purchase/order_form.php?variant_ids=' . implode(',', $variantIds))) . '">' . icon('truck', 16) . ' Reorder</a>';
if (can('products.manage')) $actions .= '<a class="btn btn-secondary" href="' . e(app_url('/inventory/product_form.php?id=' . $id)) . '">' . icon('pencil', 16) . ' Edit details</a><button class="btn btn-primary" type="button" data-modal-open="#addVariantModal">' . icon('plus', 16) . ' Add variant</button>';
page_header($product['name'], e($product['category_name'] ?? 'Uncategorized') . ($product['brand'] ? ' · ' . e($product['brand']) : '') . ' · sold per ' . e($product['unit']) . ($product['is_active'] ? '' : ' · ' . status_badge('inactive')), $actions, [['Products', '/inventory/products.php'], [$product['name'], null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>

<div class="grid grid-4 mb-4">
  <?= kpi('Saleable stock', number_format($totStock) . ' <span class="text-muted" style="font-size:14px;font-weight:600;">' . e($product['unit']) . '</span>', 'package', $totStock <= 0 ? 'red' : '', $totDamaged ? number_format($totDamaged) . ' damaged (not for sale)' : 'No damaged stock') ?>
  <?= kpi('Sold in last 30 days', number_format((float) $sold30['qty']), 'trending-up', 'green', $lastSold ? 'Last sold ' . e(fmt_date($lastSold)) : 'Never sold') ?>
  <?= kpi('Days of stock left', $daysCover === null ? '—' : number_format($daysCover, 0), 'calendar-clock', 'blue', 'At the current sales rate') ?>
  <?= $seeCost ? kpi('Stock value', format_money($value), 'wallet', 'purple', 'At cost price') : kpi('Revenue (30 days)', format_money($sold30['rev']), 'wallet', 'purple') ?>
</div>

<form method="get" action="<?= e(app_url('/inventory/barcode.php')) ?>">
<div class="card flush mb-4">
  <div class="card-head"><h3>Items &amp; prices <span class="text-muted text-sm" style="font-weight:500;">(<?= count($variants) ?>)</span></h3>
    <button class="btn btn-secondary btn-sm" type="submit"><?= icon('barcode', 14) ?> Print barcode labels</button></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th style="width:30px;"><input type="checkbox" onclick="document.querySelectorAll('.vchk').forEach(c=>c.checked=this.checked)" aria-label="Select all"></th>
        <th>Item</th><th>Code</th><th>Barcode</th><th class="num">Price</th><?php if ($seeCost): ?><th class="num">Cost</th><th class="num">Margin</th><?php endif; ?><th class="num">Stock</th><th class="num">Damaged</th><th class="num">Reorder at</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($variants as $v): $low = $v['stock_qty'] <= $v['reorder_level']; $m = (float) $v['price'] > 0 ? ((float) $v['price'] - (float) $v['cost_price']) / (float) $v['price'] * 100 : 0; ?>
        <tr class="<?= $v['is_active'] ? '' : 'row-muted' ?>">
          <td><input class="vchk" type="checkbox" name="ids[]" value="<?= (int) $v['id'] ?>" aria-label="Select"></td>
          <td class="strong"><?= e($v['variant_label'] ?: 'Standard') ?></td>
          <td class="mono"><?= e($v['sku']) ?></td>
          <td class="mono text-secondary"><?= e($v['barcode']) ?></td>
          <td class="num strong"><?= format_money($v['price']) ?></td>
          <?php if ($seeCost): ?><td class="num text-secondary"><?= format_money($v['cost_price']) ?></td><td class="num <?= $m < 0 ? 'text-error' : '' ?>"><?= number_format($m, 1) ?>%</td><?php endif; ?>
          <td class="num"><span class="strong"><?= (int) $v['stock_qty'] ?></span> <?php if ($v['stock_qty'] <= 0): ?><span class="badge badge-error">Out</span><?php elseif ($low): ?><span class="badge badge-warning">Low</span><?php endif; ?></td>
          <td class="num"><?= (int) $v['damaged_qty'] ?: '—' ?></td>
          <td class="num text-secondary"><?= (int) $v['reorder_level'] ?></td>
          <td><?= status_badge($v['is_active'] ? 'active' : 'inactive') ?></td>
          <td class="actions">
            <?php if (can('products.manage')): ?><button class="btn btn-secondary btn-sm" type="button" onclick='openEditVariant(<?= json_encode($v, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button><?php endif; ?>
            <?php if (can('inventory.adjust')): ?><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/inventory/adjustment_form.php?variant_id=' . $v['id'])) ?>">Adjust</a><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$variants) echo empty_state('layers', 'No items yet', 'Add a variant to start selling this product.'); ?>
  </div>
</div>
</form>

<?php if (count(branches_all()) > 1):
    $matrix = [];   // [variant_id => [branch_id => row]]
    foreach ($variants as $vv) foreach (variant_branch_rows((int) $vv['id']) as $br) $matrix[(int) $vv['id']][(int) $br['branch_id']] = $br;
    $myBranches = accessible_branches();
?>
<div class="card flush mb-4" id="branches">
  <div class="card-head"><h3><?= icon('building-2', 17) ?> Prices &amp; stock by branch</h3>
    <?php if (can('products.manage')): ?><div class="flex gap-2">
      <?php foreach ($myBranches as $mb): ?><button class="btn btn-secondary btn-sm" type="button" onclick="openBranchPrices(<?= (int) $mb['id'] ?>)">Set prices · <?= e($mb['code']) ?></button><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Item</th><?php foreach (branches_all() as $b): ?><th class="num"><?= e($b['name']) ?></th><?php endforeach; ?><th class="num">Total stock</th></tr></thead>
    <tbody>
    <?php foreach ($variants as $vv): $tot = 0; ?>
      <tr>
        <td class="strong"><?= e($vv['variant_label'] ?: 'Standard') ?></td>
        <?php foreach (branches_all() as $b): $cell = $matrix[(int) $vv['id']][(int) $b['id']] ?? null; $tot += (int) ($cell['stock_qty'] ?? 0); ?>
          <td class="num"><div class="strong"><?= format_money($cell['price'] ?? 0) ?></div>
            <div class="cell-sub"><?= (int) ($cell['stock_qty'] ?? 0) ?> in stock<?= (int) ($cell['damaged_qty'] ?? 0) ? ' · ' . (int) $cell['damaged_qty'] . ' dmg' : '' ?><?= $seeCost ? ' · cost ' . number_format((float) ($cell['cost_price'] ?? 0), 2) : '' ?></div></td>
        <?php endforeach; ?>
        <td class="num strong"><?= $tot ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php if (can('products.manage')): ?>
<div class="modal-overlay" id="branchPriceModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3>Selling prices — <span id="bp_name"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="branch_prices"><input type="hidden" name="branch_id" id="bp_branch">
      <p class="text-muted text-sm">Each branch sets its own prices. Changes are recorded in the audit log.</p>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Item</th><th class="num">Price</th><th class="num">Reorder at</th></tr></thead><tbody>
        <?php foreach ($variants as $vv): ?><tr><td class="strong"><?= e($vv['variant_label'] ?: 'Standard') ?></td>
          <td><input class="form-control sm bp-price" type="number" step="0.01" min="0.01" name="price[<?= (int) $vv['id'] ?>]" data-v="<?= (int) $vv['id'] ?>" required></td>
          <td><input class="form-control sm bp-re" type="number" min="0" name="reorder[<?= (int) $vv['id'] ?>]" data-v="<?= (int) $vv['id'] ?>"></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save prices</button></div>
    </form>
  </div>
</div>
<script>
const BRANCH_MATRIX = <?= json_encode($matrix, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const BRANCH_NAMES = <?= json_encode(array_map(fn($b) => $b['name'], branches_all()), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function openBranchPrices(bid) {
  document.getElementById('bp_branch').value = bid;
  document.getElementById('bp_name').textContent = BRANCH_NAMES[bid];
  document.querySelectorAll('.bp-price').forEach((i) => { const r = (BRANCH_MATRIX[i.dataset.v] || {})[bid]; i.value = r ? r.price : ''; });
  document.querySelectorAll('.bp-re').forEach((i) => { const r = (BRANCH_MATRIX[i.dataset.v] || {})[bid]; i.value = r ? r.reorder_level : ''; });
  document.getElementById('branchPriceModal').hidden = false;
}
</script>
<?php endif; endif; ?>

<nav class="subnav">
  <a class="subnav-item <?= $tab === 'movements' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=movements"><?= icon('history', 15) ?> Stock movements</a>
  <a class="subnav-item <?= $tab === 'history' ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=history"><?= icon('file-clock', 15) ?> Price & change history</a>
</nav>
<div class="card flush">
<?php if ($tab === 'history'): ?>
  <ul class="timeline" style="padding:8px 20px;">
    <?php foreach ($history as $h): ?>
      <li><span class="t-dot"></span><div><div><?= e($h['details']) ?></div><div class="cell-sub"><?= e($h['by_name'] ?? 'System') ?> · <?= e(fmt_datetime($h['created_at'])) ?> · <?= e(str_replace('_', ' ', $h['action'])) ?></div></div></li>
    <?php endforeach; ?>
    <li><span class="t-dot" style="background:var(--border-strong)"></span><div><div>Product created</div><div class="cell-sub"><?= e($product['created_name'] ?? 'System') ?> · <?= e(fmt_datetime($product['created_at'])) ?></div></div></li>
  </ul>
<?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Reference</th><?= branch_th() ?><th class="num">Change</th><th class="num">Balance</th><th>By</th></tr></thead>
    <tbody>
    <?php foreach ($movements as $m): [$ml, $mtone] = $mt[$m['type']] ?? [ucfirst($m['type']), 'neutral']; ?>
      <tr>
        <td class="nowrap text-secondary"><?= e(fmt_datetime($m['created_at'])) ?></td>
        <td><?= e($m['variant_label'] ?: 'Standard') ?></td>
        <td><span class="badge badge-<?= $mtone ?>"><?= e($ml) ?></span><?= $m['bucket'] === 'damaged' ? ' <span class="badge badge-neutral">damaged stock</span>' : '' ?></td>
        <td class="text-secondary text-sm"><?= e($m['note'] ?? '') ?></td>
        <?= branch_td((int) $m['branch_id']) ?>
        <td class="num strong <?= $m['change_qty'] > 0 ? 'text-success' : 'text-error' ?>"><?= $m['change_qty'] > 0 ? '+' : '' ?><?= (int) $m['change_qty'] ?></td>
        <td class="num"><?= $m['balance_after'] !== null ? (int) $m['balance_after'] : '—' ?></td>
        <td class="text-secondary text-sm"><?= e($m['by_name'] ?? '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$movements) echo empty_state('history', 'No stock movements yet'); ?></div>
  <?php if ($movements): ?><div class="pagination"><span class="text-muted">Showing the latest <?= count($movements) ?> movements</span><a class="link" href="<?= e(app_url('/inventory/movements.php?product_id=' . $id)) ?>">Full movement history →</a></div><?php endif; ?>
<?php endif; ?>
</div>

<?php if (can('products.manage')): ?>
<div class="modal-overlay" id="editVariantModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3>Edit item <span class="text-muted" id="ev_label"></span></h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="update_variant"><input type="hidden" name="variant_id" id="ev_id">
      <div class="form-row">
        <div class="form-group"><label>Product code</label><input class="form-control mono" type="text" name="sku" id="ev_sku" required maxlength="60" style="text-transform:uppercase;"></div>
        <div class="form-group"><label>Barcode</label><input class="form-control mono" type="text" name="barcode" id="ev_barcode" required maxlength="60"></div>
      </div>
      <?php if (current_branch_id() !== null): ?>
      <div class="form-row">
        <div class="form-group"><label>Selling price <span class="label-opt"><?= e(branch_scope_label()) ?></span></label><input class="form-control" type="number" step="0.01" min="0.01" name="price" id="ev_price" required></div>
        <?php if ($seeCost): ?><div class="form-group"><label>Cost price</label><input class="form-control" type="number" step="0.01" min="0" name="cost_price" id="ev_cost" required></div><?php endif; ?>
        <div class="form-group"><label>Reorder level</label><input class="form-control" type="number" min="0" name="reorder_level" id="ev_reorder"></div>
      </div>
      <?php else: ?>
      <div class="callout text-sm"><?= icon('building-2', 14) ?> Prices and reorder levels are per branch — use <strong>Set prices</strong> in the “Prices &amp; stock by branch” card, or pick a branch in the top bar.</div>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="is_active" id="ev_active"> Active (can be sold and purchased)</label>
      <div class="callout mt-3 text-sm"><?= icon('shield-check', 14) ?> Price and code changes are recorded in the audit log with your name.</div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save item</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addVariantModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3>Add variant</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_variant">
      <div class="form-row">
        <?php foreach ($attributes as $attr): ?>
          <div class="form-group"><label><?= e($attr['name']) ?></label>
            <select class="form-control" name="attribute_values[]"><option value="">— None —</option><?php foreach ($valuesByAttr[$attr['id']] ?? [] as $v): ?><option value="<?= (int) $v['id'] ?>"><?= e($v['value']) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Product code <span class="label-opt">(blank = auto)</span></label><input class="form-control mono" type="text" name="sku" maxlength="60"></div>
        <div class="form-group"><label>Barcode <span class="label-opt">(blank = auto)</span></label><input class="form-control mono" type="text" name="barcode" maxlength="60"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Selling price</label><input class="form-control" type="number" step="0.01" min="0.01" name="price" required value="<?= e($variants[0]['price'] ?? '') ?>"></div>
        <div class="form-group"><label>Cost price</label><input class="form-control" type="number" step="0.01" min="0" name="cost_price" required value="<?= $seeCost ? e($variants[0]['cost_price'] ?? '') : '' ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Reorder level</label><input class="form-control" type="number" min="0" name="reorder_level" value="<?= e($variants[0]['reorder_level'] ?? '10') ?>"></div>
        <div class="form-group"><label>Opening stock <span class="label-opt"><?= e(branch_scope_label()) ?></span></label><input class="form-control" type="number" min="0" name="initial_stock" value="0"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Add variant</button></div>
    </form>
  </div>
</div>
<script>
function openEditVariant(v) {
  document.getElementById('ev_id').value = v.id;
  document.getElementById('ev_label').textContent = v.variant_label ? '— ' + v.variant_label : '';
  document.getElementById('ev_sku').value = v.sku;
  document.getElementById('ev_barcode').value = v.barcode;
  const p = document.getElementById('ev_price'); if (p) p.value = v.price;
  const c = document.getElementById('ev_cost'); if (c) c.value = v.cost_price;
  const r = document.getElementById('ev_reorder'); if (r) r.value = v.reorder_level;
  document.getElementById('ev_active').checked = v.is_active == 1;
  document.getElementById('editVariantModal').hidden = false;
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
