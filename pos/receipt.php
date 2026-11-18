<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');

$pdo = db();
$id = input_int($_GET, 'id');
$stmt = $pdo->prepare(
    'SELECT s.*, u.full_name AS cashier_name, c.name AS customer_name, c.phone AS customer_phone, c.credit_balance
     FROM sales s JOIN users u ON u.id = s.cashier_id LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$sale = $stmt->fetch();
if (!$sale) { die('Sale not found.'); }
assert_branch_access((int) $sale['branch_id']);
$lh = branch_letterhead((int) $sale['branch_id']);
if (!can('sales.view_all') && (int) $sale['cashier_id'] !== (int) current_user()['id']) { http_response_code(403); die('Access denied.'); }

$items = $pdo->prepare('SELECT si.*, pv.variant_label, p.name AS product_name FROM sale_items si JOIN product_variants pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id WHERE si.sale_id = :id');
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$payments = $pdo->prepare('SELECT * FROM sale_payments WHERE sale_id = :id');
$payments->execute([':id' => $id]);
$payments = $payments->fetchAll();
$exchange = $pdo->prepare('SELECT return_number, total_amount, exchange_used, exchange_cash_back FROM sale_returns WHERE exchange_sale_id = :id');
$exchange->execute([':id' => $id]);
$exchange = $exchange->fetch();
$inclusive = setting('prices_include_tax', '1') === '1';
$taxNo = setting('tax_registration_no');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt <?= e($sale['invoice_number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Courier New', monospace; width: 80mm; margin: 0 auto; padding: 10px; color: #111; font-size: 12px; }
  .center { text-align: center; } .bold { font-weight: 700; } .right { text-align: right; }
  .line { border-top: 1px dashed #000; margin: 7px 0; }
  table { width: 100%; border-collapse: collapse; font-size: 11.5px; } td { padding: 2px 0; vertical-align: top; }
  .store-name { font-size: 16px; font-weight: 800; text-transform: uppercase; }
  .big td { font-size: 14px; font-weight: 800; }
  .void { border: 2px solid #000; text-align: center; font-weight: 800; padding: 4px; margin: 6px 0; font-size: 14px; }
  .toolbar { display: flex; gap: 6px; margin-bottom: 12px; }
  .toolbar button { flex: 1; padding: 10px; border: none; border-radius: 6px; font-weight: 700; cursor: pointer; }
  .p { background: #F2541B; color: #fff; } .s { background: #eee; }
  @media print { .no-print { display: none; } body { width: auto; } }
</style>
</head>
<body>
  <div class="toolbar no-print"><button class="p" onclick="window.print()">Print</button><button class="s" onclick="window.close()">Close</button></div>
  <div class="center">
    <div class="store-name"><?= e($lh['business'] ?: APP_NAME) ?></div>
    <?php if (count(branches_all()) > 1 && $lh['branch']): ?><div class="bold"><?= e($lh['branch']) ?></div><?php endif; ?>
    <?php if ($lh['address']): ?><div><?= e($lh['address']) ?></div><?php endif; ?>
    <?php if ($lh['phone']): ?><div>Tel: <?= e($lh['phone']) ?></div><?php endif; ?>
    <?php if ($taxNo): ?><div>VAT/TIN: <?= e($taxNo) ?></div><?php endif; ?>
  </div>
  <div class="line"></div>
  <?php if ($sale['status'] === 'voided'): ?><div class="void">*** CANCELLED ***</div><?php endif; ?>
  <table>
    <tr><td>Invoice</td><td class="right bold"><?= e($sale['invoice_number']) ?></td></tr>
    <tr><td>Date</td><td class="right"><?= e(date('Y-m-d H:i', strtotime($sale['sale_date']))) ?></td></tr>
    <tr><td>Cashier</td><td class="right"><?= e($sale['cashier_name']) ?></td></tr>
    <?php if ($sale['customer_name']): ?><tr><td>Customer</td><td class="right"><?= e($sale['customer_name']) ?></td></tr><?php endif; ?>
  </table>
  <div class="line"></div>
  <table>
    <?php foreach ($items as $it): ?>
      <tr><td colspan="2"><?= e($it['product_name']) ?><?= $it['variant_label'] ? ' ' . e($it['variant_label']) : '' ?></td></tr>
      <tr><td>&nbsp;&nbsp;<?= (int) $it['quantity'] ?> x <?= number_format($it['unit_price'], 2) ?><?= (float) $it['discount_amount'] > 0 ? ' (-' . number_format($it['discount_amount'], 2) . ')' : '' ?></td><td class="right"><?= number_format($it['line_total'], 2) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <div class="line"></div>
  <table>
    <tr><td>Subtotal (<?= array_sum(array_column($items, 'quantity')) ?> items)</td><td class="right"><?= number_format($sale['subtotal'], 2) ?></td></tr>
    <?php if ((float) $sale['discount_amount'] > 0): ?><tr><td>Discount</td><td class="right">-<?= number_format($sale['discount_amount'], 2) ?></td></tr><?php endif; ?>
    <?php if ((float) $sale['tax_amount'] > 0 || !$inclusive): ?><tr><td>Tax<?= $inclusive ? ' (included)' : '' ?></td><td class="right"><?= number_format($sale['tax_amount'], 2) ?></td></tr><?php endif; ?>
    <tr class="big"><td>TOTAL <?= e(setting('currency_code', 'LKR')) ?></td><td class="right"><?= number_format($sale['total_amount'], 2) ?></td></tr>
  </table>
  <div class="line"></div>
  <table>
    <?php foreach ($payments as $p): ?>
      <tr><td><?= e(method_label($p['method'])) ?><?= $p['reference_no'] ? ' #' . e($p['reference_no']) : '' ?></td><td class="right"><?= number_format($p['amount'], 2) ?></td></tr>
      <?php if ($p['method'] === 'cash' && $p['tendered_amount'] !== null): ?>
        <tr><td>&nbsp;&nbsp;Cash received</td><td class="right"><?= number_format($p['tendered_amount'], 2) ?></td></tr>
        <tr><td>&nbsp;&nbsp;Change</td><td class="right"><?= number_format($p['change_amount'], 2) ?></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($exchange && (float) $exchange['exchange_cash_back'] > 0): ?><tr><td>Exchange balance paid</td><td class="right"><?= number_format($exchange['exchange_cash_back'], 2) ?></td></tr><?php endif; ?>
    <?php if ($sale['customer_name'] && (float) $sale['credit_balance'] > 0): ?><tr><td>Account balance due</td><td class="right"><?= number_format($sale['credit_balance'], 2) ?></td></tr><?php endif; ?>
  </table>
  <div class="line"></div>
  <div class="center"><?= e($lh['footer']) ?></div>
  <?php if ($rp = setting('return_policy')): ?><div class="center" style="font-size:10.5px;margin-top:4px;"><?= e($rp) ?></div><?php endif; ?>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
</body>
</html>
