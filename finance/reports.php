<?php
/** Moved: the P&L now lives in the Reports module. Kept so old bookmarks still work. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_permission('reports.view');
redirect('/reports/index.php' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
