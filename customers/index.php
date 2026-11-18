<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.view');

$pdo = db();
$errors = [];

if (is_post() && input($_POST, 'action') === 'create') {
    verify_csrf();
    require_permission('customers.create');
    $name = input($_POST, 'name');
    $phone = input($_POST, 'phone');
    $email = input($_POST, 'email');
    $address = input($_POST, 'address');
    $allowCredit = can('customers.edit') ? (isset($_POST['allow_credit']) ? 1 : 0) : 1;
    $limitRaw = input($_POST, 'credit_limit');
    $limit = can('customers.edit') && $limitRaw !== '' ? max(0, (float) $limitRaw) : null;

    if ($name === '') $errors[] = 'Customer name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($phone !== '') {
        $d = $pdo->prepare('SELECT name FROM customers WHERE phone = :p');
        $d->execute([':p' => $phone]);
        if ($dn = $d->fetchColumn()) $errors[] = 'Phone ' . $phone . ' is already registered to ' . $dn . '.';
    }
    if (!$errors) {
        $pdo->prepare('INSERT INTO customers (name, phone, email, address, allow_credit, credit_limit, created_by) VALUES (:n,:p,:e,:a,:ac,:cl,:u)')
            ->execute([':n' => $name, ':p' => $phone ?: null, ':e' => $email ?: null, ':a' => $address ?: null, ':ac' => $allowCredit, ':cl' => $limit, ':u' => current_user()['id']]);
        $newId = (int) $pdo->lastInsertId();
        log_activity('customer_create', 'Registered customer ' . $name . ($phone ? ' (' . $phone . ')' : ''), 'customer', $newId);
        flash('success', 'Customer ' . $name . ' registered.');
        redirect('/customers/view.php?id=' . $newId);
    }
}

