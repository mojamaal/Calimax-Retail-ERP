<?php
/** Module tab bars. Each entry: [href, label, permission, icon, [alias scripts]] */

function nav_products(): void
{
    render_subnav([
        ['/inventory/products.php', 'Products', 'products.view', 'package', ['/inventory/product_view.php', '/inventory/product_form.php']],
        ['/inventory/categories.php', 'Categories', 'products.manage', 'folder-tree'],
        ['/inventory/attributes.php', 'Variant attributes', 'products.manage', 'tags'],
    ]);
}

function nav_stock(): void
{
    render_subnav([
        ['/inventory/adjustments.php', 'Adjustments', 'inventory.adjust', 'clipboard-check', ['/inventory/adjustment_form.php', '/inventory/adjustment_view.php']],
        ['/inventory/counts.php', 'Stock counts', 'inventory.count', 'list-checks', ['/inventory/count_view.php']],
        ['/inventory/transfers.php', 'Branch transfers', 'transfers.view', 'truck', ['/inventory/transfer_form.php', '/inventory/transfer_view.php']],
        ['/inventory/movements.php', 'Movement history', 'inventory.view', 'history'],
    ]);
}

function nav_purchase(): void
{
    render_subnav([
        ['/purchase/orders.php', 'Purchase orders', 'purchase.manage', 'file-text', ['/purchase/order_form.php', '/purchase/order_view.php']],
        ['/purchase/receipts.php', 'Goods received', 'purchase.receive', 'package-check', ['/purchase/grn_form.php', '/purchase/grn_view.php']],
        ['/purchase/returns.php', 'Returns to supplier', 'purchase.manage', 'undo-2', ['/purchase/return_form.php', '/purchase/return_view.php']],
        ['/purchase/suppliers.php', 'Suppliers', 'suppliers.view', 'building-2', ['/purchase/supplier_view.php']],
    ]);
}

function nav_finance(): void
{
    render_subnav([
        ['/finance/expenses.php', 'Expenses', 'finance.view', 'receipt'],
        ['/finance/cashbook.php', 'Cash & Bank', 'finance.view', 'landmark'],
        ['/finance/receivables.php', 'Receivables', 'finance.view', 'hand-coins'],
        ['/finance/payables.php', 'Payables', 'finance.view', 'file-clock'],
        ['/finance/expense_categories.php', 'Expense categories', 'expenses.manage', 'tags'],
    ]);
}

function nav_reports(): void
{
    render_subnav([
        ['/reports/index.php', 'Profit & Loss', 'reports.view', 'scale'],
        ['/reports/sales.php', 'Sales', 'reports.view', 'trending-up'],
        ['/reports/products.php', 'Product performance', 'reports.view', 'package-search'],
        ['/reports/inventory.php', 'Inventory', 'reports.view', 'warehouse'],
        ['/reports/tax.php', 'Tax', 'reports.view', 'percent'],
        ...(count(accessible_branches()) > 1 ? [['/reports/branches.php', 'Branches', 'reports.view', 'building-2']] : []),
    ]);
}
