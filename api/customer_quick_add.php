<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('customers.create');
require_post();
header('Content-Type: application/json');
verify_csrf();

$name = input($_POST, 'name');
$phone = input($_POST, 'phone');

if ($name === '') { http_response_code(422); echo json_encode(['error' => 'Customer name is required.']); exit; }
if ($phone !== '') {
    $dup = db()->prepare('SELECT id, name FROM customers WHERE phone = :p LIMIT 1');
    $dup->execute([':p' => $phone]);
    if ($d = $dup->fetch()) { http_response_code(422); echo json_encode(['error' => 'That phone number already belongs to ' . $d['name'] . '.']); exit; }
}

db()->prepare('INSERT INTO customers (name, phone, created_by) VALUES (:n, :p, :u)')
    ->execute([':n' => $name, ':p' => $phone ?: null, ':u' => current_user()['id']]);
$id = (int) db()->lastInsertId();
log_activity('customer_create', 'Registered customer ' . $name . ($phone ? ' (' . $phone . ')' : '') . ' at POS', 'customer', $id);

echo json_encode(['id' => $id, 'name' => $name, 'phone' => $phone, 'credit_balance' => 0, 'allow_credit' => 1, 'credit_limit' => null]);
