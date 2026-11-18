<?php
/**
 * Shared page header. Set $pageTitle (and optionally $bodyClass) before including.
 * Must be included AFTER bootstrap.php and the page's permission guard.
 */
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$pdo = db();

// ---- Live notifications & approval counts (cheap aggregate queries) ----
$notifications = [];
$navBadges = ['returns' => 0, 'stock' => 0];
if (can('inventory.view') || can('products.manage') || can('purchase.manage')) {
    $__st = $pdo->query('SELECT SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) AS out_c, SUM(CASE WHEN stock_qty > 0 AND stock_qty <= reorder_level THEN 1 ELSE 0 END) AS low_c FROM ' . pv() . ' v WHERE is_active = 1')->fetch();
    if ((int) $__st['out_c'] > 0) $notifications[] = ['package-x', 'red', (int) $__st['out_c'] . ' item(s) out of stock', 'Customers can\'t buy these until restocked', '/inventory/products.php?stock=out'];
    if ((int) $__st['low_c'] > 0) $notifications[] = ['alert-triangle', 'amber', (int) $__st['low_c'] . ' item(s) at or below reorder level', 'Raise a purchase order to restock', '/inventory/products.php?stock=low'];
    unset($__st);
}
if (can('returns.approve')) {
    $navBadges['returns'] = (int) $pdo->query("SELECT COUNT(*) FROM sale_returns WHERE status = 'pending'" . branch_and())->fetchColumn();
    if ($navBadges['returns']) $notifications[] = ['undo-2', 'blue', $navBadges['returns'] . ' customer return(s) awaiting approval', 'Review and approve or reject', '/pos/returns.php?status=pending'];
}
if (can('inventory.approve')) {
    $pendAdj = (int) $pdo->query("SELECT COUNT(*) FROM stock_adjustments WHERE status = 'pending'" . branch_and())->fetchColumn();
    $pendCnt = (int) $pdo->query("SELECT COUNT(*) FROM stock_counts WHERE status = 'submitted'" . branch_and())->fetchColumn();
    $navBadges['stock'] = $pendAdj + $pendCnt;
    if ($pendAdj) $notifications[] = ['clipboard-check', 'purple', $pendAdj . ' stock adjustment(s) awaiting approval', 'Damaged, expired or lost stock', '/inventory/adjustments.php?status=pending'];
    if ($pendCnt) $notifications[] = ['list-checks', 'purple', $pendCnt . ' stock count(s) submitted', 'Review variances and approve', '/inventory/counts.php?status=submitted'];
}
if (can('pos.checkout')) {
    $held = (int) $pdo->query("SELECT COUNT(*) FROM sales WHERE status = 'held'" . branch_and())->fetchColumn();
    if ($held) $notifications[] = ['pause-circle', 'gray', $held . ' held sale(s) waiting at the till', 'Resume them from the POS screen', '/pos/index.php'];
}
if (can('purchase.receive')) {
    $due = (int) $pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status IN ('ordered','partially_received') AND expected_date IS NOT NULL AND expected_date <= CURDATE()" . branch_and())->fetchColumn();
    if ($due) $notifications[] = ['truck', 'amber', $due . ' purchase order(s) due for delivery', 'Receive goods when they arrive', '/purchase/orders.php?status=ordered'];
}
if (can('transfers.view')) {
    // Stock sent to this branch that has not been received yet.
    $__inb = current_branch_id();
    $__incoming = (int) $pdo->query("SELECT COUNT(*) FROM stock_transfers WHERE status = 'in_transit'" . ($__inb !== null ? ' AND to_branch_id = ' . $__inb : ''))->fetchColumn();
    if ($__incoming) $notifications[] = ['truck', 'blue', $__incoming . ' stock transfer(s) on the way', 'Check the goods and confirm receipt', '/inventory/transfers.php?status=in_transit'];
    unset($__inb, $__incoming);
}
$__branches = accessible_branches();
$__multi = count(branches_all()) > 1;
$notifCount = count($notifications);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="<?= e(app_url('/assets/vendor/lucide.min.js')) ?>" defer></script>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/style.css')) ?>?v=3">
<script>window.APP = { url: <?= json_encode(app_url('')) ?>, csrf: <?= json_encode(csrf_token()) ?>, currency: <?= json_encode(setting('currency_symbol', 'Rs.')) ?> };</script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<div class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="main-col">
<header class="topbar no-print">
  <button class="icon-btn menu-toggle" type="button" data-nav-toggle aria-label="Open menu"><?= icon('menu', 18) ?></button>
  <div class="topbar-search" id="globalSearch">
    <label class="search-box">
      <?= icon('search', 16) ?>
      <input type="search" id="globalSearchInput" placeholder="Search products, barcodes, invoices, customers, suppliers…" autocomplete="off" aria-label="Global search">
      <span class="kbd hide-sm">/</span>
    </label>
    <div class="search-results" id="globalSearchResults" hidden></div>
  </div>
  <div class="topbar-actions">
    <?php if ($__multi): ?>
    <div class="dropdown" data-dropdown>
      <button class="btn btn-secondary btn-sm branch-switch" type="button" data-dropdown-toggle aria-label="Branch" title="Working branch"<?= count($__branches) < 2 ? ' style="cursor:default"' : '' ?>>
        <?= icon('building-2', 15) ?><span class="bs-name"><?= e(branch_scope_label()) ?></span><?= count($__branches) > 1 ? icon('chevron-down', 13) : '' ?>
      </button>
      <?php if (count($__branches) > 1): ?>
      <div class="dropdown-panel" hidden>
        <div class="dropdown-head" style="display:block;">Switch branch</div>
        <form method="post" action="<?= e(app_url('/branch_switch.php')) ?>">
          <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($_SERVER['REQUEST_URI'] ?? '/') ?>">
          <?php foreach ($__branches as $__b): ?>
            <button class="dropdown-item" type="submit" name="branch_id" value="<?= (int) $__b['id'] ?>"><?= icon(current_branch_id() === (int) $__b['id'] ? 'check' : 'store', 15) ?> <?= e($__b['name']) ?><?= (int) $__b['is_main'] ? ' <span class="text-muted text-sm">· main</span>' : '' ?></button>
          <?php endforeach; ?>
          <?php if (user_has_all_branches()): ?><div class="dropdown-sep"></div>
            <button class="dropdown-item" type="submit" name="branch_id" value="0"><?= icon(current_branch_id() === null ? 'check' : 'layers', 15) ?> All branches <span class="text-muted text-sm">· combined</span></button><?php endif; ?>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (can('pos.access') && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/pos/index.php')): ?>
      <a class="btn btn-primary btn-sm hide-sm" href="<?= e(app_url('/pos/index.php')) ?>"><?= icon('scan-barcode', 15) ?> New Sale</a>
    <?php endif; ?>
    <div class="dropdown" data-dropdown>
      <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Notifications" title="Notifications">
        <?= icon('bell', 17) ?><?php if ($notifCount): ?><span class="dot-count"><?= $notifCount ?></span><?php endif; ?>
      </button>
      <div class="dropdown-panel wide" hidden>
        <div class="dropdown-head"><span>Alerts</span><span class="text-muted text-sm"><?= $notifCount ?> active</span></div>
        <?php if (!$notifications): ?>
          <div class="search-empty"><?= icon('check-circle-2', 20) ?><div class="mt-2">All clear — nothing needs your attention.</div></div>
        <?php endif; ?>
        <?php foreach ($notifications as $__n): ?>
          <a class="notif-item" href="<?= e(app_url($__n[4])) ?>">
            <span class="icon-tile sm <?= e($__n[1]) ?>"><?= icon($__n[0], 15) ?></span>
            <div><div class="nt"><?= e($__n[2]) ?></div><div class="ns"><?= e($__n[3]) ?></div></div>
          </a>
        <?php endforeach; unset($__n); ?>
      </div>
    </div>
    <div class="dropdown" data-dropdown>
      <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Account" style="width:auto;padding:2px 10px 2px 2px;gap:8px;">
        <span class="avatar" style="width:30px;height:30px;font-size:12px;"><?= e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))) ?></span>
        <span class="hide-sm" style="font-size:13px;font-weight:600;color:var(--dark);"><?= e(explode(' ', $user['full_name'] ?? '')[0]) ?></span>
        <?= icon('chevron-down', 14) ?>
      </button>
      <div class="dropdown-panel" hidden>
        <div class="dropdown-head" style="display:block;">
          <div><?= e($user['full_name'] ?? '') ?></div>
          <div class="text-muted text-sm" style="font-weight:500;"><?= e(role_label($user['role'] ?? '')) ?> · @<?= e($user['username'] ?? '') ?></div>
        </div>
        <?php if (can('settings.manage')): ?><a class="dropdown-item" href="<?= e(app_url('/settings.php')) ?>"><?= icon('settings', 15) ?> Settings</a><?php endif; ?>
        <?php if (can('users.manage')): ?><a class="dropdown-item" href="<?= e(app_url('/users.php')) ?>"><?= icon('users', 15) ?> Staff & roles</a><?php endif; ?>
        <div class="dropdown-sep"></div>
        <a class="dropdown-item" href="<?= e(app_url('/logout.php')) ?>"><?= icon('log-out', 15) ?> Sign out</a>
      </div>
    </div>
  </div>
</header>
<main class="main-content">
<?php $__flashes = get_flashes(); if ($__flashes): ?>
<div class="flash-stack" role="status">
<?php foreach ($__flashes as $__f): ?>
  <div class="alert alert-<?= e($__f['type']) ?>" data-autodismiss><?= icon($__f['type'] === 'success' ? 'check-circle-2' : ($__f['type'] === 'error' ? 'alert-circle' : 'info'), 18) ?><span><?= e($__f['message']) ?></span><button class="alert-close" type="button" aria-label="Dismiss">&times;</button></div>
<?php endforeach; unset($__f, $__flashes); ?>
</div>
<?php endif; ?>
