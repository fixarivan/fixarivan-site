<?php
declare(strict_types=1);

/**
 * Выдаёт client_token и ссылку на client_portal для заказа.
 * Если токен пустой — создаётся один раз (immutable после этого).
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/client_token.php';
require_once __DIR__ . '/lib/site_url.php';
require_once __DIR__ . '/lib/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    api_json_send(false, null, 'Некорректный JSON', []);
    exit;
}

$documentId = trim((string)($input['documentId'] ?? $input['document_id'] ?? ''));
$orderId = trim((string)($input['orderId'] ?? $input['order_id'] ?? ''));

if ($documentId === '' && $orderId === '') {
    api_json_send(false, null, 'Нужен documentId или orderId', []);
    exit;
}

try {
    $pdo = getSqliteConnection();
    $row = null;
    if ($documentId !== '') {
        $stmt = $pdo->prepare('SELECT id, document_id, order_id, client_token, date_updated FROM orders WHERE document_id = :d LIMIT 1');
        $stmt->execute([':d' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ((!is_array($row) || $row === []) && $orderId !== '') {
        $stmt = $pdo->prepare('SELECT id, document_id, order_id, client_token, date_updated FROM orders WHERE order_id = :o LIMIT 1');
        $stmt->execute([':o' => $orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($row) || $row === []) {
        api_json_send(false, null, 'Заказ не найден', []);
        exit;
    }

    $token = trim((string)($row['client_token'] ?? ''));
    $now = date('c');
    if ($token === '') {
        $token = fixarivan_generate_client_token();
        $upd = $pdo->prepare('UPDATE orders SET client_token = :t, date_updated = :u WHERE id = :id AND (client_token IS NULL OR TRIM(client_token) = \'\')');
        $upd->execute([':t' => $token, ':u' => $now, ':id' => (int)($row['id'] ?? 0)]);
        if ($upd->rowCount() === 0) {
            $stmt2 = $pdo->prepare('SELECT client_token FROM orders WHERE id = :id LIMIT 1');
            $stmt2->execute([':id' => (int)($row['id'] ?? 0)]);
            $token = trim((string)$stmt2->fetchColumn());
        }
    }

    $portalUrl = fixarivan_absolute_url('client_portal.php?token=' . rawurlencode($token));
    api_json_send(true, [
        'document_id' => (string)($row['document_id'] ?? ''),
        'order_id' => trim((string)($row['order_id'] ?? '')),
        'client_token' => $token,
        'portal_url' => $portalUrl,
    ], null, [], []);
} catch (Throwable $e) {
    api_json_send(false, null, $e->getMessage(), []);
}
