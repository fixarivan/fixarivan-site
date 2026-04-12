<?php
/**
 * УЛЬТИМАТИВНЫЙ API ДЛЯ КВИТАНЦИЙ
 * Работает с любой структурой БД
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/client_token.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/order_supply.php';

function helsinki_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'));
}

function receiptsStorageDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'receipts';
}

function receiptsTokensStorageDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'receipts_tokens';
}

function ensureDir(string $dir): void {
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create dir: {$dir}");
    }
}

function saveReceiptTokenJson(array $record, string $clientToken): void {
    $clientToken = trim($clientToken);
    if ($clientToken === '') {
        throw new RuntimeException('Некорректный client_token');
    }

    $dir = receiptsTokensStorageDir();
    ensureDir($dir);
    $jsonPath = $dir . DIRECTORY_SEPARATOR . $clientToken . '.json';
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new RuntimeException('Ошибка сериализации JSON квитанции (token)');
    }
    if (file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить JSON квитанции (token)');
    }
}

function normalizeReceiptRecord(array $data, ?array $existing = null): array {
    $nowIso = helsinki_now()->format('c');
    $dateCreated = $existing['date_created'] ?? ($existing['dateCreated'] ?? $nowIso);

    $servicesRendered = $data['servicesRendered'] ?? $data['services_rendered'] ?? $data['services'] ?? ($existing['services_rendered'] ?? null);
    if (is_array($servicesRendered)) {
        // Render array of services objects into a readable string
        // expected item shape from UI: { name, description, price }
        $servicesRendered = implode("\n", array_map(static function ($item): string {
            if (is_array($item)) {
                $name = (string)($item['name'] ?? '');
                $desc = (string)($item['description'] ?? '');
                $price = $item['price'] ?? null;
                $priceText = $price === null || $price === '' ? '' : (' - ' . (string)$price);
                $line = trim($name . ($desc !== '' ? ' (' . $desc . ')' : '') . $priceText);
                return $line !== '' ? $line : '—';
            }
            return (string)$item;
        }, $servicesRendered));
    }

    $paymentMethodRaw = (string)($data['paymentMethod'] ?? $data['payment_method'] ?? $existing['payment_method'] ?? '');
    $paymentMethodMap = [
        'transfer' => 'bank_transfer',
        'mobile' => 'mobilepay',
    ];
    $paymentMethod = $paymentMethodMap[$paymentMethodRaw] ?? $paymentMethodRaw;
    $allowedPaymentMethods = ['holvi_terminal', 'cash', 'bank_transfer', 'card', 'mobilepay', 'other'];
    if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $paymentMethod = 'other';
    }

    $paymentStatus = (string)($data['paymentStatus'] ?? $data['payment_status'] ?? $existing['payment_status'] ?? 'paid');
    $allowedPaymentStatuses = ['paid', 'pending', 'partial', 'cancelled'];
    if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
        $paymentStatus = 'paid';
    }

    $totalAmount = (float)($data['totalAmount'] ?? $data['total_amount'] ?? ($existing['total_amount'] ?? 0.0));
    $amountPaid = null;
    if ($paymentStatus === 'partial') {
        $apRaw = $data['amountPaid'] ?? $data['amount_paid'] ?? ($existing['amount_paid'] ?? null);
        if ($apRaw !== null && $apRaw !== '') {
            if (is_numeric($apRaw)) {
                $amountPaid = (float)$apRaw;
            } else {
                $s = str_replace(',', '.', trim((string)$apRaw));
                if (is_numeric($s)) {
                    $amountPaid = (float)$s;
                }
            }
            if ($amountPaid !== null && $amountPaid < 0) {
                $amountPaid = 0.0;
            }
            if ($amountPaid !== null && $amountPaid > $totalAmount && $totalAmount > 0) {
                $amountPaid = $totalAmount;
            }
        }
    }

    return [
        'document_id' => trim((string)($data['documentId'] ?? $data['document_id'] ?? $existing['document_id'] ?? '')),
        'date_created' => (string)$dateCreated,
        'date_updated' => $nowIso,
        'place_of_acceptance' => (string)($data['placeOfAcceptance'] ?? $data['location'] ?? $existing['place_of_acceptance'] ?? 'Turku, Finland'),
        'date_of_acceptance' => (string)($data['dateOfAcceptance'] ?? $data['receiptDate'] ?? $data['paymentDate'] ?? $existing['date_of_acceptance'] ?? helsinki_now()->format('Y-m-d')),
        'unique_code' => isset($data['uniqueCode']) && $data['uniqueCode'] !== '' ? (string)$data['uniqueCode'] : (string)($existing['unique_code'] ?? ''),
        'language' => (string)($data['language'] ?? $existing['language'] ?? 'ru'),

        'client_name' => trim((string)($data['clientName'] ?? $data['client_name'] ?? $existing['client_name'] ?? '')),
        'client_phone' => trim((string)($data['clientPhone'] ?? $data['client_phone'] ?? $existing['client_phone'] ?? '')),
        'client_email' => (isset($data['clientEmail']) && $data['clientEmail'] !== '') ? (string)$data['clientEmail'] : (string)($existing['client_email'] ?? ''),

        'device_model' => trim((string)($data['deviceModel'] ?? $data['device_model'] ?? $existing['device_model'] ?? '')),

        'total_amount' => $totalAmount,
        'amount_paid' => $amountPaid,
        'payment_method' => $paymentMethod,
        'payment_status' => $paymentStatus,
        'payment_date' => (string)($data['paymentDate'] ?? $data['payment_date'] ?? $existing['payment_date'] ?? ''),
        'payment_note' => (string)($data['paymentNote'] ?? $data['payment_note'] ?? $existing['payment_note'] ?? ''),
        'services_rendered' => $servicesRendered === null ? null : (string)$servicesRendered,
        'notes' => (string)($data['notes'] ?? $existing['notes'] ?? ''),

        'client_signature' => (isset($data['signatureData']) && $data['signatureData'] !== '') ? (string)$data['signatureData'] : (string)($existing['client_signature'] ?? ''),
        'status' => (string)($data['status'] ?? $existing['status'] ?? 'completed'),

        'client_token' => (string)($data['clientToken'] ?? $data['client_token'] ?? $existing['client_token'] ?? ''),
        'receipt_number' => (string)($data['receiptNumber'] ?? $data['receipt_number'] ?? $existing['receipt_number'] ?? ''),
        'order_id' => trim((string)($data['orderId'] ?? $data['order_id'] ?? $existing['order_id'] ?? '')),
        /** document_id строки orders (акт), для привязки без подстановки «последнего заказа» */
        'order_document_id' => trim((string)($data['orderDocumentId'] ?? $data['order_document_id'] ?? $existing['order_document_id'] ?? '')),
    ];
}

