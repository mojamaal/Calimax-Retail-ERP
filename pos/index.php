<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('pos.access');
require_single_branch();

$pdo = db();
$categories = $pdo->query('SELECT c.id, c.name FROM categories c WHERE c.is_active = 1 AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1) ORDER BY c.name')->fetchAll();
$pageTitle = 'Point of Sale';
$bodyClass = 'pos-page';
$canCheckout = can('pos.checkout');
require __DIR__ . '/../includes/header.php';
?>
<div class="pos-shell">
  <!-- LEFT: scan + browse -->
  <section class="pos-products card" aria-label="Products">
    <div class="pos-scan">
      <?= icon('scan-barcode', 18) ?>
      <input class="form-control" type="text" id="scanInput" placeholder="Scan barcode or type product name / SKU, then press Enter" autocomplete="off" autofocus>
      <span class="kbd">F2</span>
    </div>
    <div class="pos-cats" id="catChips">
      <button type="button" class="chip active" data-cat="">All products</button>
      <?php foreach ($categories as $c): ?><button type="button" class="chip" data-cat="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></button><?php endforeach; ?>
    </div>
    <div class="pos-grid" id="productGrid" aria-live="polite"></div>
  </section>

  <!-- RIGHT: current sale -->
  <section class="pos-cart card" aria-label="Current sale">
    <div class="order-card-header">
      <div>
        <h3>Current Sale</h3>
        <div class="text-muted text-sm" id="saleTag">New sale</div>
      </div>
      <div class="flex gap-1">
        <button class="btn btn-secondary btn-sm" type="button" id="heldSalesBtn" title="Parked sales"><?= icon('clock', 14) ?> Held <span class="badge badge-neutral" id="heldCount">0</span></button>
        <?php if ($canCheckout): ?><button class="icon-btn" type="button" id="exchangeBtn" title="Redeem exchange credit"><?= icon('repeat', 15) ?></button><?php endif; ?>
        <button class="icon-btn" type="button" id="holdBtn" title="Hold / park this sale (F8)"><?= icon('pause', 15) ?></button>
        <button class="icon-btn" type="button" id="clearCartBtn" title="Clear sale"><?= icon('trash-2', 15) ?></button>
      </div>
    </div>

    <div style="position:relative;">
      <div id="customerPicker">
        <div class="pos-scan">
          <?= icon('user-round', 16) ?>
          <input class="form-control" style="padding-top:9px;padding-bottom:9px;font-size:13.5px;" type="text" id="customerSearch" placeholder="Walk-in customer · search name or phone to attach" autocomplete="off">
        </div>
      </div>
      <div id="selectedCustomer" class="pay-line" hidden style="margin:0;background:var(--surface-soft);"></div>
      <div id="customerResults" class="search-results" hidden></div>
    </div>

    <div class="pos-view">
      <h4 class="list-item-heading" id="listItemHeading">Items (0)</h4>
      <div class="pos-cart-items" id="cartItems"></div>

      <div class="payment-summary-box">
        <div class="summary-row"><span>Subtotal</span><span id="sumSubtotal">0.00</span></div>
        <div class="summary-row"><span><button type="button" class="btn-ghost btn-sm" id="billDiscountBtn" style="padding:2px 6px;margin-left:-6px;"><?= icon('tag', 13) ?> Discount</button></span><span id="sumDiscount" class="text-success">0.00</span></div>
        <div class="summary-row"><span id="taxLabel">Tax</span><span id="sumTax">0.00</span></div>
        <div class="summary-row" id="exchangeRow" hidden><span><?= icon('repeat', 13) ?> Exchange credit <span id="exchangeRef" class="text-muted"></span> <button type="button" class="line-btn" id="removeExchange" style="border:0;background:none;cursor:pointer;color:var(--error);">&times;</button></span><span id="sumExchange" class="text-success">0.00</span></div>
        <div class="summary-row total"><span>Total</span><span id="sumTotal">0.00</span></div>
      </div>

      <?php if ($canCheckout): ?>
        <button class="btn btn-primary btn-block checkout-btn" type="button" id="checkoutBtn"><?= icon('credit-card', 18) ?> Take Payment <span class="kbd" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.5);">F9</span></button>
      <?php else: ?>
        <button class="btn btn-dark btn-block checkout-btn" type="button" id="sendToCashierBtn"><?= icon('send', 18) ?> Send to Cashier</button>
        <p class="text-muted text-sm mt-2" style="text-align:center;">Your role prepares carts — a cashier takes the payment from <strong>Held</strong>.</p>
      <?php endif; ?>
      <div class="pos-hotkeys"><span><span class="kbd">F2</span> Scan</span><span><span class="kbd">F4</span> Customer</span><span><span class="kbd">F8</span> Hold</span><?php if ($canCheckout): ?><span><span class="kbd">F9</span> Pay</span><?php endif; ?></div>
    </div>
  </section>
