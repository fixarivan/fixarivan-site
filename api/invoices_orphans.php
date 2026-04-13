<?php
declare(strict_types=1);

/**
 * Счета без надёжной привязки к заказу: пустой order_id или такого заказа нет в orders.
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', '');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', ['Only GET is allowed']);
    exit;
}

$sql = <<<'SQL'
SELECT
    i.document_id,
    i.invoice_id,
    i.order_id,
    i.client_id,
    i.status,
    i.total_amount,
    COALESCE(NULLIF(TRIM(i.date_updated), ''), NULLIF(TRIM(i.date_created), ''), '') AS updated_at,
    IFNULL(i.client_token, '') AS client_token,
    IFNULL(i.client_name, '') AS client_name,
    IFNULL(i.client_phone, '') AS client_phone,
    IFNULL(i.client_email, '') AS client_email
FROM invoices i
WHERE
    i.order_id IS NULL
    OR TRIM(COALESCE(i.order_id, '')) = ''
    OR NOT EXISTS (SELECT 1 FROM orders o WHERE o.order_id = i.order_id)
ORDER BY COALESCE(i.date_updated, i.date_created, '') DESC
LIMIT 200
SQL;

try {
    $pdo = getSqliteConnection();
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) {
        $rows = [];
    }
    api_json_send(true, ['rows' => $rows, 'count' => count($rows)], null, [], [
        'rows' => $rows,
        'count' => count($rows),
    ]);
} catch (Throwable $e) {
    api_json_send(false, null, 'Ошибка выборки счетов', [$e->getMessage()]);
}
