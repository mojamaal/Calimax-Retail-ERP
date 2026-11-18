<?php
/**
 * Product variant lookup used by the POS, purchasing, returns and stock forms.
 *   ?barcode=...            exact barcode/SKU match (scanner) -> single object|null
 *   ?q=...&category_id=..   search -> list
 *   ?low=1                  items at/below reorder level (purchase suggestions)
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['products.view', 'pos.access', 'purchase.manage', 'inventory.adjust', 'inventory.count']);
header('Content-Type: application/json');

$pdo = db();
$q = input($_GET, 'q');
$barcode = input($_GET, 'barcode');
$categoryId = input_int($_GET, 'category_id');
$includeCost = can('products.view_cost') || can('purchase.manage');

$cols = 'pv.id, pv.sku, pv.barcode, pv.variant_label, pv.price, pv.stock_qty, pv.damaged_qty, pv.reorder_level,
         p.id AS product_id, p.name AS product_name, p.image, p.tax_rate, p.unit, p.category_id'
      . ($includeCost ? ', pv.cost_price' : '');
// Stock, price, cost and reorder level are the CURRENT BRANCH's figures (see pv()).
$from = 'FROM ' . pv() . ' pv JOIN products p ON p.id = pv.product_id WHERE pv.is_active = 1 AND p.is_active = 1';

if ($barcode !== '') {
    $stmt = $pdo->prepare("SELECT $cols $from AND (pv.barcode = :bc OR pv.sku = :sku) LIMIT 1");
    $stmt->execute([':bc' => $barcode, ':sku' => $barcode]);
    echo json_encode($stmt->fetch() ?: null);
    exit;
}

if (!empty($_GET['low'])) {
    $stmt = $pdo->query("SELECT $cols $from AND pv.stock_qty <= pv.reorder_level ORDER BY (pv.stock_qty - pv.reorder_level), p.name LIMIT 200");
    echo json_encode($stmt->fetchAll());
    exit;
}

$sql = "SELECT $cols $from";
$params = [];
if ($q !== '') {
    $sql .= ' AND (p.name LIKE :q1 OR pv.sku LIKE :q2 OR pv.barcode LIKE :q3 OR pv.variant_label LIKE :q4 OR p.brand LIKE :q5)';
    $needle = '%' . $q . '%';
    $params += [':q1' => $needle, ':q2' => $needle, ':q3' => $needle, ':q4' => $needle, ':q5' => $needle];
}
if ($categoryId) { $sql .= ' AND p.category_id = :cat'; $params[':cat'] = $categoryId; }
$sql .= ' ORDER BY p.name, pv.variant_label LIMIT 60';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll());
