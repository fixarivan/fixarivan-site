<?php
/**
 * Статус хранилища: SQLite (основной источник данных).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/lib/data_policy.php';

function fixarivan_db_status_sqlite(): array {
    try {
        require_once __DIR__ . '/sqlite.php';
        $pdo = getSqliteConnection();
        $orders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $receipts = (int) $pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn();
        $reports = (int) $pdo->query('SELECT COUNT(*) FROM mobile_reports')->fetchColumn();
        $total = $orders + $receipts + $reports;

        return [
            'status' => 'connected',
            'message' => 'SQLite подключена (storage/fixarivan.sqlite)',
            'data_source' => FIXARIVAN_DATA_SOURCE,
            'orders_count' => $orders,
            'receipts_count' => $receipts,
            'reports_count' => $reports,
            'total_documents' => $total,
        ];
    } catch (Throwable $e) {
        error_log('db_status SQLite: ' . $e->getMessage());
        return [
            'status' => 'disconnected',
            'message' => 'SQLite недоступна: ' . $e->getMessage(),
            'data_source' => FIXARIVAN_DATA_SOURCE,
            'orders_count' => 0,
            'receipts_count' => 0,
            'reports_count' => 0,
            'total_documents' => 0,
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(fixarivan_db_status_sqlite(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
}
