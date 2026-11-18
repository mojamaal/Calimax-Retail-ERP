<?php
/**
 * Financial calculations shared by the dashboard, reports and print views.
 *
 * Accounting rules used throughout:
 *  - Net sales      = sale totals EXCLUDING tax collected (tax is owed to the government),
 *                     minus customer returns (also excluding tax).
 *  - COGS           = cost snapshot stored on each sale line (sale_items.unit_cost),
 *                     minus the cost of items customers returned.
 *  - Inventory loss = approved stock adjustments that reduced inventory value
 *                     (expired, lost, theft, damaged write-offs, count shortages).
 *  - Gross profit   = net sales - COGS - inventory loss
 *  - Net profit     = gross profit - operating expenses
 *  - Voided (cancelled) sales and held carts are excluded everywhere.
 */
require_once __DIR__ . '/functions.php';

/** [from 00:00:00, to+1day 00:00:00) bounds so DATETIME indexes are usable. */
function period_bounds(string $from, string $to): array
{
    return [':f' => $from . ' 00:00:00', ':t' => date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
}

function fin_sales(string $from, string $to): array
{
    $s = db()->prepare(
        'SELECT COUNT(*) AS count, COALESCE(SUM(subtotal),0) AS gross, COALESCE(SUM(discount_amount),0) AS discounts,
                COALESCE(SUM(tax_amount),0) AS tax, COALESCE(SUM(total_amount),0) AS total
         FROM sales WHERE status IN ' . SALE_COUNTED . ' AND sale_date >= :f AND sale_date < :t' . branch_and()
    );
    $s->execute(period_bounds($from, $to));
    $r = $s->fetch();
    $r['net_ex_tax'] = (float) $r['total'] - (float) $r['tax'];
    return $r;
}

function fin_returns(string $from, string $to): array
{
    $s = db()->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total, COALESCE(SUM(tax_amount),0) AS tax, COALESCE(SUM(cost_amount),0) AS cost
         FROM sale_returns WHERE status = 'processed' AND COALESCE(approved_at, created_at) >= :f AND COALESCE(approved_at, created_at) < :t" . branch_and()
    );
    $s->execute(period_bounds($from, $to));
    $r = $s->fetch();
    $r['net_ex_tax'] = (float) $r['total'] - (float) $r['tax'];
    return $r;
}

function fin_cogs_gross(string $from, string $to): float
{
    $s = db()->prepare(
        'SELECT COALESCE(SUM(si.quantity * si.unit_cost),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
         WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s')
    );
    $s->execute(period_bounds($from, $to));
    return (float) $s->fetchColumn();
}

/** Net inventory value change from approved adjustments (negative = loss). */
function fin_inventory_adjustments(string $from, string $to): float
{
    $s = db()->prepare("SELECT COALESCE(SUM(value_change),0) FROM stock_adjustments WHERE status = 'approved' AND approved_at >= :f AND approved_at < :t" . branch_and());
    $s->execute(period_bounds($from, $to));
    return (float) $s->fetchColumn();
}

function fin_expenses_total(string $from, string $to): float
{
    $s = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date BETWEEN :f AND :t' . branch_and());
    $s->execute([':f' => $from, ':t' => $to]);
    return (float) $s->fetchColumn();
}

function fin_expenses_by_category(string $from, string $to): array
{
    $s = db()->prepare(
        "SELECT COALESCE(ec.name, 'Uncategorized') AS name, SUM(e.amount) AS total, COUNT(*) AS entries
         FROM expenses e LEFT JOIN expense_categories ec ON ec.id = e.category_id
         WHERE e.expense_date BETWEEN :f AND :t " . branch_and('e') . " GROUP BY ec.id, ec.name ORDER BY total DESC"
    );
    $s->execute([':f' => $from, ':t' => $to]);
    return $s->fetchAll();
}

