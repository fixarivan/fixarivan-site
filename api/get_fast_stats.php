<?php
/**
 * Быстрая статистика из единого SQLite (fixarivan.sqlite).
 * Поддерживает фильтры периода: preset, from, to, chart_range.
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
require_once __DIR__ . '/lib/dashboard_stats.php';

function getFastStatsDegraded(): array
{
    $stats = [
        'pending' => 0,
        'waiting' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'total' => 0,
        'total_orders' => 0,
        'total_documents' => 0,
        'orders' => 0,
        'receipts' => 0,
        'reports' => 0,
        'inventory' => [
            'total' => 0,
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
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

function getFastStats(): array
{
    try {
        $pdo = getSqliteConnection();
    } catch (Throwable $e) {
        error_log('get_fast_stats SQLite: ' . $e->getMessage());

        return getFastStatsDegraded();
    }

    try {
        $stats = fixarivan_dashboard_build_stats($pdo, $_GET);

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
