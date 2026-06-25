<?php
declare(strict_types=1);

/**
 * Публичный логотип бренда (из настроек компании) — для дашборда, склада и др.
 */

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/company_profile.php';
require_once __DIR__ . '/lib/api_response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$url = fixarivan_brand_logo_url();
api_json_send(true, [
    'logo_url' => $url,
    'has_custom' => $url !== '',
], null, [], [
    'logo_url' => $url,
    'has_custom' => $url !== '',
]);