/** Value of goods actually received from suppliers in the period. */
function fin_purchases(string $from, string $to): array
{
    $s = db()->prepare(
        "SELECT COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS total,
                COALESCE(SUM(CASE WHEN payment_mode = 'credit' THEN total_amount ELSE 0 END),0) AS on_credit
         FROM goods_received_notes WHERE received_date BETWEEN :f AND :t" . branch_and()
    );
    $s->execute([':f' => $from, ':t' => $to]);
    $r = $s->fetch();
    $ret = db()->prepare('SELECT COALESCE(SUM(total_amount),0) FROM purchase_returns WHERE return_date BETWEEN :f AND :t' . branch_and());
    $ret->execute([':f' => $from, ':t' => $to]);
    $r['returns'] = (float) $ret->fetchColumn();
    $r['net'] = (float) $r['total'] - $r['returns'];
    return $r;
}

/** Full profit & loss for a period. */
function fin_pnl(string $from, string $to): array
{
    $sales = fin_sales($from, $to);
    $returns = fin_returns($from, $to);
    $cogs = fin_cogs_gross($from, $to) - (float) $returns['cost'];
    $invAdj = fin_inventory_adjustments($from, $to);
    $netSales = $sales['net_ex_tax'] - $returns['net_ex_tax'];
    $gross = $netSales - $cogs + $invAdj;
    $expenses = fin_expenses_total($from, $to);
    return [
        'sales' => $sales,
        'returns' => $returns,
        'gross_sales' => (float) $sales['gross'],
        'discounts' => (float) $sales['discounts'],
        'net_sales' => $netSales,
        'tax_collected' => (float) $sales['tax'] - (float) $returns['tax'],
        'cogs' => $cogs,
        'inventory_adjustments' => $invAdj,
        'gross_profit' => $gross,
        'gross_margin' => $netSales > 0 ? $gross / $netSales * 100 : 0,
        'expenses' => $expenses,
        'net_profit' => $gross - $expenses,
        'net_margin' => $netSales > 0 ? ($gross - $expenses) / $netSales * 100 : 0,
    ];
}

function fin_payment_breakdown(string $from, string $to): array
{
    $s = db()->prepare(
        'SELECT sp.method, COUNT(DISTINCT sp.sale_id) AS count, COALESCE(SUM(sp.amount),0) AS total
         FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
         WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . '
         GROUP BY sp.method ORDER BY total DESC'
    );
    $s->execute(period_bounds($from, $to));
    return $s->fetchAll();
}

function fin_best_sellers(string $from, string $to, int $limit = 10): array
{
    $s = db()->prepare(
        'SELECT pv.id AS variant_id, p.id AS product_id, p.name, pv.variant_label, pv.sku, pv.stock_qty,
                SUM(si.quantity) AS qty, SUM(si.line_total - si.tax_amount) AS revenue,
                SUM(si.line_total - si.tax_amount - si.quantity * si.unit_cost) AS profit
         FROM sale_items si JOIN sales s ON s.id = si.sale_id
         JOIN ' . pv() . ' pv ON pv.id = si.variant_id JOIN products p ON p.id = pv.product_id
         WHERE s.status IN ' . SALE_COUNTED . ' AND s.sale_date >= :f AND s.sale_date < :t' . branch_and('s') . '
         GROUP BY pv.id ORDER BY qty DESC LIMIT ' . (int) $limit
    );
    $s->execute(period_bounds($from, $to));
    return $s->fetchAll();
}

/** Daily net sales series for charts: [ ['date' => Y-m-d, 'total' => float, 'count' => int], ...] (gaps filled). */
function fin_daily_sales(string $from, string $to): array
{
    $s = db()->prepare(
        'SELECT DATE(sale_date) AS d, COUNT(*) AS c, COALESCE(SUM(total_amount),0) AS t
         FROM sales WHERE status IN ' . SALE_COUNTED . ' AND sale_date >= :f AND sale_date < :t' . branch_and() . ' GROUP BY DATE(sale_date)'
    );
    $s->execute(period_bounds($from, $to));
    $map = [];
    foreach ($s->fetchAll() as $r) { $map[$r['d']] = $r; }
    $out = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d = strtotime('+1 day', $d)) {
        $k = date('Y-m-d', $d);
        $out[] = ['date' => $k, 'total' => (float) ($map[$k]['t'] ?? 0), 'count' => (int) ($map[$k]['c'] ?? 0)];
    }
    return $out;
}

