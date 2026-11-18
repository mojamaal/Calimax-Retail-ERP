<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$id = input_int($_GET, 'id');

$stmt = $pdo->prepare(
    "SELECT po.*, s.name AS supplier_name, s.contact_person, s.phone, s.email, s.address, u.full_name AS created_by_name
     FROM purchase_orders po
     JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN users u ON u.id = po.created_by
     WHERE po.id = :id"
);
$stmt->execute([':id' => $id]);
$po = $stmt->fetch();
if (!$po) { die('Purchase order not found.'); }

$items = $pdo->prepare(
    "SELECT poi.*, pv.sku, pv.variant_label, p.name AS product_name
     FROM purchase_order_items poi
     JOIN product_variants pv ON pv.id = poi.variant_id
     JOIN products p ON p.id = pv.product_id
     WHERE poi.po_id = :id"
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();

require __DIR__ . '/_print_layout.php';
render_print_document([
    'title' => 'Purchase Order',
    'doc_number' => $po['po_number'],
    'branch_id' => assert_branch_access((int) $po['branch_id']) ?? (int) $po['branch_id'],
    'doc_date_label' => 'Order Date',
    'doc_date' => $po['order_date'],
    'extra_meta' => $po['expected_date'] ? [['Expected Delivery', $po['expected_date']]] : [],
    'party_label' => 'Supplier',
    'party_name' => $po['supplier_name'],
    'party_lines' => array_filter([$po['contact_person'], $po['phone'], $po['email'], $po['address']]),
    'items' => array_map(fn($it) => [
        'name' => $it['product_name'] . ($it['variant_label'] ? ' — ' . $it['variant_label'] : ''),
        'sku' => $it['sku'],
        'qty' => (int) $it['quantity'],
        'unit_cost' => (float) $it['unit_cost'],
        'line_total' => (float) $it['unit_cost'] * (int) $it['quantity'],
    ], $items),
    'total' => (float) $po['total_amount'],
    'notes' => $po['notes'],
    'status' => str_replace('_', ' ', $po['status']),
    'prepared_by' => $po['created_by_name'],
]);
