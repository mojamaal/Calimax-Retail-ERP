<?php
/**
 * POS checkout / hold endpoint. JSON body:
 * {
 *   csrf_token, customer_id, notes,
 *   items: [{variant_id, qty, discount_amount}],      // per-line discount (amount)
 *   discount_amount,                                  // whole-bill discount (amount)
 *   payments: [{method, amount, tendered_amount, reference_no}],
 *   hold: bool, resume_sale_id: int|null,
 *   exchange_return_id: int|null,                     // redeem an exchange credit
 *   override_username, override_password              // manager approval (over-limit discount)
 * }
 * Prices, cost and tax are always re-derived server-side from the catalog, so a
 * tampered request cannot under-charge, oversell stock or skip approvals.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');
require_post();
header('Content-Type: application/json');
$branchId = write_branch();   // every sale belongs to one branch: its stock, price and cash drawer

function fail(string $msg, int $code = 422, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg] + $extra);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) fail('Invalid request body.', 400);
verify_csrf_value($body['csrf_token'] ?? null);

$pdo = db();
$user = current_user();
$customerId = !empty($body['customer_id']) ? (int) $body['customer_id'] : null;
$items = is_array($body['items'] ?? null) ? $body['items'] : [];
$overallDiscount = max(0, round((float) ($body['discount_amount'] ?? 0), 2));
$payments = is_array($body['payments'] ?? null) ? $body['payments'] : [];
$hold = !empty($body['hold']);
$resumeSaleId = !empty($body['resume_sale_id']) ? (int) $body['resume_sale_id'] : null;
$exchangeReturnId = !empty($body['exchange_return_id']) ? (int) $body['exchange_return_id'] : null;
$notes = mb_substr(trim((string) ($body['notes'] ?? '')), 0, 255) ?: null;
$pricesIncludeTax = setting('prices_include_tax', '1') === '1';

if (!$items) fail('The cart is empty.');
if (!$hold && !can('pos.checkout')) fail('Only cashiers can take payment. Hold the sale so a cashier can complete it.', 403);
if ($hold && $exchangeReturnId) fail('A sale with an exchange credit cannot be held. Complete it now.');

$allowedMethods = ['cash', 'card', 'bank_transfer', 'mobile_wallet', 'store_credit'];

try {
    $pdo->beginTransaction();

    // ---- Authoritative catalog data, row-locked against concurrent oversell ----
    $variantIds = array_values(array_unique(array_map(fn($i) => (int) ($i['variant_id'] ?? 0), $items)));
    $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT pv.id, COALESCE(bs.price, pv.price) AS price, COALESCE(bs.cost_price, pv.cost_price) AS cost_price, COALESCE(bs.stock_qty, 0) AS stock_qty, pv.is_active, p.is_active AS product_active, p.tax_rate, p.name AS product_name, pv.variant_label
         FROM product_variants pv JOIN products p ON p.id = pv.product_id LEFT JOIN branch_stock bs ON bs.variant_id = pv.id AND bs.branch_id = " . (int) $branchId . " WHERE pv.id IN ($placeholders) FOR UPDATE"
    );
    $stmt->execute($variantIds);
    $catalog = [];
    foreach ($stmt->fetchAll() as $row) { $catalog[$row['id']] = $row; }

    // Merge duplicate lines and validate.
    $merged = [];
    foreach ($items as $it) {
        $vid = (int) ($it['variant_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($qty <= 0 || !isset($catalog[$vid])) throw new RuntimeException('One or more items in the cart are invalid.');
        $merged[$vid]['qty'] = ($merged[$vid]['qty'] ?? 0) + $qty;
        $merged[$vid]['disc'] = ($merged[$vid]['disc'] ?? 0) + max(0, (float) ($it['discount_amount'] ?? 0));
    }

    $lines = [];
    $subtotal = 0.0;
    $lineDiscTotal = 0.0;
    foreach ($merged as $vid => $m) {
        $v = $catalog[$vid];
        $name = $v['product_name'] . ($v['variant_label'] ? ' (' . $v['variant_label'] . ')' : '');
        if (!$v['is_active'] || !$v['product_active']) throw new RuntimeException($name . ' is no longer available for sale.');
        if (!$hold && (int) $v['stock_qty'] < $m['qty']) throw new RuntimeException('Not enough stock for ' . $name . '. Only ' . (int) $v['stock_qty'] . ' left.');
        $gross = round((float) $v['price'] * $m['qty'], 2);
        $disc = min(round($m['disc'], 2), $gross);
        $subtotal += $gross;
        $lineDiscTotal += $disc;
        $lines[] = ['variant_id' => $vid, 'qty' => $m['qty'], 'unit_price' => (float) $v['price'], 'unit_cost' => (float) $v['cost_price'],
                    'gross' => $gross, 'disc' => $disc, 'tax_rate' => (float) $v['tax_rate']];
    }

    // ---- Allocate the bill discount across lines (so tax and refunds are exact) ----
    $netBeforeBill = $subtotal - $lineDiscTotal;
    $overallDiscount = min($overallDiscount, $netBeforeBill);
    $allocated = 0.0;
    $taxTotal = 0.0;
    $grandTotal = 0.0;
    foreach ($lines as $i => &$l) {
        $base = $l['gross'] - $l['disc'];
        $alloc = $i === array_key_last($lines)
            ? round($overallDiscount - $allocated, 2)
            : ($netBeforeBill > 0 ? round($overallDiscount * $base / $netBeforeBill, 2) : 0);
        $allocated += $alloc;
        $net = max(0, $base - $alloc);
        if ($pricesIncludeTax) {
            $tax = $l['tax_rate'] > 0 ? round($net - $net / (1 + $l['tax_rate'] / 100), 2) : 0;
            $lineTotal = round($net, 2);
        } else {
            $tax = round($net * $l['tax_rate'] / 100, 2);
            $lineTotal = round($net + $tax, 2);
        }
        $l['discount_amount'] = round($l['disc'] + $alloc, 2);
        $l['tax_amount'] = $tax;
        $l['line_total'] = $lineTotal;
        $taxTotal += $tax;
        $grandTotal += $lineTotal;
    }
    unset($l);
    $discountTotal = round($lineDiscTotal + $overallDiscount, 2);
    $grandTotal = round($grandTotal, 2);
    $taxTotal = round($taxTotal, 2);

    // ---- Discount authorization ----
    $discountApprovedBy = null;
    $limitPct = (float) setting('cashier_max_discount_pct', '10');
    if (!$hold && $discountTotal > 0 && $subtotal > 0 && !can('pos.discount_override') && ($discountTotal / $subtotal * 100) > $limitPct + 0.0001) {
        $approver = authorize_override($body['override_username'] ?? null, $body['override_password'] ?? null, 'pos.discount_override');
        if (!is_array($approver)) {
            $pdo->rollBack();
            fail($approver . ' (Discount of ' . number_format($discountTotal / $subtotal * 100, 1) . '% exceeds your ' . rtrim(rtrim(number_format($limitPct, 2), '0'), '.') . '% limit.)', 422, ['needs_override' => true]);
        }
        $discountApprovedBy = (int) $approver['id'];
    }

    // ---- Customer checks ----
    $customer = null;
    if ($customerId) {
        $c = $pdo->prepare('SELECT * FROM customers WHERE id = :id AND is_active = 1 FOR UPDATE');
        $c->execute([':id' => $customerId]);
        $customer = $c->fetch();
        if (!$customer) throw new RuntimeException('The selected customer was not found.');
    }

    // ---- Resume a held sale (prepared by an assistant or parked earlier) ----
    if ($resumeSaleId) {
        $check = $pdo->prepare("SELECT id, invoice_number FROM sales WHERE id = :id AND status = 'held' AND branch_id = :br FOR UPDATE");
        $check->execute([':id' => $resumeSaleId, ':br' => $branchId]);
        $heldRow = $check->fetch();
        if (!$heldRow) throw new RuntimeException('That held sale was already completed or removed.');
        $pdo->prepare('DELETE FROM sale_items WHERE sale_id = :id')->execute([':id' => $resumeSaleId]);
        $saleId = $resumeSaleId;
        $invoiceNumber = $heldRow['invoice_number'];
    } else {
        $saleId = null;
        $invoiceNumber = next_document_number(setting('invoice_prefix', 'INV'), 'sales', 'invoice_number', $branchId);
    }

    $insertItem = $pdo->prepare(
        'INSERT INTO sale_items (sale_id, variant_id, quantity, unit_price, unit_cost, discount_amount, tax_rate, tax_amount, line_total)
         VALUES (:s,:v,:q,:p,:c,:d,:tr,:ta,:lt)'
    );
    $saveItems = function (int $sid) use ($insertItem, $lines) {
        foreach ($lines as $l) {
            $insertItem->execute([':s' => $sid, ':v' => $l['variant_id'], ':q' => $l['qty'], ':p' => $l['unit_price'], ':c' => $l['unit_cost'],
                ':d' => $l['discount_amount'], ':tr' => $l['tax_rate'], ':ta' => $l['tax_amount'], ':lt' => $l['line_total']]);
        }
    };

    if ($hold) {
        if ($saleId) {
            $pdo->prepare('UPDATE sales SET customer_id=:c, subtotal=:sub, discount_amount=:disc, tax_amount=:tax, total_amount=:tot, notes=:n WHERE id=:id')
                ->execute([':c' => $customerId, ':sub' => $subtotal, ':disc' => $discountTotal, ':tax' => $taxTotal, ':tot' => $grandTotal, ':n' => $notes, ':id' => $saleId]);
        } else {
            $pdo->prepare("INSERT INTO sales (invoice_number, branch_id, customer_id, cashier_id, subtotal, discount_amount, tax_amount, total_amount, status, notes)
                           VALUES (:inv,:br,:c,:u,:sub,:disc,:tax,:tot,'held',:n)")
                ->execute([':inv' => $invoiceNumber, ':br' => $branchId, ':c' => $customerId, ':u' => $user['id'], ':sub' => $subtotal, ':disc' => $discountTotal, ':tax' => $taxTotal, ':tot' => $grandTotal, ':n' => $notes]);
            $saleId = (int) $pdo->lastInsertId();
        }
        $saveItems($saleId);
        $pdo->commit();
        log_activity('sale_hold', 'Held ' . $invoiceNumber . ' (' . format_money($grandTotal) . ')', 'sale', $saleId);
        echo json_encode(['success' => true, 'held' => true, 'sale_id' => $saleId, 'invoice_number' => $invoiceNumber]);
        exit;
    }

    // ---- Exchange credit ----
    $exchange = null;
    $exchangeUsed = 0.0;
    if ($exchangeReturnId) {
        $x = $pdo->prepare("SELECT * FROM sale_returns WHERE id = :id AND status = 'processed' AND return_type = 'exchange' AND exchange_sale_id IS NULL AND branch_id = :br FOR UPDATE");
        $x->execute([':id' => $exchangeReturnId, ':br' => $branchId]);
        $exchange = $x->fetch();
        if (!$exchange) throw new RuntimeException('That exchange credit is not valid or was already used.');
        $exchangeUsed = min((float) $exchange['total_amount'], $grandTotal);
    }

    // ---- Payments ----
    $dueAfterExchange = round($grandTotal - $exchangeUsed, 2);
    $paymentTotal = 0.0;
    $creditAmount = 0.0;
    $clean = [];
    foreach ($payments as $p) {
        $method = in_array($p['method'] ?? '', $allowedMethods, true) ? $p['method'] : null;
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if (!$method || $amount <= 0) throw new RuntimeException('One of the payment lines is invalid.');
        if ($method === 'store_credit') $creditAmount += $amount;
        $paymentTotal += $amount;
        $tendered = isset($p['tendered_amount']) && $p['tendered_amount'] !== null ? round((float) $p['tendered_amount'], 2) : null;
        if ($method === 'cash' && $tendered !== null && $tendered < $amount) $tendered = $amount;
        $clean[] = ['method' => $method, 'amount' => $amount, 'tendered' => $method === 'cash' ? $tendered : null,
                    'reference' => mb_substr(trim((string) ($p['reference_no'] ?? '')), 0, 60) ?: null];
    }
    if (abs($paymentTotal - $dueAfterExchange) > 0.01) {
        throw new RuntimeException('Payments (' . number_format($paymentTotal, 2) . ') do not match the amount due (' . number_format($dueAfterExchange, 2) . ').');
    }
    if ($creditAmount > 0) {
        if (!$customer) throw new RuntimeException('Select a registered customer to sell on credit.');
        if (!(int) $customer['allow_credit']) throw new RuntimeException($customer['name'] . ' is not allowed to buy on credit.');
        if ($customer['credit_limit'] !== null && (float) $customer['credit_balance'] + $creditAmount > (float) $customer['credit_limit'] + 0.001) {
            throw new RuntimeException('Credit limit exceeded: ' . $customer['name'] . ' owes ' . format_money($customer['credit_balance']) . ' with a limit of ' . format_money($customer['credit_limit']) . '.');
        }
    }

    // ---- Persist sale ----
    if ($saleId) {
        $pdo->prepare("UPDATE sales SET customer_id=:c, cashier_id=:u, subtotal=:sub, discount_amount=:disc, tax_amount=:tax, total_amount=:tot,
                       status='completed', sale_date=NOW(), notes=:n, discount_approved_by=:da WHERE id=:id")
            ->execute([':c' => $customerId, ':u' => $user['id'], ':sub' => $subtotal, ':disc' => $discountTotal, ':tax' => $taxTotal, ':tot' => $grandTotal, ':n' => $notes, ':da' => $discountApprovedBy, ':id' => $saleId]);
    } else {
        $pdo->prepare("INSERT INTO sales (invoice_number, branch_id, customer_id, cashier_id, subtotal, discount_amount, tax_amount, total_amount, status, notes, discount_approved_by)
                       VALUES (:inv,:br,:c,:u,:sub,:disc,:tax,:tot,'completed',:n,:da)")
            ->execute([':inv' => $invoiceNumber, ':br' => $branchId, ':c' => $customerId, ':u' => $user['id'], ':sub' => $subtotal, ':disc' => $discountTotal, ':tax' => $taxTotal, ':tot' => $grandTotal, ':n' => $notes, ':da' => $discountApprovedBy]);
        $saleId = (int) $pdo->lastInsertId();
    }
    $saveItems($saleId);
    foreach ($lines as $l) {
        apply_stock_change($pdo, $l['variant_id'], -$l['qty'], 'sale', 'sale', $saleId, 'Sale ' . $invoiceNumber, 'saleable', $branchId);
    }

    $insertPayment = $pdo->prepare('INSERT INTO sale_payments (sale_id, method, amount, tendered_amount, change_amount, reference_no) VALUES (:s,:m,:a,:t,:ch,:r)');
    $changeDue = 0.0;
    foreach ($clean as $p) {
        $change = ($p['method'] === 'cash' && $p['tendered'] !== null) ? max(0, round($p['tendered'] - $p['amount'], 2)) : null;
        $changeDue += (float) $change;
        $insertPayment->execute([':s' => $saleId, ':m' => $p['method'], ':a' => $p['amount'], ':t' => $p['tendered'], ':ch' => $change, ':r' => $p['reference']]);
    }

    $exchangeCashBack = 0.0;
    if ($exchange) {
        $insertPayment->execute([':s' => $saleId, ':m' => 'exchange', ':a' => $exchangeUsed, ':t' => null, ':ch' => null, ':r' => $exchange['return_number']]);
        $exchangeCashBack = round((float) $exchange['total_amount'] - $exchangeUsed, 2);
        $pdo->prepare('UPDATE sale_returns SET exchange_sale_id = :s, exchange_used = :u, exchange_cash_back = :cb WHERE id = :id')
            ->execute([':s' => $saleId, ':u' => $exchangeUsed, ':cb' => $exchangeCashBack, ':id' => $exchange['id']]);
    }

    if ($creditAmount > 0) {
        $newBalance = (float) $customer['credit_balance'] + $creditAmount;
        $pdo->prepare('UPDATE customers SET credit_balance = :b WHERE id = :id')->execute([':b' => $newBalance, ':id' => $customerId]);
        $pdo->prepare("INSERT INTO customer_ledger (customer_id, branch_id, sale_id, type, amount, balance_after, note, created_by) VALUES (:c,:br,:s,'credit_sale',:a,:bal,:note,:u)")
            ->execute([':c' => $customerId, ':br' => $branchId, ':s' => $saleId, ':a' => $creditAmount, ':bal' => $newBalance, ':note' => 'Credit sale ' . $invoiceNumber, ':u' => $user['id']]);
    }

    $pdo->commit();
    $detail = 'Sale ' . $invoiceNumber . ' · ' . format_money($grandTotal) . ' · ' . implode(', ', array_map(fn($p) => method_label($p['method']) . ' ' . number_format($p['amount'], 2), $clean));
    if ($discountTotal > 0) $detail .= ' · discount ' . number_format($discountTotal, 2) . ($discountApprovedBy ? ' (manager-approved)' : '');
    log_activity('sale_complete', $detail, 'sale', $saleId);
    if ($exchange) log_activity('exchange_redeemed', $exchange['return_number'] . ' credit ' . number_format($exchangeUsed, 2) . ' used on ' . $invoiceNumber, 'sale_return', (int) $exchange['id']);

    echo json_encode([
        'success' => true, 'sale_id' => $saleId, 'invoice_number' => $invoiceNumber, 'total' => $grandTotal,
        'change_due' => round($changeDue, 2), 'exchange_cash_back' => $exchangeCashBack,
    ]);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $isExpected = $ex instanceof RuntimeException;
    if (!$isExpected) error_log('POS checkout failed: ' . $ex->getMessage());
    fail($isExpected ? $ex->getMessage() : 'Checkout failed. Please try again.', $isExpected ? 422 : 500);
}
