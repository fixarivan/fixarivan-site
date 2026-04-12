<?php
/**
 * Последние документы (SQLite) — для главной и виджетов.
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

function formatRecentTitle(array $row): string {
    $type = $row['type'] ?? '';
    $id = $row['display_id'] ?? $row['document_id'] ?? '';
    $model = $row['device_model'] ?? '';
    if ($type === 'invoice') {
        return 'Счёт #' . $id . ($row['client_name'] ? ' — ' . $row['client_name'] : '');
    }
    if ($type === 'receipt') {
        return 'Квитанция #' . $id . ($row['client_name'] ? ' — ' . $row['client_name'] : '');
    }
    if ($type === 'report') {
        return 'Отчёт #' . $id;
    }
    return 'Акт #' . $id . ($model ? ' — ' . $model : '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    error_log('get_recent_documents SQLite: ' . $e->getMessage());
    // Нет pdo_sqlite или недоступен файл БД — не ломаем дашборд (fetchJson не должен видеть success:false).
    api_json_send(true, ['documents' => [], 'sqlite_available' => false], null, [], [
        'documents' => [],
    ]);
    exit;
}

try {
    $raw = documents_list_from_sqlite($pdo, 'all', 20);
    $documents = [];
    foreach ($raw as $row) {
        $documents[] = [
            'document_id' => $row['document_id'],
            'display_id' => $row['display_id'] ?? $row['document_id'],
            'order_id' => $row['order_id'] ?? null,
            'client_id' => $row['client_id'] ?? null,
            'title' => formatRecentTitle($row),
            'client_name' => $row['client_name'],
            'client_phone' => (string)($row['client_phone'] ?? ''),
            'client_email' => (string)($row['client_email'] ?? ''),
            'device_model' => (string)($row['device_model'] ?? ''),
            'status' => $row['status'],
            'status_label' => $row['status_label'] ?? '',
            'date_created' => $row['date_created'],
            'type' => $row['type'],
            'viewer_url' => $row['viewer_url'],
            'has_viewer_link' => !empty($row['has_viewer_link']),
            'payment_method' => (string)($row['payment_method'] ?? ''),
            'payment_status' => (string)($row['payment_status'] ?? ''),
            'payment_date' => (string)($row['payment_date'] ?? ''),
        ];
    }
    api_json_send(true, ['documents' => $documents, 'sqlite_available' => true], null, [], [
        'documents' => $documents,
    ]);
} catch (Throwable $e) {
    error_log('get_recent_documents: ' . $e->getMessage());
    api_json_send(true, ['documents' => [], 'sqlite_available' => false], null, [], [
        'documents' => [],
    ]);
}
