<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.manage');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    if ($action === 'create' || $action === 'update') {
        $name = input($_POST, 'name');
        $parentId = input_int($_POST, 'parent_id') ?: null;
        $description = input($_POST, 'description');
        if ($name === '') {
            flash('error', 'Category name is required.');
        } elseif ($action === 'create') {
            $pdo->prepare('INSERT INTO categories (name, parent_id, description) VALUES (:n, :p, :d)')->execute([':n' => $name, ':p' => $parentId, ':d' => $description ?: null]);
            log_activity('category_create', 'Created category ' . $name, 'category', (int) $pdo->lastInsertId());
            flash('success', 'Category created.');
        } else {
            $id = input_int($_POST, 'id');
            if ($parentId === $id) $parentId = null;
            $pdo->prepare('UPDATE categories SET name=:n, parent_id=:p, description=:d, is_active=:a WHERE id=:id')
                ->execute([':n' => $name, ':p' => $parentId, ':d' => $description ?: null, ':a' => isset($_POST['is_active']) ? 1 : 0, ':id' => $id]);
            log_activity('category_update', 'Updated category ' . $name, 'category', $id);
            flash('success', 'Category updated.');
        }
    } elseif ($action === 'delete') {
        $id = input_int($_POST, 'id');
        $c = $pdo->prepare('SELECT (SELECT COUNT(*) FROM products WHERE category_id = :id) + (SELECT COUNT(*) FROM categories WHERE parent_id = :id2)');
        $c->execute([':id' => $id, ':id2' => $id]);
        if ((int) $c->fetchColumn() > 0) {
            flash('error', 'This category still has products or sub-categories. Move them first, or mark the category inactive.');
        } else {
            $n = $pdo->query('SELECT name FROM categories WHERE id = ' . $id)->fetchColumn();
            $pdo->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => $id]);
            log_activity('category_delete', 'Deleted category ' . $n, 'category', $id);
            flash('success', 'Category deleted.');
        }
    }
    redirect('/inventory/categories.php');
}

$categories = $pdo->query(
    'SELECT c.*, p.name AS parent_name, (SELECT COUNT(*) FROM products WHERE category_id = c.id AND is_active = 1) AS product_count,
            (SELECT COALESCE(SUM(pv.stock_qty * pv.cost_price),0) FROM ' . pv() . ' pv JOIN products pp ON pp.id = pv.product_id WHERE pp.category_id = c.id AND pv.is_active = 1) AS stock_value
     FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY COALESCE(p.name, c.name), c.parent_id IS NOT NULL, c.name'
)->fetchAll();

$pageTitle = 'Categories';
require __DIR__ . '/../includes/header.php';
page_header('Products', 'Group your catalog into departments, e.g. Beverages, Rice & Grains, Electronics, Cosmetics, Hardware.', '<button class="btn btn-primary" type="button" onclick="openCreateModal()">' . icon('plus', 16) . ' New category</button>');
nav_products();
?>
<div class="card flush">
  <div class="table-wrap"><table class="data-table">
    <thead><tr><th>Category</th><th>Description</th><th class="num">Active products</th><th class="num">Stock value</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($categories as $c): ?>
      <tr class="<?= $c['is_active'] ? '' : 'row-muted' ?>">
        <td class="strong"><?= $c['parent_id'] ? '<span class="text-muted">' . e($c['parent_name']) . ' / </span>' : '' ?><a href="<?= e(app_url('/inventory/products.php?category_id=' . $c['id'])) ?>"><?= e($c['name']) ?></a></td>
        <td class="text-secondary"><?= e($c['description'] ?? '') ?></td>
        <td class="num"><?= (int) $c['product_count'] ?></td>
        <td class="num"><?= format_money($c['stock_value']) ?></td>
        <td><?= status_badge($c['is_active'] ? 'active' : 'inactive') ?></td>
        <td class="actions">
          <button class="btn btn-secondary btn-sm" type="button" onclick='openEditModal(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'>Edit</button>
          <form method="post" class="inline" data-confirm="Delete this category?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="btn btn-danger-outline btn-sm" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$categories) echo empty_state('folder-tree', 'No categories yet'); ?></div>
</div>

<div class="modal-overlay" id="categoryModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3 id="modalTitle">New category</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post" id="categoryForm">
      <?= csrf_field() ?><input type="hidden" name="action" id="formAction" value="create"><input type="hidden" name="id" id="formId">
      <div class="form-group"><label for="name">Category name</label><input class="form-control" type="text" id="name" name="name" required maxlength="100"></div>
      <div class="form-group"><label for="parent_id">Parent category <span class="label-opt">(optional)</span></label>
        <select class="form-control" id="parent_id" name="parent_id"><option value="">None (top level)</option><?php foreach ($categories as $c): if (!$c['parent_id']): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endif; endforeach; ?></select></div>
      <div class="form-group"><label for="description">Description</label><input class="form-control" id="description" name="description" maxlength="255"></div>
      <label class="check" id="activeWrap" hidden><input type="checkbox" name="is_active" id="is_active" checked> Active</label>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>
<script>
function openCreateModal() {
  document.getElementById('categoryForm').reset();
  document.getElementById('modalTitle').textContent = 'New category';
  document.getElementById('formAction').value = 'create';
  document.getElementById('activeWrap').hidden = true;
  document.getElementById('categoryModal').hidden = false;
}
function openEditModal(c) {
  document.getElementById('modalTitle').textContent = 'Edit category';
  document.getElementById('formAction').value = 'update';
  document.getElementById('formId').value = c.id;
  document.getElementById('name').value = c.name;
  document.getElementById('parent_id').value = c.parent_id || '';
  document.getElementById('description').value = c.description || '';
  document.getElementById('is_active').checked = c.is_active == 1;
  document.getElementById('activeWrap').hidden = false;
  document.getElementById('categoryModal').hidden = false;
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
