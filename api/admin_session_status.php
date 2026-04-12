<?php
declare(strict_types=1);

/**
 * Проверка админ-сессии для клиентских страниц (fetch + credentials).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_path' => '/',
        'cookie_samesite' => 'Lax',
        'cookie_httponly' => true,
    ]);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ok = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
$username = $ok ? trim((string)($_SESSION['admin_username'] ?? '')) : '';
echo json_encode([
    'ok' => $ok,
    'username' => $username !== '' ? $username : null,
], JSON_UNESCAPED_UNICODE);