function inventory_value(): array
{
    return db()->query(
        'SELECT COALESCE(SUM(GREATEST(stock_qty,0) * cost_price),0) AS cost_value,
                COALESCE(SUM(GREATEST(stock_qty,0) * price),0) AS retail_value,
                COALESCE(SUM(damaged_qty * cost_price),0) AS damaged_value,
                COALESCE(SUM(GREATEST(stock_qty,0)),0) AS units,
                SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN stock_qty > 0 AND stock_qty <= reorder_level THEN 1 ELSE 0 END) AS low_stock,
                COALESCE(SUM(damaged_qty),0) AS damaged_units
         FROM ' . pv() . ' v WHERE is_active = 1'
    )->fetch();
}

/** Customers are shared by every branch, so receivables are company-wide. */
/**
 * Side-by-side results of every branch the user can see (sales, profit, stock value, cash...).
 * Returns [ ['branch' => row, 'pnl' => ..., 'inventory' => ..., 'cash' => float, 'bank' => float, 'payables' => float], ... ]
 */
function branch_performance(string $from, string $to): array
{
    $out = [];
    foreach (accessible_branches() as $b) {
        $out[] = with_branch((int) $b['id'], fn() => [
            'branch' => $b,
            'pnl' => fin_pnl($from, $to),
            'inventory' => inventory_value(),
            'cash' => cashbook_balance('cash'),
            'bank' => cashbook_balance('bank'),
            'payables' => payables_total(),
        ]);
    }
    return $out;
}

function receivables_total(): float
{
    return (float) db()->query('SELECT COALESCE(SUM(credit_balance),0) FROM customers WHERE credit_balance > 0')->fetchColumn();
}

/** What suppliers are owed in the current scope (each branch pays its own bills). */
function payables_total(): float
{
    $b = current_branch_id();
    if ($b === null) return (float) db()->query('SELECT COALESCE(SUM(balance),0) FROM supplier_balances WHERE balance > 0')->fetchColumn();
    return (float) db()->query("SELECT COALESCE(SUM(balance),0) FROM supplier_balances WHERE balance > 0 AND branch_id = $b")->fetchColumn();
}

// ---------------------------------------------------------------------
// Cash & bank book
// ---------------------------------------------------------------------

/**
 * Every money movement into/out of an account ('cash' or 'bank') as a unified
 * list. Positive = money in, negative = money out. Optional date bounds.
 */
