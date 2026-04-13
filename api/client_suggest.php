<?php
declare(strict_types=1);

/**
 * Быстрые подсказки клиентов для форм заказа (обёртка над fixarivan_client_search_clients, mode=suggest).
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/client_search.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 12;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 20) {
    $limit = 20;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

try {
    $rows = fixarivan_client_search_clients($pdo, $q, ['mode' => 'suggest', 'limit' => $limit]);
} catch (Throwable $e) {
    api_json_send(false, null, 'Запрос: ' . $e->getMessage(), []);
    exit;
}

$out = [];
foreach ($rows as $r) {
    $out[] = [
        'client_id' => (string)($r['client_id'] ?? ''),
        'full_name' => (string)($r['full_name'] ?? ''),
        'phone' => (string)($r['phone'] ?? ''),
        'email' => (string)($r['email'] ?? ''),
    ];
}

api_json_send(true, ['clients' => $out], null, [], []);
