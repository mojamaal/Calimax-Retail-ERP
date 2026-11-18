<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('settings.manage');

$pdo = db();
$fields = [
    'store_name' => 'text', 'store_address' => 'text', 'store_phone' => 'text', 'store_email' => 'text',
    'currency_code' => 'text', 'currency_symbol' => 'text', 'tax_registration_no' => 'text', 'default_tax_rate' => 'num', 'prices_include_tax' => 'bool',
    'cashier_max_discount_pct' => 'num', 'void_requires_reason' => 'bool', 'returns_require_approval' => 'bool', 'return_window_days' => 'int', 'return_policy' => 'text',
    'adjustments_require_approval' => 'bool', 'receipt_footer' => 'text', 'invoice_prefix' => 'code', 'po_prefix' => 'code', 'grn_prefix' => 'code', 'transfer_prefix' => 'code',
];
$current = [];
foreach ($pdo->query('SELECT setting_key, setting_value FROM settings') as $row) $current[$row['setting_key']] = $row['setting_value'];

if (is_post()) {
    verify_csrf();
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2');
    $changes = [];
    foreach ($fields as $field => $type) {
        $value = match ($type) {
            'bool' => isset($_POST[$field]) ? '1' : '0',
            'num' => (string) max(0, min(100, input_float($_POST, $field))),
            'int' => (string) max(0, input_int($_POST, $field)),
            'code' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', input($_POST, $field))) ?: ($current[$field] ?? 'DOC'),
            default => input($_POST, $field),
        };
        if (($current[$field] ?? null) !== $value) $changes[] = $field . ': "' . ($current[$field] ?? '') . '" → "' . $value . '"';
        $stmt->execute([':k' => $field, ':v' => $value, ':v2' => $value]);
    }
    if ($changes) log_activity('settings_update', 'Changed settings — ' . implode('; ', $changes));
    flash('success', $changes ? 'Settings saved.' : 'No changes to save.');
    redirect('/settings.php');
}
$v = fn($k, $d = '') => e($current[$k] ?? $d);
$chk = fn($k, $d = '0') => ($current[$k] ?? $d) === '1' ? 'checked' : '';

$pageTitle = 'Settings';
require __DIR__ . '/includes/header.php';
page_header('Settings', 'Business details, tax and the controls that protect the shop.');
?>
<form method="post">
  <?= csrf_field() ?>
  <div class="grid grid-2">
    <div class="card">
      <h3 class="mb-4">Business details</h3>
      <div class="form-group"><label>Shop name</label><input class="form-control" type="text" name="store_name" value="<?= $v('store_name') ?>" maxlength="150"></div>
      <div class="form-group"><label>Address</label><input class="form-control" type="text" name="store_address" value="<?= $v('store_address') ?>" maxlength="255"></div>
      <div class="form-row">
        <div class="form-group"><label>Phone</label><input class="form-control" type="text" name="store_phone" value="<?= $v('store_phone') ?>" maxlength="30"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="store_email" value="<?= $v('store_email') ?>" maxlength="150"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Currency code</label><input class="form-control" type="text" name="currency_code" value="<?= $v('currency_code', 'LKR') ?>" maxlength="10"></div>
        <div class="form-group"><label>Currency symbol</label><input class="form-control" type="text" name="currency_symbol" value="<?= $v('currency_symbol', 'Rs.') ?>" maxlength="10"></div>
      </div>
      <h3 class="mb-4 mt-4">Receipts &amp; documents</h3>
      <div class="form-group"><label>Receipt footer</label><input class="form-control" type="text" name="receipt_footer" value="<?= $v('receipt_footer') ?>" maxlength="255"></div>
      <div class="form-row">
        <div class="form-group"><label>Invoice prefix</label><input class="form-control mono" type="text" name="invoice_prefix" value="<?= $v('invoice_prefix', 'INV') ?>" maxlength="8"></div>
        <div class="form-group"><label>PO prefix</label><input class="form-control mono" type="text" name="po_prefix" value="<?= $v('po_prefix', 'PO') ?>" maxlength="8"></div>
        <div class="form-group"><label>GRN prefix</label><input class="form-control mono" type="text" name="grn_prefix" value="<?= $v('grn_prefix', 'GRN') ?>" maxlength="8"></div>
        <div class="form-group"><label>Transfer prefix</label><input class="form-control mono" type="text" name="transfer_prefix" value="<?= $v('transfer_prefix', 'TRF') ?>" maxlength="8"></div>
      </div>
      <?php if (count(branches_all()) > 1): ?><div class="callout text-sm"><?= icon('building-2', 14) ?> Address, phone and receipt footer can be set per branch under <a class="link" href="<?= e(app_url('/branches.php')) ?>">Branches</a>; the values here are used when a branch leaves them blank. Other branches' documents are numbered with their branch code (e.g. COL-INV-000001).</div><?php endif; ?>
    </div>
    <div class="grid">
      <div class="card">
        <h3 class="mb-4">Tax</h3>
        <div class="form-row">
          <div class="form-group"><label>VAT / TIN registration no.</label><input class="form-control" type="text" name="tax_registration_no" value="<?= $v('tax_registration_no') ?>" maxlength="40" placeholder="Printed on receipts"></div>
          <div class="form-group"><label>Default tax rate for new products (%)</label><input class="form-control" type="number" step="0.01" min="0" max="100" name="default_tax_rate" value="<?= $v('default_tax_rate', '0') ?>"></div>
        </div>
        <label class="check"><input type="checkbox" name="prices_include_tax" <?= $chk('prices_include_tax', '1') ?>> Selling prices already include tax (shelf price = price paid)</label>
        <div class="form-hint">Untick if tax should be added on top of prices at the till.</div>
      </div>
      <div class="card">
        <h3 class="mb-4">Controls &amp; approvals</h3>
        <div class="form-row">
          <div class="form-group"><label>Max discount a cashier can give (%)</label><input class="form-control" type="number" step="0.5" min="0" max="100" name="cashier_max_discount_pct" value="<?= $v('cashier_max_discount_pct', '10') ?>"><div class="form-hint">Bigger discounts need a manager’s password at the till.</div></div>
          <div class="form-group"><label>Return window (days)</label><input class="form-control" type="number" min="0" name="return_window_days" value="<?= $v('return_window_days', '30') ?>"><div class="form-hint">Older sales always need approval. 0 = no limit.</div></div>
        </div>
        <label class="check mb-2"><input type="checkbox" name="returns_require_approval" <?= $chk('returns_require_approval', '1') ?>> Customer returns by cashiers need manager approval</label><br>
        <label class="check mb-2"><input type="checkbox" name="adjustments_require_approval" <?= $chk('adjustments_require_approval', '1') ?>> Stock adjustments by store staff need manager approval</label><br>
        <label class="check"><input type="checkbox" name="void_requires_reason" checked disabled> Cancelling a sale always requires a reason and manager authorization</label>
        <input type="hidden" name="void_requires_reason" value="1">
        <div class="form-group mt-4 mb-0"><label>Return policy (printed on receipts)</label><input class="form-control" type="text" name="return_policy" value="<?= $v('return_policy') ?>" maxlength="255"></div>
      </div>
    </div>
  </div>
  <div class="form-actions"><button type="submit" class="btn btn-primary btn-lg"><?= icon('save', 16) ?> Save settings</button></div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
