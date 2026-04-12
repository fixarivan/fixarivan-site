<?php
declare(strict_types=1);

/**
 * ADMIN access: PHP session only (admin/login.php).
 * Do not use localStorage or client-side flags as authorization.
 *
 * Returns 401 JSON if not authenticated.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_path' => '/',
        'cookie_samesite' => 'Lax',
        'cookie_httponly' => true,
    ]);
}

require_once __DIR__ . '/api_response.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    api_json_send(false, null, 'Требуется вход администратора (PHP session).');
    exit;
}