function cashbook_entries(string $account, ?string $from = null, ?string $to = null, bool $sumOnly = false): array|float
{
    $pdo = db();
    $m = methods_for_account($account);
    $f = $from ?? '1970-01-01';
    $t = $to ?? '2999-12-31';
    [$fdt, $tdt] = array_values(period_bounds($f, $t));
    $p = [':f' => $fdt, ':t' => $tdt, ':fd' => $f, ':td' => $t];

    $sql = [];
    // Sales receipts (voided sales excluded: the money was handed back).
    $sql[] = "SELECT s.sale_date AS dt, 'Sale' AS kind, s.invoice_number AS ref, CONCAT('Sale ', s.invoice_number) AS description, sp.amount AS amount,
                     CONCAT('/pos/sale_view.php?id=', s.id) AS link
              FROM sale_payments sp JOIN sales s ON s.id = sp.sale_id
              WHERE sp.method IN $m AND s.status IN " . SALE_COUNTED . " AND s.sale_date >= :f AND s.sale_date < :t" . branch_and('s');
    // Customer payments on account.
    $sql[] = "SELECT cl.created_at, 'Customer payment', cl.reference_no, CONCAT('Payment from ', c.name), -cl.amount, CONCAT('/customers/view.php?id=', c.id)
              FROM customer_ledger cl JOIN customers c ON c.id = cl.customer_id
              WHERE cl.type = 'payment_received' AND COALESCE(cl.method,'cash') IN $m AND cl.created_at >= :f AND cl.created_at < :t" . branch_and('cl');
    // Customer refunds.
    $sql[] = "SELECT COALESCE(r.approved_at, r.created_at), 'Refund', r.return_number, CONCAT('Refund ', r.return_number), -r.total_amount, CONCAT('/pos/return_view.php?id=', r.id)
              FROM sale_returns r WHERE r.status = 'processed' AND r.return_type = 'refund' AND r.refund_method IN $m
                AND COALESCE(r.approved_at, r.created_at) >= :f AND COALESCE(r.approved_at, r.created_at) < :t" . branch_and('r');
    if ($account === 'cash') {
        $sql[] = "SELECT COALESCE(r.approved_at, r.created_at), 'Exchange cash back', r.return_number, CONCAT('Exchange balance paid out ', r.return_number), -r.exchange_cash_back, CONCAT('/pos/return_view.php?id=', r.id)
                  FROM sale_returns r WHERE r.exchange_cash_back > 0 AND COALESCE(r.approved_at, r.created_at) >= :f AND COALESCE(r.approved_at, r.created_at) < :t" . branch_and('r');
    }
    // Expenses.
    $sql[] = "SELECT CONCAT(e.expense_date, ' 12:00:00'), 'Expense', e.reference_no, e.description, -e.amount, '/finance/expenses.php'
              FROM expenses e WHERE e.payment_method IN $m AND e.expense_date BETWEEN :fd AND :td" . branch_and('e');
    // Supplier payments.
    $sql[] = "SELECT CONCAT(sp2.payment_date, ' 12:00:00'), 'Supplier payment', sp2.reference_no, CONCAT('Paid ', su.name), -sp2.amount, CONCAT('/purchase/supplier_view.php?id=', su.id)
              FROM supplier_payments sp2 JOIN suppliers su ON su.id = sp2.supplier_id
              WHERE sp2.payment_method IN $m AND sp2.payment_date BETWEEN :fd AND :td" . branch_and('sp2');
    // Manual transfers / capital / drawings / opening balances.
    $acc = $pdo->quote($account);
    $sql[] = "SELECT CONCAT(ct.txn_date, ' 08:00:00'), 'Transfer', ct.reference_no, COALESCE(ct.note, ct.type),
                     CASE WHEN ct.to_account = $acc THEN ct.amount ELSE -ct.amount END, '/finance/cashbook.php'
              FROM cash_transactions ct WHERE (ct.to_account = $acc OR ct.from_account = $acc) AND ct.txn_date BETWEEN :fd AND :td" . branch_and('ct');

    $union = implode(' UNION ALL ', array_map(fn($q) => "($q)", $sql));
    // Named placeholders can't repeat with native prepares, so expand them per sub-query.
    $i = 0; $params = [];
    $union = preg_replace_callback('/:(f|t|fd|td)\b/', function ($mm) use (&$i, &$params, $p) {
        $name = ':p' . (++$i);
        $params[$name] = $p[':' . $mm[1]];
        return $name;
    }, $union);
    if ($sumOnly) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM ($union) x");
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT * FROM ($union) x ORDER BY dt ASC");
    $stmt->execute($params);
    return array_map(fn($r) => [
        'dt' => $r['dt'], 'kind' => $r['kind'], 'ref' => $r['ref'], 'description' => $r['description'],
        'amount' => (float) $r['amount'], 'link' => $r['link'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function cashbook_balance(string $account, ?string $asOf = null): float
{
    return cashbook_entries($account, null, $asOf, true);
}

