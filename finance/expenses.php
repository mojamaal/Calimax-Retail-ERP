<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('finance.view');

$pdo = db();
$errors = [];

if (is_post()) {
    verify_csrf();
    require_permission('expenses.manage');
    $action = input($_POST, 'action');
    if ($action === 'delete') {
        $id = input_int($_POST, 'id');
        $old = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
        $old->execute([':id' => $id]);
        if ($row = $old->fetch()) {
            assert_branch_access((int) $row['branch_id']);
            $pdo->prepare('DELETE FROM expenses WHERE id = :id')->execute([':id' => $id]);
            log_activity('expense_delete', 'Deleted expense "' . $row['description'] . '" ' . format_money($row['amount']) . ' dated ' . $row['expense_date'], 'expense', $id);
            flash('success', 'Expense deleted.');
        }
        redirect('/finance/expenses.php' . (input($_POST, 'return') ?: ''));
    }

    $id = input_int($_POST, 'id');
    $data = [
        'category_id' => input_int($_POST, 'category_id') ?: null,
        'description' => input($_POST, 'description'),
        'amount' => round(input_float($_POST, 'amount'), 2),
        'expense_date' => input_date($_POST, 'expense_date', date('Y-m-d')),
        'payment_method' => isset(money_methods()[input($_POST, 'payment_method')]) ? input($_POST, 'payment_method') : 'cash',
        'reference_no' => input($_POST, 'reference_no') ?: null,
    ];
    if ($data['description'] === '') $errors[] = 'Describe the expense.';
    if ($data['amount'] <= 0) $errors[] = 'Amount must be greater than zero.';
    if ($data['expense_date'] > date('Y-m-d')) $errors[] = 'Expense date cannot be in the future.';

    $attachment = null;
    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];
        $mime = $file['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
        if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'Attachment upload failed.';
        elseif ($file['size'] > MAX_UPLOAD_BYTES) $errors[] = 'Attachment must be smaller than 2MB.';
        elseif (!isset($allowed[$mime])) $errors[] = 'Only JPG, PNG or PDF receipts are allowed.';
        elseif (!$errors) {
            $newName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            if (!is_dir(UPLOAD_EXPENSE_DIR)) mkdir(UPLOAD_EXPENSE_DIR, 0755, true);
            if (move_uploaded_file($file['tmp_name'], UPLOAD_EXPENSE_DIR . $newName)) $attachment = $newName; else $errors[] = 'Could not save the attachment.';
        }
    }

    if (!$errors) {
        $catName = $data['category_id'] ? (string) $pdo->query('SELECT name FROM expense_categories WHERE id = ' . (int) $data['category_id'])->fetchColumn() : 'Uncategorized';
        if ($id) {
            $old = $pdo->prepare('SELECT * FROM expenses WHERE id = :id');
            $old->execute([':id' => $id]);
            $old = $old->fetch();
            if (!$old) { flash('error', 'Expense not found.'); redirect('/finance/expenses.php'); }
            assert_branch_access((int) $old['branch_id']);
            $changes = [];
            foreach (['description', 'amount', 'expense_date', 'payment_method', 'reference_no', 'category_id'] as $k) {
                if ((string) $old[$k] !== (string) ($k === 'amount' ? number_format($data[$k], 2, '.', '') : $data[$k])) $changes[] = "$k: {$old[$k]} → {$data[$k]}";
            }
            $pdo->prepare('UPDATE expenses SET category_id=:c, description=:d, amount=:a, expense_date=:ed, payment_method=:pm, reference_no=:ref' . ($attachment ? ', attachment=:att' : '') . ' WHERE id=:id')
                ->execute(array_merge([':c' => $data['category_id'], ':d' => $data['description'], ':a' => $data['amount'], ':ed' => $data['expense_date'], ':pm' => $data['payment_method'], ':ref' => $data['reference_no'], ':id' => $id], $attachment ? [':att' => $attachment] : []));
            log_activity('expense_update', 'Edited expense "' . $data['description'] . '" — ' . ($changes ? implode('; ', $changes) : 'no field changes') . ($attachment ? '; receipt replaced' : ''), 'expense', $id);
            flash('success', 'Expense updated.');
        } else {
            $expBranch = write_branch();   // each branch carries (and pays) its own operating costs
            $pdo->prepare('INSERT INTO expenses (branch_id, category_id, description, amount, expense_date, payment_method, reference_no, attachment, created_by) VALUES (:br,:c,:d,:a,:ed,:pm,:ref,:att,:u)')
                ->execute([':br' => $expBranch, ':c' => $data['category_id'], ':d' => $data['description'], ':a' => $data['amount'], ':ed' => $data['expense_date'], ':pm' => $data['payment_method'], ':ref' => $data['reference_no'], ':att' => $attachment, ':u' => current_user()['id']]);
            log_activity('expense_create', 'Recorded ' . $catName . ' expense "' . $data['description'] . '" ' . format_money($data['amount']) . ' (' . method_label($data['payment_method']) . ')', 'expense', (int) $pdo->lastInsertId());
            flash('success', 'Expense of ' . format_money($data['amount']) . ' recorded under ' . $catName . '.');
        }
        redirect('/finance/expenses.php');
    }
}

