<?php
declare(strict_types=1);
/**
 * Глобальный (анонимный) рекорд змейки для клиентского портала.
 *
 * Безопасность данных:
 * - В БД хранится только одно число (best_score) и дата обновления — без клиентских ПДн и без связи с заказами.
 * - Запись в SQLite только через prepared statements; таблица не участвует в JOIN с заказами/клиентами.
 * - POST требует тот же client_token, что уже выдаётся для доступа к порталу; новых прав доступа к заказам это не даёт.
 * - Токен в JS дублирует то, что уже есть в URL портала — отдельной утечки секрета нет.
 * - Rate limit (viewer_token_guard) как у других публичных viewer-эндпоинтов.
 * - Ответы об ошибках без стектрейсов и без деталей БД.
 */
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/viewer_token_guard.php';

const PORTAL_SNAKE_MAX_SCORE = 50000;
/** Макс. размер тела POST (байт), защита от мусорных больших тел */
const PORTAL_SNAKE_MAX_BODY = 4096;

function portal_snake_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
}

function portal_snake_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    portal_snake_security_headers();
}

function portal_snake_is_json_content_type(): bool
{
    $ct = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';

    return $ct !== '' && stripos($ct, 'application/json') !== false;
}

portal_snake_json_headers();

function portal_snake_valid_token_format(string $t): bool
{
    return $t !== '' && preg_match('/^[a-fA-F0-9]{16,128}$/', $t) === 1;
}

function portal_snake_token_known(PDO $pdo, string $token): bool
{
    $st = $pdo->prepare('SELECT 1 FROM orders WHERE client_token = :t LIMIT 1');
    $st->execute([':t' => $token]);

    return (bool) $st->fetchColumn();
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    if (!fixarivan_viewer_rate_allowed('portal_snake_get')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);

        exit;
    }
    try {
        $pdo = getSqliteConnection();
        $row = $pdo->query('SELECT best_score FROM portal_snake_global WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $best = is_array($row) ? max(0, (int) ($row['best_score'] ?? 0)) : 0;
        fixarivan_viewer_rate_success('portal_snake_get');
        echo json_encode(['success' => true, 'best' => $best], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

if ($method === 'POST') {
    if (!fixarivan_viewer_rate_allowed('portal_snake_post')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if (!portal_snake_is_json_content_type()) {
        fixarivan_viewer_rate_failure('portal_snake_post');
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'unsupported_media'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || strlen($raw) > PORTAL_SNAKE_MAX_BODY) {
        fixarivan_viewer_rate_failure('portal_snake_post');
        http_response_code(413);
        echo json_encode(['success' => false, 'error' => 'payload_too_large'], JSON_UNESCAPED_UNICODE);

        exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fixarivan_viewer_rate_failure('portal_snake_post');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bad_json'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $token = isset($data['token']) ? trim((string) $data['token']) : '';
    $score = isset($data['score']) ? (int) $data['score'] : -1;

    if (!portal_snake_valid_token_format($token)) {
        fixarivan_viewer_rate_failure('portal_snake_post');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    if ($score < 0 || $score > PORTAL_SNAKE_MAX_SCORE) {
        fixarivan_viewer_rate_failure('portal_snake_post');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bad_score'], JSON_UNESCAPED_UNICODE);

        exit;
    }

    try {
        $pdo = getSqliteConnection();
        if (!portal_snake_token_known($pdo, $token)) {
            fixarivan_viewer_rate_failure('portal_snake_post');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'unknown_token'], JSON_UNESCAPED_UNICODE);

            exit;
        }

        $now = gmdate('c');
        $up = $pdo->prepare(
            'UPDATE portal_snake_global SET best_score = :sc, updated_at = :ts WHERE id = 1 AND best_score < :sc2'
        );
        $up->execute([':sc' => $score, ':sc2' => $score, ':ts' => $now]);
        $improved = $up->rowCount() > 0;
        $row = $pdo->query('SELECT best_score FROM portal_snake_global WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $newBest = is_array($row) ? max(0, (int) ($row['best_score'] ?? 0)) : 0;
        fixarivan_viewer_rate_success('portal_snake_post');
        echo json_encode([
            'success' => true,
            'best' => $newBest,
            'improved' => $improved,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'server'], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
