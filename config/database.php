<?php
/**
 * Совместимость: раньше здесь были дубли констант и функций.
 * Единая точка — корневой config.php (загружает config.local.php).
 *
 * DEPRECATED — not used in SQLite flow as a separate DB layer; kept for includes.
 */
require_once dirname(__DIR__) . '/config.php';
