# Calimax ERP — Retail ERP & POS (multi-branch)

A full retail ERP for shops (groceries, electronics, clothing, cosmetics, hardware), built from scratch with **plain PHP 8.2, MariaDB/MySQL, vanilla JavaScript** — no framework.

> **© 2026 Mohammed Jamaal Rushdi. All Rights Reserved.**
> This repository is public for **portfolio review only**. It is *not* open source. You may not use, run, copy, modify, deploy, distribute or commercialise it. See [LICENSE](LICENSE).

## About this project

Small shops usually run on spreadsheets, paper receipts and guesswork, and they can't tell whether they are actually making money. **Calimax ERP** is a complete retail back-office I designed and built end to end for Sri Lankan shops (groceries, electronics, clothing, cosmetics, hardware): one system that covers buying, stock, selling, returns, money and reporting, across one shop or many branches.

It was built to the way a real shop works, not to a tutorial:

- **A purchase order never changes stock** — only a goods receipt does, with partial deliveries, rejected items and supplier invoices.
- **Defective returns never go back on the shelf** — they move to a separate damaged-stock bucket.
- **Revenue is not profit** — every sale stores the cost of each item at that moment, so gross and net profit stay correct when costs change later.
- **Every sensitive action is traceable** — who changed a price, adjusted stock, refunded, cancelled a sale or approved something, and when.
- **Cashiers can't go beyond their limits** — big discounts, voids and refunds need a manager's password typed at the till.

## Engineering highlights

| Area | What it shows |
|---|---|
| **Architecture** | Plain PHP 8.2 with no framework; a permission-based role system, an idempotent migration script, and a shared finance engine used by dashboards, reports and printouts |
| **Data integrity** | Transactional stock engine with a running balance on every movement, row-level locking against overselling, race-safe document numbering, weighted-average costing |
| **Multi-branch design** | Per-branch stock, prices, cash books and supplier bills on one shared catalogue; inter-branch transfers with in-transit tracking and shortfall handling; consolidated vs per-branch reporting through a single scope mechanism |
| **Security** | CSRF protection, escaped output, server-side re-pricing of every POS sale (the client is never trusted), login lockout, least-privilege roles, branch isolation, full audit trail |
| **UX** | Barcode-first POS with hotkeys, split payments and held carts; responsive layout down to phone width; a consistent design system |
| **Quality** | Developed with a large set of scripted end-to-end checks (170+) covering permissions, stock, money flows and branch isolation |

## About the developer

**Mohammed Jamaal Rushdi** — full-stack software engineer. I like turning messy real-world business processes into software that is simple to use and correct underneath: this project covers database design, backend logic, security, reporting and UI/UX, delivered as one working product.

Open to full-stack / backend roles and interesting projects. The best way to reach me is through my GitHub profile: [github.com/mojamaal](https://github.com/mojamaal).

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
