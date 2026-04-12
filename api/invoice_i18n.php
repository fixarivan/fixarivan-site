<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only GET'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/lib/invoice_i18n.php';

echo json_encode([
    'success' => true,
    'form' => fixarivan_invoice_i18n_for_form(),
], JSON_UNESCAPED_UNICODE);
