<?php
/** All parked carts (shared across tills so assistants can prepare sales for cashiers). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');
header('Content-Type: application/json');

$pdo = db();
if (is_post()) {
    // Discard a held cart.
    verify_csrf();
    $id = input_int($_POST, 'id');
    $s = $pdo->prepare("SELECT invoice_number FROM sales WHERE id = :id AND status = 'held'" . branch_and());
    $s->execute([':id' => $id]);
    if ($inv = $s->fetchColumn()) {
        $pdo->prepare("UPDATE sales SET status = 'cancelled', void_reason = 'Held cart discarded', voided_by = :u, voided_at = NOW() WHERE id = :id")
            ->execute([':u' => current_user()['id'], ':id' => $id]);
        log_activity('sale_hold_discard', 'Discarded held cart ' . $inv, 'sale', $id);
    }
    echo json_encode(['success' => true]);
    exit;
}

$stmt = $pdo->query(
    "SELECT s.id, s.invoice_number, s.total_amount, s.created_at, s.notes, COALESCE(c.name,'Walk-in') AS customer, u.full_name AS held_by,
            (SELECT COALESCE(SUM(quantity),0) FROM sale_items WHERE sale_id = s.id) AS item_count
     FROM sales s LEFT JOIN customers c ON c.id = s.customer_id JOIN users u ON u.id = s.cashier_id
     WHERE s.status = 'held'" . branch_and('s') . " ORDER BY s.created_at DESC"
);
echo json_encode($stmt->fetchAll());
