<?php
/** Finance module sub-navigation. Include after page-head. $current should be set by the calling page. */
$financeLinks = [
    'expenses.php' => 'Expenses',
    'expense_categories.php' => 'Categories',
    'payables.php' => 'Payables',
    'receivables.php' => 'Receivables',
    'reports.php' => 'Reports',
];
?>
<div class="flex gap-2 mb-4" style="flex-wrap:wrap;">
  <?php foreach ($financeLinks as $file => $label): ?>
    <a class="btn btn-sm <?= ($current ?? '') === $file ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(app_url('/finance/' . $file)) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
