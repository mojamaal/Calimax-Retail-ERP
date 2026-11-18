<?php
/**
 * Idempotent schema upgrader for the Retail ERP feature set.
 * Safe to run repeatedly: every step checks the current schema first.
 *
 *   php database/migrate.php
 *
 * Works on MariaDB and MySQL 5.7+/8 (no "ADD COLUMN IF NOT EXISTS" reliance).
 * Not web-accessible (database/.htaccess denies all).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); die('CLI only.'); }

require_once __DIR__ . '/../config/db.php';
$pdo = db();
$dbName = DB_NAME;

function col_exists(PDO $pdo, string $table, string $col): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $col]);
    return (int) $s->fetchColumn() > 0;
}
function table_exists(PDO $pdo, string $table): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $s->execute([$table]);
    return (int) $s->fetchColumn() > 0;
}
function col_type(PDO $pdo, string $table, string $col): string {
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $s->execute([$table, $col]);
    return strtolower((string) $s->fetchColumn());
}
function index_exists(PDO $pdo, string $table, string $index): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $s->execute([$table, $index]);
    return (int) $s->fetchColumn() > 0;
}
function add_col(PDO $pdo, string $table, string $col, string $definition): void {
    if (!col_exists($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
        echo "  + $table.$col\n";
    }
}
function to_varchar(PDO $pdo, string $table, string $col, string $definition): void {
    if (str_starts_with(col_type($pdo, $table, $col), 'enum')) {
        $pdo->exec("ALTER TABLE `$table` MODIFY `$col` $definition");
        echo "  ~ $table.$col -> varchar\n";
    }
}
function add_index(PDO $pdo, string $table, string $index, string $cols): void {
    if (!index_exists($pdo, $table, $index)) {
        $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($cols)");
        echo "  + index $table.$index\n";
    }
}
function create_table(PDO $pdo, string $table, string $sql): void {
    if (!table_exists($pdo, $table)) {
        $pdo->exec($sql);
        echo "  + table $table\n";
    }
}

echo "Migrating database `$dbName`...\n";

// ---------------------------------------------------------------------
// 1. Roles: admin/cashier enum -> flexible role names
//    owner | manager | cashier | sales_assistant | inventory
// ---------------------------------------------------------------------
to_varchar($pdo, 'users', 'role', "VARCHAR(30) NOT NULL DEFAULT 'cashier'");
$n = $pdo->exec("UPDATE users SET role = 'owner' WHERE role = 'admin'");
if ($n) echo "  ~ $n admin user(s) -> owner\n";
add_col($pdo, 'users', 'phone', 'VARCHAR(30) DEFAULT NULL AFTER `email`');

// ---------------------------------------------------------------------
// 2. Audit log: entity linkage for filtering "who changed what"
// ---------------------------------------------------------------------
add_col($pdo, 'activity_log', 'entity_type', 'VARCHAR(40) DEFAULT NULL AFTER `action`');
add_col($pdo, 'activity_log', 'entity_id', 'INT UNSIGNED DEFAULT NULL AFTER `entity_type`');
add_index($pdo, 'activity_log', 'idx_entity', '`entity_type`, `entity_id`');

// ---------------------------------------------------------------------
// 3. Products / stock
// ---------------------------------------------------------------------
add_col($pdo, 'products', 'unit', "VARCHAR(20) NOT NULL DEFAULT 'pcs' AFTER `brand`");
add_col($pdo, 'product_variants', 'damaged_qty', 'INT NOT NULL DEFAULT 0 AFTER `stock_qty`');
to_varchar($pdo, 'stock_movements', 'type', "VARCHAR(30) NOT NULL");
add_col($pdo, 'stock_movements', 'bucket', "VARCHAR(12) NOT NULL DEFAULT 'saleable' AFTER `type`");
add_col($pdo, 'stock_movements', 'balance_after', 'INT DEFAULT NULL AFTER `bucket`');
add_index($pdo, 'stock_movements', 'idx_created', '`created_at`');

// ---------------------------------------------------------------------
// 4. Sales: cost snapshot for true COGS, void audit, flexible methods
// ---------------------------------------------------------------------
add_col($pdo, 'sale_items', 'unit_cost', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `unit_price`');
$backfilled = $pdo->exec('UPDATE sale_items si JOIN product_variants pv ON pv.id = si.variant_id SET si.unit_cost = pv.cost_price WHERE si.unit_cost = 0');
if ($backfilled) echo "  ~ backfilled unit_cost on $backfilled sale line(s)\n";
to_varchar($pdo, 'sale_payments', 'method', 'VARCHAR(30) NOT NULL');
to_varchar($pdo, 'sales', 'status', "VARCHAR(30) NOT NULL DEFAULT 'completed'");
add_col($pdo, 'sales', 'notes', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'sales', 'void_reason', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'sales', 'voided_by', 'INT UNSIGNED DEFAULT NULL');
add_col($pdo, 'sales', 'void_approved_by', 'INT UNSIGNED DEFAULT NULL');
add_col($pdo, 'sales', 'voided_at', 'DATETIME DEFAULT NULL');
add_col($pdo, 'sales', 'discount_approved_by', 'INT UNSIGNED DEFAULT NULL');

// ---------------------------------------------------------------------
// 5. Customer returns & exchanges
// ---------------------------------------------------------------------
add_col($pdo, 'sale_returns', 'customer_id', 'INT UNSIGNED DEFAULT NULL AFTER `sale_id`');
add_col($pdo, 'sale_returns', 'status', "VARCHAR(20) NOT NULL DEFAULT 'processed' AFTER `return_number`");
add_col($pdo, 'sale_returns', 'return_type', "VARCHAR(20) NOT NULL DEFAULT 'refund' AFTER `status`");
add_col($pdo, 'sale_returns', 'total_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `return_type`');
add_col($pdo, 'sale_returns', 'tax_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`');
add_col($pdo, 'sale_returns', 'cost_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`');
add_col($pdo, 'sale_returns', 'refund_reference', 'VARCHAR(60) DEFAULT NULL AFTER `refund_method`');
add_col($pdo, 'sale_returns', 'approved_by', 'INT UNSIGNED DEFAULT NULL');
add_col($pdo, 'sale_returns', 'approved_at', 'DATETIME DEFAULT NULL');
add_col($pdo, 'sale_returns', 'rejected_reason', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'sale_returns', 'exchange_sale_id', 'INT UNSIGNED DEFAULT NULL');
add_col($pdo, 'sale_returns', 'exchange_used', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
add_col($pdo, 'sale_returns', 'exchange_cash_back', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
add_index($pdo, 'sale_returns', 'idx_status', '`status`');
add_col($pdo, 'sale_return_items', 'item_condition', "VARCHAR(20) NOT NULL DEFAULT 'resellable'");
add_col($pdo, 'sale_return_items', 'unit_cost', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
add_col($pdo, 'sale_return_items', 'tax_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
add_col($pdo, 'sale_return_items', 'line_total', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');

// ---------------------------------------------------------------------
// 6. Customers: credit control
// ---------------------------------------------------------------------
add_col($pdo, 'customers', 'allow_credit', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `credit_balance`');
add_col($pdo, 'customers', 'credit_limit', 'DECIMAL(12,2) DEFAULT NULL AFTER `allow_credit`');
add_col($pdo, 'customers', 'notes', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'customers', 'created_by', 'INT UNSIGNED DEFAULT NULL');
to_varchar($pdo, 'customer_ledger', 'type', 'VARCHAR(30) NOT NULL');
add_col($pdo, 'customer_ledger', 'method', 'VARCHAR(30) DEFAULT NULL AFTER `type`');
add_col($pdo, 'customer_ledger', 'reference_no', 'VARCHAR(60) DEFAULT NULL AFTER `method`');

// ---------------------------------------------------------------------
// 7. Suppliers & purchasing
// ---------------------------------------------------------------------
add_col($pdo, 'suppliers', 'business_reg_no', 'VARCHAR(60) DEFAULT NULL AFTER `name`');
add_col($pdo, 'suppliers', 'tax_id', 'VARCHAR(60) DEFAULT NULL AFTER `business_reg_no`');
add_col($pdo, 'suppliers', 'payment_terms_days', 'INT NOT NULL DEFAULT 0');
add_col($pdo, 'suppliers', 'notes', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'supplier_payments', 'reference_no', 'VARCHAR(60) DEFAULT NULL AFTER `payment_method`');
add_col($pdo, 'supplier_payments', 'grn_id', 'INT UNSIGNED DEFAULT NULL AFTER `po_id`');
to_varchar($pdo, 'purchase_orders', 'status', "VARCHAR(30) NOT NULL DEFAULT 'draft'");
add_col($pdo, 'purchase_orders', 'is_direct', 'TINYINT(1) NOT NULL DEFAULT 0');
add_col($pdo, 'purchase_orders', 'closed_reason', 'VARCHAR(255) DEFAULT NULL');
add_col($pdo, 'goods_received_notes', 'supplier_invoice_no', 'VARCHAR(60) DEFAULT NULL AFTER `po_id`');
add_col($pdo, 'goods_received_notes', 'total_amount', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00');
add_col($pdo, 'goods_received_notes', 'payment_mode', "VARCHAR(20) NOT NULL DEFAULT 'credit'");
add_col($pdo, 'grn_items', 'rejected_qty', 'INT NOT NULL DEFAULT 0');
add_col($pdo, 'grn_items', 'note', 'VARCHAR(255) DEFAULT NULL');
$pdo->exec('UPDATE goods_received_notes g SET total_amount = (SELECT COALESCE(SUM(quantity*unit_cost),0) FROM grn_items WHERE grn_id = g.id) WHERE total_amount = 0');
add_col($pdo, 'purchase_return_items', 'bucket', "VARCHAR(12) NOT NULL DEFAULT 'saleable'");

// ---------------------------------------------------------------------
// 8. New tables
// ---------------------------------------------------------------------
create_table($pdo, 'document_sequences', "CREATE TABLE `document_sequences` (
  `seq_key` VARCHAR(60) PRIMARY KEY,
  `next_value` INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB");

create_table($pdo, 'stock_adjustments', "CREATE TABLE `stock_adjustments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `adj_number` VARCHAR(30) NOT NULL UNIQUE,
  `reason` VARCHAR(30) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `source` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `count_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `value_change` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `requested_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_reason` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`status`), INDEX (`approved_at`),
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB");

create_table($pdo, 'stock_adjustment_items', "CREATE TABLE `stock_adjustment_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `adjustment_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `qty_change` INT NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `note` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB");

create_table($pdo, 'stock_counts', "CREATE TABLE `stock_counts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `count_number` VARCHAR(30) NOT NULL UNIQUE,
  `title` VARCHAR(150) NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'in_progress',
  `adjustment_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `submitted_by` INT UNSIGNED DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`status`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB");

create_table($pdo, 'stock_count_items', "CREATE TABLE `stock_count_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `count_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `system_qty` INT NOT NULL,
  `counted_qty` INT DEFAULT NULL,
  UNIQUE KEY `uniq_count_variant` (`count_id`, `variant_id`),
  FOREIGN KEY (`count_id`) REFERENCES `stock_counts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB");

create_table($pdo, 'cash_transactions', "CREATE TABLE `cash_transactions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `txn_date` DATE NOT NULL,
  `type` VARCHAR(30) NOT NULL,
  `from_account` VARCHAR(10) DEFAULT NULL,
  `to_account` VARCHAR(10) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `reference_no` VARCHAR(60) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`txn_date`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB");

// ---------------------------------------------------------------------
// 8. Multi-branch
//    - every branch has its own stock, selling prices, cash book, supplier
//      payables, documents and staff
//    - product_variants.stock_qty / damaged_qty stay as company-wide totals,
//      branch_stock holds the per-branch figures
//    - existing data is assigned to branch #1 (the main branch)
// ---------------------------------------------------------------------
create_table($pdo, 'branches', "CREATE TABLE `branches` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `name` VARCHAR(120) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `email` VARCHAR(120) DEFAULT NULL,
  `receipt_footer` VARCHAR(255) DEFAULT NULL,
  `is_main` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
if (!(int) $pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn()) {
    $addr = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'store_address'")->fetchColumn() ?: null;
    $phone = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'store_phone'")->fetchColumn() ?: null;
    $pdo->prepare("INSERT INTO branches (id, code, name, address, phone, is_main) VALUES (1, 'MAIN', 'Main Branch', ?, ?, 1)")->execute([$addr, $phone]);
    echo "  + main branch created\n";
}

add_col($pdo, 'users', 'branch_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
add_col($pdo, 'users', 'all_branches', 'TINYINT(1) NOT NULL DEFAULT 0');
$pdo->exec("UPDATE users SET all_branches = 1 WHERE role IN ('owner','admin') AND all_branches = 0");

$branchTables = [
    'sales' => ['sale_date'], 'sale_returns' => ['created_at'], 'purchase_orders' => ['created_at'],
    'goods_received_notes' => ['received_date'], 'purchase_returns' => ['return_date'], 'supplier_payments' => ['payment_date'],
    'stock_movements' => ['created_at'], 'stock_adjustments' => ['created_at'], 'stock_counts' => ['status'],
    'expenses' => ['expense_date'], 'cash_transactions' => ['txn_date'], 'customer_ledger' => ['created_at'],
    'activity_log' => ['created_at'],
];
if (table_exists($pdo, 'cash_shifts')) { $branchTables['cash_shifts'] = []; }
foreach ($branchTables as $t => $idxCols) {
    add_col($pdo, $t, 'branch_id', 'INT UNSIGNED NOT NULL DEFAULT 1');
    if ($idxCols && col_exists($pdo, $t, $idxCols[0])) {
        add_index($pdo, $t, 'idx_branch_' . $idxCols[0], '`branch_id`, `' . $idxCols[0] . '`');
    } else {
        add_index($pdo, $t, 'idx_branch', '`branch_id`');
    }
}

create_table($pdo, 'branch_stock', "CREATE TABLE `branch_stock` (
  `variant_id` INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED NOT NULL,
  `stock_qty` INT NOT NULL DEFAULT 0,
  `damaged_qty` INT NOT NULL DEFAULT 0,
  `price` DECIMAL(12,2) DEFAULT NULL,
  `cost_price` DECIMAL(12,2) DEFAULT NULL,
  `reorder_level` INT DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`variant_id`, `branch_id`),
  INDEX (`branch_id`),
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`)
) ENGINE=InnoDB");
if (!(int) $pdo->query('SELECT COUNT(*) FROM branch_stock')->fetchColumn()) {
    $n = $pdo->exec('INSERT INTO branch_stock (variant_id, branch_id, stock_qty, damaged_qty, price, cost_price, reorder_level)
                     SELECT id, 1, stock_qty, damaged_qty, price, cost_price, reorder_level FROM product_variants');
    echo "  + $n product(s) assigned to the main branch's stock\n";
}

create_table($pdo, 'supplier_balances', "CREATE TABLE `supplier_balances` (
  `supplier_id` INT UNSIGNED NOT NULL,
  `branch_id` INT UNSIGNED NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`supplier_id`, `branch_id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`)
) ENGINE=InnoDB");
if (!(int) $pdo->query('SELECT COUNT(*) FROM supplier_balances')->fetchColumn()) {
    $pdo->exec('INSERT INTO supplier_balances (supplier_id, branch_id, balance) SELECT id, 1, balance FROM suppliers WHERE balance <> 0');
}

create_table($pdo, 'stock_transfers', "CREATE TABLE `stock_transfers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transfer_number` VARCHAR(30) NOT NULL UNIQUE,
  `from_branch_id` INT UNSIGNED NOT NULL,
  `to_branch_id` INT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'in_transit',
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  `cancelled_by` INT UNSIGNED DEFAULT NULL,
  `cancelled_at` DATETIME DEFAULT NULL,
  INDEX (`status`), INDEX (`from_branch_id`), INDEX (`to_branch_id`),
  FOREIGN KEY (`from_branch_id`) REFERENCES `branches`(`id`),
  FOREIGN KEY (`to_branch_id`) REFERENCES `branches`(`id`)
) ENGINE=InnoDB");

create_table($pdo, 'stock_transfer_items', "CREATE TABLE `stock_transfer_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transfer_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `received_qty` INT DEFAULT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB");

// Per-branch document numbering: counters are keyed "<table>" (main branch) or "<table>:<branch id>".

// ---------------------------------------------------------------------
// 9. Reference data & settings
// ---------------------------------------------------------------------
$ins = $pdo->prepare('INSERT IGNORE INTO expense_categories (name) VALUES (?)');
foreach (['Rent', 'Electricity', 'Water', 'Internet & Telephone', 'Salaries', 'Transportation', 'Maintenance', 'Marketing', 'Bank Charges', 'Miscellaneous'] as $cat) {
    $ins->execute([$cat]);
}

$defaults = [
    'tax_registration_no'        => '',
    'prices_include_tax'         => '1',
    'cashier_max_discount_pct'   => '10',
    'returns_require_approval'   => '1',
    'return_window_days'         => '30',
    'return_policy'              => 'Returns accepted within 30 days with receipt.',
    'adjustments_require_approval' => '1',
    'void_requires_reason'       => '1',
    'transfer_prefix'            => 'TRF',
];
$insSetting = $pdo->prepare('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)');
foreach ($defaults as $k => $v) { $insSetting->execute([$k, $v]); }

echo "Done.\n";
