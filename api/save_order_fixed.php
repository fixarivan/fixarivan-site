<?php
/**
 * ИСПРАВЛЕННЫЙ API для сохранения заказов
 * Полностью рабочий с правильной обработкой ошибок
 */

// Очистка буфера
if (ob_get_length()) ob_end_clean();

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/client_token.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/order_supply.php';
require_once __DIR__ . '/lib/order_warehouse_sync.php';
require_once __DIR__ . '/lib/order_json_storage.php';
require_once __DIR__ . '/lib/order_track_lines.php';

/** @return float|null */
function fixarivan_optional_float(mixed $v) {
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $s = str_replace([',', ' '], ['.', ''], trim((string)$v));
    if ($s === '' || $s === '-' || $s === '—') {
        return null;
    }
    if (!is_numeric($s)) {
        return null;
    }

    return (float)$s;
}

/** @return float|null */
function fixarivan_resolve_estimated_labor_cost(array $data, ?array $existing = null): ?float {
    $e = $existing ?? [];
    if (array_key_exists('estimatedLaborCost', $data) || array_key_exists('estimated_labor_cost', $data)) {
        return fixarivan_optional_float($data['estimatedLaborCost'] ?? $data['estimated_labor_cost'] ?? null);
    }
    $pubInPayload = array_key_exists('publicEstimatedCost', $data) || array_key_exists('public_estimated_cost', $data);
    if ($pubInPayload) {
        return fixarivan_optional_float($data['publicEstimatedCost'] ?? $data['public_estimated_cost'] ?? null);
    }
    if (array_key_exists('estimated_labor_cost', $e)) {
        return fixarivan_optional_float($e['estimated_labor_cost'] ?? null);
    }

    return fixarivan_optional_float($e['public_estimated_cost'] ?? null);
}

function fixarivan_parse_order_lines_array(mixed $raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($raw) ? $raw : [];
}

/** @return array{0: float, 1: float} */
function fixarivan_totals_from_order_lines(array $lines): array {
    return fixarivan_track_totals_from_lines($lines);
}

function fixarivan_resolve_public_order_status_for_normalize(array $data, ?array $existing): string {
    $e = $existing ?? [];
    $raw = $data['publicStatus'] ?? $data['public_status'] ?? $data['orderStatus'] ?? $data['order_status'] ?? null;
    if (($raw === null || $raw === '') && (($e['public_status'] ?? '') !== '' || ($e['order_status'] ?? '') !== '')) {
        $raw = ($e['public_status'] ?? '') !== '' ? $e['public_status'] : ($e['order_status'] ?? '');
    }

    return fixarivan_normalize_public_status($raw !== null && $raw !== '' ? (string)$raw : null);
}

function fixarivan_order_lines_json_encode(array $data, ?array $existing = null): string {
    if (isset($data['orderLines']) && is_array($data['orderLines'])) {
        $enc = json_encode($data['orderLines'], JSON_UNESCAPED_UNICODE);

        return $enc !== false ? $enc : '[]';
    }
    if (isset($data['order_lines']) && is_array($data['order_lines'])) {
        $enc = json_encode($data['order_lines'], JSON_UNESCAPED_UNICODE);

        return $enc !== false ? $enc : '[]';
    }
    if (isset($data['order_lines_json']) && is_array($data['order_lines_json'])) {
        $enc = json_encode($data['order_lines_json'], JSON_UNESCAPED_UNICODE);

        return $enc !== false ? $enc : '[]';
    }
    if (isset($data['order_lines_json']) && is_string($data['order_lines_json'])) {
        return $data['order_lines_json'];
    }

    return fixarivan_normalize_order_lines_json_for_sync($existing['order_lines_json'] ?? '[]');
}

