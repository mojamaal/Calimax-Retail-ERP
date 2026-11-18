<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.manage');

$pdo = db();
$id = input_int($_GET, 'id');
$product = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();
    if (!$product) { flash('error', 'Product not found.'); redirect('/inventory/products.php'); }
}
$units = ['pcs' => 'Pieces (pcs)', 'pack' => 'Pack', 'box' => 'Box', 'bottle' => 'Bottle', 'kg' => 'Kilogram (kg)', 'g' => 'Gram (g)', 'l' => 'Litre (L)', 'ml' => 'Millilitre (ml)', 'm' => 'Metre (m)', 'pair' => 'Pair', 'set' => 'Set', 'dozen' => 'Dozen'];
$errors = [];
$old = $_POST;

if (is_post()) {
    verify_csrf();
    $name = input($_POST, 'name');
    $categoryId = input_int($_POST, 'category_id') ?: null;
    $brand = input($_POST, 'brand');
    $unit = array_key_exists(input($_POST, 'unit'), $units) ? input($_POST, 'unit') : 'pcs';
    $description = input($_POST, 'description');
    $taxRate = input_float($_POST, 'tax_rate');
    $isActive = isset($_POST['is_active']) || !$product ? 1 : 0;

    if ($name === '') $errors[] = 'Product name is required.';
    if ($taxRate < 0 || $taxRate > 100) $errors[] = 'Tax rate must be between 0 and 100.';

    if (!$product) {
        $mode = input($_POST, 'mode') === 'variants' ? 'variants' : 'simple';
        $price = input_float($_POST, 'price');
        $cost = input_float($_POST, 'cost_price');
        $reorder = max(0, input_int($_POST, 'reorder_level'));
        $opening = max(0, input_int($_POST, 'initial_stock'));
        $sku = strtoupper(input($_POST, 'sku'));
        $barcode = input($_POST, 'barcode');
        $selectedValues = array_map('intval', $_POST['attribute_values'] ?? []);
        if ($price <= 0) $errors[] = 'Enter a selling price.';
        if ($cost < 0) $errors[] = 'Cost price cannot be negative.';
        if ($opening > 0 && current_branch_id() === null) $errors[] = 'Choose a branch (top bar) to receive the opening stock, or leave opening stock at 0.';
        if ($mode === 'simple') {
            if ($sku !== '' && !preg_match('/^[A-Z0-9._-]{2,60}$/', $sku)) $errors[] = 'Product code may only contain letters, numbers, dot, dash and underscore.';
            if ($sku !== '' && !variant_code_available('sku', $sku)) $errors[] = 'Product code ' . $sku . ' is already used.';
            if ($barcode !== '' && !preg_match('/^[0-9A-Za-z-]{4,60}$/', $barcode)) $errors[] = 'Barcode contains invalid characters.';
            if ($barcode !== '' && !variant_code_available('barcode', $barcode)) $errors[] = 'Barcode ' . $barcode . ' already belongs to another product.';
        } elseif (!$selectedValues) {
            $errors[] = 'Select at least one attribute value (e.g. a size or colour), or choose “Single item”.';
        }
    }

    // Image upload
    $imageFilename = $product['image'] ?? null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'Image upload failed. Please try again.';
        elseif ($file['size'] > MAX_UPLOAD_BYTES) $errors[] = 'Image must be smaller than 2MB.';
        else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) $errors[] = 'Only JPG, PNG or WEBP images are allowed.';
            elseif (!$errors) {
                $newName = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                if (!is_dir(UPLOAD_PRODUCT_DIR)) mkdir(UPLOAD_PRODUCT_DIR, 0755, true);
                if (move_uploaded_file($file['tmp_name'], UPLOAD_PRODUCT_DIR . $newName)) $imageFilename = $newName;
                else $errors[] = 'Could not save the uploaded image.';
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            if ($product) {
                $catName = fn($cid) => $cid ? (string) $pdo->query('SELECT name FROM categories WHERE id = ' . (int) $cid)->fetchColumn() : 'Uncategorized';
                $changes = [];
                $cmp = ['name' => $name, 'brand' => $brand, 'unit' => $unit, 'tax_rate' => number_format($taxRate, 2, '.', ''), 'is_active' => (string) $isActive];
                foreach ($cmp as $k => $v) { if ((string) $product[$k] !== (string) $v) $changes[] = "$k: \"{$product[$k]}\" → \"$v\""; }
                if ((int) $product['category_id'] !== (int) $categoryId) $changes[] = 'category: "' . $catName($product['category_id']) . '" → "' . $catName($categoryId) . '"';
                if ($imageFilename !== $product['image']) $changes[] = 'image replaced';
                if ($description !== (string) $product['description']) $changes[] = 'description edited';
                $pdo->prepare('UPDATE products SET name=:n, category_id=:c, brand=:b, unit=:un, description=:d, tax_rate=:t, image=:img, is_active=:a WHERE id=:id')
                    ->execute([':n' => $name, ':c' => $categoryId, ':b' => $brand ?: null, ':un' => $unit, ':d' => $description ?: null, ':t' => $taxRate, ':img' => $imageFilename, ':a' => $isActive, ':id' => $product['id']]);
                $productId = (int) $product['id'];
                $pdo->commit();
                if ($changes) log_activity('product_update', 'Edited product ' . $name . ' — ' . implode('; ', $changes), 'product', $productId);
                flash('success', 'Product updated.');
            } else {
                $pdo->prepare('INSERT INTO products (name, category_id, brand, unit, description, tax_rate, image, created_by) VALUES (:n,:c,:b,:un,:d,:t,:img,:u)')
                    ->execute([':n' => $name, ':c' => $categoryId, ':b' => $brand ?: null, ':un' => $unit, ':d' => $description ?: null, ':t' => $taxRate, ':img' => $imageFilename, ':u' => current_user()['id']]);
                $productId = (int) $pdo->lastInsertId();

                $combinations = [[]];
                if ($mode === 'variants') {
                    $ph = implode(',', array_fill(0, count($selectedValues), '?'));
                    $s = $pdo->prepare("SELECT av.id, av.value, a.id AS attr_id FROM attribute_values av JOIN attributes a ON a.id = av.attribute_id WHERE av.id IN ($ph) ORDER BY a.id, av.id");
                    $s->execute($selectedValues);
                    $grouped = [];
                    foreach ($s->fetchAll() as $r) { $grouped[$r['attr_id']][] = $r; }
                    foreach ($grouped as $vals) {
                        $next = [];
                        foreach ($combinations as $combo) foreach ($vals as $av) $next[] = array_merge($combo, [$av]);
                        $combinations = $next;
                    }
                }
                $insVariant = $pdo->prepare('INSERT INTO product_variants (product_id, sku, barcode, variant_label, price, cost_price, reorder_level, stock_qty) VALUES (:p,:sku,:bc,:lbl,:price,:cost,:re,0)');
                $insPivot = $pdo->prepare('INSERT INTO variant_attribute_values (variant_id, attribute_value_id) VALUES (:v,:a)');
                foreach ($combinations as $combo) {
                    $label = implode(' / ', array_map(fn($v) => $v['value'], $combo));
                    $vSku = ($mode === 'simple' && $sku !== '') ? $sku : generate_sku($name, $label);
                    $vBarcode = ($mode === 'simple' && $barcode !== '') ? $barcode : generate_barcode();
                    $insVariant->execute([':p' => $productId, ':sku' => $vSku, ':bc' => $vBarcode, ':lbl' => $label ?: null, ':price' => $price, ':cost' => $cost, ':re' => $reorder]);
                    $variantId = (int) $pdo->lastInsertId();
                    foreach ($combo as $av) $insPivot->execute([':v' => $variantId, ':a' => $av['id']]);
                    if ($opening > 0) apply_stock_change($pdo, $variantId, $opening, 'initial', 'product', $productId, 'Opening stock on product creation', 'saleable', current_branch_id());
                }
                $pdo->commit();
                log_activity('product_create', 'Created product ' . $name . ' · ' . count($combinations) . ' item(s) · price ' . format_money($price) . ' · cost ' . format_money($cost) . ($opening ? ' · opening stock ' . $opening . ' each' : ''), 'product', $productId);
                flash('success', 'Product created' . (count($combinations) > 1 ? ' with ' . count($combinations) . ' variants' : '') . '. It is now available for purchasing and sale.');
            }
            redirect('/inventory/product_view.php?id=' . $productId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Product save failed: ' . $ex->getMessage());
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

$categories = $pdo->query('SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll();
$attributes = $pdo->query('SELECT * FROM attributes ORDER BY name')->fetchAll();
$valuesByAttr = [];
foreach ($pdo->query('SELECT * FROM attribute_values ORDER BY attribute_id, id')->fetchAll() as $v) $valuesByAttr[$v['attribute_id']][] = $v;
$val = fn($k, $d = '') => e((string) ($old[$k] ?? ($product[$k] ?? $d)));

$pageTitle = $product ? 'Edit Product' : 'New Product';
require __DIR__ . '/../includes/header.php';
page_header($product ? 'Edit product' : 'New product', $product ? e($product['name']) : 'Register a product once — it becomes available for purchasing, stock control and the POS.', '', [['Products', '/inventory/products.php'], [$product ? $product['name'] : 'New', $product ? '/inventory/product_view.php?id=' . $product['id'] : null]]);
?>
<?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle', 18) ?><span><?= e($err) ?></span></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" id="productForm">
  <?= csrf_field() ?>
  <div class="layout-2-1">
    <div class="card">
      <div class="form-section">
        <div class="form-section-title">Product details</div>
        <div class="form-group"><label for="name">Product name</label><input class="form-control" type="text" id="name" name="name" required maxlength="150" value="<?= $val('name') ?>" placeholder="e.g. Coca-Cola 1L" autofocus></div>
        <div class="form-row">
          <div class="form-group"><label for="category_id">Category</label>
            <select class="form-control" id="category_id" name="category_id">
              <option value="">Uncategorized</option>
              <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($old['category_id'] ?? $product['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= $c['parent_id'] ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
            <div class="form-hint"><a class="link" href="<?= e(app_url('/inventory/categories.php')) ?>">Manage categories</a></div>
          </div>
          <div class="form-group"><label for="brand">Brand <span class="label-opt">(optional)</span></label><input class="form-control" type="text" id="brand" name="brand" maxlength="100" value="<?= $val('brand') ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label for="unit">Unit of sale</label>
            <select class="form-control" id="unit" name="unit"><?php foreach ($units as $k => $l): ?><option value="<?= $k ?>" <?= ($old['unit'] ?? $product['unit'] ?? 'pcs') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label for="tax_rate">Tax rate (%)</label><input class="form-control" type="number" id="tax_rate" name="tax_rate" step="0.01" min="0" max="100" value="<?= $val('tax_rate', setting('default_tax_rate', '0')) ?>">
            <div class="form-hint">Selling prices <?= setting('prices_include_tax', '1') === '1' ? 'include' : 'exclude' ?> tax (Settings).</div></div>
        </div>
        <div class="form-group mb-0"><label for="description">Description <span class="label-opt">(optional)</span></label><textarea class="form-control" id="description" name="description" rows="2"><?= $val('description') ?></textarea></div>
      </div>

      <?php if (!$product): ?>
      <div class="form-section">
        <div class="form-section-title">How is it sold?</div>
        <div class="option-cards mb-3">
          <label class="option-card"><input type="radio" name="mode" value="simple" <?= ($old['mode'] ?? 'simple') === 'simple' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('box', 15) ?> Single item</span><span class="oc-desc">One code & barcode — groceries, electronics, cosmetics, hardware</span></span></label>
          <label class="option-card"><input type="radio" name="mode" value="variants" <?= ($old['mode'] ?? '') === 'variants' ? 'checked' : '' ?>><span class="oc-body"><span class="oc-title"><?= icon('layers', 15) ?> With variants</span><span class="oc-desc">Sizes, colours, flavours — each gets its own barcode & stock</span></span></label>
        </div>
        <div id="simpleFields">
          <div class="form-row">
            <div class="form-group"><label for="sku">Product code (SKU)</label><input class="form-control mono" type="text" id="sku" name="sku" maxlength="60" placeholder="e.g. COKE001 — blank to auto-generate" value="<?= $val('sku') ?>" style="text-transform:uppercase;"></div>
            <div class="form-group"><label for="barcode">Barcode</label><input class="form-control mono" type="text" id="barcode" name="barcode" maxlength="60" placeholder="Scan the product barcode (e.g. 479…)" value="<?= $val('barcode') ?>">
              <div class="form-hint">Blank = we create a printable in-store barcode.</div></div>
          </div>
        </div>
        <div id="variantFields" hidden>
          <?php foreach ($attributes as $attr): ?>
            <div class="form-group"><label><?= e($attr['name']) ?></label>
              <div class="chip-group">
                <?php foreach ($valuesByAttr[$attr['id']] ?? [] as $v): ?>
                  <label class="chip"><input type="checkbox" name="attribute_values[]" value="<?= (int) $v['id'] ?>" style="accent-color:var(--orange);" <?= in_array((string) $v['id'], array_map('strval', $old['attribute_values'] ?? []), true) ? 'checked' : '' ?>> <?= e($v['value']) ?></label>
                <?php endforeach; ?>
                <?php if (empty($valuesByAttr[$attr['id']])): ?><span class="text-muted text-sm">No values yet.</span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <div class="callout text-sm">A variant is created for every combination you tick (e.g. 2 sizes × 3 colours = 6 variants). <a class="link" href="<?= e(app_url('/inventory/attributes.php')) ?>">Manage attributes</a></div>
        </div>
      </div>
      <div class="form-section">
        <div class="form-section-title">Pricing & stock</div>
        <div class="form-row">
          <div class="form-group"><label for="cost_price">Cost price (purchase)</label><div class="input-group"><input class="form-control" type="number" id="cost_price" name="cost_price" step="0.01" min="0" required value="<?= $val('cost_price') ?>"><span class="addon"><?= e(setting('currency_code', 'LKR')) ?></span></div></div>
          <div class="form-group"><label for="price">Selling price</label><div class="input-group"><input class="form-control" type="number" id="price" name="price" step="0.01" min="0.01" required value="<?= $val('price') ?>"><span class="addon"><?= e(setting('currency_code', 'LKR')) ?></span></div>
            <div class="form-hint" id="marginHint"></div>
            <?php if (count(branches_all()) > 1): ?><div class="form-hint">Starting price for every branch — each branch can set its own price afterwards on the product page.</div><?php endif; ?></div>
        </div>
        <div class="form-row">
          <div class="form-group mb-0"><label for="reorder_level">Reorder level</label><input class="form-control" type="number" id="reorder_level" name="reorder_level" min="0" value="<?= $val('reorder_level', '10') ?>"><div class="form-hint">You’ll be alerted when stock falls to this level.</div></div>
          <div class="form-group mb-0"><label for="initial_stock">Opening stock <span class="label-opt">(per item)</span></label><input class="form-control" type="number" id="initial_stock" name="initial_stock" min="0" value="<?= $val('initial_stock', '0') ?>"><div class="form-hint">Stock already on the shelf<?= count(branches_all()) > 1 ? ' at <strong>' . e(branch_scope_label()) . '</strong>' : '' ?>. New deliveries go through Purchases.</div></div>
        </div>
      </div>
      <?php else: ?>
        <div class="callout"><?= icon('info', 14) ?> Prices, codes, barcodes and reorder levels are managed per item on the <a class="link" href="<?= e(app_url('/inventory/product_view.php?id=' . $product['id'])) ?>">product page</a>, so every price change is tracked in the audit log.</div>
      <?php endif; ?>
    </div>

    <div class="grid">
      <div class="card">
        <div class="form-section-title">Image</div>
        <?php if (!empty($product['image'])): ?><img src="<?= e(UPLOAD_PRODUCT_URL . $product['image']) ?>" alt="" style="width:100%;max-height:200px;object-fit:contain;border-radius:10px;background:var(--surface-soft);margin-bottom:10px;"><?php endif; ?>
        <input class="form-control" type="file" name="image" accept="image/png,image/jpeg,image/webp">
        <div class="form-hint">JPG, PNG or WEBP up to 2MB. Shown on the POS.</div>
      </div>
      <?php if ($product): ?>
      <div class="card"><label class="check"><input type="checkbox" name="is_active" <?= $product['is_active'] ? 'checked' : '' ?>> Active — available for sale and purchasing</label></div>
      <?php endif; ?>
      <div class="flex gap-2">
        <a class="btn btn-secondary" href="<?= e(app_url($product ? '/inventory/product_view.php?id=' . $product['id'] : '/inventory/products.php')) ?>">Cancel</a>
        <button type="submit" class="btn btn-primary flex-1"><?= icon('check', 16) ?> <?= $product ? 'Save changes' : 'Create product' ?></button>
      </div>
    </div>
  </div>
</form>
<script>
(function () {
  const modes = document.querySelectorAll('input[name=mode]');
  function sync() {
    const m = document.querySelector('input[name=mode]:checked');
    if (!m) return;
    document.getElementById('simpleFields').hidden = m.value !== 'simple';
    document.getElementById('variantFields').hidden = m.value !== 'variants';
  }
  modes.forEach((r) => r.addEventListener('change', sync)); sync();
  const price = document.getElementById('price'), cost = document.getElementById('cost_price'), hint = document.getElementById('marginHint');
  function margin() {
    if (!price || !cost) return;
    const p = parseFloat(price.value), c = parseFloat(cost.value);
    if (p > 0 && c >= 0) { const m = (p - c) / p * 100; hint.innerHTML = 'Margin: <strong class="' + (m < 0 ? 'text-error' : 'text-success') + '">' + m.toFixed(1) + '%</strong> · profit ' + CalimaxUI.money(p - c) + ' per unit'; } else hint.textContent = '';
  }
  if (price) { price.addEventListener('input', margin); cost.addEventListener('input', margin); margin(); }
  // Barcode scanners send Enter — don't submit the form from the barcode field.
  const bc = document.getElementById('barcode');
  if (bc) bc.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('cost_price').focus(); } });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
