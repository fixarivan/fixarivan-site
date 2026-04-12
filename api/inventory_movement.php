<?php
/**
 * Запись движения по складу (приход / списание / корректировка и т.д.).
 *
 * Приход «Под заказ» (сценарий A): order_warehouse_line_id + order_id (+ document_id) + item_id;
 *   опционально order_line_key. arrival_context=order_queue — не допускает приход без привязки к строке заказа.
 * Обычный приход (B): только item_id — чистый IN; implicit_owl_resolve=true — временное сопоставление owl (не основная логика).
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
require_once __DIR__ . '/lib/inventory_arrival_auto_out.php';

/**
 * TZ v2-add C: при движении с order_warehouse_line_id клиент обязан передать контекст заказа
 * (order_id и/или document_id и/или ref_id), совпадающий со строкой склада заказа.
 *
 * @return string|null сообщение об ошибке или null
 */
function fixarivan_inventory_movement_validate_owl_order_context(PDO $pdo, int $owlId, array $input): ?string {
    if ($owlId <= 0) {
        return null;
    }
    $st = $pdo->prepare('SELECT order_id FROM order_warehouse_lines WHERE id = ? LIMIT 1');
    $st->execute([$owlId]);
    $owlOid = trim((string)$st->fetchColumn());
    if ($owlOid === '') {
        return 'Строка складского заказа не найдена.';
    }
    $hints = [];
    foreach (['order_id', 'document_id', 'ref_id'] as $k) {
        $v = trim((string)($input[$k] ?? ''));
        if ($v !== '') {
            $hints[$v] = true;
        }
    }
    if ($hints === []) {
        return 'Для операции по строке заказа укажите order_id или document_id заказа (контекст заказа обязателен).';
    }
    $owlVars = fixarivan_order_id_variants_for_pdo($pdo, '', $owlOid);
    foreach (array_keys($hints) as $h) {
        foreach (fixarivan_order_id_variants_for_pdo($pdo, $h, $h) as $x) {
            if ($x !== '' && in_array($x, $owlVars, true)) {
                return null;
            }
        }
    }

    return 'order_id / document_id / ref_id не соответствуют строке заказа.';
}

const INV_MOV_IN = ['in', 'return', 'balance_on'];
const INV_MOV_OUT = ['out', 'sale', 'writeoff', 'balance_off'];

function jsonOut(bool $success, ?string $message, $data, array $errors = []): void {
    echo json_encode(
        ['success' => $success, 'message' => $message, 'data' => $data, 'errors' => $errors],
        JSON_UNESCAPED_UNICODE
    );
}

function movementDelta(string $type, float $quantity): ?float {
    if ($quantity <= 0) {
        return null;
    }
    if (in_array($type, INV_MOV_IN, true)) {
        return $quantity;
    }
    if (in_array($type, INV_MOV_OUT, true)) {
        return -$quantity;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonOut(false, 'Только POST', null, []);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($input)) {
    jsonOut(false, 'Некорректный JSON', null, ['invalid_json']);
    exit;
}

$itemId = isset($input['item_id']) ? (int)$input['item_id'] : 0;
$type = strtolower(trim((string)($input['movement_type'] ?? '')));
$qty = isset($input['quantity']) ? (float)$input['quantity'] : 0;

if ($itemId <= 0) {
    jsonOut(false, 'Укажите item_id', null, ['item_id']);
    exit;
}

if ($type === 'adjust') {
    if ($qty == 0.0) {
        jsonOut(false, 'Для adjust укажите ненулевое quantity', null, ['quantity']);
        exit;
    }
    $delta = $qty;
} else {
    if ($qty <= 0) {
        jsonOut(false, 'quantity должно быть > 0', null, ['quantity']);
        exit;
    }
    $delta = movementDelta($type, $qty);
    if ($delta === null) {
        jsonOut(false, 'Неизвестный movement_type', null, ['movement_type']);
        exit;
    }
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    error_log('inventory_movement SQLite: ' . $e->getMessage());
    $msg = $e->getMessage();
    if (stripos($msg, 'could not find driver') !== false) {
        $msg = 'SQLite недоступен на сервере (включите расширение pdo_sqlite в PHP).';
    }
    jsonOut(false, $msg, null, [$e->getMessage()]);
    exit;
}

$itemStmt = $pdo->prepare('SELECT id, default_cost, sale_price FROM inventory_items WHERE id = ? LIMIT 1');
$itemStmt->execute([$itemId]);
$itemRow = $itemStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($itemRow)) {
    jsonOut(false, 'Позиция не найдена', null, ['not_found']);
    exit;
}

