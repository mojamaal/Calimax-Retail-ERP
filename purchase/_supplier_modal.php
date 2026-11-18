<?php if (!function_exists('e')) { http_response_code(403); exit; } if (!can('suppliers.manage')) return; ?>
<div class="modal-overlay" id="supplierModal" hidden>
  <div class="modal lg">
    <div class="modal-header"><h3 id="supModalTitle">New supplier</h3><button class="modal-close" type="button" data-modal-close>&times;</button></div>
    <form method="post" action="<?= e(app_url('/purchase/suppliers.php')) ?>" id="supplierForm">
      <?= csrf_field() ?><input type="hidden" name="action" id="sf_action" value="create"><input type="hidden" name="id" id="sf_id">
      <div class="form-section-title">Business</div>
      <div class="form-row">
        <div class="form-group" style="flex:2;"><label>Business name</label><input class="form-control" type="text" name="name" id="sf_name" required maxlength="150"></div>
        <div class="form-group"><label>Business reg. no. <span class="label-opt">(BR)</span></label><input class="form-control" type="text" name="business_reg_no" id="sf_business_reg_no" maxlength="60"></div>
        <div class="form-group"><label>VAT / TIN</label><input class="form-control" type="text" name="tax_id" id="sf_tax_id" maxlength="60"></div>
      </div>
      <div class="form-section-title">Contact</div>
      <div class="form-row">
        <div class="form-group"><label>Contact person</label><input class="form-control" type="text" name="contact_person" id="sf_contact_person" maxlength="100"></div>
        <div class="form-group"><label>Phone</label><input class="form-control" type="tel" name="phone" id="sf_phone" maxlength="30"></div>
        <div class="form-group"><label>Email</label><input class="form-control" type="email" name="email" id="sf_email" maxlength="150"></div>
      </div>
      <div class="form-group"><label>Address</label><input class="form-control" type="text" name="address" id="sf_address" maxlength="255"></div>
      <div class="form-row">
        <div class="form-group"><label>Payment terms (days)</label><input class="form-control" type="number" min="0" name="payment_terms_days" id="sf_payment_terms_days" value="0"><div class="form-hint">0 = cash on delivery</div></div>
        <div class="form-group" style="flex:2;"><label>Notes</label><input class="form-control" type="text" name="notes" id="sf_notes" maxlength="255" placeholder="e.g. delivers Tuesdays; minimum order Rs. 10,000"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save supplier</button></div>
    </form>
  </div>
</div>
<script>
function openSupplier(s) {
  const f = document.getElementById('supplierForm');
  f.reset();
  document.getElementById('supModalTitle').textContent = s ? 'Edit supplier' : 'New supplier';
  document.getElementById('sf_action').value = s ? 'update' : 'create';
  document.getElementById('sf_id').value = s ? s.id : '';
  if (s) ['name', 'business_reg_no', 'tax_id', 'contact_person', 'phone', 'email', 'address', 'payment_terms_days', 'notes'].forEach((k) => { document.getElementById('sf_' + k).value = s[k] == null ? '' : s[k]; });
  document.getElementById('supplierModal').hidden = false;
}
</script>
