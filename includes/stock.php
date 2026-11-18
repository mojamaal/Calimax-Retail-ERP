<?php
/**
 * Stock adjustments: authorized changes to inventory outside of normal
 * purchasing/selling (damaged, expired, lost, found, count corrections).
 * Each reason defines which bucket moves and whether value is lost.
 */

/** reason => [label, description, direction(+1/-1/0=either), icon] */
function adjustment_reasons(): array
{
    return [
        'damaged'           => ['Mark as damaged', 'Move from saleable stock into damaged stock (not for sale).', -1, 'package-x'],
        'expired'           => ['Expired', 'Remove expired items from saleable stock (written off).', -1, 'calendar-x'],
        'lost'              => ['Lost / stolen', 'Items missing or stolen (written off).', -1, 'search-x'],
        'write_off_damaged' => ['Dispose damaged stock', 'Remove items from damaged stock (written off).', -1, 'trash-2'],
        'restore_damaged'   => ['Repaired / restored', 'Move items from damaged stock back to saleable.', 1, 'wrench'],
        'found'             => ['Found / extra stock', 'Add stock that was found or not previously recorded.', 1, 'package-plus'],
        'correction'        => ['Count correction', 'Correct the quantity after a physical check (+ or âˆ’).', 0, 'scale'],
    ];
}

function adjustment_reason_label(string $reason): string
{
    return adjustment_reasons()[$reason][0] ?? ucwords(str_replace('_', ' ', $reason));
}

/** Apply an adjustment's items to stock and mark it approved. Runs inside the caller's transaction. */
function apply_stock_adjustment(PDO $pdo, int $adjustmentId, int $approverId): void
{
    $a = $pdo->prepare('SELECT * FROM stock_adjustments WHERE id = :id FOR UPDATE');
    $a->execute([':id' => $adjustmentId]);
    $adj = $a->fetch();
    if (!$adj || $adj['status'] !== 'pending') throw new RuntimeException('This adjustment is no longer pending.');

    $br = (int) $adj['branch_id'];   // stock is adjusted in the branch the request was raised in
    $items = $pdo->prepare('SELECT sai.*, pv.stock_qty, pv.damaged_qty, pv.sku, p.name FROM stock_adjustment_items sai JOIN ' . pv($br) . ' pv ON pv.id = sai.variant_id JOIN products p ON p.id = pv.product_id WHERE sai.adjustment_id = :id');
    $items->execute([':id' => $adjustmentId]);
    $value = 0.0;
    $note = $adj['adj_number'] . ' Â· ' . adjustment_reason_label($adj['reason']) . ($adj['note'] ? ' Â· ' . $adj['note'] : '');
    foreach ($items->fetchAll() as $it) {
        $q = (int) $it['qty_change'];           // signed change to the primary bucket
        $vid = (int) $it['variant_id'];
        $cost = (float) $it['unit_cost'];
        $label = $it['name'] . ' (' . $it['sku'] . ')';
        $abs = abs($q);
        switch ($adj['reason']) {
            case 'damaged':
                if ((int) $it['stock_qty'] < $abs) throw new RuntimeException("Only {$it['stock_qty']} saleable units of $label to mark as damaged.");
                apply_stock_change($pdo, $vid, -$abs, 'damaged', 'adjustment', $adjustmentId, $note, 'saleable', $br);
                apply_stock_change($pdo, $vid, $abs, 'damaged', 'adjustment', $adjustmentId, $note, 'damaged', $br);
                break;
            case 'restore_damaged':
                if ((int) $it['damaged_qty'] < $abs) throw new RuntimeException("Only {$it['damaged_qty']} damaged units of $label.");
                apply_stock_change($pdo, $vid, -$abs, 'adjustment', 'adjustment', $adjustmentId, $note, 'damaged', $br);
                apply_stock_change($pdo, $vid, $abs, 'adjustment', 'adjustment', $adjustmentId, $note, 'saleable', $br);
                break;
            case 'write_off_damaged':
                if ((int) $it['damaged_qty'] < $abs) throw new RuntimeException("Only {$it['damaged_qty']} damaged units of $label to dispose.");
                apply_stock_change($pdo, $vid, -$abs, 'write_off', 'adjustment', $adjustmentId, $note, 'damaged', $br);
                $value -= $abs * $cost;
                break;
            case 'expired':
            case 'lost':
                if ((int) $it['stock_qty'] < $abs) throw new RuntimeException("Only {$it['stock_qty']} units of $label in stock.");
                apply_stock_change($pdo, $vid, -$abs, 'write_off', 'adjustment', $adjustmentId, $note, 'saleable', $br);
                $value -= $abs * $cost;
                break;
            case 'found':
                apply_stock_change($pdo, $vid, $abs, 'adjustment', 'adjustment', $adjustmentId, $note, 'saleable', $br);
                $value += $abs * $cost;
                break;
            default: // correction (signed)
                if ((int) $it['stock_qty'] + $q < 0) throw new RuntimeException("Correction would make $label negative (current {$it['stock_qty']}).");
                apply_stock_change($pdo, $vid, $q, $adj['source'] === 'count' ? 'count' : 'adjustment', 'adjustment', $adjustmentId, $note, 'saleable', $br);
                $value += $q * $cost;
        }
    }
    $pdo->prepare("UPDATE stock_adjustments SET status = 'approved', approved_by = :u, approved_at = NOW(), value_change = :v WHERE id = :id")
        ->execute([':u' => $approverId, ':v' => round($value, 2), ':id' => $adjustmentId]);
}

/**
 * Create an adjustment from [ ['variant_id'=>, 'qty'=>signed or positive, 'note'=>] ].
 * Approves immediately when $autoApprove. Returns the new id.
 */
function create_stock_adjustment(PDO $pdo, string $reason, array $lines, ?string $note, bool $autoApprove, string $source = 'manual', ?int $countId = null, ?int $branchId = null): int
{
    $reasons = adjustment_reasons();
    if (!isset($reasons[$reason])) throw new RuntimeException('Choose a valid reason.');
    if (!$lines) throw new RuntimeException('Add at least one item.');
    $dir = $reasons[$reason][2];
    $br = $branchId ?? write_branch();
    $number = next_document_number('ADJ', 'stock_adjustments', 'adj_number', $br);
    $user = current_user();
    $pdo->prepare('INSERT INTO stock_adjustments (adj_number, branch_id, reason, status, source, count_id, note, requested_by) VALUES (:n,:br,:r,\'pending\',:s,:c,:note,:u)')
        ->execute([':n' => $number, ':br' => $br, ':r' => $reason, ':s' => $source, ':c' => $countId, ':note' => $note ?: null, ':u' => $user['id']]);
    $id = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO stock_adjustment_items (adjustment_id, variant_id, qty_change, unit_cost, note) VALUES (:a,:v,:q,:c,:n)');
    $costStmt = $pdo->prepare('SELECT cost_price FROM ' . pv($br) . ' v WHERE id = :id');
    foreach ($lines as $l) {
        $q = (int) $l['qty'];
        if ($q === 0) continue;
        if ($dir !== 0) $q = abs($q) * $dir;
        $costStmt->execute([':id' => (int) $l['variant_id']]);
        $cost = $costStmt->fetchColumn();
        if ($cost === false) throw new RuntimeException('A selected product no longer exists.');
        $ins->execute([':a' => $id, ':v' => (int) $l['variant_id'], ':q' => $q, ':c' => (float) $cost, ':n' => $l['note'] ?? null]);
    }
    if ($autoApprove) apply_stock_adjustment($pdo, $id, (int) $user['id']);
    return $id;
}


