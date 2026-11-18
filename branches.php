<?php
/** Branch management (owner): create, edit and deactivate branches. */
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('branches.manage');

$pdo = db();
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    $id = input_int($_POST, 'id');

    if ($action === 'toggle') {
        $b = branch_row($id);
        if (!$b) { flash('error', 'Branch not found.'); }
        elseif ((int) $b['is_main']) { flash('error', 'The main branch cannot be deactivated.'); }
        else {
            // Do not close a branch that still holds stock, money in transit or unpaid supplier bills.
            if ((int) $b['is_active']) {
                $stock = (int) $pdo->query("SELECT COALESCE(SUM(stock_qty + damaged_qty),0) FROM branch_stock WHERE branch_id = $id")->fetchColumn();
                $transit = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit' AND (from_branch_id = $id OR to_branch_id = $id)")->fetchColumn();
                if ($stock > 0 || $transit > 0) {
                    flash('error', 'Move out the remaining stock (' . $stock . ' unit(s)) and finish transfers in transit before deactivating this branch.');
                    redirect('/branches.php');
                }
            }
            $pdo->prepare('UPDATE branches SET is_active = 1 - is_active WHERE id = :id')->execute([':id' => $id]);
            log_activity('branch_toggle', ($b['is_active'] ? 'Deactivated' : 'Reactivated') . ' branch ' . $b['name'], 'branch', $id);
            flash('success', $b['name'] . ($b['is_active'] ? ' deactivated.' : ' reactivated.'));
        }
        redirect('/branches.php');
    }

    $f = [
        'name' => input($_POST, 'name'),
        'code' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', input($_POST, 'code'))),
        'address' => input($_POST, 'address') ?: null,
        'phone' => input($_POST, 'phone') ?: null,
        'email' => input($_POST, 'email') ?: null,
        'receipt_footer' => input($_POST, 'receipt_footer') ?: null,
    ];
    if ($f['name'] === '') $errors[] = 'Branch name is required.';
    if (strlen($f['code']) < 2 || strlen($f['code']) > 6) $errors[] = 'Branch code must be 2–6 letters or digits (e.g. COL, KDY). It prefixes the branch\'s invoice numbers.';
    if ($f['email'] && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    $dup = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE code = :c AND id <> :id');
    $dup->execute([':c' => $f['code'], ':id' => $id]);
    if ((int) $dup->fetchColumn()) $errors[] = 'Another branch already uses the code ' . $f['code'] . '.';

    if (!$errors && $action === 'create') {
        $pdo->prepare('INSERT INTO branches (code, name, address, phone, email, receipt_footer) VALUES (:code,:name,:address,:phone,:email,:receipt_footer)')
            ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($f)), $f));
        $newId = (int) $pdo->lastInsertId();
        log_activity('branch_create', 'Opened branch ' . $f['name'] . ' (' . $f['code'] . ')', 'branch', $newId);
        flash('success', 'Branch ' . $f['name'] . ' created. Add staff to it under Staff & Roles, then transfer or receive stock.');
        redirect('/branches.php');
    } elseif (!$errors && $action === 'update' && branch_row($id)) {
        $old = branch_row($id);
        // The main branch keeps its code: its documents carry no prefix and existing numbers must stay valid.
        if ((int) $old['is_main']) $f['code'] = $old['code'];
        $pdo->prepare('UPDATE branches SET code=:code, name=:name, address=:address, phone=:phone, email=:email, receipt_footer=:receipt_footer WHERE id=:id')
            ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($f)), $f) + [':id' => $id]);
        $changes = [];
        foreach ($f as $k => $v) { if ((string) $old[$k] !== (string) $v) $changes[] = "$k: \"{$old[$k]}\" → \"$v\""; }
        log_activity('branch_update', 'Updated branch ' . $old['name'] . ($changes ? ' — ' . implode('; ', $changes) : ''), 'branch', $id);
        flash('success', 'Branch updated.');
        redirect('/branches.php');
    }
}

