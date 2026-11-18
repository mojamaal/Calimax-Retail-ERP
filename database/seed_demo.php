<?php
/**
 * CLI-only demo data generator: 5 years of realistic purchase/sales/expense history.
 * Run AFTER the schema upgrade:  php database/migrate.php  &&  php database/seed_demo.php
 * Not web-accessible (blocked by database/.htaccess).
 */
if (PHP_SAPI !== 'cli') { die('CLI only.'); }

require_once __DIR__ . '/../includes/functions.php'; // pulls in config/db.php
$pdo = db();

$startTime = microtime(true);
$today = new DateTime('today');
$startDate = (clone $today)->modify('-5 years');

echo "Seeding demo data from {$startDate->format('Y-m-d')} to {$today->format('Y-m-d')}...\n";

// -----------------------------------------------------------------------
// Lookups
// -----------------------------------------------------------------------
function lookupMap(PDO $pdo, string $table, string $keyCol, string $valCol, ?string $whereCol = null, $whereVal = null): array
{
    $sql = "SELECT $keyCol, $valCol FROM $table";
    if ($whereCol) $sql .= " WHERE $whereCol = :w";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($whereCol ? [':w' => $whereVal] : []);
    $map = [];
    foreach ($stmt->fetchAll() as $row) { $map[$row[$keyCol]] = $row[$valCol]; }
    return $map;
}

$categoryIds = array_flip(lookupMap($pdo, 'categories', 'id', 'name'));
$attrIds = array_flip(lookupMap($pdo, 'attributes', 'id', 'name')); // name -> id
$sizeValues = array_flip(lookupMap($pdo, 'attribute_values', 'id', 'value', 'attribute_id', $attrIds['Size']));
$colorValues = array_flip(lookupMap($pdo, 'attribute_values', 'id', 'value', 'attribute_id', $attrIds['Color']));

// Ensure any color used below but missing from the seeded palette exists.
$insColorValue = $pdo->prepare('INSERT IGNORE INTO attribute_values (attribute_id, value) VALUES (?, ?)');
foreach (['Brown', 'Beige'] as $extraColor) {
    if (!isset($colorValues[$extraColor])) {
        $insColorValue->execute([$attrIds['Color'], $extraColor]);
        $colorValues[$extraColor] = (int) $pdo->lastInsertId();
    }
}
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();

// -----------------------------------------------------------------------
// Suppliers
// -----------------------------------------------------------------------
$supplierNames = [
    ['Textile Traders Ltd', 'Nimal Perera', '0771234567', 'nimal@textiletraders.lk'],
    ['Colombo Fabric House', 'Sanduni Fernando', '0772345678', 'sanduni@colombofabric.lk'],
    ['Lanka Apparel Imports', 'Ruwan Jayasuriya', '0773456789', 'ruwan@lankaapparel.lk'],
    ['Kandy Garment Suppliers', 'Chathuri Bandara', '0774567890', 'chathuri@kandygarment.lk'],
    ['Southern Textile Mills', 'Dinesh Wickramasinghe', '0775678901', 'dinesh@southerntextile.lk'],
];
$supplierIds = [];
$insSupplier = $pdo->prepare('INSERT INTO suppliers (name, contact_person, phone, email, address) VALUES (?,?,?,?,?)');
foreach ($supplierNames as $s) {
    $insSupplier->execute([$s[0], $s[1], $s[2], $s[3], 'Colombo, Sri Lanka']);
    $supplierIds[] = (int) $pdo->lastInsertId();
}
echo 'Suppliers: ' . count($supplierIds) . "\n";

