<?php
declare(strict_types=1);

/**
 * Рабочий стол (бывший index.html): только при активной админ-сессии.
 */
session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin/login.php?next=' . rawurlencode('../index.php'));
    exit;
}

$app = __DIR__ . DIRECTORY_SEPARATOR . 'dashboard_app.html';
if (!is_readable($app)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'dashboard_app.html not found';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
readfile($app);