$owlIdExplicit = isset($input['order_warehouse_line_id']) ? (int) $input['order_warehouse_line_id'] : 0;
$owlIdHint = $owlIdExplicit;
/** Временная подстраховка: угадывание owl по одному item_id (см. implicit_owl_resolve в API). Основной сценарий — order_warehouse_line_id + order_id (+ order_line_key). */
$allowImplicitOwl = filter_var($input['implicit_owl_resolve'] ?? false, FILTER_VALIDATE_BOOLEAN);
if ($owlIdHint <= 0 && in_array($type, INV_MOV_IN, true)) {
    $resolvedOwl = fixarivan_resolve_owl_id_for_inward_movement($pdo, $itemId, $input, $allowImplicitOwl);
    if ($resolvedOwl > 0) {
        $owlIdHint = $resolvedOwl;
    }
}
if ($owlIdExplicit > 0) {
    $ctxErr = fixarivan_inventory_movement_validate_owl_order_context($pdo, $owlIdExplicit, $input);
    if ($ctxErr !== null) {
        jsonOut(false, $ctxErr, null, ['order_context']);
        exit;
    }
}
if ($owlIdHint > 0 && $owlIdExplicit === 0) {
    $hasOrderHints = false;
    foreach (['order_id', 'document_id', 'ref_id'] as $hk) {
        if (trim((string) ($input[$hk] ?? '')) !== '') {
            $hasOrderHints = true;
            break;
        }
    }
    if ($hasOrderHints) {
        $ctxErr2 = fixarivan_inventory_movement_validate_owl_order_context($pdo, $owlIdHint, $input);
        if ($ctxErr2 !== null) {
            jsonOut(false, $ctxErr2, null, ['order_context']);
            exit;
        }
    }
}

$arrivalCtx = trim((string) ($input['arrival_context'] ?? ''));
if ($arrivalCtx === 'order_queue' && $owlIdHint <= 0 && in_array($type, INV_MOV_IN, true)) {
    jsonOut(
        false,
        'Приход «Под заказ»: передайте order_warehouse_line_id, order_id и item_id (при необходимости order_line_key). Включите implicit_owl_resolve только как временную подстраховку.',
        null,
        ['arrival_context']
    );
    exit;
}

