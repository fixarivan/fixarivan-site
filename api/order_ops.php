<?php
declare(strict_types=1);

/**
 * Операции с заявками/заказами: архивирование, массовое удаление, завершение по оплате счёта.
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_ops.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_send(false, null, 'Method not allowed', [], ['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    api_json_send(false, null, 'Invalid JSON', [], ['error' => 'invalid_json']);
    exit;
}

$action = strtolower(trim((string) ($input['action'] ?? '')));
$requestId = trim((string) ($input['request_id'] ?? $input['requestId'] ?? ''));
$deletePassword = trim((string) ($input['delete_password'] ?? $input['deletePassword'] ?? ''));

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    http_response_code(503);
    api_json_send(false, null, 'Database unavailable', [], ['error' => 'db_error']);
    exit;
}

switch ($action) {
    case 'preview':
        $docId = trim((string) ($input['document_id'] ?? $input['documentId'] ?? ''));
        if ($docId === '') {
            http_response_code(400);
            api_json_send(false, null, 'document_id required', [], ['error' => 'missing_document_id']);
            exit;
        }
        $preview = fixarivan_order_ops_preview($pdo, $docId);
        api_json_send($preview['success'], $preview['data'] ?? null, $preview['success'] ? 'OK' : 'Not found');
        exit;

    case 'delete':
    case 'archive':
        $docId = trim((string) ($input['document_id'] ?? $input['documentId'] ?? ''));
        if ($docId === '') {
            http_response_code(400);
            api_json_send(false, null, 'document_id required', [], ['error' => 'missing_document_id']);
            exit;
        }
        $result = fixarivan_order_ops_archive($pdo, $docId, $deletePassword !== '' ? $deletePassword : null, $requestId !== '' ? $requestId : null);
        if (empty($result['success'])) {
            $code = (string) ($result['code'] ?? '');
            if ($code === 'bad_password') {
                http_response_code(403);
            } elseif (in_array($code, ['linked_documents', 'already_archived'], true)) {
                http_response_code(409);
            } else {
                http_response_code(400);
            }
        }
        api_json_send(!empty($result['success']), $result['data'] ?? $result, (string) ($result['message'] ?? ''), [], [
            'code' => $result['code'] ?? '',
        ]);
        exit;

    case 'bulk_delete':
    case 'bulk_archive':
        $ids = $input['document_ids'] ?? $input['documentIds'] ?? [];
        if (!is_array($ids)) {
            http_response_code(400);
            api_json_send(false, null, 'document_ids must be array', [], ['error' => 'invalid_document_ids']);
            exit;
        }
        $result = fixarivan_order_ops_bulk_archive(
            $pdo,
            array_map('strval', $ids),
            $deletePassword !== '' ? $deletePassword : null,
            $requestId !== '' ? $requestId : null
        );
        api_json_send(!empty($result['success']), $result, (string) ($result['message'] ?? ''));
        exit;

    case 'complete_paid':
        $docId = trim((string) ($input['document_id'] ?? $input['documentId'] ?? ''));
        $confirmUnpaid = !empty($input['confirm_unpaid']) || !empty($input['confirmUnpaid']);
        if ($docId === '') {
            http_response_code(400);
            api_json_send(false, null, 'document_id required', [], ['error' => 'missing_document_id']);
            exit;
        }
        $result = fixarivan_order_ops_complete_paid($pdo, $docId, $confirmUnpaid, $requestId !== '' ? $requestId : null);
        if (empty($result['success'])) {
            if (!empty($result['need_confirm'])) {
                http_response_code(409);
            } else {
                http_response_code(400);
            }
        }
        api_json_send(!empty($result['success']), $result['data'] ?? $result, (string) ($result['message'] ?? ''), [], [
            'code' => $result['code'] ?? '',
            'need_confirm' => !empty($result['need_confirm']),
        ]);
        exit;

    case 'reopen':
        $docId = trim((string) ($input['document_id'] ?? $input['documentId'] ?? ''));
        if ($docId === '') {
            http_response_code(400);
            api_json_send(false, null, 'document_id required', [], ['error' => 'missing_document_id']);
            exit;
        }
        $result = fixarivan_order_ops_reopen($pdo, $docId, $requestId !== '' ? $requestId : null);
        if (empty($result['success'])) {
            http_response_code(400);
        }
        api_json_send(!empty($result['success']), $result['data'] ?? $result, (string) ($result['message'] ?? ''), [], [
            'code' => $result['code'] ?? '',
        ]);
        exit;

    default:
        http_response_code(400);
        api_json_send(false, null, 'Unknown action', [], ['error' => 'unknown_action']);
        exit;
}