$q = input($_GET, 'q');
$filter = input($_GET, 'filter');
$page = max(1, input_int($_GET, 'page', 1));
$perPage = 50;
$where = '1=1';
$params = [];
if ($q !== '') { $where .= ' AND (c.name LIKE :q1 OR c.phone LIKE :q2 OR c.email LIKE :q3)'; $params += [':q1' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%"]; }
if ($filter === 'owing') $where .= ' AND c.credit_balance > 0';
if ($filter === 'credit') $where .= ' AND c.credit_balance < 0';
if ($filter === 'inactive') $where .= ' AND c.is_active = 0';
elseif ($filter !== 'all') $where .= ' AND c.is_active = 1';

$cnt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $where");
$cnt->execute($params);
$total = (int) $cnt->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT c.*, st.visits, st.spent, st.last_visit
     FROM customers c
     LEFT JOIN (SELECT customer_id, COUNT(*) AS visits, SUM(total_amount) AS spent, MAX(sale_date) AS last_visit FROM sales WHERE status IN " . SALE_COUNTED . " AND customer_id IS NOT NULL GROUP BY customer_id) st ON st.customer_id = c.id
     WHERE $where ORDER BY " . ($filter === 'owing' ? 'c.credit_balance DESC' : 'c.name') . " LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$customers = $stmt->fetchAll();

$kpi = $pdo->query("SELECT COUNT(*) AS n, SUM(CASE WHEN credit_balance > 0 THEN 1 ELSE 0 END) AS owing, COALESCE(SUM(CASE WHEN credit_balance > 0 THEN credit_balance END),0) AS owed,
    SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS new_month FROM customers WHERE is_active = 1")->fetch();

$pageTitle = 'Customers';
require __DIR__ . '/../includes/header.php';
page_header('Customers', 'Registered customers, their purchase history and credit balances. Walk-in sales never require registration.',
    can('customers.create') ? '<button class="btn btn-primary" type="button" data-modal-open="#customerModal">' . icon('user-plus', 16) . ' New customer</button>' : '');
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>

<div class="grid grid-4 mb-4">
  <?= kpi('Registered customers', number_format((int) $kpi['n']), 'contact', 'blue') ?>
  <?= kpi('New this month', number_format((int) $kpi['new_month']), 'user-plus', 'green') ?>
  <?= kpi('Customers owing', number_format((int) $kpi['owing']), 'notebook-pen', 'amber', 'Buying on credit', '/customers/index.php?filter=owing') ?>
  <?= kpi('Total receivable', format_money($kpi['owed']), 'hand-coins', 'red', 'Owed to the shop', '/customers/index.php?filter=owing') ?>
</div>

<div class="card flush">
  <div style="padding:16px 16px 0;">
    <form method="get" class="filter-bar">
      <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, phone or email"></div>
      <div class="chip-group">
        <?php foreach (['' => 'Active', 'owing' => 'Owes money', 'credit' => 'Has store credit', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?>
          <a class="chip <?= $filter === $k ? 'active' : '' ?>" href="<?= e(query_with(['filter' => $k ?: null, 'page' => null])) ?>"><?= e($l) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    </form>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Customer</th><th>Phone</th><th class="num">Visits</th><th class="num">Total spent</th><th>Last purchase</th><th class="num">Balance</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($customers as $c): $bal = (float) $c['credit_balance']; ?>
        <tr class="<?= $c['is_active'] ? '' : 'row-muted' ?>">
          <td><a class="strong" href="<?= e(app_url('/customers/view.php?id=' . $c['id'])) ?>"><?= e($c['name']) ?></a><?php if ($c['email']): ?><div class="cell-sub"><?= e($c['email']) ?></div><?php endif; ?></td>
          <td class="text-secondary"><?= e($c['phone'] ?? '—') ?></td>
          <td class="num"><?= (int) $c['visits'] ?></td>
          <td class="num"><?= format_money($c['spent'] ?? 0) ?></td>
          <td class="text-secondary nowrap"><?= $c['last_visit'] ? e(fmt_date($c['last_visit'])) : '—' ?></td>
          <td class="num"><?= $bal > 0 ? '<span class="badge badge-warning">Owes ' . format_money($bal) . '</span>' : ($bal < 0 ? '<span class="badge badge-success">Credit ' . format_money(-$bal) . '</span>' : '<span class="text-muted">—</span>') ?></td>
          <td class="actions"><a class="btn btn-secondary btn-sm" href="<?= e(app_url('/customers/view.php?id=' . $c['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$customers) echo empty_state('contact', 'No customers found', $q ? 'Try a different search.' : 'Register customers here or directly from the POS.'); ?>
  </div>
  <?php render_pagination($page, $perPage, $total); ?>
</div>

<?php if (can('customers.create')): ?>
<div class="modal-overlay" id="customerModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3>Register customer</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="form-group"><label>Full name</label><input class="form-control" type="text" name="name" required maxlength="150" value="<?= e(input($_POST, 'name')) ?>"></div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" type="tel" name="phone" maxlength="30" placeholder="07X XXX XXXX" value="<?= e(input($_POST, 'phone')) ?>"></div>
        <div class="form-group"><label>Email <span class="label-opt">(optional)</span></label><input class="form-control" type="email" name="email" maxlength="150" value="<?= e(input($_POST, 'email')) ?>"></div>
      </div>
      <div class="form-group"><label>Address <span class="label-opt">(optional)</span></label><input class="form-control" type="text" name="address" maxlength="255" value="<?= e(input($_POST, 'address')) ?>"></div>
      <?php if (can('customers.edit')): ?>
      <div class="form-section-title mt-2">Credit</div>
      <div class="form-row items-center">
        <label class="check"><input type="checkbox" name="allow_credit" checked> Allow buying on credit</label>
        <div class="form-group mb-0"><label>Credit limit <span class="label-opt">(blank = no limit)</span></label><input class="form-control" type="number" step="0.01" min="0" name="credit_limit"></div>
      </div>
      <?php endif; ?>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Register</button></div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
