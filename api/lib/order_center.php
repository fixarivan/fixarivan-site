<?php
declare(strict_types=1);

require_once __DIR__ . '/client_token.php';

/** Публичный статус заказа (TZ v3). */
function fixarivan_allowed_order_statuses(): array {
    return ['in_progress', 'waiting_parts', 'in_transit', 'done', 'delivered'];
}

/** Статус запчастей по заказу (TZ v3 + v4.4 агрегат по заказу). */
function fixarivan_allowed_parts_statuses(): array {
    return ['ordered', 'in_transit', 'arrived', 'installed', 'waiting', 'partial', 'ready'];
}

function fixarivan_normalize_order_status(?string $s): string {
    $t = trim((string)$s);
    return in_array($t, fixarivan_allowed_order_statuses(), true) ? $t : 'in_progress';
}

/** Публичный статус (TZ v4): те же значения, что и order_status; legacy-текст → enum. */
function fixarivan_normalize_public_status(?string $s): string {
    $t = trim((string)$s);
    if ($t === '') {
        return 'in_progress';
    }
    if (in_array($t, fixarivan_allowed_order_statuses(), true)) {
        return $t;
    }
    $lower = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    $legacy = [
        'в работе' => 'in_progress',
        'ожидание запчастей' => 'waiting_parts',
        'ожидает запчасть' => 'waiting_parts',
        'в пути' => 'in_transit',
        'готово' => 'done',
        'выдан' => 'delivered',
        'выдано' => 'delivered',
    ];

    return $legacy[$lower] ?? 'in_progress';
}

function fixarivan_normalize_parts_status(?string $s): ?string {
    $t = trim((string)$s);
    if ($t === '') {
        return null;
    }

    return in_array($t, fixarivan_allowed_parts_statuses(), true) ? $t : null;
}

function fixarivan_generate_order_document_id(): string {
    return 'ORD-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * Создаёт минимальный заказ (документ + order_id + токен). Без угадываний привязки.
 *
 * @return array{order_id: string, document_id: string, client_token: string, client_id: int}
 */
function fixarivan_create_minimal_order(PDO $pdo, ?int $clientId, string $name, string $phone, string $email, string $orderType = 'repair'): array {
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Нельзя создать заказ без имени клиента');
    }
    $ot = strtolower(trim($orderType));
    if (!in_array($ot, ['repair', 'sale', 'custom'], true)) {
        $ot = 'repair';
    }

    $cid = $clientId;
    if ($cid === null || $cid <= 0) {
        $resolved = fixarivan_ensure_client($pdo, $name, $phone, $email);
        if ($resolved === null) {
            throw new RuntimeException('Не удалось создать клиента в SQLite');
        }
        $cid = $resolved;
    }

    $documentId = fixarivan_generate_order_document_id();
    $orderId = $documentId;
    $token = fixarivan_generate_client_token();
    $now = date('c');
    $today = date('Y-m-d');

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
            order_status, parts_status
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
            :order_status, :parts_status
        )'
    );
    $stmt->execute([
        ':document_id' => $documentId,
        ':date_created' => $now,
        ':date_updated' => $now,
        ':place_of_acceptance' => 'Turku, Finland',
        ':date_of_acceptance' => $today,
        ':unique_code' => $orderId,
        ':language' => 'ru',
        ':client_name' => $name,
        ':client_phone' => trim($phone),
        ':client_email' => trim($email),
        ':device_model' => '',
        ':device_serial' => '',
        ':device_type' => '',
        ':device_condition' => '',
        ':accessories' => '',
        ':device_password' => '',
        ':problem_description' => '',
        ':priority' => 'normal',
        ':status' => 'pending',
        ':technician_name' => '',
        ':work_date' => '',
        ':pattern_data' => '',
        ':client_signature' => '',
        ':client_token' => $token,
        ':viewed_at' => '',
        ':signed_at' => '',
        ':order_id' => $orderId,
        ':client_id' => $cid,
        ':parts_purchase_total' => null,
        ':parts_sale_total' => null,
        ':order_type' => $ot,
        ':public_status' => 'in_progress',
        ':public_comment' => '',
        ':public_expected_date' => '',
        ':public_estimated_cost' => '',
        ':estimated_labor_cost' => null,
        ':internal_comment' => '',
        ':order_lines_json' => '[]',
        ':order_status' => 'in_progress',
        ':parts_status' => null,
    ]);

    return [
        'order_id' => $orderId,
        'document_id' => $documentId,
        'client_token' => $token,
        'client_id' => $cid,
    ];
}

function fixarivan_generate_client_id(): string {
    return 'CL-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function fixarivan_safe_lower(string $value): string {
    $v = trim($value);
    if ($v === '') return '';
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($v);
    }
    return strtolower($v);
}

function fixarivan_normalize_phone(string $phone): string {
    $d = preg_replace('/\D+/', '', $phone) ?? '';
    if ($d === '') {
        return '';
    }
    // Finland: leading 0 → 358 (mobile/landline national format)
    if (strlen($d) >= 9 && strlen($d) <= 12 && $d[0] === '0') {
        $d = '358' . substr($d, 1);
    }

    return $d;
}

