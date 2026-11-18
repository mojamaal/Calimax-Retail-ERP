<?php
/** Topbar search across products, sales, customers, suppliers and POs — scoped by permission. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
header('Content-Type: application/json');

$q = input($_GET, 'q');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }
$like = '%' . $q . '%';
$pdo = db();
$out = [];

if (can('products.view')) {
    $s = $pdo->prepare(
        "SELECT p.id, p.name, pv.variant_label, pv.sku, pv.barcode, pv.price, pv.stock_qty
         FROM " . pv() . " pv JOIN products p ON p.id = pv.product_id
         WHERE pv.barcode = :exact OR pv.sku LIKE :q1 OR p.name LIKE :q2 OR p.brand LIKE :q3
         ORDER BY (pv.barcode = :exact2) DESC, p.name LIMIT 6"
    );
    $s->execute([':exact' => $q, ':exact2' => $q, ':q1' => $like, ':q2' => $like, ':q3' => $like]);
    foreach ($s->fetchAll() as $r) {
        $out['products'][] = [
            'title' => $r['name'] . ($r['variant_label'] ? ' — ' . $r['variant_label'] : ''),
            'sub' => $r['sku'] . ' · ' . $r['barcode'] . ' · ' . (int) $r['stock_qty'] . ' in stock',
            'meta' => format_money($r['price']),
            'url' => app_url('/inventory/product_view.php?id=' . $r['id']),
        ];
    }
}
if (can('pos.access')) {
    $sql = "SELECT s.id, s.invoice_number, s.total_amount, s.sale_date, s.status, COALESCE(c.name,'Walk-in') AS customer
            FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
            WHERE s.status <> 'held' AND (s.invoice_number LIKE :q1 OR c.name LIKE :q2 OR c.phone LIKE :q3)" . branch_and('s');
    $params = [':q1' => $like, ':q2' => $like, ':q3' => $like];
    if (!can('sales.view_all')) { $sql .= ' AND s.cashier_id = :u'; $params[':u'] = current_user()['id']; }
    $s = $pdo->prepare($sql . ' ORDER BY s.sale_date DESC LIMIT 5');
    $s->execute($params);
    foreach ($s->fetchAll() as $r) {
        $out['sales'][] = ['title' => $r['invoice_number'] . ' · ' . $r['customer'], 'sub' => fmt_datetime($r['sale_date']) . ' · ' . ucwords(str_replace('_', ' ', $r['status'])), 'meta' => format_money($r['total_amount']), 'url' => app_url('/pos/sale_view.php?id=' . $r['id'])];
    }
}
if (can('customers.view')) {
    $s = $pdo->prepare('SELECT id, name, phone, credit_balance FROM customers WHERE name LIKE :q1 OR phone LIKE :q2 ORDER BY name LIMIT 5');
    $s->execute([':q1' => $like, ':q2' => $like]);
    foreach ($s->fetchAll() as $r) {
        $out['customers'][] = ['title' => $r['name'], 'sub' => $r['phone'] ?: 'No phone', 'meta' => (float) $r['credit_balance'] > 0 ? 'Owes ' . format_money($r['credit_balance']) : '', 'url' => app_url('/customers/view.php?id=' . $r['id'])];
    }
}
if (can('suppliers.view')) {
    $s = $pdo->prepare('SELECT s.id, s.name, s.contact_person, s.phone, ' . supplier_balance_sql('s') . ' AS balance FROM suppliers s WHERE name LIKE :q1 OR contact_person LIKE :q2 OR phone LIKE :q3 ORDER BY name LIMIT 4');
    $s->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    foreach ($s->fetchAll() as $r) {
        $out['suppliers'][] = ['title' => $r['name'], 'sub' => trim(($r['contact_person'] ?? '') . ' ' . ($r['phone'] ?? '')), 'meta' => (float) $r['balance'] > 0 ? 'Owed ' . format_money($r['balance']) : '', 'url' => app_url('/purchase/supplier_view.php?id=' . $r['id'])];
    }
}
if (can('purchase.manage')) {
    $s = $pdo->prepare('SELECT po.id, po.po_number, po.status, po.total_amount, s.name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.po_number LIKE :q' . branch_and('po') . ' ORDER BY po.id DESC LIMIT 4');
    $s->execute([':q' => $like]);
    foreach ($s->fetchAll() as $r) {
        $out['purchases'][] = ['title' => $r['po_number'] . ' · ' . $r['name'], 'sub' => ucwords(str_replace('_', ' ', $r['status'])), 'meta' => format_money($r['total_amount']), 'url' => app_url('/purchase/order_view.php?id=' . $r['id'])];
    }
}
echo json_encode($out);
