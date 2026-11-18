<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('products.view');

$pdo = db();
$ids = array_map('intval', $_GET['ids'] ?? []);
$ids = array_values(array_unique(array_filter($ids)));

$variants = [];
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT pv.id, pv.sku, pv.barcode, pv.variant_label, pv.price, p.name AS product_name
         FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id
         WHERE pv.id IN ($placeholders)"
    );
    $stmt->execute($ids);
    $variants = $stmt->fetchAll();
}

$storeName = setting('store_name', APP_NAME);
$currency = setting('currency_symbol', 'Rs.');
$pageTitle = 'Barcode Labels';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>">
<script src="<?= e(app_url('/assets/vendor/jsbarcode.min.js')) ?>"></script>
<script src="<?= e(app_url('/assets/vendor/lucide.min.js')) ?>" defer></script>
<style>
  .label-controls { max-width: 900px; margin: 0 auto var(--space-5); }
  .label-sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; max-width: 900px; margin: 0 auto; }
  .barcode-label {
    border: 1px dashed #ccc; border-radius: 6px; padding: 8px; text-align: center;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
  }
  .barcode-label .store { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .barcode-label .pname { font-size: 10px; font-weight: 700; }
  .barcode-label .price { font-size: 11px; font-weight: 800; color: var(--orange); }
  .barcode-label svg { max-width: 100%; }
  @media print {
    body { background: #fff; }
    .label-sheet { gap: 4mm; }
    .barcode-label { border: 1px solid #ddd; page-break-inside: avoid; }
  }
</style>
</head>
<body style="padding: var(--space-6) 0;">

<div class="label-controls no-print">
  <div class="card flex justify-between items-center" style="max-width:900px;margin:0 auto;">
    <div>
      <h2>Barcode Label Sheet</h2>
      <p class="text-muted mt-2"><?= count($variants) ?> variant(s) selected. Set copies per label, then print on your label sheet or thermal printer.</p>
    </div>
    <div class="flex gap-2">
      <a class="btn btn-secondary" href="javascript:history.back()">Back</a>
      <button class="btn btn-primary" type="button" onclick="window.print()"><i data-lucide="printer" style="width:16px;height:16px"></i> Print</button>
    </div>
  </div>

  <?php if ($variants): ?>
  <div class="card mt-4" style="max-width:900px;margin:16px auto 0;">
    <form method="get" id="copiesForm">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>Variant</th><th>Barcode</th><th>Copies</th></tr></thead>
          <tbody>
          <?php foreach ($variants as $v): ?>
            <tr>
              <td><?= e($v['product_name']) ?> <?= $v['variant_label'] ? '— ' . e($v['variant_label']) : '' ?></td>
              <td style="font-family:monospace;"><?= e($v['barcode']) ?></td>
              <td><input class="form-control copies-input" data-id="<?= (int)$v['id'] ?>" type="number" min="1" max="50" value="1" style="width:80px;"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if (!$variants): ?>
  <div class="card no-print" style="max-width:600px;margin:24px auto;text-align:center;">
    <p class="text-muted">No variants selected. Go back to a product and select variants to print.</p>
  </div>
<?php endif; ?>

<div class="label-sheet" id="labelSheet"></div>

<script src="<?= e(app_url('/assets/js/app.js')) ?>"></script>
<script>
const variants = <?= json_encode(array_map(function ($v) use ($storeName, $currency) {
    return [
        'id' => (int) $v['id'],
        'barcode' => $v['barcode'],
        'product_name' => $v['product_name'],
        'variant_label' => $v['variant_label'],
        'price' => number_format((float) $v['price'], 2),
    ];
}, $variants), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const storeName = <?= json_encode($storeName) ?>;
const currency = <?= json_encode($currency) ?>;

function render() {
  const sheet = document.getElementById('labelSheet');
  sheet.innerHTML = '';
  variants.forEach(v => {
    const copiesInput = document.querySelector(`.copies-input[data-id="${v.id}"]`);
    const copies = copiesInput ? Math.max(1, parseInt(copiesInput.value, 10) || 1) : 1;
    for (let i = 0; i < copies; i++) {
      const label = document.createElement('div');
      label.className = 'barcode-label';
      label.innerHTML = `
        <div class="store">${CalimaxUI.esc(storeName)}</div>
        <div class="pname">${CalimaxUI.esc(v.product_name)}${v.variant_label ? ' · ' + CalimaxUI.esc(v.variant_label) : ''}</div>
        <svg class="bc-svg" data-code="${CalimaxUI.esc(v.barcode)}"></svg>
        <div class="price">${CalimaxUI.esc(currency)} ${v.price}</div>
      `;
      sheet.appendChild(label);
    }
  });
  document.querySelectorAll('.bc-svg').forEach(svg => {
    JsBarcode(svg, svg.getAttribute('data-code'), { format: 'CODE128', width: 1.6, height: 40, fontSize: 11, margin: 2 });
  });
}
document.addEventListener('DOMContentLoaded', render);
document.addEventListener('input', e => { if (e.target.classList.contains('copies-input')) render(); });
document.addEventListener('DOMContentLoaded', () => window.lucide && lucide.createIcons());
</script>
</body>
</html>
