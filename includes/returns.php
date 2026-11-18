<?php
/**
 * Customer return processing. A return is created (pending or immediately
 * approved) by return_form.php and finalized here, so the same rules apply
 * whether a cashier with manager override or a manager later approves it.
 */

function return_reasons(): array
{
    return ['Defective / not working', 'Damaged item', 'Wrong item or size', 'Expired product', 'Not as described', 'Customer changed mind', 'Other'];
}

function refund_methods(): array
{
    return ['cash' => 'Cash', 'card' => 'Card reversal', 'bank_transfer' => 'Bank Transfer', 'mobile_wallet' => 'Mobile Payment', 'store_credit' => 'Credit to customer account'];
}

/** Quantities already returned or awaiting approval, per sale_item_id. */
function returned_quantities(PDO $pdo, int $saleId): array
{
    $s = $pdo->prepare(
        "SELECT ri.sale_item_id, SUM(ri.quantity) AS qty, SUM(ri.line_total) AS value
         FROM sale_return_items ri JOIN sale_returns r ON r.id = ri.return_id
         WHERE r.sale_id = :s AND r.status IN ('pending','processed') GROUP BY ri.sale_item_id"
    );
    $s->execute([':s' => $saleId]);
    $out = [];
    foreach ($s->fetchAll() as $r) { $out[(int) $r['sale_item_id']] = ['qty' => (int) $r['qty'], 'value' => (float) $r['value']]; }
    return $out;
}

/**
 * Finalize an approved return: restock (saleable or damaged bucket), refund to
 * the customer's account when chosen, update the original sale, and stamp the
 * approver. Runs inside the caller's transaction.
 */
function process_sale_return(PDO $pdo, int $returnId, int $approverId): void
{
    $r = $pdo->prepare("SELECT * FROM sale_returns WHERE id = :id FOR UPDATE");
    $r->execute([':id' => $returnId]);
    $ret = $r->fetch();
    if (!$ret || $ret['status'] !== 'pending') throw new RuntimeException('This return is no longer pending.');

    $items = $pdo->prepare('SELECT * FROM sale_return_items WHERE return_id = :id');
    $items->execute([':id' => $returnId]);
    foreach ($items->fetchAll() as $it) {
        $pdo->prepare('UPDATE sale_items SET returned_qty = returned_qty + :q WHERE id = :id')->execute([':q' => $it['quantity'], ':id' => $it['sale_item_id']]);
        if ($it['item_condition'] === 'damaged') {
            apply_stock_change($pdo, (int) $it['variant_id'], (int) $it['quantity'], 'return_damaged', 'sale_return', $returnId, 'Damaged return ' . $ret['return_number'] . ' — held out of saleable stock', 'damaged', (int) $ret['branch_id']);
        } else {
            apply_stock_change($pdo, (int) $it['variant_id'], (int) $it['quantity'], 'sale_return', 'sale_return', $returnId, 'Customer return ' . $ret['return_number'], 'saleable', (int) $ret['branch_id']);
        }
    }

    if ($ret['return_type'] === 'refund' && $ret['refund_method'] === 'store_credit' && $ret['customer_id']) {
        $c = $pdo->prepare('SELECT credit_balance FROM customers WHERE id = :id FOR UPDATE');
        $c->execute([':id' => $ret['customer_id']]);
        $newBal = (float) $c->fetchColumn() - (float) $ret['total_amount'];
        $pdo->prepare('UPDATE customers SET credit_balance = :b WHERE id = :id')->execute([':b' => $newBal, ':id' => $ret['customer_id']]);
        $pdo->prepare("INSERT INTO customer_ledger (customer_id, branch_id, sale_id, type, amount, balance_after, note, created_by) VALUES (:c,:br,:s,'refund',:a,:b,:n,:u)")
            ->execute([':c' => $ret['customer_id'], ':br' => $ret['branch_id'], ':s' => $ret['sale_id'], ':a' => -(float) $ret['total_amount'], ':b' => $newBal, ':n' => 'Return ' . $ret['return_number'], ':u' => $approverId]);
    }

    $left = $pdo->prepare('SELECT COALESCE(SUM(quantity - returned_qty),0) FROM sale_items WHERE sale_id = :s');
    $left->execute([':s' => $ret['sale_id']]);
    $newStatus = (int) $left->fetchColumn() <= 0 ? 'refunded' : 'partially_refunded';
    $pdo->prepare("UPDATE sales SET status = :st WHERE id = :id AND status IN ('completed','partially_refunded')")->execute([':st' => $newStatus, ':id' => $ret['sale_id']]);

    $pdo->prepare("UPDATE sale_returns SET status = 'processed', approved_by = :a, approved_at = NOW() WHERE id = :id")->execute([':a' => $approverId, ':id' => $returnId]);
}
