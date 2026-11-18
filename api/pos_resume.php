<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');
header('Content-Type: application/json');

$id = input_int($_GET, 'id');
$pdo = db();

$stmt = $pdo->prepare(
    "SELECT s.*, c.name AS customer_name, c.phone AS customer_phone, c.credit_balance AS customer_credit_balance, c.allow_credit, c.credit_limit
     FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = :id AND s.status = 'held'" . branch_and('s')
);
$stmt->execute([':id' => $id]);
$sale = $stmt->fetch();
if (!$sale) { http_response_code(404); echo json_encode(['error' => 'Held sale not found.']); exit; }

$items = $pdo->prepare(
    "SELECT si.variant_id, si.quantity AS qty, si.discount_amount, pv.sku, pv.barcode, pv.variant_label, pv.price, pv.stock_qty, p.name AS product_name, p.image, p.tax_rate
     FROM sale_items si JOIN " . pv() . " pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id WHERE si.sale_id = :id"
);
$items->execute([':id' => $id]);

echo json_encode([
    'sale_id' => (int) $sale['id'],
    'invoice_number' => $sale['invoice_number'],
    'notes' => $sale['notes'],
    'customer' => $sale['customer_id'] ? [
        'id' => (int) $sale['customer_id'], 'name' => $sale['customer_name'], 'phone' => $sale['customer_phone'],
        'credit_balance' => $sale['customer_credit_balance'], 'allow_credit' => (int) $sale['allow_credit'], 'credit_limit' => $sale['credit_limit'],
    ] : null,
    'items' => $items->fetchAll(),
]);