$period = resolve_report_period('this_month');
$categoryId = input_int($_GET, 'category_id');
$method = input($_GET, 'method');
$q = input($_GET, 'q');
$where = 'e.expense_date BETWEEN :f AND :t' . branch_and('e');
$params = [':f' => $period['from'], ':t' => $period['to']];
if ($categoryId) { $where .= ' AND e.category_id = :cat'; $params[':cat'] = $categoryId; }
if (isset(money_methods()[$method])) { $where .= ' AND e.payment_method = :m'; $params[':m'] = $method; }
if ($q !== '') { $where .= ' AND (e.description LIKE :q1 OR e.reference_no LIKE :q2)'; $params += [':q1' => "%$q%", ':q2' => "%$q%"]; }
$stmt = $pdo->prepare("SELECT e.*, ec.name AS category_name, u.full_name AS by_name FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id LEFT JOIN users u ON u.id = e.created_by WHERE $where ORDER BY e.expense_date DESC, e.id DESC LIMIT 500");
$stmt->execute($params);
$expenses = $stmt->fetchAll();
$total = array_sum(array_column($expenses, 'amount'));
$byCat = fin_expenses_by_category($period['from'], $period['to']);
$catTotal = array_sum(array_column($byCat, 'total')) ?: 1;
$categories = $pdo->query('SELECT id, name FROM expense_categories ORDER BY name')->fetchAll();
$sales = fin_sales($period['from'], $period['to']);

$pageTitle = 'Expenses';
require __DIR__ . '/../includes/header.php';
page_header('Finance', 'Operating costs — rent, electricity, water, internet, salaries, transport and maintenance.', can('expenses.manage') && current_branch_id() !== null ? '<button class="btn btn-primary" type="button" onclick="openExpense()">' . icon('plus', 16) . ' Record expense</button>' : '');
nav_finance();
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>
<div class="card tight mb-4"><?php render_period_filter($period, array_filter(['category_id' => $categoryId ?: '', 'method' => $method, 'q' => $q])); ?></div>
<div class="layout-2-1 mb-4">
  <div class="grid grid-3">
    <?= kpi('Expenses', format_money($total), 'receipt', 'red', count($expenses) . ' entries · ' . e($period['label'])) ?>
    <?= kpi('Largest category', e($byCat[0]['name'] ?? '—'), 'pie-chart', 'amber', $byCat ? format_money($byCat[0]['total']) : '') ?>
    <?= kpi('Expenses vs sales', $sales['net_ex_tax'] > 0 ? number_format($total / $sales['net_ex_tax'] * 100, 1) . '%' : '—', 'percent', 'blue', 'of net sales in period') ?>
  </div>
  <div class="card">
    <h4 class="mb-2">By category</h4>
    <?php foreach (array_slice($byCat, 0, 5) as $c): ?>
      <div class="hbar-row"><span><?= e($c['name']) ?></span><span class="hbar-track"><span class="hbar-fill" style="display:block;width:<?= round($c['total'] / $catTotal * 100) ?>%"></span></span><span class="hbar-val"><?= format_money_short($c['total']) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$byCat): ?><div class="text-muted text-sm">No expenses in this period.</div><?php endif; ?>
  </div>
</div>

