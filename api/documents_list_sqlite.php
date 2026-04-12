<?php
/**
 * Список документов из SQLite: акты, квитанции, отчёты.
 * GET: type=all|order|receipt|report, limit=200
 */

declare(strict_types=1);

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
require_once __DIR__ . '/lib/documents_query.php';
require_once __DIR__ . '/lib/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', ['method']);
    exit;
}

$type = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'all';
if (!in_array($type, ['all', 'order', 'receipt', 'report'], true)) {
    $type = 'all';
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;

try {
    $pdo = getSqliteConnection();
    $documents = documents_list_from_sqlite($pdo, $type, $limit);
    api_json_send(true, ['documents' => $documents, 'count' => count($documents)], null, [], [
        'documents' => $documents,
        'count' => count($documents),
    ]);
} catch (Throwable $e) {
    api_json_send(false, null, $e->getMessage(), [$e->getMessage()]);
}
