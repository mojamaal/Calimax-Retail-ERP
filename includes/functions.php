<?php
/**
 * General-purpose helpers shared across the app: settings, formatting,
 * document numbering, the stock-movement engine, payment methods and
 * small UI building blocks.
 */
require_once __DIR__ . '/../config/db.php';

/** Sale statuses that count as revenue (returns are deducted separately). */
const SALE_COUNTED = "('completed','partially_refunded','refunded')";

// ---------------------------------------------------------------------
// Settings & formatting
// ---------------------------------------------------------------------

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function format_money(float|string|null $amount): string
{
    return setting('currency_symbol', 'Rs.') . ' ' . number_format((float) $amount, 2);
}

/** Compact money for KPI tiles: Rs. 1.25M / Rs. 845.2K / Rs. 950.00 */
function format_money_short(float|string|null $amount): string
{
    $n = (float) $amount;
    $abs = abs($n);
    $sym = setting('currency_symbol', 'Rs.');
    if ($abs >= 1_000_000) { return $sym . ' ' . number_format($n / 1_000_000, 2) . 'M'; }
    if ($abs >= 100_000)   { return $sym . ' ' . number_format($n / 1_000, 1) . 'K'; }
    return $sym . ' ' . number_format($n, 2);
}

function fmt_date(?string $value): string
{
    return $value ? date('M j, Y', strtotime($value)) : '—';
}

function fmt_datetime(?string $value): string
{
    return $value ? date('M j, Y g:i A', strtotime($value)) : '—';
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function input(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}

function input_int(array $source, string $key, int $default = 0): int
{
    return isset($source[$key]) && is_numeric($source[$key]) ? (int) $source[$key] : $default;
}

function input_float(array $source, string $key, float $default = 0.0): float
{
    return isset($source[$key]) && is_numeric($source[$key]) ? (float) $source[$key] : $default;
}

/** A Y-m-d date from input, or the default when missing/invalid. */
function input_date(array $source, string $key, ?string $default = null): ?string
{
    $v = input($source, $key);
    if ($v !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false) { return $v; }
    return $default;
}

/** Build a query string from the current GET params with overrides. */
function query_with(array $overrides): string
{
    $q = array_merge($_GET, $overrides);
    foreach ($q as $k => $v) { if ($v === null || $v === '') unset($q[$k]); }
    return $q ? '?' . http_build_query($q) : '?';
}

// ---------------------------------------------------------------------
// Document numbering — race-safe sequences (INV-000123, PO-000045 ...)
// ---------------------------------------------------------------------

function next_document_number(string $prefix, string $table, string $column, ?int $branchId = null): string
{
    $allowed = ['sales', 'purchase_orders', 'goods_received_notes', 'purchase_returns', 'sale_returns', 'stock_adjustments', 'stock_counts', 'stock_transfers'];
    if (!in_array($table, $allowed, true) || !preg_match('/^[a-z_]+$/', $column)) {
        throw new InvalidArgumentException('Invalid table for document numbering.');
    }
    $pdo = db();
    // Each branch has its own counter and number series: the main branch keeps plain
    // "INV-000123"; other branches are prefixed with their code ("COL-INV-000001").
    $branchId = $branchId ?? write_branch();
    $branch = branch_row($branchId);
    $isMain = !$branch || (int) $branch['is_main'] === 1;
    $key = $isMain ? $table : $table . ':' . $branchId;
    $tag = $isMain ? '' : $branch['code'] . '-';

    $exists = $pdo->prepare('SELECT COUNT(*) FROM document_sequences WHERE seq_key = :k');
    $exists->execute([':k' => $key]);
    if (!(int) $exists->fetchColumn()) {
        $hasBranch = $table !== 'stock_transfers';
        $where = $hasBranch ? 'WHERE branch_id = ' . (int) $branchId : ($isMain ? '' : 'WHERE 1=0');
        $max = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(`$column`, '-', -1) AS UNSIGNED)), 0) FROM `$table` $where")->fetchColumn();
        $pdo->prepare('INSERT IGNORE INTO document_sequences (seq_key, next_value) VALUES (:k, :v)')->execute([':k' => $key, ':v' => $max + 1]);
    }
    // Atomic increment: LAST_INSERT_ID(expr) is connection-local, so concurrent tills never share a number.
    $pdo->prepare('UPDATE document_sequences SET next_value = LAST_INSERT_ID(next_value + 1) WHERE seq_key = :k')->execute([':k' => $key]);
    $number = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn() - 1;
    return sprintf('%s%s-%06d', $tag, $prefix, $number);
}

