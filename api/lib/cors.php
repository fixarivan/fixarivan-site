<?php
declare(strict_types=1);

/**
 * CORS: только origin текущего хоста (без *).
 */
function fixarivan_send_cors_headers(?string $methods = null, ?string $allowHeaders = null): void
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $origin = $scheme . '://' . $host;
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: ' . ($methods ?? 'GET, POST, PUT, DELETE, PATCH, OPTIONS'));
    header('Access-Control-Allow-Headers: ' . ($allowHeaders ?? 'Content-Type, Authorization, X-Fixarivan-Auth'));
    header('Vary: Origin');
}