$branches = $pdo->query("SELECT b.*,
    (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.id AND u.is_active = 1 AND u.all_branches = 0) AS staff,
    (SELECT COALESCE(SUM(bs.stock_qty),0) FROM branch_stock bs WHERE bs.branch_id = b.id) AS units,
    (SELECT COALESCE(SUM(GREATEST(bs.stock_qty,0) * COALESCE(bs.cost_price,0)),0) FROM branch_stock bs WHERE bs.branch_id = b.id) AS stock_value,
    (SELECT COALESCE(SUM(s.total_amount),0) FROM sales s WHERE s.branch_id = b.id AND s.status IN " . SALE_COUNTED . " AND s.sale_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_sales
    FROM branches b ORDER BY b.is_main DESC, b.is_active DESC, b.name")->fetchAll();

$pageTitle = 'Branches';
require __DIR__ . '/includes/header.php';
page_header('Branches', 'Each branch has its own stock, selling prices, cash drawer, bank account, expenses and staff. Products, suppliers and customers are shared.',
    '<button class="btn btn-primary" type="button" onclick="openBranch()">' . icon('plus', 16) . ' New branch</button>');
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Branch</th><th>Code</th><th>Contact</th><th class="num">Staff</th><th class="num">Stock units</th><th class="num">Stock value</th><th class="num">Sales this month</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($branches as $b): ?>
    <tr class="<?= $b['is_active'] ? '' : 'row-muted' ?>">
      <td><span class="strong"><?= e($b['name']) ?></span><?= (int) $b['is_main'] ? ' <span class="badge badge-orange">Main</span>' : '' ?><?= $b['address'] ? '<div class="cell-sub">' . e($b['address']) . '</div>' : '' ?></td>
      <td class="mono"><?= e($b['code']) ?><div class="cell-sub"><?= (int) $b['is_main'] ? 'INV-000001' : e($b['code']) . '-INV-000001' ?></div></td>
      <td class="text-secondary text-sm"><?= e($b['phone'] ?? '') ?><?= $b['email'] ? '<div class="cell-sub">' . e($b['email']) . '</div>' : '' ?></td>
      <td class="num"><?= (int) $b['staff'] ?></td>
      <td class="num"><?= number_format((int) $b['units']) ?></td>
      <td class="num"><?= format_money_short($b['stock_value']) ?></td>
      <td class="num strong"><?= format_money_short($b['month_sales']) ?></td>
      <td><?= status_badge($b['is_active'] ? 'active' : 'inactive') ?></td>
      <td class="actions">
        <button class="btn btn-secondary btn-sm" type="button" onclick='openBranch(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
        <?php if (!(int) $b['is_main']): ?><form method="post" class="inline" data-confirm="<?= $b['is_active'] ? 'Deactivate this branch?' : 'Reactivate this branch?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-<?= $b['is_active'] ? 'danger-outline' : 'secondary' ?> btn-sm" type="submit"><?= $b['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>

<div class="callout mt-4 text-sm"><?= icon('info', 14) ?> <strong>How branches work.</strong> Staff are tied to one branch and only see that branch's sales, stock and money. Owners (and anyone you tick “can work in every branch”) switch branches from the top bar, or choose <em>All branches</em> for combined reports. Move stock between branches with <a class="link" href="<?= e(app_url('/inventory/transfers.php')) ?>">Branch transfers</a>. Each branch sets its own selling prices on the product page and pays its own suppliers.</div>

<div class="modal-overlay" id="branchModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3 id="bTitle">New branch</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post" id="branchForm">
      <?= csrf_field() ?><input type="hidden" name="action" id="b_action" value="create"><input type="hidden" name="id" id="b_id">
      <div class="form-row">
        <div class="form-group"><label>Branch name</label><input class="form-control" type="text" name="name" id="b_name" required maxlength="120" placeholder="e.g. Kandy City Centre"></div>
        <div class="form-group"><label>Code</label><input class="form-control mono" type="text" name="code" id="b_code" required maxlength="6" style="text-transform:uppercase" placeholder="KDY"><div class="form-hint">Prefixes invoice numbers: KDY-INV-000001.</div></div>
      </div>
      <div class="form-group"><label>Address <span class="label-opt">(printed on receipts)</span></label><input class="form-control" type="text" name="address" id="b_address" maxlength="255"></div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" type="text" name="phone" id="b_phone" maxlength="40"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="b_email" maxlength="120"></div>
      </div>
      <div class="form-group"><label>Receipt footer <span class="label-opt">(blank = company default)</span></label><input class="form-control" type="text" name="receipt_footer" id="b_footer" maxlength="255"></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save branch</button></div>
    </form>
  </div>
</div>
<script>
function openBranch(b) {
  document.getElementById('branchForm').reset();
  document.getElementById('bTitle').textContent = b ? 'Edit ' + b.name : 'New branch';
  document.getElementById('b_action').value = b ? 'update' : 'create';
  document.getElementById('b_id').value = b ? b.id : '';
  if (b) {
    document.getElementById('b_name').value = b.name || ''; document.getElementById('b_code').value = b.code || '';
    document.getElementById('b_code').readOnly = b.is_main == 1;
    document.getElementById('b_address').value = b.address || ''; document.getElementById('b_phone').value = b.phone || '';
    document.getElementById('b_email').value = b.email || ''; document.getElementById('b_footer').value = b.receipt_footer || '';
  } else document.getElementById('b_code').readOnly = false;
  document.getElementById('branchModal').hidden = false;
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
