<?php
/** Switch the working branch (or "All branches" for multi-branch users). */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_post()) redirect('/index.php');
verify_csrf();

$id = input_int($_POST, 'branch_id', -1);
if ($id >= 0 && switch_branch($id)) {
    $label = $id === 0 ? 'All branches (combined view)' : (branch_row($id)['name'] ?? 'branch');
    flash('success', 'Now working in: ' . $label . '.');
} else {
    flash('error', 'You cannot switch to that branch.');
}

// Return to where the user was (same-site path only).
$back = input($_POST, 'back');
$path = parse_url($back, PHP_URL_PATH) ?: '';
$base = parse_url(APP_URL, PHP_URL_PATH) ?: '';
if ($path !== '' && str_starts_with($path, $base . '/') && !str_contains($back, '//') && !str_contains($path, '..')) {
    // Detail pages of the previous branch may not exist in the new one — go to the list instead of an id URL.
    if (preg_match('#_view\.php|_form\.php|receipt\.php|_print\.php#', $path)) { redirect('/index.php'); }
    header('Location: ' . $back);
    exit;
}
redirect('/index.php');
