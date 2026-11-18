<?php
/**
 * Stock transfers between branches.
 *
 *   send (create)  → stock leaves the sending branch immediately and is "in transit"
 *   receive        → the receiving branch counts what arrived; that amount joins its stock
 *                    (at the sender's cost), any shortfall goes back to the sender's stock
 *   cancel         → while still in transit the sender can cancel; everything returns to the sender
 *
 * Only saleable stock is transferred. Value never disappears: stock is either in a branch or in transit.
 */

/**
 * @param array $lines [ ['variant_id' => int, 'qty' => int], ... ]
 * Runs inside the caller's transaction. Returns the new transfer id.
 */
function create_stock_transfer(PDO $pdo, int $fromBranch, int $toBranch, array $lines, ?string $note): int
{
    $branches = branches_all();
    if ($fromBranch === $toBranch) throw new RuntimeException('Choose a different branch to send the stock to.');
    if (!isset($branches[$fromBranch]) || !isset($branches[$toBranch])) throw new RuntimeException('Choose an active destination branch.');

    $merged = [];
    foreach ($lines as $l) {
        $vid = (int) ($l['variant_id'] ?? 0); $q = (int) ($l['qty'] ?? 0);
        if ($vid && $q > 0) $merged[$vid] = ($merged[$vid] ?? 0) + $q;
    }
    if (!$merged) throw new RuntimeException('Add at least one item with a quantity.');

    $number = next_document_number(setting('transfer_prefix', 'TRF'), 'stock_transfers', 'transfer_number', $fromBranch);
    $pdo->prepare("INSERT INTO stock_transfers (transfer_number, from_branch_id, to_branch_id, status, note, created_by) VALUES (:n,:f,:t,'in_transit',:note,:u)")
        ->execute([':n' => $number, ':f' => $fromBranch, ':t' => $toBranch, ':note' => $note ?: null, ':u' => current_user()['id'] ?? null]);
    $id = (int) $pdo->lastInsertId();

    $ins = $pdo->prepare('INSERT INTO stock_transfer_items (transfer_id, variant_id, quantity, unit_cost) VALUES (:t,:v,:q,:c)');
    foreach ($merged as $vid => $qty) {
        ensure_branch_stock($pdo, $vid, $fromBranch);
        $s = $pdo->prepare('SELECT bs.stock_qty, bs.cost_price, v.sku FROM branch_stock bs JOIN product_variants v ON v.id = bs.variant_id WHERE bs.variant_id = :v AND bs.branch_id = :b FOR UPDATE');
        $s->execute([':v' => $vid, ':b' => $fromBranch]);
        $row = $s->fetch();
        if (!$row) throw new RuntimeException('A selected product no longer exists.');
        if ((int) $row['stock_qty'] < $qty) throw new RuntimeException('Only ' . (int) $row['stock_qty'] . ' saleable unit(s) of ' . $row['sku'] . ' available at ' . $branches[$fromBranch]['name'] . '.');
        $ins->execute([':t' => $id, ':v' => $vid, ':q' => $qty, ':c' => (float) $row['cost_price']]);
        apply_stock_change($pdo, $vid, -$qty, 'transfer_out', 'stock_transfer', $id, $number . ' → ' . $branches[$toBranch]['name'], 'saleable', $fromBranch);
    }
    return $id;
}

/** @param array<int,int> $received transfer_item_id => quantity counted at the destination */
function receive_stock_transfer(PDO $pdo, int $transferId, array $received): array
{
    $t = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE');
    $t->execute([':id' => $transferId]);
    $tr = $t->fetch();
    if (!$tr || $tr['status'] !== 'in_transit') throw new RuntimeException('This transfer is no longer in transit.');
    $from = (int) $tr['from_branch_id']; $to = (int) $tr['to_branch_id'];

    $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = :id');
    $items->execute([':id' => $transferId]);
    $upd = $pdo->prepare('UPDATE stock_transfer_items SET received_qty = :r WHERE id = :id');
    $got = 0; $short = 0;
    foreach ($items->fetchAll() as $it) {
        $sent = (int) $it['quantity'];
        $r = (int) ($received[(int) $it['id']] ?? $sent);
        if ($r < 0 || $r > $sent) throw new RuntimeException('Received quantity cannot be more than the ' . $sent . ' sent.');
        $vid = (int) $it['variant_id'];
        if ($r > 0) {
            update_average_cost($pdo, $vid, $r, (float) $it['unit_cost'], $to);   // before adding, so the average is weighted correctly
            apply_stock_change($pdo, $vid, $r, 'transfer_in', 'stock_transfer', $transferId, $tr['transfer_number'] . ' received', 'saleable', $to);
        }
        if ($r < $sent) {
            apply_stock_change($pdo, $vid, $sent - $r, 'transfer_return', 'stock_transfer', $transferId, $tr['transfer_number'] . ' shortfall returned to sender', 'saleable', $from);
        }
        $upd->execute([':r' => $r, ':id' => $it['id']]);
        $got += $r; $short += $sent - $r;
    }
    $pdo->prepare("UPDATE stock_transfers SET status = 'received', received_by = :u, received_at = NOW() WHERE id = :id")
        ->execute([':u' => current_user()['id'] ?? null, ':id' => $transferId]);
    return ['number' => $tr['transfer_number'], 'received' => $got, 'short' => $short, 'to' => $to, 'from' => $from];
}

function cancel_stock_transfer(PDO $pdo, int $transferId): array
{
    $t = $pdo->prepare('SELECT * FROM stock_transfers WHERE id = :id FOR UPDATE');
    $t->execute([':id' => $transferId]);
    $tr = $t->fetch();
    if (!$tr || $tr['status'] !== 'in_transit') throw new RuntimeException('Only a transfer that is still in transit can be cancelled.');
    $items = $pdo->prepare('SELECT * FROM stock_transfer_items WHERE transfer_id = :id');
    $items->execute([':id' => $transferId]);
    $units = 0;
    foreach ($items->fetchAll() as $it) {
        apply_stock_change($pdo, (int) $it['variant_id'], (int) $it['quantity'], 'transfer_return', 'stock_transfer', $transferId, $tr['transfer_number'] . ' cancelled', 'saleable', (int) $tr['from_branch_id']);
        $units += (int) $it['quantity'];
    }
    $pdo->prepare("UPDATE stock_transfers SET status = 'cancelled', cancelled_by = :u, cancelled_at = NOW() WHERE id = :id")
        ->execute([':u' => current_user()['id'] ?? null, ':id' => $transferId]);
    return ['number' => $tr['transfer_number'], 'units' => $units];
}

/** May the user see this transfer (sender or receiver branch)? */
function can_access_transfer(array $tr): bool
{
    if (user_has_all_branches()) return true;
    $mine = array_keys(accessible_branches());
    return in_array((int) $tr['from_branch_id'], $mine, true) || in_array((int) $tr['to_branch_id'], $mine, true);
}
