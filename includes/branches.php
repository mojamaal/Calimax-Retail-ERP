<?php
/**
 * Multi-branch support.
 *
 * Model
 *  - Every operational record (sale, purchase, expense, adjustment ...) carries a branch_id.
 *  - Stock quantities, selling prices, average cost and reorder levels are per branch
 *    (table branch_stock). product_variants.stock_qty / damaged_qty are company-wide totals.
 *  - Suppliers, customers, products and categories are shared by the whole business;
 *    what a supplier is owed is tracked per branch (supplier_balances).
 *  - A user belongs to one branch. Users flagged "all_branches" (the owner by default)
 *    can switch branches or view every branch consolidated ("All branches").
 *
 * The "current branch" scope drives every list, report and dashboard automatically:
 * current_branch_id() returns a branch id, or NULL for the consolidated all-branches view.
 */

/** @return array<int,array> all branches keyed by id (cached per request). */
function branches_all(bool $activeOnly = true): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM branches ORDER BY is_main DESC, name') as $b) { $cache[(int) $b['id']] = $b; }
    }
    return $activeOnly ? array_filter($cache, fn($b) => (int) $b['is_active'] === 1) : $cache;
}

function branch_row(?int $id): ?array
{
    return $id ? (branches_all(false)[$id] ?? null) : null;
}

function user_has_all_branches(?array $user = null): bool
{
    $user = $user ?? current_user();
    if (!$user) return false;
    return (int) ($user['all_branches'] ?? 0) === 1 || in_array($user['role'] ?? '', ['owner', 'admin'], true);
}

/** Branches the logged-in user may work in. */
function accessible_branches(): array
{
    $all = branches_all();
    if (user_has_all_branches()) return $all;
    $own = (int) (current_user()['branch_id'] ?? 1);
    return isset($all[$own]) ? [$own => $all[$own]] : array_slice($all, 0, 1, true);
}

/** The branch being worked in: an id, or null = all branches combined (only for multi-branch users). */
function current_branch_id(): ?int
{
    if (array_key_exists('__branch_override', $GLOBALS)) return $GLOBALS['__branch_override'];   // see with_branch()
    static $resolved = false, $value = null;
    if ($resolved) return $value;
    $user = current_user();
    if (!$user) return 1;                       // login page etc.
    $resolved = true;
    $ok = accessible_branches();
    $home = (int) ($user['branch_id'] ?? 1);
    if (!isset($ok[$home])) { $home = (int) array_key_first($ok); }
    if (!user_has_all_branches()) return $value = $home;
    $sel = $_SESSION['branch_id'] ?? $home;
    if ((int) $sel === 0 && count($ok) > 1) return $value = null;
    return $value = isset($ok[(int) $sel]) ? (int) $sel : $home;
}

/** Run $fn with the branch scope temporarily set (null = all branches). Used for side-by-side comparisons. */
function with_branch(?int $branchId, callable $fn): mixed
{
    $had = array_key_exists('__branch_override', $GLOBALS);
    $prev = $GLOBALS['__branch_override'] ?? null;
    $GLOBALS['__branch_override'] = $branchId;
    try { return $fn(); }
    finally { if ($had) $GLOBALS['__branch_override'] = $prev; else unset($GLOBALS['__branch_override']); }
}

function current_branch(): ?array
{
    return branch_row(current_branch_id());
}

/** Name shown in headers/badges for the current scope. */
function branch_scope_label(): string
{
    return current_branch()['name'] ?? 'All branches';
}

/** Change the working branch (0 = all). Returns false if not permitted. */
function switch_branch(int $id): bool
{
    if ($id === 0) {
        if (!user_has_all_branches() || count(accessible_branches()) < 2) return false;
        $_SESSION['branch_id'] = 0;
        return true;
    }
    if (!isset(accessible_branches()[$id])) return false;
    $_SESSION['branch_id'] = $id;
    return true;
}

/** " AND s.branch_id = 3" for the current scope, or '' when viewing all branches. */
function branch_and(string $alias = '', string $col = 'branch_id'): string
{
    $b = current_branch_id();
    if ($b === null) return '';
    return ' AND ' . ($alias !== '' ? $alias . '.' : '') . $col . ' = ' . $b;
}

/** Same but as a WHERE fragment that is always valid: "1=1" or "s.branch_id = 3". */
function branch_where(string $alias = '', string $col = 'branch_id'): string
{
    return '1=1' . branch_and($alias, $col);
}

/**
 * Branch an action should be recorded against. When the user is looking at
 * "All branches" they must pick one first — we never guess where money or stock goes.
 */
function write_branch(): int
{
    $b = current_branch_id();
    if ($b !== null) return $b;
    $msg = 'Choose a branch (top bar) before doing this — you are viewing all branches combined.';
    if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg]);
        exit;
    }
    flash('error', $msg);
    redirect('/index.php');
}

