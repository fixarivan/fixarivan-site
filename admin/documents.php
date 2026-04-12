<?php
declare(strict_types=1);

/**
 * Список документов перенесён на рабочий стол (index.php). Старые ссылки — редирект.
 */
session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

header('Location: ../index.php', true, 302);
exit;
