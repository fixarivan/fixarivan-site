<?php
/**
 * История движений по позиции (SQLite).
 * GET: item_id (обязательно), limit (по умолчанию 50).
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('GET, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';

require_once __DIR__ . '/sqlite.php';

function jsonOut(bool $success, ?string $message, $data, array $errors = []): void {
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data, 'errors' => $errors],
        JSON_UNESCAPED_UNICODE
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOut(false, 'Только GET', null, []);
    exit;
}

$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
if ($itemId <= 0) {
    jsonOut(false, 'Укажите item_id', null, ['item_id']);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 500) {
    $limit = 500;
}

$pdo = null;
try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    error_log('inventory_history SQLite: ' . $e->getMessage());
}

if ($pdo === null) {
    jsonOut(true, null, ['movements' => [], 'item_id' => $itemId, 'sqlite_available' => false], []);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by
     FROM inventory_movements
     WHERE item_id = ?
     ORDER BY datetime(created_at) DESC, id DESC
     LIMIT ' . (int)$limit
);
$stmt->execute([$itemId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonOut(true, null, ['movements' => $rows, 'item_id' => $itemId]);