// -----------------------------------------------------------------------
// Products + variants
// -----------------------------------------------------------------------
$productTemplates = [
    // [name, category, brand, price, cost, sizes[], colors[]]
    ['Classic Cotton T-Shirt', 'Men', 'Calimax Basics', 2200, 1000, ['S','M','L','XL','XXL'], ['Black','White','Navy']],
    ['Slim Fit Jeans', 'Men', 'DenimCo', 5500, 2800, ['S','M','L','XL'], ['Black','Blue']],
    ['Formal Shirt', 'Men', 'Calimax Formal', 4200, 2000, ['S','M','L','XL','XXL'], ['White','Blue','Black']],
    ['Polo Shirt', 'Men', 'SportLine', 3200, 1500, ['S','M','L','XL'], ['Red','Blue','Green','Black']],
    ['Hooded Sweatshirt', 'Men', 'UrbanWear', 6500, 3200, ['S','M','L','XL'], ['Grey','Black','Navy']],
    ['Floral Summer Dress', 'Women', 'Calimax Women', 4800, 2200, ['XS','S','M','L'], ['Red','Blue','White']],
    ['Casual Blouse', 'Women', 'ElegantLine', 3600, 1700, ['XS','S','M','L'], ['White','Black','Grey']],
    ['Skinny Jeans', 'Women', 'DenimCo', 5200, 2600, ['XS','S','M','L'], ['Blue','Black']],
    ['Knit Cardigan', 'Women', 'CozyWear', 4500, 2100, ['S','M','L'], ['Grey','Navy','Black']],
    ['Maxi Skirt', 'Women', 'Calimax Women', 3800, 1800, ['XS','S','M','L'], ['Black','Navy','Green']],
    ['Kids Graphic Tee', 'Kids', 'LittleStars', 1800, 800, ['S','M','L'], ['Red','Blue','Green']],
    ['Kids Denim Shorts', 'Kids', 'LittleStars', 2400, 1100, ['S','M','L'], ['Blue','Black']],
    ['Kids Hoodie', 'Kids', 'LittleStars', 3200, 1500, ['S','M','L','XL'], ['Grey','Red','Blue']],
    ['Leather Belt', 'Accessories', 'Calimax Accessories', 2500, 1100, [], []],
    ['Canvas Tote Bag', 'Accessories', 'Calimax Accessories', 3200, 1400, [], ['Black','Brown','Beige']],
    ['Wool Scarf', 'Accessories', 'Calimax Accessories', 1900, 800, [], ['Black','Grey','Red']],
];

$insProduct = $pdo->prepare('INSERT INTO products (name, category_id, brand, tax_rate, is_active, created_by) VALUES (?,?,?,0,1,?)');
$insVariant = $pdo->prepare('INSERT INTO product_variants (product_id, sku, barcode, variant_label, price, cost_price, reorder_level, stock_qty) VALUES (?,?,?,?,?,?,?,0)');
$insPivot = $pdo->prepare('INSERT INTO variant_attribute_values (variant_id, attribute_value_id) VALUES (?,?)');

$variants = []; // id => {product_id, price, cost, stock, name, label}
foreach ($productTemplates as [$name, $catName, $brand, $price, $cost, $sizes, $colors]) {
    $insProduct->execute([$name, $categoryIds[$catName], $brand, $adminId]);
    $productId = (int) $pdo->lastInsertId();

    $combos = [[]];
    if ($sizes) {
        $new = [];
        foreach ($combos as $c) foreach ($sizes as $sz) $new[] = array_merge($c, ['size' => $sz]);
        $combos = $new;
    }
    if ($colors) {
        $new = [];
        foreach ($combos as $c) foreach ($colors as $col) $new[] = array_merge($c, ['color' => $col]);
        $combos = $new;
    }

    foreach ($combos as $combo) {
        $labelParts = [];
        if (!empty($combo['color'])) $labelParts[] = $combo['color'];
        if (!empty($combo['size'])) $labelParts[] = $combo['size'];
        $label = implode(' / ', $labelParts);
        $sku = generate_sku($name, $label);
        $barcode = generate_barcode();
        $insVariant->execute([$productId, $sku, $barcode, $label ?: null, $price, $cost, 15]);
        $variantId = (int) $pdo->lastInsertId();
        if (!empty($combo['size'])) $insPivot->execute([$variantId, $sizeValues[$combo['size']]]);
        if (!empty($combo['color'])) $insPivot->execute([$variantId, $colorValues[$combo['color']]]);
        $variants[$variantId] = ['product_id' => $productId, 'price' => $price, 'cost' => $cost, 'stock' => 0];
    }
}
echo 'Products: ' . count($productTemplates) . ', Variants: ' . count($variants) . "\n";

// -----------------------------------------------------------------------
// Customers
// -----------------------------------------------------------------------
$firstNames = ['Kasun','Nimali','Dilshan','Chamari','Ruwan','Sanduni','Tharindu','Ishara','Amila','Nadeesha','Chathura','Piumi','Lahiru','Hansini','Roshan','Kavindi','Sachini','Dinesh','Thilini','Chanaka'];
$lastNames = ['Perera','Fernando','Silva','Jayasuriya','Bandara','Wickramasinghe','Rathnayake','Gunawardena','Senanayake','Dissanayake'];
$custIds = [];
$insCustomer = $pdo->prepare('INSERT INTO customers (name, phone) VALUES (?,?)');
for ($i = 0; $i < 45; $i++) {
    $name = $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
    $phone = '07' . random_int(1, 9) . random_int(1000000, 9999999);
    $insCustomer->execute([$name, $phone]);
    $custIds[] = (int) $pdo->lastInsertId();
}
echo 'Customers: ' . count($custIds) . "\n";

