<?php
/**
 * Сохранение позиций заказа из Track + опциональное списание со склада (артикул / inventory_item_id).
 */

declare(strict_types=1);

if (ob_get_length()) {
    ob_end_clean();
}

require_once __DIR__ . '/lib/cors.php';
fixarivan_send_cors_headers('POST, OPTIONS', 'Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/lib/require_admin_session.php';
require_once __DIR__ . '/sqlite.php';
require_once __DIR__ . '/lib/order_center.php';
require_once __DIR__ . '/lib/order_supply.php';
require_once __DIR__ . '/lib/order_warehouse_sync.php';
require_once __DIR__ . '/lib/order_track_lines.php';
require_once __DIR__ . '/lib/order_json_storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => 'Некорректный JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

$documentId = trim((string)($input['documentId'] ?? $input['document_id'] ?? ''));
if ($documentId === '') {
    echo json_encode(['success' => false, 'message' => 'Нужен documentId'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderLinesRaw = $input['orderLines'] ?? $input['order_lines'] ?? null;
if (!is_array($orderLinesRaw)) {
    echo json_encode(['success' => false, 'message' => 'Передайте массив orderLines'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getSqliteConnection();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $st = $pdo->prepare('SELECT * FROM orders WHERE document_id = :d LIMIT 1');
    $st->execute([':d' => $documentId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row === []) {
        echo json_encode(['success' => false, 'message' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $normalized = fixarivan_track_normalize_order_lines($orderLinesRaw);
    foreach ($normalized as &$lnPre) {
        $iidPre = (int)($lnPre['inventory_item_id'] ?? 0);
        $skuPre = trim((string)($lnPre['sku'] ?? ''));
        if ($iidPre <= 0 && $skuPre !== '') {
            $ridPre = fixarivan_track_resolve_inventory_item_id($pdo, 0, $skuPre);
            if ($ridPre > 0) {
                $lnPre['inventory_item_id'] = $ridPre;
            }
        }
    }
    unset($lnPre);
    $jsonNorm = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($jsonNorm === false) {
        echo json_encode(['success' => false, 'message' => 'Ошибка кодирования позиций'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $jsonNorm = fixarivan_prepare_order_lines_json_for_persist($pdo, $jsonNorm);
    $normalizedPrepared = json_decode($jsonNorm, true);
    if (!is_array($normalizedPrepared) || count($normalizedPrepared) !== count($normalized)) {
        echo json_encode(['success' => false, 'message' => 'Несовпадение позиций после line_id/SKU'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach ($normalizedPrepared as $idx => &$lnM) {
        if (isset($normalized[$idx]['_deduct'])) {
            $lnM['_deduct'] = $normalized[$idx]['_deduct'];
        }
    }
    unset($lnM);
    $normalized = $normalizedPrepared;

    $orderIdForDb = trim((string)($row['order_id'] ?? ''));
    if ($orderIdForDb === '') {
        $orderIdForDb = $documentId;
    }
    $clientId = isset($row['client_id']) ? (int)$row['client_id'] : 0;
    $now = date('c');
    $deductNotes = [];

    $pdo->beginTransaction();
    try {
        foreach ($normalized as $idx => &$ln) {
            $deduct = !empty($ln['_deduct']);
            unset($ln['_deduct']);
            if (!empty($ln['from_stock'])) {
                continue;
            }
            if (!$deduct) {
                continue;
            }
            $itemId = fixarivan_track_resolve_inventory_item_id(
                $pdo,
                (int)($ln['inventory_item_id'] ?? 0),
                (string)($ln['sku'] ?? '')
            );
            if ($itemId <= 0) {
                throw new RuntimeException('Строка ' . ($idx + 1) . ': укажите артикул (SKU) существующей позиции склада или inventory_item_id');
            }
            $qty = (float)($ln['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1.0;
            }
            $bal = fixarivan_track_inventory_balance($pdo, $itemId);
            if ($bal < $qty) {
                throw new RuntimeException(
                    'Строка ' . ($idx + 1) . ': на складе недостаточно (есть ' . $bal . ', нужно ' . $qty . ')'
                );
            }
            $costStmt = $pdo->prepare('SELECT default_cost, sale_price, name FROM inventory_items WHERE id = ? LIMIT 1');
            $costStmt->execute([$itemId]);
            $it = $costStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($it)) {
                throw new RuntimeException('Строка ' . ($idx + 1) . ': позиция склада не найдена');
            }
            $lineKey = trim((string)($ln['line_id'] ?? ''));
            if ($lineKey === '') {
                $lineKey = 'idx' . (string)($idx + 1);
            }
            $lineOutNote = 'ORDER_LINE_OUT:' . $orderIdForDb . ':' . $lineKey;
            $dupSt = $pdo->prepare('SELECT id FROM inventory_movements WHERE note = ? LIMIT 1');
            $dupSt->execute([$lineOutNote]);
            $alreadyOut = (bool)$dupSt->fetchColumn();
            if (!$alreadyOut) {
                $ins = $pdo->prepare(
                    'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $itemId,
                    'out',
                    -$qty,
                    isset($it['default_cost']) ? (float)$it['default_cost'] : null,
                    'order',
                    $orderIdForDb,
                    $lineOutNote,
                    $now,
                    null,
                ]);
            }
            $itName = trim((string)($it['name'] ?? ''));
            if ($itName !== '') {
                $ln['name'] = $itName;
            }
            if (($ln['purchase'] ?? 0) == 0.0 && isset($it['default_cost'])) {
                $ln['purchase'] = (float)$it['default_cost'];
            }
            if (($ln['sale'] ?? 0) == 0.0) {
                $ln['sale'] = (float)($it['sale_price'] ?? 0);
            }
            $ln['inventory_item_id'] = $itemId;
            $ln['from_stock'] = true;
            $ln['status'] = 'ready';
            $skuDb = $pdo->prepare('SELECT sku FROM inventory_items WHERE id = ?');
            $skuDb->execute([$itemId]);
            $skuVal = trim((string)$skuDb->fetchColumn());
            if ($skuVal !== '') {
                $ln['sku'] = $skuVal;
            }
            $deductNotes[] = $alreadyOut
                ? ('Строка ' . ($idx + 1) . ': списание уже учтено (идемпотентно), item #' . $itemId)
                : ('Строка ' . ($idx + 1) . ': списано ' . $qty . ' шт. (item #' . $itemId . ')');
        }
        unset($ln);

        $linesForJson = [];
        foreach ($normalized as $ln) {
            $clean = [
                'name' => $ln['name'],
                'qty' => $ln['qty'],
                'purchase' => $ln['purchase'],
                'sale' => $ln['sale'],
            ];
            if (!empty($ln['line_id'])) {
                $clean['line_id'] = (string)$ln['line_id'];
            }
            if (!empty($ln['sku'])) {
                $clean['sku'] = $ln['sku'];
            }
            if (!empty($ln['inventory_item_id'])) {
                $clean['inventory_item_id'] = (int)$ln['inventory_item_id'];
            }
            if (!empty($ln['from_stock'])) {
                $clean['from_stock'] = true;
            }
            if (!empty($ln['status'])) {
                $clean['status'] = $ln['status'];
            }
            $linesForJson[] = $clean;
        }

        $jsonStr = json_encode($linesForJson, JSON_UNESCAPED_UNICODE);
        if ($jsonStr === false) {
            throw new RuntimeException('Ошибка кодирования order_lines_json');
        }
        $jsonStr = fixarivan_prepare_order_lines_json_for_persist($pdo, $jsonStr);
        $linesForJson = json_decode($jsonStr, true);
        if (!is_array($linesForJson)) {
            throw new RuntimeException('Ошибка нормализации order_lines_json');
        }
        [$pTot, $sTot] = fixarivan_track_totals_from_lines($linesForJson);

        $newSupply = $linesForJson !== [] ? fixarivan_supply_request_from_order_lines($linesForJson) : '';

        $upd = $pdo->prepare(
            'UPDATE orders SET order_lines_json = :olj, parts_purchase_total = :ppt, parts_sale_total = :pst, supply_request = :sr, date_updated = :u WHERE document_id = :d'
        );
        $upd->execute([
            ':olj' => $jsonStr,
            ':ppt' => $pTot,
            ':pst' => $sTot,
            ':sr' => $newSupply,
            ':u' => $now,
            ':d' => $documentId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $row['order_lines_json'] = $jsonStr;
    $row['parts_purchase_total'] = $pTot;
    $row['parts_sale_total'] = $sTot;
    $row['supply_request'] = $newSupply;
    $row['date_updated'] = $now;
    fixarivan_save_order_json_files($row, $documentId, (string)($row['client_token'] ?? ''));

    $warehouseWarning = null;
    $supplyWarning = null;
    try {
        fixarivan_sync_order_warehouse(
            $pdo,
            $orderIdForDb,
            $clientId > 0 ? $clientId : null,
            $jsonStr,
            (string)($row['public_expected_date'] ?? '')
        );
        fixarivan_sync_order_purchase_lines_to_inventory(
            $pdo,
            $orderIdForDb,
            $linesForJson,
            (string)($row['device_model'] ?? '')
        );
        $enrichedTrack = fixarivan_enrich_order_lines_json_from_owl($pdo, $orderIdForDb, $jsonStr);
        if ($enrichedTrack !== $jsonStr) {
            $nowUp = date('c');
            $pdo->prepare('UPDATE orders SET order_lines_json = :j, date_updated = :u WHERE document_id = :d')->execute([
                ':j' => $enrichedTrack,
                ':u' => $nowUp,
                ':d' => $documentId,
            ]);
            $jsonStr = $enrichedTrack;
            $row['order_lines_json'] = $jsonStr;
            fixarivan_save_order_json_files($row, $documentId, (string)($row['client_token'] ?? ''));
        }
        fixarivan_sync_order_warehouse(
            $pdo,
            $orderIdForDb,
            $clientId > 0 ? $clientId : null,
            $jsonStr,
            (string)($row['public_expected_date'] ?? '')
        );
        fixarivan_create_supply_reminder(
            $pdo,
            $orderIdForDb,
            fixarivan_order_lines_to_supply_items($linesForJson),
            (string)($row['supply_urgency'] ?? $row['priority'] ?? 'medium'),
            (string)($row['public_expected_date'] ?? ''),
            (string)($row['client_name'] ?? '')
        );
        fixarivan_recompute_order_parts_aggregate($pdo, $orderIdForDb);
        $oidHook = $orderIdForDb !== '' ? $orderIdForDb : $documentId;
        fixarivan_on_order_terminal_public_status(
            $pdo,
            $oidHook,
            fixarivan_normalize_public_status($row['public_status'] ?? $row['order_status'] ?? null)
        );
    } catch (Throwable $wh) {
        $warehouseWarning = $wh->getMessage();
    }

    $linesDecoded = json_decode($jsonStr, true);
    if (is_array($linesDecoded)) {
        $linesForJson = $linesDecoded;
    }

    $sdw = fixarivan_supply_missing_expected_date_warning(
        (string)($row['supply_request'] ?? ''),
        (string)($row['public_expected_date'] ?? ''),
        $jsonStr
    );
    if ($sdw !== null) {
        $supplyWarning = ($supplyWarning !== null && $supplyWarning !== '' ? $supplyWarning . ' ' : '') . $sdw;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Позиции сохранены',
        'document_id' => $documentId,
        'order_id' => $orderIdForDb,
        'order_lines' => $linesForJson,
        'deduct_notes' => $deductNotes,
        'warehouse_warning' => $warehouseWarning,
        'supply_warning' => $supplyWarning,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
