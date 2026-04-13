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
require_once __DIR__ . '/lib/invoice_center.php';

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

$documentId = trim((string)($input['document_id'] ?? $input['documentId'] ?? ''));
$status = strtolower(trim((string)($input['status'] ?? '')));
$allowed = ['draft', 'issued', 'paid', 'overdue', 'partially_paid', 'cancelled'];
if ($documentId === '' || !in_array($status, $allowed, true)) {
    api_json_send(false, null, 'Некорректные document_id или status', []);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE document_id = :id LIMIT 1');
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        api_json_send(false, null, 'Счёт не найден', []);
        exit;
    }

    $record = $row;
    $record['status'] = $status;
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Helsinki'));
    $record = fixarivan_invoice_finalize_payment_fields($record, $now);
    $record['date_updated'] = $now->format('c');

    $rj = json_decode((string)($row['raw_json'] ?? '{}'), true);
    if (!is_array($rj)) {
        $rj = [];
    }
    $rj['status'] = $status;
    $rjRaw = json_encode($rj, JSON_UNESCAPED_UNICODE);
    if ($rjRaw === false) {
        $rjRaw = '{}';
    }

    $pd = $record['payment_date'] ?? null;
    $pdVal = is_string($pd) && trim($pd) !== '' ? trim($pd) : null;

    $upd = $pdo->prepare(
        'UPDATE invoices SET status = :status, date_updated = :du, payment_date = :pd, raw_json = :rj WHERE document_id = :id'
    );
    $upd->execute([
        ':status' => $status,
        ':du' => $record['date_updated'],
        ':pd' => $pdVal,
        ':rj' => $rjRaw,
        ':id' => $documentId,
    ]);

    api_json_send(true, ['document_id' => $documentId, 'status' => $status], 'Статус сохранён');
} catch (Throwable $e) {
    api_json_send(false, null, 'Ошибка сохранения', [$e->getMessage()]);
}