/** Page-level guard for screens that only make sense inside one branch (POS, receiving...). */
function require_single_branch(): int
{
    $b = current_branch_id();
    if ($b !== null) return $b;
    $pageTitle = 'Choose a branch';
    require __DIR__ . '/header.php';
    echo '<div class="card"><div class="empty-state">' . icon('building-2', 36) . '<h3>Select a branch to continue</h3>'
       . '<p>You are viewing <strong>all branches</strong> combined. This screen works inside one branch — pick it from the branch selector in the top bar.</p></div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

/**
 * Derived table that exposes product_variants with THIS branch's stock, price,
 * cost and reorder level under the usual column names. Use in place of the
 * table name:   FROM ' . pv() . ' v   (read queries only).
 * In the all-branches view it is simply the base table (company totals).
 */
function pv(?int $branchId = null): string
{
    $b = $branchId ?? current_branch_id();
    if ($b === null) return 'product_variants';
    $b = (int) $b;
    return "(SELECT v0.id, v0.product_id, v0.sku, v0.barcode, v0.variant_label,
                    COALESCE(bs0.price, v0.price) AS price, COALESCE(bs0.cost_price, v0.cost_price) AS cost_price,
                    COALESCE(bs0.reorder_level, v0.reorder_level) AS reorder_level,
                    COALESCE(bs0.stock_qty, 0) AS stock_qty, COALESCE(bs0.damaged_qty, 0) AS damaged_qty,
                    v0.is_active, v0.created_at, v0.updated_at
             FROM product_variants v0 LEFT JOIN branch_stock bs0 ON bs0.variant_id = v0.id AND bs0.branch_id = $b)";
}

/** Make sure a branch_stock row exists (seeded with the base price/cost) and return nothing. */
function ensure_branch_stock(PDO $pdo, int $variantId, int $branchId): void
{
    $pdo->prepare('INSERT IGNORE INTO branch_stock (variant_id, branch_id, stock_qty, damaged_qty, price, cost_price, reorder_level)
                   SELECT id, :b, 0, 0, price, cost_price, reorder_level FROM product_variants WHERE id = :v')
        ->execute([':b' => $branchId, ':v' => $variantId]);
}

/** Set a branch's selling price (and optionally reorder level) for a variant. */
function set_branch_price(PDO $pdo, int $variantId, int $branchId, float $price, ?int $reorder = null): void
{
    ensure_branch_stock($pdo, $variantId, $branchId);
    $pdo->prepare('UPDATE branch_stock SET price = :p' . ($reorder !== null ? ', reorder_level = :r' : '') . ' WHERE variant_id = :v AND branch_id = :b')
        ->execute(array_filter([':p' => round($price, 2), ':r' => $reorder, ':v' => $variantId, ':b' => $branchId], fn($x) => $x !== null));
}

/** Per-branch rows for a variant (stock + price) for every active branch. */
function variant_branch_rows(int $variantId): array
{
    $s = db()->prepare('SELECT b.id AS branch_id, b.name, b.code, COALESCE(bs.stock_qty,0) AS stock_qty, COALESCE(bs.damaged_qty,0) AS damaged_qty,
                               COALESCE(bs.price, v.price) AS price, COALESCE(bs.cost_price, v.cost_price) AS cost_price,
                               COALESCE(bs.reorder_level, v.reorder_level) AS reorder_level, (bs.variant_id IS NOT NULL) AS has_row
                        FROM branches b JOIN product_variants v ON v.id = :v
                        LEFT JOIN branch_stock bs ON bs.variant_id = v.id AND bs.branch_id = b.id
                        WHERE b.is_active = 1 ORDER BY b.is_main DESC, b.name');
    $s->execute([':v' => $variantId]);
    return $s->fetchAll();
}

// ---------------------------------------------------------------------
// Supplier balances are tracked per branch ("each branch pays its own")
// ---------------------------------------------------------------------

function supplier_balance_adjust(PDO $pdo, int $supplierId, int $branchId, float $delta): void
{
    $pdo->prepare('UPDATE suppliers SET balance = balance + :d WHERE id = :id')->execute([':d' => $delta, ':id' => $supplierId]);
    $pdo->prepare('INSERT INTO supplier_balances (supplier_id, branch_id, balance) VALUES (:s, :b, :d)
                   ON DUPLICATE KEY UPDATE balance = balance + VALUES(balance)')
        ->execute([':s' => $supplierId, ':b' => $branchId, ':d' => $delta]);
}

/** SQL expression for what the supplier alias is owed in the current scope. */
function supplier_balance_sql(string $alias = 's'): string
{
    $b = current_branch_id();
    if ($b === null) return $alias . '.balance';
    return "COALESCE((SELECT sb.balance FROM supplier_balances sb WHERE sb.supplier_id = $alias.id AND sb.branch_id = $b), 0)";
}

/** Branch block (name, address, phone, footer) for receipts and print headers. */
function branch_letterhead(?int $branchId = null): array
{
    $b = branch_row($branchId ?? current_branch_id()) ?? branch_row(1) ?? [];
    return [
        'business' => setting('store_name', 'Store'),
        'branch'   => $b['name'] ?? '',
        'address'  => ($b['address'] ?? '') ?: setting('store_address'),
        'phone'    => ($b['phone'] ?? '') ?: setting('store_phone'),
        'email'    => ($b['email'] ?? '') ?: setting('store_email'),
        'footer'   => ($b['receipt_footer'] ?? '') ?: setting('receipt_footer'),
    ];
}

function branch_badge(?int $branchId): string
{
    $b = branch_row($branchId);
    return $b ? '<span class="badge badge-neutral" title="' . e($b['name']) . '">' . e($b['code']) . '</span>' : '';
}

/** In the consolidated view, lists gain a "Branch" column so rows can be told apart. */
function show_branch_col(): bool { return current_branch_id() === null; }
function branch_th(): string { return show_branch_col() ? '<th>Branch</th>' : ''; }
function branch_td(?int $branchId): string { return show_branch_col() ? '<td>' . branch_badge($branchId) . '</td>' : ''; }

/**
 * Detail pages: staff tied to a branch may only open that branch's records
 * (even by typing a URL); multi-branch users may open any.
 */
function assert_branch_access(?int $branchId): void
{
    if ($branchId === null || user_has_all_branches()) return;
    if (!isset(accessible_branches()[$branchId])) deny_access();
}
