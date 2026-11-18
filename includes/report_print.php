<?php
/**
 * Shared branded A4 report/PDF template used across modules (Financial
 * Reports, Payables, Receivables, Inventory valuation, Sales reports).
 * Printed via the browser's print dialog — choosing "Save as PDF" produces
 * the PDF file, so no server-side PDF library is required.
 *
 * Call render_report_print() with:
 *   title, period_label, period_range          — header
 *   kpis: [ ['label'=>..,'value'=>..,'sub'=>?] ]  — top metric strip (optional)
 *   statement: [ ['type'=>'section'|'line'|'subtotal'|'grand', 'label'=>.., 'value'=>?] ]
 *              — formal indented income-statement body (optional, used by P&L)
 *   tables: [ ['heading'=>.., 'columns'=>[..], 'rows'=>[[..],..], 'totals'=>[..]?] ]
 *              — one or more supporting data tables (optional)
 *   generated_by, notes
 */
if (!function_exists('e')) {
    http_response_code(403);
    die('Direct access not permitted.');
}

function render_report_print(array $d): void
{
    $storeName = setting('store_name', APP_NAME);
    $lh = branch_letterhead();
    $storeAddress = current_branch_id() === null ? '' : $lh['address'];
    $storePhone = current_branch_id() === null ? setting('store_phone', '') : $lh['phone'];
    $branchLine = count(branches_all()) > 1 ? (current_branch()['name'] ?? 'All branches (consolidated)') : '';
    $currency = setting('currency_symbol', 'Rs.');
    $fmt = fn($n) => $currency . ' ' . number_format((float) $n, 2);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($d['title']) ?> · <?= e($d['period_label'] ?? '') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Inter', sans-serif; color: #1a1c20; margin: 0; background: #eceff3; }
  .sheet { max-width: 800px; margin: 24px auto; background: #fff; padding: 48px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
  .toolbar { max-width: 800px; margin: 16px auto; display: flex; justify-content: flex-end; gap: 8px; }
  .btn { padding: 10px 18px; border-radius: 8px; border: none; font-weight: 700; font-size: 13.5px; cursor: pointer; }
  .btn-primary { background: #FF4B12; color: #fff; }
  .btn-secondary { background: #fff; border: 1px solid #ddd; color: #333; }
  .letterhead { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 3px solid #25272B; padding-bottom: 20px; margin-bottom: 8px; }
  .store-name { font-size: 20px; font-weight: 800; text-transform: uppercase; letter-spacing: .02em; }
  .store-meta { font-size: 12px; color: #666; margin-top: 4px; line-height: 1.6; }
  .doc-title { text-align: right; }
  .doc-title h1 { font-size: 22px; margin: 0; text-transform: uppercase; letter-spacing: .03em; color: #FF4B12; }
  .doc-title .period { font-size: 13px; font-weight: 700; color: #444; margin-top: 4px; }
  .doc-title .range { font-size: 11.5px; color: #999; margin-top: 2px; }
  .gen-meta { font-size: 10.5px; color: #aaa; text-align: right; margin-bottom: 28px; }

  .kpi-strip { display: flex; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
  .kpi-box { flex: 1; min-width: 140px; background: #FAFBFD; border: 1px solid #eee; border-radius: 10px; padding: 14px 16px; }
  .kpi-box .l { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #999; font-weight: 700; }
  .kpi-box .v { font-size: 19px; font-weight: 800; margin-top: 4px; }
  .kpi-box .s { font-size: 11px; color: #888; margin-top: 2px; }
  .kpi-box .v.positive { color: #1a9c5c; }
  .kpi-box .v.negative { color: #e0392b; }

  .statement { width: 100%; border-collapse: collapse; margin-bottom: 32px; }
  .statement td { padding: 7px 4px; font-size: 13.5px; }
  .statement .section-row td { padding-top: 16px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #999; font-weight: 700; border-bottom: 1.5px solid #25272B; padding-bottom: 6px; }
  .statement .line-row td:first-child { padding-left: 20px; color: #444; }
  .statement .line-row td:last-child, .statement .subtotal-row td:last-child, .statement .grand-row td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
  .statement .subtotal-row td { font-weight: 700; border-top: 1px solid #ddd; padding-top: 10px; }
  .statement .grand-row td { font-weight: 800; font-size: 16px; border-top: 3px double #25272B; padding-top: 12px; color: #FF4B12; }

  .report-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
  .report-table caption { text-align: left; font-size: 13px; font-weight: 700; margin-bottom: 10px; caption-side: top; }
  .report-table thead th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: #999; padding: 8px 6px; border-bottom: 2px solid #25272B; }
  .report-table tbody td { padding: 9px 6px; border-bottom: 1px solid #eee; font-size: 12.5px; }
  .report-table tfoot td { padding: 9px 6px; font-weight: 800; border-top: 2px solid #25272B; }
  .report-table .num { text-align: right; font-variant-numeric: tabular-nums; }

  .notes { font-size: 12px; color: #666; margin-top: 8px; }
  .footer-note { margin-top: 40px; padding-top: 16px; border-top: 1px solid #eee; font-size: 10.5px; color: #aaa; text-align: center; }

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
          <?php if ($branchLine): ?><strong>Branch: <?= e($branchLine) ?></strong><br><?php endif; ?>
          <?php if ($storeAddress): ?><?= e($storeAddress) ?><br><?php endif; ?>
          <?php if ($storePhone): ?><?= e($storePhone) ?><?php endif; ?>
        </div>
      </div>
      <div class="doc-title">
        <h1><?= e($d['title']) ?></h1>
        <?php if (!empty($d['period_label'])): ?><div class="period"><?= e($d['period_label']) ?></div><?php endif; ?>
        <?php if (!empty($d['period_range'])): ?><div class="range"><?= e($d['period_range']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="gen-meta">Generated <?= e(date('M j, Y g:i A')) ?><?= !empty($d['generated_by']) ? ' by ' . e($d['generated_by']) : '' ?></div>

    <?php if (!empty($d['kpis'])): ?>
      <div class="kpi-strip">
        <?php foreach ($d['kpis'] as $k): ?>
          <div class="kpi-box">
            <div class="l"><?= e($k['label']) ?></div>
            <div class="v <?= e($k['tone'] ?? '') ?>"><?php
                if (($k['format'] ?? 'currency') === 'number') {
                    echo e(is_numeric($k['value']) ? number_format((float) $k['value']) : (string) $k['value']);
                } elseif (($k['format'] ?? '') === 'text') {
                    echo e((string) $k['value']);
                } else {
                    echo is_numeric($k['value']) ? $fmt($k['value']) : e((string) $k['value']);
                }
            ?></div>
            <?php if (!empty($k['sub'])): ?><div class="s"><?= e($k['sub']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($d['statement'])): ?>
      <table class="statement">
        <tbody>
        <?php foreach ($d['statement'] as $row): ?>
          <?php if ($row['type'] === 'section'): ?>
            <tr class="section-row"><td colspan="2"><?= e($row['label']) ?></td></tr>
          <?php elseif ($row['type'] === 'line'): ?>
            <tr class="line-row"><td><?= e($row['label']) ?></td><td><?= ($row['value'] < 0 ? '(' . $fmt(abs($row['value'])) . ')' : $fmt($row['value'])) ?></td></tr>
          <?php elseif ($row['type'] === 'subtotal'): ?>
            <tr class="subtotal-row"><td><?= e($row['label']) ?></td><td><?= ($row['value'] < 0 ? '(' . $fmt(abs($row['value'])) . ')' : $fmt($row['value'])) ?></td></tr>
          <?php elseif ($row['type'] === 'grand'): ?>
            <tr class="grand-row"><td><?= e($row['label']) ?></td><td><?= ($row['value'] < 0 ? '(' . $fmt(abs($row['value'])) . ')' : $fmt($row['value'])) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <?php foreach ($d['tables'] ?? [] as $table): ?>
      <table class="report-table">
        <?php if (!empty($table['heading'])): ?><caption><?= e($table['heading']) ?></caption><?php endif; ?>
        <thead><tr><?php foreach ($table['columns'] as $i => $col): ?><th class="<?= $i > 0 ? 'num' : '' ?>"><?= e($col) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php if (!$table['rows']): ?>
          <tr><td colspan="<?= count($table['columns']) ?>" style="color:#999;">No data for this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($table['rows'] as $row): ?>
          <tr><?php foreach ($row as $i => $cell): ?><td class="<?= $i > 0 ? 'num' : '' ?>"><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (!empty($table['totals'])): ?>
          <tfoot><tr><?php foreach ($table['totals'] as $i => $cell): ?><td class="<?= $i > 0 ? 'num' : '' ?>"><?= e((string) $cell) ?></td><?php endforeach; ?></tr></tfoot>
        <?php endif; ?>
      </table>
    <?php endforeach; ?>

    <?php if (!empty($d['notes'])): ?><div class="notes"><?= e($d['notes']) ?></div><?php endif; ?>

    <div class="footer-note"><?= e($storeName) ?> · Generated by <?= e(APP_NAME) ?></div>
  </div>
</body>
</html>
<?php
}
