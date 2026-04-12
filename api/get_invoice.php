<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/site_url.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', ['Only GET is allowed']);
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    api_json_send(false, null, 'Не указан id счёта', ['id is required']);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE document_id = :id OR invoice_id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        api_json_send(false, null, 'Счёт не найден', ['Invoice not found']);
        exit;
    }
    $items = json_decode((string)($row['items_json'] ?? '[]'), true);
    if (!is_array($items)) $items = [];
    $row['items'] = $items;
    $token = trim((string)($row['client_token'] ?? ''));
    $row['viewer_url'] = $token !== '' ? fixarivan_absolute_url('invoice_view.php?token=' . rawurlencode($token)) : null;
    api_json_send(true, $row, null);
} catch (Throwable $e) {
    api_json_send(false, null, 'Ошибка чтения счёта', [$e->getMessage()]);
}
