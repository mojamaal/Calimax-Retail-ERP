<?php
/**
 * Single entry point every page includes first.
 * Order matters: config -> db -> security/session -> auth -> functions -> finance.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/branches.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/navs.php';
