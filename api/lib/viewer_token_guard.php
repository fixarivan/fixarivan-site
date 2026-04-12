<?php
declare(strict_types=1);

/**
 * Мягкий rate limit + логирование для публичных token-viewer (без утечки токена в логах).
 */

function fixarivan_viewer_remote_addr(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '' || !is_string($ip)) {
        return '0.0.0.0';
    }
    return $ip;
}

function fixarivan_viewer_token_fingerprint(string $token): string
{
    $t = trim($token);
    if ($t === '') {
        return 'empty';
    }

    return substr(hash('sha256', $t), 0, 16);
}

function fixarivan_viewer_lang_from_request(): string
{
    $l = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
    if (in_array($l, ['ru', 'en', 'fi'], true)) {
        return $l;
    }

    return 'ru';
}

/** Окно учёта неудач, макс. попыток за окно, блокировка (сек.) */
const FIXARIVAN_VIEWER_RATE_WINDOW = 600;
const FIXARIVAN_VIEWER_RATE_MAX_FAILS = 40;
const FIXARIVAN_VIEWER_RATE_BLOCK = 900;

function fixarivan_viewer_rate_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'viewer_rate';
}

function fixarivan_viewer_rate_file(string $ip): string
{
    return fixarivan_viewer_rate_dir() . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
}

/**
 * @return array{fail_ts: int[], blocked_until: int}
 */
function fixarivan_viewer_rate_load(string $ip): array
{
    $path = fixarivan_viewer_rate_file($ip);
    if (!is_file($path)) {
        return ['fail_ts' => [], 'blocked_until' => 0];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['fail_ts' => [], 'blocked_until' => 0];
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        return ['fail_ts' => [], 'blocked_until' => 0];
    }

    return [
        'fail_ts' => isset($d['fail_ts']) && is_array($d['fail_ts']) ? array_map('intval', $d['fail_ts']) : [],
        'blocked_until' => (int) ($d['blocked_until'] ?? 0),
    ];
}

function fixarivan_viewer_rate_save(string $ip, array $data): void
{
    $dir = fixarivan_viewer_rate_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = fixarivan_viewer_rate_file($ip);
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** false = временно заблокирован по IP */
function fixarivan_viewer_rate_allowed(string $endpoint): bool
{
    $ip = fixarivan_viewer_remote_addr();
    $now = time();
    $data = fixarivan_viewer_rate_load($ip);
    $blocked = (int) ($data['blocked_until'] ?? 0);
    if ($blocked > $now) {
        fixarivan_viewer_log_line($endpoint, 'rate_blocked', 'n/a', 'ip temporarily limited');

        return false;
    }

    $cutoff = $now - FIXARIVAN_VIEWER_RATE_WINDOW;
    $failTs = array_filter($data['fail_ts'] ?? [], static fn ($t) => (int) $t >= $cutoff);
    $data['fail_ts'] = array_values($failTs);
    if ($blocked > 0 && $blocked <= $now) {
        $data['blocked_until'] = 0;
    }
    fixarivan_viewer_rate_save($ip, $data);

    return true;
}

function fixarivan_viewer_rate_failure(string $endpoint): void
{
    $ip = fixarivan_viewer_remote_addr();
    $now = time();
    $data = fixarivan_viewer_rate_load($ip);
    $cutoff = $now - FIXARIVAN_VIEWER_RATE_WINDOW;
    $failTs = array_filter($data['fail_ts'] ?? [], static fn ($t) => (int) $t >= $cutoff);
    $failTs[] = $now;
    $data['fail_ts'] = array_values($failTs);
    if (count($data['fail_ts']) >= FIXARIVAN_VIEWER_RATE_MAX_FAILS) {
        $data['blocked_until'] = $now + FIXARIVAN_VIEWER_RATE_BLOCK;
        $data['fail_ts'] = [];
        fixarivan_viewer_log_line($endpoint, 'rate_limit_engage', 'n/a', 'threshold reached');
    }
    fixarivan_viewer_rate_save($ip, $data);
}

function fixarivan_viewer_rate_success(string $endpoint): void
{
    $ip = fixarivan_viewer_remote_addr();
    $data = fixarivan_viewer_rate_load($ip);
    $data['fail_ts'] = [];
    $data['blocked_until'] = 0;
    fixarivan_viewer_rate_save($ip, $data);
}

function fixarivan_viewer_log_line(string $endpoint, string $event, string $tokenFingerprint, string $detail = ''): void
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    if (strlen($ua) > 400) {
        $ua = substr($ua, 0, 400);
    }
    $line = json_encode([
        'ts' => gmdate('c'),
        'endpoint' => $endpoint,
        'event' => $event,
        'ip' => fixarivan_viewer_remote_addr(),
        'token_fp' => $tokenFingerprint,
        'ua' => $ua,
        'detail' => $detail,
    ], JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'token_viewer_access.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Нейтральная страница: одинаковый смысл для «нет документа», «битая ссылка», rate limit.
 */
function fixarivan_viewer_render_neutral_unavailable(?string $lang = null): void
{
    $l = $lang ?? fixarivan_viewer_lang_from_request();
    if (!in_array($l, ['ru', 'en', 'fi'], true)) {
        $l = 'ru';
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $titles = [
        'ru' => ['Документ недоступен', 'Ссылка недействительна или устарела. Обратитесь к мастеру за новой ссылкой.'],
        'en' => ['Document unavailable', 'This link is invalid or no longer works. Contact the technician for a new link.'],
        'fi' => ['Asiakirja ei ole saatavilla', 'Linkki on virheellinen tai vanhentunut. Pyydä uusi linkki teknikolta.'],
    ];
    $t = $titles[$l];
    echo '<!DOCTYPE html><html lang="' . htmlspecialchars($l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($t[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title></head><body style="font-family:system-ui,sans-serif;padding:40px;text-align:center;background:#f4f6fb;"><h1 style="color:#1e293b;">' . htmlspecialchars($t[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p style="color:#475569;max-width:28rem;margin:0 auto;">' . htmlspecialchars($t[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
}
