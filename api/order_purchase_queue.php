<?php
/**
 * Очередь закупок: строки order_warehouse_lines, которые ещё не «со склада» и не пришли.
 * GET — для блока «Список покупок» на inventory.html.
 *
 * Проверка в БД (подставьте order_id из заказа):
 * SELECT id, order_id, name, qty, order_line_key, status, from_stock, inventory_item_id
 * FROM order_warehouse_lines WHERE order_id = 'ORD-...';
 *
 * Опционально: ?order_id=ORD-... или document_id — только строки этого заказа.
 * ?debug=1 — счётчики и примеры статусов (для диагностики).
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
        [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ],
        JSON_UNESCAPED_UNICODE
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonOut(false, 'Только GET', null, ['method']);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    jsonOut(false, $e->getMessage(), null, [$e->getMessage()]);
    exit;
}

try {
    $oidFilter = isset($_GET['order_id']) ? trim((string) $_GET['order_id']) : '';
    $oidVariants = [];
    if ($oidFilter !== '') {
        $oidVariants[] = $oidFilter;
        $stOv = $pdo->prepare('SELECT order_id, document_id FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
        $stOv->execute([$oidFilter, $oidFilter]);
        $orv = $stOv->fetch(PDO::FETCH_ASSOC);
        if (is_array($orv)) {
            $o1 = trim((string) ($orv['order_id'] ?? ''));
            $d1 = trim((string) ($orv['document_id'] ?? ''));
            if ($o1 !== '') {
                $oidVariants[] = $o1;
            }
            if ($d1 !== '') {
                $oidVariants[] = $d1;
            }
        }
        $oidVariants = array_values(array_unique(array_filter($oidVariants)));
    }

    $sql = '
        SELECT
            owl.id AS owl_id,
            owl.order_id,
            owl.order_line_key,
            owl.name,
            owl.qty,
            owl.purchase_price,
            owl.sale_price,
            owl.status,
            owl.expected_date,
            owl.inventory_item_id,
            owl.from_stock,
            (SELECT client_name FROM orders WHERE order_id = owl.order_id OR document_id = owl.order_id LIMIT 1) AS client_name,
            (SELECT device_model FROM orders WHERE order_id = owl.order_id OR document_id = owl.order_id LIMIT 1) AS device_model,
            (SELECT document_id FROM orders WHERE order_id = owl.order_id OR document_id = owl.order_id LIMIT 1) AS document_id
        FROM order_warehouse_lines owl
        WHERE IFNULL(owl.from_stock, 0) = 0
          AND LOWER(TRIM(COALESCE(owl.status, \'ordered\'))) NOT IN (\'arrived\', \'installed\', \'ready\')
    ';
    $params = [];
    if ($oidVariants !== []) {
        $ph = implode(',', array_fill(0, count($oidVariants), '?'));
        $sql .= ' AND owl.order_id IN (' . $ph . ')';
        $params = $oidVariants;
    }
    $sql .= '
        ORDER BY
            CASE WHEN owl.expected_date IS NULL OR owl.expected_date = \'\' THEN 1 ELSE 0 END,
            owl.expected_date ASC,
            owl.updated_at DESC
    ';
    if ($params === []) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $dismissRows = $pdo->query('SELECT order_id, name_norm, owl_id FROM purchase_list_dismissals')->fetchAll(PDO::FETCH_ASSOC);
    $dismissSet = [];
    $dismissOwlIds = [];
    foreach ($dismissRows as $dr) {
        $oid = trim((string)($dr['order_id'] ?? ''));
        $nn = trim((string)($dr['name_norm'] ?? ''));
        if ($oid !== '' && $nn !== '') {
            $dismissSet[$oid . "\0" . $nn] = true;
        }
        $owd = isset($dr['owl_id']) ? (int)$dr['owl_id'] : 0;
        if ($owd > 0) {
            $dismissOwlIds[$owd] = true;
        }
    }
    $filtered = [];
    foreach ($rows as $r) {
        $oid = trim((string)($r['order_id'] ?? ''));
        $nm = trim((string)($r['name'] ?? ''));
        $owlPk = (int)($r['owl_id'] ?? 0);
        if ($owlPk > 0 && isset($dismissOwlIds[$owlPk])) {
            continue;
        }
        $nameNorm = function_exists('mb_strtolower') ? mb_strtolower($nm, 'UTF-8') : strtolower($nm);
        if ($oid !== '' && isset($dismissSet[$oid . "\0" . $nameNorm])) {
            continue;
        }
        $filtered[] = $r;
    }
    $queueItemIds = [];
    foreach ($filtered as $fr) {
        $qi = isset($fr['inventory_item_id']) ? (int) $fr['inventory_item_id'] : 0;
        if ($qi > 0) {
            $queueItemIds[$qi] = true;
        }
    }
    $dataOut = [
        'lines' => $filtered,
        'queue_inventory_item_ids' => array_map('intval', array_keys($queueItemIds)),
    ];
    if (isset($_GET['debug']) && (string) $_GET['debug'] === '1') {
        $statusSample = [];
        foreach (array_slice($rows, 0, 20) as $r0) {
            $statusSample[] = [
                'owl_id' => (int) ($r0['owl_id'] ?? 0),
                'order_id' => (string) ($r0['order_id'] ?? ''),
                'status' => (string) ($r0['status'] ?? ''),
                'from_stock' => (int) ($r0['from_stock'] ?? 0),
                'order_line_key' => (string) ($r0['order_line_key'] ?? ''),
            ];
        }
        $dataOut['debug'] = [
            'owl_rows_after_status_filter' => count($rows),
            'owl_rows_after_dismissals' => count($filtered),
            'order_id_filter' => $oidFilter !== '' ? $oidFilter : null,
            'order_id_variants_used' => $oidVariants !== [] ? $oidVariants : null,
            'sample' => $statusSample,
        ];
    }
    jsonOut(true, null, $dataOut);
} catch (Throwable $e) {
    jsonOut(false, $e->getMessage(), null, [$e->getMessage()]);
}
