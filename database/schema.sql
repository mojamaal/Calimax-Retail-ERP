-- =====================================================================
-- CALIPOS / Calimax ERP - Database Schema
-- Retail ERP: Products, Suppliers, Purchasing, Inventory, POS, Customers,
-- Returns, Finance, Reports and Audit.
-- Engine: InnoDB, Charset: utf8mb4
--
-- FRESH INSTALL:  1) import this file   2) php database/migrate.php
--                 3) (optional demo data) php database/seed_demo.php
-- The migration upgrades this base schema to the full feature set
-- (roles, returns workflow, stock adjustments/counts, cash book, ...)
-- and is safe to re-run at any time.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `calipos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `calipos`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- USERS & ACCESS CONTROL
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `role` ENUM('admin','cashier') NOT NULL DEFAULT 'cashier',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Brute-force protection
CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`username`, `attempted_at`),
  INDEX (`ip_address`, `attempted_at`)
) ENGINE=InnoDB;

-- Audit trail
CREATE TABLE `activity_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`user_id`), INDEX(`action`), INDEX(`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SETTINGS (single-row key/value store)
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- INVENTORY: categories, attributes, products, variants, stock
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `attributes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE -- e.g. Size, Color
) ENGINE=InnoDB;

CREATE TABLE `attribute_values` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `attribute_id` INT UNSIGNED NOT NULL,
  `value` VARCHAR(50) NOT NULL, -- e.g. M, L, Red, Blue
  UNIQUE KEY `uniq_attr_value` (`attribute_id`, `value`),
  FOREIGN KEY (`attribute_id`) REFERENCES `attributes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `brand` VARCHAR(100) DEFAULT NULL,
  `image` VARCHAR(255) DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00, -- percent
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`category_id`), INDEX(`name`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `product_variants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `sku` VARCHAR(60) NOT NULL UNIQUE,
  `barcode` VARCHAR(60) NOT NULL UNIQUE,
  `variant_label` VARCHAR(150) DEFAULT NULL, -- cached "Red / M" for fast display
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `cost_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reorder_level` INT NOT NULL DEFAULT 0,
  `stock_qty` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`product_id`), INDEX (`barcode`), INDEX(`sku`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `variant_attribute_values` (
  `variant_id` INT UNSIGNED NOT NULL,
  `attribute_value_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`variant_id`, `attribute_value_id`),
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`attribute_value_id`) REFERENCES `attribute_values`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `variant_id` INT UNSIGNED NOT NULL,
  `change_qty` INT NOT NULL, -- signed: +in / -out
  `type` ENUM('purchase','sale','sale_void','adjustment','purchase_return','sale_return','initial') NOT NULL,
  `reference_type` VARCHAR(40) DEFAULT NULL, -- e.g. 'sale', 'grn', 'purchase_return', 'adjustment'
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`variant_id`), INDEX(`type`), INDEX(`reference_type`,`reference_id`),
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- PURCHASE: suppliers, purchase orders, GRN, purchase returns
-- ---------------------------------------------------------------------
CREATE TABLE `suppliers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- amount we owe supplier
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `purchase_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `po_number` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `status` ENUM('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `order_date` DATE NOT NULL,
  `expected_date` DATE DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX(`supplier_id`), INDEX(`status`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `purchase_order_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `po_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL,
  `received_qty` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `goods_received_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grn_number` VARCHAR(30) NOT NULL UNIQUE,
  `po_id` INT UNSIGNED NOT NULL,
  `received_date` DATE NOT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `grn_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grn_id` INT UNSIGNED NOT NULL,
  `po_item_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`grn_id`) REFERENCES `goods_received_notes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`po_item_id`) REFERENCES `purchase_order_items`(`id`),
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase_returns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_number` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `po_id` INT UNSIGNED DEFAULT NULL,
  `return_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `purchase_return_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `unit_cost` DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`return_id`) REFERENCES `purchase_returns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `supplier_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `supplier_id` INT UNSIGNED NOT NULL,
  `po_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cash',
  `payment_date` DATE NOT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- POS: customers, sales, sale items, payments, cash shifts
-- ---------------------------------------------------------------------
CREATE TABLE `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `credit_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- amount customer owes us
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(`phone`)
) ENGINE=InnoDB;

CREATE TABLE `cash_shifts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cashier_id` INT UNSIGNED NOT NULL,
  `opening_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `closing_balance` DECIMAL(12,2) DEFAULT NULL,
  `expected_cash` DECIMAL(12,2) DEFAULT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `opened_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sales` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `cashier_id` INT UNSIGNED NOT NULL,
  `shift_id` INT UNSIGNED DEFAULT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('held','completed','voided','refunded','partially_refunded') NOT NULL DEFAULT 'completed',
  `sale_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(`customer_id`), INDEX(`cashier_id`), INDEX(`status`), INDEX(`sale_date`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`shift_id`) REFERENCES `cash_shifts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `sale_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL,
  `returned_qty` INT NOT NULL DEFAULT 0,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sale_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT UNSIGNED NOT NULL,
  `method` ENUM('cash','card','mobile_wallet','store_credit') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `tendered_amount` DECIMAL(12,2) DEFAULT NULL,
  `change_amount` DECIMAL(12,2) DEFAULT NULL,
  `reference_no` VARCHAR(60) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `sale_returns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sale_id` INT UNSIGNED NOT NULL,
  `return_number` VARCHAR(30) NOT NULL UNIQUE,
  `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `refund_method` VARCHAR(30) DEFAULT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `sale_return_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT UNSIGNED NOT NULL,
  `sale_item_id` INT UNSIGNED NOT NULL,
  `variant_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL,
  `unit_price` DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (`return_id`) REFERENCES `sale_returns`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items`(`id`),
  FOREIGN KEY (`variant_id`) REFERENCES `product_variants`(`id`)
) ENGINE=InnoDB;

-- Customer store-credit / receivables ledger
CREATE TABLE `customer_ledger` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT UNSIGNED NOT NULL,
  `sale_id` INT UNSIGNED DEFAULT NULL,
  `type` ENUM('credit_sale','payment_received','adjustment','refund') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL, -- positive = increases balance owed, negative = decreases
  `balance_after` DECIMAL(12,2) NOT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- FINANCE: expenses
-- ---------------------------------------------------------------------
CREATE TABLE `expense_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE `expenses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cash',
  `reference_no` VARCHAR(60) DEFAULT NULL,
  `attachment` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(`category_id`), INDEX(`expense_date`),
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- SEED DATA
-- ---------------------------------------------------------------------

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('store_name', 'Calimax Clothing'),
('store_address', 'No. 123, Galle Road, Colombo'),
('store_phone', '+94 11 234 5678'),
('store_email', 'info@calimax.lk'),
('currency_code', 'LKR'),
('currency_symbol', 'Rs.'),
('default_tax_rate', '0.00'),
('receipt_footer', 'Thank you for shopping with us!'),
('invoice_prefix', 'INV'),
('po_prefix', 'PO'),
('grn_prefix', 'GRN');

-- Default admin user: username "admin", password "admin123" (CHANGE THIS IN PRODUCTION)
-- Hash generated with PHP password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `email`, `role`, `is_active`) VALUES
('admin', '$2y$10$A1Djd5beAmr4hIRvvjK.eeSK85AQSpOGhzxHCy0H5jHhEmDHgeevS', 'System Administrator', 'tech@shopq.lk', 'admin', 1);

INSERT INTO `attributes` (`name`) VALUES ('Size'), ('Color');

INSERT INTO `attribute_values` (`attribute_id`, `value`) VALUES
(1,'XS'),(1,'S'),(1,'M'),(1,'L'),(1,'XL'),(1,'XXL'),
(2,'Black'),(2,'White'),(2,'Red'),(2,'Blue'),(2,'Green'),(2,'Grey'),(2,'Navy');

INSERT INTO `categories` (`name`, `description`) VALUES
('Men', 'Men\'s clothing'), ('Women', 'Women\'s clothing'), ('Kids', 'Kids clothing'), ('Accessories', 'Bags, belts, etc.');

INSERT INTO `expense_categories` (`name`) VALUES
('Rent'), ('Utilities'), ('Salaries'), ('Marketing'), ('Maintenance'), ('Miscellaneous');