// ---------------------------------------------------------------------
// Stock engine — every quantity change goes through here so the
// movement ledger (with running balance) is always complete.
// ---------------------------------------------------------------------

/**
 * Apply a signed quantity change to a variant's saleable or damaged bucket
 * and write the movement record. Must run inside the caller's transaction.
 * Returns the bucket's new balance.
 */
function apply_stock_change(PDO $pdo, int $variantId, int $qty, string $type, ?string $refType = null, ?int $refId = null, ?string $note = null, string $bucket = 'saleable', ?int $branchId = null): int
{
    $branchId = $branchId ?? write_branch();
    $col = $bucket === 'damaged' ? 'damaged_qty' : 'stock_qty';
    ensure_branch_stock($pdo, $variantId, $branchId);
    $row = $pdo->prepare("SELECT $col FROM branch_stock WHERE variant_id = :id AND branch_id = :b FOR UPDATE");
    $row->execute([':id' => $variantId, ':b' => $branchId]);
    $current = $row->fetchColumn();
    if ($current === false) { throw new RuntimeException('Product variant not found.'); }
    $new = (int) $current + $qty;

    $pdo->prepare("UPDATE branch_stock SET $col = :n WHERE variant_id = :id AND branch_id = :b")->execute([':n' => $new, ':id' => $variantId, ':b' => $branchId]);
    // product_variants keeps the company-wide total.
    $pdo->prepare("UPDATE product_variants SET $col = $col + :q WHERE id = :id")->execute([':q' => $qty, ':id' => $variantId]);
    $pdo->prepare(
        'INSERT INTO stock_movements (variant_id, branch_id, change_qty, type, bucket, balance_after, reference_type, reference_id, note, created_by)
         VALUES (:v, :br, :q, :t, :b, :bal, :rt, :rid, :note, :u)'
    )->execute([
        ':v' => $variantId, ':br' => $branchId, ':q' => $qty, ':t' => $type, ':b' => $bucket, ':bal' => $new,
        ':rt' => $refType, ':rid' => $refId, ':note' => $note, ':u' => current_user()['id'] ?? null,
    ]);
    return $new;
}

/**
 * Weighted-average cost update when new stock is received at a given unit cost.
 * Call BEFORE the received quantity is added to stock. Each branch has its own
 * average cost (it pays its own suppliers); the company-wide average is kept too.
 */
function update_average_cost(PDO $pdo, int $variantId, int $qtyIn, float $unitCost, ?int $branchId = null): void
{
    if ($qtyIn <= 0) return;
    $branchId = $branchId ?? write_branch();
    ensure_branch_stock($pdo, $variantId, $branchId);
    $s = $pdo->prepare('SELECT stock_qty, cost_price FROM branch_stock WHERE variant_id = :id AND branch_id = :b FOR UPDATE');
    $s->execute([':id' => $variantId, ':b' => $branchId]);
    $v = $s->fetch();
    if ($v) {
        $onHand = max(0, (int) $v['stock_qty']);
        $newCost = $onHand > 0 ? (($onHand * (float) $v['cost_price']) + ($qtyIn * $unitCost)) / ($onHand + $qtyIn) : $unitCost;
        $pdo->prepare('UPDATE branch_stock SET cost_price = :c WHERE variant_id = :id AND branch_id = :b')->execute([':c' => round($newCost, 2), ':id' => $variantId, ':b' => $branchId]);
    }
    $g = $pdo->prepare('SELECT stock_qty, cost_price FROM product_variants WHERE id = :id FOR UPDATE');
    $g->execute([':id' => $variantId]);
    $gv = $g->fetch();
    if ($gv) {
        $onHand = max(0, (int) $gv['stock_qty']);
        $newCost = $onHand > 0 ? (($onHand * (float) $gv['cost_price']) + ($qtyIn * $unitCost)) / ($onHand + $qtyIn) : $unitCost;
        $pdo->prepare('UPDATE product_variants SET cost_price = :c WHERE id = :id')->execute([':c' => round($newCost, 2), ':id' => $variantId]);
    }
}