</div>

<!-- Payment modal -->
<div class="modal-overlay" id="paymentModal" hidden data-static>
  <div class="modal lg">
    <div class="modal-header"><h3>Take Payment</h3><button class="modal-close" type="button" data-modal-close aria-label="Close">&times;</button></div>
    <div class="amount-due-banner" id="dueBanner">
      <div><div class="label" id="dueLabel">Amount due</div><div class="amount" id="remainingAmount">0.00</div></div>
      <div style="text-align:right;"><div class="label">Sale total</div><div style="font-weight:700;font-size:16px;" id="payTotal">0.00</div></div>
    </div>
    <div class="pay-two-col">
      <div>
        <div class="label">Payment method</div>
        <div class="pay-methods" style="margin-top:4px;">
          <?php foreach (['cash', 'card', 'bank_transfer', 'mobile_wallet', 'store_credit'] as $m): $pm = payment_methods()[$m]; ?>
            <button type="button" class="pay-chip" data-method="<?= e($m) ?>"><?= icon($pm['icon'], 18) ?><?= e($pm['label']) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="form-row">
          <div class="form-group mb-2"><label for="paymentAmountInput">Amount to apply</label><input class="form-control numpad-target" id="paymentAmountInput" type="number" min="0" step="0.01" inputmode="decimal" style="text-align:right;font-weight:700;"></div>
          <div class="form-group mb-2" id="tenderedGroup"><label for="tenderedInput">Cash received</label><input class="form-control numpad-target" id="tenderedInput" type="number" min="0" step="0.01" inputmode="decimal" style="text-align:right;font-weight:700;"></div>
        </div>
        <div class="quick-cash" id="quickCash"></div>
        <div class="form-group mt-3 mb-2" id="referenceGroup" hidden><label for="referenceInput">Reference / approval code <span class="label-opt">(optional)</span></label><input class="form-control" id="referenceInput" type="text" maxlength="60" placeholder="e.g. card slip no. or transfer ref"></div>
        <div class="alert alert-info mt-3 mb-2" id="creditInfo" hidden></div>
        <div class="text-sm fw-700 mt-2" id="changeDisplay"></div>
        <button class="btn btn-secondary btn-block mt-3" type="button" id="addPaymentBtn"><?= icon('plus', 15) ?> Add payment (split)</button>
        <div id="paymentLines" class="mt-3"></div>
      </div>
      <div>
        <div class="numpad-panel">
          <div class="numpad-display" id="numpadDisplay">0</div>
          <div class="numpad">
            <?php foreach (['7','8','9','4','5','6','1','2','3','00','0','.'] as $k): ?><button type="button" data-key="<?= $k ?>"><?= $k ?></button><?php endforeach; ?>
            <button type="button" class="op" data-key="back">⌫</button>
            <button type="button" class="clear" data-key="clear" style="grid-column: span 2;">Clear</button>
          </div>
        </div>
        <div id="overrideBox" class="callout mt-3" hidden>
          <div class="fw-700 mb-2"><?= icon('shield-check', 15) ?> Manager approval required</div>
          <div class="text-sm mb-2" id="overrideMsg"></div>
          <div class="form-row">
            <input class="form-control" id="ovUser" type="text" placeholder="Manager username" autocomplete="off">
            <input class="form-control" id="ovPass" type="password" placeholder="Password" autocomplete="new-password">
          </div>
        </div>
        <button class="btn btn-primary btn-block btn-lg mt-3" type="button" id="completeBtn"><?= icon('check', 18) ?> Complete Sale</button>
      </div>
    </div>
  </div>
