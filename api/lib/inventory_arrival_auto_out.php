<?php
/**
 * TZ FINAL: после прихода (IN) по строке заказа — авто-списание под заказ (OUT) без ручного действия в Track.
 * Идемпотентность: ORDER_LINE_ARR_OUT:{order}:{lineKey}:{inMovementId}; учёт уже существующего ORDER_LINE_OUT (Track).
 */
declare(strict_types=1);

require_once __DIR__ . '/order_supply.php';

/**
 * Приход только с item_id (без order_id в теле): если в БД ровно одна ожидающая строка заказа под эту карточку / имя — подставить owl id.
 */
function fixarivan_resolve_single_pending_owl_by_item_only(PDO $pdo, int $itemId): int
{
    $itemId = (int) $itemId;
    if ($itemId <= 0) {
        return 0;
    }

    $pend = ' AND IFNULL(from_stock, 0) = 0 AND LOWER(TRIM(COALESCE(status, \'\'))) NOT IN (\'arrived\', \'installed\', \'ready\') ';

    $st = $pdo->prepare(
        'SELECT id FROM order_warehouse_lines WHERE IFNULL(inventory_item_id, 0) = ?' . $pend . ' ORDER BY id ASC'
    );
    $st->execute([$itemId]);
    $byId = array_map('intval', array_filter($st->fetchAll(PDO::FETCH_COLUMN) ?: [], static fn ($x) => (int) $x > 0));
    if (count($byId) === 1) {
        return $byId[0];
    }

    return 0;
}

/**
 * После успешного IN по order_warehouse_line_id: создать OUT на min(приход, остаток потребности по строке).
 *
 * @return array{out_qty: float, skipped: string} skipped — пустая строка или причина
 */
function fixarivan_apply_auto_order_line_out_after_inward(
    PDO $pdo,
    int $inMovementId,
    int $itemId,
    float $inQty,
    int $owlId,
    string $now
): array {
    $outQty = 0.0;
    if ($inMovementId <= 0 || $itemId <= 0 || $inQty <= 0.0 || $owlId <= 0) {
        return ['out_qty' => 0.0, 'skipped' => 'bad_args'];
    }

    $stOwl = $pdo->prepare(
        'SELECT order_id, qty, IFNULL(from_stock, 0) AS from_stock, order_line_key, inventory_item_id
         FROM order_warehouse_lines WHERE id = ? LIMIT 1'
    );
    $stOwl->execute([$owlId]);
    $owl = $stOwl->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owl)) {
        return ['out_qty' => 0.0, 'skipped' => 'no_owl'];
    }
    if ((int) ($owl['from_stock'] ?? 0) === 1) {
        return ['out_qty' => 0.0, 'skipped' => 'from_stock'];
    }
    $owlInv = (int) ($owl['inventory_item_id'] ?? 0);
    if ($owlInv > 0 && $owlInv !== $itemId) {
        return ['out_qty' => 0.0, 'skipped' => 'item_mismatch'];
    }

    $oidRaw = trim((string) ($owl['order_id'] ?? ''));
    if ($oidRaw === '') {
        return ['out_qty' => 0.0, 'skipped' => 'no_order_id'];
    }

    $lineKey = trim((string) ($owl['order_line_key'] ?? ''));
    if ($lineKey === '') {
        $lineKey = 'owl' . $owlId;
    }

    $stOrd = $pdo->prepare('SELECT order_id, document_id FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $stOrd->execute([$oidRaw, $oidRaw]);
    $orow = $stOrd->fetch(PDO::FETCH_ASSOC);
    $refOrderId = trim((string) ($orow['order_id'] ?? ''));
    if ($refOrderId === '') {
        $refOrderId = trim((string) ($orow['document_id'] ?? ''));
    }
    if ($refOrderId === '') {
        $refOrderId = $oidRaw;
    }

    $noteArr = 'ORDER_LINE_ARR_OUT:' . $refOrderId . ':' . $lineKey . ':' . $inMovementId;
    $dup = $pdo->prepare('SELECT id FROM inventory_movements WHERE note = ? LIMIT 1');
    $dup->execute([$noteArr]);
    if ($dup->fetchColumn()) {
        return ['out_qty' => 0.0, 'skipped' => 'already_arr_out'];
    }

    $need = (float) ($owl['qty'] ?? 1);
    if ($need <= 0) {
        $need = 1.0;
    }
    $alreadyOut = fixarivan_sum_order_line_out_quantity($pdo, $itemId, $refOrderId, $lineKey);
    $room = $need - $alreadyOut;
    if ($room <= 1e-9) {
        return ['out_qty' => 0.0, 'skipped' => 'line_already_out'];
    }

    $outNow = $inQty < $room ? $inQty : $room;
    if ($outNow <= 1e-9) {
        return ['out_qty' => 0.0, 'skipped' => 'zero_out'];
    }

    $costStmt = $pdo->prepare('SELECT default_cost FROM inventory_items WHERE id = ? LIMIT 1');
    $costStmt->execute([$itemId]);
    $uc = $costStmt->fetchColumn();
    $unitCost = $uc !== false && $uc !== null ? (float) $uc : null;

    $ins = $pdo->prepare(
        'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([
        $itemId,
        'out',
        -$outNow,
        $unitCost,
        'order',
        $refOrderId,
        $noteArr,
        $now,
        null,
    ]);

    return ['out_qty' => $outNow, 'skipped' => ''];
}

