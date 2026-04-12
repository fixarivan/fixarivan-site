<?php
/**
 * Все документы из SQLite (замена MySQL).
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
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$typeFilter = isset($_GET['type']) ? strtolower(trim((string)$_GET['type'])) : 'all';
if (!in_array($typeFilter, ['all', 'order', 'receipt', 'report', 'invoice'], true)) {
    $typeFilter = 'all';
}

try {
    $pdo = getSqliteConnection();
    $list = documents_list_from_sqlite($pdo, $typeFilter, 50);
    $results = [];
    foreach ($list as $row) {
        $results[] = [
            'document_id' => $row['document_id'],
            'display_id' => $row['display_id'] ?? $row['document_id'],
            'order_id' => $row['order_id'] ?? null,
            'client_id' => $row['client_id'] ?? null,
            'client_name' => $row['client_name'],
            'client_phone' => (string)($row['client_phone'] ?? ''),
            'client_email' => (string)($row['client_email'] ?? ''),
            'device_model' => (string)($row['device_model'] ?? ''),
            'device_type' => (string)($row['device_type'] ?? ''),
            'problem_description' => (string)($row['problem_description'] ?? ''),
            'order_status' => $row['order_status'] ?? null,
            'public_status' => $row['public_status'] ?? null,
            'public_expected_date' => $row['public_expected_date'] ?? null,
            'public_comment' => $row['public_comment'] ?? null,
            'public_estimated_cost' => $row['public_estimated_cost'] ?? null,
            'internal_comment' => $row['internal_comment'] ?? null,
            'order_type' => (string)($row['order_type'] ?? ''),
            'language' => $row['language'] ?? 'ru',
            'parts_status' => $row['parts_status'] ?? null,
            'status' => $row['status'],
            'status_label' => $row['status_label'] ?? '',
            'date_created' => $row['date_created'],
            'type' => $row['type'],
            'viewer_url' => $row['viewer_url'],
            'portal_url' => $row['portal_url'] ?? null,
            'client_token' => $row['client_token'] ?? null,
            'has_viewer_link' => !empty($row['has_viewer_link']),
            'total_amount' => $row['total_amount'] ?? null,
            'payment_method' => (string)($row['payment_method'] ?? ''),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'payment_date' => (string)($row['payment_date'] ?? ''),
            'amount_paid' => $row['amount_paid'] ?? null,
            'payment_note' => (string)($row['payment_note'] ?? ''),
        ];
    }
    api_json_send(true, ['results' => $results, 'count' => count($results)], null, [], [
        'results' => $results,
        'count' => count($results),
    ]);
} catch (Throwable $e) {
    api_json_send(false, null, $e->getMessage(), [$e->getMessage()]);
}