</div>

<!-- Discount modal (line or whole bill) -->
<div class="modal-overlay" id="discountModal" hidden>
  <div class="modal sm">
    <div class="modal-header"><h3 id="discountTitle">Discount</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <div class="text-muted text-sm mb-3" id="discountBase"></div>
    <div class="segmented mb-3">
      <label><input type="radio" name="discType" value="amount" checked><span><?= e(setting('currency_symbol', 'Rs.')) ?> Amount</span></label>
      <label><input type="radio" name="discType" value="percent"><span>% Percent</span></label>
    </div>
    <div class="form-group"><input class="form-control" id="discountValue" type="number" min="0" step="0.01" inputmode="decimal" style="font-size:20px;font-weight:700;text-align:right;"></div>
    <div class="form-hint" id="discountHint">Discounts above <?= e(setting('cashier_max_discount_pct', '10')) ?>% of the bill need manager approval at payment.</div>
    <div class="modal-footer">
      <button class="btn btn-secondary" type="button" id="discountRemove">Remove discount</button>
      <button class="btn btn-primary" type="button" id="discountApply">Apply</button>
    </div>
  </div>
</div>

<!-- Held sales -->
<div class="modal-overlay" id="heldModal" hidden>
  <div class="modal">
    <div class="modal-header"><h3>Held Sales</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <div id="heldList"></div>
  </div>
</div>

<!-- Exchange credit -->
<div class="modal-overlay" id="exchangeModal" hidden>
  <div class="modal sm">
    <div class="modal-header"><h3>Redeem Exchange Credit</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <p class="text-muted text-sm">Enter the return number printed on the exchange slip (e.g. RET-000012). The returned items' value is applied to this sale.</p>
    <div class="form-group"><input class="form-control" id="exchangeRefInput" type="text" placeholder="RET-000000" autocomplete="off"></div>
    <div class="modal-footer"><button class="btn btn-primary" type="button" id="exchangeApply">Apply credit</button></div>
  </div>
</div>

<!-- Sale complete -->
<div class="modal-overlay" id="doneModal" hidden data-static>
  <div class="modal sm" style="text-align:center;">
    <div class="icon-tile green" style="width:56px;height:56px;margin:0 auto 12px;"><?= icon('check', 28) ?></div>
    <h3 style="font-size:19px;">Sale completed</h3>
    <p class="text-muted mt-1" id="doneInvoice"></p>
    <div id="doneChange" class="mt-4"></div>
    <div class="modal-footer" style="justify-content:center;">
      <button class="btn btn-secondary" type="button" id="donePrint"><?= icon('printer', 16) ?> Print receipt</button>
      <button class="btn btn-primary" type="button" id="doneNew"><?= icon('plus', 16) ?> New sale <span class="kbd" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.5);">Enter</span></button>
    </div>
  </div>
</div>

<script>
window.POS = {
  canCheckout: <?= json_encode($canCheckout) ?>,
  canOverride: <?= json_encode(can('pos.discount_override')) ?>,
  maxDiscountPct: <?= json_encode((float) setting('cashier_max_discount_pct', '10')) ?>,
  pricesIncludeTax: <?= json_encode(setting('prices_include_tax', '1') === '1') ?>,
  canCreateCustomer: <?= json_encode(can('customers.create')) ?>,
  exchangeRef: <?= json_encode(input($_GET, 'exchange')) ?>,
  resumeId: <?= json_encode(input_int($_GET, 'resume')) ?>,
};
</script>
<?php $pageScripts = [app_url('/assets/js/pos.js') . '?v=3']; require __DIR__ . '/../includes/footer.php'; ?>
