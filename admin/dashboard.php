<?php
declare(strict_types=1);

/**
 * Раньше дублировал рабочий стол. Теперь — перенаправление на рабочий стол (index.php).
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
