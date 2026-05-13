<?php
/**
 * Немедленное списание со склада по строкам заказа (ORDER_LINE_OUT), как в Track.
 */
declare(strict_types=1);

require_once __DIR__ . '/order_track_lines.php';

function fixarivan_order_line_should_apply_immediate_deduct(array $ln): bool
{
    $from = $ln['from_stock'] ?? $ln['fromStock'] ?? false;
    $fromTrue = $from === true || $from === 1 || $from === '1'
        || (is_string($from) && strtolower(trim((string) $from)) === 'true');
    if ($fromTrue) {
        return false;
    }
    $d = $ln['_deduct'] ?? $ln['deduct_from_stock'] ?? $ln['deductFromStock'] ?? false;

    return $d === true || $d === 1 || $d === '1'
        || (is_string($d) && strtolower(trim((string) $d)) === 'true');
}

/**
 * @param list<array<string, mixed>> $lines строки с line_id (после ensure_order_line_ids)
 * @return list<string> заметки для ответа API
 */
function fixarivan_order_lines_apply_stock_deductions(PDO $pdo, string $orderIdForDb, array &$lines): array
{
    $orderIdForDb = trim($orderIdForDb);
    if ($orderIdForDb === '') {
        return [];
    }
    $notes = [];
    $now = date('c');
    foreach ($lines as $idx => &$ln) {
        if (!is_array($ln)) {
            continue;
        }
        $doDeduct = fixarivan_order_line_should_apply_immediate_deduct($ln);
        unset($ln['_deduct'], $ln['deduct_from_stock'], $ln['deductFromStock']);
        if (!$doDeduct) {
            continue;
        }
        $itemId = fixarivan_track_resolve_inventory_item_id(
            $pdo,
            (int) ($ln['inventory_item_id'] ?? 0),
            (string) ($ln['sku'] ?? '')
        );
        if ($itemId <= 0) {
            throw new RuntimeException('Строка ' . ($idx + 1) . ': укажите ID позиции склада или SKU для немедленного списания');
        }
        $qty = (float) ($ln['qty'] ?? 1);
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
        $lineKey = trim((string) ($ln['line_id'] ?? ''));
        if ($lineKey === '') {
            $lineKey = 'idx' . (string) ($idx + 1);
        }
        $lineOutNote = 'ORDER_LINE_OUT:' . $orderIdForDb . ':' . $lineKey;
        $dupSt = $pdo->prepare('SELECT id FROM inventory_movements WHERE note = ? LIMIT 1');
        $dupSt->execute([$lineOutNote]);
        $alreadyOut = (bool) $dupSt->fetchColumn();
        if (!$alreadyOut) {
            $ins = $pdo->prepare(
                'INSERT INTO inventory_movements (item_id, movement_type, quantity_delta, unit_cost, ref_kind, ref_id, note, created_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $itemId,
                'out',
                -$qty,
                isset($it['default_cost']) ? (float) $it['default_cost'] : null,
                'order',
                $orderIdForDb,
                $lineOutNote,
                $now,
                null,
            ]);
        }
        $itName = trim((string) ($it['name'] ?? ''));
        if ($itName !== '') {
            $ln['name'] = $itName;
        }
        if (($ln['purchase'] ?? 0) == 0.0 && isset($it['default_cost'])) {
            $ln['purchase'] = (float) $it['default_cost'];
        }
        if (($ln['sale'] ?? 0) == 0.0) {
            $ln['sale'] = (float) ($it['sale_price'] ?? 0);
        }
        $ln['inventory_item_id'] = $itemId;
        $ln['from_stock'] = true;
        $ln['status'] = 'ready';
        $skuDb = $pdo->prepare('SELECT sku FROM inventory_items WHERE id = ?');
        $skuDb->execute([$itemId]);
        $skuVal = trim((string) $skuDb->fetchColumn());
        if ($skuVal !== '') {
            $ln['sku'] = $skuVal;
        }
        $notes[] = $alreadyOut
            ? ('Строка ' . ($idx + 1) . ': списание уже учтено, item #' . $itemId)
            : ('Строка ' . ($idx + 1) . ': списано ' . $qty . ' шт. (item #' . $itemId . ')');
    }
    unset($ln);

    return $notes;
}

/**
 * Убрать служебные поля и привести к формату order_lines_json (как после сохранения в Track).
 *
 * @param list<array<string, mixed>> $lines
 * @return list<array<string, mixed>>
 */
function fixarivan_order_lines_clean_for_order_json(array $lines): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        unset($ln['_deduct'], $ln['deduct_from_stock'], $ln['deductFromStock']);
        $name = trim((string) ($ln['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $clean = [
            'name' => $ln['name'],
            'qty' => $ln['qty'],
            'purchase' => $ln['purchase'],
            'sale' => $ln['sale'],
        ];
        if (!empty($ln['line_id'])) {
            $clean['line_id'] = (string) $ln['line_id'];
        }
        if (!empty($ln['sku'])) {
            $clean['sku'] = $ln['sku'];
        }
        if (!empty($ln['inventory_item_id'])) {
            $clean['inventory_item_id'] = (int) $ln['inventory_item_id'];
        }
        if (!empty($ln['from_stock'])) {
            $clean['from_stock'] = true;
        }
        if (!empty($ln['status'])) {
            $clean['status'] = $ln['status'];
        }
        $out[] = $clean;
    }

    return $out;
}