/** Отображение: +358… из нормализованных цифр */
function fixarivan_format_phone_fi_display(string $digits): string {
    $d = trim($digits);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '358') && strlen($d) > 3) {
        return '+358 ' . substr($d, 3);
    }

    return '+' . $d;
}

function fixarivan_resolve_client_id(PDO $pdo, string $name, string $phone, string $email): ?int {
    $phoneNorm = fixarivan_normalize_phone($phone);
    if ($phoneNorm === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE phone = :p ORDER BY id ASC LIMIT 1');
    $stmt->execute([':p' => $phoneNorm]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int)$id : null;
}

function fixarivan_ensure_client(PDO $pdo, string $name, string $phone, string $email): ?int {
    $name = trim($name);
    if ($name === '') return null;

    $existingId = fixarivan_resolve_client_id($pdo, $name, $phone, $email);
    $phoneNorm = fixarivan_normalize_phone($phone);
    $emailNorm = fixarivan_safe_lower($email);
    $now = date('c');

    if ($existingId !== null) {
        $stmt = $pdo->prepare(
            'UPDATE clients SET full_name = :n, phone = :p, email = :e, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':n' => $name,
            ':p' => $phoneNorm !== '' ? $phoneNorm : null,
            ':e' => $emailNorm !== '' ? $emailNorm : null,
            ':u' => $now,
            ':id' => $existingId,
        ]);
        return $existingId;
    }

    $cid = fixarivan_generate_client_id();
    $stmt = $pdo->prepare(
        'INSERT INTO clients (client_id, full_name, phone, email, notes, created_at, updated_at)
         VALUES (:cid, :n, :p, :e, NULL, :c, :u)'
    );
    $stmt->execute([
        ':cid' => $cid,
        ':n' => $name,
        ':p' => $phoneNorm !== '' ? $phoneNorm : null,
        ':e' => $emailNorm !== '' ? $emailNorm : null,
        ':c' => $now,
        ':u' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

function fixarivan_resolve_order_id_for_document(PDO $pdo, string $documentId, ?string $providedOrderId = null): string {
    $provided = trim((string)$providedOrderId);
    if ($provided !== '') return $provided;

    $doc = trim($documentId);
    if ($doc === '') return '';

    $stmt = $pdo->prepare('SELECT order_id FROM orders WHERE document_id = :d LIMIT 1');
    $stmt->execute([':d' => $doc]);
    $orderId = trim((string)$stmt->fetchColumn());
    if ($orderId !== '') return $orderId;

    // Fallback for old data: use order document id as center id.
    return $doc;
}

/**
 * Резолв клиента и заказа для квитанций/счётов (TZ v4.4): новый заказ НЕ создаётся.
 * — Явный order_id (или document_id акта в первом аргументе): как раньше, без «последнего заказа».
 * — Только orderDocumentRef (document_id акта во втором ref): заказ ищется в orders; если у заказа
 *   уже есть client_id, он должен совпадать с обеспеченным клиентом (блок C v2-add: не гадать).
 *
 * @return array{client_id:?int,order_id:string}
 */
function fixarivan_resolve_client_and_order(PDO $pdo, string $name, string $phone, string $email, ?string $providedOrderId = null, ?string $orderDocumentRef = null): array {
    $clientId = fixarivan_ensure_client($pdo, $name, $phone, $email);
    $provided = trim((string)$providedOrderId);
    $docRef = trim((string)$orderDocumentRef);

    $lookupKey = $provided !== '' ? $provided : $docRef;
    if ($lookupKey === '') {
        return [
            'client_id' => $clientId,
            'order_id' => '',
        ];
    }

    $stmt = $pdo->prepare('SELECT order_id, document_id, client_id FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $stmt->execute([':o' => $lookupKey, ':d' => $lookupKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        return [
            'client_id' => $clientId,
            'order_id' => '',
        ];
    }

    $rowClientId = (int)($row['client_id'] ?? 0);
    if ($provided === '' && $docRef !== '' && $rowClientId > 0 && $rowClientId !== (int)$clientId) {
        return [
            'client_id' => $clientId,
            'order_id' => '',
        ];
    }

    $oid = trim((string)($row['order_id'] ?? ''));
    if ($oid === '') {
        $oid = trim((string)($row['document_id'] ?? ''));
    }

    return [
        'client_id' => $clientId,
        'order_id' => $oid,
    ];
}

/**
 * Отчёт диагностики: только существующий заказ (TZ v4.4). Без создания заказа.
 *
 * @return array{order_id:string,row:array<string,mixed>}
 */
function fixarivan_require_existing_order_for_report(PDO $pdo, string $orderIdRaw): array {
    $orderIdRaw = trim($orderIdRaw);
    if ($orderIdRaw === '') {
        throw new RuntimeException('Укажите order_id существующего заказа (отчёт привязывается только к заказу).');
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1');
    $stmt->execute([':o' => $orderIdRaw, ':d' => $orderIdRaw]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        throw new RuntimeException('Заказ не найден: ' . $orderIdRaw);
    }

    $oid = trim((string)($row['order_id'] ?? ''));
    if ($oid === '') {
        $oid = trim((string)($row['document_id'] ?? ''));
    }

    return ['order_id' => $oid, 'row' => $row];
}
