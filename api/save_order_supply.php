<?php
declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_supply.php';
require_once __DIR__ . '/lib/order_json_storage.php';

/** @return array<int, mixed> */
function fixarivan_save_supply_parse_order_lines(?string $json): array {
    if ($json === null || $json === '') {
        return [];
    }
    $d = json_decode($json, true);

    return is_array($d) ? $d : [];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = trim((string)($input['orderId'] ?? $input['order_id'] ?? ''));
$documentId = trim((string)($input['documentId'] ?? $input['document_id'] ?? ''));
$additionalInfo = trim((string)($input['additionalInfo'] ?? ''));
$supplyRequest = trim((string)($input['supplyRequest'] ?? ''));
$supplyUrgency = trim((string)($input['supplyUrgency'] ?? 'medium'));
$supplyDueDate = trim((string)($input['supplyDueDate'] ?? ''));
$clientName = trim((string)($input['clientName'] ?? ''));
$deviceModel = trim((string)($input['deviceModel'] ?? ''));

if ($orderId === '' && $documentId === '') {
    echo json_encode(['success' => false, 'message' => 'Нужен orderId или documentId'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($supplyUrgency, ['low', 'medium', 'high', 'urgent'], true)) {
    $supplyUrgency = 'medium';
}

try {
    $pdo = getSqliteConnection();
    $params = [];
    $where = [];
    if ($orderId !== '') {
        $where[] = 'order_id = :oid';
        $params[':oid'] = $orderId;
    }
    if ($documentId !== '') {
        $where[] = 'document_id = :did';
        $params[':did'] = $documentId;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM orders WHERE ' . implode(' OR ', $where) . '
         ORDER BY COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') DESC
         LIMIT 1'
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (fixarivan_save_supply_parse_order_lines((string)($row['order_lines_json'] ?? '[]')) !== []) {
        echo json_encode([
            'success' => false,
            'message' => 'У заказа есть позиции в order_lines_json. Сохраняйте заказ через save_order_fixed.php с isMasterForm=true и orderLines.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $resolvedOrderId = trim((string)($row['order_id'] ?? $orderId));
    $resolvedDocumentId = trim((string)($row['document_id'] ?? $documentId));
    if ($resolvedOrderId === '') {
        $resolvedOrderId = $resolvedDocumentId;
    }
    if ($resolvedDocumentId === '') {
        echo json_encode(['success' => false, 'message' => 'Не удалось определить document_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonPath = fixarivan_orders_storage_dir() . DIRECTORY_SEPARATOR . $resolvedDocumentId . '.json';
    $jsonRecord = [];
    if (is_file($jsonPath)) {
        $jsonRaw = file_get_contents($jsonPath);
        $decoded = is_string($jsonRaw) ? json_decode($jsonRaw, true) : null;
        if (is_array($decoded)) {
            $jsonRecord = $decoded;
        }
    }
    if ($jsonRecord === []) {
        $jsonRecord = $row;
    }

    $jsonRecord['document_id'] = $resolvedDocumentId;
    $jsonRecord['order_id'] = $resolvedOrderId;
    $jsonRecord['additional_info'] = $additionalInfo;
    $jsonRecord['supply_request'] = $supplyRequest;
    $jsonRecord['supply_urgency'] = $supplyUrgency;
    $jsonRecord['supply_due_date'] = trim((string)($row['public_expected_date'] ?? ''));
    $jsonRecord['date_updated'] = date('c');
    $jsonRecord['raw_json'] = array_merge(
        is_array($jsonRecord['raw_json'] ?? null) ? $jsonRecord['raw_json'] : [],
        [
            'additionalInfo' => $additionalInfo,
            'supplyRequest' => $supplyRequest,
            'supplyUrgency' => $supplyUrgency,
            'supplyDueDate' => $supplyDueDate,
            'supplyUpdatedFrom' => 'track',
        ]
    );

    fixarivan_save_order_json_files($jsonRecord, $resolvedDocumentId, (string)($row['client_token'] ?? ''));

    $items = fixarivan_parse_supply_request($supplyRequest);
    $pdo->beginTransaction();
    try {
        fixarivan_apply_supply_effects(
            $pdo,
            $resolvedOrderId,
            $supplyRequest,
            $deviceModel !== '' ? $deviceModel : (string)($row['device_model'] ?? ''),
            $supplyUrgency,
            (string)($row['public_expected_date'] ?? ''),
            $clientName !== '' ? $clientName : (string)($row['client_name'] ?? '')
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $out = [
        'success' => true,
        'message' => 'Заявка на закупку сохранена',
        'order_id' => $resolvedOrderId,
        'document_id' => $resolvedDocumentId,
        'items_count' => count($items),
    ];
    $sdw = fixarivan_supply_missing_expected_date_warning(
        $supplyRequest,
        (string)($row['public_expected_date'] ?? ''),
        (string)($row['order_lines_json'] ?? '[]')
    );
    if ($sdw !== null) {
        $out['supply_date_warning'] = $sdw;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка сохранения заявки: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