/**
 * Проверка: строка owl относится к этой карточке склада (id или разрешение из JSON заказа).
 */
function fixarivan_owl_row_matches_inventory_item(PDO $pdo, array $r, int $itemId): bool
{
    $itemId = (int) $itemId;
    if ($itemId <= 0) {
        return false;
    }
    $iid = (int) ($r['inventory_item_id'] ?? 0);
    if ($iid > 0) {
        return $iid === $itemId;
    }
    $oid = trim((string) ($r['order_id'] ?? ''));
    $key = trim((string) ($r['order_line_key'] ?? ''));
    if ($oid === '' || $key === '') {
        return false;
    }
    $st = $pdo->prepare('SELECT notes FROM inventory_items WHERE id = ? LIMIT 1');
    $st->execute([$itemId]);
    $notes = (string) ($st->fetchColumn() ?: '');
    $reqTag = '[REQ ' . $oid . ']';
    $lineTag = '[LINE ' . $key . ']';

    return strpos($notes, $reqTag) !== false && strpos($notes, $lineTag) !== false;
}

/**
 * Сценарий A (явно): order_line_key + идентификатор заказа → ровно одна строка, без «угадывания» по имени.
 *
 * @param array<string, mixed> $input
 */
function fixarivan_resolve_owl_by_order_and_line_key(PDO $pdo, int $itemId, array $input): int
{
    $itemId = (int) $itemId;
    $key = trim((string) ($input['order_line_key'] ?? $input['orderLineKey'] ?? ''));
    if ($key === '') {
        return 0;
    }

    $hints = [];
    foreach (['order_id', 'document_id'] as $k) {
        $v = trim((string) ($input[$k] ?? ''));
        if ($v !== '') {
            $hints[$v] = true;
        }
    }
    $rk = strtolower(trim((string) ($input['ref_kind'] ?? '')));
    $refId = trim((string) ($input['ref_id'] ?? ''));
    if ($refId !== '' && ($rk === '' || $rk === 'order')) {
        $hints[$refId] = true;
    }
    if ($hints === []) {
        return 0;
    }

    $oids = [];
    foreach (array_keys($hints) as $h) {
        foreach (fixarivan_order_id_variants_for_pdo($pdo, $h, $h) as $x) {
            $x = trim((string) $x);
            if ($x !== '') {
                $oids[$x] = true;
            }
        }
    }
    $oidList = array_keys($oids);
    if ($oidList === []) {
        return 0;
    }

    $ph = implode(',', array_fill(0, count($oidList), '?'));
    $st = $pdo->prepare(
        "SELECT id, order_id, name, IFNULL(inventory_item_id, 0) AS inventory_item_id
         FROM order_warehouse_lines
         WHERE order_id IN ($ph)
           AND TRIM(COALESCE(order_line_key, '')) = ?
           AND IFNULL(from_stock, 0) = 0
           AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('arrived', 'installed', 'ready')
         ORDER BY id ASC LIMIT 2"
    );
    $params = array_merge($oidList, [$key]);
    $st->execute($params);
    $found = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($found) !== 1 || !is_array($found[0])) {
        return 0;
    }

    return fixarivan_owl_row_matches_inventory_item($pdo, $found[0], $itemId) ? (int) $found[0]['id'] : 0;
}

