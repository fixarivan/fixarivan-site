<?php
declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/api_response.php';
require_once __DIR__ . '/lib/order_center.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_json_send(false, null, 'Метод не поддерживается', []);
    exit;
}

$phone = trim((string)($_GET['phone'] ?? ''));
$email = trim((string)($_GET['email'] ?? ''));
$phoneNorm = fixarivan_normalize_phone($phone);
$emailNorm = fixarivan_safe_lower($email);

if ($phoneNorm === '' && $emailNorm === '') {
    api_json_send(false, null, 'Нужен phone или email', []);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    api_json_send(false, null, 'SQLite недоступна: ' . $e->getMessage(), []);
    exit;
}

$client = null;
try {
    if ($phoneNorm !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE phone = :p ORDER BY id DESC LIMIT 1');
        $stmt->execute([':p' => $phoneNorm]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($client === null && $emailNorm !== '') {
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE lower(email) = :e ORDER BY id DESC LIMIT 1');
        $stmt->execute([':e' => $emailNorm]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    // ignore
}

$params = [];
$where = [];
if ($phoneNorm !== '') {
    $where[] = 'REPLACE(REPLACE(REPLACE(IFNULL(client_phone, \'\'), \'+\', \'\'), \' \', \'\'), \'-\', \'\') = :p';
    $params[':p'] = $phoneNorm;
}
if ($emailNorm !== '') {
    $where[] = 'lower(IFNULL(client_email, \'\')) = :e';
    $params[':e'] = $emailNorm;
}

$latest = null;
if ($where !== []) {
    $sql = 'SELECT document_id, order_id, client_name, client_phone, client_email, device_model, problem_description, status,
                   COALESCE(NULLIF(TRIM(date_updated), \'\'), NULLIF(TRIM(date_created), \'\'), \'\') AS updated_at
            FROM orders
            WHERE ' . implode(' OR ', $where) . '
            ORDER BY updated_at DESC
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $latest = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

api_json_send(true, [
    'client' => $client,
    'latest_order' => $latest,
], null, [], []);
