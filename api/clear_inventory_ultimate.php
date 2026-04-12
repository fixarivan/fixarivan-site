<?php
/**
 * УЛЬТИМАТИВНАЯ ОЧИСТКА СКЛАДА
 * Работает с любой структурой БД
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once dirname(__DIR__) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Проверяем подтверждение
    if (!isset($input['confirm']) || $input['confirm'] !== 'YES_DELETE_INVENTORY') {
        echo json_encode(['success' => false, 'message' => 'Требуется подтверждение для удаления данных склада']);
        exit;
    }

    $pdo = getDatabaseConnection();
    if (!$pdo) {
        echo json_encode(['success' => false, 'message' => 'Ошибка подключения к базе данных']);
        exit;
    }

    try {
        $results = [];
        $totalDeleted = 0;
        
        // Проверяем существование таблицы inventory
        $checkTable = $pdo->query("SHOW TABLES LIKE 'inventory'");
        if ($checkTable->rowCount() === 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Таблица inventory не существует - нечего очищать',
                'deleted_counts' => [],
                'total_deleted' => 0,
                'updated_stats' => [
                    'total' => 0,
                    'in_stock' => 0,
                    'low_stock' => 0,
                    'out_of_stock' => 0,
                    'value' => 0
                ]
            ]);
            exit;
        }
        
        // Получаем количество записей перед удалением
        $countStmt = $pdo->query("SELECT COUNT(*) as count FROM inventory");
        $beforeCount = $countStmt->fetch()['count'];
        
        if ($beforeCount == 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Склад уже пуст - нечего очищать',
                'deleted_counts' => ['inventory' => 0],
                'total_deleted' => 0,
                'updated_stats' => [
                    'total' => 0,
                    'in_stock' => 0,
                    'low_stock' => 0,
                    'out_of_stock' => 0,
                    'value' => 0
                ]
            ]);
            exit;
        }
        
        // Удаляем все данные из таблицы inventory
        $deleteStmt = $pdo->prepare("DELETE FROM inventory");
        $deleteStmt->execute();
        $deletedCount = $deleteStmt->rowCount();
        
        // Сбрасываем автоинкремент
        $pdo->exec("ALTER TABLE inventory AUTO_INCREMENT = 1");
        
        $totalDeleted = $deletedCount;
        
        // Получаем обновленную статистику после очистки
        $updatedStats = [
            'total' => 0,
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
            'value' => 0
        ];
        
        // Логируем операцию очистки
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'inventory_cleared',
            'deleted_count' => $totalDeleted,
            'user' => 'admin'
        ];
        error_log("Inventory cleared: " . json_encode($logEntry));
        
        echo json_encode([
            'success' => true,
            'message' => "Склад успешно очищен! Удалено позиций: $totalDeleted",
            'deleted_counts' => ['inventory' => $totalDeleted],
            'total_deleted' => $totalDeleted,
            'updated_stats' => $updatedStats
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $e) {
        error_log("Inventory clear error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка базы данных при очистке склада: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("General error during inventory clear: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Ошибка при очистке склада: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
