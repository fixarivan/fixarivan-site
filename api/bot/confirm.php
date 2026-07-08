<?php
declare(strict_types=1);

/**
 * Bot API v1 — confirm preliminary lead → regular order (in_progress).
 * Called by engineer workflow or future automation after review.
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/../lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization, X-FixariVan-Api-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../sqlite.php';
require_once __DIR__ . '/../lib/api_response.php';
require_once __DIR__ . '/../lib/bot_lead.php';
require_once __DIR__ . '/../lib/require_admin_session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_send(false, null, 'Method not allowed', [], ['error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    api_json_send(false, null, 'Invalid JSON body', [], ['error' => 'invalid_json']);
    exit;
}

$orderRef = trim((string) ($payload['order_id'] ?? $payload['document_id'] ?? ''));
if ($orderRef === '') {
    http_response_code(400);
    api_json_send(false, null, 'order_id or document_id required', [], ['error' => 'validation_error']);
    exit;
}

try {
    $pdo = getSqliteConnection();
    [$ok, $data, $err, $code] = fixarivan_bot_confirm_lead($pdo, $orderRef);
    if (!$ok) {
        http_response_code($code);
        api_json_send(false, null, $err, [], ['error' => 'confirm_rejected', 'success' => false]);
        exit;
    }
    $data['pending_review'] = false;
    $data['status'] = 'in_progress';
    $data['order_status'] = 'in_progress';
    $data['message'] = 'Lead confirmed — order in progress';
    api_json_send(true, $data, $data['message'], [], $data);
} catch (Throwable $e) {
    http_response_code(500);
    api_json_send(false, null, 'Server error', [], ['error' => 'internal_error', 'success' => false]);
}