// -----------------------------------------------------------------------
// Expense categories map
// -----------------------------------------------------------------------
$expCatIds = array_flip(lookupMap($pdo, 'expense_categories', 'id', 'name'));

// -----------------------------------------------------------------------
// Prepared statements reused across the simulation loop
// -----------------------------------------------------------------------
$insPO = $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, status, order_date, expected_date, total_amount, subtotal, created_by) VALUES (?,?,'received',?,?,?,?,?)");
$insPOItem = $pdo->prepare('INSERT INTO purchase_order_items (po_id, variant_id, quantity, unit_cost, received_qty) VALUES (?,?,?,?,?)');
$insGRN = $pdo->prepare('INSERT INTO goods_received_notes (grn_number, po_id, received_date, created_by, total_amount) VALUES (?,?,?,?,?)');
$insGRNItem = $pdo->prepare('INSERT INTO grn_items (grn_id, po_item_id, variant_id, quantity, unit_cost) VALUES (?,?,?,?,?)');
$insStockMove = $pdo->prepare('INSERT INTO stock_movements (variant_id, change_qty, type, reference_type, reference_id, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)');
$updVariantStock = $pdo->prepare('UPDATE product_variants SET stock_qty = stock_qty + ? WHERE id = ?');
$updSupplierBalance = $pdo->prepare('UPDATE suppliers SET balance = balance + ? WHERE id = ?');
$insSupplierPayment = $pdo->prepare('INSERT INTO supplier_payments (supplier_id, amount, payment_method, payment_date, created_by) VALUES (?,?,?,?,?)');

$insSale = $pdo->prepare("INSERT INTO sales (invoice_number, customer_id, cashier_id, subtotal, discount_amount, tax_amount, total_amount, status, sale_date, created_at) VALUES (?,?,?,?,0,0,?,'completed',?,?)");
$insSaleItem = $pdo->prepare('INSERT INTO sale_items (sale_id, variant_id, quantity, unit_price, unit_cost, tax_rate, tax_amount, line_total) VALUES (?,?,?,?,?,0,0,?)');
$insSalePayment = $pdo->prepare('INSERT INTO sale_payments (sale_id, method, amount, tendered_amount, change_amount) VALUES (?,?,?,?,?)');
$updCustomerCredit = $pdo->prepare('UPDATE customers SET credit_balance = credit_balance + ? WHERE id = ?');
$insLedger = $pdo->prepare('INSERT INTO customer_ledger (customer_id, sale_id, type, amount, balance_after, created_by, created_at) VALUES (?,?,?,?,?,?,?)');

$insExpense = $pdo->prepare('INSERT INTO expenses (category_id, description, amount, expense_date, payment_method, created_by) VALUES (?,?,?,?,?,?)');

$poCounter = 1; $grnCounter = 1; $invCounter = 1;
function poNum(&$c) { return sprintf('PO-%06d', $c++); }
function grnNum(&$c) { return sprintf('GRN-%06d', $c++); }
function invNum(&$c) { return sprintf('INV-%06d', $c++); }

function doRestock(PDO $pdo, $variantId, &$variants, $supplierIds, $adminId, $dateStr, &$poCounter, &$grnCounter, $insPO, $insPOItem, $insGRN, $insGRNItem, $insStockMove, $updVariantStock, $updSupplierBalance) {
    $v = $variants[$variantId];
    $qty = random_int(40, 90);
    $unitCost = round($v['cost'] * (0.95 + mt_rand(0, 10) / 100), 2);
    $total = $qty * $unitCost;
    $supplierId = $supplierIds[array_rand($supplierIds)];

    $insPO->execute([poNum($poCounter), $supplierId, $dateStr, $dateStr, $total, $total, $adminId]);
    $poId = (int) $pdo->lastInsertId();
    $insPOItem->execute([$poId, $variantId, $qty, $unitCost, $qty]);
    $poItemId = (int) $pdo->lastInsertId();

    $insGRN->execute([grnNum($grnCounter), $poId, $dateStr, $adminId, $total]);
    $grnId = (int) $pdo->lastInsertId();
    $insGRNItem->execute([$grnId, $poItemId, $variantId, $qty, $unitCost]);

    $updVariantStock->execute([$qty, $variantId]);
    $insStockMove->execute([$variantId, $qty, 'purchase', 'grn', $grnId, 'Demo restock', $adminId, $dateStr . ' 09:00:00']);
    $updSupplierBalance->execute([$total, $supplierId]);

    $variants[$variantId]['stock'] += $qty;
}

