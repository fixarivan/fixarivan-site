<?php
/**
 * Действия по очереди закупок.
 * Вариант B: удалить позицию из заказа (order_lines_json) и из OWL по конкретной строке.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_warehouse_sync.php';
require_once __DIR__ . '/lib/order_supply.php';
require_once __DIR__ . '/lib/order_json_storage.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'Только POST', null, ['method']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    jsonOut(false, 'Некорректный JSON', null, ['invalid_json']);
    exit;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
if (!in_array($action, ['dismiss', 'remove_from_order'], true)) {
    jsonOut(false, 'Неизвестное действие', null, ['action']);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    jsonOut(false, $e->getMessage(), null, [$e->getMessage()]);
    exit;
}

$owlId = isset($input['owl_id']) ? (int)$input['owl_id'] : 0;
if ($owlId <= 0) {
    jsonOut(false, 'Укажите owl_id', null, ['owl_id']);
    exit;
}

/**
 * @param array<int, mixed> $lines
 * @return array{0: float, 1: float}
 */
function fixarivan_queue_action_totals_from_lines(array $lines): array {
    $purchase = 0.0;
    $sale = 0.0;
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $q = (float)($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($q <= 0) {
            $q = 1.0;
        }
        $purchase += (float)($ln['purchase'] ?? $ln['purchase_price'] ?? $ln['cost'] ?? 0) * $q;
        $sale += (float)($ln['sale'] ?? $ln['sale_price'] ?? $ln['price'] ?? 0) * $q;
    }

    return [$purchase, $sale];
}

try {
    $st = $pdo->prepare('SELECT id, order_id, order_line_key, name, qty, IFNULL(inventory_item_id, 0) AS inventory_item_id FROM order_warehouse_lines WHERE id = ? LIMIT 1');
    $st->execute([$owlId]);
    $owl = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owl) || $owl === []) {
        jsonOut(false, 'Строка не найдена', null, ['not_found']);
        exit;
    }
    $orderId = trim((string)($owl['order_id'] ?? ''));
    $lineKey = trim((string)($owl['order_line_key'] ?? ''));
    $lineName = trim((string)($owl['name'] ?? ''));
    $lineInv = (int)($owl['inventory_item_id'] ?? 0);
    $lineQty = (float)($owl['qty'] ?? 0);
    if ($orderId === '') {
        jsonOut(false, 'Пустой order_id', null, ['order_id']);
        exit;
    }

    if ($action === 'dismiss') {
        $nameNorm = function_exists('mb_strtolower')
            ? mb_strtolower($lineName, 'UTF-8')
            : strtolower($lineName);
        $now = date('c');
        $ins = $pdo->prepare(
            'INSERT OR IGNORE INTO purchase_list_dismissals (order_id, owl_id, name_norm, created_at) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$orderId, $owlId, $nameNorm, $now]);
        jsonOut(true, null, ['dismissed' => true, 'order_id' => $orderId]);
        exit;
    }

    $oSt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $oSt->execute([$orderId, $orderId]);
    $orderRow = $oSt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($orderRow) || $orderRow === []) {
        jsonOut(false, 'Заказ не найден', null, ['order_not_found']);
        exit;
    }
    $documentId = trim((string)($orderRow['document_id'] ?? ''));
    $orderIdDb = trim((string)($orderRow['order_id'] ?? $orderId));
    if ($orderIdDb === '') {
        $orderIdDb = $orderId;
    }
    $rawJson = fixarivan_normalize_order_lines_json_for_sync($orderRow['order_lines_json'] ?? '[]');
    $lines = json_decode($rawJson, true);
    if (!is_array($lines)) {
        $lines = [];
    }

    $removed = false;
    $nextLines = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            $nextLines[] = $ln;
            continue;
        }
        $k = trim((string)($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        $inv = (int)($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        $nm = trim((string)($ln['name'] ?? $ln['title'] ?? ''));
        $qv = (float)($ln['qty'] ?? $ln['quantity'] ?? 0);

        $matched = false;
        if ($lineKey !== '' && $k === $lineKey) {
            $matched = true;
        } elseif ($lineKey === '' && !$removed) {
            if ($lineInv > 0 && $inv > 0 && $lineInv === $inv) {
                $matched = true;
            } elseif ($lineName !== '' && $nm !== '' && strtolower($lineName) === strtolower($nm) && ($lineQty <= 0 || abs($qv - $lineQty) < 0.0001)) {
                $matched = true;
            }
        }

        if ($matched && !$removed) {
            $removed = true;
            continue;
        }
        $nextLines[] = $ln;
    }
    if (!$removed) {
        jsonOut(false, 'Не удалось сопоставить строку в order_lines_json', null, ['line_not_matched']);
        exit;
    }

    $enc = json_encode($nextLines, JSON_UNESCAPED_UNICODE);
    if ($enc === false) {
        jsonOut(false, 'Ошибка кодирования order_lines_json', null, ['json_encode']);
        exit;
    }
    $enc = fixarivan_prepare_order_lines_json_for_persist($pdo, $enc);
    $finalLines = json_decode($enc, true);
    if (!is_array($finalLines)) {
        $finalLines = [];
    }
    [$pTot, $sTot] = fixarivan_queue_action_totals_from_lines($finalLines);
    $newSupply = $finalLines !== [] ? fixarivan_supply_request_from_order_lines($finalLines) : '';

    $now = date('c');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM order_warehouse_lines WHERE id = ?')->execute([$owlId]);
        $pdo->prepare('DELETE FROM purchase_list_dismissals WHERE owl_id = ?')->execute([$owlId]);

        $pdo->prepare(
            'UPDATE orders
             SET order_lines_json = :j,
                 parts_purchase_total = :pp,
                 parts_sale_total = :ps,
                 supply_request = :sr,
                 date_updated = :u
             WHERE document_id = :d'
        )->execute([
            ':j' => $enc,
            ':pp' => $pTot,
            ':ps' => $sTot,
            ':sr' => $newSupply,
            ':u' => $now,
            ':d' => $documentId,
        ]);

        // Пересчёт только по оставшимся активным строкам OWL.
        fixarivan_recompute_order_parts_aggregate($pdo, $orderIdDb);
        $pdo->commit();
    } catch (Throwable $txe) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $txe;
    }

    $orderRow['order_lines_json'] = $enc;
    $orderRow['parts_purchase_total'] = $pTot;
    $orderRow['parts_sale_total'] = $sTot;
    $orderRow['supply_request'] = $newSupply;
    $orderRow['date_updated'] = $now;
    if ($documentId !== '') {
        try {
            fixarivan_save_order_json_files($orderRow, $documentId, (string)($orderRow['client_token'] ?? ''));
        } catch (Throwable $ignored) {
        }
    }

    jsonOut(true, null, [
        'removed_from_order' => true,
        'owl_id' => $owlId,
        'order_id' => $orderIdDb,
        'document_id' => $documentId,
        'remaining_order_lines' => count($finalLines),
    ]);
} catch (Throwable $e) {
    jsonOut(false, $e->getMessage(), null, [$e->getMessage()]);
}