/**
 * Подбор owl для IN: сначала явные идентификаторы; угадывание по item_id — только при implicit_owl_resolve=true.
 *
 * @param array<string, mixed> $input тело POST inventory_movement
 */
function fixarivan_resolve_owl_id_for_inward_movement(PDO $pdo, int $itemId, array $input, bool $allowImplicitFallback): int
{
    $itemId = (int) $itemId;
    if ($itemId <= 0) {
        return 0;
    }
    $owlDirect = (int) ($input['order_warehouse_line_id'] ?? 0);
    if ($owlDirect > 0) {
        return $owlDirect;
    }

    $lineKeyInput = trim((string) ($input['order_line_key'] ?? $input['orderLineKey'] ?? ''));

    $byKey = fixarivan_resolve_owl_by_order_and_line_key($pdo, $itemId, $input);
    if ($byKey > 0) {
        return $byKey;
    }
    if ($lineKeyInput !== '') {
        return 0;
    }

    $hints = [];
    foreach (['order_id', 'document_id'] as $k) {
        $v = trim((string) ($input[$k] ?? ''));
        if ($v !== '') {
            $hints[$v] = true;
        }
    }
    $rk = strtolower(trim((string) ($input['ref_kind'] ?? '')));
    $refId = trim((string) ($input['ref_id'] ?? ''));
    if ($refId !== '' && ($rk === '' || $rk === 'order')) {
        $hints[$refId] = true;
    }
    if ($hints === []) {
        return $allowImplicitFallback ? fixarivan_resolve_single_pending_owl_by_item_only($pdo, $itemId) : 0;
    }

    $allOrderIds = [];
    foreach (array_keys($hints) as $h) {
        foreach (fixarivan_order_id_variants_for_pdo($pdo, $h, $h) as $x) {
            $x = trim((string) $x);
            if ($x !== '') {
                $allOrderIds[$x] = true;
            }
        }
    }
    $oids = array_keys($allOrderIds);
    if ($oids === []) {
        return $allowImplicitFallback ? fixarivan_resolve_single_pending_owl_by_item_only($pdo, $itemId) : 0;
    }

    $ph = implode(',', array_fill(0, count($oids), '?'));
    $sql = "SELECT id, order_id, name, qty, IFNULL(inventory_item_id, 0) AS inventory_item_id, IFNULL(from_stock, 0) AS from_stock,
                   order_line_key, status
            FROM order_warehouse_lines
            WHERE order_id IN ($ph)
              AND IFNULL(from_stock, 0) = 0
              AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ('arrived', 'installed', 'ready')";
    $st = $pdo->prepare($sql . ' ORDER BY id ASC');
    $st->execute($oids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $candidates = [];
    foreach ($rows as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (fixarivan_owl_row_matches_inventory_item($pdo, $r, $itemId)) {
            $candidates[] = $r;
        }
    }

    if (count($candidates) === 1) {
        return (int) $candidates[0]['id'];
    }
    if (count($candidates) > 1) {
        foreach ($candidates as $r) {
            if ((int) ($r['inventory_item_id'] ?? 0) === $itemId) {
                return (int) $r['id'];
            }
        }

        return (int) $candidates[0]['id'];
    }

    if ($allowImplicitFallback) {
        $stName = $pdo->prepare('SELECT name FROM inventory_items WHERE id = ? LIMIT 1');
        $stName->execute([$itemId]);
        $itemName = trim((string) $stName->fetchColumn());
        if ($itemName !== '') {
            foreach ($oids as $oid) {
                $found = fixarivan_find_order_warehouse_line_for_arrival($pdo, $oid, $itemName);
                if ($found > 0) {
                    return $found;
                }
            }
        }

        return fixarivan_resolve_single_pending_owl_by_item_only($pdo, $itemId);
    }

    return 0;
}
