<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.manage');

$pdo = db();
if (is_post()) {
    verify_csrf();
    $action = input($_POST, 'action');
    if ($action === 'add_attribute' && ($name = input($_POST, 'name')) !== '') {
        $pdo->prepare('INSERT IGNORE INTO attributes (name) VALUES (:n)')->execute([':n' => $name]);
        log_activity('attribute_create', 'Added variant attribute ' . $name);
        flash('success', 'Attribute added.');
    } elseif ($action === 'delete_attribute') {
        $aid = input_int($_POST, 'id');
        $used = $pdo->prepare('SELECT COUNT(*) FROM variant_attribute_values vav JOIN attribute_values av ON av.id = vav.attribute_value_id WHERE av.attribute_id = :id');
        $used->execute([':id' => $aid]);
        if ((int) $used->fetchColumn()) flash('error', 'This attribute is used by existing products and cannot be removed.');
        else { $pdo->prepare('DELETE FROM attributes WHERE id = :id')->execute([':id' => $aid]); log_activity('attribute_delete', 'Removed variant attribute #' . $aid); flash('success', 'Attribute removed.'); }
    } elseif ($action === 'add_value' && ($value = input($_POST, 'value')) !== '' && ($attrId = input_int($_POST, 'attribute_id'))) {
        $pdo->prepare('INSERT IGNORE INTO attribute_values (attribute_id, value) VALUES (:a, :v)')->execute([':a' => $attrId, ':v' => $value]);
        flash('success', 'Value added.');
    } elseif ($action === 'delete_value') {
        $vid = input_int($_POST, 'id');
        $used = $pdo->prepare('SELECT COUNT(*) FROM variant_attribute_values WHERE attribute_value_id = :id');
        $used->execute([':id' => $vid]);
        if ((int) $used->fetchColumn()) flash('error', 'That value is used by existing products.');
        else { $pdo->prepare('DELETE FROM attribute_values WHERE id = :id')->execute([':id' => $vid]); flash('success', 'Value removed.'); }
    }
    redirect('/inventory/attributes.php');
}

$attributes = $pdo->query('SELECT * FROM attributes ORDER BY name')->fetchAll();
$valuesByAttr = [];
foreach ($pdo->query('SELECT * FROM attribute_values ORDER BY attribute_id, id')->fetchAll() as $v) $valuesByAttr[$v['attribute_id']][] = $v;

$pageTitle = 'Variant Attributes';
require __DIR__ . '/../includes/header.php';
page_header('Products', 'Attributes such as Size, Colour, Flavour or Pack Size turn one product into several sellable variants.');
nav_products();
?>
<div class="card mb-4">
  <form method="post" class="flex gap-2 items-center flex-wrap"><?= csrf_field() ?><input type="hidden" name="action" value="add_attribute">
    <input class="form-control" style="max-width:320px;" type="text" name="name" placeholder="New attribute, e.g. Flavour or Pack size" required maxlength="50">
    <button class="btn btn-primary" type="submit"><?= icon('plus', 16) ?> Add attribute</button>
  </form>
</div>
<div class="grid grid-2">
<?php foreach ($attributes as $attr): ?>
  <div class="card">
    <div class="card-head"><h3><?= e($attr['name']) ?></h3>
      <form method="post" data-confirm="Remove this attribute?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_attribute"><input type="hidden" name="id" value="<?= (int) $attr['id'] ?>"><button class="btn-ghost btn-sm" style="color:var(--error);" type="submit">Remove</button></form></div>
    <div class="chip-group mb-4">
      <?php foreach ($valuesByAttr[$attr['id']] ?? [] as $v): ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete_value"><input type="hidden" name="id" value="<?= (int) $v['id'] ?>"><button type="submit" class="chip" title="Remove value"><?= e($v['value']) ?> <span class="text-muted">&times;</span></button></form>
      <?php endforeach; ?>
      <?php if (empty($valuesByAttr[$attr['id']])): ?><span class="text-muted text-sm">No values yet.</span><?php endif; ?>
    </div>
    <form method="post" class="flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="add_value"><input type="hidden" name="attribute_id" value="<?= (int) $attr['id'] ?>">
      <input class="form-control" type="text" name="value" placeholder="Add a value…" maxlength="50" required><button class="btn btn-secondary" type="submit">Add</button></form>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$attributes) echo '<div class="card">' . empty_state('tags', 'No attributes', 'Only needed for products sold in sizes, colours or flavours.') . '</div>'; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
