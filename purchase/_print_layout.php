<?php
/**
 * Shared A4 print layout for formal purchase documents (PO, GRN, Returns).
 * Call render_print_document() with the fields below after bootstrap + auth
 * have already run in the including page. Printed via the browser's
 * print dialog — "Save as PDF" produces the PDF, no server-side library needed.
 */
if (!function_exists('e')) {
    http_response_code(403);
    die('Direct access not permitted.');
}

function render_print_document(array $d): void
{
    $storeName = setting('store_name', APP_NAME);
    // Documents carry the letterhead of the branch that issued them.
    $lh = branch_letterhead(isset($d['branch_id']) ? (int) $d['branch_id'] : null);
    $storeAddress = $lh['address'];
    $storePhone = $lh['phone'];
    $storeEmail = $lh['email'];
    if (count(branches_all()) > 1 && $lh['branch']) $storeName .= ' — ' . $lh['branch'];
    $currency = setting('currency_symbol', 'Rs.');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($d['doc_number']) ?> · <?= e($d['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Inter', sans-serif; color: #1a1c20; margin: 0; background: #eceff3; }
  .sheet { max-width: 800px; margin: 24px auto; background: #fff; padding: 48px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
  .toolbar { max-width: 800px; margin: 16px auto; display: flex; justify-content: flex-end; gap: 8px; }
  .btn { padding: 10px 18px; border-radius: 8px; border: none; font-weight: 700; font-size: 13.5px; cursor: pointer; }
  .btn-primary { background: #FF4B12; color: #fff; }
  .btn-secondary { background: #fff; border: 1px solid #ddd; color: #333; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #25272B; padding-bottom: 20px; margin-bottom: 28px; }
  .store-name { font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: .02em; }
  .store-meta { font-size: 12px; color: #666; margin-top: 4px; line-height: 1.6; }
  .doc-title { text-align: right; }
  .doc-title h1 { font-size: 24px; margin: 0; text-transform: uppercase; letter-spacing: .03em; color: #FF4B12; }
  .doc-title .num { font-size: 14px; font-weight: 700; color: #444; margin-top: 4px; }
  .status-pill { display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 999px; background: #FFF0E6; color: #FF4B12; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
  .meta-grid { display: flex; justify-content: space-between; gap: 32px; margin-bottom: 28px; flex-wrap: wrap; }
  .meta-block h4 { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: #999; margin: 0 0 6px; }
  .meta-block .party-name { font-weight: 700; font-size: 14px; }
  .meta-block .line { font-size: 12.5px; color: #555; line-height: 1.6; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  thead th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: #999; padding: 8px 6px; border-bottom: 2px solid #25272B; }
  tbody td { padding: 10px 6px; border-bottom: 1px solid #eee; font-size: 13px; }
  .num-col { text-align: right; }
  .totals { display: flex; justify-content: flex-end; margin-bottom: 28px; }
  .totals table { width: 260px; margin: 0; }
  .totals td { padding: 6px 6px; border: none; font-size: 13.5px; }
  .totals tr.grand td { font-weight: 800; font-size: 16px; border-top: 2px solid #25272B; padding-top: 10px; }
  .notes { font-size: 12.5px; color: #555; margin-bottom: 32px; }
  .signatures { display: flex; justify-content: space-between; margin-top: 48px; }
  .signature-box { width: 45%; }
  .signature-line { border-top: 1px solid #999; margin-top: 40px; padding-top: 6px; font-size: 11.5px; color: #777; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <button class="btn btn-secondary" onclick="history.back()">Back</button>
    <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
  </div>
  <div class="sheet">
    <div class="letterhead">
      <div>
        <div class="store-name"><?= e($storeName) ?></div>
        <div class="store-meta">
          <?php if ($storeAddress): ?><?= e($storeAddress) ?><br><?php endif; ?>
          <?php if ($storePhone): ?><?= e($storePhone) ?><?php endif; ?>
          <?php if ($storeEmail): ?> · <?= e($storeEmail) ?><?php endif; ?>
        </div>
      </div>
      <div class="doc-title">
        <h1><?= e($d['title']) ?></h1>
        <div class="num">#<?= e($d['doc_number']) ?></div>
        <?php if (!empty($d['status'])): ?><div class="status-pill"><?= e($d['status']) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="meta-grid">
      <div class="meta-block">
        <h4><?= e($d['party_label']) ?></h4>
        <div class="party-name"><?= e($d['party_name']) ?></div>
        <?php foreach ($d['party_lines'] as $line): ?><div class="line"><?= e($line) ?></div><?php endforeach; ?>
      </div>
      <div class="meta-block">
        <h4><?= e($d['doc_date_label']) ?></h4>
        <div class="line"><?= e($d['doc_date']) ?></div>
        <?php foreach ($d['extra_meta'] as [$label, $value]): ?>
          <h4 style="margin-top:10px;"><?= e($label) ?></h4>
          <div class="line"><?= e($value) ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <table>
      <thead>
        <tr><th>Item</th><th>SKU</th><th class="num-col">Qty</th><th class="num-col">Unit Cost</th><th class="num-col">Total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($d['items'] as $it): ?>
          <tr>
            <td><?= e($it['name']) ?></td>
            <td style="color:#888;"><?= e($it['sku']) ?></td>
            <td class="num-col"><?= (int) $it['qty'] ?></td>
            <td class="num-col"><?= e($currency) ?> <?= number_format($it['unit_cost'], 2) ?></td>
            <td class="num-col"><?= e($currency) ?> <?= number_format($it['line_total'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <table>
        <tr class="grand"><td>Total</td><td class="num-col"><?= e($currency) ?> <?= number_format($d['total'], 2) ?></td></tr>
      </table>
    </div>

    <?php if (!empty($d['notes'])): ?>
      <div class="notes"><strong>Notes:</strong> <?= e($d['notes']) ?></div>
    <?php endif; ?>

    <div class="signatures">
      <div class="signature-box">
        <div class="signature-line">Prepared by<?= !empty($d['prepared_by']) ? ' — ' . e($d['prepared_by']) : '' ?></div>
      </div>
      <div class="signature-box">
        <div class="signature-line">Authorized Signature</div>
      </div>
    </div>
  </div>
</body>
</html>
<?php
}
