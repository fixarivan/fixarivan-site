<?php
declare(strict_types=1);

/**
 * Universal Bot API v1 — create/update preliminary lead (pending_review).
 * Channel-agnostic: WhatsApp, Telegram, website, etc.
 */

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/../lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization, X-FixariVan-Api-Key, X-Idempotency-Key');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../sqlite.php';
require_once __DIR__ . '/../lib/api_response.php';
require_once __DIR__ . '/../lib/bot_lead.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json_send(false, null, 'Method not allowed', [], ['error' => 'method_not_allowed']);
    exit;
}

if (!fixarivan_bot_verify_api_key(fixarivan_bot_read_api_key_from_request())) {
    http_response_code(fixarivan_bot_api_key() === '' ? 503 : 401);
    api_json_send(false, null, fixarivan_bot_api_key() === ''
        ? 'Bot API key is not configured on CRM server'
        : 'Invalid or missing API key', [], ['error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    api_json_send(false, null, 'Invalid JSON body', [], ['error' => 'invalid_json']);
    exit;
}

if (trim((string) ($payload['idempotency_key'] ?? '')) === '') {
    $hdrKey = trim((string) ($_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? ''));
    if ($hdrKey !== '') {
        $payload['idempotency_key'] = $hdrKey;
    }
}

try {
    $pdo = getSqliteConnection();
    [$ok, $data, $err, $code] = fixarivan_bot_upsert_lead($pdo, $payload);
    if (!$ok) {
        http_response_code($code);
        api_json_send(false, null, $err, [], ['error' => 'lead_rejected', 'success' => false]);
        exit;
    }
    http_response_code($code === 201 ? 201 : 200);
    api_json_send(true, $data, $data['message'] ?? null, [], $data);
} catch (Throwable $e) {
    http_response_code(500);
    api_json_send(false, null, 'Server error', [], ['error' => 'internal_error', 'success' => false]);
}
