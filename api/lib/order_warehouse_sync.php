<?php
declare(strict_types=1);

require_once __DIR__ . '/order_center.php';
require_once __DIR__ . '/order_supply.php';

/**
 * Строка JSON для sync: нельзя делать (string) от массива — получится "Array" и сломается OWL.
 *
 * @param mixed $raw
 */
function fixarivan_normalize_order_lines_json_for_sync($raw): string
{
    if (is_array($raw)) {
        $enc = json_encode($raw, JSON_UNESCAPED_UNICODE);

        return $enc !== false ? $enc : '[]';
    }
    $s = (string) ($raw ?? '[]');

    return ($s === 'Array') ? '[]' : $s;
}

/**
 * TZ v4.4: единая точка синхронизации строк заказа → order_warehouse_lines (алиас).
 */
/**
 * @param string|array $orderLinesJson JSON строки заказа или уже массив (из merge без сериализации).
 */
function fixarivan_sync_order_warehouse(PDO $pdo, string $orderId, ?int $clientId, $orderLinesJson, ?string $publicExpectedDate): void {
    fixarivan_sync_order_lines_to_warehouse($pdo, $orderId, $clientId, $orderLinesJson, $publicExpectedDate);
}

/**
 * Позиции заказа в «складе закупки»: привязка order_id + client_id (TZ v4).
 */
/**
 * Ранее подставлял inventory_item_id по SKU — отключено (связь только через line_id / явный id в JSON).
 *
 * @return string JSON массива строк
 */
/**
 * Стабильный line_id для каждой строки заказа (TZ v2-add A/H): без привязки бизнес-логики к имени.
 *
 * @return string JSON массива строк
 */
