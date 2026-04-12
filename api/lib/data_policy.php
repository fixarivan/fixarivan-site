<?php
declare(strict_types=1);

/**
 * SQLite is the single source of truth (storage/fixarivan.sqlite; see sqlite.php).
 * JSON under storage/ is backup / fallback only — never treat JSON as authoritative when SQLite has a row.
 * Legacy MySQL paths are not extended.
 */
if (!defined('FIXARIVAN_DATA_SOURCE')) {
    define('FIXARIVAN_DATA_SOURCE', 'sqlite');
}