function movement_types(): array
{
    return [
        'initial'          => ['Opening stock', 'neutral'],
        'purchase'         => ['Goods received', 'success'],
        'sale'             => ['Sale', 'orange'],
        'sale_void'        => ['Sale cancelled', 'neutral'],
        'sale_return'      => ['Customer return', 'info'],
        'return_damaged'   => ['Return (damaged)', 'warning'],
        'purchase_return'  => ['Returned to supplier', 'warning'],
        'adjustment'       => ['Adjustment', 'neutral'],
        'damaged'          => ['Marked damaged', 'warning'],
        'write_off'        => ['Write-off', 'error'],
        'count'            => ['Stock count', 'info'],
        'transfer_out'     => ['Transferred out', 'warning'],
        'transfer_in'      => ['Transferred in', 'success'],
        'transfer_return'  => ['Transfer shortfall returned', 'neutral'],
    ];
}

// ---------------------------------------------------------------------
// Payment methods
// ---------------------------------------------------------------------

/** POS tender types and the money account each one lands in. */
function payment_methods(): array
{
    return [
        'cash'          => ['label' => 'Cash',            'icon' => 'banknote',     'account' => 'cash'],
        'card'          => ['label' => 'Card',            'icon' => 'credit-card',  'account' => 'bank'],
        'bank_transfer' => ['label' => 'Bank Transfer',   'icon' => 'landmark',     'account' => 'bank'],
        'mobile_wallet' => ['label' => 'Mobile Payment',  'icon' => 'smartphone',   'account' => 'bank'],
        'store_credit'  => ['label' => 'Credit (On Account)', 'icon' => 'notebook-pen', 'account' => 'receivable'],
        'exchange'      => ['label' => 'Exchange Credit', 'icon' => 'repeat',       'account' => 'none'],
    ];
}

/** Methods used for paying/receiving money outside the POS (expenses, suppliers, customers). */
function money_methods(): array
{
    return ['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'card' => 'Card', 'cheque' => 'Cheque', 'mobile_wallet' => 'Mobile Payment'];
}

function method_label(?string $method): string
{
    return payment_methods()[$method]['label'] ?? money_methods()[$method] ?? ucwords(str_replace('_', ' ', (string) $method));
}

/** SQL list of methods that settle into the given money account. */
function methods_for_account(string $account): string
{
    $m = $account === 'cash' ? ["'cash'"] : ["'card'", "'bank_transfer'", "'mobile_wallet'", "'cheque'"];
    return '(' . implode(',', $m) . ')';
}

// ---------------------------------------------------------------------
// Reporting period resolver (shared by dashboard, reports and prints)
// ---------------------------------------------------------------------

function resolve_report_period(string $defaultPreset = 'this_month'): array
{
    $quarterStartMonth = (int) (floor((((int) date('n')) - 1) / 3) * 3 + 1);
    $presets = [
        'today'        => ['label' => 'Today',        'from' => date('Y-m-d'), 'to' => date('Y-m-d')],
        'yesterday'    => ['label' => 'Yesterday',    'from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d', strtotime('-1 day'))],
        'this_week'    => ['label' => 'This Week',    'from' => date('Y-m-d', strtotime('monday this week')), 'to' => date('Y-m-d')],
        'last_30'      => ['label' => 'Last 30 Days', 'from' => date('Y-m-d', strtotime('-29 days')), 'to' => date('Y-m-d')],
        'this_month'   => ['label' => 'This Month',   'from' => date('Y-m-01'), 'to' => date('Y-m-d')],
        'last_month'   => ['label' => 'Last Month',   'from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('last day of last month'))],
        'this_quarter' => ['label' => 'This Quarter', 'from' => date('Y') . '-' . str_pad((string) $quarterStartMonth, 2, '0', STR_PAD_LEFT) . '-01', 'to' => date('Y-m-d')],
        'this_year'    => ['label' => 'This Year',    'from' => date('Y-01-01'), 'to' => date('Y-m-d')],
    ];

    $preset = input($_GET, 'preset');
    if ($preset === '' && !isset($_GET['from']) && !isset($_GET['to'])) { $preset = $defaultPreset; }
    if ($preset !== '' && isset($presets[$preset])) {
        $from = $presets[$preset]['from'];
        $to = $presets[$preset]['to'];
        $label = $presets[$preset]['label'];
    } else {
        $from = input_date($_GET, 'from', date('Y-m-01'));
        $to = input_date($_GET, 'to', date('Y-m-d'));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        $preset = '';
        $label = fmt_date($from) . ' – ' . fmt_date($to);
    }
    return ['from' => $from, 'to' => $to, 'preset' => $preset, 'presets' => $presets, 'label' => $label];
}

// ---------------------------------------------------------------------
// Product codes
// ---------------------------------------------------------------------

/** Internal barcode for items without a manufacturer barcode (EAN-13 with valid check digit, '2' in-store prefix). */
function generate_barcode(): string
{
    do {
        $base = '2' . str_pad((string) random_int(0, 99999999999), 11, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) { $sum += (int) $base[$i] * ($i % 2 ? 3 : 1); }
        $code = $base . ((10 - $sum % 10) % 10);
        $stmt = db()->prepare('SELECT COUNT(*) FROM product_variants WHERE barcode = :b');
        $stmt->execute([':b' => $code]);
    } while ((int) $stmt->fetchColumn() > 0);
    return $code;
}

function generate_sku(string $productName, string $variantLabel = ''): string
{
    $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $productName));
    $base = substr($base, 0, 6) ?: 'PRD';
    $suffix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $variantLabel));
    return $base . ($suffix !== '' ? '-' . $suffix : '') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

