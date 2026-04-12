<?php
declare(strict_types=1);

/**
 * Базовый URL сайта (путь до корня веб-приложения, не включая /api).
 */
function fixarivan_web_base_path(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '' && preg_match('#^(.*)/api/[^/]+$#', $script, $m)) {
        return rtrim($m[1], '/');
    }
    return '';
}

function fixarivan_origin(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function fixarivan_absolute_url(string $relativePath): string {
    $relativePath = ltrim($relativePath, '/');
    $base = fixarivan_web_base_path();
    $prefix = $base === '' ? '' : $base . '/';
    return fixarivan_origin() . '/' . $prefix . $relativePath;
}
