<?php
/**
 * УЛЬТИМАТИВНЫЙ API ДЛЯ ОТЧЁТОВ
 *
 * DEPRECATED — not used in SQLite flow (MySQL). Kept for reference; do not use from new UI.
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once dirname(__DIR__) . '/config.php';

function saveReportUltimate($data) {
    $pdo = getDatabaseConnection();
    if (!$pdo) {
        return ['success' => false, 'message' => 'Ошибка подключения к базе данных'];
    }

    try {
        // Получаем структуру таблицы reports
        $columns = $pdo->query("SHOW COLUMNS FROM reports")->fetchAll(PDO::FETCH_COLUMN);
        
        // Подготавливаем данные для вставки
        $insertData = [
            'document_id' => $data['documentId'] ?? $data['document_id'] ?? null,
            'client_name' => $data['clientName'] ?? $data['client_name'] ?? null,
            'client_phone' => $data['clientPhone'] ?? $data['client_phone'] ?? null,
            'client_email' => $data['clientEmail'] ?? $data['client_email'] ?? null,
            'device_model' => $data['deviceModel'] ?? $data['device_model'] ?? null,
            'device_serial' => $data['deviceSerial'] ?? $data['device_serial'] ?? null,
            'device_type' => $data['deviceType'] ?? $data['device_type'] ?? null,
            'diagnosis' => $data['diagnosis'] ?? null,
            'recommendations' => $data['recommendations'] ?? null,
            'repair_cost' => $data['repairCost'] ?? $data['repair_cost'] ?? null,
            'repair_time' => $data['repairTime'] ?? $data['repair_time'] ?? null,
            'warranty' => $data['warranty'] ?? null,
            'report_type' => $data['reportType'] ?? $data['report_type'] ?? 'general',
            'priority' => $data['priority'] ?? 'normal',
            'status' => 'completed'
        ];
        
        // Фильтруем только существующие колонки
        $validData = [];
        $validPlaceholders = [];
        
        foreach ($insertData as $column => $value) {
            if (in_array($column, $columns)) {
                $validData[$column] = $value;
                $validPlaceholders[] = '?';
            }
        }
        
        // Проверяем обязательные поля
        if (empty($validData['document_id'])) {
            return ['success' => false, 'message' => 'Не указан ID документа'];
        }
        if (empty($validData['client_name'])) {
            return ['success' => false, 'message' => 'Не указано имя клиента'];
        }
        
        // Проверяем дубликаты
        $checkStmt = $pdo->prepare("SELECT document_id FROM reports WHERE document_id = ?");
        $checkStmt->execute([$validData['document_id']]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Отчёт с таким номером уже существует'];
        }
        
        // SQL запрос
        $sql = "INSERT INTO reports (" . implode(', ', array_keys($validData)) . ", date_created) VALUES (" . implode(', ', $validPlaceholders) . ", NOW())";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute(array_values($validData));
        
        if ($result) {
            return ['success' => true, 'message' => 'Отчёт успешно сохранён', 'document_id' => $validData['document_id']];
        } else {
            return ['success' => false, 'message' => 'Ошибка при сохранении отчёта'];
        }
        
    } catch (PDOException $e) {
        error_log("Database error in saveReportUltimate: " . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка базы данных: ' . $e->getMessage()];
    } catch (Exception $e) {
        error_log("General error in saveReportUltimate: " . $e->getMessage());
        return ['success' => false, 'message' => 'Произошла непредвиденная ошибка: ' . $e->getMessage()];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
        exit;
    }
    
    $result = saveReportUltimate($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
