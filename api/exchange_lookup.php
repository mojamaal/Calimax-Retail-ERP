<?php
/** Look up an unused exchange credit by return number or id (for the POS exchange flow). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.checkout');
header('Content-Type: application/json');

$ref = input($_GET, 'ref');
$stmt = db()->prepare(
    "SELECT r.id, r.return_number, r.total_amount, r.exchange_sale_id, r.status, r.return_type, s.invoice_number, c.id AS customer_id, c.name AS customer_name, c.phone AS customer_phone, c.credit_balance, c.allow_credit, c.credit_limit
     FROM sale_returns r JOIN sales s ON s.id = r.sale_id LEFT JOIN customers c ON c.id = r.customer_id
     WHERE (r.return_number = :ref OR r.id = :id)" . branch_and('r') . " LIMIT 1"
);
$stmt->execute([':ref' => $ref, ':id' => ctype_digit($ref) ? (int) $ref : 0]);
$r = $stmt->fetch();
if (!$r || $r['return_type'] !== 'exchange') { http_response_code(404); echo json_encode(['error' => 'No exchange found with that number at this branch.']); exit; }
if ($r['status'] !== 'processed') { http_response_code(422); echo json_encode(['error' => 'This exchange has not been approved yet.']); exit; }
if ($r['exchange_sale_id']) { http_response_code(422); echo json_encode(['error' => 'This exchange credit was already used.']); exit; }

echo json_encode([
    'id' => (int) $r['id'], 'return_number' => $r['return_number'], 'amount' => (float) $r['total_amount'], 'invoice_number' => $r['invoice_number'],
    'customer' => $r['customer_id'] ? ['id' => (int) $r['customer_id'], 'name' => $r['customer_name'], 'phone' => $r['customer_phone'], 'credit_balance' => $r['credit_balance'], 'allow_credit' => (int) $r['allow_credit'], 'credit_limit' => $r['credit_limit']] : null,
]);