function fixarivan_ensure_order_line_ids(string $orderLinesJson): string {
    $lines = json_decode($orderLinesJson, true);
    if (!is_array($lines)) {
        return $orderLinesJson;
    }
    $changed = false;
    foreach ($lines as &$ln) {
        if (!is_array($ln)) {
            continue;
        }
        $lid = trim((string)($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        if ($lid === '') {
            $lid = 'OL-' . bin2hex(random_bytes(8));
            $ln['line_id'] = $lid;
            $changed = true;
        } else {
            $ln['line_id'] = $lid;
        }
    }
    unset($ln);
    if (!$changed) {
        return $orderLinesJson;
    }
    $enc = json_encode($lines, JSON_UNESCAPED_UNICODE);

    return $enc !== false ? $enc : $orderLinesJson;
}

/**
 * Единый шаг перед сохранением заказа: стабильные line_id. Без подбора inventory по SKU (связь только line_id / явный id).
 */
function fixarivan_prepare_order_lines_json_for_persist(PDO $pdo, string $orderLinesJson): string {
    return fixarivan_ensure_order_line_ids($orderLinesJson);
}

function fixarivan_enrich_order_lines_inventory_ids(PDO $pdo, string $orderLinesJson): string {
    return $orderLinesJson;
}

/**
 * Источник истины — order_warehouse_lines: подставить inventory_item_id в JSON по order_line_key ↔ line_id.
 */
function fixarivan_enrich_order_lines_json_from_owl(PDO $pdo, string $orderId, string $orderLinesJson): string
{
    $orderId = trim($orderId);
    if ($orderId === '') {
        return $orderLinesJson;
    }
    $lines = json_decode($orderLinesJson, true);
    if (!is_array($lines)) {
        return $orderLinesJson;
    }
    $docRow = $pdo->prepare('SELECT document_id, order_id FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $docRow->execute([$orderId, $orderId]);
    $or = $docRow->fetch(PDO::FETCH_ASSOC);
    $documentId = is_array($or) ? trim((string) ($or['document_id'] ?? '')) : '';
    $variants = fixarivan_order_id_variants_for_pdo($pdo, $documentId, $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }
    $ph = implode(',', array_fill(0, count($variants), '?'));
    $st = $pdo->prepare(
        "SELECT TRIM(COALESCE(order_line_key, '')) AS lk, IFNULL(inventory_item_id, 0) AS inventory_item_id FROM order_warehouse_lines WHERE order_id IN ($ph)"
    );
    $st->execute(array_values($variants));
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $k = trim((string) ($r['lk'] ?? ''));
        if ($k === '') {
            continue;
        }
        $map[$k] = (int) ($r['inventory_item_id'] ?? 0);
    }
    $changed = false;
    foreach ($lines as &$ln) {
        if (!is_array($ln)) {
            continue;
        }
        $kid = trim((string) ($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        if ($kid === '' || !isset($map[$kid])) {
            continue;
        }
        $iid = $map[$kid];
        if ($iid <= 0) {
            continue;
        }
        $cur = (int) ($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        if ($cur !== $iid) {
            $ln['inventory_item_id'] = $iid;
            $changed = true;
        }
    }
    unset($ln);
    if (!$changed) {
        return $orderLinesJson;
    }
    $enc = json_encode($lines, JSON_UNESCAPED_UNICODE);

    return $enc !== false ? $enc : $orderLinesJson;
}

/**
 * @deprecated Используйте fixarivan_enrich_order_lines_json_from_owl
 */
function fixarivan_enrich_order_lines_json_from_req_inventory(PDO $pdo, string $orderId, string $orderLinesJson): string
{
    return fixarivan_enrich_order_lines_json_from_owl($pdo, $orderId, $orderLinesJson);
}

/**
 * @param string|array $orderLinesJson
 */
function fixarivan_sync_order_lines_to_warehouse(PDO $pdo, string $orderId, ?int $clientId, $orderLinesJson, ?string $publicExpectedDate): void {
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }

    if (is_array($orderLinesJson)) {
        $enc = json_encode($orderLinesJson, JSON_UNESCAPED_UNICODE);
        $orderLinesJson = $enc !== false ? $enc : '[]';
    } else {
        $orderLinesJson = (string) $orderLinesJson;
    }

    $lines = json_decode($orderLinesJson, true);
    if ($orderLinesJson !== '' && $orderLinesJson !== '[]' && $lines === null && json_last_error() !== JSON_ERROR_NONE) {
        return;
    }
    if (!is_array($lines)) {
        return;
    }
    if ($lines === []) {
        $pdo->prepare('DELETE FROM order_warehouse_lines WHERE order_id = :o')->execute([':o' => $orderId]);

        return;
    }

    $existingByLineKey = [];
    $stExisting = $pdo->prepare(
        'SELECT order_line_key, status, IFNULL(qty_received, 0) AS qty_received, IFNULL(inventory_item_id, 0) AS inventory_item_id
         FROM order_warehouse_lines
         WHERE order_id = :o'
    );
    $stExisting->execute([':o' => $orderId]);
    foreach (($stExisting->fetchAll(PDO::FETCH_ASSOC) ?: []) as $er) {
        if (!is_array($er)) {
            continue;
        }
        $ek = trim((string)($er['order_line_key'] ?? ''));
        if ($ek === '') {
            continue;
        }
        $existingByLineKey[$ek] = [
            'status' => trim((string)($er['status'] ?? '')),
            'qty_received' => (float)($er['qty_received'] ?? 0),
            'inventory_item_id' => (int)($er['inventory_item_id'] ?? 0),
        ];
    }

    $pdo->prepare('DELETE FROM order_warehouse_lines WHERE order_id = :o')->execute([':o' => $orderId]);

    $exp = trim((string)($publicExpectedDate ?? ''));
    $exp = $exp !== '' ? $exp : null;
    $now = date('c');
    $cid = ($clientId !== null && $clientId > 0) ? $clientId : null;

    $ins = $pdo->prepare(
        'INSERT INTO order_warehouse_lines (
            order_id, client_id, name, qty, purchase_price, sale_price, status, expected_date,
            inventory_item_id, from_stock, qty_received, order_line_key, created_at, updated_at
        ) VALUES (
            :order_id, :client_id, :name, :qty, :purchase_price, :sale_price, :status, :expected_date,
            :inventory_item_id, :from_stock, :qty_received, :order_line_key, :created_at, :updated_at
        )'
    );

    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $name = trim((string)($ln['name'] ?? $ln['title'] ?? ''));
        if ($name === '') {
            continue;
        }
        $qty = (float)($ln['qty'] ?? $ln['quantity'] ?? 1);
        if ($qty <= 0) {
            $qty = 1.0;
        }
        // TZ v4.4: не подставлять 0, если в JSON явно переданы ключи с нулём vs отсутствие ключа
        if (isset($ln['purchase'])) {
            $pp = (float)$ln['purchase'];
        } elseif (isset($ln['purchase_price'])) {
            $pp = (float)$ln['purchase_price'];
        } elseif (isset($ln['cost'])) {
            $pp = (float)$ln['cost'];
        } else {
            $pp = 0.0;
        }
        if (isset($ln['sale'])) {
            $sp = (float)$ln['sale'];
        } elseif (isset($ln['sale_price'])) {
            $sp = (float)$ln['sale_price'];
        } elseif (isset($ln['price'])) {
            $sp = (float)$ln['price'];
        } else {
            $sp = 0.0;
        }
        $lineKey = trim((string)($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
        if ($lineKey === '') {
            continue;
        }
        $existingRow = $existingByLineKey[$lineKey] ?? null;

        $invItemId = (int)($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        if ($invItemId <= 0 && is_array($existingRow)) {
            $existingInv = (int)($existingRow['inventory_item_id'] ?? 0);
            if ($existingInv > 0) {
                $invItemId = $existingInv;
            }
        }
        $fromStockFlag = $ln['from_stock'] ?? $ln['fromStock'] ?? false;
        $fromStock = ($fromStockFlag === true || $fromStockFlag === 1 || $fromStockFlag === '1'
            || (is_string($fromStockFlag) && strtolower(trim($fromStockFlag)) === 'true'));
        // Не помечать строку как «со склада» только из‑за привязки к карточке товара (SKU / id).
        if ($invItemId > 0 && $fromStock) {
            $itSt = $pdo->prepare('SELECT default_cost, sale_price, name FROM inventory_items WHERE id = ? LIMIT 1');
            $itSt->execute([$invItemId]);
            $it = $itSt->fetch(PDO::FETCH_ASSOC);
            if (is_array($it)) {
                if ($pp === 0.0 && isset($it['default_cost'])) {
                    $pp = (float)$it['default_cost'];
                }
                if ($sp === 0.0) {
                    $sp = (float)($it['sale_price'] ?? 0);
                }
                $itName = trim((string)($it['name'] ?? ''));
                if ($itName !== '') {
                    $name = $itName;
                }
            }
        } elseif ($invItemId > 0 && !$fromStock) {
            // Связь с каталогом без списания: подтянуть только цены, если в заказе нули.
            $itSt = $pdo->prepare('SELECT default_cost, sale_price FROM inventory_items WHERE id = ? LIMIT 1');
            $itSt->execute([$invItemId]);
            $it = $itSt->fetch(PDO::FETCH_ASSOC);
            if (is_array($it)) {
                if ($pp === 0.0 && isset($it['default_cost'])) {
                    $pp = (float)$it['default_cost'];
                }
                if ($sp === 0.0) {
                    $sp = (float)($it['sale_price'] ?? 0);
                }
            }
        }

        $lineSt = trim((string)($ln['status'] ?? ''));
        $existingSt = is_array($existingRow) ? strtolower(trim((string)($existingRow['status'] ?? ''))) : '';
        $existingTerminal = in_array($existingSt, ['ready', 'arrived', 'installed'], true);
        $qtyReceived = isset($ln['qty_received']) ? (float)$ln['qty_received'] : 0.0;
        if ($qtyReceived <= 0 && is_array($existingRow)) {
            $qtyReceived = (float)($existingRow['qty_received'] ?? 0);
        }
        if ($lineSt !== '') {
            $st = $lineSt;
        } elseif ($existingTerminal) {
            $st = $existingSt;
        } elseif ($fromStock && $invItemId > 0) {
            $st = 'ready';
        } else {
            $st = 'ordered';
        }
        if (!$fromStock) {
            $sn = strtolower(trim((string) $st));
            if (in_array($sn, ['ready', 'arrived', 'installed'], true) && !$existingTerminal) {
                $st = 'ordered';
            }
        }
        if ($qtyReceived < 0) {
            $qtyReceived = 0.0;
        }
        if ($qtyReceived > $qty) {
            $qtyReceived = $qty;
        }
        if (in_array(strtolower(trim((string)$st)), ['ready', 'arrived', 'installed'], true) && $qtyReceived <= 0) {
            $qtyReceived = $qty;
        }

        $ins->execute([
            ':order_id' => $orderId,
            ':client_id' => $cid,
            ':name' => $name,
            ':qty' => $qty,
            ':purchase_price' => $pp,
            ':sale_price' => $sp,
            ':status' => $st,
            ':expected_date' => $exp,
            ':inventory_item_id' => $invItemId > 0 ? $invItemId : null,
            ':from_stock' => $fromStock ? 1 : 0,
            ':qty_received' => $qtyReceived,
            ':order_line_key' => $lineKey !== '' ? $lineKey : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

/**
 * Пересчёт агрегата parts_status по строкам склада заказа (TZ v4.4).
 * waiting / partial / ready + влияние на order_status (ожидание запчастей).
 */
function fixarivan_recompute_order_parts_aggregate(PDO $pdo, string $orderId): void {
    $orderId = trim($orderId);
    if ($orderId === '') {
        return;
    }

    $variants = fixarivan_order_id_variants_for_pdo($pdo, '', $orderId);
    if ($variants === []) {
        $variants = [$orderId];
    }

    $ph = implode(',', array_fill(0, count($variants), '?'));
    $st = $pdo->prepare("SELECT status FROM order_warehouse_lines WHERE order_id IN ($ph)");
    $st->execute(array_values($variants));
    $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($rows === []) {
        return;
    }

    $arrivedLike = ['arrived', 'installed', 'ready'];

    $total = count($rows);
    $arrived = 0;
    foreach ($rows as $raw) {
        $s = strtolower(trim((string)$raw));
        if ($s === '') {
            $s = 'ordered';
        }
        if (in_array($s, $arrivedLike, true)) {
            $arrived++;
        }
    }

    if ($arrived === 0) {
        $agg = 'waiting';
    } elseif ($arrived >= $total) {
        $agg = 'ready';
    } else {
        $agg = 'partial';
    }

    $aggNorm = fixarivan_normalize_parts_status($agg) ?? $agg;
    $now = date('c');
    $upd = $pdo->prepare(
        'UPDATE orders SET parts_status = :ps, date_updated = :u WHERE order_id = :oid OR document_id = :did'
    );
    $upd->execute([
        ':ps' => $aggNorm,
        ':u' => $now,
        ':oid' => $orderId,
        ':did' => $orderId,
    ]);

    // Блок F: при ожидании запчастей подтягиваем публичный статус (не перезаписываем done/delivered).
    $stPub = $pdo->prepare(
        'SELECT public_status, order_status FROM orders WHERE order_id = :o OR document_id = :d LIMIT 1'
    );
    $stPub->execute([':o' => $orderId, ':d' => $orderId]);
    $pubRow = $stPub->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pubRow)) {
        return;
    }

    $pPub = fixarivan_normalize_public_status($pubRow['public_status'] ?? null);
    $pOrd = fixarivan_normalize_public_status($pubRow['order_status'] ?? null);
    if (in_array($pPub, ['done', 'delivered'], true) || in_array($pOrd, ['done', 'delivered'], true)) {
        return;
    }

    $pub = fixarivan_normalize_public_status($pubRow['public_status'] ?? $pubRow['order_status'] ?? null);

    $whereOrder = '(order_id = :oid OR document_id = :did)'
        . ' AND COALESCE(public_status, \'\') NOT IN (\'done\',\'delivered\')'
        . ' AND COALESCE(order_status, \'\') NOT IN (\'done\',\'delivered\')';

    // Согласовать публичный статус с агрегатом по строкам склада:
    // — ничего не пришло → «ожидает запчасть»;
    // — часть пришла (partial) → остаёмся в «ожидает запчасть»: клиент видит чип «частично пришло»
    //   по parts_status, а основной статус не прыгает в «в работе», пока не готовы все позиции;
    // — всё пришло (ready) → «в работе».
    if ($agg === 'waiting') {
        $pdo->prepare(
            "UPDATE orders SET order_status = :os, public_status = :ps, date_updated = :u WHERE $whereOrder"
        )->execute([':os' => 'waiting_parts', ':ps' => 'waiting_parts', ':u' => $now, ':oid' => $orderId, ':did' => $orderId]);
    } elseif ($agg === 'partial') {
        $pdo->prepare(
            "UPDATE orders SET order_status = :os, public_status = :ps, date_updated = :u WHERE $whereOrder"
        )->execute([':os' => 'waiting_parts', ':ps' => 'waiting_parts', ':u' => $now, ':oid' => $orderId, ':did' => $orderId]);
    } elseif ($agg === 'ready' && in_array($pub, ['waiting_parts', 'in_progress'], true)) {
        $pdo->prepare(
            "UPDATE orders SET order_status = :os, public_status = :ps, date_updated = :u WHERE $whereOrder"
        )->execute([':os' => 'in_progress', ':ps' => 'in_progress', ':u' => $now, ':oid' => $orderId, ':did' => $orderId]);
    }
}

/**
 * Синхронизирует статус строки в order_lines_json со строкой order_warehouse_lines (Track / портал).
 */
function fixarivan_sync_order_lines_json_from_owl(PDO $pdo, int $owlId): void
{
    if ($owlId <= 0) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT order_id, order_line_key, name, status, qty, COALESCE(qty_received, 0) AS qty_received, IFNULL(inventory_item_id, 0) AS inventory_item_id
         FROM order_warehouse_lines WHERE id = ? LIMIT 1'
    );
    $st->execute([$owlId]);
    $owl = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owl)) {
        return;
    }
    $key = trim((string) ($owl['order_line_key'] ?? ''));
    $oid = trim((string) ($owl['order_id'] ?? ''));
    if ($oid === '') {
        return;
    }
    $stO = $pdo->prepare('SELECT document_id, order_lines_json FROM orders WHERE order_id = ? OR document_id = ? LIMIT 1');
    $stO->execute([$oid, $oid]);
    $row = $stO->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $raw = (string) ($row['order_lines_json'] ?? '[]');
    $lines = json_decode($raw, true);
    if (!is_array($lines)) {
        return;
    }
    $stOwl = strtolower(trim((string) ($owl['status'] ?? 'ordered')));
    $qr = (float) ($owl['qty_received'] ?? 0);
    $qn = (float) ($owl['qty'] ?? 1);
    if ($qn <= 0) {
        $qn = 1.0;
    }

    $owlInv = (int) ($owl['inventory_item_id'] ?? 0);

    $lineIndexesMatching = [];
    foreach ($lines as $idx => $ln) {
        if (!is_array($ln)) {
            continue;
        }
        if ($key !== '') {
            $kid = trim((string) ($ln['line_id'] ?? $ln['orderLineId'] ?? $ln['lineId'] ?? ''));
            if ($kid === $key) {
                $lineIndexesMatching[] = (int) $idx;
            }
            continue;
        }
        $lnInv = (int) ($ln['inventory_item_id'] ?? $ln['inventoryItemId'] ?? 0);
        if ($owlInv > 0 && $lnInv === $owlInv) {
            $lineIndexesMatching[] = (int) $idx;
        }
    }

    $lineIndexesMatching = array_values(array_unique($lineIndexesMatching));

    if ($key !== '') {
        if (count($lineIndexesMatching) === 0) {
            return;
        }
    } elseif (count($lineIndexesMatching) !== 1) {
        return;
    }
    $pickIdx = $lineIndexesMatching[0];
    if (!isset($lines[$pickIdx]) || !is_array($lines[$pickIdx])) {
        return;
    }

    $ln = &$lines[$pickIdx];
    $ln['status'] = $stOwl;
    if ($qr > 0) {
        $ln['qty_received'] = $qr;
    }
    if ($qr + 1e-6 < $qn && $stOwl === 'ordered') {
        $ln['parts_partial'] = true;
    } else {
        unset($ln['parts_partial']);
    }
    unset($ln);
    $enc = json_encode($lines, JSON_UNESCAPED_UNICODE);
    if ($enc === false) {
        return;
    }
    $enc = fixarivan_prepare_order_lines_json_for_persist($pdo, $enc);
    $now = date('c');
    $upd = $pdo->prepare('UPDATE orders SET order_lines_json = :j, date_updated = :u WHERE order_id = :o OR document_id = :d');
    $upd->execute([':j' => $enc, ':u' => $now, ':o' => $oid, ':d' => $oid]);
}
