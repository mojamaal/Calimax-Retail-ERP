<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission(['purchase.receive','purchase.manage']);

$pdo = db();
$id = input_int($_GET, 'id');

$stmt = $pdo->prepare(
    "SELECT g.*, po.po_number, s.name AS supplier_name, s.contact_person, s.phone, s.email, s.address, u.full_name AS created_by_name
     FROM goods_received_notes g
     JOIN purchase_orders po ON po.id = g.po_id
     JOIN suppliers s ON s.id = po.supplier_id
     LEFT JOIN users u ON u.id = g.created_by
     WHERE g.id = :id"
);
$stmt->execute([':id' => $id]);
$grn = $stmt->fetch();
if (!$grn) { die('GRN not found.'); }

$items = $pdo->prepare(
    "SELECT gi.*, pv.sku, pv.variant_label, p.name AS product_name
     FROM grn_items gi JOIN product_variants pv ON pv.id = gi.variant_id JOIN products p ON p.id = pv.product_id
     WHERE gi.grn_id = :id"
);
$items->execute([':id' => $id]);
$items = $items->fetchAll();
$total = array_sum(array_map(fn($i) => $i['quantity'] * $i['unit_cost'], $items));

require __DIR__ . '/_print_layout.php';
render_print_document([
    'title' => 'Goods Received Note',
    'doc_number' => $grn['grn_number'],
    'branch_id' => assert_branch_access((int) $grn['branch_id']) ?? (int) $grn['branch_id'],
    'doc_date_label' => 'Received Date',
    'doc_date' => $grn['received_date'],
    'extra_meta' => [['Purchase Order', $grn['po_number']]],
    'party_label' => 'Supplier',
    'party_name' => $grn['supplier_name'],
    'party_lines' => array_filter([$grn['contact_person'], $grn['phone'], $grn['email'], $grn['address']]),
    'items' => array_map(fn($it) => [
        'name' => $it['product_name'] . ($it['variant_label'] ? ' — ' . $it['variant_label'] : ''),
        'sku' => $it['sku'],
        'qty' => (int) $it['quantity'],
        'unit_cost' => (float) $it['unit_cost'],
        'line_total' => (float) $it['unit_cost'] * (int) $it['quantity'],
    ], $items),
    'total' => (float) $total,
    'notes' => $grn['notes'],
    'status' => '',
    'prepared_by' => $grn['created_by_name'],
]);
