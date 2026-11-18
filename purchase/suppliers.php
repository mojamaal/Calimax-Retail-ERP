<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('suppliers.view');

$pdo = db();
if (is_post()) {
    verify_csrf();
    require_permission('suppliers.manage');
    $action = input($_POST, 'action');
    $f = [
        'name' => input($_POST, 'name'), 'business_reg_no' => input($_POST, 'business_reg_no') ?: null, 'tax_id' => input($_POST, 'tax_id') ?: null,
        'contact_person' => input($_POST, 'contact_person') ?: null, 'phone' => input($_POST, 'phone') ?: null, 'email' => input($_POST, 'email') ?: null,
        'address' => input($_POST, 'address') ?: null, 'payment_terms_days' => max(0, input_int($_POST, 'payment_terms_days')), 'notes' => input($_POST, 'notes') ?: null,
    ];
    if ($action === 'toggle') {
        $id = input_int($_POST, 'id');
        $pdo->prepare('UPDATE suppliers SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
        log_activity('supplier_toggle', 'Changed active status of supplier #' . $id, 'supplier', $id);
        flash('success', 'Supplier status updated.');
    } elseif ($f['name'] === '') {
        flash('error', 'Supplier name is required.');
    } elseif ($f['email'] && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid email address.');
    } elseif ($action === 'create') {
        $pdo->prepare('INSERT INTO suppliers (name, business_reg_no, tax_id, contact_person, phone, email, address, payment_terms_days, notes) VALUES (:name,:business_reg_no,:tax_id,:contact_person,:phone,:email,:address,:payment_terms_days,:notes)')
            ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($f)), $f));
        $newId = (int) $pdo->lastInsertId();
        log_activity('supplier_create', 'Registered supplier ' . $f['name'], 'supplier', $newId);
        flash('success', 'Supplier ' . $f['name'] . ' added.');
        redirect('/purchase/supplier_view.php?id=' . $newId);
    } elseif ($action === 'update') {
        $id = input_int($_POST, 'id');
        $pdo->prepare('UPDATE suppliers SET name=:name, business_reg_no=:business_reg_no, tax_id=:tax_id, contact_person=:contact_person, phone=:phone, email=:email, address=:address, payment_terms_days=:payment_terms_days, notes=:notes WHERE id=:id')
            ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($f)), $f) + [':id' => $id]);
        log_activity('supplier_update', 'Updated supplier details for ' . $f['name'], 'supplier', $id);
        flash('success', 'Supplier updated.');
        redirect('/purchase/supplier_view.php?id=' . $id);
    }
    redirect('/purchase/suppliers.php');
}

$q = input($_GET, 'q');
$filter = input($_GET, 'filter');
$where = $filter === 'inactive' ? 's.is_active = 0' : 's.is_active = 1';
$params = [];
$owedSql = supplier_balance_sql('s');   // what THIS branch owes (all branches combined in the consolidated view)
if ($filter === 'owed') $where .= " AND $owedSql > 0";
if ($q !== '') { $where .= ' AND (s.name LIKE :q1 OR s.contact_person LIKE :q2 OR s.phone LIKE :q3)'; $params = [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
$stmt = $pdo->prepare("SELECT s.*, $owedSql AS scope_balance,
    (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.id AND status IN ('ordered','partially_received')" . branch_and() . ") AS open_pos,
    (SELECT MAX(g.received_date) FROM goods_received_notes g WHERE g.po_id IN (SELECT po.id FROM purchase_orders po WHERE po.supplier_id = s.id)" . branch_and('g') . ") AS last_delivery,
    (SELECT COALESCE(SUM(g.total_amount),0) FROM goods_received_notes g JOIN purchase_orders po ON po.id = g.po_id WHERE po.supplier_id = s.id AND g.received_date >= DATE_FORMAT(CURDATE(), '%Y-01-01')" . branch_and('g') . ") AS ytd
    FROM suppliers s WHERE $where ORDER BY " . ($filter === 'owed' ? 'scope_balance DESC' : 's.name'));
$stmt->execute($params);
$suppliers = $stmt->fetchAll();
$totalOwed = payables_total();

$pageTitle = 'Suppliers';
require __DIR__ . '/../includes/header.php';
page_header('Purchasing', 'Who supplies your products, how to reach them and how much you owe each one.', can('suppliers.manage') ? '<button class="btn btn-primary" type="button" onclick="openSupplier()">' . icon('plus', 16) . ' New supplier</button>' : '');
nav_purchase();
?>
<div class="grid grid-3 mb-4">
  <?= kpi('Active suppliers', (string) count(array_filter($suppliers, fn($s) => $s['is_active'])), 'building-2', 'blue') ?>
  <?= kpi('Total owed to suppliers', format_money($totalOwed), 'file-clock', 'red', 'Accounts payable', '/purchase/suppliers.php?filter=owed') ?>
  <?= kpi('Open orders', (string) array_sum(array_column($suppliers, 'open_pos')), 'truck', 'amber', 'Awaiting delivery', '/purchase/orders.php?status=ordered') ?>
</div>
<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar">
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Supplier, contact or phone"></div>
    <div class="chip-group"><?php foreach (['' => 'Active', 'owed' => 'We owe money', 'inactive' => 'Inactive'] as $k => $l): ?><a class="chip <?= $filter === $k ? 'active' : '' ?>" href="<?= e(query_with(['filter' => $k ?: null])) ?>"><?= $l ?></a><?php endforeach; ?></div>
  </form></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th>Terms</th><th>Last delivery</th><th class="num">Purchased (YTD)</th><th class="num">We owe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
      <tr>
        <td><a class="strong" href="<?= e(app_url('/purchase/supplier_view.php?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a><?= $s['business_reg_no'] ? '<div class="cell-sub">BR ' . e($s['business_reg_no']) . '</div>' : '' ?></td>
        <td class="text-secondary"><?= e($s['contact_person'] ?? '—') ?></td>
        <td class="text-secondary"><?= e($s['phone'] ?? '—') ?></td>
        <td class="text-secondary"><?= (int) $s['payment_terms_days'] ? (int) $s['payment_terms_days'] . ' days' : 'Cash / COD' ?></td>
        <td class="text-secondary nowrap"><?= e(fmt_date($s['last_delivery'])) ?></td>
        <td class="num"><?= format_money($s['ytd']) ?></td>
        <td class="num"><?= (float) $s['scope_balance'] > 0 ? '<span class="badge badge-warning">' . format_money($s['scope_balance']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/purchase/supplier_view.php?id=' . $s['id'])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$suppliers) echo empty_state('building-2', 'No suppliers found', 'Register the businesses you buy stock from.'); ?></div>
</div>
<?php require __DIR__ . '/_supplier_modal.php'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
