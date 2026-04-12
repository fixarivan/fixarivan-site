<?php
/**
 * Редактирование документов в SQLite (админ-сессия).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization, X-Fixarivan-Auth');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/mobile_report_document.php';

/**
 * @param array<string,mixed> $data
 * @return array{success:bool,message?:string,document_id?:string}
 */
function updateDocumentSqlite(string $documentId, string $documentType, array $data): array {
    $documentId = trim($documentId);
    if ($documentId === '') {
        return ['success' => false, 'message' => 'Пустой идентификатор документа'];
    }

    $config = [
        'order' => [
            'table' => 'orders',
            'id_column' => 'document_id',
            'fields' => [
                'client_name', 'client_phone', 'client_email', 'device_model', 'device_serial', 'device_type',
                'problem_description', 'device_password', 'device_condition', 'accessories', 'client_signature', 'pattern_data',
                'priority', 'status', 'place_of_acceptance', 'date_of_acceptance', 'unique_code', 'technician_name', 'work_date',
                'language', 'date_created',
            ],
        ],
        'receipt' => [
            'table' => 'receipts',
            'id_column' => 'document_id',
            'fields' => [
                'client_name', 'client_phone', 'client_email', 'total_amount', 'payment_method', 'services_rendered',
                'notes', 'status', 'place_of_acceptance', 'date_of_acceptance', 'unique_code', 'language', 'date_created',
                'receipt_number',
            ],
        ],
    ];

    try {
        $pdo = getSqliteConnection();
    } catch (Throwable $e) {
        return ['success' => false, 'message' => 'SQLite недоступна: ' . $e->getMessage()];
    }

    if ($documentType === 'report') {
        try {
            fixarivan_update_mobile_report($pdo, $documentId, $data);
            return ['success' => true, 'message' => 'Документ успешно обновлён', 'document_id' => $documentId];
        } catch (Throwable $e) {
            error_log('update report: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    if (!isset($config[$documentType])) {
        return ['success' => false, 'message' => 'Неизвестный тип документа'];
    }

    $table = $config[$documentType]['table'];
    $idCol = $config[$documentType]['id_column'];
    $allowed = $config[$documentType]['fields'];

    $sets = [];
    $vals = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $data)) {
            $sets[] = $field . ' = ?';
            $vals[] = $data[$field];
        }
    }

    if ($sets === []) {
        return ['success' => false, 'message' => 'Нет данных для обновления'];
    }

    $sets[] = 'date_updated = ?';
    $vals[] = date('c');
    $vals[] = $documentId;

    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $idCol . ' = ?';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        if ($stmt->rowCount() === 0) {
            return ['success' => false, 'message' => 'Документ не найден или данные не изменились'];
        }
    } catch (Throwable $e) {
        error_log('updateDocumentSqlite: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Ошибка при обновлении документа: ' . $e->getMessage()];
    }

    return ['success' => true, 'message' => 'Документ успешно обновлён', 'document_id' => $documentId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['documentId'], $input['documentType'])) {
        echo json_encode(['success' => false, 'message' => 'Не указаны обязательные поля'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $updates = $input['updates'] ?? $input;
    unset($updates['documentId'], $updates['documentType']);

    $result = updateDocumentSqlite((string) $input['documentId'], (string) $input['documentType'], is_array($updates) ? $updates : []);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
