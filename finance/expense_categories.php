<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('expenses.manage');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    if ($action === 'create' && ($name = input($_POST, 'name')) !== '') {
        $pdo->prepare('INSERT IGNORE INTO expense_categories (name) VALUES (:n)')->execute([':n' => $name]);
        log_activity('expense_category_create', 'Added expense category ' . $name);
        flash('success', 'Category added.');
    } elseif ($action === 'rename' && ($name = input($_POST, 'name')) !== '') {
        $pdo->prepare('UPDATE expense_categories SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => input_int($_POST, 'id')]);
        flash('success', 'Category renamed.');
    } elseif ($action === 'delete') {
        $id = input_int($_POST, 'id');
        $c = $pdo->prepare('SELECT COUNT(*) FROM expenses WHERE category_id = :id');
        $c->execute([':id' => $id]);
        if ((int) $c->fetchColumn() > 0) flash('error', 'This category has expenses recorded against it and cannot be deleted.');
        else { $pdo->prepare('DELETE FROM expense_categories WHERE id = :id')->execute([':id' => $id]); log_activity('expense_category_delete', 'Deleted expense category #' . $id); flash('success', 'Category deleted.'); }
    }
    redirect('/finance/expense_categories.php');
}
$categories = $pdo->query("SELECT ec.*, COUNT(e.id) AS n, COALESCE(SUM(CASE WHEN e.expense_date >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN e.amount END),0) AS ytd FROM expense_categories ec LEFT JOIN expenses e ON e.category_id = ec.id GROUP BY ec.id ORDER BY ec.name")->fetchAll();

$pageTitle = 'Expense Categories';
require __DIR__ . '/../includes/header.php';
page_header('Finance', 'Categories used to group expenses on reports.');
nav_finance();
?>
<div class="card mb-4"><form method="post" class="flex gap-2 flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input class="form-control" style="max-width:320px;" type="text" name="name" required maxlength="100" placeholder="New category name"><button class="btn btn-primary" type="submit"><?= icon('plus', 16) ?> Add</button></form></div>
<div class="card flush"><div class="table-wrap"><table class="data-table">
  <thead><tr><th>Category</th><th class="num">Entries</th><th class="num">This year</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($categories as $c): ?>
    <tr>
      <td><form method="post" class="flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input class="form-control" style="max-width:280px;" name="name" value="<?= e($c['name']) ?>" maxlength="100"><button class="btn btn-secondary btn-sm" type="submit">Rename</button></form></td>
      <td class="num"><?= (int) $c['n'] ?></td>
      <td class="num"><?= format_money($c['ytd']) ?></td>
      <td class="actions"><form method="post" class="inline" data-confirm="Delete this category?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger-outline btn-sm" type="submit" <?= $c['n'] ? 'disabled title="Has expenses"' : '' ?>>Delete</button></form></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
