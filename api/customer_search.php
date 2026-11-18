<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['customers.view', 'pos.access']);
header('Content-Type: application/json');

$q = input($_GET, 'q');
if ($q === '') { echo json_encode([]); exit; }

$stmt = db()->prepare(
    "SELECT id, name, phone, credit_balance, allow_credit, credit_limit FROM customers
     WHERE is_active = 1 AND (name LIKE :q1 OR phone LIKE :q2) ORDER BY (phone = :exact) DESC, name LIMIT 15"
);
$needle = '%' . $q . '%';
$stmt->execute([':q1' => $needle, ':q2' => $needle, ':exact' => $q]);
echo json_encode($stmt->fetchAll());
