<?php
/**
 * БЕЗОПАСНАЯ СИНХРОНИЗАЦИЯ СКЛАДА
 * Работает даже с пустой таблицей
 */

// Очистка буфера
if (ob_get_length()) ob_end_clean();

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

/**
 * DEPRECATED — not used in SQLite flow; kept for reference. Do not call from new UI.
 */
require_once __DIR__ . '/lib/require_admin_session.php';

require_once dirname(__DIR__) . '/config.php';

function safeSyncInventory($pdo) {
    try {
        $synced = 0;
        $stats = [
            'total' => 0,
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0
        ];
        
        // Проверяем существование таблицы
        $checkTable = $pdo->query("SHOW TABLES LIKE 'inventory'");
        if ($checkTable->rowCount() === 0) {
            return [
                'success' => true,
                'message' => 'Таблица inventory не существует',
                'synced_records' => 0,
                'statistics' => $stats
            ];
        }
        
        // Проверяем количество записей
        $countStmt = $pdo->query("SELECT COUNT(*) as count FROM inventory");
        $totalCount = $countStmt->fetch()['count'];
        
        if ($totalCount == 0) {
            return [
                'success' => true,
                'message' => 'Склад пуст - нечего синхронизировать',
                'synced_records' => 0,
                'statistics' => $stats
            ];
        }
        
        // Выполняем синхронизацию только если есть записи
        try {
            // 1. Исправляем отрицательные остатки
            $stmt = $pdo->prepare("UPDATE inventory SET quantity = 0 WHERE quantity < 0");
            $stmt->execute();
            $synced += $stmt->rowCount();
            
            // 2. Устанавливаем минимальные остатки
            $stmt = $pdo->prepare("UPDATE inventory SET min_stock = 5 WHERE min_stock IS NULL OR min_stock = 0");
            $stmt->execute();
            $synced += $stmt->rowCount();
            
            // 3. Исправляем пустые названия
            $stmt = $pdo->prepare("UPDATE inventory SET name = 'Неизвестный товар' WHERE name IS NULL OR name = ''");
            $stmt->execute();
            $synced += $stmt->rowCount();
            
            // 4. Устанавливаем категорию по умолчанию
            $stmt = $pdo->prepare("UPDATE inventory SET category = 'other' WHERE category IS NULL OR category = ''");
            $stmt->execute();
            $synced += $stmt->rowCount();
            
            // 5. Обновляем время последнего изменения
            $stmt = $pdo->prepare("UPDATE inventory SET last_updated = NOW() WHERE 1");
            $stmt->execute();
            
        } catch (PDOException $e) {
            // Если есть ошибки в обновлении, продолжаем с получением статистики
            error_log("Sync update error: " . $e->getMessage());
        }
        
        // Получаем статистику
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory");
            $stats['total'] = $stmt->fetch()['total'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as in_stock FROM inventory WHERE quantity > 0");
            $stats['in_stock'] = $stmt->fetch()['in_stock'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as low_stock FROM inventory WHERE quantity > 0 AND quantity <= min_stock");
            $stats['low_stock'] = $stmt->fetch()['low_stock'] ?? 0;
            
            $stmt = $pdo->query("SELECT COUNT(*) as out_of_stock FROM inventory WHERE quantity = 0");
            $stats['out_of_stock'] = $stmt->fetch()['out_of_stock'] ?? 0;
            
        } catch (PDOException $e) {
            error_log("Stats query error: " . $e->getMessage());
        }
        
        $message = "Синхронизация завершена успешно!";
        if ($synced > 0) {
            $message .= " Исправлено записей: $synced";
        } else {
            $message .= " Данные уже актуальны";
        }
        
        return [
            'success' => true,
            'message' => $message,
            'synced_records' => $synced,
            'statistics' => $stats,
            'sync_time' => date('Y-m-d H:i:s')
        ];
        
    } catch (PDOException $e) {
        error_log("Safe sync failed: " . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка синхронизации: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Ошибка подключения к БД']);
        exit;
    }
    
    $result = safeSyncInventory($pdo);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