/** Is a SKU/barcode free (optionally ignoring one variant)? */
function variant_code_available(string $column, string $value, int $ignoreId = 0): bool
{
    if (!in_array($column, ['sku', 'barcode'], true)) return false;
    $s = db()->prepare("SELECT COUNT(*) FROM product_variants WHERE `$column` = :v AND id <> :id");
    $s->execute([':v' => $value, ':id' => $ignoreId]);
    return (int) $s->fetchColumn() === 0;
}

// ---------------------------------------------------------------------
// UI helpers
// ---------------------------------------------------------------------

function icon(string $name, int $size = 16, string $extra = ''): string
{
    return '<i data-lucide="' . e($name) . '" style="width:' . $size . 'px;height:' . $size . 'px" ' . $extra . '></i>';
}

/** Colored status badge for any document status. */
function status_badge(?string $status): string
{
    $map = [
        'completed' => 'success', 'received' => 'success', 'approved' => 'success', 'processed' => 'success', 'paid' => 'success', 'active' => 'success',
        'held' => 'info', 'draft' => 'neutral', 'in_progress' => 'info', 'in_transit' => 'info', 'submitted' => 'warning',
        'ordered' => 'warning', 'partially_received' => 'warning', 'pending' => 'warning', 'partially_refunded' => 'warning', 'credit' => 'warning',
        'voided' => 'error', 'cancelled' => 'error', 'rejected' => 'error', 'refunded' => 'neutral', 'closed' => 'neutral', 'inactive' => 'neutral',
    ];
    $labels = ['partially_received' => 'Part received', 'partially_refunded' => 'Part refunded', 'in_progress' => 'In progress', 'in_transit' => 'In transit', 'voided' => 'Cancelled'];
    $s = (string) $status;
    return '<span class="badge badge-' . ($map[$s] ?? 'neutral') . '">' . e($labels[$s] ?? ucwords(str_replace('_', ' ', $s))) . '</span>';
}

function empty_state(string $iconName, string $title, string $hint = '', string $actionHtml = ''): string
{
    return '<div class="empty-state"><div class="icon-tile">' . icon($iconName, 22) . '</div><div class="title">' . e($title) . '</div>'
        . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . $actionHtml . '</div>';
}