// Initial stock-up for every variant before the simulation begins.
$firstDateStr = $startDate->format('Y-m-d');
foreach (array_keys($variants) as $vid) {
    doRestock($pdo, $vid, $variants, $supplierIds, $adminId, $firstDateStr, $poCounter, $grnCounter, $insPO, $insPOItem, $insGRN, $insGRNItem, $insStockMove, $updVariantStock, $updSupplierBalance);
}
echo "Initial stock-up complete.\n";

// -----------------------------------------------------------------------
// Day-by-day simulation
// -----------------------------------------------------------------------
$variantIds = array_keys($variants);
$cursor = clone $startDate;
$dayCount = 0;
$totalSales = 0;
$totalExpenses = 0;
$paymentMethodPool = array_merge(array_fill(0, 70, 'cash'), array_fill(0, 15, 'card'), array_fill(0, 10, 'mobile_wallet'), array_fill(0, 5, 'store_credit'));

$pdo->beginTransaction();

while ($cursor <= $today) {
    $dayCount++;
    $dateStr = $cursor->format('Y-m-d');
    $dow = (int) $cursor->format('N'); // 1=Mon .. 7=Sun
    $yearIndex = (int) $cursor->format('Y') - (int) $startDate->format('Y');

    // --- Restocking: top up any variant that's running low ---
    foreach ($variantIds as $vid) {
        if ($variants[$vid]['stock'] < 18 && mt_rand(1, 100) <= 70) {
            doRestock($pdo, $vid, $variants, $supplierIds, $adminId, $dateStr, $poCounter, $grnCounter, $insPO, $insPOItem, $insGRN, $insGRNItem, $insStockMove, $updVariantStock, $updSupplierBalance);
        }
    }

    // --- Sales for the day ---
    $base = 3 + $yearIndex; // gentle year-over-year growth
    $weekendBoost = in_array($dow, [6, 7], true) ? random_int(2, 5) : 0;
    $salesToday = max(0, $base + $weekendBoost + random_int(-2, 3));

    for ($i = 0; $i < $salesToday; $i++) {
        $itemCount = random_int(1, 3);
        $chosenVariants = (array) array_rand(array_flip($variantIds), min($itemCount, count($variantIds)));
        if (!is_array($chosenVariants)) $chosenVariants = [$chosenVariants];

        $lines = [];
        $subtotal = 0.0;
        foreach ($chosenVariants as $vid) {
            if ($variants[$vid]['stock'] <= 0) continue;
            $qty = min($variants[$vid]['stock'], random_int(1, 2));
            $price = $variants[$vid]['price'];
            $lineTotal = $qty * $price;
            $lines[] = ['variant_id' => $vid, 'qty' => $qty, 'price' => $price, 'line_total' => $lineTotal];
            $subtotal += $lineTotal;
        }
        if (!$lines) continue;

        $useCustomer = mt_rand(1, 100) <= 55;
        $customerId = $useCustomer ? $custIds[array_rand($custIds)] : null;
        $method = $paymentMethodPool[array_rand($paymentMethodPool)];
        if ($method === 'store_credit' && !$customerId) { $method = 'cash'; }

        $hour = random_int(9, 20);
        $minute = random_int(0, 59);
        $saleDateTime = $dateStr . ' ' . sprintf('%02d:%02d:00', $hour, $minute);

        $invoiceNumber = invNum($invCounter);
        $insSale->execute([$invoiceNumber, $customerId, $adminId, $subtotal, $subtotal, $saleDateTime, $saleDateTime]);
        $saleId = (int) $pdo->lastInsertId();

        foreach ($lines as $l) {
            $insSaleItem->execute([$saleId, $l['variant_id'], $l['qty'], $l['price'], $variants[$l['variant_id']]['cost'], $l['line_total']]);
            $updVariantStock->execute([-1 * $l['qty'], $l['variant_id']]);
            $insStockMove->execute([$l['variant_id'], -1 * $l['qty'], 'sale', 'sale', $saleId, 'Demo sale ' . $invoiceNumber, $adminId, $saleDateTime]);
            $variants[$l['variant_id']]['stock'] -= $l['qty'];
        }

        if ($method === 'store_credit') {
            $insSalePayment->execute([$saleId, 'store_credit', $subtotal, null, null]);
            $updCustomerCredit->execute([$subtotal, $customerId]);
            $balStmt = $pdo->prepare('SELECT credit_balance FROM customers WHERE id = ?');
            $balStmt->execute([$customerId]);
            $insLedger->execute([$customerId, $saleId, 'credit_sale', $subtotal, (float) $balStmt->fetchColumn(), $adminId, $saleDateTime]);
        } elseif ($method === 'cash') {
            $tendered = ceil($subtotal / 100) * 100; // round up to nearest 100
            $insSalePayment->execute([$saleId, 'cash', $subtotal, $tendered, $tendered - $subtotal]);
        } else {
            $insSalePayment->execute([$saleId, $method, $subtotal, null, null]);
        }
        $totalSales++;
    }

    // --- Occasional customer credit payments ---
    if (mt_rand(1, 100) <= 8) {
        $stmt = $pdo->prepare('SELECT id, credit_balance FROM customers WHERE credit_balance > 0 ORDER BY RAND() LIMIT 1');
        $stmt->execute();
        if ($c = $stmt->fetch()) {
            $pay = round($c['credit_balance'] * (0.3 + mt_rand(0, 70) / 100), 2);
            $updCustomerCredit->execute([-1 * $pay, $c['id']]);
            $newBal = (float) $c['credit_balance'] - $pay;
            $insLedger->execute([$c['id'], null, 'payment_received', -1 * $pay, $newBal, $adminId, $dateStr . ' 17:00:00']);
        }
    }

    // --- Occasional supplier payments ---
    if (mt_rand(1, 100) <= 10) {
        $stmt = $pdo->prepare('SELECT id, balance FROM suppliers WHERE balance > 0 ORDER BY RAND() LIMIT 1');
        $stmt->execute();
        if ($s = $stmt->fetch()) {
            $pay = round($s['balance'] * (0.2 + mt_rand(0, 60) / 100), 2);
            $updSupplierBalance->execute([-1 * $pay, $s['id']]);
            $insSupplierPayment->execute([$s['id'], $pay, 'bank_transfer', $dateStr, $adminId]);
        }
    }

    // --- Monthly recurring expenses on the 1st ---
    if ((int) $cursor->format('j') === 1) {
        $rentBase = 80000 + $yearIndex * 5000;
        $insExpense->execute([$expCatIds['Rent'], 'Monthly shop rent', $rentBase, $dateStr, 'bank_transfer', $adminId]);
        $insExpense->execute([$expCatIds['Utilities'], 'Electricity & water', random_int(12000, 22000), $dateStr, 'cash', $adminId]);
        $insExpense->execute([$expCatIds['Salaries'], 'Staff salaries', 150000 + $yearIndex * 8000, $dateStr, 'bank_transfer', $adminId]);
        $totalExpenses += 3;
    }
    // --- Occasional ad-hoc expenses ---
    if (mt_rand(1, 100) <= 12) {
        $adhoc = [
            ['Marketing', 'Social media promotion', [3000, 15000]],
            ['Maintenance', 'Store fixture repair', [2000, 12000]],
            ['Miscellaneous', 'Packaging supplies', [1500, 8000]],
            ['Marketing', 'Festive season banners', [5000, 20000]],
        ];
        [$catName, $desc, $range] = $adhoc[array_rand($adhoc)];
        $insExpense->execute([$expCatIds[$catName], $desc, random_int($range[0], $range[1]), $dateStr, 'cash', $adminId]);
        $totalExpenses++;
    }

    // Commit monthly for performance while keeping batches small enough to be safe.
    if ((int) $cursor->format('j') === 1) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  ...through {$dateStr} ({$totalSales} sales so far)\n";
    }

    $cursor->modify('+1 day');
}
$pdo->commit();

$elapsed = round(microtime(true) - $startTime, 1);
echo "\nDone in {$elapsed}s.\n";
echo "Days simulated: {$dayCount}\n";
echo "Total sales: {$totalSales}\n";
