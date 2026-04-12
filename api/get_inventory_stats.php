<?php
/**
 * Статистика склада из SQLite (единый источник с get_fast_stats).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/inventory_sqlite_helpers.php';

try {
    $pdo = getSqliteConnection();
    $inventoryStats = sqliteInventoryAggregateStats($pdo);
    echo json_encode([
        'success' => true,
        'message' => null,
        'data' => $inventoryStats,
        'errors' => [],
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_inventory_stats SQLite: ' . $e->getMessage());
    $fallback = [
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
        'sqlite_degraded' => true,
    ];
    echo json_encode([
        'success' => true,
        'message' => null,
        'data' => $fallback,
        'errors' => [],
        'timestamp' => time(),
    ], JSON_UNESCAPED_UNICODE);
}