$now = date('c');
$owlPurchaseHint = null;
$owlSaleHint = null;
if ($owlIdHint > 0 && in_array($type, ['in', 'return', 'balance_on'], true)) {
    $owlPriceSt = $pdo->prepare('SELECT purchase_price, sale_price FROM order_warehouse_lines WHERE id = ? LIMIT 1');
    $owlPriceSt->execute([$owlIdHint]);
    $owlPr = $owlPriceSt->fetch(PDO::FETCH_ASSOC);
    if (is_array($owlPr)) {
        $owlPurchaseHint = isset($owlPr['purchase_price']) ? (float)$owlPr['purchase_price'] : null;
        $owlSaleHint = isset($owlPr['sale_price']) ? (float)$owlPr['sale_price'] : null;
    }
}
$unitCost = null;
if (array_key_exists('unit_cost', $input)) {
    $unitCost = (float)$input['unit_cost'];
} elseif ($owlPurchaseHint !== null && $owlPurchaseHint > 0) {
    $unitCost = $owlPurchaseHint;
} elseif (in_array($type, INV_MOV_OUT, true) && isset($itemRow['default_cost'])) {
    $unitCost = (float)$itemRow['default_cost'];
}
$unitSalePrice = null;
if (array_key_exists('unit_sale_price', $input)) {
    $unitSalePrice = (float)$input['unit_sale_price'];
} elseif ($owlSaleHint !== null && $owlSaleHint > 0) {
    $unitSalePrice = $owlSaleHint;
} elseif ($type === 'sale' && isset($itemRow['sale_price'])) {
    $unitSalePrice = (float)$itemRow['sale_price'];
}
$note = isset($input['note']) ? trim((string)$input['note']) : null;
$refKind = isset($input['ref_kind']) ? trim((string)$input['ref_kind']) : null;
$refId = isset($input['ref_id']) ? trim((string)$input['ref_id']) : null;
$createdBy = isset($input['created_by']) ? trim((string)$input['created_by']) : null;

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, unit_sale_price, ref_kind, ref_id, note, created_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $itemId,
        $type,
        $delta,
        $unitCost,
        $unitSalePrice,
        $refKind ?: null,
        $refId ?: null,
        $note,
        $now,
        $createdBy,
    ]);
    $movementId = (int)$pdo->lastInsertId();

    if ($unitCost !== null && $unitCost > 0 && in_array($type, ['in', 'return', 'balance_on'], true)) {
        $pdo->prepare(
            'UPDATE inventory_items SET default_cost = ?, updated_at = ? WHERE id = ? AND (default_cost IS NULL OR default_cost = 0)'
        )->execute([$unitCost, $now, $itemId]);
    }
    if ($owlSaleHint !== null && $owlSaleHint > 0 && in_array($type, ['in', 'return', 'balance_on'], true)) {
        $pdo->prepare(
            'UPDATE inventory_items SET sale_price = ?, updated_at = ? WHERE id = ? AND (sale_price IS NULL OR sale_price = 0)'
        )->execute([$owlSaleHint, $now, $itemId]);
    }
    $updItem = $pdo->prepare('UPDATE inventory_items SET updated_at = ? WHERE id = ?');
    $updItem->execute([$now, $itemId]);

    if ($owlIdHint > 0) {
        $pdo->prepare(
            'UPDATE order_warehouse_lines SET inventory_item_id = ?, updated_at = ? WHERE id = ? AND (inventory_item_id IS NULL OR inventory_item_id = 0)'
        )->execute([$itemId, $now, $owlIdHint]);
    }

    $owlId = $owlIdHint;
    if ($owlId > 0 && in_array($type, ['in', 'return', 'balance_on'], true)) {
        $stOwl = $pdo->prepare(
            'SELECT order_id, qty, COALESCE(qty_received, 0) AS qty_received, status FROM order_warehouse_lines WHERE id = ?'
        );
        $stOwl->execute([$owlId]);
        $owlRow = $stOwl->fetch(PDO::FETCH_ASSOC);
        if (is_array($owlRow)) {
            $oidMove = trim((string)($owlRow['order_id'] ?? ''));
            $stCur = strtolower(trim((string)($owlRow['status'] ?? '')));
            if ($oidMove !== '' && !in_array($stCur, ['arrived', 'installed', 'ready'], true)) {
                $need = (float)($owlRow['qty'] ?? 1);
                if ($need <= 0) {
                    $need = 1.0;
                }
                $prevRecv = (float)($owlRow['qty_received'] ?? 0);
                $merged = $prevRecv + $qty;
                $newRecv = $merged > $need ? $need : $merged;
                $isFull = $merged >= $need - 1e-6;
                $newStatus = $isFull ? 'arrived' : 'ordered';
                $pdo->prepare(
                    'UPDATE order_warehouse_lines SET qty_received = ?, status = ?, updated_at = ? WHERE id = ?'
                )->execute([$newRecv, $newStatus, $now, $owlId]);
                fixarivan_recompute_order_parts_aggregate($pdo, $oidMove);
                if ($isFull) {
                    fixarivan_clear_supply_calendar_for_order($pdo, $oidMove);
                }
            }
        }
    }

    $autoOutQty = 0.0;
    $autoOutSkipped = '';
    if ($owlIdHint > 0 && in_array($type, ['in', 'return', 'balance_on'], true)) {
        $ar = fixarivan_apply_auto_order_line_out_after_inward($pdo, $movementId, $itemId, $qty, $owlIdHint, $now);
        $autoOutQty = (float) ($ar['out_qty'] ?? 0);
        $autoOutSkipped = (string) ($ar['skipped'] ?? '');
        fixarivan_sync_order_lines_json_from_owl($pdo, $owlIdHint);
    }

    $balStmt = $pdo->prepare('SELECT quantity FROM inventory_balances WHERE item_id = ?');
    $balStmt->execute([$itemId]);
    $balRow = $balStmt->fetch(PDO::FETCH_ASSOC);
    $newQty = (float) ($balRow['quantity'] ?? 0);

    $pdo->commit();

    $outData = [
        'movement_id' => $movementId,
        'item_id' => $itemId,
        'quantity_after' => $newQty,
        'order_warehouse_line_id' => $owlIdHint > 0 ? $owlIdHint : null,
        'auto_out_qty' => $autoOutQty,
        'auto_out_skipped' => $autoOutSkipped,
    ];
    if ($owlIdExplicit === 0 && $owlIdHint > 0) {
        $outData['resolved_order_warehouse_line_id'] = $owlIdHint;
    }

    jsonOut(true, null, $outData);
} catch (Throwable $e) {
    $pdo->rollBack();
    jsonOut(false, $e->getMessage(), null, [$e->getMessage()]);
}