/** Module tab bar. $items: [ [href, label, permission?, icon?], ... ]. Active = matches current script. */
function render_subnav(array $items): void
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    echo '<nav class="subnav" aria-label="Section">';
    foreach ($items as $it) {
        [$href, $label] = $it;
        $perm = $it[2] ?? null;
        $ico = $it[3] ?? null;
        if ($perm && !can($perm)) continue;
        $path = parse_url($href, PHP_URL_PATH);
        $active = str_ends_with($script, $path) ? ' active' : '';
        foreach ($it[4] ?? [] as $alias) { if (str_ends_with($script, $alias)) $active = ' active'; }
        echo '<a class="subnav-item' . $active . '" href="' . e(app_url($href)) . '">' . ($ico ? icon($ico, 15) : '') . '<span>' . e($label) . '</span></a>';
    }
    echo '</nav>';
}

/** Standard page header. */
function page_header(string $title, string $subtitle = '', string $actionsHtml = '', array $crumbs = []): void
{
    echo '<div class="page-head"><div class="page-head-text">';
    if ($crumbs) {
        echo '<div class="crumbs">';
        foreach ($crumbs as $i => [$label, $href]) {
            echo ($i ? '<span class="sep">/</span>' : '') . ($href ? '<a href="' . e(app_url($href)) . '">' . e($label) . '</a>' : '<span>' . e($label) . '</span>');
        }
        echo '</div>';
    }
    echo '<h1 class="page-title">' . e($title) . '</h1>';
    if ($subtitle !== '') echo '<p class="page-subtitle">' . $subtitle . '</p>';
    echo '</div>';
    if ($actionsHtml !== '') echo '<div class="page-actions">' . $actionsHtml . '</div>';
    echo '</div>';
}

/** KPI tile. */
function kpi(string $label, string $value, string $iconName = '', string $tone = '', string $desc = '', ?string $href = null): string
{
    $tag = $href ? 'a href="' . e(app_url($href)) . '"' : 'div';
    $close = $href ? 'a' : 'div';
    return '<' . $tag . ' class="kpi-card' . ($href ? ' is-link' : '') . '">'
        . '<div class="kpi-top"><span class="kpi-label">' . e($label) . '</span>' . ($iconName ? '<span class="icon-tile sm ' . e($tone) . '">' . icon($iconName, 16) . '</span>' : '') . '</div>'
        . '<div class="kpi-value">' . $value . '</div>'
        . ($desc !== '' ? '<div class="kpi-desc">' . $desc . '</div>' : '')
        . '</' . $close . '>';
}

/** Pagination controls. */
function render_pagination(int $page, int $perPage, int $total): void
{
    $pages = (int) ceil($total / max(1, $perPage));
    if ($pages <= 1) return;
    echo '<div class="pagination"><span class="text-muted">Page ' . $page . ' of ' . $pages . ' · ' . number_format($total) . ' records</span><div class="flex gap-2">';
    if ($page > 1) echo '<a class="btn btn-secondary btn-sm" href="' . e(query_with(['page' => $page - 1])) . '">' . icon('chevron-left', 14) . ' Prev</a>';
    if ($page < $pages) echo '<a class="btn btn-secondary btn-sm" href="' . e(query_with(['page' => $page + 1])) . '">Next ' . icon('chevron-right', 14) . '</a>';
    echo '</div></div>';
}

/** Period preset chips + custom range, as a GET form. */
function render_period_filter(array $period, array $extraHidden = []): void
{
    echo '<form method="get" class="filter-bar period-filter">';
    foreach ($extraHidden as $k => $v) echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    echo '<div class="chip-group">';
    foreach ($period['presets'] as $key => $p) {
        echo '<a class="chip' . ($period['preset'] === $key ? ' active' : '') . '" href="' . e(query_with(['preset' => $key, 'from' => null, 'to' => null, 'page' => null])) . '">' . e($p['label']) . '</a>';
    }
    echo '</div><div class="range-inputs"><input class="form-control sm" type="date" name="from" value="' . e($period['from']) . '" aria-label="From date">'
        . '<span class="text-muted">to</span><input class="form-control sm" type="date" name="to" value="' . e($period['to']) . '" aria-label="To date">'
        . '<input type="hidden" name="preset" value="custom"><button class="btn btn-secondary btn-sm" type="submit">Apply</button></div></form>';
}
