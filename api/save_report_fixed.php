<?php
declare(strict_types=1);
/**
 * ИСПРАВЛЕННЫЙ API для сохранения отчётов
 * Полностью рабочий с правильной обработкой ошибок
 */
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/client_token.php';
require_once __DIR__ . '/lib/order_center.php';

// Очистка буфера
if (ob_get_length()) ob_end_clean();

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

function generateReportId(): string {
    return 'RPT-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function getReportsStorageDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reports';
}

function saveReportToJson(array $data): array {
    $storageDir = getReportsStorageDir();
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        return ['success' => false, 'message' => 'Не удалось создать директорию storage/reports'];
    }

    $reportId = generateReportId();
    $token = fixarivan_generate_client_token();
    $nowIso = date('c');

    $record = [
        'report_id' => $reportId,
        'token' => $token,
        'created_at' => $nowIso,
        'updated_at' => $nowIso,
        'data' => $data
    ];

    $jsonPath = $storageDir . DIRECTORY_SEPARATOR . $token . '.json';
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        return ['success' => false, 'message' => 'Ошибка сериализации данных отчёта'];
    }

    if (file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
        return ['success' => false, 'message' => 'Не удалось сохранить JSON отчёт'];
    }

    return [
        'success' => true,
        'report_id' => $reportId,
        'token' => $token,
        'file' => $jsonPath
    ];
}

function saveReportFixed($data) {
    try {
        $pdo = getSqliteConnection();
        $resolved = fixarivan_require_existing_order_for_report(
            $pdo,
            (string)($data['orderId'] ?? $data['order_id'] ?? '')
        );
        $resolvedOrderId = (string)($resolved['order_id'] ?? '');
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    $jsonResult = saveReportToJson($data);
    if (!$jsonResult['success']) {
        return $jsonResult;
    }

    try {

        $testsPayload = [
            'componentTests' => (string)($data['componentTests'] ?? ''),
            'cleaning' => is_array($data['cleaning'] ?? null) ? $data['cleaning'] : []
        ];

        $batteryPayload = [
            'batteryCapacity' => (string)($data['batteryCapacity'] ?? ''),
            'batteryStatus' => (string)($data['batteryStatus'] ?? ''),
            'batteryReplacement' => (bool)($data['batteryReplacement'] ?? false),
            'batteryNotes' => (string)($data['batteryNotes'] ?? '')
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO mobile_reports (
                report_id, token, created_at, client_name, phone, device_type, model, serial_number,
                tests_json, battery_json, diagnosis, recommendations, device_rating, appearance_rating,
                master_name, work_date, order_id, verification_code, raw_json
            ) VALUES (
                :report_id, :token, :created_at, :client_name, :phone, :device_type, :model, :serial_number,
                :tests_json, :battery_json, :diagnosis, :recommendations, :device_rating, :appearance_rating,
                :master_name, :work_date, :order_id, :verification_code, :raw_json
            )'
        );

        $stmt->execute([
            ':report_id' => $jsonResult['report_id'],
            ':token' => $jsonResult['token'],
            ':created_at' => date('c'),
            ':client_name' => trim((string)($data['clientName'] ?? '')),
            ':phone' => trim((string)($data['clientPhone'] ?? '')),
            ':device_type' => (string)($data['deviceType'] ?? ''),
            ':model' => (string)($data['deviceModel'] ?? ''),
            ':serial_number' => (string)($data['deviceSerial'] ?? ''),
            ':tests_json' => json_encode($testsPayload, JSON_UNESCAPED_UNICODE),
            ':battery_json' => json_encode($batteryPayload, JSON_UNESCAPED_UNICODE),
            ':diagnosis' => (string)($data['diagnosis'] ?? ''),
            ':recommendations' => (string)($data['recommendations'] ?? ''),
            ':device_rating' => (int)($data['deviceRating'] ?? 0),
            ':appearance_rating' => (int)($data['conditionRating'] ?? 0),
            ':master_name' => (string)($data['technicianName'] ?? ''),
            ':work_date' => (string)($data['workDate'] ?? ''),
            ':order_id' => $resolvedOrderId !== '' ? $resolvedOrderId : null,
            ':verification_code' => (string)($data['uniqueCode'] ?? ''),
            ':raw_json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);

        return [
            'success' => true,
            'message' => 'Отчёт сохранён в SQLite',
            'document_id' => $data['documentId'] ?? null,
            'order_id' => $resolvedOrderId !== '' ? $resolvedOrderId : null,
            'report_id' => $jsonResult['report_id'],
            'token' => $jsonResult['token'],
            'storage' => 'storage/fixarivan.sqlite',
            'json_backup' => 'storage/reports'
        ];
    } catch (Throwable $e) {
        error_log('SQLite save error: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Отчёт сохранён только в JSON backup: ' . $e->getMessage(),
            'document_id' => $data['documentId'] ?? null,
            'order_id' => null,
            'report_id' => $jsonResult['report_id'],
            'token' => $jsonResult['token'],
            'storage' => 'storage/reports',
            'sqlite_warning' => $e->getMessage()
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные JSON']);
        exit;
    }
    
    // Валидация обязательных полей (TZ v4.4: order_id обязателен для привязки отчёта к заказу)
    $requiredFields = ['documentId', 'clientName', 'clientPhone'];
    foreach ($requiredFields as $field) {
        if (empty($input[$field])) {
            echo json_encode(['success' => false, 'message' => "Обязательное поле '$field' не заполнено"]);
            exit;
        }
    }
    $oidIn = trim((string)($input['orderId'] ?? $input['order_id'] ?? ''));
    if ($oidIn === '') {
        echo json_encode(['success' => false, 'message' => 'Укажите order_id существующего заказа'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = saveReportFixed($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>