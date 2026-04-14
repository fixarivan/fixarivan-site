<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/invoice_center.php';
require_once __DIR__ . '/lib/invoice_validation.php';
require_once __DIR__ . '/lib/client_token.php';
require_once __DIR__ . '/lib/site_url.php';

function invoicesStorageDir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'invoices';
}

function ensureInvoiceDir(string $dir): void {
    if (is_dir($dir)) return;
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create dir: {$dir}");
    }
}

function saveInvoiceJson(array $record): void {
    $documentId = (string)($record['document_id'] ?? '');
    if ($documentId === '') {
        throw new RuntimeException('invoice document_id is required');
    }
    $dir = invoicesStorageDir();
    ensureInvoiceDir($dir);
    $path = $dir . DIRECTORY_SEPARATOR . $documentId . '.json';
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        throw new RuntimeException('cannot encode invoice json');
    }
    if (file_put_contents($path, $encoded, LOCK_EX) === false) {
        throw new RuntimeException('cannot write invoice json');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_send(false, null, 'Метод не поддерживается', ['Only POST is allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    api_json_send(false, null, 'Некорректный JSON', ['Invalid JSON body']);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $documentId = trim((string)($input['documentId'] ?? $input['document_id'] ?? ''));
    $old = [];
    if ($documentId !== '') {
        $s = $pdo->prepare('SELECT * FROM invoices WHERE document_id = :id LIMIT 1');
        $s->execute([':id' => $documentId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $old = $row;
        }
    }

    $input = fixarivan_invoice_normalize_input($input);
    $validation = fixarivan_invoice_validate($input, $old);
    if (!$validation['ok']) {
        http_response_code(422);
        api_json_send(false, null, 'Validation failed', $validation['errors']);
        exit;
    }

    $record = fixarivan_normalize_invoice_record($input, $old);
    $record = fixarivan_invoice_finalize_payment_fields(
        $record,
        new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'))
    );
    if ($record['client_name'] === '') {
        api_json_send(false, null, 'Не указано имя клиента', ['client_name is required']);
        exit;
    }
    if ($record['document_id'] === '') {
        $record['document_id'] = 'INV-DOC-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    if ($record['invoice_id'] === '') {
        $record['invoice_id'] = fixarivan_next_invoice_id($pdo);
    }
    $record['client_token'] = trim((string)($input['clientToken'] ?? $input['client_token'] ?? ($old['client_token'] ?? '')));
    if ($record['client_token'] === '') {
        $record['client_token'] = fixarivan_generate_client_token();
    }

    $orderDocRef = trim((string)($input['orderDocumentId'] ?? $input['order_document_id'] ?? ''));
    $resolved = fixarivan_resolve_client_and_order(
        $pdo,
        (string)$record['client_name'],
        (string)$record['client_phone'],
        (string)$record['client_email'],
        (string)$record['order_id'],
        $orderDocRef
    );
    $record['client_id'] = $resolved['client_id'] ?: null;
    $record['order_id'] = (string)($resolved['order_id'] ?? '');

    if (!empty($input['removeInvoiceLogo'])) {
        fixarivan_invoice_delete_logo_file($record['invoice_logo'] ?? '');
        $record['invoice_logo'] = '';
    } elseif (!empty($input['invoiceLogoDataUrl']) && is_string($input['invoiceLogoDataUrl'])) {
        $savedPath = fixarivan_invoice_save_logo_from_data_url((string)$record['document_id'], $input['invoiceLogoDataUrl']);
        if ($savedPath !== null) {
            if (($record['invoice_logo'] ?? '') !== '' && $record['invoice_logo'] !== $savedPath) {
                fixarivan_invoice_delete_logo_file($record['invoice_logo']);
            }
            $record['invoice_logo'] = $savedPath;
        }
    }

    $cleanInput = $input;
    unset($cleanInput['invoiceLogoDataUrl']);
    $record['raw_json'] = $cleanInput;

    $stmt = $pdo->prepare(
        'INSERT INTO invoices (
            document_id, invoice_id, order_id, client_id, date_created, date_updated, due_date, status, language,
            client_name, client_phone, client_email, service_object, service_address, items_json, subtotal, tax_rate, tax_amount,
            total_amount, payment_terms, note, raw_json, client_token, invoice_logo, payment_date, payment_method
        ) VALUES (
            :document_id, :invoice_id, :order_id, :client_id, :date_created, :date_updated, :due_date, :status, :language,
            :client_name, :client_phone, :client_email, :service_object, :service_address, :items_json, :subtotal, :tax_rate, :tax_amount,
            :total_amount, :payment_terms, :note, :raw_json, :client_token, :invoice_logo, :payment_date, :payment_method
        ) ON CONFLICT(document_id) DO UPDATE SET
            invoice_id=excluded.invoice_id,
            order_id=excluded.order_id,
            client_id=excluded.client_id,
            date_updated=excluded.date_updated,
            due_date=excluded.due_date,
            status=excluded.status,
            language=excluded.language,
            client_name=excluded.client_name,
            client_phone=excluded.client_phone,
            client_email=excluded.client_email,
            service_object=excluded.service_object,
            service_address=excluded.service_address,
            items_json=excluded.items_json,
            subtotal=excluded.subtotal,
            tax_rate=excluded.tax_rate,
            tax_amount=excluded.tax_amount,
            total_amount=excluded.total_amount,
            payment_terms=excluded.payment_terms,
            note=excluded.note,
            raw_json=excluded.raw_json,
            client_token=excluded.client_token,
            invoice_logo=excluded.invoice_logo,
            payment_date=excluded.payment_date,
            payment_method=excluded.payment_method'
    );
    $stmt->execute([
        ':document_id' => $record['document_id'],
        ':invoice_id' => $record['invoice_id'],
        ':order_id' => $record['order_id'] !== '' ? $record['order_id'] : null,
        ':client_id' => $record['client_id'] !== '' ? (int)$record['client_id'] : null,
        ':date_created' => $record['date_created'],
        ':date_updated' => $record['date_updated'],
        ':due_date' => $record['due_date'],
        ':status' => $record['status'],
        ':language' => $record['language'],
        ':client_name' => $record['client_name'],
        ':client_phone' => $record['client_phone'],
        ':client_email' => $record['client_email'],
        ':service_object' => $record['service_object'],
        ':service_address' => $record['service_address'] !== '' ? $record['service_address'] : null,
        ':items_json' => json_encode($record['items'], JSON_UNESCAPED_UNICODE),
        ':subtotal' => (float)$record['subtotal'],
        ':tax_rate' => (float)$record['tax_rate'],
        ':tax_amount' => (float)$record['tax_amount'],
        ':total_amount' => (float)$record['total_amount'],
        ':payment_terms' => $record['payment_terms'],
        ':note' => $record['note'],
        ':raw_json' => json_encode($record['raw_json'], JSON_UNESCAPED_UNICODE),
        ':client_token' => $record['client_token'],
        ':invoice_logo' => $record['invoice_logo'] !== '' ? $record['invoice_logo'] : null,
        ':payment_date' => $record['payment_date'] !== '' ? $record['payment_date'] : null,
        ':payment_method' => $record['payment_method'] !== '' ? $record['payment_method'] : null,
    ]);

    saveInvoiceJson($record);
    $tok = (string) $record['client_token'];
    $viewerUrl = fixarivan_absolute_url('invoice_view.php?token=' . rawurlencode($tok));
    $portalUrl = fixarivan_absolute_url('client_portal.php?token=' . rawurlencode($tok));
    api_json_send(true, [
        'document_id' => $record['document_id'],
        'invoice_id' => $record['invoice_id'],
        'order_id' => $record['order_id'],
        'client_token' => $record['client_token'],
        'viewer_url' => $viewerUrl,
        'portal_url' => $portalUrl,
        'invoice_logo' => $record['invoice_logo'] !== '' ? $record['invoice_logo'] : null,
    ], 'Счёт сохранён', [], [
        'document_id' => $record['document_id'],
        'invoice_id' => $record['invoice_id'],
        'viewer_url' => $viewerUrl,
        'portal_url' => $portalUrl,
    ]);
} catch (Throwable $e) {
    api_json_send(false, null, 'Ошибка сохранения счёта', [$e->getMessage()]);
}
