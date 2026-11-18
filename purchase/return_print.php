<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('purchase.manage');

$pdo = db();
$id = input_int($_GET, 'id');

$stmt = $pdo->prepare(
    "SELECT pr.*, s.name AS supplier_name, s.contact_person, s.phone, s.email, s.address, po.po_number, u.full_name AS created_by_name
     FROM purchase_returns pr
     JOIN suppliers s ON s.id = pr.supplier_id
     LEFT JOIN purchase_orders po ON po.id = pr.po_id
     LEFT JOIN users u ON u.id = pr.created_by
     WHERE pr.id = :id"
);
$stmt->execute([':id' => $id]);
$return = $stmt->fetch();
if (!$return) { die('Purchase return not found.'); }

$items = $pdo->prepare(
    "SELECT pri.*, pv.sku, pv.variant_label, p.name AS product_name
     FROM purchase_return_items pri JOIN product_variants pv ON pv.id = pri.variant_id JOIN products p ON p.id = pv.product_id
     WHERE pri.return_id = :id"
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();

require __DIR__ . '/_print_layout.php';
render_print_document([
    'title' => 'Purchase Return',
    'doc_number' => $return['return_number'],
    'branch_id' => assert_branch_access((int) $return['branch_id']) ?? (int) $return['branch_id'],
    'doc_date_label' => 'Return Date',
    'doc_date' => $return['return_date'],
    'extra_meta' => $return['po_number'] ? [['Against Purchase Order', $return['po_number']]] : [],
    'party_label' => 'Supplier',
    'party_name' => $return['supplier_name'],
    'party_lines' => array_filter([$return['contact_person'], $return['phone'], $return['email'], $return['address']]),
    'items' => array_map(fn($it) => [
        'name' => $it['product_name'] . ($it['variant_label'] ? ' — ' . $it['variant_label'] : ''),
        'sku' => $it['sku'],
        'qty' => (int) $it['quantity'],
        'unit_cost' => (float) $it['unit_cost'],
        'line_total' => (float) $it['unit_cost'] * (int) $it['quantity'],
    ], $items),
    'total' => (float) $return['total_amount'],
    'notes' => $return['reason'],
    'status' => '',
    'prepared_by' => $return['created_by_name'],
]);