<div class="card flush">
  <div style="padding:16px 16px 0;"><form method="get" class="filter-bar">
    <input type="hidden" name="preset" value="<?= e($period['preset'] ?: 'custom') ?>"><input type="hidden" name="from" value="<?= e($period['from']) ?>"><input type="hidden" name="to" value="<?= e($period['to']) ?>">
    <div class="search-input"><?= icon('search', 15) ?><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Description or reference"></div>
    <select class="form-control" name="category_id" onchange="this.form.submit()"><option value="">All categories</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
    <select class="form-control" name="method" onchange="this.form.submit()"><option value="">Any method</option><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  </form></div>
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Date</th><?= branch_th() ?><th>Description</th><th>Category</th><th>Paid by</th><th>Recorded by</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($expenses as $x): ?>
      <tr>
        <td class="text-secondary nowrap"><?= e(fmt_date($x['expense_date'])) ?></td>
        <?= branch_td((int) $x['branch_id']) ?>
        <td class="strong"><?= e($x['description']) ?><?php if ($x['attachment']): ?> <a href="<?= e(UPLOAD_EXPENSE_URL . $x['attachment']) ?>" target="_blank" title="View receipt" class="text-muted"><?= icon('paperclip', 13) ?></a><?php endif; ?><?= $x['reference_no'] ? '<div class="cell-sub">Ref ' . e($x['reference_no']) . '</div>' : '' ?></td>
        <td><span class="badge badge-neutral"><?= e($x['category_name'] ?? 'Uncategorized') ?></span></td>
        <td class="text-secondary"><?= e(method_label($x['payment_method'])) ?></td>
        <td class="text-secondary text-sm"><?= e($x['by_name'] ?? '—') ?></td>
        <td class="num strong"><?= format_money($x['amount']) ?></td>
        <td class="actions"><?php if (can('expenses.manage')): ?>
          <button class="btn btn-secondary btn-sm" type="button" onclick='openExpense(<?= json_encode($x, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
          <form method="post" class="inline" data-confirm="Delete this expense? This is recorded in the audit log."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>"><button class="btn btn-danger-outline btn-sm btn-icon" type="submit" title="Delete"><?= icon('trash-2', 14) ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($expenses): ?><tfoot><tr><td colspan="<?= show_branch_col() ? 6 : 5 ?>">Total</td><td class="num"><?= format_money($total) ?></td><td></td></tr></tfoot><?php endif; ?>
  </table>
  <?php if (!$expenses) echo empty_state('receipt', 'No expenses in this period', 'Record rent, bills and salaries so your profit is accurate.'); ?></div>
</div>

<?php if (can('expenses.manage')): ?>
<div class="modal-overlay" id="expenseModal" <?= $errors ? '' : 'hidden' ?>>
  <div class="modal">
    <div class="modal-header"><h3 id="expTitle">Record expense</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post" enctype="multipart/form-data" id="expenseForm">
      <?= csrf_field() ?><input type="hidden" name="id" id="ex_id">
      <div class="form-group"><label>Category</label>
        <div class="chip-group" id="catChips"><?php foreach ($categories as $c): ?><label class="chip"><input type="radio" name="category_id" value="<?= (int) $c['id'] ?>" class="sr-only"> <?= e($c['name']) ?></label><?php endforeach; ?></div></div>
      <div class="form-group"><label>Description</label><input class="form-control" type="text" name="description" id="ex_description" required maxlength="255" placeholder="e.g. CEB electricity bill — October"></div>
      <div class="form-row">
        <div class="form-group"><label>Amount</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" id="ex_amount" required></div>
        <div class="form-group"><label>Date</label><input class="form-control" type="date" name="expense_date" id="ex_expense_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Paid by</label><select class="form-control" name="payment_method" id="ex_payment_method"><?php foreach (money_methods() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Reference <span class="label-opt">(bill / cheque no.)</span></label><input class="form-control" type="text" name="reference_no" id="ex_reference_no" maxlength="60"></div>
      </div>
      <div class="form-group"><label>Receipt <span class="label-opt">(JPG, PNG or PDF)</span></label><input class="form-control" type="file" name="attachment" accept="image/png,image/jpeg,application/pdf"></div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save expense</button></div>
    </form>
  </div>
</div>
<script>
function openExpense(x) {
  const f = document.getElementById('expenseForm'); f.reset();
  document.getElementById('expTitle').textContent = x ? 'Edit expense' : 'Record expense';
  document.getElementById('ex_id').value = x ? x.id : '';
  ['description', 'amount', 'expense_date', 'payment_method', 'reference_no'].forEach((k) => { if (x) document.getElementById('ex_' + k).value = x[k] == null ? '' : x[k]; });
  document.querySelectorAll('#catChips input').forEach((r) => { r.checked = x && String(x.category_id) === r.value; });
  syncChips();
  document.getElementById('expenseModal').hidden = false;
}
function syncChips() { document.querySelectorAll('#catChips .chip').forEach((c) => c.classList.toggle('active', c.querySelector('input').checked)); }
document.getElementById('catChips').addEventListener('change', syncChips);
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
