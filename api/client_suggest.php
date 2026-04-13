<?php
declare(strict_types=1);

/**
 * Быстрые подсказки клиентов для форм заказа: имя, телефон (частичное совпадение по нормализованным цифрам), email, client_id.
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
require_once __DIR__ . '/lib/order_center.php';

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

$len = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
if ($len < 2) {
    api_json_send(true, ['clients' => []], null, [], []);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite: ' . $e->getMessage(), []);
    exit;
}

// Убираем % и _ из фрагмента, чтобы LIKE оставался предсказуемым без ESCAPE.
$qFrag = str_replace(['%', '_'], '', $q);

$phoneSearch = fixarivan_normalize_phone($q);
if ($phoneSearch === '') {
    $phoneSearch = preg_replace('/\D+/', '', $q) ?? '';
}

$params = [];
$parts = [];

if ($qFrag !== '') {
    $params[':qlike'] = '%' . $qFrag . '%';
    $parts[] = 'c.full_name LIKE :qlike';
    $parts[] = 'IFNULL(c.client_id, \'\') LIKE :qlike';
    $parts[] = 'IFNULL(c.email, \'\') LIKE :qlike';
}

if (strlen($phoneSearch) >= 2) {
    $params[':phlike'] = '%' . $phoneSearch . '%';
    $parts[] = '(c.phone IS NOT NULL AND c.phone LIKE :phlike)';
}

if ($parts === []) {
    api_json_send(true, ['clients' => []], null, [], []);
    exit;
}

$sql = 'SELECT c.client_id, c.full_name, c.phone, c.email,
               COALESCE(NULLIF(TRIM(c.updated_at), \'\'), \'\') AS updated_at
        FROM clients c
        WHERE (' . implode(' OR ', $parts) . ')
        ORDER BY c.updated_at DESC
        LIMIT ' . (int)$limit;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
