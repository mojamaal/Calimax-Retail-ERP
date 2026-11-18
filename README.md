# Calimax ERP — Retail ERP & POS (multi-branch)

A full retail ERP for shops (groceries, electronics, clothing, cosmetics, hardware), built from scratch with **plain PHP 8.2, MariaDB/MySQL, vanilla JavaScript** — no framework.

> **© 2026 Mohammed Jamaal Rushdi. All Rights Reserved.**
> This repository is public for **portfolio review only**. It is *not* open source. You may not use, run, copy, modify, deploy, distribute or commercialise it. See [LICENSE](LICENSE).

## What it does
- **Products & variants** — own SKU/barcodes, per-branch prices, margins, audit of every price change
- **Suppliers & purchasing** — purchase orders, goods receipt (partial / rejected / direct), supplier ledger and payments
- **Inventory** — saleable vs damaged stock, adjustments with approval, blind stock counts, full movement history
- **POS** — barcode scanning, line/bill discounts, split payments, held carts, credit sales, manager overrides, receipts
- **Customers** — profiles, purchase history, credit limits, statements
- **Returns & exchanges** — approval workflow, damaged items kept out of saleable stock
- **Finance** — expenses, cash & bank book, receivables/payables, true P&L (revenue ≠ profit, COGS snapshot per sale)
- **Multi-branch** — per-branch stock, prices, cash, expenses and supplier bills; inter-branch transfers; branch comparison
- **Security** — role-based permissions (owner, manager, cashier, sales assistant, inventory), CSRF, audit log

## Tech
PHP 8.2 · PDO · MariaDB 10.4 · vanilla JS · Lucide icons · JsBarcode · Apache (XAMPP)

## Running it locally (for evaluation by the owner's permission only)
1. Place the project in `htdocs/calipos`, create a database `calipos`, import `database/schema.sql`
2. Run `php database/migrate.php`
3. Adjust `config/config.php`, then open `http://localhost/calipos`
