<?php
/** Sidebar navigation (grouped, permission-aware). Included by header.php.
 *  Rendered inside a closure so its loop variables never leak into the page. */
(function (array $navBadges) {
$user = current_user();
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?: '', '/');
$rel = substr($script, strlen($base));

/** @var array $navBadges set in header.php */
$sections = [
    'Overview' => [
        ['/index.php', 'Dashboard', 'layout-grid', 'dashboard.view', ['/index.php']],
    ],
    'Sales' => [
        ['/pos/index.php', 'Point of Sale', 'scan-barcode', 'pos.access', ['/pos/index.php']],
        ['/pos/history.php', 'Sales History', 'receipt', 'pos.access', ['/pos/history.php', '/pos/sale_view.php']],
        ['/pos/returns.php', 'Returns & Exchanges', 'undo-2', ['returns.create', 'returns.approve'], ['/pos/returns.php', '/pos/return_form.php', '/pos/return_view.php'], 'returns'],
        ['/customers/index.php', 'Customers', 'contact', 'customers.view', ['/customers/']],
    ],
    'Inventory' => [
        ['/inventory/products.php', 'Products', 'package', 'products.view', ['/inventory/products.php', '/inventory/product_form.php', '/inventory/product_view.php', '/inventory/categories.php', '/inventory/attributes.php', '/inventory/barcode.php']],
        ['/inventory/adjustments.php', 'Stock Control', 'clipboard-check', ['inventory.adjust', 'inventory.approve', 'inventory.count', 'transfers.view'], ['/inventory/transfers.php', '/inventory/transfer_form.php', '/inventory/transfer_view.php', '/inventory/adjustments.php', '/inventory/adjustment_form.php', '/inventory/adjustment_view.php', '/inventory/counts.php', '/inventory/count_view.php', '/inventory/movements.php'], 'stock'],
    ],
    'Purchasing' => [
        ['/purchase/orders.php', 'Purchases', 'truck', 'purchase.manage', ['/purchase/orders.php', '/purchase/order_form.php', '/purchase/order_view.php', '/purchase/grn_form.php', '/purchase/grn_view.php', '/purchase/returns.php', '/purchase/return_form.php', '/purchase/return_view.php', '/purchase/receipts.php']],
        ['/purchase/suppliers.php', 'Suppliers', 'building-2', 'suppliers.view', ['/purchase/suppliers.php', '/purchase/supplier_view.php']],
    ],
    'Finance' => [
        ['/finance/expenses.php', 'Finance', 'wallet', 'finance.view', ['/finance/']],
        ['/reports/index.php', 'Reports', 'bar-chart-3', 'reports.view', ['/reports/']],
    ],
    'Administration' => [
        ['/branches.php', 'Branches', 'store', 'branches.manage', ['/branches.php']],
        ['/users.php', 'Staff & Roles', 'users', 'users.manage', ['/users.php']],
        ['/audit.php', 'Audit Log', 'shield-check', 'audit.view', ['/audit.php']],
        ['/settings.php', 'Settings', 'settings', 'settings.manage', ['/settings.php']],
    ],
];
?>
<aside class="sidebar" id="sidebar">
  <div class="brand-block">
    <div class="brutal-logo"><span class="mark">CX</span><span class="word"><?= e(APP_NAME) ?></span></div>
    <span class="brand-sub"><?= count(branches_all()) > 1 ? e(branch_scope_label()) : 'Retail ERP' ?></span>
  </div>

  <?php foreach ($sections as $heading => $items):
      $visible = array_filter($items, fn($it) => can_any((array) $it[3]));
      if (!$visible) continue; ?>
    <div class="nav-section">
      <div class="nav-heading"><?= e($heading) ?></div>
      <nav class="nav-group">
        <?php foreach ($visible as $it):
            $active = false;
            foreach ($it[4] as $match) { if (str_starts_with($rel, $match)) { $active = true; break; } }
            $badge = isset($it[5]) ? (int) ($navBadges[$it[5]] ?? 0) : 0; ?>
          <a class="nav-item <?= $active ? 'active' : '' ?>" href="<?= e(app_url($it[0])) ?>">
            <?= icon($it[2], 18) ?><span class="nav-label"><?= e($it[1]) ?></span>
            <?php if ($badge > 0): ?><span class="nav-count" title="Awaiting approval"><?= $badge ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  <?php endforeach; ?>

  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="avatar"><?= e(strtoupper(substr($user['full_name'] ?? '?', 0, 1))) ?></div>
      <div class="who">
        <div class="name"><?= e($user['full_name'] ?? '') ?></div>
        <div class="role"><?= e(role_label($user['role'] ?? '')) ?></div>
      </div>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop" data-nav-close></div>
<?php })($navBadges ?? []); ?>