function saveReceiptJson(array $record): void {
    $documentId = (string)($record['document_id'] ?? '');
    if ($documentId === '') {
        throw new RuntimeException('Не указан document_id для квитанции');
    }
    $dir = receiptsStorageDir();
    ensureDir($dir);
    $jsonPath = $dir . DIRECTORY_SEPARATOR . $documentId . '.json';
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new RuntimeException('Ошибка сериализации JSON квитанции');
    }
    if (file_put_contents($jsonPath, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить JSON квитанцию');
    }
}

function saveReceiptUltimate($data) {
    $documentId = trim((string)($data['documentId'] ?? $data['document_id'] ?? ''));
    if ($documentId === '') {
        return ['success' => false, 'message' => 'Не указан ID документа'];
    }

    // Optional existing record (not required for MVP, but helps merging signature)
    $old = [];
    $jsonPath = receiptsStorageDir() . DIRECTORY_SEPARATOR . $documentId . '.json';
    if (is_file($jsonPath)) {
        $raw = file_get_contents($jsonPath);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $old = $decoded;
        }
    }

    $record = normalizeReceiptRecord($data, $old);
    if (empty($record['client_name'])) {
        return ['success' => false, 'message' => 'Не указано имя клиента'];
    }

    // Raw payload backup for recovery/debugging (JSON fallback).
    $record['raw_json'] = $data;

    // MVP: generate token & human-readable receipt number on master save.
    $clientToken = trim((string)($record['client_token'] ?? ''));
    if ($clientToken === '') {
        $clientToken = fixarivan_generate_client_token();
    }
    $receiptNumber = trim((string)($record['receipt_number'] ?? ''));
    if ($receiptNumber === '') {
        // If documentId already looks like RCT-YYYY-xxxx, reuse it.
        $receiptNumber = (strpos($documentId, 'RCT-') === 0) ? $documentId : ('RCT-' . helsinki_now()->format('Y') . '-' . mt_rand(1000, 9999));
    }
    $record['client_token'] = $clientToken;
    $record['receipt_number'] = $receiptNumber;

    // SQLite is the single source of truth; JSON files are backup/fallback (see data_policy.php).
    $sqliteWarning = null;
    try {
        $pdo = getSqliteConnection();
        $resolved = fixarivan_resolve_client_and_order(
            $pdo,
            (string)$record['client_name'],
            (string)$record['client_phone'],
            (string)$record['client_email'],
            (string)($record['order_id'] ?? ''),
            (string)($record['order_document_id'] ?? '')
        );
        $record['order_id'] = $resolved['order_id'];

        $stmt = $pdo->prepare(
            'INSERT INTO receipts (
                document_id, date_created, date_updated, place_of_acceptance, date_of_acceptance, unique_code, language,
                client_name, client_phone, client_email, device_model,
                total_amount, amount_paid, payment_method, payment_status, payment_date, payment_note, services_rendered, notes,
                client_signature, status, client_token, receipt_number, order_id
            ) VALUES (
                :document_id, :date_created, :date_updated, :place_of_acceptance, :date_of_acceptance, :unique_code, :language,
                :client_name, :client_phone, :client_email, :device_model,
                :total_amount, :amount_paid, :payment_method, :payment_status, :payment_date, :payment_note, :services_rendered, :notes,
                :client_signature, :status, :client_token, :receipt_number, :order_id
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
                total_amount=excluded.total_amount,
                amount_paid=excluded.amount_paid,
                payment_method=excluded.payment_method,
                payment_status=excluded.payment_status,
                payment_date=excluded.payment_date,
                payment_note=excluded.payment_note,
                services_rendered=excluded.services_rendered,
                notes=excluded.notes,
                client_signature=excluded.client_signature,
                status=excluded.status,
                client_token=excluded.client_token,
                receipt_number=excluded.receipt_number,
                order_id=excluded.order_id'
        );

        $stmt->execute([
            ':document_id' => $record['document_id'],
            ':date_created' => $record['date_created'],
            ':date_updated' => $record['date_updated'],
            ':place_of_acceptance' => $record['place_of_acceptance'],
            ':date_of_acceptance' => $record['date_of_acceptance'],
            ':unique_code' => $record['unique_code'],
            ':language' => $record['language'],
            ':client_name' => $record['client_name'],
            ':client_phone' => $record['client_phone'],
            ':client_email' => $record['client_email'],
            ':device_model' => $record['device_model'],
            ':total_amount' => (float)$record['total_amount'],
            ':amount_paid' => isset($record['amount_paid']) && $record['amount_paid'] !== null ? (float)$record['amount_paid'] : null,
            ':payment_method' => $record['payment_method'],
            ':payment_status' => $record['payment_status'],
            ':payment_date' => $record['payment_date'],
            ':payment_note' => $record['payment_note'],
            ':services_rendered' => $record['services_rendered'],
            ':notes' => $record['notes'],
            ':client_signature' => $record['client_signature'],
            ':status' => $record['status'],
            ':client_token' => $record['client_token'],
            ':receipt_number' => $record['receipt_number'],
            ':order_id' => $record['order_id'] ?? null,
        ]);

        if (($record['payment_status'] ?? '') === 'paid' && trim((string)($record['client_signature'] ?? '')) !== '') {
            $oidForOrder = trim((string)($record['order_id'] ?? ''));
            if ($oidForOrder !== '') {
                try {
                    fixarivan_sync_order_status_after_paid_receipt($pdo, $oidForOrder);
                } catch (Throwable $orderEx) {
                    error_log('save_receipt_ultimate order status sync: ' . $orderEx->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        $sqliteWarning = $e->getMessage();
    }

    try {
        saveReceiptJson($record);
    } catch (Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }

    try {
        saveReceiptTokenJson($record, $clientToken);
    } catch (Throwable $e) {
        // Ignore token JSON issues.
    }

    return [
        'success' => true,
        'message' => 'Квитанция сохранена (SQLite + JSON backup)',
        'document_id' => $documentId,
        'order_id' => $record['order_id'] ?? null,
        'client_token' => $clientToken,
        'receipt_number' => $receiptNumber,
        'storage' => 'storage/receipts',
        'sqlite_warning' => $sqliteWarning,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
        exit;
    }
    
    $result = saveReceiptUltimate($input);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
}
?>
