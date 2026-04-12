<?php
/**
 * Minimal health check for monitoring (no secrets).
 * Returns unified API shape: success, message, data.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/data_policy.php';
require_once __DIR__ . '/lib/api_response.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$storage = $root . DIRECTORY_SEPARATOR . 'storage';
$dbFile = $storage . DIRECTORY_SEPARATOR . 'fixarivan.sqlite';

$data = [
    'php_version' => PHP_VERSION,
    'time' => date('c'),
    'extensions' => [
        'pdo_sqlite' => extension_loaded('pdo_sqlite'),
        'mbstring' => extension_loaded('mbstring'),
        'dom' => extension_loaded('dom') || class_exists('DOMDocument'),
        'gd' => extension_loaded('gd'),
        'zip' => extension_loaded('zip'),
    ],
    'storage' => [
        'path' => 'storage/',
        'exists' => is_dir($storage),
        'writable' => is_dir($storage) && is_writable($storage),
    ],
    'sqlite_file' => [
        'path' => 'storage/fixarivan.sqlite',
        'reachable' => false,
        'error' => null,
    ],
    'data_source' => defined('FIXARIVAN_DATA_SOURCE') ? FIXARIVAN_DATA_SOURCE : 'unknown',
];

$ok = $data['extensions']['pdo_sqlite']
    && $data['storage']['writable'];

if ($data['extensions']['pdo_sqlite'] && $data['storage']['writable']) {
    try {
        require_once __DIR__ . '/sqlite.php';
        $pdo = getSqliteConnection();
        $pdo->query('SELECT 1');
        $data['sqlite_file']['reachable'] = true;
    } catch (Throwable $e) {
        $data['sqlite_file']['error'] = $e->getMessage();
        $ok = false;
    }
} else {
    $ok = false;
}

http_response_code($ok ? 200 : 503);
api_json_send($ok, $data, $ok ? null : 'Health check failed (see data.extensions / sqlite_file)', []);
