<?php
declare(strict_types=1);
/**
 * Проверка админ-сессии для статических страниц (склад, трекинг и т.д.).
 * GET → JSON { success: true } или 401.
 */

session_start([
    'cookie_path' => '/',
    'cookie_samesite' => 'Lax',
    'cookie_httponly' => true,
]);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'authenticated' => true], JSON_UNESCAPED_UNICODE);
