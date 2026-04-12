<?php
/**
 * ОПТИМИЗИРОВАННЫЙ API для получения статистики
 *
 * DEPRECATED — not used in SQLite flow (MySQL). Kept for reference; do not use from new UI.
 */

// Очистка буфера
if (ob_get_length()) ob_end_clean();

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once dirname(__DIR__) . '/config.php';

function getOptimizedStatistics() {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Ошибка подключения к базе данных'];
    }

    try {
        // ОДИН ОПТИМИЗИРОВАННЫЙ ЗАПРОС для всех данных
        $sql = "
            SELECT 
                'orders' as table_name,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_week,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
            FROM orders
            WHERE (is_deleted IS NULL OR is_deleted = FALSE)
            
            UNION ALL
            
            SELECT 
                'receipts' as table_name,
                COUNT(*) as total,
                0 as pending,
                0 as in_progress,
                SUM(CASE WHEN status = 'paid' OR status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_week,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
            FROM receipts
            WHERE (is_deleted IS NULL OR is_deleted = FALSE)
            
            UNION ALL
            
            SELECT 
                'reports' as table_name,
                COUNT(*) as total,
                0 as pending,
                0 as in_progress,
                COUNT(*) as completed,
                0 as cancelled,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as last_week,
                SUM(CASE WHEN date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as last_month
            FROM reports
            WHERE (is_deleted IS NULL OR is_deleted = FALSE)
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        // Агрегируем данные
        $totalOrders = 0;
        $totalReceipts = 0;
        $totalReports = 0;
        $pending = 0;
        $inProgress = 0;
        $completed = 0;
        $cancelled = 0;
        $lastWeek = 0;
        $lastMonth = 0;
        
        foreach ($results as $row) {
            if ($row['table_name'] === 'orders') {
                $totalOrders = (int)$row['total'];
                $pending = (int)$row['pending'];
                $inProgress = (int)$row['in_progress'];
                $completed += (int)$row['completed'];
                $cancelled += (int)$row['cancelled'];
                $lastWeek += (int)$row['last_week'];
                $lastMonth += (int)$row['last_month'];
            } elseif ($row['table_name'] === 'receipts') {
                $totalReceipts = (int)$row['total'];
                $completed += (int)$row['completed'];
                $cancelled += (int)$row['cancelled'];
                $lastWeek += (int)$row['last_week'];
                $lastMonth += (int)$row['last_month'];
            } elseif ($row['table_name'] === 'reports') {
                $totalReports = (int)$row['total'];
                $completed += (int)$row['completed'];
                $lastWeek += (int)$row['last_week'];
                $lastMonth += (int)$row['last_month'];
            }
        }
        
        $totalDocuments = $totalOrders + $totalReceipts + $totalReports;
        
        // Получаем статистику по типам документов
        $documentTypes = [
            'orders' => $totalOrders,
            'receipts' => $totalReceipts,
            'reports' => $totalReports
        ];
        
        // Получаем последние документы для дашборда
        $recentSql = "
            (SELECT document_id, client_name, 'order' as type, date_created, status FROM orders 
             WHERE (is_deleted IS NULL OR is_deleted = FALSE) 
             ORDER BY date_created DESC LIMIT 5)
            UNION ALL
            (SELECT document_id, client_name, 'receipt' as type, date_created, 'paid' as status FROM receipts 
             WHERE (is_deleted IS NULL OR is_deleted = FALSE) 
             ORDER BY date_created DESC LIMIT 5)
            UNION ALL
            (SELECT document_id, client_name, 'report' as type, date_created, 'completed' as status FROM reports 
             WHERE (is_deleted IS NULL OR is_deleted = FALSE) 
             ORDER BY date_created DESC LIMIT 5)
            ORDER BY date_created DESC LIMIT 10
        ";
        
        $recentStmt = $pdo->prepare($recentSql);
        $recentStmt->execute();
        $recentDocuments = $recentStmt->fetchAll();
        
        $stats = [
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'total' => $totalDocuments,
            'orders' => $totalOrders,
            'receipts' => $totalReceipts,
            'reports' => $totalReports,
            'last_week' => $lastWeek,
            'last_month' => $lastMonth,
            'document_types' => $documentTypes,
            'recent_documents' => $recentDocuments,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        return ['success' => true, 'stats' => $stats];
        
    } catch (PDOException $e) {
        error_log("Get optimized statistics error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка при получении статистики: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = getOptimizedStatistics();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
