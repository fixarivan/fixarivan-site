<?php
declare(strict_types=1);

/**
 * CLI: перезаписать storage/admin_auth.json (логин + bcrypt).
 * Нужно, если вход не принимает пароль из config — файл admin_auth.json имеет приоритет.
 *
 * Запуск на сервере (из корня сайта):
 *   php tools/reset_admin_password.php
 *
 * Опционально переменные окружения:
 *   FV_ADMIN_USER  (по умолчанию spacefix)
 *   FV_ADMIN_PASS  (по умолчанию из config или задайте явно)
 */
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';

$user = getenv('FV_ADMIN_USER') ?: (defined('ADMIN_USERNAME') ? (string) ADMIN_USERNAME : 'spacefix');
$pass = getenv('FV_ADMIN_PASS');
if ($pass === false || $pass === '') {
    $pass = defined('ADMIN_PASSWORD') ? (string) ADMIN_PASSWORD : '';
}
if ($user === '' || $pass === '') {
    fwrite(STDERR, "Missing username/password (set ADMIN_* in config or FV_ADMIN_* env).\n");
    exit(1);
}

require_once $root . '/api/lib/admin_auth.php';

try {
    fixarivan_admin_save_credentials($user, $pass);
    fwrite(STDOUT, "OK: admin credentials saved for user: {$user}\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
