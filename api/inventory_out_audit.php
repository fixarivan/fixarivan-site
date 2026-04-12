<?php
/**
 * Диагностика: дубли списаний по одной строке заказа (note ORDER_LINE_OUT / ORDER_CLOSE).
 * GET, admin session. Не изменяет данные.
 */
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
require_once __DIR__ . '/sqlite.php';

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$dupNotes = [];
$st = $pdo->query(
    "SELECT note, COUNT(*) AS c FROM inventory_movements
     WHERE quantity_delta < 0 AND note IS NOT NULL AND TRIM(note) != ''
       AND (note LIKE 'ORDER_LINE_OUT:%' OR note LIKE 'ORDER_CLOSE:%')
     GROUP BY note HAVING COUNT(*) > 1"
);
if ($st) {
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $dupNotes[] = ['note' => $r['note'], 'count' => (int)($r['c'] ?? 0)];
    }
}

$sameLineMulti = [];
$st2 = $pdo->query(
    "SELECT ref_id, item_id, COUNT(*) AS c, GROUP_CONCAT(id) AS movement_ids
     FROM inventory_movements
     WHERE quantity_delta < 0 AND ref_kind IN ('order', 'order_close')
     GROUP BY ref_id, item_id
     HAVING COUNT(*) > 1
     LIMIT 200"
);
if ($st2) {
    while ($r = $st2->fetch(PDO::FETCH_ASSOC)) {
        $sameLineMulti[] = [
            'ref_id' => $r['ref_id'],
            'item_id' => (int)($r['item_id'] ?? 0),
            'out_movements' => (int)($r['c'] ?? 0),
            'movement_ids' => $r['movement_ids'] ?? '',
        ];
    }
}

echo json_encode(
    [
        'success' => true,
        'duplicate_note_tags' => $dupNotes,
        'same_order_ref_multiple_outs_same_item' => $sameLineMulti,
        'hint' => 'duplicate_note_tags: одинаковый note у нескольких движений — аномалия. same_order_ref_*: несколько out по одному ref_id и item_id — проверить вручную (разные строки заказа с одной карточкой склада допустимы).',
    ],
    JSON_UNESCAPED_UNICODE
);