function loadOrderJsonByClientToken(string $clientToken): array {
    $clientToken = trim($clientToken);
    if ($clientToken === '') return [];

    $dir = fixarivan_orders_tokens_storage_dir();
    $jsonPath = $dir . DIRECTORY_SEPARATOR . $clientToken . '.json';
    if (!is_file($jsonPath)) return [];

    $raw = file_get_contents($jsonPath);
    if ($raw === false) return [];

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalizeOrderRecord(array $data, ?array $existing = null): array {
    $nowIso = date('c');

    // Keep date_created from existing if available
    $dateCreated = $existing['date_created'] ?? ($existing['dateCreated'] ?? null);
    if (empty($dateCreated)) {
        $dateCreated = $nowIso;
    }

    // Map incoming payload (camelCase) to template/db keys (snake_case)
    return [
        'document_id' => trim((string)($data['documentId'] ?? $existing['document_id'] ?? '')),
        'date_created' => (string)$dateCreated,
        'date_updated' => $nowIso,
        'place_of_acceptance' => (string)($data['placeOfAcceptance'] ?? $data['location'] ?? ($existing['place_of_acceptance'] ?? 'Turku, Finland')),
        'date_of_acceptance' => (string)($data['dateOfAcceptance'] ?? $data['acceptDate'] ?? ($data['workDate'] ?? ($existing['date_of_acceptance'] ?? date('Y-m-d')))),
        'unique_code' => isset($data['uniqueCode']) && $data['uniqueCode'] !== '' ? (string)$data['uniqueCode'] : (string)($existing['unique_code'] ?? ''),
        'language' => (string)($data['language'] ?? ($existing['language'] ?? 'ru')),

        'client_name' => trim((string)($data['clientName'] ?? $existing['client_name'] ?? '')),
        'client_phone' => trim((string)($data['clientPhone'] ?? $existing['client_phone'] ?? '')),
        'client_email' => isset($data['clientEmail']) && $data['clientEmail'] !== '' ? (string)$data['clientEmail'] : (string)($existing['client_email'] ?? ''),

        'device_model' => trim((string)($data['deviceModel'] ?? $existing['device_model'] ?? '')),
        'device_serial' => trim((string)($data['serialNumber'] ?? $data['deviceSerial'] ?? $existing['device_serial'] ?? '')),
        'device_type' => isset($data['deviceType']) && $data['deviceType'] !== '' ? (string)$data['deviceType'] : (string)($existing['device_type'] ?? ''),
        'device_condition' => isset($data['deviceCondition']) && $data['deviceCondition'] !== '' ? (string)$data['deviceCondition'] : (string)($existing['device_condition'] ?? ''),
        'accessories' => isset($data['accessories']) && $data['accessories'] !== '' ? (string)$data['accessories'] : (string)($existing['accessories'] ?? ''),

        'device_password' => isset($data['devicePassword']) && $data['devicePassword'] !== '' ? (string)$data['devicePassword'] : (string)($existing['device_password'] ?? ''),
        'problem_description' => trim((string)($data['problemDescription'] ?? $existing['problem_description'] ?? '')),

        'priority' => isset($data['priority']) && $data['priority'] !== '' ? (string)$data['priority'] : (string)($existing['priority'] ?? 'normal'),
        'status' => isset($data['status']) && $data['status'] !== '' ? (string)$data['status'] : (string)($existing['status'] ?? 'pending'),

        'pattern_data' => isset($data['patternData']) && $data['patternData'] !== '' ? (string)$data['patternData'] : (string)($existing['pattern_data'] ?? ''),
        'client_signature' => isset($data['signatureData']) && $data['signatureData'] !== '' ? (string)$data['signatureData'] : (string)($existing['client_signature'] ?? ''),

        'technician_name' => isset($data['technicianName']) && $data['technicianName'] !== '' ? (string)$data['technicianName'] : (string)($existing['technician_name'] ?? ''),
        'work_date' => isset($data['workDate']) && $data['workDate'] !== '' ? (string)$data['workDate'] : (string)($existing['work_date'] ?? ''),
        'additional_info' => isset($data['additionalInfo']) && $data['additionalInfo'] !== '' ? (string)$data['additionalInfo'] : (string)($existing['additional_info'] ?? ''),
        'supply_request' => isset($data['supplyRequest']) && $data['supplyRequest'] !== '' ? (string)$data['supplyRequest'] : (string)($existing['supply_request'] ?? ''),
        'supply_urgency' => isset($data['supplyUrgency']) && $data['supplyUrgency'] !== '' ? (string)$data['supplyUrgency'] : (string)($existing['supply_urgency'] ?? 'medium'),
        'supply_due_date' => isset($data['supplyDueDate']) && $data['supplyDueDate'] !== '' ? (string)$data['supplyDueDate'] : (string)($existing['supply_due_date'] ?? ''),

        'client_token' => (string)($data['clientToken'] ?? $data['client_token'] ?? $data['token'] ?? $existing['client_token'] ?? ''),
        'viewed_at' => (string)($data['viewedAt'] ?? $data['viewed_at'] ?? ($existing['viewed_at'] ?? '')),
        'signed_at' => (string)($data['signedAt'] ?? $data['signed_at'] ?? ($existing['signed_at'] ?? '')),
        'order_id' => trim((string)($data['orderId'] ?? $data['order_id'] ?? $existing['order_id'] ?? '')),
        'client_id' => isset($existing['client_id']) ? (int)$existing['client_id'] : null,
        'parts_purchase_total' => array_key_exists('partsPurchaseTotal', $data) || array_key_exists('parts_purchase_total', $data)
            ? fixarivan_optional_float($data['partsPurchaseTotal'] ?? $data['parts_purchase_total'] ?? null)
            : fixarivan_optional_float($existing['parts_purchase_total'] ?? null),
        'parts_sale_total' => array_key_exists('partsSaleTotal', $data) || array_key_exists('parts_sale_total', $data)
            ? fixarivan_optional_float($data['partsSaleTotal'] ?? $data['parts_sale_total'] ?? null)
            : fixarivan_optional_float($existing['parts_sale_total'] ?? null),

        'order_type' => trim((string)($data['orderType'] ?? $data['order_type'] ?? $existing['order_type'] ?? 'repair')),
        'public_comment' => trim((string)($data['publicComment'] ?? $data['public_comment'] ?? $existing['public_comment'] ?? '')),
        'public_expected_date' => trim((string)($data['publicExpectedDate'] ?? $data['public_expected_date'] ?? $existing['public_expected_date'] ?? '')),
        'public_estimated_cost' => trim((string)($data['publicEstimatedCost'] ?? $data['public_estimated_cost'] ?? $existing['public_estimated_cost'] ?? '')),
        'estimated_labor_cost' => fixarivan_resolve_estimated_labor_cost($data, $existing),
        'internal_comment' => array_key_exists('internalComment', $data) || array_key_exists('internal_comment', $data)
            ? trim((string)($data['internalComment'] ?? $data['internal_comment'] ?? ''))
            : trim((string)($existing['internal_comment'] ?? '')),
        'order_lines_json' => fixarivan_order_lines_json_encode($data, $existing),

        'public_status' => fixarivan_resolve_public_order_status_for_normalize($data, $existing),
        'order_status' => fixarivan_resolve_public_order_status_for_normalize($data, $existing),
        'parts_status' => array_key_exists('partsStatus', $data) || array_key_exists('parts_status', $data)
            ? fixarivan_normalize_parts_status($data['partsStatus'] ?? $data['parts_status'] ?? null)
            : fixarivan_normalize_parts_status(($existing ?? [])['parts_status'] ?? null),
    ];
}

function mergeOrderRecord(array $old, array $new): array {
    // Override only non-empty values (so client signature updates don't wipe master info)
    $merged = $old;
    foreach ($new as $k => $v) {
        $isEmpty = $v === null || $v === '' || (is_array($v) && $v === []);
        if (!$isEmpty) {
            $merged[$k] = $v;
        }
    }
    foreach (['parts_purchase_total', 'parts_sale_total', 'estimated_labor_cost'] as $fk) {
        if (array_key_exists($fk, $new)) {
            $merged[$fk] = $new[$fk];
        }
    }
    foreach (['order_type', 'public_status', 'public_comment', 'public_expected_date', 'internal_comment', 'order_lines_json', 'order_status', 'parts_status', 'supply_request', 'supply_urgency'] as $fk) {
        if (array_key_exists($fk, $new)) {
            $merged[$fk] = $new[$fk];
        }
    }
    if (!empty($old['client_token']) && trim((string)$old['client_token']) !== '') {
        $merged['client_token'] = trim((string)$old['client_token']);
    }
    // Ensure id fields always present
    if (!empty($new['document_id'])) $merged['document_id'] = $new['document_id'];
    return $merged;
}

function saveOrderFixed(array $data): array {
    $isMasterForm = !empty($data['isMasterForm']);

    if ($isMasterForm) {
        $linesForTotals = fixarivan_parse_order_lines_array($data['orderLines'] ?? $data['order_lines'] ?? null);
        if ($linesForTotals !== []) {
            [$pTot, $sTot] = fixarivan_totals_from_order_lines($linesForTotals);
            if (!array_key_exists('partsPurchaseTotal', $data) && !array_key_exists('parts_purchase_total', $data)) {
                $data['partsPurchaseTotal'] = $pTot;
            }
            if (!array_key_exists('partsSaleTotal', $data) && !array_key_exists('parts_sale_total', $data)) {
                $data['partsSaleTotal'] = $sTot;
            }
        }
        $mode = strtolower(trim((string)($data['orderMode'] ?? $data['order_type'] ?? 'repair')));
        if (!in_array($mode, ['repair', 'sale', 'custom'], true)) {
            $mode = 'repair';
        }
        $data['orderType'] = $mode;
        if (in_array($mode, ['sale', 'custom'], true)) {
            if (trim((string)($data['deviceModel'] ?? '')) === '') {
                $data['deviceModel'] = '—';
            }
            if (trim((string)($data['problemDescription'] ?? '')) === '') {
                $data['problemDescription'] = '—';
            }
        }
    }

    $inputDocumentId = trim((string)($data['documentId'] ?? ''));
    $inputClientToken = trim((string)($data['clientToken'] ?? $data['client_token'] ?? $data['token'] ?? ''));

    $documentId = $inputDocumentId;

    // Load existing JSON to merge master/client updates
    $oldRecord = [];
    if ($documentId !== '') {
        $jsonPath = fixarivan_orders_storage_dir() . DIRECTORY_SEPARATOR . $documentId . '.json';
        if (is_file($jsonPath)) {
            $raw = file_get_contents($jsonPath);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $oldRecord = $decoded;
                }
            }
        }
    } elseif ($inputClientToken !== '') {
        $oldRecord = loadOrderJsonByClientToken($inputClientToken);
        if (is_array($oldRecord) && !empty($oldRecord['document_id'])) {
            $documentId = (string)$oldRecord['document_id'];
        }
    }

    if (!$isMasterForm && $documentId === '' && $inputClientToken !== '') {
        // As a last resort, try SQLite lookup by token (optional MVP).
        try {
            $pdo = getSqliteConnection();
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE client_token = :t LIMIT 1');
            $stmt->execute([':t' => $inputClientToken]);
            $row = $stmt->fetch();
            if (is_array($row) && $row !== []) {
                $oldRecord = $row;
                $documentId = (string)($row['document_id'] ?? '');
            }
        } catch (Throwable $e) {
            // Ignore SQLite issues.
        }
    }

    if ($documentId === '') {
        return ['success' => false, 'message' => 'Некорректный documentId'];
    }

    // Master: generate token & set status.
    if ($isMasterForm) {
        $existingTok = trim((string)($oldRecord['client_token'] ?? ''));
        if ($existingTok !== '') {
            $data['clientToken'] = $existingTok;
        }
        $clientToken = trim((string)($oldRecord['client_token'] ?? ''));
        if ($clientToken === '') {
            $clientToken = $inputClientToken !== '' ? $inputClientToken : fixarivan_generate_client_token();
        }

        // Don't overwrite "signed" with "sent_to_client" if the client already signed.
        $oldStatus = trim((string)($oldRecord['status'] ?? ''));
        $status = $oldStatus === 'signed' ? 'signed' : 'sent_to_client';

        $data['clientToken'] = $clientToken;
        $data['status'] = $status;
        if (empty($oldRecord['viewed_at'])) {
            $data['viewedAt'] = '';
        }
        if (empty($oldRecord['signed_at'])) {
            $data['signedAt'] = '';
        }
    } else {
        // Client actions:
        // - view: status=viewed
        // - sign: status=signed + signatureData
        if ($inputClientToken === '' && !empty($oldRecord['client_token'])) {
            $data['clientToken'] = (string)$oldRecord['client_token'];
        }
        if (!empty($data['signatureData']) || (isset($data['status']) && $data['status'] === 'signed')) {
            $data['status'] = 'signed';
            $data['signedAt'] = date('c');
        } elseif (isset($data['status']) && $data['status'] === 'viewed') {
            $data['viewedAt'] = date('c');
        }
    }

    if ($documentId !== '') {
        try {
            $pdoPre = getSqliteConnection();
            $st = $pdoPre->prepare('SELECT parts_purchase_total, parts_sale_total FROM orders WHERE document_id = :d LIMIT 1');
            $st->execute([':d' => $documentId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($r)) {
                if (!array_key_exists('partsPurchaseTotal', $data) && !array_key_exists('parts_purchase_total', $data)) {
                    if (isset($r['parts_purchase_total']) && $r['parts_purchase_total'] !== null && $r['parts_purchase_total'] !== '') {
                        $oldRecord['parts_purchase_total'] = $r['parts_purchase_total'];
                    }
                }
                if (!array_key_exists('partsSaleTotal', $data) && !array_key_exists('parts_sale_total', $data)) {
                    if (isset($r['parts_sale_total']) && $r['parts_sale_total'] !== null && $r['parts_sale_total'] !== '') {
                        $oldRecord['parts_sale_total'] = $r['parts_sale_total'];
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $newRecord = normalizeOrderRecord($data, $oldRecord);
    $merged = $oldRecord ? mergeOrderRecord($oldRecord, $newRecord) : $newRecord;
    if ($isMasterForm && !empty($oldRecord['client_token']) && trim((string)$oldRecord['client_token']) !== '') {
        $merged['client_token'] = trim((string)$oldRecord['client_token']);
    }
    // Keep a raw payload backup for recovery/debugging (JSON fallback).
    $merged['raw_json'] = $data;

    // Заявка на закупку и склад: единый источник — позиции заказа (order_lines_json).
    $linesParsedSupply = fixarivan_parse_order_lines_array($merged['order_lines_json'] ?? '[]');
    if ($linesParsedSupply !== []) {
        $merged['supply_request'] = fixarivan_supply_request_from_order_lines($linesParsedSupply);
        $merged['supply_derived_from'] = 'order_lines';
    }

    try {
        $token = trim((string)($merged['client_token'] ?? $inputClientToken));
        fixarivan_save_order_json_files($merged, $documentId, $token);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    $sqliteWarning = null;
    $supplyWarning = null;
    $warehouseWarning = null;
    // SQLite: клиент + заказ в одной транзакции; синхронизация supply — отдельно (не откатывает заказ).
    try {
        $pdo = getSqliteConnection();
        $merged['order_lines_json'] = fixarivan_prepare_order_lines_json_for_persist(
            $pdo,
            fixarivan_normalize_order_lines_json_for_sync($merged['order_lines_json'] ?? '[]')
        );
        $pdo->beginTransaction();
        try {
            $resolvedClientId = fixarivan_ensure_client(
                $pdo,
                (string)($merged['client_name'] ?? ''),
                (string)($merged['client_phone'] ?? ''),
                (string)($merged['client_email'] ?? '')
            );
            if ($isMasterForm && $resolvedClientId === null) {
                throw new RuntimeException(
                    'Клиент не создан в SQLite: пустое ФИО после нормализации. Заполните имя (не только пробелы).'
                );
            }

            $resolvedOrderId = fixarivan_resolve_order_id_for_document(
                $pdo,
                (string)($merged['document_id'] ?? ''),
                (string)($merged['order_id'] ?? '')
            );
            $orderIdForDb = trim((string)$resolvedOrderId);
            if ($orderIdForDb === '') {
                $orderIdForDb = $documentId;
            }
            $merged['client_id'] = $resolvedClientId;
            $merged['order_id'] = $orderIdForDb;

            $stmt = $pdo->prepare(
            'INSERT INTO orders (
                document_id, date_created, date_updated, place_of_acceptance, date_of_acceptance, unique_code, language,
                client_name, client_phone, client_email,
                device_model, device_serial, device_type, device_condition, accessories, device_password,
                problem_description, priority, status,
                technician_name, work_date,
                pattern_data, client_signature,
                client_token, viewed_at, signed_at, order_id, client_id,
                parts_purchase_total, parts_sale_total,
                order_type, public_status, public_comment, public_expected_date, public_estimated_cost, estimated_labor_cost, internal_comment, order_lines_json,
                order_status, parts_status, supply_request, supply_urgency
            ) VALUES (
                :document_id, :date_created, :date_updated, :place_of_acceptance, :date_of_acceptance, :unique_code, :language,
                :client_name, :client_phone, :client_email,
                :device_model, :device_serial, :device_type, :device_condition, :accessories, :device_password,
                :problem_description, :priority, :status,
                :technician_name, :work_date,
                :pattern_data, :client_signature,
                :client_token, :viewed_at, :signed_at, :order_id, :client_id,
                :parts_purchase_total, :parts_sale_total,
                :order_type, :public_status, :public_comment, :public_expected_date, :public_estimated_cost, :estimated_labor_cost, :internal_comment, :order_lines_json,
                :order_status, :parts_status, :supply_request, :supply_urgency
            )
            ON CONFLICT(document_id) DO UPDATE SET
                date_updated=excluded.date_updated,
                place_of_acceptance=excluded.place_of_acceptance,
                date_of_acceptance=excluded.date_of_acceptance,
                unique_code=excluded.unique_code,
                language=excluded.language,
                client_name=excluded.client_name,
                client_phone=excluded.client_phone,
                client_email=excluded.client_email,
                device_model=excluded.device_model,
                device_serial=excluded.device_serial,
                device_type=excluded.device_type,
                device_condition=excluded.device_condition,
                accessories=excluded.accessories,
                device_password=excluded.device_password,
                problem_description=excluded.problem_description,
                priority=excluded.priority,
                status=excluded.status,
                technician_name=excluded.technician_name,
                work_date=excluded.work_date,
                pattern_data=excluded.pattern_data,
                client_signature=excluded.client_signature,
                client_token=COALESCE(NULLIF(orders.client_token, \'\'), excluded.client_token),
                viewed_at=excluded.viewed_at,
                signed_at=excluded.signed_at,
                order_id=excluded.order_id,
                client_id=excluded.client_id,
                parts_purchase_total=excluded.parts_purchase_total,
                parts_sale_total=excluded.parts_sale_total,
                order_type=excluded.order_type,
                public_status=excluded.public_status,
                public_comment=excluded.public_comment,
                public_expected_date=excluded.public_expected_date,
                public_estimated_cost=excluded.public_estimated_cost,
                estimated_labor_cost=excluded.estimated_labor_cost,
                internal_comment=excluded.internal_comment,
                order_lines_json=excluded.order_lines_json,
                order_status=excluded.order_status,
                parts_status=excluded.parts_status,
                supply_request=excluded.supply_request,
                supply_urgency=excluded.supply_urgency'
        );

            $stmt->execute([
                ':document_id' => $merged['document_id'],
                ':date_created' => $merged['date_created'],
                ':date_updated' => $merged['date_updated'],
                ':place_of_acceptance' => $merged['place_of_acceptance'],
                ':date_of_acceptance' => $merged['date_of_acceptance'],
                ':unique_code' => $merged['unique_code'],
                ':language' => $merged['language'],
                ':client_name' => $merged['client_name'],
                ':client_phone' => $merged['client_phone'],
                ':client_email' => $merged['client_email'],
                ':device_model' => $merged['device_model'],
                ':device_serial' => $merged['device_serial'],
                ':device_type' => $merged['device_type'],
                ':device_condition' => $merged['device_condition'],
                ':accessories' => $merged['accessories'],
                ':device_password' => $merged['device_password'],
                ':problem_description' => $merged['problem_description'],
                ':priority' => $merged['priority'],
                ':status' => $merged['status'],
                ':technician_name' => $merged['technician_name'],
                ':work_date' => $merged['work_date'],
                ':pattern_data' => $merged['pattern_data'],
                ':client_signature' => $merged['client_signature'],
                ':client_token' => $merged['client_token'] ?? $inputClientToken,
                ':viewed_at' => $merged['viewed_at'] ?? '',
                ':signed_at' => $merged['signed_at'] ?? '',
                ':order_id' => $orderIdForDb,
                ':client_id' => $merged['client_id'] ?? null,
                ':parts_purchase_total' => $merged['parts_purchase_total'] ?? null,
                ':parts_sale_total' => $merged['parts_sale_total'] ?? null,
                ':order_type' => $merged['order_type'] ?? 'repair',
                ':public_status' => fixarivan_normalize_public_status($merged['public_status'] ?? null),
                ':public_comment' => $merged['public_comment'] ?? '',
                ':public_expected_date' => $merged['public_expected_date'] ?? '',
                ':public_estimated_cost' => $merged['public_estimated_cost'] ?? '',
                ':estimated_labor_cost' => $merged['estimated_labor_cost'] ?? null,
                ':internal_comment' => $merged['internal_comment'] ?? '',
                ':order_lines_json' => fixarivan_normalize_order_lines_json_for_sync($merged['order_lines_json'] ?? '[]'),
                ':order_status' => fixarivan_normalize_public_status($merged['order_status'] ?? $merged['public_status'] ?? null),
                ':parts_status' => fixarivan_normalize_parts_status($merged['parts_status'] ?? null),
                ':supply_request' => (string)($merged['supply_request'] ?? ''),
                ':supply_urgency' => trim((string)($merged['supply_urgency'] ?? 'medium')) !== ''
                    ? trim((string)($merged['supply_urgency'] ?? 'medium'))
                    : 'medium',
            ]);

            $verify = $pdo->prepare('SELECT id, client_id FROM orders WHERE document_id = :d LIMIT 1');
            $verify->execute([':d' => $merged['document_id']]);
            $verifyRow = $verify->fetch(PDO::FETCH_ASSOC);
            if (!is_array($verifyRow) || $verifyRow === []) {
                throw new RuntimeException('Заказ не найден в SQLite после сохранения (document_id).');
            }
            if ($isMasterForm && (int)($verifyRow['client_id'] ?? 0) <= 0) {
                throw new RuntimeException('Связь orders.client_id с clients не установлена — запись отклонена.');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $oidForReq = trim((string)($merged['order_id'] ?? ''));
        if ($oidForReq === '') {
            $oidForReq = trim((string)($merged['document_id'] ?? ''));
        }
        $jsonForWh = fixarivan_normalize_order_lines_json_for_sync($merged['order_lines_json'] ?? '[]');
        $cidWh = isset($merged['client_id']) ? (int)$merged['client_id'] : null;
        $linesParsedWh = fixarivan_parse_order_lines_array($merged['order_lines_json'] ?? '[]');
        $hasWarehouseLines = $linesParsedWh !== [];

        if ($hasWarehouseLines) {
            try {
                fixarivan_sync_order_warehouse(
                    $pdo,
                    $oidForReq,
                    $cidWh,
                    $jsonForWh,
                    (string)($merged['public_expected_date'] ?? '')
                );
                fixarivan_sync_order_purchase_lines_to_inventory(
                    $pdo,
                    $oidForReq,
                    $linesParsedWh,
                    (string)($merged['device_model'] ?? '')
                );
                $enrichedJson = fixarivan_enrich_order_lines_json_from_owl($pdo, $oidForReq, $jsonForWh);
                if ($enrichedJson !== $jsonForWh) {
                    $nowUp = date('c');
                    $pdo->prepare('UPDATE orders SET order_lines_json = :j, date_updated = :u WHERE document_id = :d')->execute([
                        ':j' => $enrichedJson,
                        ':u' => $nowUp,
                        ':d' => $merged['document_id'],
                    ]);
                    $merged['order_lines_json'] = $enrichedJson;
                    try {
                        fixarivan_save_order_json_files($merged, $documentId, trim((string)($merged['client_token'] ?? $inputClientToken)));
                    } catch (Throwable $jsonEx) {
                    }
                }
                fixarivan_sync_order_warehouse(
                    $pdo,
                    $oidForReq,
                    $cidWh,
                    fixarivan_normalize_order_lines_json_for_sync($merged['order_lines_json'] ?? '[]'),
                    (string)($merged['public_expected_date'] ?? '')
                );
                fixarivan_create_supply_reminder(
                    $pdo,
                    $oidForReq,
                    fixarivan_order_lines_to_supply_items($linesParsedWh),
                    (string)($merged['supply_urgency'] ?? $merged['priority'] ?? 'medium'),
                    (string)($merged['public_expected_date'] ?? ''),
                    (string)($merged['client_name'] ?? ''),
                    (string)($merged['device_model'] ?? '')
                );
            } catch (Throwable $supplyEx) {
                $supplyWarning = $supplyEx->getMessage();
            }
        } else {
            try {
                fixarivan_sync_order_warehouse(
                    $pdo,
                    $oidForReq,
                    $cidWh,
                    '[]',
                    (string)($merged['public_expected_date'] ?? '')
                );
            } catch (Throwable $supplyEx) {
                $supplyWarning = $supplyEx->getMessage();
            }
        }

        try {
            fixarivan_recompute_order_parts_aggregate($pdo, (string)($merged['order_id'] ?? ''));
            $oidHook = trim((string)($merged['order_id'] ?? ''));
            if ($oidHook === '') {
                $oidHook = trim((string)($merged['document_id'] ?? ''));
            }
            fixarivan_on_order_terminal_public_status(
                $pdo,
                $oidHook,
                fixarivan_normalize_public_status($merged['public_status'] ?? $merged['order_status'] ?? null)
            );
        } catch (Throwable $whEx) {
            $warehouseWarning = $whEx->getMessage();
        }
    } catch (Throwable $e) {
        $sqliteWarning = $e->getMessage();
    }

    // SQLite is the primary storage for documents used by dashboard/track/clients.
    // If SQLite write failed, return an explicit error instead of silent success.
    if ($sqliteWarning !== null) {
        return [
            'success' => false,
            'message' => 'SQLite save failed for order',
            'document_id' => $documentId,
            'order_id' => $merged['order_id'] ?? $documentId,
            'client_id' => $merged['client_id'] ?? null,
            'client_token' => trim((string)($merged['client_token'] ?? $inputClientToken)),
            'storage' => 'storage/orders',
            'sqlite_warning' => $sqliteWarning,
        ];
    }

    $sdw = fixarivan_supply_missing_expected_date_warning(
        (string)($merged['supply_request'] ?? ''),
        (string)($merged['public_expected_date'] ?? ''),
        fixarivan_normalize_order_lines_json_for_sync($merged['order_lines_json'] ?? '[]')
    );
    if ($sdw !== null) {
        $supplyWarning = ($supplyWarning !== null && $supplyWarning !== '' ? $supplyWarning . ' ' : '') . $sdw;
    }

    return [
        'success' => true,
        'message' => 'Заказ сохранён (JSON backup + SQLite)',
        'document_id' => $documentId,
        'order_id' => $merged['order_id'] ?? $documentId,
        'client_id' => $merged['client_id'] ?? null,
        'client_token' => trim((string)($merged['client_token'] ?? $inputClientToken)),
        'storage' => 'storage/orders',
        'sqlite_warning' => $sqliteWarning,
        'supply_warning' => $supplyWarning,
        'warehouse_warning' => $warehouseWarning,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные JSON']);
        exit;
    }

    // Master saves require admin PHP session; client viewer updates use token only (TOKEN-ONLY tier).
    $isMasterForm = !empty($input['isMasterForm']);
    if ($isMasterForm) {
        require_once __DIR__ . '/lib/require_admin_session.php';
    }

    if ($isMasterForm) {
        foreach (['documentId', 'clientName', 'clientPhone', 'clientEmail', 'deviceModel', 'problemDescription', 'placeOfAcceptance', 'dateOfAcceptance'] as $k) {
            if (isset($input[$k]) && is_string($input[$k])) {
                $input[$k] = trim($input[$k]);
            }
        }
    }

    // Валидация обязательных полей:
    // - master: full payload (repair) или sale/custom + позиции
    // - client: token + signature/status (minimal, no documentId access)
    if ($isMasterForm) {
        $orderMode = strtolower(trim((string)($input['orderMode'] ?? $input['order_type'] ?? 'repair')));
        if (!in_array($orderMode, ['repair', 'sale', 'custom'], true)) {
            $orderMode = 'repair';
        }
        $linesCheck = fixarivan_parse_order_lines_array($input['orderLines'] ?? $input['order_lines'] ?? null);

        if (in_array($orderMode, ['sale', 'custom'], true)) {
            foreach (['documentId', 'clientName', 'clientPhone'] as $field) {
                if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                    echo json_encode(['success' => false, 'message' => "Обязательное поле '$field' не заполнено"]);
                    exit;
                }
            }
            if ($linesCheck === []) {
                echo json_encode(['success' => false, 'message' => 'Для типа заказа sale/custom укажите хотя бы одну позицию (orderLines)']);
                exit;
            }
        } else {
            foreach (['documentId', 'clientName', 'clientPhone'] as $field) {
                if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                    echo json_encode(['success' => false, 'message' => "Обязательное поле '$field' не заполнено"]);
                    exit;
                }
            }
        }
    } else {
        $clientToken = trim((string)($input['clientToken'] ?? $input['client_token'] ?? $input['token'] ?? ''));
        $documentId = trim((string)($input['documentId'] ?? ''));
        $status = trim((string)($input['status'] ?? ''));
        $signatureData = (string)($input['signatureData'] ?? '');

        if ($clientToken === '') {
            echo json_encode(['success' => false, 'message' => 'Нужен token (clientToken) для клиентских действий']);
            exit;
        }
        if ($status === 'signed' || $signatureData !== '') {
            if ($signatureData === '') {
                echo json_encode(['success' => false, 'message' => 'Для подписи требуется signatureData']);
                exit;
            }
        }
        if ($status !== 'viewed' && $status !== 'signed' && $signatureData === '') {
            // No-op guard: client must either mark viewed or sign.
            echo json_encode(['success' => false, 'message' => 'Нужно указать status=viewed или status=signed (или передать signatureData)']);
            exit;
        }
    }
    
    $result = saveOrderFixed($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>