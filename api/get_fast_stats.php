<?php
/**
 * Быстрая статистика из единого SQLite (fixarivan.sqlite).
 */

declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/inventory_sqlite_helpers.php';

function getFastStats(): array {
    try {
        $pdo = getSqliteConnection();
    } catch (Throwable $e) {
        error_log('get_fast_stats SQLite: ' . $e->getMessage());
        // Деградация: дашборд не должен сыпать ошибками, если нет pdo_sqlite.
        $stats = [
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total' => 0,
            'orders' => 0,
            'receipts' => 0,
            'reports' => 0,
            'inventory' => [
                'total' => 0,
                'in_stock' => 0,
                'low_stock' => 0,
                'value' => 0.0,
                'purchase_value' => 0.0,
                'sale_value' => 0.0,
                'profit_potential' => 0.0,
                'sold_qty' => 0.0,
                'sold_revenue' => 0.0,
                'sold_purchase' => 0.0,
                'realized_margin' => 0.0,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'fast_mode' => true,
            'sqlite_degraded' => true,
        ];
        return [
            'success' => true,
            'message' => null,
            'data' => ['stats' => $stats],
            'errors' => [],
            'stats' => $stats,
        ];
    }

    try {
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM orders) AS total_orders,
                (SELECT COUNT(*) FROM orders WHERE COALESCE(TRIM(status), '') IN ('pending', 'draft') OR status IS NULL OR TRIM(COALESCE(status, '')) = '') AS pending_orders,
                (SELECT COUNT(*) FROM orders WHERE status IN ('sent_to_client', 'viewed')) AS in_progress_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'signed') AS completed_orders,
                (SELECT COUNT(*) FROM orders WHERE status = 'cancelled') AS cancelled_orders,
                (SELECT COUNT(*) FROM receipts) AS total_receipts,
                (SELECT COUNT(*) FROM mobile_reports) AS total_reports
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            throw new RuntimeException('Empty stats row');
        }

        $inv = sqliteInventoryAggregateStats($pdo);

        $totalDocuments = (int)$data['total_orders'] + (int)$data['total_receipts'] + (int)$data['total_reports'];
        $totalCompleted = (int)$data['completed_orders'] + (int)$data['total_receipts'] + (int)$data['total_reports'];

        $stats = [
            'pending' => (int)$data['pending_orders'],
            'in_progress' => (int)$data['in_progress_orders'],
            'completed' => $totalCompleted,
            'cancelled' => (int)$data['cancelled_orders'],
            'total' => $totalDocuments,
            'orders' => (int)$data['total_orders'],
            'receipts' => (int)$data['total_receipts'],
            'reports' => (int)$data['total_reports'],
            'inventory' => [
                'total' => $inv['total'],
                'in_stock' => $inv['in_stock'],
                'low_stock' => $inv['low_stock'],
                'value' => $inv['value'],
                'purchase_value' => $inv['purchase_value'] ?? $inv['value'],
                'sale_value' => $inv['sale_value'] ?? 0.0,
                'profit_potential' => $inv['profit_potential'] ?? 0.0,
                'sold_qty' => $inv['sold_qty'] ?? 0.0,
                'sold_revenue' => $inv['sold_revenue'] ?? 0.0,
                'sold_purchase' => $inv['sold_purchase'] ?? 0.0,
                'realized_margin' => $inv['realized_margin'] ?? 0.0,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'fast_mode' => true,
        ];

        return [
            'success' => true,
            'message' => null,
            'data' => ['stats' => $stats],
            'errors' => [],
            'stats' => $stats,
        ];
    } catch (Throwable $e) {
        error_log('Get fast stats error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Ошибка при получении статистики: ' . $e->getMessage(),
            'data' => null,
            'errors' => [$e->getMessage()],
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(getFastStats(), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается', 'data' => null, 'errors' => []], JSON_UNESCAPED_UNICODE);
}
